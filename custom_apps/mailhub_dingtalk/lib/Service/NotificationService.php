<?php
declare(strict_types=1);
namespace OCA\MailhubDingtalk\Service;

use OCP\IConfig;
use OCP\IUserManager;
use OCP\IURLGenerator;
use OCP\AppFramework\Utility\ITimeFactory;
use Psr\Log\LoggerInterface;

final class NotificationService {
    private const APP = 'mailhub_dingtalk';
    public function __construct(private NotificationRepository $repository, private Settings $settings,
        private IConfig $config, private IUserManager $users, private IURLGenerator $urls,
        private ITimeFactory $clock, private DingTalkClient $client, private LoggerInterface $logger) {}

    public function globalEnabled(): bool {
        return $this->settings->get('enabled', false) === true
            && $this->settings->get('notifications_enabled', false) === true;
    }
    public function userEnabled(string $uid, ?array $mapping = null): bool {
        if (!$this->globalEnabled() || $this->config->getUserValue($uid, self::APP, 'notifications_enabled', '1') !== '1') { return false; }
        $user = $this->users->get($uid);
        $mapping ??= $this->repository->mapping($uid);
        return $user !== null && $user->isEnabled() && $mapping !== null
            && $mapping['corp_id'] === $this->settings->corpId() && $this->settings->permits($mapping['ding_user_id']);
    }
    public function epoch(string $uid): string {
        return hash('sha256', implode("\0", [
            (string)$this->settings->get('notifications_epoch', 'initial'),
            $this->config->getAppValue(self::APP, 'notifications_runtime_epoch', 'initial'),
            $this->config->getUserValue($uid, self::APP, 'notifications_epoch', 'initial'),
        ]));
    }

    public function tick(): void {
        $now = $this->clock->getTime();
        $this->config->setAppValue(self::APP, 'notifications_last_attempt', (string)$now);
        $mailVersion = $this->config->getAppValue('mail', 'installed_version', '');
        if (!in_array($mailVersion, NotificationPolicy::MAIL_VERSIONS, true)) {
            $this->config->setAppValue(self::APP, 'notifications_last_error', 'unsupported_mail_version');
            return; // fail closed after an unreviewed Mail schema change
        }
        $enabled = $this->globalEnabled();
        $oldEnabled = $this->config->getAppValue(self::APP, 'notifications_was_enabled', '0') === '1';
        if ($enabled !== $oldEnabled) {
            // Also covers a root-edited configuration file instead of the UI toggle.
            $this->config->setAppValue(self::APP, 'notifications_runtime_epoch', bin2hex(random_bytes(16)));
            $this->config->setAppValue(self::APP, 'notifications_was_enabled', $enabled ? '1' : '0');
        }
        $this->repository->recoverAbandonedSends($now);
        $errors = 0;
        foreach ($this->repository->inboxes() as $mailbox) {
            if (!NotificationPolicy::ready($mailbox, $now)) { continue; }
            try { $this->scan($mailbox, $now); }
            catch (\Throwable) {
                ++$errors;
                // Never include raw SQL, addresses, credentials, message text or upstream exception strings.
                $this->logger->warning('Mailhub notification cache scan failed', ['app' => self::APP, 'mailboxId' => (int)$mailbox['id']]);
            }
        }
        foreach ($this->repository->pendingUsers() as $uid) {
            $mapping = $this->repository->mapping($uid);
            if (!$this->userEnabled($uid, $mapping)) { $this->repository->cancelUser($uid, $now); continue; }
            // A zero window aggregates the current scan and submits in this tick;
            // existing in-flight batches and retry/backoff guards still apply.
            $this->repository->createBatch($uid, $mapping, $this->epoch($uid), $now,
                NotificationPolicy::digestSeconds($this->settings->get('notification_digest_seconds', NotificationPolicy::DEFAULT_DIGEST_SECONDS)));
        }
        // No token acquisition or any DingTalk request occurs while the global switch is off.
        if ($enabled) {
            foreach ($this->repository->dueBatches($now) as $batch) { $this->process($batch, $now); }
        }
        $this->repository->pruneHistory($now);
        $this->config->setAppValue(self::APP, 'notifications_last_successful_run', (string)$now);
        $this->config->setAppValue(self::APP, 'notifications_last_error', $errors > 0 ? 'mail_cache_scan_failed' : '');
    }

