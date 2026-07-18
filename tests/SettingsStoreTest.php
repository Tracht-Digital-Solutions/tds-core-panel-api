<?php
declare(strict_types=1);

namespace Tds\CorePanelApi\Tests;

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tds\CorePanelApi\Bootstrap;
use Tds\CorePanelApi\Service\SettingsStore;

/**
 * Crypto round-trip (no DB) + admin-route gating through the REAL app. The
 * DB-backed get/set/mask paths skip without a database — the crypto and the auth
 * gate are the parts worth pinning here.
 */
final class SettingsStoreTest extends TestCase
{
    public function testEncryptRoundTrips(): void
    {
        $key = 'unit-test-key';
        $cipher = SettingsStore::encrypt('DEEPL-abcd-1234', $key);
        self::assertStringStartsWith('v1:', $cipher);
        self::assertNotSame('DEEPL-abcd-1234', $cipher);
        self::assertSame('DEEPL-abcd-1234', SettingsStore::decrypt($cipher, $key));
    }

    public function testDecryptRejectsWrongKeyAndGarbage(): void
    {
        $cipher = SettingsStore::encrypt('secret', 'right-key');
        self::assertNull(SettingsStore::decrypt($cipher, 'wrong-key'));
        self::assertNull(SettingsStore::decrypt('not-a-cipher', 'right-key'));
        self::assertNull(SettingsStore::decrypt('v1:!!!!', 'right-key'));
    }

    public function testAdminSettingsRequireAdmin(): void
    {
        // Anonymous (no token) → 401 on both read and write, before any DB touch.
        $app = Bootstrap::createApp(dirname(__DIR__));
        self::assertSame(401, $app->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/admin/settings/blog-cms')
        )->getStatusCode());
        self::assertSame(401, $app->handle(
            (new ServerRequestFactory())->createServerRequest('PUT', '/admin/settings/blog-cms')
                ->withParsedBody(['settings' => []])
        )->getStatusCode());
    }
}
