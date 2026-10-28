<?php
declare(strict_types=1);
namespace OCA\MailhubDingtalk\AlternativeLogin;
use OCA\MailhubDingtalk\Service\Settings;
use OCP\Authentication\IAlternativeLogin;
use OCP\Authentication\IAlternativeLoginProvider;
use OCP\IURLGenerator;

class Provider implements IAlternativeLoginProvider {
    public function __construct(private Settings $settings, private IURLGenerator $urls) {}
    public function getAlternativeLogins(): array {
        try { $this->settings->requireEnabled(); } catch (\Throwable) { return []; }
        if ($this->settings->get('login_enabled', true) !== true) { return []; }
        return [new class($this->urls->linkToRoute('mailhub_dingtalk.login.index')) implements IAlternativeLogin {
            public function __construct(private string $url) {}
            public function getLabel(): string { return '在钉钉中登录公司邮箱'; }
            public function getLink(): string { return $this->url; }
            public function getClass(): string { return 'mailhub-dingtalk-login'; }
            public function load(): void {}
        }];
    }
}
