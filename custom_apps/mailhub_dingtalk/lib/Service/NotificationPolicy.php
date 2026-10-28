<?php
declare(strict_types=1);
namespace OCA\MailhubDingtalk\Service;

/** Pure policy; no network, credentials, Nextcloud bootstrap or message bodies. */
final class NotificationPolicy {
    public const MAIL_VERSIONS = ['5.12.4', '5.12.5'];
    public const MAX_SEND_ATTEMPTS = 6;
    public const MAX_POLL_ATTEMPTS = 10;
    public const DEFAULT_DIGEST_SECONDS = 0;

    public static function digestSeconds(mixed $configured = self::DEFAULT_DIGEST_SECONDS): int {
        return max(0, min(3600, (int)$configured));
    }

    /** Mail 5.12.4/5.12.5 HordeSyncTokenParser uses base64 comma-separated V/U/H fields. */
    public static function validity(?string $token): ?string {
        if ($token === null || ($decoded = base64_decode($token, true)) === false) { return null; }
        foreach (explode(',', $decoded) as $part) {
            if (preg_match('/^V([1-9][0-9]{0,19})$/D', $part, $m)) { return $m[1]; }
        }
        return null;
    }

    public static function ready(array $mailbox, int $now): bool {
        if (strtolower((string)$mailbox['name']) !== 'inbox') { return false; }
        foreach (['sync_new_token', 'sync_changed_token', 'sync_vanished_token'] as $key) {
            if ($mailbox[$key] === null || $mailbox[$key] === '') { return false; }
        }
        foreach (['sync_new_lock', 'sync_changed_lock', 'sync_vanished_lock'] as $key) {
            if ($mailbox[$key] !== null && (int)$mailbox[$key] > $now - 300) { return false; }
        }
        return self::validity($mailbox['sync_new_token']) !== null;
    }

    public static function mustBaseline(?array $cursor, array $mailbox, string $epoch, bool $active): bool {
        return $cursor === null || !$active || !(bool)$cursor['active']
            || $cursor['epoch'] !== $epoch
            || (string)$cursor['account_id'] !== (string)$mailbox['account_id']
            || $cursor['nc_uid'] !== $mailbox['nc_uid']
            || $cursor['corp_id'] !== $mailbox['corp_id']
            || $cursor['ding_user_id'] !== $mailbox['ding_user_id']
            || $cursor['uid_validity'] !== self::validity($mailbox['sync_new_token']);
    }

    public static function eventKey(array $mailbox, int $imapUid): string {
        return hash('sha256', implode("\0", [$mailbox['nc_uid'], $mailbox['account_id'], $mailbox['id'],
            self::validity($mailbox['sync_new_token']), $imapUid]));
    }

    public static function retryDelay(int $attempt): int {
        return min(3600, 60 * (2 ** min(6, max(0, $attempt - 1))));
    }

    /** Acknowledgement of task creation is not acknowledgement of delivery. */
    public static function deliveryState(array $response, string $userId): string {
        $result = $response['send_result'] ?? null;
        if (!is_array($result)) { return 'pending'; }
        foreach (['invalid_user_id_list', 'failed_user_id_list', 'forbidden_list'] as $key) {
            foreach (($result[$key] ?? []) as $entry) {
                $id = is_array($entry) ? ($entry['userid'] ?? $entry['user_id'] ?? null) : $entry;
                if ((string)$id === $userId) { return $key === 'failed_user_id_list' ? 'retry' : 'failed'; }
            }
        }
        foreach (['read_user_id_list', 'unread_user_id_list'] as $key) {
            if (in_array($userId, array_map('strval', $result[$key] ?? []), true)) { return 'delivered'; }
        }
        return 'pending';
    }

    /** Wrap the server-generated login route, not a client-supplied redirect or a login token. */
    public static function dingtalkLink(string $url, string $corpId, int $agentId): string {
        $parts = parse_url($url);
        if (!is_array($parts) || strtolower((string)($parts['scheme'] ?? '')) !== 'https'
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || filter_var($url, FILTER_VALIDATE_URL) === false
            || preg_match('/[\x00-\x20\x7F]/', $url) || str_contains($url, '\\')) {
            throw new \InvalidArgumentException('Absolute HTTPS application URL without credentials required');
        }
        if (preg_match('/^[A-Za-z0-9_-]{1,128}$/D', $corpId) !== 1 || $agentId < 1) {
            throw new \InvalidArgumentException('Valid server-configured corporate application identifiers required');
        }
        // Each protocol layer is independently RFC3986 encoded. Desktop opens
        // this internal app's work-platform container; mobile opens its H5 URL.
        $pc = 'dingtalk://dingtalkclient/action/openapp?' . http_build_query([
            'corpid' => $corpId,
            'container_type' => 'work_platform',
            'app_id' => '0_' . $agentId,
            'redirect_type' => 'jump',
            'redirect_url' => $url,
        ], '', '&', PHP_QUERY_RFC3986);
        $mobile = 'dingtalk://dingtalkclient/page/link?url=' . rawurlencode($url);
        return 'dingtalk://dingtalkclient/action/open_platform_link?' . http_build_query([
            'pcLink' => $pc, 'mobileLink' => $mobile,
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public static function payload(array $batch, int $agentId, string $url, string $corpId): array {
        if (!is_string($batch['ding_user_id']) || $batch['ding_user_id'] === '' || strlen($batch['ding_user_id']) > 128
            || preg_match('/[,\x00-\x20\x7F]/', $batch['ding_user_id'])) {
            throw new \InvalidArgumentException('Invalid single recipient identifier');
        }
        $link = self::dingtalkLink($url, $corpId, $agentId);
        $time = (new \DateTimeImmutable('@' . (int)$batch['created_at']))
            ->setTimezone(new \DateTimeZone('Asia/Shanghai'))->format('m-d H:i:s');
        return [
            'agent_id' => $agentId,
            'userid_list' => $batch['ding_user_id'],
            'to_all_user' => false,
            'msg' => ['msgtype' => 'markdown', 'markdown' => [
                'title' => '企业邮箱：新邮件提醒',
                // Include a stable time per batch so provider same-content deduplication does not suppress later digests.
                'text' => sprintf("你有 **%d 封**新邮件。\n\n[打开企业邮箱](%s)\n\n提醒时间：%s", (int)$batch['message_count'], $link, $time),
            ]],
        ];
    }
}
