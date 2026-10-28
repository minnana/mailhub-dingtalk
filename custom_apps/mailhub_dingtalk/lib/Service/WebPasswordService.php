<?php
declare(strict_types=1);
namespace OCA\MailhubDingtalk\Service;

use OC\Authentication\Token\IProvider;
use OCP\Config\IUserConfig;
use OCP\Encryption\IManager as EncryptionManager;
use OCP\IConfig;
use OCP\ISession;
use OCP\IUser;
use OCP\IUserManager;
use OCP\IUserSession;

/** Changes only the current mapped employee, through Nextcloud's password API. */
class WebPasswordService {
    public function __construct(private IUserSession $users, private ISession $session, private IProvider $tokens,
        private UserProvisioner $provisioner, private Settings $settings, private DingTalkClient $dingTalk,
        private WebPasswordNonce $nonce, private NcSessionAdapter $sessions, private IConfig $config,
        private IUserConfig $userConfig, private EncryptionManager $encryption) {}

    /** @return array{uid:string,name:string} */
    public function account(): array {
        $user = $this->currentUser();
        return ['uid' => $user->getUID(), 'name' => $user->getDisplayName()];
    }

    private function currentUser(): IUser {
        if ((int)explode('.', $this->config->getSystemValueString('version', '0'))[0] !== 34) {
            throw new \RuntimeException('Unsupported password implementation');
        }
        $user = $this->users->getUser();
        if ($user === null || !$user->isEnabled() || $this->users->getImpersonatingUserID() !== null) {
            throw new \RuntimeException('Employee session required');
        }
        $uid = $user->getUID();
        // This public API explicitly bypasses cache, catching disable/delete during the DingTalk request.
        $enabled = $this->userConfig->getValuesByUsers('core', 'enabled', null, [$uid]);
        $deleted = $this->userConfig->getValuesByUsers('core', 'deleted', null, [$uid]);
        if ((isset($enabled[$uid]) && !in_array($enabled[$uid], [true, 'true', '1', 1], true))
            || in_array($deleted[$uid] ?? false, [true, 'true', '1', 1], true)) {
            throw new \RuntimeException('Employee account unavailable');
        }
        $mapping = $this->provisioner->findByUid($uid);
        if ($mapping === null || $mapping['corp_id'] !== $this->settings->corpId()
            || !hash_equals(UserProvisioner::stableUid($mapping['corp_id'], $mapping['ding_user_id']), $uid)
            || !$this->settings->permits($mapping['ding_user_id'])) {
            throw new \RuntimeException('Employee mapping unavailable');
        }
        $token = $this->tokens->getToken($this->session->getId());
        // getToken may use a 10-second local cache; NC34 getTokenById reads the mapper directly.
        $freshToken = $this->tokens->getTokenById($token->getId());
        if ($token->getUID() !== $uid || $freshToken->getUID() !== $uid) { throw new \RuntimeException('Employee token mismatch'); }
        return $user;
    }

    public static function validatePassword(#[\SensitiveParameter] string $password, #[\SensitiveParameter] string $confirmation): void {
        if ($password !== $confirmation) { throw new \InvalidArgumentException('两次输入的密码不一致。'); }
        if (!mb_check_encoding($password, 'UTF-8') || mb_strlen($password, 'UTF-8') < 12
            || mb_strlen($password, 'UTF-8') > 128 || strlen($password) > IUserManager::MAX_PASSWORD_LENGTH
            || preg_match('/[\x00-\x1F\x7F]/', $password)) {
            throw new \InvalidArgumentException('请使用 12–128 个字符的独立密码，不含换行或控制字符。');
        }
    }

    public function change(bool $https, string $nonce, #[\SensitiveParameter] string $code,
        #[\SensitiveParameter] string $password, #[\SensitiveParameter] string $confirmation): void {
        if (!$https) { throw new \RuntimeException('HTTPS required'); }
        $user = $this->currentUser(); $uid = $user->getUID();
        if (!$this->nonce->consume($uid, $nonce)) { throw new \RuntimeException('Password request expired'); }
        self::validatePassword($password, $confirmation);
        $this->settings->requireEnabled();
        if ($this->settings->get('login_enabled', true) !== true || $this->encryption->isEnabled() || !$user->canChangePassword()) {
            throw new \RuntimeException('SSO password reset unavailable');
        }
        $identity = $this->dingTalk->identity($code);
        // Recheck access after the network call, before any password write.
        $this->settings->reload(); $this->settings->requireEnabled();
        $current = $this->currentUser();
        if ($current->getUID() !== $uid || !hash_equals(UserProvisioner::stableUid($this->settings->corpId(), $identity['user_id']), $uid)
            || $this->settings->get('login_enabled', true) !== true || $this->encryption->isEnabled() || !$current->canChangePassword()) {
            throw new \RuntimeException('Fresh identity does not authorize this account');
        }
        try {
            // This invokes native hashing and BeforePasswordUpdated/PasswordUpdated hooks.
            // Mail's encrypted credentials use the instance secret, not this password.
            if (!$current->setPassword($password)) { throw new \RuntimeException('Password backend rejected update'); }
        } finally {
            // Even if a post-update listener throws, old sessions must not remain valid.
            // Delete passwordless SSO tokens as well; a later SSO creates fresh scopes.
            try { $this->sessions->revoke($uid); }
            finally { $this->users->logout(); }
        }
    }
}
