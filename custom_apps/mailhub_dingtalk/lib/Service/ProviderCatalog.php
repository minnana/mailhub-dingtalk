<?php
declare(strict_types=1);
namespace OCA\MailhubDingtalk\Service;

/**
 * Conservative, pure catalogue of verified mainland-China client endpoints.
 * No network, email local part, credential, DNS override or connection attempt belongs here.
 * Sources and deliberately excluded cases: docs/MAIL-PROVIDERS.md.
 */
final class ProviderCatalog {
    private const PROVIDERS = [
        'tencent_exmail' => [
            'id' => 'tencent_exmail', 'label' => '腾讯企业邮箱', 'username' => 'full_email',
            'imap' => ['host' => 'imap.exmail.qq.com', 'port' => 993, 'security' => 'ssl'],
            'smtp' => ['host' => 'smtp.exmail.qq.com', 'port' => 465, 'security' => 'ssl'],
        ],
        'qq_mail' => [
            'id' => 'qq_mail', 'label' => 'QQ / Foxmail 邮箱', 'username' => 'full_email',
            'imap' => ['host' => 'imap.qq.com', 'port' => 993, 'security' => 'ssl'],
            'smtp' => ['host' => 'smtp.qq.com', 'port' => 465, 'security' => 'ssl'],
        ],
        'netease_163' => [
            'id' => 'netease_163', 'label' => '网易 163 邮箱', 'username' => 'full_email',
            'imap' => ['host' => 'imap.163.com', 'port' => 993, 'security' => 'ssl'],
            'smtp' => ['host' => 'smtp.163.com', 'port' => 465, 'security' => 'ssl'],
        ],
        'netease_126' => [
            'id' => 'netease_126', 'label' => '网易 126 邮箱', 'username' => 'full_email',
            'imap' => ['host' => 'imap.126.com', 'port' => 993, 'security' => 'ssl'],
            'smtp' => ['host' => 'smtp.126.com', 'port' => 465, 'security' => 'ssl'],
        ],
        'netease_yeah' => [
            'id' => 'netease_yeah', 'label' => '网易 Yeah 邮箱', 'username' => 'full_email',
            'imap' => ['host' => 'imap.yeah.net', 'port' => 993, 'security' => 'ssl'],
            'smtp' => ['host' => 'smtp.yeah.net', 'port' => 465, 'security' => 'ssl'],
        ],
        'alibaba_enterprise' => [
            'id' => 'alibaba_enterprise', 'label' => '阿里企业邮箱', 'username' => 'full_email',
            'imap' => ['host' => 'imap.qiye.aliyun.com', 'port' => 993, 'security' => 'ssl'],
            'smtp' => ['host' => 'smtp.qiye.aliyun.com', 'port' => 465, 'security' => 'ssl'],
        ],
        'aliyun_personal' => [
            'id' => 'aliyun_personal', 'label' => '阿里云个人邮箱', 'username' => 'full_email',
            'imap' => ['host' => 'imap.aliyun.com', 'port' => 993, 'security' => 'ssl'],
            'smtp' => ['host' => 'smtp.aliyun.com', 'port' => 465, 'security' => 'ssl'],
        ],
    ];

    // Exact consumer domains only: their subdomains are not implicitly equivalent.
    private const EMAIL_DOMAINS = [
        'qq.com' => 'qq_mail', 'foxmail.com' => 'qq_mail',
        '163.com' => 'netease_163', '126.com' => 'netease_126', 'yeah.net' => 'netease_yeah',
        'aliyun.com' => 'aliyun_personal',
    ];

    // Exact provider-published MX hosts. Do not expand these to *.qq.com or *.aliyun.com.
    // MX is evidence for a suggested fixed endpoint, not proof of account ownership or access.
    private const MX_HOSTS = [
        'mxbiz1.qq.com' => 'tencent_exmail', 'mxbiz2.qq.com' => 'tencent_exmail',
        'mx1.qiye.aliyun.com' => 'alibaba_enterprise',
        'mx2.qiye.aliyun.com' => 'alibaba_enterprise',
        'mx3.qiye.aliyun.com' => 'alibaba_enterprise',
        'mxn.mxhichina.com' => 'alibaba_enterprise',
        'mxw.mxhichina.com' => 'alibaba_enterprise',
    ];

    /** Normalize ASCII DNS names; accept a single root dot, never whitespace, URLs or IP literals. */
    private static function normalizeHost(string $host): ?string {
        if ($host === '' || strlen($host) > 254) { return null; }
        if (str_ends_with($host, '.')) { $host = substr($host, 0, -1); }
        $host = strtolower($host);
        if (strlen($host) > 253 || !str_contains($host, '.')
            || filter_var($host, FILTER_VALIDATE_IP) !== false) { return null; }
        foreach (explode('.', $host) as $label) {
            if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?$/D', $label)) { return null; }
        }
        return $host;
    }

    public static function byEmailDomain(string $domain): ?array {
        $normalized = self::normalizeHost($domain);
        $id = $normalized !== null ? (self::EMAIL_DOMAINS[$normalized] ?? null) : null;
        return $id !== null ? self::PROVIDERS[$id] : null;
    }

    /** @param array<mixed> $hosts MX host names only, without priority prefixes. */
    public static function byMxHosts(array $hosts): ?array {
        return self::classifyMxHosts($hosts)['provider'];
    }

    /**
     * Every MX must identify the same supported provider before returning an endpoint.
     * A known provider mixed with an unknown/malformed host is also ambiguous.
     * @param array<mixed> $hosts
     * @return array{status: 'matched'|'unknown'|'ambiguous', provider: ?array}
     */
    public static function classifyMxHosts(array $hosts): array {
        if ($hosts === [] || count($hosts) > 100) { return ['status' => 'unknown', 'provider' => null]; }
        $providers = []; $unknown = false;
        foreach ($hosts as $host) {
            $normalized = is_string($host) ? self::normalizeHost($host) : null;
            $id = $normalized !== null ? (self::MX_HOSTS[$normalized] ?? null) : null;
            if ($id === null) { $unknown = true; } else { $providers[$id] = true; }
        }
        if ($providers === []) { return ['status' => 'unknown', 'provider' => null]; }
        if ($unknown || count($providers) !== 1) { return ['status' => 'ambiguous', 'provider' => null]; }
        return ['status' => 'matched', 'provider' => self::PROVIDERS[array_key_first($providers)]];
    }
}
