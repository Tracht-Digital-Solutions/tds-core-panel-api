<?php
declare(strict_types=1);

namespace Tds\CorePanelApi\Middleware;

use DI\Container;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Tds\CorePanelApi\Auth\TokenVerifier;
use Tds\CorePanelApi\Support\AnonymousUserContext;
use Tds\CorePanelApi\Support\JwtUserContext;
use Tds\Panel\Contract\UserContext;

/**
 * Populates the request principal for every request, then hands off. It does
 * NOT gate — routes/modules enforce their own auth via the resolved
 * {@see UserContext} (a RequirePermission middleware or in-action checks). This
 * keeps auth in ONE place: modules read the context, never re-verify a token.
 *
 * The token comes from the `Authorization: Bearer` header or the cross-subdomain
 * `tds_session` cookie tds-auth-api sets (so the static panels authenticate with
 * `credentials: 'include'`). A missing/invalid token → anonymous context.
 *
 * It rebinds `UserContext::class` on the shared container each request. Safe in
 * the in-process model (one request per PHP-FPM worker at a time); the binding
 * is always set (Jwt or Anonymous) so no request inherits another's principal.
 */
final class AuthMiddleware implements MiddlewareInterface
{
    public const COOKIE_NAME = 'tds_session';
    private const ACT_AS_HEADER = 'X-Act-As-Customer';

    public function __construct(
        private readonly Container $container,
        private readonly ?TokenVerifier $verifier,
    ) {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $context = new AnonymousUserContext();

        $token = $this->extractToken($request);
        if ($token !== null && $this->verifier !== null) {
            try {
                $claims = $this->verifier->verify($token);
                $context = new JwtUserContext($claims, $request->getHeaderLine(self::ACT_AS_HEADER));
            } catch (\Throwable) {
                // Invalid/expired token → stay anonymous (routes decide the 401).
            }
        }

        $this->container->set(UserContext::class, $context);
        return $handler->handle($request);
    }

    private function extractToken(ServerRequestInterface $request): ?string
    {
        $auth = $request->getHeaderLine('Authorization');
        if ($auth !== '' && preg_match('/^Bearer\s+(.+)$/i', $auth, $m) === 1) {
            return $m[1];
        }
        $cookie = $request->getCookieParams()[self::COOKIE_NAME] ?? null;
        return is_string($cookie) && $cookie !== '' ? $cookie : null;
    }
}
