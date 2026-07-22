<?php
declare(strict_types=1);

namespace Tds\CoreFrontendApi\Tests;

use PHPUnit\Framework\TestCase;
use Tds\CoreFrontendApi\Support\JwtUserContext;

/**
 * Claim → principal mapping (the logic the four services duplicated), without a
 * live JWKS: constructs the context directly from decoded claims.
 */
final class JwtUserContextTest extends TestCase
{
    public function testAdminBypassesPermissionsAndActsAsHeaderCompany(): void
    {
        $ctx = new JwtUserContext(['admin' => true, 'uid' => 7, 'email' => 'a@x.de'], '42');

        self::assertTrue($ctx->isAuthenticated());
        self::assertTrue($ctx->isAdmin());
        self::assertSame(7, $ctx->userId());
        self::assertSame('a@x.de', $ctx->email());
        self::assertTrue($ctx->has('anything:at-all')); // admin bypass
        self::assertSame(42, $ctx->activeCompanyId());  // acts as the header company
    }

    public function testMultiCompanyResolvesActiveCompanyAndItsPermissions(): void
    {
        $claims = [
            'admin' => false,
            'sub' => 5,
            'customer_id' => 10,
            'companies' => [
                ['id' => 10, 'permissions' => ['tickets:read']],
                ['id' => 20, 'permissions' => ['tickets:read', 'tickets:write']],
            ],
        ];

        // Requests company 20 (a membership) → that company's permissions.
        $ctx = new JwtUserContext($claims, '20');
        self::assertSame(20, $ctx->activeCompanyId());
        self::assertTrue($ctx->has('tickets:write'));

        // No header → primary company (customer_id 10), which lacks :write.
        $primary = new JwtUserContext($claims, '');
        self::assertSame(10, $primary->activeCompanyId());
        self::assertTrue($primary->has('tickets:read'));
        self::assertFalse($primary->has('tickets:write'));
    }

    public function testRejectsCompanyTheLoginDoesNotBelongTo(): void
    {
        $claims = [
            'admin' => false,
            'customer_id' => 10,
            'companies' => [['id' => 10, 'permissions' => ['tickets:read']]],
        ];
        // Header asks for 99 (not a membership) → falls back to primary 10, no 99 perms.
        $ctx = new JwtUserContext($claims, '99');
        self::assertSame(10, $ctx->activeCompanyId());
    }

    public function testFallsBackToFlatPermissionsForPreMultiCompanyToken(): void
    {
        $ctx = new JwtUserContext(
            ['admin' => false, 'customer_id' => 3, 'permissions' => ['documents:read']],
            '',
        );
        self::assertSame(3, $ctx->activeCompanyId());
        self::assertTrue($ctx->has('documents:read'));
    }
}
