# AGENTS.md — tds-core-panel-api

The base panel API kernel. Read `tds-panel-contract`'s AGENTS.md first — this
repo consumes that contract's PHP half (`Module` + `ModuleRegistry`).

## Model

In-process composition, like the gateway: `Modules::enabled()` returns the
extension `Module`s for this build; `Bootstrap` composes them through a
`ModuleRegistry` (dependency-ordered, collision-checked) and mounts their routes.
One PHP-FPM app, no service processes. The base ships only kernel routes
(`/healthz`, `/admin/permissions`); it MUST boot with zero modules.

## Load-bearing gotchas (carried from the four APIs)

- **CORS middleware is added AFTER `addRoutingMiddleware()`** (Slim is LIFO, so
  it must be outermost) or OPTIONS preflights get 405'd and browsers block every
  cross-origin request. `tests/PreflightTest.php` guards this through the REAL
  Bootstrap app — never delete it.
- **`env()` uses explicit `?? false` checks**, never
  `$_ENV[$k] ?? getenv($k) ?: $default` (`??` binds tighter than `?:`, clobbering
  "0"/""). See `Bootstrap::env()`.
- **Migration class names must be globally unique** across every module (the
  in-process auto-migrator includes them all into one process). Each extension
  prefixes with its module id; the base only aggregates the paths.
- **`php -S` needs `public/router.php`** (built-in server 404s dotted paths).

## Core services for modules

`Bootstrap::container()` binds the services extensions resolve via
`$app->getContainer()->get(...)` — all lazy (boot does no DB/SMTP work):
- **`PDO`** — the shared DB connection (env `DB_*`).
- **`Mailer`** (panel-contract) — SMTP via Symfony Mailer when `MAIL_DSN` is set,
  else `NullMailer` (`isConfigured()` false). From identity is core-owned
  (`MAIL_FROM`/`MAIL_FROM_NAME`); no extension configures its own SMTP.
- **`UserContext`** (panel-contract) — the request principal, populated by
  `AuthMiddleware` from the verified RS256 JWT (`Auth\JwksClient` against
  tds-auth-api's JWKS). Maps admin/uid + the multi-company claims + the
  `X-Act-As-Customer` header to `isAdmin`/`userId`/`permissions`/`activeCompanyId`
  (see `Support\JwtUserContext`). Auth is centralized here — **modules read the
  UserContext, never verify a token themselves**.

`AuthMiddleware` is **non-gating**: it sets the principal (Jwt or anonymous) and
hands off; routes/modules enforce their own auth via the context (a
RequirePermission middleware or in-action checks). It rebinds `UserContext` on the
shared container per request — safe in the in-process (one-request-per-worker)
model. Unset `AUTH_API_URL` → no verifier → every request anonymous (boot/dev
works without auth-api).

## Enabling a module

Add `new SomeModule()` to `Modules::enabled()` and add the extension's Composer
package (path repo for local dev; published/bundled for CI/deploy). The registry
throws on a duplicate id / missing dep / cycle / duplicate permission key.

## Not done yet (next)

User management + RS256 JWT/JWKS (port from tds-auth-api), wiki, email, the
in-process auto-migrator (consume `Bootstrap::migrationPaths()`), the runtime
settings store, and the assemble/deploy pipeline (bundle base + enabled extension
repos, like the gateway's `_assemble.yml`). CI is deferred until the
cross-repo-dep resolution (published packages vs. bundle) is designed and the
org's GitHub Packages billing is restored.

## After a change

Bump `version` in `composer.json`, update this file + README, commit together.
