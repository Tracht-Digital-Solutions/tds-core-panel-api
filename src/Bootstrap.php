<?php
declare(strict_types=1);

namespace Tds\CorePanelApi;

use DI\Container;
use Dotenv\Dotenv;
use GuzzleHttp\Client as GuzzleClient;
use PDO;
use Psr\Http\Message\ResponseInterface as Response;
use Psr\Http\Message\ServerRequestInterface as Request;
use Slim\App;
use Slim\Factory\AppFactory;
use Symfony\Component\Mailer\Mailer as SymfonyMailer;
use Symfony\Component\Mailer\Transport;
use Tds\CorePanelApi\Auth\JwksClient;
use Tds\CorePanelApi\Auth\TokenVerifier;
use Tds\CorePanelApi\Middleware\AuthMiddleware;
use Tds\CorePanelApi\Middleware\CorsMiddleware;
use Tds\CorePanelApi\Service\NullMailer;
use Tds\CorePanelApi\Service\SmtpMailer;
use Tds\CorePanelApi\Support\AnonymousUserContext;
use Tds\Panel\Contract\Mailer;
use Tds\Panel\Contract\ModuleRegistry;
use Tds\Panel\Contract\UserContext;

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

        // DI container of the core services extensions may resolve (Mailer /
        // UserContext / PDO). Modules reach them via $app->getContainer().
        $container = self::container();
        AppFactory::setContainer($container);
        $app = AppFactory::create();
        $app->addBodyParsingMiddleware();
        $app->addRoutingMiddleware();
        $app->addErrorMiddleware(self::env('APP_ENV', 'production') !== 'production', true, true);
        // Auth populates the request principal (UserContext) each request; it
        // does NOT gate — routes/modules enforce via the resolved context.
        $app->add(new AuthMiddleware($container, self::tokenVerifier($rootDir)));
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

        // In-panel API wiki: the full route map of the base + every composed
        // module, introspected from the registered Slim routes at request time
        // (so all modules are present). Admin-only; the panel Wiki page renders
        // it. Auto-generated — new module routes appear without touching the UI.
        $app->get('/wiki.json', function (Request $request, Response $response) use ($app, $registry): Response {
            $user = $app->getContainer()?->get(UserContext::class);
            if ($user === null || !$user->isAdmin()) {
                $response->getBody()->write(json_encode(['error' => 'Forbidden'], JSON_THROW_ON_ERROR));
                return $response->withStatus($user === null || !$user->isAuthenticated() ? 401 : 403)
                    ->withHeader('Content-Type', 'application/json');
            }
            $routes = [];
            foreach ($app->getRouteCollector()->getRoutes() as $route) {
                $pattern = $route->getPattern();
                foreach ($route->getMethods() as $method) {
                    if ($method === 'HEAD' || $method === 'OPTIONS') {
                        continue;
                    }
                    $group = explode('/', ltrim($pattern, '/'))[0];
                    $routes[] = [
                        'method' => $method,
                        'pattern' => $pattern,
                        'group' => $group === '' ? 'root' : $group,
                    ];
                }
            }
            usort($routes, static fn (array $a, array $b): int =>
                [$a['group'], $a['pattern'], $a['method']] <=> [$b['group'], $b['pattern'], $b['method']]);
            $response->getBody()->write(json_encode([
                'generated_at' => date('c'),
                'modules' => $registry->order(),
                'routes' => $routes,
            ], JSON_THROW_ON_ERROR));
            return $response->withHeader('Content-Type', 'application/json');
        });

        return $app;
    }

    /**
     * The DI container of core services exposed to modules. All bindings are
     * lazy so boot stays side-effect-free (no DB connect, no SMTP handshake).
     */
    private static function container(): Container
    {
        $container = new Container();

        // Shared DB connection (extensions store their own tables through it).
        $container->set(PDO::class, static function (): PDO {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
                self::env('DB_HOST', '127.0.0.1'),
                self::env('DB_PORT', '3306'),
                self::env('DB_NAME', ''),
            );
            return new PDO($dsn, self::env('DB_USER', ''), self::env('DB_PASS', ''), [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        });

        // Core SMTP mailer. Unconfigured (no MAIL_DSN) → a no-op mailer, so a
        // module can call send() unconditionally. Config + From live here only.
        $container->set(Mailer::class, static function (): Mailer {
            $dsn = self::env('MAIL_DSN', '');
            if ($dsn === '') {
                return new NullMailer();
            }
            return new SmtpMailer(
                new SymfonyMailer(Transport::fromDsn($dsn)),
                self::env('MAIL_FROM', 'no-reply@tracht-digital.de'),
                self::env('MAIL_FROM_NAME', 'Tracht Digital Solutions'),
            );
        });

        // The default binding is anonymous; AuthMiddleware rebinds it per
        // request to a JwtUserContext when a valid token is presented.
        $container->set(UserContext::class, static fn (): UserContext => new AnonymousUserContext());

        return $container;
    }

    /**
     * The JWKS token verifier, or null when auth is unconfigured (`AUTH_API_URL`
     * unset — local dev / boot) so every request is anonymous rather than 500ing.
     */
    private static function tokenVerifier(string $rootDir): ?TokenVerifier
    {
        $authUrl = self::env('AUTH_API_URL', '');
        if ($authUrl === '') {
            return null;
        }
        return new JwksClient(
            new GuzzleClient(['timeout' => 5]),
            rtrim($authUrl, '/') . '/.well-known/jwks.json',
            $rootDir . '/var/cache',
            (int) self::env('JWKS_CACHE_TTL', '600'),
        );
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
