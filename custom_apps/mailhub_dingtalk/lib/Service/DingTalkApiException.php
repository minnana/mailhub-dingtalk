<?php
declare(strict_types=1);
namespace OCA\MailhubDingtalk\Service;
class DingTalkApiException extends \RuntimeException {
    public function __construct(private int $apiCode) { parent::__construct('DingTalk rejected the request', $apiCode); }
    public function getApiCode(): int { return $this->apiCode; }
}
