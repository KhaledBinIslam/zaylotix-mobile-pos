<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

/**
 * In-app health snapshot — not a substitute for a real third-party APM
 * (Sentry/UptimeRobot etc, which need an external account this project
 * doesn't have), but gives an admin a same-day answer to "is the DB up,
 * is disk filling, has anything actually errored recently" without SSH-ing
 * into the server and grepping the log by hand.
 */
class SystemHealthController extends Controller
{
    public function index()
    {
        $dbOk = true;
        $dbError = null;
        try {
            DB::connection()->getPdo();
        } catch (\Throwable $e) {
            $dbOk = false;
            $dbError = $e->getMessage();
        }

        $logPath = storage_path('logs/laravel.log');
        $recentErrors = [];
        if (is_file($logPath)) {
            // tail the last ~500KB rather than reading a potentially huge
            // file whole — recent entries are what an admin actually wants
            $size = filesize($logPath);
            $chunk = min($size, 500000);
            $handle = fopen($logPath, 'r');
            fseek($handle, -$chunk, SEEK_END);
            $tail = fread($handle, $chunk);
            fclose($handle);

            preg_match_all('/^\[(?<time>[\d\-: ]+)\].*?(?<level>ERROR|CRITICAL|EMERGENCY|ALERT):\s*(?<message>.+)$/m', $tail, $matches, PREG_SET_ORDER);
            $recentErrors = collect($matches)->reverse()->take(20)->map(fn ($m) => [
                'time' => $m['time'],
                'level' => $m['level'],
                'message' => \Illuminate\Support\Str::limit($m['message'], 300),
            ])->values();
        }

        $diskFree = @disk_free_space(storage_path());
        $diskTotal = @disk_total_space(storage_path());

        return Inertia::render('Admin/SystemHealth/Index', [
            'db' => ['ok' => $dbOk, 'error' => $dbError],
            'recentErrors' => $recentErrors,
            'disk' => [
                'free_gb' => $diskFree ? round($diskFree / 1073741824, 1) : null,
                'total_gb' => $diskTotal ? round($diskTotal / 1073741824, 1) : null,
                'used_percent' => ($diskFree && $diskTotal) ? round((1 - $diskFree / $diskTotal) * 100, 1) : null,
            ],
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'queue_connection' => config('queue.default'),
            'app_env' => config('app.env'),
            'app_debug' => config('app.debug'),
        ]);
    }
}
