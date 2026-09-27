<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * php artisan db:transfer [--from=sqlite] [--to=transfer_target] [--chunk=500] [--truncate]
 *
 * Copies every table (in migration/foreign-key order) from one connection to
 * another through the Query Builder in chunks. Used to migrate from SQLite to
 * MySQL/PostgreSQL:
 *
 *   1. Point TRANSFER_DB_* in .env at the new database.
 *   2. php artisan migrate --database=transfer_target --force
 *   3. php artisan db:transfer --from=sqlite --to=transfer_target --truncate
 *   4. Switch DB_CONNECTION in .env to the new driver.
 */
class DbTransferCommand extends Command
{
    protected $signature = 'db:transfer
        {--from= : Source connection (defaults to DB_CONNECTION)}
        {--to=transfer_target : Target connection}
        {--chunk=500 : Rows per insert batch}
        {--truncate : Empty target tables before copying}
        {--only= : Comma-separated list of tables to copy}';

    protected $description = 'Copy all tables from one database connection to another through Eloquent/Query Builder in chunks';

    /** Tables in dependency order (parents first). quran_surahs is reference data filled by its migration, so it is not copied. */
    private const TABLE_ORDER = [
        'users', 'password_reset_tokens',
        'permissions', 'roles', 'model_has_permissions', 'model_has_roles', 'role_has_permissions',
        'personal_access_tokens',
        'settings', 'media', 'audit_logs', 'otp_codes',
        'locations', 'packages', 'teachers', 'students', 'registration_requests',
        'lessons', 'lesson_students', 'lesson_location_overrides',
        'exams', 'location_bookings', 'lesson_sessions', 'attendances',
        'evaluations', 'student_progress', 'student_issues', 'issue_notes',
        'lotteries', 'lottery_teachers', 'lottery_students', 'lottery_results',
        'exam_questions', 'exam_attempts', 'exam_answers', 'certificates',
        'wallets', 'invoices', 'payments', 'invoice_payments', 'wallet_transactions', 'refunds',
        'message_templates', 'message_logs', 'alerts',
        'jobs', 'job_batches', 'failed_jobs',
    ];

    public function handle(): int
    {
        $from = $this->option('from') ?: config('database.default');
        $to = $this->option('to');
        $chunk = max(1, (int) $this->option('chunk'));

        if ($from === $to) {
            $this->error('Source and target connections must differ.');

            return self::FAILURE;
        }

        $only = $this->option('only') ? array_map('trim', explode(',', $this->option('only'))) : null;
        $tables = array_values(array_filter(self::TABLE_ORDER, fn ($t) => (! $only || in_array($t, $only, true)) && Schema::connection($from)->hasTable($t)));

        $missing = array_filter($tables, fn ($t) => ! Schema::connection($to)->hasTable($t));
        if ($missing) {
            $this->error('Target is missing tables: '.implode(', ', $missing).". Run: php artisan migrate --database={$to} --force");

            return self::FAILURE;
        }

        $target = DB::connection($to);
        $this->disableForeignKeys($target);

        try {
            foreach ($tables as $table) {
                $this->copyTable($from, $to, $table, $chunk);
            }
            $this->resetSequences($target, $tables);
        } finally {
            $this->enableForeignKeys($target);
        }

        $this->info('Transfer completed.');

        return self::SUCCESS;
    }

    private function copyTable(string $from, string $to, string $table, int $chunk): void
    {
        $source = DB::connection($from);
        $target = DB::connection($to);

        if ($this->option('truncate')) {
            $target->table($table)->delete();
        }

        $total = $source->table($table)->count();
        $this->line(sprintf('%-32s %6d rows', $table, $total));

        if ($total === 0) {
            return;
        }

        $orderBy = Schema::connection($from)->hasColumn($table, 'id') ? 'id' : null;
        $bar = $this->output->createProgressBar($total);

        $query = $source->table($table);
        if ($orderBy) {
            $query->orderBy($orderBy);
        } else {
            // Tables without an id: order by all columns to keep chunking deterministic.
            foreach (Schema::connection($from)->getColumnListing($table) as $col) {
                $query->orderBy($col);
            }
        }

        $query->chunk($chunk, function ($rows) use ($target, $table, $bar) {
            $batch = array_map(fn ($row) => $this->normalizeRow((array) $row), $rows->all());
            $target->table($table)->insert($batch);
            $bar->advance(count($batch));
        });

        $bar->finish();
        $this->newLine();
    }

    /** Booleans arrive from SQLite as "0"/"1" strings; PostgreSQL wants real booleans. */
    private function normalizeRow(array $row): array
    {
        foreach ($row as $k => $v) {
            if (is_string($v) && ($k === 'is_active' || str_starts_with($k, 'is_') || in_array($k, ['balance_ages', 'keep_siblings', 'balance_levels', 'randomize', 'passed', 'is_correct'], true))) {
                $row[$k] = $v === '' ? null : (bool) (int) $v;
            }
        }

        return $row;
    }

    private function disableForeignKeys($conn): void
    {
        Schema::connection($conn->getName())->disableForeignKeyConstraints();
    }

    private function enableForeignKeys($conn): void
    {
        Schema::connection($conn->getName())->enableForeignKeyConstraints();
    }

    /** PostgreSQL sequences do not follow explicit ids; realign them after the copy. */
    private function resetSequences($conn, array $tables): void
    {
        if ($conn->getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($tables as $table) {
            if (! Schema::connection($conn->getName())->hasColumn($table, 'id')) {
                continue;
            }
            $max = (int) $conn->table($table)->max('id');
            $conn->statement("SELECT setval(pg_get_serial_sequence('{$table}', 'id'), ?, true)", [max($max, 1)]);
        }
    }
}
