<?php
declare(strict_types=1);
/** Standalone scheduling-boundary checks; no Nextcloud bootstrap, DNS or credentials. */
namespace OCP {
    interface IConfig {}
    final class Server {
        public static mixed $job = null;
        public static int $calls = 0;
        public static bool $fail = false;
        public static function get(string $class): mixed {
            ++self::$calls;
            if (self::$fail) { throw new \RuntimeException('secret upstream detail'); }
            return self::$job;
        }
    }
}
namespace Psr\Log { interface LoggerInterface {} }
namespace OCA\Mail\BackgroundJob {
    final class SyncJob {
        public int $interval = 300;
        public int $sets = 0;
        public bool $forcedSyncInterval = true;
        public bool $forcedSkip = false;
        public string $lockState = 'unchanged';
        public function setInterval(int $interval): void { $this->interval = $interval; ++$this->sets; }
    }
}
namespace OCP\AppFramework { class App { public function __construct(...$args) {} } }
namespace OCP\AppFramework\Bootstrap {
    interface IBootstrap {}
    interface IBootContext { public function injectFn(callable $fn); }
    interface IRegistrationContext {}
}
namespace {
    if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
    use OCA\MailhubDingtalk\Service\FastSyncConfigurator;
    use OCA\MailhubDingtalk\AppInfo\Application;
    use OCA\Mail\BackgroundJob\SyncJob;
    use OCP\Server;
    require __DIR__ . '/../lib/Service/FastSyncConfigurator.php';
    require __DIR__ . '/../lib/AppInfo/Application.php';
    class OC { public static bool $CLI = true; }
    final class TestConfig implements \OCP\IConfig {
        public array $system = ['app.mailhub.fast-sync-enabled' => true, 'app.mail.background-sync-interval' => 60, 'version' => '34.0.5'];
        public string $mail = '5.12.5';
        public function getSystemValue($key, $default) { return $this->system[$key] ?? $default; }
        public function getSystemValueString($key, $default): string { return (string)($this->system[$key] ?? $default); }
        public function getAppValue($app, $key, $default): string { return $this->mail; }
    }
    final class TestLogger implements \Psr\Log\LoggerInterface {
        public array $entries = [];
        public function warning($message, $context): void { $this->entries[] = [$message, $context]; }
    }
    final class TestBootContext implements \OCP\AppFramework\Bootstrap\IBootContext {
        public int $calls = 0;
        public function __construct(private FastSyncConfigurator $configurator) {}
        public function injectFn(callable $fn) { ++$this->calls; $fn($this->configurator); }
    }
    $checks = 0;
    function check(bool $condition, string $label): void {
        global $checks; ++$checks;
        if (!$condition) { throw new \RuntimeException('FAILED: ' . $label); }
    }
    function fixture(): array {
        Server::$job = new SyncJob(); Server::$calls = 0; Server::$fail = false;
        $config = new TestConfig(); $logger = new TestLogger();
        return [$config, $logger, new FastSyncConfigurator($config, $logger)];
    }
    [$config, $logger, $service] = fixture();
    $same = Server::$job;
    check($service->configure(true) && $same->interval === 1, 'Explicit supported CLI configuration applies one-second eligibility for minute cron rounds');
    check(Server::$job === $same && Server::$calls === 1, 'Cached service instance is adjusted, not replaced');
    check($same->forcedSyncInterval && !$same->forcedSkip && $same->lockState === 'unchanged', 'Only interval changes; forced flags and locks remain intact');
    check($service->configure(true) && $same->interval === 1 && $logger->entries === [], 'Repeated configuration is harmless and quiet');
    [$config, $logger, $service] = fixture();
    check(!$service->configure(false) && Server::$calls === 0 && Server::$job->interval === 300, 'Non-CLI context does not resolve or mutate Mail');
    foreach ([false, null, 0, 1, 'true', '1'] as $value) {
        [$config, $logger, $service] = fixture(); $config->system['app.mailhub.fast-sync-enabled'] = $value;
        check(!$service->configure(true) && Server::$calls === 0, 'Feature switch requires boolean true');
    }
    [$config, $logger, $service] = fixture(); unset($config->system['app.mailhub.fast-sync-enabled']);
    check(!$service->configure(true) && Server::$calls === 0, 'Feature defaults to disabled');
    foreach ([-1, 0, 55, 59, 61, 300, '60', 60.0, true, null] as $value) {
        [$config, $logger, $service] = fixture(); $config->system['app.mail.background-sync-interval'] = $value;
        check(!$service->configure(true) && Server::$calls === 0, 'Interval must be integer 60; native skip/other intervals are preserved');
    }
    foreach (['', '5.12.4', '5.12.6', '6.0.0', '5.12.5-dev'] as $value) {
        [$config, $logger, $service] = fixture(); $config->mail = $value;
        check(!$service->configure(true) && Server::$calls === 0, 'Unreviewed Mail version is untouched');
    }
    foreach (['', '33.0.5', '35.0.0', '34oops', '134.0.5', '34.0.5-dev', "34.0.5\n"] as $value) {
        [$config, $logger, $service] = fixture(); $config->system['version'] = $value;
        check(!$service->configure(true) && Server::$calls === 0, 'Unsupported or malformed Nextcloud version is untouched');
    }
    [$config, $logger, $service] = fixture(); $config->system['version'] = '34.0.5.1';
    check($service->configure(true) && Server::$job->interval === 1, 'Nextcloud four-component installed version is supported');
    [$config, $logger, $service] = fixture(); Server::$fail = true;
    check(!$service->configure(true) && Server::$job->interval === 300 && count($logger->entries) === 1
        && !str_contains(json_encode($logger->entries), 'secret upstream detail'), 'Resolution error preserves native scheduling and logs no upstream details');
    [$config, $logger, $service] = fixture(); Server::$job = new \stdClass();
    check(!$service->configure(true) && count($logger->entries) === 1, 'Unexpected container service is not modified');
    [$config, $logger, $service] = fixture(); $context = new TestBootContext($service); $app = new Application();
    OC::$CLI = false; $app->boot($context);
    check($context->calls === 0 && Server::$calls === 0, 'Application boot never injects fast sync in web context');
    OC::$CLI = true; $app->boot($context);
    check($context->calls === 1 && Server::$job->interval === 1, 'Application CLI boot applies configurator through injected service');
    echo "PASS $checks fast-sync boundary checks; no network or mailbox access.\n";
}
