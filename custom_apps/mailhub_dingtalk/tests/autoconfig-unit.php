<?php
declare(strict_types=1);
// Standalone resolver/middleware behavior checks. No network, passwords, or Nextcloud database.
namespace OCP { interface ICacheFactory {} interface IRequest {} interface IConfig {} }
namespace Psr\Log { interface LoggerInterface {} }
namespace OCP\AppFramework {
    class Controller {}
    abstract class Middleware {
        public function beforeController(Controller $controller, string $methodName) {}
        public function afterException(Controller $controller, string $methodName, \Exception $exception) { throw $exception; }
    }
}
namespace OCP\AppFramework\Http {
    class Response { public array $headers = []; public function addHeader($name, $value) { $this->headers[$name] = $value; } }
    class JSONResponse extends Response { public function __construct(public array $data) {} }
}
namespace OCA\Mail\Controller { class AutoConfigController extends \OCP\AppFramework\Controller {} }
namespace {
    use OCA\MailhubDingtalk\Service\DnsMxLookup;
    use OCA\MailhubDingtalk\Service\ProviderResolver;
    use OCA\MailhubDingtalk\Middleware\AutoConfigMiddleware;
    use OCA\MailhubDingtalk\Middleware\AutoConfigPreset;
    foreach (['ProviderCatalog', 'DnsMxLookup', 'ProviderResolver'] as $name) { require __DIR__ . '/../lib/Service/' . $name . '.php'; }
    foreach (['AutoConfigPreset', 'AutoConfigMiddleware'] as $name) { require __DIR__ . '/../lib/Middleware/' . $name . '.php'; }
    $count = 0;
    function check(bool $value, string $name): void { global $count; if (!$value) { throw new RuntimeException($name); } ++$count; }
    class TestDns extends DnsMxLookup {
        public array $calls = []; public array $hosts = ['mxbiz1.qq.com', 'mxbiz2.qq.com']; public bool $fail = false;
        public function lookup(string $domain): array { $this->calls[] = $domain; if ($this->fail) throw new RuntimeException('private detail must not appear'); return $this->hosts; }
    }
    class TestCaches implements \OCP\ICacheFactory {
        public array $items = []; public array $ttls = [];
        public function createDistributed($prefix) { return $this; }
        public function get($key) { return $this->items[$key] ?? null; }
        public function set($key, $value, $ttl) { $this->items[$key] = $value; $this->ttls[$key] = $ttl; }
    }
    class TestRequest implements \OCP\IRequest {
        public array $accessed = []; public array $params = ['email' => 'Member@qq.com', 'host' => 'attacker.invalid', 'password' => 'not-read'];
        public function getParam($key, $default) { $this->accessed[] = $key; return $this->params[$key] ?? $default; }
    }
    class TestConfig implements \OCP\IConfig {
        public string $mail = '5.12.5'; public string $nextcloud = '34.0.5'; public string $enabled = '1';
        public function getAppValue($app, $key, $default) { return $app === 'mail' ? $this->mail : $this->enabled; }
        public function getSystemValueString($key, $default) { return $this->nextcloud; }
    }
    class TestLogger implements \Psr\Log\LoggerInterface {
        public array $entries = [];
        public function warning($message, $context) { $this->entries[] = [$message, $context]; }
    }
    $dns = new TestDns(); $cache = new TestCaches(); $resolver = new ProviderResolver($dns, $cache);
    $qq = $resolver->resolve('Member@qq.com');
    check($qq['configuration']['imapConfig']['host'] === 'imap.qq.com' && $dns->calls === [], 'Known direct domain avoids DNS');
    check($qq['configuration']['imapConfig']['username'] === 'Member@qq.com', 'Full original email is used as username');
    $company = $resolver->resolve('one@example.com');
    check($company['configuration']['imapConfig']['host'] === 'imap.exmail.qq.com'
        && $company['configuration']['smtpConfig']['host'] === 'smtp.exmail.qq.com', 'Custom domain MX selects fixed Tencent enterprise hosts');
    check($company['configuration']['imapConfig']['port'] === 993 && $company['configuration']['smtpConfig']['port'] === 465
        && $company['configuration']['imapConfig']['security'] === 'ssl', 'Tencent preset uses encrypted standard ports');
    $second = $resolver->resolve('two@example.com');
    check(count($dns->calls) === 1 && $second['configuration']['imapConfig']['username'] === 'two@example.com', 'DNS cached per domain, never per employee identity');
    check(!str_contains(json_encode($cache->items), 'one@') && !str_contains(json_encode($cache->items), 'two@'), 'Cache has no employee email');
    check(array_values($cache->ttls) === [300], 'Positive DNS cache bounded to five minutes');
    $dns->hosts = [];
    check($resolver->resolve('one@empty.example') === null && $resolver->resolve('two@empty.example') === null, 'Empty MX retains native fallback');
    check(count($dns->calls) === 2 && in_array(60, $cache->ttls, true), 'Empty result cached for one minute');
    $dns->hosts = ['mx.unrecognized.example'];
    check($resolver->resolve('one@other.example') === null, 'Unknown MX has no invented provider');
    $before = count($dns->calls);
    check($resolver->resolve('invalid address') === null && $resolver->resolve('test@[127.0.0.1]') === null
        && count($dns->calls) === $before, 'Malformed addresses and address literals do not trigger DNS');
    check(!ProviderResolver::validDomain('localhost') && !ProviderResolver::validDomain('-bad.example')
        && !ProviderResolver::validDomain('foo.example/path'), 'Invalid DNS names rejected');
    $mixed = DnsMxLookup::extractHosts([['type' => 'MX', 'target' => 'MXBIZ1.QQ.COM.'], ['type' => 'MX', 'target' => '.']]);
    check(\OCA\MailhubDingtalk\Service\ProviderCatalog::byMxHosts($mixed) === null, 'Malformed or null MX remains visible to conflict detection');
    check(DnsMxLookup::extractHosts(array_fill(0, 101, ['type' => 'MX', 'target' => 'mxbiz1.qq.com'])) === [], 'Oversized DNS response is not silently truncated');
    check(DnsMxLookup::extractHosts([['type' => 'A', 'ip' => '127.0.0.1'], ['type' => 'MX', 'target' => 'mxbiz1.qq.com']]) === ['mxbiz1.qq.com'], 'Only MX records are used for provider classification');

