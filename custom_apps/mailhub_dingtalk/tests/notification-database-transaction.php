<?php
declare(strict_types=1);
/** Rollback-only integration test for the notification tables. Never calls DingTalk or modifies Mail tables. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('OC_CONSOLE', 1);
require (getenv('NEXTCLOUD_ROOT') ?: '/var/www/html') . '/lib/base.php';

use OCA\MailhubDingtalk\Service\NotificationRepository;

final class NotificationProbeRollback extends \RuntimeException {}
$repository = \OCP\Server::get(NotificationRepository::class);
$checks = 0;
function check(bool $condition, string $name): void {
    global $checks;
    ++$checks;
    if (!$condition) { throw new \RuntimeException('FAILED: ' . $name); }
}
$probe = '__mh_probe_' . bin2hex(random_bytes(12));
// Actual IMAP mailbox ids are positive. A negative random id cannot replace a real cursor.
$mailboxId = -random_int(1000000, 2000000000);
$box = ['id' => $mailboxId, 'account_id' => -1, 'nc_uid' => $probe, 'corp_id' => $probe,
    'ding_user_id' => $probe, 'sync_new_token' => base64_encode('V42,U103,H1')];
$mapping = ['nc_uid' => $probe, 'corp_id' => $probe, 'ding_user_id' => $probe];
check($repository->cursor($mailboxId) === null, 'probe cursor does not already exist');
check($repository->status($probe)['states'] === [], 'probe queue does not already exist');
try {
    $repository->transaction(function () use ($repository, $box, $mapping, $probe, $mailboxId): void {
        $repository->saveCursor($box, 'probe-epoch', true, 101, 1);
        check((int)$repository->cursor($mailboxId)['high_uid'] === 101, 'cursor stores UID watermark');
        $repository->enqueue($box, 102, 1);
        check($repository->status($probe)['pending_messages'] === 1, 'event is persisted as pending');
        $repository->createBatch($probe, $mapping, 'probe-epoch', 62, 60);
        $batches = array_values(array_filter($repository->dueBatches(62), static fn (array $b): bool => $b['nc_uid'] === $probe));
        check(count($batches) === 1 && (int)$batches[0]['message_count'] === 1, 'nested transaction atomically creates one digest');
        check($repository->status($probe)['pending_messages'] === 0, 'event is assigned to digest');
        $key = $batches[0]['batch_key'];
        check(!$repository->updateBatch($key, ['state' => 'failed'], 'submitted'), 'state transition rejects stale expected state');
        check($repository->updateBatch($key, ['state' => 'sending', 'attempts' => 1, 'updated_at' => 63], 'queued'), 'state transition claims sender once');
        check(!$repository->updateBatch($key, ['state' => 'sending'], 'queued'), 'second sender cannot claim same digest');
        $repository->updateBatch($key, ['state' => 'submitted', 'task_id' => '1', 'updated_at' => 64]);
        $repository->enqueue($box, 103, 64);
        $repository->createBatch($probe, $mapping, 'probe-epoch', 64, 0);
        check($repository->status($probe)['pending_messages'] === 0, 'accepted receipt polling does not block a newer digest');
        check($repository->batchState($key) === 'submitted', 'new digest does not change accepted task');
        $repository->cancelUser($probe, 65);
        $states = array_column($repository->status($probe)['states'], 'total', 'state');
        check((int)($states['cancelled'] ?? 0) === 1 && (int)($states['uncertain'] ?? 0) === 1, 'disable cancels unsent digest and marks accepted delivery uncertain');
        check(!$repository->updateBatch($key, ['state' => 'retry'], 'submitted') && $repository->batchState($key) === 'uncertain', 'late delivery response cannot revive a revoked task');
        $db = \OCP\Server::get(\OCP\IDBConnection::class);
        foreach ([['core', 'enabled', 'false'], ['core', 'deleted', 'true'], ['mailhub_dingtalk', 'notifications_enabled', '0'],
            ['mailhub_dingtalk', 'notifications_epoch', 'probe-new-epoch']] as [$app, $pref, $value]) {
            $q = $db->getQueryBuilder(); $q->insert('preferences')->values([
                'userid' => $q->createNamedParameter($probe), 'appid' => $q->createNamedParameter($app),
                'configkey' => $q->createNamedParameter($pref), 'configvalue' => $q->createNamedParameter($value)])->executeStatement();
        }
        $fresh = $repository->authorizationOverrides($probe);
        check($fresh['user_enabled'] === 'false' && $fresh['user_deleted'] === 'true'
            && $fresh['user_notifications_enabled'] === '0' && $fresh['user_epoch'] === 'probe-new-epoch', 'authorization guard sees persisted preferences independently of application caches');
        throw new NotificationProbeRollback();
    });
    throw new \RuntimeException('Rollback probe unexpectedly committed');
} catch (NotificationProbeRollback) {
    // The repository catches Throwable and rolls the outer transaction back before rethrowing.
}
check($repository->cursor($mailboxId) === null, 'cursor fixture rolled back');
check($repository->status($probe)['states'] === [] && $repository->status($probe)['pending_messages'] === 0, 'all queue fixtures rolled back');
check(!isset($repository->authorizationOverrides($probe)['user_epoch']), 'authorization preference fixtures rolled back');
echo "Notification database transaction: $checks checks passed; all probe rows were rolled back. No Mail data or real notification was touched.\n";
