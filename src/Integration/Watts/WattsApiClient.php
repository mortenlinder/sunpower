<?php
declare(strict_types=1);

namespace Solportalen\Integration\Watts;

use RuntimeException;
use Solportalen\Config\Env;

final class WattsApiClient
{
    private const DEFAULT_TOKEN_URL = 'https://wattsenergyassistant.b2clogin.com/wattsenergyassistant.onmicrosoft.com/B2C_1A_JITMigraion_signup_signin/oauth2/v2.0/token';
    private const DEFAULT_SCOPE = 'https://wattsenergyassistant.onmicrosoft.com/6975a1eb-15f5-41a0-b233-2568ccc7a2a5/.default';

    private ?string $accessToken = null;

    public function locations(): array
    {
        return $this->request('/locations?v=1.0');
    }

    public function liveData(string $deviceId): array
    {
        return $this->request('/devices/' . rawurlencode($deviceId) . '/electricity-livedata?v=1.0');
    }

    public function probe(): array
    {
        $locations = $this->locations();
        $locationRows = array_is_list($locations) ? $locations : ($locations['items'] ?? $locations['locations'] ?? []);
        $deviceCount = 0;
        $liveCardCount = 0;
        $productionCount = 0;
        $deviceTypes = [];
        foreach (is_array($locationRows) ? $locationRows : [] as $location) {
            if (!is_array($location)) continue;
            foreach (($location['devices'] ?? []) as $device) {
                if (!is_array($device)) continue;
                $deviceCount++;
                if (($device['isLiveCardInstalled'] ?? false) === true) $liveCardCount++;
                if (($device['isProduction'] ?? false) === true) $productionCount++;
                $type = (string) ($device['type'] ?? $device['deviceType'] ?? 'unknown');
                $deviceTypes[$type] = ($deviceTypes[$type] ?? 0) + 1;
            }
        }
        ksort($deviceTypes);
        return [
            'authenticated' => true,
            'locations' => is_array($locationRows) ? count($locationRows) : 0,
            'devices' => $deviceCount,
            'live_card_devices' => $liveCardCount,
            'production_devices' => $productionCount,
            'device_types' => $deviceTypes,
        ];
    }

    private function request(string $path): array
    {
        $baseUrl = rtrim((string) Env::get('WATTS_API_BASE_URL', 'https://p.watts-energy.dk/api'), '/');
        return $this->jsonRequest($baseUrl . $path, [
            'Authorization: Bearer ' . $this->token(),
            'Accept: application/json',
        ]);
    }

    private function token(): string
    {
        if ($this->accessToken !== null) return $this->accessToken;
        $clientId = trim((string) Env::get('WATTS_CLIENT_ID', ''));
        $secret = trim((string) Env::get('WATTS_CLIENT_SECRET', ''));
        if ($clientId === '' || $secret === '') throw new RuntimeException('Watts Client ID eller secret mangler.');

        $curl = curl_init((string) Env::get('WATTS_TOKEN_URL', self::DEFAULT_TOKEN_URL));
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'client_credentials',
                'client_id' => $clientId,
                'client_secret' => $secret,
                'scope' => Env::get('WATTS_SCOPE', self::DEFAULT_SCOPE),
            ]),
            CURLOPT_HTTPHEADER => ['Accept: application/json', 'Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_TIMEOUT => 25,
        ]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        $payload = is_string($body) ? json_decode($body, true) : null;
        if ($status < 200 || $status >= 300 || !is_array($payload)) {
            $reason = is_array($payload) ? (string) ($payload['error_description'] ?? $payload['error'] ?? '') : $error;
            throw new RuntimeException('Watts OAuth fejlede (HTTP ' . $status . ')' . ($reason !== '' ? ': ' . $reason : '.'));
        }
        $token = $payload['access_token'] ?? null;
        if (!is_string($token) || $token === '') throw new RuntimeException('Watts OAuth-svaret indeholdt intet access token.');
        return $this->accessToken = $token;
    }

    private function jsonRequest(string $url, array $headers): array
    {
        $curl = curl_init($url);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_HTTPHEADER => $headers, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_TIMEOUT => 25]);
        $body = curl_exec($curl);
        $status = (int) curl_getinfo($curl, CURLINFO_RESPONSE_CODE);
        $error = curl_error($curl);
        curl_close($curl);
        if (!is_string($body) || $status < 200 || $status >= 300) throw new RuntimeException('Watts API fejlede (HTTP ' . $status . ')' . ($error !== '' ? ': ' . $error : '.'));
        $payload = json_decode($body, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) throw new RuntimeException('Watts API returnerede et ugyldigt JSON-svar.');
        return $payload;
    }
}
