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
        self::assertContains('lexware', $body['modules']);
        self::assertContains('customers', $body['modules']);
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
        self::assertContains('lexware:read', $ids);
        self::assertContains('customers:read', $ids);
    }

    public function testModuleRouteIsMounted(): void
    {
        // The composed time-tracker mounts /time/summary and gates it via the core
        // UserContext — anonymous → 401 (a 404 would mean the route wasn't injected).
        $app = Bootstrap::createApp(dirname(__DIR__));
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/time/summary');
        $response = $app->handle($request);

        self::assertSame(401, $response->getStatusCode());
    }

    public function testLexwareRouteIsMounted(): void
    {
        // The composed lexware module mounts /lexware/summary and gates it via
        // the core UserContext — anonymous → 401 (404 would mean not injected).
        $app = Bootstrap::createApp(dirname(__DIR__));
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/lexware/summary');
        $response = $app->handle($request);

        self::assertSame(401, $response->getStatusCode());
    }

    public function testCustomersRouteIsMounted(): void
    {
        $app = Bootstrap::createApp(dirname(__DIR__));
        $request = (new ServerRequestFactory())->createServerRequest('GET', '/customers/summary');
        self::assertSame(401, $app->handle($request)->getStatusCode());
    }

    public function testWikiJsonRequiresAdmin(): void
    {
        // No token → anonymous → 401 (the admin route map is not public). The
        // admin 200 path needs a real JWT, out of scope for the no-DB suite.
        $app = Bootstrap::createApp(dirname(__DIR__));
        $response = $app->handle((new ServerRequestFactory())->createServerRequest('GET', '/wiki.json'));
        self::assertSame(401, $response->getStatusCode());
    }

    public function testDashboardLayoutRequiresAuth(): void
    {
        // No token → anonymous → 401 on both read and write, before any DB touch.
        $app = Bootstrap::createApp(dirname(__DIR__));
        self::assertSame(401, $app->handle(
            (new ServerRequestFactory())->createServerRequest('GET', '/me/dashboard-layout')
        )->getStatusCode());
        self::assertSame(401, $app->handle(
            (new ServerRequestFactory())->createServerRequest('PUT', '/me/dashboard-layout')
                ->withParsedBody(['layout' => []])
        )->getStatusCode());
    }
}