    private function scan(array $mailbox, int $now): void {
        $active = $this->userEnabled($mailbox['nc_uid'], $mailbox);
        $epoch = $this->epoch($mailbox['nc_uid']);
        $this->repository->transaction(function () use ($mailbox, $now, $active, $epoch): void {
            $cursor = $this->repository->cursor((int)$mailbox['id']);
            $highest = $this->repository->highUid((int)$mailbox['id']);
            $baseline = NotificationPolicy::mustBaseline($cursor, $mailbox, $epoch, $active);
            $items = $baseline ? [] : $this->repository->candidates((int)$mailbox['id'], (int)$cursor['high_uid'], $highest);
            // The Mail synchronizer can run concurrently. Never advance our watermark across a cache reset or an in-flight sync.
            $current = $this->repository->mailbox((int)$mailbox['id']);
            if ($current === null || !NotificationPolicy::ready($current, $now)
                || $current['sync_new_token'] !== $mailbox['sync_new_token']) { return; }
            if ($baseline) {
                if ($cursor !== null && (!$active || $cursor['epoch'] !== $epoch || !(bool)$cursor['active'])) {
                    $this->repository->cancelUser($mailbox['nc_uid'], $now);
                }
            } else {
                foreach ($items as $item) {
                    // New INBOX UIDs are arrival events even if another client already marked them read.
                    if (!(bool)$item['flag_deleted'] && !(bool)$item['flag_draft']) {
                        $this->repository->enqueue($mailbox, (int)$item['uid'], $now);
                    }
                }
                // A bounded page may not reach the captured maximum; the next run continues at its last UID.
                $highest = $items === [] ? max((int)$cursor['high_uid'], $highest) : (int)$items[array_key_last($items)]['uid'];
            }
            $this->repository->saveCursor($mailbox, $epoch, $active, $highest, $now);
        });
    }

    private function batchAuthorized(array $batch): bool {
        $this->settings->reload();
        $mapping = $this->repository->mapping($batch['nc_uid']);
        if (!$this->userEnabled($batch['nc_uid'], $mapping) || $mapping['corp_id'] !== $batch['corp_id']
            || $mapping['ding_user_id'] !== $batch['ding_user_id'] || $batch['epoch'] !== $this->epoch($batch['nc_uid'])) {
            return false;
        }
        // CLI jobs can live for minutes. User::isEnabled and IConfig cache values,
        // so re-read the small authorization projection before an external action.
        $fresh = $this->repository->authorizationOverrides($batch['nc_uid']);
        if (($fresh['user_enabled'] ?? 'true') !== 'true'
            || in_array($fresh['user_deleted'] ?? 'false', ['true', '1'], true)
            || ($fresh['user_notifications_enabled'] ?? '1') !== '1'
            || (isset($fresh['global_notifications_enabled']) && $fresh['global_notifications_enabled'] !== '1')) { return false; }
        $epoch = hash('sha256', implode("\0", [
            $fresh['global_epoch'] ?? (string)$this->settings->get('notifications_epoch', 'initial'),
            $fresh['runtime_epoch'] ?? 'initial', $fresh['user_epoch'] ?? 'initial',
        ]));
        return hash_equals($batch['epoch'], $epoch);
    }

