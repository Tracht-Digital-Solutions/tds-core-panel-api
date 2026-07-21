# tds-core-panel-api

The **base panel API kernel** (PHP 8 + Slim) — the backend twin of
`tds-core-frontend-pkg`. It composes enabled extension **Modules** in-process
via `tds-panel-contract-pkg` into one app (the same "one PHP-FPM app, no service
processes" model the gateway already uses), and ships the base kernel routes.

## What it does

- Builds a `ModuleRegistry` from `Modules::enabled()` (the backend twin of the
  frontend product's `astro.config` extension list), composing them
  dependency-ordered with collision checks.
- Mounts every module's routes (`registerAll`).
- Serves the kernel routes: `GET /healthz` (status + composed module list) and
  `GET /admin/permissions` (the merged RBAC catalog contributed by all modules).
- Exposes `Bootstrap::migrationPaths()` for the in-process auto-migrator (ported
  next).

The base MUST boot with **zero** modules — extensions are additive. The admin and
customer API targets differ only in what `Modules::enabled()` returns.

## Develop

```bash
composer install     # path repos → ../tds-panel-contract, ../tds-ext-time-tracker
composer start        # php -S localhost:8100 -t public public/router.php
composer test         # phpunit
```

`public/router.php` is required for `php -S` (the built-in server 404s dotted
paths without it).

## Status

Skeleton that **validates backend composition end-to-end** (health lists the
composed module, the merged permission catalog resolves, a module route is
mounted, and the CORS preflight is answered — see `tests/`). Still to port from
`tds-auth-api` + `tds-admin`: user management + RS256 JWT/JWKS, the wiki, email,
the in-process auto-migrator, the runtime settings store, and the assemble/deploy
pipeline (which bundles base + enabled extension repos, like the gateway).

CI is intentionally not wired yet: cross-repo module deps resolve via local path
repos today; the published-package / bundle-assembly path is blocked on the org's
GitHub Packages billing and needs the deploy design (see AGENTS.md).
