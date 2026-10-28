<?php
declare(strict_types=1);
namespace OCA\MailhubDingtalk\Service;

use OCP\IConfig;
use OCP\IUserManager;

/** Controllers must authorize the requesting user before calling administration methods. */
final class NotificationStatusService {
    private const APP = 'mailhub_dingtalk';
    public function __construct(private NotificationRepository $repository, private NotificationService $notifications,
        private Settings $settings, private IConfig $config, private IUserManager $users) {}
    private function common(): array {
        $version = $this->config->getAppValue('mail', 'installed_version', '');
        $digestSeconds = NotificationPolicy::digestSeconds($this->settings->get('notification_digest_seconds', NotificationPolicy::DEFAULT_DIGEST_SECONDS));
        return ['global_enabled' => $this->notifications->globalEnabled(), 'mail_version' => $version,
            'mail_version_supported' => in_array($version, NotificationPolicy::MAIL_VERSIONS, true),
            'last_job_attempt' => (int)$this->config->getAppValue(self::APP, 'notifications_last_attempt', '0'),
            'last_job_success' => (int)$this->config->getAppValue(self::APP, 'notifications_last_successful_run', '0'),
            'last_job_error' => $this->config->getAppValue(self::APP, 'notifications_last_error', ''),
            'source_sync_interval' => $this->config->getSystemValueInt('app.mail.background-sync-interval', 0),
            'digest_seconds' => $digestSeconds,
            'history_retention_days' => 90,
            'delay_note' => $digestSeconds === 0
                ? '邮件同步后，通知扫描会合并当前新增邮件并立即提交，不再额外等待摘要窗口；同步、任务排队、正在处理的通知及钉钉接口仍可能带来延迟。'
                : sprintf('当前设置额外等待 %d 秒合并摘要；同步、任务排队、正在处理的通知及钉钉接口仍可能带来延迟。', $digestSeconds),
            'privacy_note' => '仅发送个人新邮件数量与应用链接，不发送正文、附件或主题。',
            'delivery_note' => 'submitted 只表示钉钉已接收任务；delivered 表示接口确认投递，不能证明员工已看见。uncertain 不自动重发。'];
    }
    private function summarize(array $status): array {
        $counts = []; $success = 0; $failure = 0;
        foreach ($status['states'] as $row) {
            $counts[$row['state']] = (int)$row['total'];
            if ($row['state'] === 'delivered') { $success = (int)$row['last_at']; }
            if (in_array($row['state'], ['failed', 'uncertain'], true)) { $failure = max($failure, (int)$row['last_at']); }
        }
        return $status + ['queue_counts' => $counts, 'last_success' => $success, 'last_failure' => $failure];
    }
    public function forUser(string $ncUid): array {
        return $this->common() + $this->summarize($this->repository->status($ncUid)) + [
            'enabled' => $this->notifications->userEnabled($ncUid),
            'preference' => $this->config->getUserValue($ncUid, self::APP, 'notifications_enabled', '1') === '1'];
    }
    public function adminStatus(): array { return $this->common() + $this->summarize($this->repository->status(null)); }
    public function setUserEnabled(string $ncUid, bool $enabled): void {
        if ($this->users->get($ncUid) === null || $this->repository->mapping($ncUid) === null) {
            throw new \InvalidArgumentException('Unknown mapped employee');
        }
        $value = $enabled ? '1' : '0';
        if ($this->config->getUserValue($ncUid, self::APP, 'notifications_enabled', '1') === $value) {
            return;
        }
        $this->config->setUserValue($ncUid, self::APP, 'notifications_enabled', $value);
        $this->config->setUserValue($ncUid, self::APP, 'notifications_epoch', bin2hex(random_bytes(16)));
        $this->repository->cancelUser($ncUid, time());
    }
    public function accountsForUsers(array $ncUids): array { return $this->repository->accountsForUsers($ncUids); }
}
