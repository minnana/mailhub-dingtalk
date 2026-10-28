<?php
declare(strict_types=1);
namespace OCA\MailhubDingtalk\Service;
use OCP\ISession;

class LoginNonce {
    private const KEY = 'mailhub_dingtalk.login';
    public function __construct(private ISession $session) {}
    public function issue(string $purpose = 'authenticate'): string {
        $nonce = bin2hex(random_bytes(32));
        $this->session->set(self::KEY, ['hash' => hash('sha256', $nonce), 'expires' => time() + 300, 'purpose' => $purpose]);
        return $nonce;
    }
    public function consume(string $nonce, string $purpose = 'authenticate'): bool {
        $saved = $this->session->get(self::KEY);
        // Consume before any network request; retries must start a new login flow.
        $this->session->remove(self::KEY);
        return is_array($saved) && strlen($nonce) === 64 && ($saved['expires'] ?? 0) >= time()
            && ($saved['purpose'] ?? '') === $purpose
            && is_string($saved['hash'] ?? null) && hash_equals($saved['hash'], hash('sha256', $nonce));
    }
}
