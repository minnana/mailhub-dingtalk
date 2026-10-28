<?php
declare(strict_types=1);
namespace OCA\MailhubDingtalk\Service;

use OCP\ICacheFactory;

/** Resolves provider settings from the email domain, never from a caller-selected host. */
final class ProviderResolver {
    public function __construct(private DnsMxLookup $dns, private ICacheFactory $caches) {}

    public static function validDomain(string $domain): bool {
        return strlen($domain) <= 253 && preg_match(
            '/^(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z](?:[a-z0-9-]{0,61}[a-z0-9])?$/D',
            $domain,
        ) === 1;
    }

    /** @return array{provider: string, configuration: array}|null */
    public function resolve(string $email): ?array {
        $email = trim($email);
        if (strlen($email) > 254 || filter_var($email, FILTER_VALIDATE_EMAIL) === false) { return null; }
        $domain = strtolower(substr($email, (int)strrpos($email, '@') + 1));
        if (!self::validDomain($domain)) { return null; }

        $provider = ProviderCatalog::byEmailDomain($domain);
        if ($provider === null) {
            // No address, local part, password or provider token is written to this cache.
            $cache = $this->caches->createDistributed('mailhub_autoconfig_v1');
            $key = hash('sha256', $domain);
            $cached = $cache->get($key);
            if (is_array($cached) && isset($cached['hosts']) && is_array($cached['hosts'])) {
                $hosts = $cached['hosts'];
            } else {
                $hosts = $this->dns->lookup($domain);
                $cache->set($key, ['hosts' => $hosts], $hosts === [] ? 60 : 300);
            }
            $provider = ProviderCatalog::byMxHosts($hosts);
        }
        if ($provider === null) { return null; }

        return ['provider' => $provider['id'], 'configuration' => [
            'imapConfig' => ['username' => $email, 'host' => $provider['imap']['host'],
                'port' => $provider['imap']['port'], 'security' => $provider['imap']['security']],
            'smtpConfig' => ['username' => $email, 'host' => $provider['smtp']['host'],
                'port' => $provider['smtp']['port'], 'security' => $provider['smtp']['security']],
        ]];
    }
}
