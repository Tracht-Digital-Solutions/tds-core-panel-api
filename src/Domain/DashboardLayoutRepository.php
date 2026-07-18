<?php
declare(strict_types=1);

namespace Tds\CorePanelApi\Domain;

use PDO;

/**
 * Per-user dashboard layout store: which widgets a user shows and in what order.
 * A base-service table (owned by the core, not an extension) keyed by the panel
 * user id (from the JWT `UserContext`) × widget id.
 *
 * The core panel API has no Phinx migration runner yet (deferred with the
 * assemble pipeline), so this base table is self-bootstrapping — an idempotent
 * `CREATE TABLE IF NOT EXISTS` runs once per process. When the core gains an
 * in-process migrator, move this DDL into a migration and drop `ensureSchema()`.
 */
final class DashboardLayoutRepository
{
    private static bool $schemaEnsured = false;

    public function __construct(private readonly PDO $pdo)
    {
    }

    /** The user's saved layout rows (visible + hidden), ordered. @return list<array<string,mixed>> */
    public function get(int $userId): array
    {
        $this->ensureSchema();
        $stmt = $this->pdo->prepare(
            'SELECT widget_id, visible, sort FROM user_dashboard_layout
             WHERE user_id = :u ORDER BY sort, widget_id'
        );
        $stmt->execute([':u' => $userId]);
        return array_map(static fn (array $r): array => [
            'widget_id' => (string) $r['widget_id'],
            'visible' => (int) $r['visible'] === 1,
            'sort' => (int) $r['sort'],
        ], $stmt->fetchAll());
    }

    /**
     * Replace the user's layout with the given ordered rows.
     *
     * @param list<array{widget_id:string,visible:bool,sort:int}> $items
     */
    public function save(int $userId, array $items): void
    {
        $this->ensureSchema();
        $this->pdo->beginTransaction();
        try {
            $del = $this->pdo->prepare('DELETE FROM user_dashboard_layout WHERE user_id = :u');
            $del->execute([':u' => $userId]);

            $ins = $this->pdo->prepare(
                'INSERT INTO user_dashboard_layout (user_id, widget_id, visible, sort)
                 VALUES (:u, :w, :v, :s)'
            );
            foreach ($items as $item) {
                $ins->execute([
                    ':u' => $userId,
                    ':w' => $item['widget_id'],
                    ':v' => $item['visible'] ? 1 : 0,
                    ':s' => $item['sort'],
                ]);
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }

    private function ensureSchema(): void
    {
        if (self::$schemaEnsured) {
            return;
        }
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS user_dashboard_layout (
                id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                user_id BIGINT UNSIGNED NOT NULL,
                widget_id VARCHAR(64) NOT NULL,
                visible TINYINT(1) NOT NULL DEFAULT 1,
                sort INT NOT NULL DEFAULT 100,
                UNIQUE KEY uniq_user_widget (user_id, widget_id),
                KEY idx_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
        self::$schemaEnsured = true;
    }
}
