<?php
declare(strict_types=1);
namespace OCA\MailhubDingtalk\Service;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;

class UserProvisioner {
    public function __construct(private IDBConnection $db, private IUserManager $users, private Settings $settings) {}
    public static function stableUid(string $corp, string $user): string { return 'dt_' . substr(hash('sha256', $corp . "\0" . $user), 0, 48); }
    public function findByUid(string $uid): ?array {
        $q = $this->db->getQueryBuilder();
        $row = $q->select('*')->from('mailhub_dt_users')->where($q->expr()->eq('nc_uid', $q->createNamedParameter($uid)))->executeQuery()->fetchAssociative();
        return $row === false ? null : $row;
    }
    public function all(): array {
        $q = $this->db->getQueryBuilder();
        return $q->select('*')->from('mailhub_dt_users')->where($q->expr()->eq('corp_id', $q->createNamedParameter($this->settings->corpId())))->orderBy('id', 'ASC')->executeQuery()->fetchAllAssociative();
    }
    public function provision(string $dingUserId, string $name): IUser {
        if (!$this->settings->permits($dingUserId)) { throw new \RuntimeException('Employee is outside the application access policy'); }
        $corp = $this->settings->corpId();
        if ($corp === '' || strlen($corp) > 128 || $dingUserId === '' || strlen($dingUserId) > 128) { throw new \RuntimeException('Invalid corporate identity'); }
        $uid = self::stableUid($corp, $dingUserId);
        $row = $this->findByUid($uid);
        $user = $this->users->get($uid);
        if ($row === null) {
            // Never adopt a pre-existing local account based on an email/name/hash alone.
            if ($user !== null) { throw new \RuntimeException('Account mapping conflict'); }
            $q = $this->db->getQueryBuilder();
            $q->insert('mailhub_dt_users')->values([
                'corp_id' => $q->createNamedParameter($corp), 'ding_user_id' => $q->createNamedParameter($dingUserId),
                'nc_uid' => $q->createNamedParameter($uid), 'created_at' => $q->createNamedParameter(time()),
                'last_login' => $q->createNamedParameter(0),
            ])->executeStatement();
            try {
                $user = $this->users->createUser($uid, bin2hex(random_bytes(48)));
                if (!$user) { throw new \RuntimeException('Unable to create employee account'); }
                $user->setDisplayName($name !== '' ? $name : $dingUserId);
            } catch (\Throwable $e) {
                // A simultaneous login cannot adopt an unfinished mapping; it must retry.
                if ($this->users->get($uid) === null) {
                    $q = $this->db->getQueryBuilder();
                    $q->delete('mailhub_dt_users')->where($q->expr()->eq('nc_uid', $q->createNamedParameter($uid)))->executeStatement();
                }
                throw new \RuntimeException('Employee account provisioning failed');
            }
        } elseif ($row['corp_id'] !== $corp || $row['ding_user_id'] !== $dingUserId) {
            throw new \RuntimeException('Account mapping conflict');
        }
        if ($user === null || !$user->isEnabled()) { throw new \RuntimeException('Employee account is unavailable'); }
        return $user;
    }
    public function recordLogin(string $uid): void {
        $q = $this->db->getQueryBuilder();
        $q->update('mailhub_dt_users')->set('last_login', $q->createNamedParameter(time()))->where($q->expr()->eq('nc_uid', $q->createNamedParameter($uid)))->executeStatement();
    }
}
