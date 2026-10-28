<?php
declare(strict_types=1);
namespace OCA\MailhubDingtalk\Controller;

use OCA\MailhubDingtalk\Service\NcSessionAdapter;
use OCA\MailhubDingtalk\Service\NotificationStatusService;
use OCA\MailhubDingtalk\Service\Settings;
use OCA\MailhubDingtalk\Service\UserProvisioner;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IConfig;
use OCP\IGroupManager;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

/** All routes retain Nextcloud's default administrator and CSRF checks. */
class AdminController extends Controller {
    public function __construct(IRequest $request, private Settings $settings, private UserProvisioner $mapping,
        private NotificationStatusService $notifications, private NcSessionAdapter $sessions,
        private IUserManager $users, private IUserSession $userSession, private IGroupManager $groups,
        private IConfig $config, private IURLGenerator $urls, private LoggerInterface $logger) {
        parent::__construct('mailhub_dingtalk', $request);
    }
    #[NoCSRFRequired]
    public function index(): TemplateResponse {
        $response = new TemplateResponse('mailhub_dingtalk', 'admin', [
            'statusUrl' => $this->urls->linkToRoute('mailhub_dingtalk.admin.status'),
            'preferencesUrl' => $this->urls->linkToRoute('mailhub_dingtalk.admin.preferences'),
            'userStateUrl' => $this->urls->linkToRoute('mailhub_dingtalk.admin.userState'),
            'entryUrl' => $this->urls->linkToRouteAbsolute('mailhub_dingtalk.login.index'),
            'mailUrl' => $this->urls->getAbsoluteURL('/index.php/apps/mail/'),
            'usersUrl' => $this->urls->getAbsoluteURL('/index.php/settings/users'),
            'overviewUrl' => $this->urls->getAbsoluteURL('/index.php/settings/admin/overview'),
        ]);
        $response->addHeader('Cache-Control', 'no-store');
        return $response;
    }
    #[NoCSRFRequired]
    public function status(): JSONResponse {
        $rows = $this->mapping->all();
        $accounts = $this->notifications->accountsForUsers(array_column($rows, 'nc_uid'));
        $users = [];
        foreach ($rows as $row) {
            $u = $this->users->get($row['nc_uid']);
            $users[] = [
                'uid' => $row['nc_uid'], 'ding_user_id' => $row['ding_user_id'],
                'name' => $u ? $u->getDisplayName() : '(账号已删除)',
                'enabled' => $u !== null && $u->isEnabled(), 'last_login' => (int)$row['last_login'],
                'notification_preference' => $this->config->getUserValue($row['nc_uid'], 'mailhub_dingtalk', 'notifications_enabled', '1') === '1',
                'accounts' => $accounts[$row['nc_uid']] ?? [],
            ];
        }
        $result = new JSONResponse([
            'settings' => $this->settings->publicStatus(), 'users' => $users,
            'notifications' => $this->notifications->adminStatus(),
            'server' => [
                'nextcloud' => $this->config->getSystemValueString('version', ''),
                'mail' => $this->config->getAppValue('mail', 'installed_version', ''),
                'maintenance' => $this->config->getSystemValueBool('maintenance', false),
                'last_cron' => (int)$this->config->getAppValue('core', 'lastcron', '0'),
            ],
        ]);
        $result->addHeader('Cache-Control', 'no-store');
        return $result;
    }
    public function preferences(bool $loginEnabled = true, bool $notificationsEnabled = false): JSONResponse {
        if ($notificationsEnabled) {
            $this->settings->requireEnabled();
            if ($this->settings->agentId() === '') { return new JSONResponse(['message' => '请先配置 AgentId。'], 400); }
        }
        $wasEnabled = $this->settings->get('notifications_enabled', false) === true;
        $this->config->setAppValue('mailhub_dingtalk', 'login_enabled', $loginEnabled ? '1' : '0');
        $this->config->setAppValue('mailhub_dingtalk', 'notifications_enabled', $notificationsEnabled ? '1' : '0');
        if ($wasEnabled !== $notificationsEnabled) { $this->config->setAppValue('mailhub_dingtalk', 'notifications_epoch', bin2hex(random_bytes(16))); }
        $this->logger->notice('Mailhub administrator changed application preferences', [
            'app' => 'mailhub_dingtalk', 'actor' => $this->userSession->getUser()?->getUID(),
            'login_enabled' => $loginEnabled, 'notifications_enabled' => $notificationsEnabled,
        ]);
        return new JSONResponse(['ok' => true]);
    }
    public function userState(string $uid, bool $enabled, bool $notificationsEnabled): JSONResponse {
        $row = $this->mapping->findByUid($uid);
        $user = $this->users->get($uid);
        if ($row === null || $row['corp_id'] !== $this->settings->corpId() || $user === null) {
            return new JSONResponse(['message' => '员工账号不存在。'], 404);
        }
        if ($this->groups->isAdmin($uid) || $uid === $this->userSession->getUser()?->getUID()) {
            return new JSONResponse(['message' => '不能从邮箱控制台停用管理员或当前登录账号。'], 403);
        }
        $user->setEnabled($enabled);
        if (!$enabled) { $this->sessions->revoke($uid); }
        $this->notifications->setUserEnabled($uid, $notificationsEnabled);
        $this->logger->notice('Mailhub administrator changed employee access', [
            'app' => 'mailhub_dingtalk', 'actor' => $this->userSession->getUser()?->getUID(),
            'target' => $uid, 'enabled' => $enabled, 'notifications_enabled' => $notificationsEnabled,
        ]);
        return new JSONResponse(['ok' => true]);
    }
}
