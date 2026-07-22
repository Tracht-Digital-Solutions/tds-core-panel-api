<?php
declare(strict_types=1);

namespace Tds\CoreFrontendApi\Tests;

use DI\Container;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Factory\ServerRequestFactory;
use Slim\Psr7\Response;
use Tds\CoreFrontendApi\Auth\TokenVerifier;
use Tds\CoreFrontendApi\Middleware\AuthMiddleware;
use Tds\CoreFrontendApi\Support\AnonymousUserContext;
use Tds\Frontend\Contract\UserContext;

/**
 * AuthMiddleware populates the container's UserContext each request (Jwt when a
 * valid token is presented, anonymous otherwise) and never gates. Uses a stub
 * verifier so no live JWKS is needed.
 */
final class AuthMiddlewareTest extends TestCase
{
    public function testBindsJwtContextForAValidBearerToken(): void
    {
        $container = new Container();
        $container->set(UserContext::class, static fn () => new AnonymousUserContext());

        $verifier = new class implements TokenVerifier {
            public function verify(string $jwt): array
            {
                return ['admin' => true, 'uid' => 1];
            }
        };

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/x')
            ->withHeader('Authorization', 'Bearer whatever');

        (new AuthMiddleware($container, $verifier))->process($request, $this->passThrough());

        $ctx = $container->get(UserContext::class);
        self::assertTrue($ctx->isAuthenticated());
        self::assertTrue($ctx->isAdmin());
    }

    public function testStaysAnonymousWithoutAToken(): void
    {
        $container = new Container();
        $container->set(UserContext::class, static fn () => new AnonymousUserContext());
        $verifier = new class implements TokenVerifier {
            public function verify(string $jwt): array
            {
                throw new \RuntimeException('should not be called');
            }
        };

        $request = (new ServerRequestFactory())->createServerRequest('GET', '/x');
        (new AuthMiddleware($container, $verifier))->process($request, $this->passThrough());

        self::assertFalse($container->get(UserContext::class)->isAuthenticated());
    }

    public function testInvalidTokenFallsBackToAnonymous(): void
    {
        $container = new Container();
        $container->set(UserContext::class, static fn () => new AnonymousUserContext());
        $verifier = new class implements TokenVerifier {
            public function verify(string $jwt): array
            {
                throw new \RuntimeException('bad signature');
            }
        };

        $request = (new ServerRequestFactory())
            ->createServerRequest('GET', '/x')
            ->withHeader('Authorization', 'Bearer bad');
        (new AuthMiddleware($container, $verifier))->process($request, $this->passThrough());

        self::assertFalse($container->get(UserContext::class)->isAuthenticated());
    }

    private function passThrough(): RequestHandlerInterface
    {
        return new class implements RequestHandlerInterface {
            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                return new Response(200);
            }
        };
    }
}
