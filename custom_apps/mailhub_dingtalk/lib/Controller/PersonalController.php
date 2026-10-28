<?php
declare(strict_types=1);
namespace OCA\MailhubDingtalk\Controller;

use OCA\MailhubDingtalk\Service\Settings;
use OCA\MailhubDingtalk\Service\WebPasswordNonce;
use OCA\MailhubDingtalk\Service\WebPasswordService;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\Attribute\BruteForceProtection;
use OCP\AppFramework\Http\Attribute\NoAdminRequired;
use OCP\AppFramework\Http\Attribute\NoCSRFRequired;
use OCP\AppFramework\Http\Attribute\UseSession;
use OCP\AppFramework\Http\Attribute\UserRateLimit;
use OCP\AppFramework\Http\ContentSecurityPolicy;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IRequest;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

class PersonalController extends Controller {
    public function __construct(IRequest $request, private WebPasswordService $passwords, private WebPasswordNonce $nonce,
        private Settings $settings, private IURLGenerator $urls, private LoggerInterface $logger) {
        parent::__construct('mailhub_dingtalk', $request);
    }
    #[NoAdminRequired] #[NoCSRFRequired] #[UseSession]
    public function index(): TemplateResponse {
        $account = null;
        try { if ($this->request->getServerProtocol() === 'https') { $account = $this->passwords->account(); } } catch (\Throwable) {}
        $response = new TemplateResponse('mailhub_dingtalk', 'personal', [
            'account' => $account, 'nonce' => $account === null ? '' : $this->nonce->issue($account['uid']),
            'clientId' => $account === null ? '' : $this->settings->clientId(),
            'corpId' => $account === null ? '' : $this->settings->corpId(),
            'saveUrl' => $this->urls->linkToRoute('mailhub_dingtalk.personal.password'),
            'webLogin' => $this->urls->linkToRouteAbsolute('core.login.showLoginForm'),
            'personalWebLogin' => $account === null ? '' : $this->urls->linkToRouteAbsolute('core.login.showLoginForm', ['user' => $account['uid']]),
            'dingLogin' => $this->urls->linkToRoute('mailhub_dingtalk.login.index'),
            'mailUrl' => $this->urls->getAbsoluteURL('/index.php/apps/mail/'),
        ]);
        $csp = new ContentSecurityPolicy(); $csp->addAllowedScriptDomain('https://g.alicdn.com');
        $response->setContentSecurityPolicy($csp); $response->addHeader('Cache-Control', 'no-store');
        $response->addHeader('Referrer-Policy', 'no-referrer');
        return $response;
    }
    // Authentication and native CSRF are required. No public/CSRF-bypass annotations.
    #[NoAdminRequired] #[UseSession] #[UserRateLimit(limit: 5, period: 300)]
    #[BruteForceProtection(action: 'mailhubWebPassword')]
    public function password(string $nonce = '', #[\SensitiveParameter] string $code = '',
        #[\SensitiveParameter] string $password = '', #[\SensitiveParameter] string $confirmation = ''): JSONResponse {
        try {
            $this->passwords->change($this->request->getServerProtocol() === 'https', $nonce, $code, $password, $confirmation);
            $response = new JSONResponse(['message' => '网页登录密码已保存，旧会话已退出。可重新从钉钉进入，或用下方网页登录入口登录。']);
        } catch (\InvalidArgumentException $e) {
            $response = new JSONResponse(['message' => '请确认两次密码一致，且为 12–128 个字符，不含换行或控制字符。刷新页面后重试。'], 400);
        } catch (\Throwable $e) {
            $this->logger->warning('Mailhub personal password update did not complete', ['app' => 'mailhub_dingtalk', 'exception_class' => get_class($e)]);
            $response = new JSONResponse(['message' => '未能完成保存。请重新从钉钉打开后重试；如仍失败，请联系管理员检查账号状态或密码规则。'], 403);
            $response->throttle(['reason' => 'password_update_rejected']);
        }
        $response->addHeader('Cache-Control', 'no-store');
        return $response;
    }
}
