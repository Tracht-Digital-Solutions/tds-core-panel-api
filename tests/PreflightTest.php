<?php
declare(strict_types=1);

namespace Tds\CoreFrontendApi\Tests;

use PHPUnit\Framework\TestCase;
use Slim\Psr7\Factory\ServerRequestFactory;
use Tds\CoreFrontendApi\Bootstrap;

/**
 * Regression guard for the Slim LIFO CORS ordering trap. An OPTIONS preflight
 * must be short-circuited by CorsMiddleware (204 + CORS headers), NOT 405'd by
 * the routing middleware. This runs through the REAL Bootstrap app — unit-
 * testing the middleware in isolation cannot catch the ordering mistake.
 */
final class PreflightTest extends TestCase
{
    public function testPreflightIsAnsweredWithCorsHeaders(): void
    {
        $_ENV['CORS_ALLOWED_ORIGINS'] = 'https://management.tracht-digital.de';
        $app = Bootstrap::createApp(dirname(__DIR__));

        $request = (new ServerRequestFactory())
            ->createServerRequest('OPTIONS', '/admin/permissions')
            ->withHeader('Origin', 'https://management.tracht-digital.de')
            ->withHeader('Access-Control-Request-Method', 'GET');
        $response = $app->handle($request);

        self::assertSame(204, $response->getStatusCode());
        self::assertSame(
            'https://management.tracht-digital.de',
            $response->getHeaderLine('Access-Control-Allow-Origin'),
        );
        self::assertStringContainsString('OPTIONS', $response->getHeaderLine('Access-Control-Allow-Methods'));

        unset($_ENV['CORS_ALLOWED_ORIGINS']);
    }
}
