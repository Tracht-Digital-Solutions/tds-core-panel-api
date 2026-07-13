<?php
declare(strict_types=1);

namespace Tds\CorePanelApi\Tests;

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tds\CorePanelApi\Bootstrap;

/**
 * Composition end-to-end through the REAL app: the base boots, composes the
 * enabled modules, mounts their routes, and surfaces the merged catalog.
 */
final class CompositionTest extends TestCase
{
    public function testHealthListsComposedModules(): void
    {
        $app = Bootstrap::createApp(dirname(__DIR__));
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/healthz');
        $response = $app->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $body = json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('ok', $body['status']);
        self::assertContains('time-tracker', $body['modules']);
    }

    public function testAdminPermissionsExposesMergedCatalog(): void
    {
        $app = Bootstrap::createApp(dirname(__DIR__));
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/admin/permissions');
        $response = $app->handle($request);

        self::assertSame(200, $response->getStatusCode());
        $ids = array_column(
            json_decode((string) $response->getBody(), true, 512, JSON_THROW_ON_ERROR),
            'id',
        );
        self::assertContains('time:read', $ids);
    }

    public function testModuleRouteIsMounted(): void
    {
        $app = Bootstrap::createApp(dirname(__DIR__));
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/time/summary');
        $response = $app->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertStringContainsString('weekHours', (string) $response->getBody());
    }
}
