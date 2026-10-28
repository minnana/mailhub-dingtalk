<?php
declare(strict_types=1);
namespace OCA\MailhubDingtalk\Settings;
use OCP\IURLGenerator;
use OCP\Settings\IIconSection;

class PersonalSection implements IIconSection {
    public function __construct(private IURLGenerator $urls) {}
    public function getID(): string { return 'mailhub-web-login'; }
    public function getName(): string { return '网页登录'; }
    public function getPriority(): int { return 35; }
    public function getIcon(): string { return $this->urls->imagePath('core', 'actions/password.svg'); }
}
