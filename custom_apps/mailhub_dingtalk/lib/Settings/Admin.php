<?php
declare(strict_types=1);
namespace OCA\MailhubDingtalk\Settings;
use OCP\AppFramework\Http\TemplateResponse;
use OCP\IURLGenerator;
use OCP\Settings\ISettings;

class Admin implements ISettings {
    public function __construct(private IURLGenerator $urls) {}
    public function getForm(): TemplateResponse {
        return new TemplateResponse('mailhub_dingtalk', 'settings', ['url' => $this->urls->linkToRoute('mailhub_dingtalk.admin.index')]);
    }
    public function getSection(): string { return 'additional'; }
    public function getPriority(): int { return 50; }
}
