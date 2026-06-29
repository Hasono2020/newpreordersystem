<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

class BackupDatabase extends Command
{
    protected $signature = 'backup:database {--keep=7 : Number of days of backups to retain}';
    protected $description = 'Dump the MySQL database to storage/app/backups and prune backups older than --keep days';

    public function handle(): int
    {
        $keepDays = (int) $this->option('keep') ?: 7;

        $dir = storage_path('app/backups');
        if (!File::isDirectory($dir)) {
            File::makeDirectory($dir, 0755, true);
        }

        $db = config('database.connections.' . config('database.default'));
        $timestamp = now()->format('Y-m-d-His');
        $filename  = "backup-{$timestamp}.sql";
        $path      = $dir . DIRECTORY_SEPARATOR . $filename;

        $this->info("Backing up database '{$db['database']}' …");

        // Build mysqldump command. --single-transaction gives a consistent snapshot
        // without locking tables (safe to run while staff are using the app).
        $command = [
            'mysqldump',
            '--host=' . ($db['host'] ?? '127.0.0.1'),
            '--port=' . ($db['port'] ?? '3306'),
            '--user=' . $db['username'],
            '--password=' . $db['password'],
            '--single-transaction',
            '--quick',
            '--skip-lock-tables',
            $db['database'],
        ];

        $process = new Process($command);
        $process->setTimeout(600);

        // Stream mysqldump's stdout straight into the backup file
        $handle = fopen($path, 'w');
        try {
            $process->run(function ($type, $buffer) use ($handle) {
                if ($type === Process::OUT) {
                    fwrite($handle, $buffer);
                }
            });
        } finally {
            fclose($handle);
        }

        if (!$process->isSuccessful()) {
            // Remove the partial/empty file so a failed backup isn't mistaken for a good one
            if (File::exists($path)) File::delete($path);
            $this->error('Backup failed: ' . trim($process->getErrorOutput()));
            \Log::error('Database backup failed', ['error' => $process->getErrorOutput()]);
            return self::FAILURE;
        }

        $sizeMb = round(File::size($path) / 1048576, 2);
        $this->info("Backup created: {$filename} ({$sizeMb} MB)");

        // ── Prune old backups ────────────────────────────────────────
        $cutoff  = now()->subDays($keepDays);
        $deleted = 0;
        foreach (File::files($dir) as $file) {
            if (str_starts_with($file->getFilename(), 'backup-')
                && $file->getExtension() === 'sql') {
                if (\Carbon\Carbon::createFromTimestamp($file->getMTime())->lt($cutoff)) {
                    File::delete($file->getPathname());
                    $deleted++;
                }
            }
        }
        if ($deleted > 0) {
            $this->info("Pruned {$deleted} backup(s) older than {$keepDays} days.");
        }

        return self::SUCCESS;
    }
}