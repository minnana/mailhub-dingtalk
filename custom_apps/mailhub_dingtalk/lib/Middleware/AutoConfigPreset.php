<?php
declare(strict_types=1);
namespace OCA\MailhubDingtalk\Middleware;

/** Internal short-circuit response, handled only by the middleware that created it. */
final class AutoConfigPreset extends \Exception {
    public function __construct(public readonly array $configuration, public readonly string $provider) {
        parent::__construct('A known mail provider configuration is available');
    }
}
