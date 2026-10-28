<?php
declare(strict_types=1);
namespace OCA\MailhubDingtalk\Service;

use OC\Security\CSRF\CsrfTokenManager;
use OCP\ISession;
use OCP\IUserSession;

/** Called only by the CSRF-protected begin endpoint, before loading DingTalk's SDK. */
final class LoginFlow {
    public function __construct(private LoginNonce $nonce, private IUserSession $users,
        private ISession $session, private CsrfTokenManager $csrf) {}

    public function begin(bool $secure, string $nonce): array {
        if (!$secure || !$this->nonce->consume($nonce, 'begin')) {
            throw new \RuntimeException('Invalid login preparation');
        }
        // No application configuration or upstream call may precede clearing the old identity.
        // logout clears the session and invalidates its authentication token, even if login is paused.
        $this->users->logout();
        $this->session->regenerateId();
        // The manager caches the previous token in this request: explicitly refresh it after logout.
        $requestToken = $this->csrf->refreshToken()->getEncryptedValue();
        return ['nonce' => $this->nonce->issue('authenticate'), 'requesttoken' => $requestToken];
    }
}
