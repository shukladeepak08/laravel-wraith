<?php

declare(strict_types=1);

namespace SdPayHub\Wraith\Analyzers\Database;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use SdPayHub\Wraith\Analyzers\AbstractAnalyzer;
use SdPayHub\Wraith\Contracts\Analyzer;
use SdPayHub\Wraith\Results\AnalysisResult;
use SdPayHub\Wraith\Support\Category;
use SdPayHub\Wraith\Support\Severity;

/**
 * Schema introspection — not live query monitoring.
 * No EXPLAIN / query plans / lock contention (out of scope).
 */
final class DatabaseSchemaAnalyzer extends AbstractAnalyzer implements Analyzer
{
    public function category(): string
    {
        return Category::DATABASE;
    }

    public function name(): string
    {
        return 'Database Schema';
    }

    public function supports(): bool
    {
        try {
            DB::connection()->getPdo();

            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    public function analyze(): AnalysisResult
    {
        $findings = [];
        $findings = array_merge($findings, $this->checkPendingMigrations());
        $findings = array_merge($findings, $this->checkMigrationDownMethods());
        $findings = array_merge($findings, $this->checkSchemaBasics());
        $findings = array_merge($findings, $this->checkSecondaryConnections());

        return $this->result($this->name(), $this->category(), $findings);
    }

    /**
     * @return array<int, \SdPayHub\Wraith\Results\Finding>
     */
    private function checkPendingMigrations(): array
    {
        try {
            $migrator = app('migrator');
            $paths = [database_path('migrations')];
            $files = $migrator->getMigrationFiles($paths);
            $ran = $migrator->getRepository()->getRan();
            $pending = array_diff(array_keys($files), $ran);

            if ($pending === []) {
                return [];
            }

            return [
                $this->finding(
                    Severity::HIGH,
                    $this->category(),
                    'database.pending_migrations',
                    sprintf('%d pending migration(s) detected.', count($pending)),
                    'Schema drift between code and database causes runtime errors.',
                    'Run `php artisan migrate` in a controlled deploy.',
                    'https://laravel.com/docs/migrations',
                    false,
                    ['pending' => array_values($pending)]
                ),
            ];
        } catch (\Throwable $e) {
            return [
                $this->finding(
                    Severity::INFO,
                    $this->category(),
                    'database.migration_check_failed',
                    'Could not inspect migration status: '.$e->getMessage(),
                    'Migration status is part of schema reliability.',
                    'Ensure the migrations table exists and the DB connection works.'
                ),
            ];
        }
    }

    /**
     * @return array<int, \SdPayHub\Wraith\Results\Finding>
     */
    private function checkMigrationDownMethods(): array
    {
        $path = database_path('migrations');

        if (! is_dir($path)) {
            return [];
        }

        $findings = [];
        $files = glob($path.'/*.php') ?: [];

        foreach ($files as $file) {
            $contents = (string) file_get_contents($file);

            // Anonymous class migrations (L8+) and classic class migrations.
            if (! preg_match('/function\s+down\s*\(/', $contents)) {
                $findings[] = $this->finding(
                    Severity::MEDIUM,
                    $this->category(),
                    'database.migration_missing_down',
                    sprintf('Migration %s has no down() method.', basename($file)),
                    'Irreversible migrations make rollbacks impossible during failed deploys.',
                    'Add a down() method that reverses the up() changes.',
                    'https://laravel.com/docs/migrations#rolling-back-migrations',
                    false,
                    ['file' => $file]
                );
            } elseif (preg_match('/function\s+down\s*\([^)]*\)\s*:\s*void\s*\{[\s\n]*\}/s', $contents)
                || preg_match('/function\s+down\s*\([^)]*\)\s*\{[\s\n]*\/\/[^\n]*\n[\s\n]*\}/s', $contents)
                || preg_match('/function\s+down\s*\([^)]*\)\s*\{[\s\n]*\}/s', $contents)) {
                $findings[] = $this->finding(
                    Severity::LOW,
                    $this->category(),
                    'database.migration_empty_down',
                    sprintf('Migration %s has an empty down() method.', basename($file)),
                    'Empty down() methods block safe rollbacks.',
                    'Implement down() or document why rollback is intentionally unsupported.',
                    null,
                    false,
                    ['file' => $file]
                );
            }
        }

        return $findings;
    }

    /**
     * @return array<int, \SdPayHub\Wraith\Results\Finding>
     */
    private function checkSchemaBasics(): array
    {
        $findings = [];

        try {
            $connection = Schema::getConnection();
            $database = $connection->getDatabaseName();
            $driver = $connection->getDriverName();

            if (! in_array($driver, ['mysql', 'pgsql', 'sqlite', 'sqlsrv'], true)) {
                return [];
            }

            $tables = $this->listTables($driver, $database);

            foreach ($tables as $table) {
                if (in_array($table, ['migrations', 'failed_jobs', 'password_resets', 'password_reset_tokens', 'personal_access_tokens', 'jobs', 'job_batches', 'sessions', 'cache', 'cache_locks'], true)) {
                    continue;
                }

                if (! $this->hasPrimaryKey($driver, $database, $table)) {
                    $findings[] = $this->finding(
                        Severity::HIGH,
                        $this->category(),
                        'database.missing_primary_key',
                        sprintf('Table `%s` has no primary key.', $table),
                        'Tables without primary keys complicate replication, ORM usage, and updates.',
                        sprintf('Add a primary key to `%s`.', $table),
                        null,
                        false,
                        ['table' => $table]
                    );
                }

                $findings = array_merge($findings, $this->checkForeignKeyIndexes($driver, $database, $table));
                $findings = array_merge($findings, $this->checkDuplicateIndexes($driver, $database, $table));
            }

            $findings = array_merge($findings, $this->checkCharsetCollation($driver, $database, $tables));
        } catch (\Throwable $e) {
            $findings[] = $this->finding(
                Severity::INFO,
                $this->category(),
                'database.schema_introspection_failed',
                'Schema introspection failed: '.$e->getMessage(),
                'Deep schema checks require a working database connection and privileges.',
                'Verify DB credentials and that the database user can read information_schema / catalogs.'
            );
        }

        return $findings;
    }

    /**
     * @return array<int, string>
     */
    private function listTables(string $driver, string $database): array
    {
        if ($driver === 'mysql') {
            $rows = DB::select('SHOW TABLES');
            $key = 'Tables_in_'.$database;

            return array_map(static function ($row) use ($key) {
                $arr = (array) $row;

                return (string) ($arr[$key] ?? reset($arr));
            }, $rows);
        }

        if ($driver === 'pgsql') {
            $rows = DB::select("SELECT tablename FROM pg_catalog.pg_tables WHERE schemaname = 'public'");

            return array_map(static function ($row) {
                return (string) $row->tablename;
            }, $rows);
        }

        if ($driver === 'sqlite') {
            $rows = DB::select("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'");

            return array_map(static function ($row) {
                return (string) $row->name;
            }, $rows);
        }

        return [];
    }

    private function hasPrimaryKey(string $driver, string $database, string $table): bool
    {
        if ($driver === 'mysql') {
            $rows = DB::select(
                'SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND CONSTRAINT_TYPE = ?',
                [$database, $table, 'PRIMARY KEY']
            );

            return count($rows) > 0;
        }

        if ($driver === 'sqlite') {
            $rows = DB::select('PRAGMA table_info('.$this->quoteIdent($table).')');

            foreach ($rows as $row) {
                if ((int) $row->pk > 0) {
                    return true;
                }
            }

            return false;
        }

        if ($driver === 'pgsql') {
            $rows = DB::select(
                "SELECT a.attname FROM pg_index i JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY(i.indkey) WHERE i.indrelid = ?::regclass AND i.indisprimary",
                [$table]
            );

            return count($rows) > 0;
        }

        return true;
    }

    /**
     * @return array<int, \SdPayHub\Wraith\Results\Finding>
     */
    private function checkForeignKeyIndexes(string $driver, string $database, string $table): array
    {
        if ($driver !== 'mysql') {
            return [];
        }

        $fks = DB::select(
            'SELECT COLUMN_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL',
            [$database, $table]
        );

        $indexed = DB::select(
            'SELECT COLUMN_NAME FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [$database, $table]
        );

        $indexedCols = [];

        foreach ($indexed as $row) {
            $indexedCols[(string) $row->COLUMN_NAME] = true;
        }

        $findings = [];

        foreach ($fks as $fk) {
            $col = (string) $fk->COLUMN_NAME;

            if (! isset($indexedCols[$col])) {
                $findings[] = $this->finding(
                    Severity::MEDIUM,
                    $this->category(),
                    'database.fk_missing_index',
                    sprintf('Foreign key column `%s`.`%s` has no index.', $table, $col),
                    'Unindexed FK columns slow joins and cascade operations.',
                    sprintf('Add an index on `%s`.`%s`.', $table, $col),
                    null,
                    false,
                    ['table' => $table, 'column' => $col]
                );
            }
        }

        // Also flag common *_id columns without FK constraints.
        $columns = DB::select(
            'SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? AND COLUMN_NAME LIKE ?',
            [$database, $table, '%_id']
        );

        $fkCols = [];

        foreach ($fks as $fk) {
            $fkCols[(string) $fk->COLUMN_NAME] = true;
        }

        foreach ($columns as $column) {
            $col = (string) $column->COLUMN_NAME;

            if ($col === 'id' || isset($fkCols[$col])) {
                continue;
            }

            $findings[] = $this->finding(
                Severity::LOW,
                $this->category(),
                'database.missing_foreign_key',
                sprintf('Column `%s`.`%s` looks like a foreign key but has no FK constraint.', $table, $col),
                'Missing FK constraints allow orphan rows and hide relationship bugs.',
                sprintf('Add a foreign key constraint on `%s`.`%s` if this references another table.', $table, $col),
                null,
                false,
                ['table' => $table, 'column' => $col]
            );
        }

        return $findings;
    }

    /**
     * @return array<int, \SdPayHub\Wraith\Results\Finding>
     */
    private function checkDuplicateIndexes(string $driver, string $database, string $table): array
    {
        if ($driver !== 'mysql') {
            return [];
        }

        $rows = DB::select(
            'SELECT INDEX_NAME, GROUP_CONCAT(COLUMN_NAME ORDER BY SEQ_IN_INDEX) AS cols FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? GROUP BY INDEX_NAME',
            [$database, $table]
        );

        $byCols = [];
        $findings = [];

        foreach ($rows as $row) {
            $cols = (string) $row->cols;
            $name = (string) $row->INDEX_NAME;

            if (isset($byCols[$cols])) {
                $findings[] = $this->finding(
                    Severity::LOW,
                    $this->category(),
                    'database.duplicate_index',
                    sprintf('Duplicate indexes on `%s`: %s and %s cover (%s).', $table, $byCols[$cols], $name, $cols),
                    'Duplicate indexes waste write performance and disk.',
                    'Drop one of the redundant indexes.',
                    null,
                    false,
                    ['table' => $table, 'columns' => $cols]
                );
            } else {
                $byCols[$cols] = $name;
            }
        }

        return $findings;
    }

    /**
     * @param array<int, string> $tables
     * @return array<int, \SdPayHub\Wraith\Results\Finding>
     */
    private function checkCharsetCollation(string $driver, string $database, array $tables): array
    {
        if ($driver !== 'mysql' || $tables === []) {
            return [];
        }

        $rows = DB::select(
            'SELECT TABLE_NAME, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = ?',
            [$database, 'BASE TABLE']
        );

        $collations = [];

        foreach ($rows as $row) {
            $collations[(string) $row->TABLE_NAME] = (string) $row->TABLE_COLLATION;
        }

        $unique = array_unique(array_values($collations));

        if (count($unique) <= 1) {
            return [];
        }

        return [
            $this->finding(
                Severity::MEDIUM,
                $this->category(),
                'database.collation_mismatch',
                'Tables use inconsistent collations: '.implode(', ', $unique).'.',
                'Mixed collations cause join failures and unexpected sort order.',
                'Normalize table collations (e.g. utf8mb4_unicode_ci) across the schema.',
                null,
                false,
                ['collations' => $unique]
            ),
        ];
    }

    /**
     * @return array<int, \SdPayHub\Wraith\Results\Finding>
     */
    private function checkSecondaryConnections(): array
    {
        if (! $this->isProduction()) {
            return [];
        }

        $default = (string) config('database.default', 'mysql');
        $connections = (array) config('database.connections', []);
        $findings = [];

        foreach ($connections as $name => $config) {
            if (! is_array($config) || (string) $name === $default) {
                continue;
            }

            $driver = isset($config['driver']) ? (string) $config['driver'] : '';

            if (! in_array($driver, ['mysql', 'pgsql', 'sqlsrv'], true)) {
                continue;
            }

            $host = isset($config['host']) ? (string) $config['host'] : '';
            $password = array_key_exists('password', $config) ? $config['password'] : null;
            $username = isset($config['username']) ? (string) $config['username'] : '';

            if (in_array($host, ['127.0.0.1', 'localhost'], true)) {
                $findings[] = $this->finding(
                    Severity::MEDIUM,
                    $this->category(),
                    'database.secondary_localhost',
                    sprintf('Secondary DB connection `%s` points at %s in production.', $name, $host),
                    'Localhost secondary connections often indicate unfinished multi-DB production config.',
                    sprintf('Point `%s` at the real production host or remove unused connections.', $name),
                    null,
                    false,
                    ['connection' => $name, 'host' => $host]
                );
            }

            if ($password === null || $password === '') {
                $findings[] = $this->finding(
                    Severity::HIGH,
                    $this->category(),
                    'database.secondary_empty_password',
                    sprintf('Secondary DB connection `%s` has an empty password in production.', $name),
                    'Empty DB passwords in production are a common credential leak / default leftover.',
                    sprintf('Set a strong password for connection `%s` (user: %s).', $name, $username !== '' ? $username : 'unknown'),
                    null,
                    false,
                    ['connection' => $name]
                );
            }
        }

        return $findings;
    }

    private function quoteIdent(string $name): string
    {
        return '"'.str_replace('"', '""', $name).'"';
    }
}
