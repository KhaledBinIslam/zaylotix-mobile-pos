<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Ifsnop\Mysqldump\Mysqldump;

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
            // Deliberately NOT shelling out to the real `mysqldump` binary —
            // this shop's actual hosting (shared, no SSH exec access) has
            // exec()/shell_exec()/proc_open() all disabled for security, the
            // same reason storage:link needed a manual workaround at deploy
            // time (see Filesystem::link()). Every nightly run of this
            // command failed outright until this was caught live via the
            // admin System Health log — a real backup had never once
            // succeeded on production. ifsnop/mysqldump-php reimplements
            // mysqldump in pure PHP over the existing PDO connection, so it
            // works identically whether exec() is available or not.
            $tmpPath = tempnam(sys_get_temp_dir(), 'zaylotix-backup-').'.sql';

            $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s', $config['host'], $config['port'], $config['database']);

            try {
                $dump = new Mysqldump($dsn, $config['username'], $config['password'] ?? '');
                $dump->start($tmpPath);
            } catch (\Throwable $e) {
                $this->error('Database dump failed: '.$e->getMessage());
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
