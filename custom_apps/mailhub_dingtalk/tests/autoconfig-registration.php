<?php
declare(strict_types=1);
/** Read-only real Nextcloud middleware integration check; no mailbox credentials or messages are accessed. */
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
define('OC_CONSOLE', 1);
$root = getenv('NEXTCLOUD_ROOT') ?: '/var/www/html';
require $root . '/lib/base.php';

use OCA\MailhubDingtalk\Middleware\AutoConfigMiddleware;
use OCA\MailhubDingtalk\Service\ProviderResolver;
use OC\AppFramework\Middleware\MiddlewareDispatcher;
use OC\AppFramework\Middleware\Security\SecurityMiddleware;
use OC\AppFramework\Middleware\Security\RateLimitingMiddleware;

$checks = 0;
function check(bool $condition, string $label): void {
    global $checks;
    if (!$condition) { throw new RuntimeException('FAILED: ' . $label); }
    ++$checks;
}
\OC_App::loadApp('mailhub_dingtalk');
\OC_App::loadApp('mail');
$container = \OC::$server->getRegisteredAppContainer('mail');
$dispatcher = $container->get(MiddlewareDispatcher::class);
$classes = array_map(static fn ($middleware): string => get_class($middleware), $dispatcher->getMiddlewares());
$custom = array_search(AutoConfigMiddleware::class, $classes, true);
$security = array_search(SecurityMiddleware::class, $classes, true);
$rateLimit = array_search(RateLimitingMiddleware::class, $classes, true);
check($custom !== false, 'Global preset middleware present in actual Mail request pipeline');
check($security !== false && $security < $custom, 'Authentication and CSRF execute before provider middleware');
check($rateLimit !== false && $rateLimit < $custom, 'Mail rate limiting executes before provider middleware');
$controller = $container->get(\OCA\Mail\Controller\AutoConfigController::class);
check($controller instanceof \OCA\Mail\Controller\AutoConfigController, 'Mail controller dependency injection remains valid');
$resolver = \OCP\Server::get(ProviderResolver::class);
$preset = $resolver->resolve('mailhub-probe@qq.com');
check($preset !== null && $preset['configuration']['imapConfig']['host'] === 'imap.qq.com', 'Real DI resolves a direct-domain preset without network');
check(!\OCP\Server::get(\OCP\IUserSession::class)->isLoggedIn(), 'CLI integration probe has no employee session');
// Do not manually dispatch a request: the real HTTP dispatcher initializes additional
// reflection and request state. Anonymous/CSRF/rate-limit rejection is verified over HTTP.
echo "PASS $checks read-only autoconfig registration checks; no mailbox authentication attempted.\n";
