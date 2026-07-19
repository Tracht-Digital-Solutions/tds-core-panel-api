<?php
declare(strict_types=1);

namespace Tds\CorePanelApi\Support;

use Phinx\Config\Config;
use Phinx\Migration\Manager;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Throwable;

/**
 * Brings the composed panel schema up to date automatically, once per deployed
 * migration-set, from inside the API process.
 *
 * Why: the prod host (Plesk) installs + runs **without SSH/cron** and commonly
 * **disables `proc_open`**, so shelling out to `phinx` silently applies nothing
 * (the outage that motivated the gateway's runner). This closes the gap for the
 * composed panel API: on the first request after a deploy every enabled
 * extension's pending migrations are applied in-process (Phinx's PHP `Manager`,
 * no proc_open, no CLI php), then the DB is never touched again until the next
 * deploy adds/removes a migration.
 *
 * Unlike the gateway (one bundle, N services × N databases), the panel API is
 * ONE app over ONE database: every extension contributes migration dirs via
 * {@see \Tds\Panel\Contract\ModuleRegistry::migrationPaths()} and they all share
 * a single `phinxlog`, applied in version order by one Manager run.
 *
 * Safety properties (mirrors the gateway runner):
 *  - **Idempotent & cheap steady-state** — a marker file keyed to the set of
 *    migration files short-circuits every request once applied (hot path is an
 *    `is_file()`); the marker name changes only when a migration is added/removed.
 *  - **Single-flight** — an exclusive non-blocking `flock` means only the first
 *    worker after a deploy migrates; concurrent workers skip and serve normally.
 *  - **Never fatal** — any failure is logged and swallowed; a hiccup must not
 *    take the API down. A partial failure isn't marked done, so it retries.
 *  - **Only pending work** — Phinx applies just the migrations not yet in
 *    `phinxlog`; a fully-migrated DB is a no-op.
 *  - **Collision-guarded** — Phinx `include`s every migration file into ONE
 *    process, so two extensions declaring the same migration class name would be
 *    an uncatchable fatal redeclaration. Class names are scanned up front and the
 *    run is skipped + logged instead of fataling (convention: module-prefixed
 *    class names keep them globally unique).
 */
final class MigrationRunner
{
    /** @var callable(): array{0: bool, 1: string} */
    private $migrate;

    /**
     * @param string[]             $migrationPaths Absolute Phinx migration dirs (from the registry).
     * @param array<string,string> $db             DB config: host, port, name, user, pass.
     * @param string               $stateDir       Preferred dir for the marker + lock (falls back to the system temp dir).
     * @param (callable(): array{0: bool, 1: string})|null $migrate Runs all pending migrations, returns [ok, output]. Defaults to in-process Phinx; tests inject a fake.
     */
    public function __construct(
        private readonly array $migrationPaths,
        private readonly array $db,
        private readonly string $stateDir,
        private readonly ?LoggerInterface $logger = null,
        ?callable $migrate = null,
    ) {
        $this->migrate = $migrate ?? fn (): array => self::phinxInProcess($this->migrationPaths, $this->db);
    }

    /** Best-effort entry point — brings the schema up to date, never throws. */
    public function ensureMigrated(): void
    {
        try {
            $this->run();
        } catch (Throwable $e) {
            $this->log('error', 'auto-migrate: unexpected failure: ' . $e->getMessage());
        }
    }

