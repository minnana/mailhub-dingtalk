<?php
declare(strict_types=1);
namespace OCA\MailhubDingtalk\AppInfo;

use OCA\MailhubDingtalk\AlternativeLogin\Provider;
use OCA\MailhubDingtalk\Middleware\AutoConfigMiddleware;
use OCA\MailhubDingtalk\Service\FastSyncConfigurator;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IRegistrationContext;

class Application extends App implements IBootstrap {
    public const APP_ID = 'mailhub_dingtalk';
    public function __construct(array $urlParams = []) { parent::__construct(self::APP_ID, $urlParams); }
    public function register(IRegistrationContext $context): void {
        $context->registerAlternativeLoginProvider(Provider::class);
        $context->registerMiddleware(AutoConfigMiddleware::class, true);
    }
    public function boot(IBootContext $context): void {
        if (PHP_SAPI !== 'cli' || !\OC::$CLI) { return; }
        $context->injectFn(static function (FastSyncConfigurator $configurator): void {
            $configurator->configure(true);
        });
    }
}
