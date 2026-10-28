<?php
declare(strict_types=1);
namespace OCA\MailhubDingtalk\Settings;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IURLGenerator;
use OCP\Settings\ISettings;

class Personal implements ISettings {
    public function __construct(private IURLGenerator $urls) {}
    public function getForm(): TemplateResponse {
        return new TemplateResponse('mailhub_dingtalk', 'personal-link', ['url' => $this->urls->linkToRoute('mailhub_dingtalk.personal.index')]);
    }
    public function getSection(): string { return 'mailhub-web-login'; }
    public function getPriority(): int { return 10; }
}
