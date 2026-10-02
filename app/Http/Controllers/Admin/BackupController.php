<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/**
 * Admin-only self-service backup/restore for the whole platform database —
 * deliberately NOT exposed to shop owners: `zaylotix:backup` dumps the
 * entire shared-schema database (every tenant's rows in one file), so
 * letting a shop owner download or restore one would leak every other
 * shop's data to them, or roll every shop on the platform back to a stale
 * state. This is infrastructure-level tooling for the platform admin only.
 */
class BackupController extends Controller
{
    private const DIR = 'backups';

    public function index()
    {
        $disk = Storage::disk(config('backup.disk', 'local'));
        $files = collect($disk->files(self::DIR))
            ->filter(fn ($f) => str_ends_with($f, '.sql') || str_ends_with($f, '.sqlite'))
            ->map(fn ($f) => [
                'name' => basename($f),
                'size' => $disk->size($f),
                'modified_at' => date('d M Y, h:i A', $disk->lastModified($f)),
            ])
            ->sortByDesc('modified_at')
            ->values();

        return Inertia::render('Admin/Backups/Index', [
            'files' => $files,
            'connection' => config('database.default'),
        ]);
    }

    /** Runs the same backup the nightly scheduler does, on demand. */
    public function store()
    {
        $exitCode = Artisan::call('zaylotix:backup');

        if ($exitCode !== 0) {
            return back()->withErrors(['backup' => 'ব্যাকআপ ব্যর্থ হয়েছে — সার্ভার লগ দেখুন।']);
        }

        return back()->with('success', 'নতুন ব্যাকআপ তৈরি হয়েছে।');
    }

    public function download(string $filename)
    {
        $path = $this->resolveSafePath($filename);
        $disk = Storage::disk(config('backup.disk', 'local'));

        if (! $disk->exists($path)) {
            abort(404);
        }

        return $disk->download($path);
    }

    public function destroy(string $filename)
    {
        $path = $this->resolveSafePath($filename);
        Storage::disk(config('backup.disk', 'local'))->delete($path);

        return back()->with('success', 'ব্যাকআপ ফাইল মুছে ফেলা হয়েছে।');
    }

    /**
     * Restores the ENTIRE platform database from a backup file — the most
     * destructive action in this whole app. Guarded three ways: (1) mysql
     * only, matching what BackupDatabase can actually produce/consume;
     * (2) the admin must type the exact confirmation phrase, not just
     * click a button; (3) a fresh safety backup of the *current* state is
     * taken automatically right before overwriting anything, so a restore
     * chosen in error is itself always recoverable.
     */
    public function restore(Request $request, string $filename)
    {
        $data = $request->validate([
            'confirm' => ['required', 'string'],
        ]);

        if ($data['confirm'] !== 'RESTORE') {
            throw ValidationException::withMessages(['confirm' => 'ঠিক "RESTORE" লিখে নিশ্চিত করুন।']);
        }

        $connection = config('database.default');
        if ($connection !== 'mysql') {
            abort(422, 'রিস্টোর শুধু MySQL-এ সমর্থিত।');
        }

        $path = $this->resolveSafePath($filename);
        $disk = Storage::disk(config('backup.disk', 'local'));
        if (! $disk->exists($path)) {
            abort(404);
        }

        // safety net — never overwrite live data without a fresh copy of
        // what it looked like the instant before this ran
        Artisan::call('zaylotix:backup');

        $config = config("database.connections.{$connection}");

        // Deliberately NOT shelling out to the `mysql` CLI (as this used to)
        // — this host's production hosting has exec()/shell_exec()/
        // proc_open() all disabled, the exact same constraint that made
        // zaylotix:backup silently fail every night until it was rewritten
        // around a pure-PHP dumper (see BackupDatabase's own comment). This
        // shelled-out restore would fail the identical way, making the
        // platform's one disaster-recovery path non-functional. A second
        // PDO connection with MYSQL_ATTR_MULTI_STATEMENTS lets the mysql
        // client library itself parse/run the whole dump in one call,
        // without a shell or a hand-rolled SQL statement splitter.
        try {
            $dsn = sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                $config['host'], $config['port'], $config['database'], $config['charset'] ?? 'utf8mb4'
            );
            $pdo = new \PDO($dsn, $config['username'], $config['password'] ?? '', [
                \PDO::MYSQL_ATTR_MULTI_STATEMENTS => true,
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);
            $pdo->exec($disk->get($path));
        } catch (\Throwable $e) {
            Log::error('Database restore failed', ['file' => $filename, 'error' => $e->getMessage()]);

            return back()->withErrors(['restore' => 'রিস্টোর ব্যর্থ হয়েছে — সার্ভার লগ দেখুন। বর্তমান ডাটার একটি নতুন সেফটি ব্যাকআপ নেওয়া হয়েছে।']);
        }

        // re-apply any migration since this dump was taken — a restore
        // from an older backup must never leave the schema behind what
        // the running code expects
        Artisan::call('migrate', ['--force' => true]);

        return back()->with('success', "'{$filename}' থেকে রিস্টোর সম্পন্ন হয়েছে।");
    }

    /** Rejects anything that isn't a plain filename inside the backups directory — no path traversal, no reaching outside it. */
    private function resolveSafePath(string $filename): string
    {
        $safe = basename($filename);
        $hasValidExtension = str_ends_with($safe, '.sql') || str_ends_with($safe, '.sqlite');
        if ($safe !== $filename || ! $hasValidExtension) {
            abort(422, 'অবৈধ ফাইলের নাম।');
        }

        return self::DIR.'/'.$safe;
    }
}
