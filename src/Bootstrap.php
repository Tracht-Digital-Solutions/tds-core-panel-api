<?php
declare(strict_types=1);

namespace Tds\CorePanelApi;

use Dotenv\Dotenv;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Factory\AppFactory;
use Tds\CorePanelApi\Middleware\CorsMiddleware;
use Tds\Panel\Contract\ModuleRegistry;

/**
 * Wires the base panel API: env, Slim app, middleware, base routes, and the
 * composition of enabled extension Modules (in-process, via panel-contract's
 * ModuleRegistry) — the backend twin of core-panel-frontend's panelHost.
 *
 * The base ships only the kernel routes here (/healthz, /admin/permissions);
 * user management, wiki, email etc. are ported in next. It MUST boot with zero
 * modules — extensions are additive.
 */
final class Bootstrap
{
    public static function createApp(string $rootDir): App
    {
        if (file_exists($rootDir . '/.env')) {
            Dotenv::createImmutable($rootDir)->load();
        }

        $app = AppFactory::create();
        $app->addBodyParsingMiddleware();
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(self::env('APP_ENV', 'production') !== 'production', true, true);
        // Slim middleware is LIFO — the LAST added runs FIRST. CORS must be
        // added after routing so it is outermost; otherwise routing 405s an
        // OPTIONS preflight (no OPTIONS routes) before CORS can short-circuit
        // it, and browsers block every cross-origin request. See PreflightTest.
        $app->add(new CorsMiddleware(self::corsOrigins()));

        // Compose the enabled extensions. A duplicate id / missing dep / cycle
        // throws here (fail fast at boot), and a duplicate permission/setting
        // key throws when the catalog is read below.
        $registry = new ModuleRegistry(Modules::enabled());
        $registry->registerAll($app);

        // --- Base kernel routes -------------------------------------------------
        $app->get('/healthz', function (Request $request, Response $response) use ($registry): Response {
            $response->getBody()->write(json_encode([
                'status' => 'ok',
                'modules' => $registry->order(),
            ], JSON_THROW_ON_ERROR));
            return $response->withHeader('Content-Type', 'application/json');
        });

        // Merged RBAC permission catalog contributed by all modules — the base
        // surfaces it for the admin user editor (permission gating lives in each
        // module's routes/JWT, this is just the catalog).
        $app->get('/admin/permissions', function (Request $request, Response $response) use ($registry): Response {
            $permissions = array_map(
                static fn ($p): array => $p->toArray(),
                $registry->permissions(),
            );
            $response->getBody()->write(json_encode($permissions, JSON_THROW_ON_ERROR));
            return $response->withHeader('Content-Type', 'application/json');
        });

        return $app;
    }

    /**
     * All enabled modules' Phinx migration directories, for the in-process
     * auto-migrator (ported next). Exposed so the migration runner can consume
     * it without rebuilding the registry.
     *
     * @return string[]
     */
    public static function migrationPaths(): array
    {
        return (new ModuleRegistry(Modules::enabled()))->migrationPaths();
    }

    /**
     * Env reader. NB explicit `?? false` checks — never
     * `$_ENV[$key] ?? getenv($key) ?: $default`, which clobbers falsy values
     * ("0", "") because `??` binds tighter than `?:` (the trap that bit all
     * four APIs via copy-paste).
     */
    private static function env(string $key, ?string $default = null): string
    {
        $v = $_ENV[$key] ?? false;
        if ($v === false) {
            $v = getenv($key);
        }
        if ($v === false) {
            $v = $default;
        }
        if ($v === null) {
            throw new \RuntimeException("Missing required env var: {$key}");
        }
        return (string) $v;
    }

    /** @return string[] */
    private static function corsOrigins(): array
    {
        $raw = self::env('CORS_ALLOWED_ORIGINS', '');
        return array_values(array_filter(array_map('trim', explode(',', $raw))));
    }
}
