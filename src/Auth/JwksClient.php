<?php
declare(strict_types=1);

namespace Tds\CorePanelApi\Auth;

use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use GuzzleHttp\Client;

/**
 * Fetches tds-auth-api's JWKS, caches it on disk for the configured TTL, and
 * verifies RS256 bearer tokens against it. Ported from tds-content-api /
 * tds-customer-api — the base now owns auth verification for every mounted
 * module (they read the resulting UserContext, never re-verify).
 */
final class JwksClient implements TokenVerifier
{
    public function __construct(
        private readonly Client $http,
        private readonly string $jwksUrl,
        private readonly string $cacheDir,
        private readonly int $cacheTtl,
    ) {
    }

    /** @return array<string, mixed> */
    public function verify(string $jwt): array
    {
        $keys = JWK::parseKeySet($this->loadJwks());
        return (array) JWT::decode($jwt, $keys);
    }

    /** @return array<string, mixed> */
    private function loadJwks(): array
    {
        $cacheFile = $this->cacheDir . '/jwks.json';
        if (is_file($cacheFile) && filemtime($cacheFile) > time() - $this->cacheTtl) {
            $raw = file_get_contents($cacheFile);
            if ($raw !== false) {
                $decoded = json_decode($raw, true);
                if (is_array($decoded)) {
                    return $decoded;
                }
            }
        }

        $raw = (string) $this->http->get($this->jwksUrl)->getBody();
        $decoded = json_decode($raw, true);
        if (!is_array($decoded) || !isset($decoded['keys'])) {
            throw new \RuntimeException("Invalid JWKS response from {$this->jwksUrl}");
        }

        if (!is_dir($this->cacheDir)) {
            @mkdir($this->cacheDir, 0755, true);
        }
        @file_put_contents($cacheFile, $raw);

        return $decoded;
    }
}
