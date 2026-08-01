<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

class BackupDatabase extends Command
{
    protected $signature = 'zaylotix:backup';

    protected $description = 'Dump the database to storage/app/backups (or the configured backup disk).';

    public function handle(): int
    {
        $connection = config('database.default');
        $config = config("database.connections.{$connection}");
        $filename = 'backups/zaylotix-pos-'.now()->format('Y-m-d_His').'.sql';
        $disk = config('backup.disk', 'local');

        if ($connection === 'sqlite') {
            // simplest, always-available path for the default local setup
            $path = $config['database'];
            if ($path && $path !== ':memory:' && File::exists($path)) {
                Storage::disk($disk)->put(
                    str_replace('.sql', '.sqlite', $filename),
                    File::get($path)
                );
                $this->info('SQLite database copied to '.$disk.':'.str_replace('.sql', '.sqlite', $filename));

                return self::SUCCESS;
            }

            $this->error('SQLite database file not found.');

            return self::FAILURE;
        }

        if ($connection === 'mysql') {
            // mysqldump needs a real filesystem path to write to; dump to a
            // scratch temp file, then always hand it to the Storage disk
            // abstraction (even for 'local') so the final location is
            // exactly where config/backup.php says it is — no special case.
            $tmpPath = tempnam(sys_get_temp_dir(), 'zaylotix-backup-').'.sql';

            $command = sprintf(
                'mysqldump -h%s -P%s -u%s %s %s > %s',
                escapeshellarg($config['host']),
                escapeshellarg((string) $config['port']),
                escapeshellarg($config['username']),
                $config['password'] ? '-p'.escapeshellarg($config['password']) : '',
                escapeshellarg($config['database']),
                escapeshellarg($tmpPath)
            );

            exec($command, $output, $exitCode);

            if ($exitCode !== 0) {
                $this->error('mysqldump failed. Is it installed and on PATH?');
                File::delete($tmpPath);

                return self::FAILURE;
            }

            Storage::disk($disk)->put($filename, File::get($tmpPath));
            File::delete($tmpPath);

            $this->info("Database dumped to {$disk}:{$filename}");

            return self::SUCCESS;
        }

        $this->error("No backup strategy for connection '{$connection}'.");

        return self::FAILURE;
    }
}
