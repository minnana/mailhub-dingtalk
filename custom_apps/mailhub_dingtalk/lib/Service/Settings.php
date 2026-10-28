<?php
declare(strict_types=1);
namespace OCA\MailhubDingtalk\Service;

use OCP\IConfig;
use RuntimeException;

class Settings {
    private ?array $values = null;
    private const APP = 'mailhub_dingtalk';
    public function __construct(private IConfig $config) {}

    private function values(): array {
        if ($this->values !== null) { return $this->values; }
        $values = $this->config->getSystemValue(self::APP, []);
        if (!is_array($values)) { throw new RuntimeException('Invalid Mailhub system configuration'); }
        $path = getenv('MAILHUB_DINGTALK_CONFIG');
        if ($path !== false && $path !== '') {
            if (!str_starts_with($path, '/') || !is_file($path) || !is_readable($path) || filesize($path) > 65536) {
                throw new RuntimeException('Mailhub configuration file is unavailable');
            }
            $json = json_decode((string)file_get_contents($path), true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($json) || array_is_list($json)) { throw new RuntimeException('Invalid Mailhub configuration'); }
            $values = array_replace($values, $json);
        }
        return $this->values = $values;
    }

    public function get(string $key, mixed $default = null): mixed {
        // Only these non-secret preferences are writable from the administrator UI.
        if (in_array($key, ['login_enabled', 'notifications_enabled', 'notifications_epoch'], true)) {
            $stored = $this->config->getAppValue(self::APP, $key, '');
            if ($stored !== '') { return $key === 'notifications_epoch' ? $stored : $stored === '1'; }
        }
        return $this->values()[$key] ?? $default;
    }
    public function corpId(): string { return (string)$this->get('corp_id', ''); }
    public function clientId(): string { return (string)$this->get('client_id', ''); }
    public function agentId(): string { return (string)$this->get('agent_id', ''); }
    /** Re-read the restricted policy file after a remote identity check. */
    public function reload(): void { $this->values = null; }
    public function clientSecret(): string {
        $path = (string)$this->get('client_secret_file', '');
        if ($path !== '') {
            if (!str_starts_with($path, '/') || !is_file($path) || !is_readable($path) || filesize($path) > 16384) {
                throw new RuntimeException('Application secret file is unavailable');
            }
            return trim((string)file_get_contents($path));
        }
        return (string)$this->get('client_secret', '');
    }
    public function requireEnabled(): void {
        if ($this->get('enabled', false) !== true || $this->corpId() === '' || $this->clientId() === '' || $this->clientSecret() === '') {
            throw new RuntimeException('DingTalk application is not configured');
        }
    }
    public function permits(string $dingUserId): bool {
        $allow = $this->get('allowed_userids', []);
        return $this->get('allow_all_corporate_users', false) === true
            || (is_array($allow) && in_array($dingUserId, $allow, true));
    }
    public function publicStatus(): array {
        try {
            $secretPresent = $this->clientSecret() !== '';
            return [
                'configured' => $this->corpId() !== '' && $this->clientId() !== '' && $secretPresent,
                'enabled' => $this->get('enabled', false) === true,
                'login_enabled' => $this->get('login_enabled', true) === true,
                'notifications_enabled' => $this->get('notifications_enabled', false) === true,
                'corp_id' => $this->corpId(), 'client_id' => $this->clientId(), 'agent_id' => $this->agentId(),
                'secret_present' => $secretPresent,
                'all_corporate_users' => $this->get('allow_all_corporate_users', false) === true,
                'allowed_count' => count((array)$this->get('allowed_userids', [])),
            ];
        } catch (\Throwable) { return ['configured' => false, 'enabled' => false, 'error' => '配置文件缺少或格式错误，请联系服务器管理员。']; }
    }
}
