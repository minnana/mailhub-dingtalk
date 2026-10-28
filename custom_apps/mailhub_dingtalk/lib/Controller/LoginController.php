<?php
declare(strict_types=1);
namespace OCA\MailhubDingtalk\Controller;

use OCA\MailhubDingtalk\Service\DingTalkClient;
use OCA\MailhubDingtalk\Service\LoginFlow;
use OCA\MailhubDingtalk\Service\LoginNonce;
use OCA\MailhubDingtalk\Service\NcSessionAdapter;
use OCA\MailhubDingtalk\Service\Settings;
use OCA\MailhubDingtalk\Service\UserProvisioner;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\AnonRateLimit;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\PublicPage;
use OCP\AppFramework\Http\Attribute\UseSession;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use OCP\IUserSession;
use Psr\Log\LoggerInterface;

class LoginController extends Controller {
    public function __construct(IRequest $request, private Settings $settings, private LoginNonce $nonce,
        private DingTalkClient $dingTalk, private UserProvisioner $provisioner, private NcSessionAdapter $sessionAdapter,
        private IURLGenerator $urls, private IUserSession $users, private LoggerInterface $logger,
        private LoginFlow $loginFlow) {
        parent::__construct('mailhub_dingtalk', $request);
    }
    #[PublicPage] #[NoAdminRequired] #[NoCSRFRequired] #[UseSession]
    #[AnonRateLimit(limit: 300, period: 60)] #[UserRateLimit(limit: 30, period: 60)]
    public function index(): TemplateResponse {
        $ready = false;
        try { $this->settings->requireEnabled(); $ready = $this->settings->get('login_enabled', true) === true; } catch (\Throwable) {}
        $secure = $this->request->getServerProtocol() === 'https';
        $response = new TemplateResponse('mailhub_dingtalk', 'login', [
            'ready' => $ready && $secure,
            'message' => !$secure ? '请使用 HTTPS 地址打开邮箱。' : (!$ready ? '钉钉登录尚未配置完成或已暂停，请联系管理员。' : '正在确认您的钉钉身份…'),
            'clientId' => $ready ? $this->settings->clientId() : '',
            'corpId' => $ready ? $this->settings->corpId() : '',
            'nonce' => $secure ? $this->nonce->issue('begin') : '',
            'beginUrl' => $this->urls->linkToRoute('mailhub_dingtalk.login.begin'),
            'authUrl' => $this->urls->linkToRoute('mailhub_dingtalk.login.authenticate'),
            'localLogin' => $this->urls->linkToRoute('core.login.showLoginForm'),
        ], 'guest');
        $csp = new ContentSecurityPolicy();
        $csp->addAllowedScriptDomain('https://g.alicdn.com');
        $response->setContentSecurityPolicy($csp);
        $response->addHeader('Cache-Control', 'no-store');
        $response->addHeader('Referrer-Policy', 'no-referrer');
        return $response;
    }
    // Ordinary browsers do not invoke this automatically; DingTalk does before any SDK work.
    // No NoCSRFRequired: a third-party page must not be able to log out an administrator.
    #[PublicPage] #[NoAdminRequired] #[UseSession]
    #[AnonRateLimit(limit: 300, period: 60)] #[UserRateLimit(limit: 30, period: 60)]
    #[BruteForceProtection(action: 'mailhubDingTalkBegin')]
    public function begin(string $nonce = ''): JSONResponse {
        try {
            $response = new JSONResponse($this->loginFlow->begin($this->request->getServerProtocol() === 'https', $nonce));
        } catch (\Throwable $e) {
            $this->logger->warning('Mailhub login preparation was rejected', [
                'app' => 'mailhub_dingtalk', 'exception_class' => get_class($e),
            ]);
            $response = new JSONResponse(['message' => '无法准备安全登录，请关闭页面后从钉钉工作台重新打开。'], 403);
            $response->throttle(['reason' => 'begin_rejected']);
        }
        $response->addHeader('Cache-Control', 'no-store');
        return $response;
    }
    // No NoCSRFRequired attribute here: Nextcloud validates the requesttoken header.
    #[PublicPage] #[NoAdminRequired] #[UseSession]
    #[AnonRateLimit(limit: 300, period: 60)] #[UserRateLimit(limit: 30, period: 60)]
    #[BruteForceProtection(action: 'mailhubDingTalkLogin')]
    public function authenticate(string $code = '', string $nonce = ''): JSONResponse {
        $loginStarted = false;
        try {
            if ($this->request->getServerProtocol() !== 'https' || !$this->nonce->consume($nonce)) {
                throw new \RuntimeException('Invalid login flow');
            }
            // Once a CSRF-validated new login starts, an old WebView identity must
            // not survive a rejected employee switch or an upstream failure.
            if ($this->users->getUser() !== null) { $this->users->logout(); }
            $this->settings->requireEnabled();
            if ($this->settings->get('login_enabled', true) !== true) { throw new \RuntimeException('Login paused'); }
            $identity = $this->dingTalk->identity($code);
            $user = $this->provisioner->provision($identity['user_id'], $identity['name']);
            // Always verify DingTalk even if a different employee used this WebView earlier.
            $loginStarted = true;
            $this->sessionAdapter->login($user);
            $this->provisioner->recordLogin($user->getUID());
            $response = new JSONResponse(['redirect' => $this->urls->getAbsoluteURL('/index.php/apps/mail/')]);
        } catch (\Throwable $e) {
            // A failure after partial login or during bookkeeping must not leave a new session usable.
            if ($loginStarted) {
                try { $this->users->logout(); }
                catch (\Throwable $cleanupError) {
                    $this->logger->warning('Mailhub failed login session cleanup failed', [
                        'app' => 'mailhub_dingtalk', 'exception_class' => get_class($cleanupError),
                    ]);
                }
            }
            // Exception messages and previous exceptions may contain credentials or provider data.
            $this->logger->warning('Mailhub DingTalk login was rejected', [
                'app' => 'mailhub_dingtalk', 'exception_class' => get_class($e),
                'api_code' => $e instanceof \OCA\MailhubDingtalk\Service\DingTalkApiException ? $e->getApiCode() : null,
            ]);
            $response = new JSONResponse(['message' => '登录未完成。请重新打开应用；若仍失败，请管理员检查应用配置、人员范围或账号状态。'], 403);
            $response->throttle(['reason' => 'login_rejected']);
        }
        $response->addHeader('Cache-Control', 'no-store');
        return $response;
    }
}
