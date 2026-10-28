<?php
declare(strict_types=1);
namespace OCA\MailhubDingtalk\Middleware;

use OCA\MailhubDingtalk\Service\ProviderResolver;
use OCP\AppFramework\Controller;
use OCP\AppFramework\Http\JSONResponse;
use OCP\AppFramework\Http\Response;
use OCP\AppFramework\Middleware;
use OCP\IConfig;
use OCP\IRequest;
use Psr\Log\LoggerInterface;

/** Global registration runs after Nextcloud's authentication, CSRF and rate-limit middleware. */
final class AutoConfigMiddleware extends Middleware {
    public function __construct(private ProviderResolver $resolver, private IRequest $request,
        private IConfig $config, private LoggerInterface $logger) {}

    public function beforeController(Controller $controller, string $methodName): void {
        if (get_class($controller) !== 'OCA\\Mail\\Controller\\AutoConfigController' || $methodName !== 'queryIspdb') { return; }
        if ($this->config->getAppValue('mail', 'installed_version', '') !== '5.12.5'
            || $this->config->getAppValue('mailhub_dingtalk', 'domestic_autoconfig_enabled', '1') !== '1') { return; }
        $major = (int)explode('.', $this->config->getSystemValueString('version', '0'))[0];
        if ($major !== 34) { return; }

        // The existing endpoint only receives host/email. Never inspect the password form or account payload.
        $email = $this->request->getParam('email', '');
        if (!is_string($email)) { return; }
        try { $result = $this->resolver->resolve($email); }
        catch (\Throwable $e) {
            $this->logger->warning('Mailhub provider discovery unavailable; using native Mail discovery', [
                'app' => 'mailhub_dingtalk', 'exception_class' => get_class($e),
            ]);
            return;
        }
        if ($result !== null) { throw new AutoConfigPreset($result['configuration'], $result['provider']); }
    }

    public function afterException(Controller $controller, string $methodName, \Exception $exception): Response {
        if (!$exception instanceof AutoConfigPreset
            || get_class($controller) !== 'OCA\\Mail\\Controller\\AutoConfigController'
            || $methodName !== 'queryIspdb') { throw $exception; }
        $response = new JSONResponse(['status' => 'success', 'data' => $exception->configuration]);
        $response->addHeader('x-mail-response', 'true');
        $response->addHeader('X-Mailhub-Autoconfig', $exception->provider);
        $response->addHeader('Cache-Control', 'no-store');
        return $response;
    }
}