    private function run(): void
    {
        if ($this->migrationPaths === []) {
            return; // zero extensions — nothing to migrate
        }

        $stateDir = $this->resolveStateDir();
        if ($stateDir === null) {
            $this->log('warning', 'auto-migrate skipped: no writable state dir');
            return;
        }

        $marker = $stateDir . '/.migrated-' . $this->signature();
        if (is_file($marker)) {
            return; // already migrated for this exact migration-set — hot path
        }

        // Guard the fatal-redeclaration case BEFORE Phinx includes the files.
        $collision = $this->classCollision();
        if ($collision !== null) {
            $this->log('error', "auto-migrate aborted: duplicate migration class '{$collision}' across extensions "
                . '(would fatal when included into the shared process)');
            return;
        }

        $lock = @fopen($stateDir . '/.migrate.lock', 'c');
        if ($lock === false) {
            $this->log('warning', 'auto-migrate skipped: could not open lock file');
            return;
        }

        try {
            if (!flock($lock, LOCK_EX | LOCK_NB)) {
                return; // another worker is already migrating — let it finish
            }
            if (is_file($marker)) {
                return; // won the race, but the winner already finished
            }

            [$ok, $out] = ($this->migrate)();
            if ($ok) {
                @file_put_contents($marker, gmdate('c') . "\n");
                $this->log('info', 'auto-migrate: schema up to date');
            } else {
                // Not marked done → retries on the next request instead of latching.
                $this->log('error', 'auto-migrate failed: ' . $out);
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Apply all pending migrations across every path via Phinx's PHP API — no
     * proc_open, no CLI php. One environment, one `phinxlog`, version-ordered.
     *
     * @param string[]             $paths
     * @param array<string,string> $db
     * @return array{0: bool, 1: string} [ok, output]
     */
    private static function phinxInProcess(array $paths, array $db): array
    {
        if (($db['name'] ?? '') === '') {
            return [false, 'DB_NAME is not configured'];
        }

        $config = new Config([
            'paths' => ['migrations' => array_values($paths)],
            'environments' => [
                'default_migration_table' => 'phinxlog',
                'default_environment' => 'production',
                'production' => [
                    'adapter' => 'mysql',
                    'host' => $db['host'] ?? '127.0.0.1',
                    'port' => $db['port'] ?? '3306',
                    'name' => $db['name'],
                    'user' => $db['user'] ?? 'root',
                    'pass' => $db['pass'] ?? '',
                    'charset' => 'utf8mb4',
                    'collation' => 'utf8mb4_unicode_ci',
                ],
            ],
        ]);

        $output = new BufferedOutput();
        try {
            $manager = new Manager($config, new ArrayInput([]), $output);
            $manager->migrate('production');
            return [true, trim($output->fetch())];
        } catch (Throwable $e) {
            return [false, 'in-process migrate failed: ' . $e->getMessage() . "\n" . trim($output->fetch())];
        }
    }

    /**
     * The first migration class name declared by two different files across all
     * paths, or null when every class name is unique. Scanned as text (an actual
     * include of a duplicate is the very fatal this guards against).
     */
    private function classCollision(): ?string
    {
        $seen = [];
        foreach ($this->migrationPaths as $dir) {
            foreach ((array) glob(rtrim($dir, '/\\') . '/*.php') as $file) {
                $src = (string) @file_get_contents((string) $file);
                if (preg_match_all('/^\s*(?:final\s+|abstract\s+)?class\s+(\w+)/mi', $src, $m) > 0) {
                    foreach ($m[1] as $class) {
                        if (isset($seen[$class])) {
                            return $class;
                        }
                        $seen[$class] = true;
                    }
                }
            }
        }
        return null;
    }

    /** Preferred state dir if writable, else a per-host temp dir, else null. */
    private function resolveStateDir(): ?string
    {
        foreach ([$this->stateDir, sys_get_temp_dir() . '/tds-panel-migrate'] as $dir) {
            if (is_dir($dir) && is_writable($dir)) {
                return $dir;
            }
            if (!is_dir($dir) && @mkdir($dir, 0775, true) && is_writable($dir)) {
                return $dir;
            }
        }
        return null;
    }

    /**
     * Signature over every migration filename across all paths. Adding/removing a
     * migration changes it (→ re-run on next deploy); a plain redeploy keeps it
     * (→ stays a no-op).
     */
    private function signature(): string
    {
        $names = [];
        foreach ($this->migrationPaths as $dir) {
            foreach ((array) glob(rtrim($dir, '/\\') . '/*.php') as $file) {
                // Basename only — the path differs between local (path repo) and
                // deployed (vendor/) but the migration-set is the same.
                $names[] = basename((string) $file);
            }
        }
        sort($names);
        return substr(hash('sha256', implode('|', $names)), 0, 16);
    }

    private function log(string $level, string $message): void
    {
        if ($this->logger !== null) {
            $this->logger->log($level, $message);
            return;
        }
        // No PSR logger wired — surface errors/warnings in the FPM error log so a
        // migration problem is visible rather than silent.
        if ($level === 'error' || $level === 'warning') {
            error_log('[tds-panel migrate] ' . $message);
        }
    }
}
