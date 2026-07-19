# AGENTS.md — tds-core-panel-api

The base panel API kernel. Read `tds-panel-contract`'s AGENTS.md first — this
repo consumes that contract's PHP half (`Module` + `ModuleRegistry`).

## Model

In-process composition, like the gateway: `Modules::enabled()` returns the
extension `Module`s for this build; `Bootstrap` composes them through a
`ModuleRegistry` (dependency-ordered, collision-checked) and mounts their routes.
One PHP-FPM app, no service processes. The base ships the kernel routes
(`/healthz`, `/admin/permissions`, `/wiki.json`, `/me/dashboard-layout`,
`/admin/settings/{ns}`); it MUST boot with zero modules.

## Runtime settings store

`Service\SettingsStore` (bound in the container, resolvable by modules via the contract `SettingsStore` interface) is a
namespaced key/value store so third-party config (DeepL keys, rebuild tokens, …)
is panel-editable instead of `.env`-only. **Read pattern for consumers: DB-first
with env fallback** — a non-empty stored value wins, else the env var, else a
coded default. **Secrets are AES-256-GCM-encrypted at rest** under
`SETTINGS_ENCRYPTION_KEY` (`v1:base64(iv|tag|cipher)`); the admin API
(`GET`/`PUT /admin/settings/{ns}`, admin-only) returns only masked state
(`configured` + `last4`), and a blank secret on save means "keep existing". The
`app_setting` table (`namespace`×`skey`, `svalue`, `is_secret`) **self-bootstraps**
(no migrator yet — same as the dashboard-layout table). Namespaces are per-extension
(`blog-cms`, `website-cms`, …) so keys don't collide in the shared table. An
extension adopts it by resolving `SettingsStore` from the container (or reading the
shared `app_setting` table via the core PDO); the DeepL/rebuild env vars stay the
fallback.

## Base-service data (per-user dashboard layout)

`GET`/`PUT /me/dashboard-layout` persist each authenticated user's dashboard
widget arrangement (which widgets show + order), keyed by the JWT `userId` — no
admin gate, a user manages their own. `Domain\DashboardLayoutRepository` owns the
`user_dashboard_layout` table (`user_id`×`widget_id`, `visible`, `sort`). PUT
replaces the whole layout (order = array position → `sort`), validating widget ids
against `^[a-z0-9:_-]{1,64}$`. **The core has no Phinx migration runner yet** (it
lands with the assemble pipeline), so this base table **self-bootstraps**: an
idempotent `CREATE TABLE IF NOT EXISTS` runs once per process. When the migrator
lands, move that DDL into a base migration and drop `ensureSchema()`.

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
  prefixes with its module id; the base only aggregates the paths. **Migration
  *versions* (the numeric filename prefix) must also be unique across extensions**
  — they share ONE `phinxlog`, so a duplicate version makes Phinx throw.
- **In-process auto-migrator (`Support/MigrationRunner`).** On the first request
  after a deploy, `Bootstrap::autoMigrate()` applies every enabled extension's
  pending migrations via Phinx's PHP `Manager` (no `proc_open`/cron/CLI php — the
  prod host has none), over all `registry->migrationPaths()` into one `phinxlog`.
  A signature-keyed marker + non-blocking `flock` make it a cheap single-flight
  no-op after the first run; a class-name collision or failure is logged and
  swallowed (never fatal), and a failure isn't marked done so it retries. **Gated
  off when `DB_NAME` is empty (tests/boot) or `AUTO_MIGRATE=0`.** Base self-
  bootstrap tables (`app_setting`, `user_dashboard_layout`) still use their own
  `ensureSchema()` — move them to base migrations here when convenient.
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

The **assemble/deploy pipeline** (bundle base + enabled extension repos into a
self-contained artifact, like the gateway's `_assemble.yml`, + a deploy webhook)
is the remaining half of the deployment story — the in-process auto-migrator
(above) is done, so once the bundle deploys, the schema comes up on its own. CI
is deferred until the cross-repo-dep resolution (published packages vs. bundled
`vendor/`) is designed. Tracked in issues #1 (pipeline) / #2 (cutover).

## After a change

Bump `version` in `composer.json`, update this file + README, commit together.
