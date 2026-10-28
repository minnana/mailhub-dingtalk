<?php
declare(strict_types=1);
namespace OCA\MailhubDingtalk\Service;

/** Uses the container's configured DNS only. Does not contact mail servers or accept credentials. */
class DnsMxLookup {
    public function lookup(string $domain): array {
        $records = @dns_get_record($domain, DNS_MX);
        if (!is_array($records)) { return []; }
        return self::extractHosts($records);
    }

    /** Preserve malformed MX targets so a known+unknown set cannot become a false positive. */
    public static function extractHosts(array $records): array {
        $hosts = [];
        foreach ($records as $record) {
            if (!is_array($record) || ($record['type'] ?? '') !== 'MX') { continue; }
            $hosts[] = is_string($record['target'] ?? null) ? strtolower($record['target']) : '';
            // Never classify a truncated prefix while ignoring a later conflicting MX.
            if (count($hosts) > 100) { return []; }
        }
        return array_values(array_unique($hosts));
    }
}
