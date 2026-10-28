<?php
declare(strict_types=1);
namespace OCA\MailhubDingtalk\Service;

use OCP\Http\Client\IClientService;
use OCP\ICacheFactory;

class DingTalkClient {
    public function __construct(private Settings $settings, private IClientService $http, private ICacheFactory $cacheFactory) {}
    private function tokenCacheKey(): string {
        return 'token:' . hash('sha256', $this->settings->clientId() . "\0" . $this->settings->clientSecret());
    }
    public function accessToken(): string {
        $this->settings->requireEnabled();
        $cache = $this->cacheFactory->createDistributed('mailhub_dingtalk');
        $key = $this->tokenCacheKey();
        $cached = $cache->get($key);
        if (is_string($cached) && $cached !== '') { return $cached; }
        $data = $this->request('https://api.dingtalk.com/v1.0/oauth2/accessToken', [
            'appKey' => $this->settings->clientId(), 'appSecret' => $this->settings->clientSecret(),
        ]);
        if (!is_string($data['accessToken'] ?? null) || $data['accessToken'] === '') {
            throw new DingTalkTransportException('DingTalk did not return an application token');
        }
        $cache->set($key, $data['accessToken'], max(1, min(7000, (int)($data['expireIn'] ?? 7200) - 120)));
        return $data['accessToken'];
    }
    public function identity(string $code): array {
        if ($code === '' || strlen($code) > 2048 || preg_match('/[\x00-\x20]/', $code)) {
            throw new \InvalidArgumentException('Invalid authorization code');
        }
        // This corporate application token binds the authorization code to our enterprise.
        // The caller cannot select a corpId, appKey, token, identity or API endpoint.
        $data = $this->postLegacy('/topapi/v2/user/getuserinfo', ['code' => $code]);
        $result = $data['result'] ?? null;
        if (!is_array($result) || !is_string($result['userid'] ?? null) || $result['userid'] === '' || strlen($result['userid']) > 128) {
            throw new DingTalkTransportException('DingTalk returned an invalid identity');
        }
        return ['user_id' => $result['userid'], 'name' => mb_substr((string)($result['name'] ?? $result['userid']), 0, 128)];
    }
    public function postLegacy(string $path, array $body): array {
        if (!in_array($path, [
            '/topapi/v2/user/getuserinfo',
            '/topapi/message/corpconversation/asyncsend_v2',
            '/topapi/message/corpconversation/getsendresult',
        ], true)) { throw new \InvalidArgumentException('DingTalk endpoint is not allowed'); }
        $data = $this->request('https://oapi.dingtalk.com' . $path . '?access_token=' . rawurlencode($this->accessToken()), $body);
        if (!array_key_exists('errcode', $data) || !is_numeric($data['errcode'])) { throw new DingTalkTransportException('Invalid DingTalk response'); }
        if ((int)$data['errcode'] !== 0) {
            if (in_array((int)$data['errcode'], [40014, 42001], true)) {
                $this->cacheFactory->createDistributed('mailhub_dingtalk')->remove($this->tokenCacheKey());
            }
            // Do not silently retry a message submission. The notification queue owns retry policy.
            throw new DingTalkApiException((int)$data['errcode']);
        }
        return $data;
    }
    private function request(string $url, array $body): array {
        try {
            $response = $this->http->newClient()->post($url, [
                'json' => $body, 'timeout' => 15, 'connect_timeout' => 5,
                'allow_redirects' => false, 'http_errors' => false,
                'headers' => ['Accept' => 'application/json'],
            ]);
            if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
                throw new DingTalkTransportException('DingTalk service returned an HTTP error');
            }
            $raw = (string)$response->getBody();
            if (strlen($raw) > 1048576) { throw new DingTalkTransportException('DingTalk response is too large'); }
            $data = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
            if (!is_array($data)) { throw new DingTalkTransportException('Invalid DingTalk response'); }
            return $data;
        } catch (\Throwable) {
            // Never chain a HTTP exception: its URI can contain the application access token.
            throw new DingTalkTransportException('Unable to complete the DingTalk request');
        }
    }
}