    private function process(array $batch, int $now): void {
        if (!$this->batchAuthorized($batch)) {
            $alreadyAccepted = $batch['state'] === 'submitted';
            $this->repository->updateBatch($batch['batch_key'], ['state' => $alreadyAccepted ? 'uncertain' : 'cancelled',
                'updated_at' => $now, 'error_code' => $alreadyAccepted ? 'accepted_before_authorization_changed' : 'recipient_or_authorization_changed'], $batch['state']);
            return;
        }
        if ($batch['state'] === 'submitted') { $this->poll($batch, $now); return; }
        $attempt = (int)$batch['attempts'] + 1;
        if (!$this->repository->updateBatch($batch['batch_key'], ['state' => 'sending', 'attempts' => $attempt,
            'updated_at' => $now, 'error_code' => null], $batch['state'])) { return; }
        try {
            $agentId = $this->agentId();
            $url = $this->urls->linkToRouteAbsolute('mailhub_dingtalk.login.index');
            $payload = NotificationPolicy::payload($batch, $agentId, $url, $this->settings->corpId());
        } catch (\Throwable) {
            $this->repository->updateBatch($batch['batch_key'], ['state' => 'failed', 'updated_at' => $now, 'error_code' => 'invalid_notification_configuration'], 'sending');
            return;
        }
        try {
            // A token failure occurs before submission, so retrying this stage cannot duplicate a notification.
            $this->client->accessToken();
        } catch (\Throwable) {
            $this->retry($batch, $now, $attempt, 'application_token_unavailable');
            return;
        }
        // A slow token request leaves time for an administrator or the employee
        // to revoke delivery. Recheck fresh preferences and the durable batch state.
        try {
            $authorized = $this->repository->batchState($batch['batch_key']) === 'sending' && $this->batchAuthorized($batch)
                && $this->agentId() === $agentId;
        } catch (\Throwable) {
            // No message request has been made yet, so this failure is safe to retry.
            $this->retry($batch, $now, $attempt, 'pre_submission_check_failed');
            return;
        }
        if (!$authorized) {
            $this->repository->updateBatch($batch['batch_key'], ['state' => 'cancelled', 'updated_at' => $now,
                'error_code' => 'authorization_changed_before_submission'], 'sending');
            return;
        }
        try {
            $response = $this->client->postLegacy('/topapi/message/corpconversation/asyncsend_v2', $payload);
            $task = (string)($response['task_id'] ?? '');
            if (!preg_match('/^[0-9]{1,64}$/D', $task)) { throw new \UnexpectedValueException('Missing task id'); }
            $this->repository->updateBatch($batch['batch_key'], ['state' => 'submitted', 'task_id' => $task,
                'updated_at' => $now, 'next_attempt' => $now + 60, 'error_code' => null], 'sending');
        } catch (DingTalkApiException $e) {
            $this->retry($batch, $now, $attempt, 'dingtalk_api_' . $e->getApiCode());
        } catch (\Throwable) {
            // The server may have accepted a request before a timeout/crash. There is no public idempotency key here.
            $this->repository->updateBatch($batch['batch_key'], ['state' => 'uncertain', 'updated_at' => $now,
                'error_code' => 'submission_result_unknown'], 'sending');
        }
    }
    private function agentId(): int {
        $value = $this->settings->agentId();
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($id === false) { throw new \RuntimeException('Agent id required'); }
        return $id;
    }
    private function retry(array $batch, int $now, int $attempt, string $code, string $expectedState = 'sending'): void {
        $this->repository->updateBatch($batch['batch_key'], [
            'state' => $attempt >= NotificationPolicy::MAX_SEND_ATTEMPTS ? 'failed' : 'retry',
            'attempts' => $attempt, 'poll_attempts' => 0, 'task_id' => null, 'updated_at' => $now,
            'next_attempt' => $now + NotificationPolicy::retryDelay($attempt), 'error_code' => $code,
        ], $expectedState);
    }
    private function poll(array $batch, int $now): void {
        $attempt = (int)$batch['poll_attempts'] + 1;
        try {
            $response = $this->client->postLegacy('/topapi/message/corpconversation/getsendresult', [
                'agent_id' => $this->agentId(), 'task_id' => $batch['task_id'],
            ]);
            $state = NotificationPolicy::deliveryState($response, $batch['ding_user_id']);
            if ($state === 'retry') {
                $this->retry($batch, $now, (int)$batch['attempts'], 'recipient_delivery_failed', 'submitted');
                return;
            }
            if ($state === 'delivered' || $state === 'failed') {
                $this->repository->updateBatch($batch['batch_key'], ['state' => $state, 'updated_at' => $now,
                    'poll_attempts' => $attempt, 'error_code' => $state === 'failed' ? 'recipient_invalid_or_forbidden' : null], 'submitted');
                return;
            }
            $code = 'delivery_not_confirmed';
        } catch (DingTalkApiException $e) { $code = 'result_api_' . $e->getApiCode(); }
        catch (\Throwable) { $code = 'result_query_failed'; }
        $expired = $attempt >= NotificationPolicy::MAX_POLL_ATTEMPTS || (int)$batch['created_at'] < $now - 20 * 3600;
        $this->repository->updateBatch($batch['batch_key'], ['state' => $expired ? 'uncertain' : 'submitted',
            'poll_attempts' => $attempt, 'updated_at' => $now, 'next_attempt' => $now + NotificationPolicy::retryDelay($attempt), 'error_code' => $code], 'submitted');
    }
}
