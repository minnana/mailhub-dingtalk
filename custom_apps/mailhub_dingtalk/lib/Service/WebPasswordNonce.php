<?php
declare(strict_types=1);
namespace OCA\MailhubDingtalk\Service;

use OCP\AppFramework\Utility\ITimeFactory;
use OCP\ISession;

/** A distinct, user-bound nonce; never shares or replaces an SSO login nonce. */
class WebPasswordNonce {
    private const KEY = 'mailhub_dingtalk.web_password';
    public function __construct(private ISession $session, private ITimeFactory $clock) {}
    public function issue(string $uid): string {
        $value = bin2hex(random_bytes(32));
        $this->session->set(self::KEY, ['uid' => $uid, 'hash' => hash('sha256', $value), 'expires' => $this->clock->getTime() + 300]);
        return $value;
    }
    public function consume(string $uid, string $value): bool {
        $saved = $this->session->get(self::KEY);
        $this->session->remove(self::KEY);
        return is_array($saved) && ($saved['uid'] ?? '') === $uid
            && ($saved['expires'] ?? 0) >= $this->clock->getTime()
            && preg_match('/^[a-f0-9]{64}$/D', $value) === 1
            && is_string($saved['hash'] ?? null) && hash_equals($saved['hash'], hash('sha256', $value));
    }
}
