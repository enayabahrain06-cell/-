<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * php artisan db:backup [--connection=] [--path=]
 *
 * mysql  -> mysqldump  (single-transaction, utf8mb4)   -> .sql
 * pgsql  -> pg_dump    (plain SQL, no owner/privileges) -> .sql
 * sqlite -> file copy of the database file              -> .sqlite
 */
class DbBackupCommand extends Command
{
    protected $signature = 'db:backup
        {--connection= : Connection name (defaults to DB_CONNECTION)}
        {--path= : Directory to write the backup into (defaults to BACKUP_PATH)}';

    protected $description = 'Back up the current database (mysqldump / pg_dump / file copy depending on the driver)';

    public function handle(): int
    {
        $name = $this->option('connection') ?: config('database.default');
        $config = config("database.connections.{$name}");

        if (! $config) {
            $this->error("Unknown connection [{$name}].");

            return self::FAILURE;
        }

        $dir = $this->option('path') ?: base_path(env('BACKUP_PATH', 'storage/app/backups'));
        File::ensureDirectoryExists($dir);

        $stamp = now()->format('Ymd_His');

        try {
            $file = match ($config['driver']) {
                'mysql', 'mariadb' => $this->backupMysql($config, $dir, $stamp),
                'pgsql' => $this->backupPgsql($config, $dir, $stamp),
                'sqlite' => $this->backupSqlite($config, $dir, $stamp),
                default => throw new \RuntimeException("Driver [{$config['driver']}] not supported."),
            };
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Backup written: {$file}");
        $this->line('Size: '.number_format(filesize($file) / 1024, 1).' KB');

        return self::SUCCESS;
    }

    private function backupMysql(array $c, string $dir, string $stamp): string
    {
        $file = "{$dir}/{$c['database']}_{$stamp}.sql";
        $bin = env('MYSQLDUMP_PATH', 'mysqldump');

        $cmd = [
            $bin,
            '--host='.$c['host'], '--port='.$c['port'], '--user='.$c['username'],
            '--single-transaction', '--routines', '--triggers', '--default-character-set=utf8mb4',
            '--result-file='.$file,
            $c['database'],
        ];

        $this->runProcess($cmd, ['MYSQL_PWD' => $c['password'] ?? '']);

        return $file;
    }

    private function backupPgsql(array $c, string $dir, string $stamp): string
    {
        $file = "{$dir}/{$c['database']}_{$stamp}.sql";
        $bin = env('PG_DUMP_PATH', 'pg_dump');

        $cmd = [
            $bin,
            '--host='.$c['host'], '--port='.$c['port'], '--username='.$c['username'],
            '--format=plain', '--no-owner', '--no-privileges', '--encoding=UTF8',
            '--file='.$file,
            $c['database'],
        ];

        $this->runProcess($cmd, ['PGPASSWORD' => $c['password'] ?? '']);

        return $file;
    }

    private function backupSqlite(array $c, string $dir, string $stamp): string
    {
        $source = $c['database'];

        if ($source === ':memory:' || ! is_file($source)) {
            throw new \RuntimeException("SQLite file not found: {$source}");
        }

        $file = "{$dir}/".pathinfo($source, PATHINFO_FILENAME)."_{$stamp}.sqlite";

        // Checkpoint the WAL so the copy is self-contained, then copy.
        \DB::connection('sqlite')->statement('PRAGMA wal_checkpoint(TRUNCATE)');
        File::copy($source, $file);

        return $file;
    }

    private function runProcess(array $cmd, array $env): void
    {
        $process = new Process($cmd, base_path(), $env + ['PATH' => getenv('PATH')], null, 3600);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException(trim($process->getErrorOutput()) ?: 'Backup process failed.');
        }
    }
}
