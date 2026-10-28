<?php
declare(strict_types=1);
// Pure, network-free behavioural checks. No Nextcloud bootstrap and no credentials.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/../lib/Service/ProviderCatalog.php';
use OCA\MailhubDingtalk\Service\ProviderCatalog as P;

$checks = 0;
function check(bool $condition, string $name): void {
    global $checks;
    ++$checks;
    if (!$condition) { throw new RuntimeException('FAILED: ' . $name); }
}
foreach (['qq.com' => 'qq_mail', 'foxmail.com' => 'qq_mail', '163.com' => 'netease_163',
    '126.com' => 'netease_126', 'yeah.net' => 'netease_yeah', 'aliyun.com' => 'aliyun_personal'] as $domain => $id) {
    $provider = P::byEmailDomain($domain);
    check($provider !== null && $provider['id'] === $id, 'known exact domain: ' . $domain);
    check(P::byEmailDomain(strtoupper($domain) . '.') === $provider, 'DNS case/root-dot normalization: ' . $domain);
}
foreach (['gmail.com', 'googlemail.com', 'outlook.com', 'hotmail.com', 'unknown.example',
    'sub.qq.com', 'qq.com.attacker.example', 'evilqq.com', 'vip.163.com', '163.net', 'qiye.aliyun.com',
    'mxbiz1.qq.com', 'qq.com..', ' qq.com', "qq.com\n", 'user@qq.com', 'https://qq.com', 'qq.com:993',
    '127.0.0.1', '[::1]', '', '.', 'qq。com', '-qq.com', 'qq_.com'] as $domain) {
    check(P::byEmailDomain($domain) === null, 'unknown/OAuth/malformed domain rejected: ' . json_encode($domain));
}
$tencent = P::byMxHosts(['mxbiz1.qq.com', 'mxbiz2.qq.com']);
check($tencent !== null && $tencent['id'] === 'tencent_exmail', 'Tencent enterprise official MX pair');
check($tencent['imap'] === ['host' => 'imap.exmail.qq.com', 'port' => 993, 'security' => 'ssl']
    && $tencent['smtp'] === ['host' => 'smtp.exmail.qq.com', 'port' => 465, 'security' => 'ssl'], 'Tencent fixed TLS endpoints');
check(P::byMxHosts(['MXBIZ2.QQ.COM.', 'mxbiz1.qq.com', 'mxbiz1.qq.com']) === $tencent, 'MX order, duplicates, case and final dot do not change result');
$alibaba = P::byMxHosts(['mx1.qiye.aliyun.com', 'mx2.qiye.aliyun.com', 'mx3.qiye.aliyun.com']);
check($alibaba !== null && $alibaba['id'] === 'alibaba_enterprise', 'Alibaba current official MX set');
check(P::byMxHosts(['mxn.mxhichina.com', 'mxw.mxhichina.com']) === $alibaba, 'Alibaba legacy official MX set');
check(P::byMxHosts(['mxn.mxhichina.com', 'mx2.qiye.aliyun.com']) === $alibaba, 'old and current MX names are one provider');
check($alibaba['imap']['host'] === 'imap.qiye.aliyun.com' && $alibaba['smtp']['host'] === 'smtp.qiye.aliyun.com', 'legacy Alibaba MX still returns current recommended endpoints');
foreach ([[], ['.'], ['aspmx.l.google.com'], ['smtp.google.com'], ['tenant.mail.protection.outlook.com'],
    ['mxbiz1.qq.com.attacker.example'], ['evil-mxbiz1.qq.com'], ['child.mxbiz1.qq.com'],
    ['mx9.qiye.aliyun.com'], ['random.mxhichina.com'], ['10 mxbiz1.qq.com'],
    ['mxbiz1.qq.com..'], [null], [42], [['host' => 'mxbiz1.qq.com']], array_fill(0, 101, 'mxbiz1.qq.com')] as $hosts) {
    $result = P::classifyMxHosts($hosts);
    check($result['status'] === 'unknown' && $result['provider'] === null && P::byMxHosts($hosts) === null, 'unsupported/malformed MX fails closed');
}
foreach ([['mxbiz1.qq.com', 'mxn.mxhichina.com'], ['mxbiz1.qq.com', 'relay.unknown.example'],
    ['mx1.qiye.aliyun.com', 'aspmx.l.google.com'], ['mxbiz1.qq.com', null],
    ['mxbiz1.qq.com', 'mxbiz2.qq.com.attacker.example'], ['mxbiz1.qq.com', '.']] as $hosts) {
    $result = P::classifyMxHosts($hosts);
    check($result['status'] === 'ambiguous' && $result['provider'] === null && P::byMxHosts($hosts) === null, 'mixed/conflicting MX cannot select an endpoint');
}
check(P::classifyMxHosts(['mxbiz1.qq.com'])['status'] === 'matched', 'single official MX is supported');
$all = [$tencent, $alibaba];
foreach (['qq.com', '163.com', '126.com', 'yeah.net', 'aliyun.com'] as $domain) { $all[] = P::byEmailDomain($domain); }
foreach ($all as $provider) {
    check(array_keys($provider) === ['id', 'label', 'username', 'imap', 'smtp']
        && $provider['username'] === 'full_email' && $provider['imap']['port'] === 993
        && $provider['smtp']['port'] === 465 && $provider['imap']['security'] === 'ssl'
        && $provider['smtp']['security'] === 'ssl', 'catalogue contains fixed TLS settings and username mode only');
}
$changed = $tencent; $changed['imap']['host'] = 'attacker.example';
check(P::byMxHosts(['mxbiz1.qq.com']) === $tencent, 'caller mutation cannot change the catalogue');
echo "Provider catalogue: $checks checks passed. No DNS, credentials or network calls were made.\n";
