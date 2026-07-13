<?php
declare(strict_types=1);

namespace Tds\CorePanelApi\Auth;

/**
 * Verifies an RS256 JWT and returns its claims. Implemented by {@see JwksClient}
 * (against tds-auth-api's JWKS); stubbable in tests.
 */
interface TokenVerifier
{
    /**
     * @return array<string, mixed> decoded claims
     * @throws \Throwable on any failure (bad signature, expired, malformed)
     */
    public function verify(string $jwt): array;
}
