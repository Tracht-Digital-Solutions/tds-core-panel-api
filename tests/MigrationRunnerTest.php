<?php
declare(strict_types=1);

namespace Tds\CorePanelApi\Tests;

use PHPUnit\Framework\TestCase;
use Tds\CorePanelApi\Support\MigrationRunner;

/**
 * DB-free coverage of the auto-migrator's control flow — the marker/single-flight
 * bookkeeping and the collision guard — with an injected fake migrate callable.
 * (The real Phinx path is exercised by deploying against MySQL.)
 */
final class MigrationRunnerTest extends TestCase
{
    private string $tmp;

    protected function setUp(): void
    {
        $this->tmp = sys_get_temp_dir() . '/tds-mrt-' . uniqid('', true);
        mkdir($this->tmp, 0775, true);
    }

    protected function tearDown(): void
    {
        self::rmrf($this->tmp);
    }

    /** A migration dir with one file per class name. @param string[] $classes */
    private function migDir(string $name, array $classes): string
    {
        $dir = $this->tmp . '/' . $name;
        mkdir($dir . '/db/migrations', 0775, true);
        $ts = 20260719000000;
        foreach ($classes as $class) {
            file_put_contents(
                $dir . '/db/migrations/' . (++$ts) . '_' . strtolower($class) . '.php',
                "<?php\nfinal class {$class} {}\n",
            );
        }
        return $dir . '/db/migrations';
    }

    private function stateDir(): string
    {
        return $this->tmp . '/state';
    }

    public function testAppliesPendingAndWritesMarker(): void
    {
        $calls = 0;
        $runner = new MigrationRunner(
            [$this->migDir('a', ['CreateA'])],
            ['name' => 'x'],
            $this->stateDir(),
            null,
            function () use (&$calls): array {
                $calls++;
                return [true, 'ok'];
            },
        );
        $runner->ensureMigrated();

        self::assertSame(1, $calls);
        $markers = glob($this->stateDir() . '/.migrated-*');
        self::assertNotEmpty($markers, 'a marker should be written on success');
    }

    public function testMarkerShortCircuitsSecondRun(): void
    {
        $calls = 0;
        $migrate = function () use (&$calls): array {
            $calls++;
            return [true, 'ok'];
        };
        $paths = [$this->migDir('a', ['CreateA'])];
        (new MigrationRunner($paths, ['name' => 'x'], $this->stateDir(), null, $migrate))->ensureMigrated();
        (new MigrationRunner($paths, ['name' => 'x'], $this->stateDir(), null, $migrate))->ensureMigrated();

        self::assertSame(1, $calls, 'the second run must short-circuit on the marker');
    }

    public function testEmptyPathsIsNoop(): void
    {
        $calls = 0;
        (new MigrationRunner([], ['name' => 'x'], $this->stateDir(), null, function () use (&$calls): array {
            $calls++;
            return [true, 'ok'];
        }))->ensureMigrated();

        self::assertSame(0, $calls);
    }

    public function testDuplicateClassNameAbortsWithoutMigrating(): void
    {
        $calls = 0;
        $runner = new MigrationRunner(
            [$this->migDir('a', ['CreateShared']), $this->migDir('b', ['CreateShared'])],
            ['name' => 'x'],
            $this->stateDir(),
            null,
            function () use (&$calls): array {
                $calls++;
                return [true, 'ok'];
            },
        );
        $runner->ensureMigrated();

        self::assertSame(0, $calls, 'a class-name collision must abort before Phinx includes the files');
        self::assertEmpty(glob($this->stateDir() . '/.migrated-*') ?: []);
    }

    public function testFailureIsNotMarkedAndRetries(): void
    {
        $calls = 0;
        $migrate = function () use (&$calls): array {
            $calls++;
            return [false, 'boom'];
        };
        $paths = [$this->migDir('a', ['CreateA'])];
        (new MigrationRunner($paths, ['name' => 'x'], $this->stateDir(), null, $migrate))->ensureMigrated();
        (new MigrationRunner($paths, ['name' => 'x'], $this->stateDir(), null, $migrate))->ensureMigrated();

        self::assertSame(2, $calls, 'a failed migrate must not latch a marker — it retries');
        self::assertEmpty(glob($this->stateDir() . '/.migrated-*') ?: []);
    }

    private static function rmrf(string $path): void
    {
        if (is_dir($path)) {
            foreach ((array) scandir($path) as $e) {
                if ($e !== '.' && $e !== '..') {
                    self::rmrf($path . '/' . $e);
                }
            }
            @rmdir($path);
        } elseif (is_file($path)) {
            @unlink($path);
        }
    }
}
