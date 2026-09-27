<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

/**
 * php artisan db:restore {file} [--connection=] [--force]
 *
 * mysql  -> mysql < file.sql
 * pgsql  -> psql  -f file.sql
 * sqlite -> replaces the database file with the backup copy
 */
class DbRestoreCommand extends Command
{
    protected $signature = 'db:restore
        {file : Backup file produced by db:backup}
        {--connection= : Connection name (defaults to DB_CONNECTION)}
        {--force : Skip the confirmation prompt}';

    protected $description = 'Restore a backup produced by db:backup into the current database';

    public function handle(): int
    {
        $file = $this->argument('file');
        if (! is_file($file)) {
            $this->error("File not found: {$file}");

            return self::FAILURE;
        }

        $name = $this->option('connection') ?: config('database.default');
        $config = config("database.connections.{$name}");

        if (! $config) {
            $this->error("Unknown connection [{$name}].");

            return self::FAILURE;
        }

        if (! $this->option('force') && ! $this->confirm("This will OVERWRITE database [{$config['database']}] on [{$name}]. Continue?")) {
            return self::INVALID;
        }

        try {
            match ($config['driver']) {
                'mysql', 'mariadb' => $this->restoreMysql($config, $file),
                'pgsql' => $this->restorePgsql($config, $file),
                'sqlite' => $this->restoreSqlite($config, $file),
                default => throw new \RuntimeException("Driver [{$config['driver']}] not supported."),
            };
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info('Restore completed.');

        return self::SUCCESS;
    }

    private function restoreMysql(array $c, string $file): void
    {
        $bin = env('MYSQL_PATH', 'mysql');
        $cmd = [$bin, '--host='.$c['host'], '--port='.$c['port'], '--user='.$c['username'], '--default-character-set=utf8mb4', $c['database']];

        $this->runProcess($cmd, ['MYSQL_PWD' => $c['password'] ?? ''], $file);
    }

    private function restorePgsql(array $c, string $file): void
    {
        $bin = env('PSQL_PATH', 'psql');
        $cmd = [$bin, '--host='.$c['host'], '--port='.$c['port'], '--username='.$c['username'], '--dbname='.$c['database'], '--set=ON_ERROR_STOP=1', '--file='.$file];

        $this->runProcess($cmd, ['PGPASSWORD' => $c['password'] ?? '']);
    }

    private function restoreSqlite(array $c, string $file): void
    {
        $target = $c['database'];
        \DB::disconnect();

        foreach (['-wal', '-shm'] as $suffix) {
            if (is_file($target.$suffix)) {
                File::delete($target.$suffix);
            }
        }

        File::copy($file, $target);
    }

    private function runProcess(array $cmd, array $env, ?string $stdinFile = null): void
    {
        $process = new Process($cmd, base_path(), $env + ['PATH' => getenv('PATH')], $stdinFile ? fopen($stdinFile, 'r') : null, 3600);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new \RuntimeException(trim($process->getErrorOutput()) ?: 'Restore process failed.');
        }
    }
}
