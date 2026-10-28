<?php
declare(strict_types=1);
namespace OCA\MailhubDingtalk\Service;

use OCP\IDBConnection;
use OCP\DB\QueryBuilder\IQueryBuilder;

/** Own tables are writable; the Mail adapter below selects only non-secret cache metadata. */
final class NotificationRepository {
    public const CURSORS = 'mailhub_dt_ncursors';
    public const ITEMS = 'mailhub_dt_nitems';
    public const BATCHES = 'mailhub_dt_nbatches';
    public function __construct(private IDBConnection $db) {}

    private function rows($q): array { $r = $q->executeQuery(); try { return $r->fetchAll(); } finally { $r->closeCursor(); } }
    public function transaction(callable $callback): mixed {
        $this->db->beginTransaction();
        try { $result = $callback(); $this->db->commit(); return $result; }
        catch (\Throwable $e) { $this->db->rollBack(); throw $e; }
    }
    private function insert(string $table, array $values): void {
        $q = $this->db->getQueryBuilder(); $q->insert($table);
        foreach ($values as $key => $value) {
            $q->setValue($key, $q->createNamedParameter($value, is_bool($value) ? IQueryBuilder::PARAM_BOOL : (is_int($value) ? IQueryBuilder::PARAM_INT : ($value === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_STR))));
        }
        $q->executeStatement();
    }
    public function inboxes(): array {
        $q = $this->db->getQueryBuilder();
        $q->select('mb.id', 'mb.account_id', 'mb.name', 'mb.sync_new_token', 'mb.sync_changed_token', 'mb.sync_vanished_token',
            'mb.sync_new_lock', 'mb.sync_changed_lock', 'mb.sync_vanished_lock',
            'map.corp_id', 'map.ding_user_id')
            ->selectAlias('a.user_id', 'nc_uid')
            ->from('mail_mailboxes', 'mb')->innerJoin('mb', 'mail_accounts', 'a', $q->expr()->eq('a.id', 'mb.account_id'))
            ->innerJoin('a', 'mailhub_dt_users', 'map', $q->expr()->eq('map.nc_uid', 'a.user_id'))
            ->where($q->expr()->eq($q->func()->lower('mb.name'), $q->createNamedParameter('inbox')))
            ->orderBy('mb.id', 'ASC');
        return $this->rows($q);
    }
    public function mapping(string $uid): ?array {
        $q = $this->db->getQueryBuilder();
        $q->select('nc_uid', 'corp_id', 'ding_user_id')->from('mailhub_dt_users')
            ->where($q->expr()->eq('nc_uid', $q->createNamedParameter($uid)));
        return $this->rows($q)[0] ?? null;
    }
    /** Read only non-secret authorization fields directly, bypassing per-process IConfig/User caches. */
    public function authorizationOverrides(string $uid): array {
        $out = [];
        $q = $this->db->getQueryBuilder();
        $q->select('configkey', 'configvalue')->from('appconfig')
            ->where($q->expr()->eq('appid', $q->createNamedParameter('mailhub_dingtalk')))
            ->andWhere($q->expr()->in('configkey', $q->createNamedParameter(
                ['notifications_enabled', 'notifications_epoch', 'notifications_runtime_epoch'], IQueryBuilder::PARAM_STR_ARRAY)));
        $globalKeys = ['notifications_enabled' => 'global_notifications_enabled', 'notifications_epoch' => 'global_epoch',
            'notifications_runtime_epoch' => 'runtime_epoch'];
        foreach ($this->rows($q) as $row) { $out[$globalKeys[$row['configkey']]] = (string)$row['configvalue']; }
        $q = $this->db->getQueryBuilder();
        $q->select('appid', 'configkey', 'configvalue')->from('preferences')
            ->where($q->expr()->eq('userid', $q->createNamedParameter($uid)))
            ->andWhere($q->expr()->orX(
                $q->expr()->andX($q->expr()->eq('appid', $q->createNamedParameter('core')),
                    $q->expr()->in('configkey', $q->createNamedParameter(['enabled', 'deleted'], IQueryBuilder::PARAM_STR_ARRAY))),
                $q->expr()->andX($q->expr()->eq('appid', $q->createNamedParameter('mailhub_dingtalk')),
                    $q->expr()->in('configkey', $q->createNamedParameter(['notifications_enabled', 'notifications_epoch'], IQueryBuilder::PARAM_STR_ARRAY)))
            ));
        foreach ($this->rows($q) as $row) {
            $key = $row['appid'] === 'core' ? 'user_' . $row['configkey']
                : ($row['configkey'] === 'notifications_enabled' ? 'user_notifications_enabled' : 'user_epoch');
            $out[$key] = (string)$row['configvalue'];
        }
        return $out;
    }
    public function batchState(string $key): ?string {
        $q = $this->db->getQueryBuilder(); $q->select('state')->from(self::BATCHES)
            ->where($q->expr()->eq('batch_key', $q->createNamedParameter($key)));
        return $this->rows($q)[0]['state'] ?? null;
    }
    public function mailbox(int $id): ?array {
        $q = $this->db->getQueryBuilder();
        $q->select('id', 'sync_new_token', 'sync_changed_token', 'sync_vanished_token', 'sync_new_lock', 'sync_changed_lock', 'sync_vanished_lock', 'name')->from('mail_mailboxes')
            ->where($q->expr()->eq('id', $q->createNamedParameter($id, IQueryBuilder::PARAM_INT)));
        return $this->rows($q)[0] ?? null;
    }
    public function cursor(int $mailbox): ?array {
        $q = $this->db->getQueryBuilder(); $q->select('*')->from(self::CURSORS)
            ->where($q->expr()->eq('mailbox_id', $q->createNamedParameter($mailbox, IQueryBuilder::PARAM_INT)));
        return $this->rows($q)[0] ?? null;
    }
    public function highUid(int $mailbox): int {
        $q = $this->db->getQueryBuilder(); $q->selectAlias($q->func()->max('uid'), 'highest')->from('mail_messages')
            ->where($q->expr()->eq('mailbox_id', $q->createNamedParameter($mailbox, IQueryBuilder::PARAM_INT)));
        return (int)($this->rows($q)[0]['highest'] ?? 0);
    }
    public function candidates(int $mailbox, int $after, int $through): array {
        $q = $this->db->getQueryBuilder();
        $q->select('uid', 'flag_deleted', 'flag_draft')->from('mail_messages')
            ->where($q->expr()->eq('mailbox_id', $q->createNamedParameter($mailbox, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->gt('uid', $q->createNamedParameter($after, IQueryBuilder::PARAM_INT)))
            ->andWhere($q->expr()->lte('uid', $q->createNamedParameter($through, IQueryBuilder::PARAM_INT)))
            ->orderBy('uid', 'ASC')->setMaxResults(500);
        return $this->rows($q);
    }
    public function saveCursor(array $mailbox, string $epoch, bool $active, int $high, int $now): void {
        $q = $this->db->getQueryBuilder(); $q->delete(self::CURSORS)
            ->where($q->expr()->eq('mailbox_id', $q->createNamedParameter((int)$mailbox['id'], IQueryBuilder::PARAM_INT)))->executeStatement();
        $this->insert(self::CURSORS, ['mailbox_id' => (int)$mailbox['id'], 'account_id' => (int)$mailbox['account_id'],
            'nc_uid' => $mailbox['nc_uid'], 'corp_id' => $mailbox['corp_id'], 'ding_user_id' => $mailbox['ding_user_id'],
            'uid_validity' => NotificationPolicy::validity($mailbox['sync_new_token']), 'high_uid' => $high,
            'epoch' => $epoch, 'active' => $active, 'checked_at' => $now]);
    }
    public function enqueue(array $mailbox, int $uid, int $now): void {
        $this->insert(self::ITEMS, ['event_key' => NotificationPolicy::eventKey($mailbox, $uid), 'nc_uid' => $mailbox['nc_uid'],
            'corp_id' => $mailbox['corp_id'], 'ding_user_id' => $mailbox['ding_user_id'], 'mailbox_id' => (int)$mailbox['id'],
            'imap_uid' => $uid, 'created_at' => $now, 'batch_key' => null]);
    }
    public function pendingUsers(): array {
        $q = $this->db->getQueryBuilder(); $q->selectDistinct('nc_uid')->from(self::ITEMS)
            ->where($q->expr()->isNull('batch_key'))->setMaxResults(1000);
        return array_column($this->rows($q), 'nc_uid');
    }
    public function cancelUser(string $uid, int $now): void {
        $q = $this->db->getQueryBuilder(); $q->delete(self::ITEMS)->where($q->expr()->eq('nc_uid', $q->createNamedParameter($uid)))
            ->andWhere($q->expr()->isNull('batch_key'))->executeStatement();
        $q = $this->db->getQueryBuilder(); $q->update(self::BATCHES)->set('state', $q->createNamedParameter('cancelled'))
            ->set('updated_at', $q->createNamedParameter($now, IQueryBuilder::PARAM_INT))
            ->set('error_code', $q->createNamedParameter('notifications_disabled_or_rebased'))
            ->where($q->expr()->eq('nc_uid', $q->createNamedParameter($uid)))
            ->andWhere($q->expr()->in('state', $q->createNamedParameter(['queued', 'retry'], IQueryBuilder::PARAM_STR_ARRAY)))
            ->executeStatement();
        // A request may already be in flight. Never claim it was definitely cancelled,
        // and never let a late success/retry update revive a revoked batch.
        $q = $this->db->getQueryBuilder(); $q->update(self::BATCHES)->set('state', $q->createNamedParameter('uncertain'))
            ->set('updated_at', $q->createNamedParameter($now, IQueryBuilder::PARAM_INT))
            ->set('error_code', $q->createNamedParameter('authorization_changed_during_submission'))
            ->where($q->expr()->eq('nc_uid', $q->createNamedParameter($uid)))
            ->andWhere($q->expr()->in('state', $q->createNamedParameter(['sending', 'submitted'], IQueryBuilder::PARAM_STR_ARRAY)))
            ->executeStatement();
    }
    public function createBatch(string $uid, array $mapping, string $epoch, int $now, int $delay): void {
        $this->transaction(function () use ($uid, $mapping, $epoch, $now, $delay): void {
            $q = $this->db->getQueryBuilder(); $q->select('batch_key')->from(self::BATCHES)
                ->where($q->expr()->eq('nc_uid', $q->createNamedParameter($uid)))
                // Accepted tasks have their own persistent id and polling lifecycle.
                // Waiting for their receipt must not hold newer arrivals hostage.
                ->andWhere($q->expr()->in('state', $q->createNamedParameter(['queued', 'retry', 'sending'], IQueryBuilder::PARAM_STR_ARRAY)))->setMaxResults(1);
            if ($this->rows($q) !== []) { return; }
            $q = $this->db->getQueryBuilder(); $q->select('id', 'created_at', 'corp_id', 'ding_user_id')->from(self::ITEMS)
                ->where($q->expr()->eq('nc_uid', $q->createNamedParameter($uid)))->andWhere($q->expr()->isNull('batch_key'))
                ->orderBy('id', 'ASC')->setMaxResults(1000);
            $items = $this->rows($q);
            if ($items === [] || (int)$items[0]['created_at'] + $delay > $now) { return; }
            foreach ($items as $item) {
                if ($item['corp_id'] !== $mapping['corp_id'] || $item['ding_user_id'] !== $mapping['ding_user_id']) {
                    $this->cancelUser($uid, $now); return;
                }
            }
            $key = bin2hex(random_bytes(16));
            $this->insert(self::BATCHES, ['batch_key' => $key, 'nc_uid' => $uid, 'corp_id' => $mapping['corp_id'],
                'ding_user_id' => $mapping['ding_user_id'], 'epoch' => $epoch, 'message_count' => count($items),
                'state' => 'queued', 'attempts' => 0, 'poll_attempts' => 0, 'created_at' => $now, 'updated_at' => $now,
                'next_attempt' => $now, 'task_id' => null, 'error_code' => null]);
            $q = $this->db->getQueryBuilder(); $q->update(self::ITEMS)->set('batch_key', $q->createNamedParameter($key))
                ->where($q->expr()->in('id', $q->createNamedParameter(array_map('intval', array_column($items, 'id')), IQueryBuilder::PARAM_INT_ARRAY)))
                ->andWhere($q->expr()->isNull('batch_key'))->executeStatement();
        });
    }
    public function dueBatches(int $now): array {
        $q = $this->db->getQueryBuilder(); $q->select('*')->from(self::BATCHES)
            ->where($q->expr()->in('state', $q->createNamedParameter(['queued', 'retry', 'submitted'], IQueryBuilder::PARAM_STR_ARRAY)))
            ->andWhere($q->expr()->lte('next_attempt', $q->createNamedParameter($now, IQueryBuilder::PARAM_INT)))
            ->orderBy('next_attempt', 'ASC')->setMaxResults(50);
        return $this->rows($q);
    }
    public function updateBatch(string $key, array $fields, ?string $expectedState = null): bool {
        $q = $this->db->getQueryBuilder(); $q->update(self::BATCHES);
        foreach ($fields as $name => $value) {
            $q->set($name, $q->createNamedParameter($value, is_int($value) ? IQueryBuilder::PARAM_INT : ($value === null ? IQueryBuilder::PARAM_NULL : IQueryBuilder::PARAM_STR)));
        }
        $q->where($q->expr()->eq('batch_key', $q->createNamedParameter($key)));
        if ($expectedState !== null) { $q->andWhere($q->expr()->eq('state', $q->createNamedParameter($expectedState))); }
        return $q->executeStatement() === 1;
    }
    public function recoverAbandonedSends(int $now): void {
        $q = $this->db->getQueryBuilder(); $q->update(self::BATCHES)
            ->set('state', $q->createNamedParameter('uncertain'))->set('error_code', $q->createNamedParameter('sender_interrupted_result_unknown'))
            ->set('updated_at', $q->createNamedParameter($now, IQueryBuilder::PARAM_INT))
            ->where($q->expr()->eq('state', $q->createNamedParameter('sending')))
            ->andWhere($q->expr()->lt('updated_at', $q->createNamedParameter($now - 600, IQueryBuilder::PARAM_INT)))->executeStatement();
    }
    public function pruneHistory(int $now): void {
        $q = $this->db->getQueryBuilder(); $q->select('batch_key')->from(self::BATCHES)
            ->where($q->expr()->in('state', $q->createNamedParameter(['delivered', 'failed', 'uncertain', 'cancelled'], IQueryBuilder::PARAM_STR_ARRAY)))
            ->andWhere($q->expr()->lt('updated_at', $q->createNamedParameter($now - 90 * 86400, IQueryBuilder::PARAM_INT)))
            ->setMaxResults(500);
        $keys = array_column($this->rows($q), 'batch_key');
        if ($keys === []) { return; }
        $this->transaction(function () use ($keys): void {
            foreach ([self::ITEMS, self::BATCHES] as $table) {
                $q = $this->db->getQueryBuilder(); $q->delete($table)
                    ->where($q->expr()->in('batch_key', $q->createNamedParameter($keys, IQueryBuilder::PARAM_STR_ARRAY)))->executeStatement();
            }
        });
        // Persistent mailbox watermarks are not pruned; old messages cannot be re-enqueued just because audit rows expire.
    }
    public function status(?string $uid): array {
        $q = $this->db->getQueryBuilder(); $q->select('state')->selectAlias($q->func()->count('*'), 'total')
            ->selectAlias($q->func()->max('updated_at'), 'last_at')->from(self::BATCHES)->groupBy('state');
        if ($uid !== null) { $q->where($q->expr()->eq('nc_uid', $q->createNamedParameter($uid))); }
        $states = $this->rows($q);
        $q = $this->db->getQueryBuilder(); $q->selectAlias($q->func()->count('*'), 'total')->from(self::ITEMS)->where($q->expr()->isNull('batch_key'));
        if ($uid !== null) { $q->andWhere($q->expr()->eq('nc_uid', $q->createNamedParameter($uid))); }
        $pending = (int)($this->rows($q)[0]['total'] ?? 0);
        $q = $this->db->getQueryBuilder(); $q->selectAlias($q->func()->count('*'), 'total')
            ->selectAlias($q->func()->max('checked_at'), 'last_at')->from(self::CURSORS);
        if ($uid !== null) { $q->where($q->expr()->eq('nc_uid', $q->createNamedParameter($uid))); }
        $baseline = $this->rows($q)[0] ?? [];
        $q = $this->db->getQueryBuilder(); $q->select('state', 'error_code', 'updated_at')->from(self::BATCHES)
            ->where($q->expr()->isNotNull('error_code'))->orderBy('updated_at', 'DESC')->setMaxResults(5);
        if ($uid !== null) { $q->andWhere($q->expr()->eq('nc_uid', $q->createNamedParameter($uid))); }
        return ['states' => $states, 'pending_messages' => $pending, 'baseline_count' => (int)($baseline['total'] ?? 0),
            'last_scan' => (int)($baseline['last_at'] ?? 0), 'recent_errors' => $this->rows($q)];
    }
    public function accountsForUsers(array $uids): array {
        if ($uids === []) { return []; }
        if (count($uids) > 1000) { throw new \InvalidArgumentException('At most 1000 users per request'); }
        $q = $this->db->getQueryBuilder();
        $q->select('id', 'user_id', 'email', 'name', 'inbound_host', 'inbound_port', 'outbound_host', 'outbound_port', 'last_mailbox_sync')
            ->from('mail_accounts')->where($q->expr()->in('user_id', $q->createNamedParameter(array_values($uids), IQueryBuilder::PARAM_STR_ARRAY)));
        $result = [];
        foreach ($this->rows($q) as $row) {
            $result[$row['user_id']][] = ['id' => (int)$row['id'], 'email' => $row['email'], 'name' => $row['name'],
                'imap_host' => $row['inbound_host'], 'imap_port' => (int)$row['inbound_port'],
                'smtp_host' => $row['outbound_host'], 'smtp_port' => (int)$row['outbound_port'],
                'last_mailbox_sync' => (int)$row['last_mailbox_sync']];
        }
        return $result;
    }
}