    $request = new TestRequest(); $config = new TestConfig(); $logger = new TestLogger();
    $middleware = new AutoConfigMiddleware($resolver, $request, $config, $logger);
    $controller = new \OCA\Mail\Controller\AutoConfigController();
    try { $middleware->beforeController($controller, 'queryIspdb'); throw new RuntimeException('Known provider did not intercept'); }
    catch (AutoConfigPreset $preset) {
        $response = $middleware->afterException($controller, 'queryIspdb', $preset);
        check($response->data['status'] === 'success' && $response->data['data']['imapConfig']['host'] === 'imap.qq.com', 'Middleware returns Mail-compatible JSend');
        check($response->headers['x-mail-response'] === 'true' && $response->headers['Cache-Control'] === 'no-store', 'Preset response uses Mail marker and no-store');
    }
    check($request->accessed === ['email'], 'Caller host and password are never read during provider discovery');
    $request->accessed = [];
    $middleware->beforeController(new \OCP\AppFramework\Controller(), 'queryIspdb');
    $middleware->beforeController($controller, 'queryMx');
    check($request->accessed === [], 'All other controllers and methods untouched');
    $config->mail = '5.12.6'; $middleware->beforeController($controller, 'queryIspdb');
    check($request->accessed === [], 'Unreviewed Mail version falls back to native behavior');
    $config->mail = '5.12.5'; $config->enabled = '0'; $middleware->beforeController($controller, 'queryIspdb');
    check($request->accessed === [], 'Administrator feature switch restores native behavior');
    $config->enabled = '1'; $config->nextcloud = '35.0.0'; $middleware->beforeController($controller, 'queryIspdb');
    check($request->accessed === [], 'Unverified Nextcloud 35 falls back');
    $config->nextcloud = '34.0.5'; $request->params['email'] = 'person@other.example';
    $middleware->beforeController($controller, 'queryIspdb');
    check($logger->entries === [], 'Unknown provider fallback is not treated as an error');
    $request->params['email'] = 'person@failure.example'; $dns->fail = true;
    $middleware->beforeController($controller, 'queryIspdb');
    check(count($logger->entries) === 1 && !str_contains(json_encode($logger->entries), 'private detail'), 'Unexpected failure logs no exception message or address');
    $unrelated = new RuntimeException('unrelated');
    try { $middleware->afterException($controller, 'queryIspdb', $unrelated); throw new RuntimeException('Other failure swallowed'); }
    catch (RuntimeException $e) { check($e === $unrelated, 'Unrelated exceptions never converted into preset success'); }
    echo "PASS $count autoconfig resolver and middleware behavior checks\n";
}
