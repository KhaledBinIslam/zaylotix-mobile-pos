<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Support\ShopResourceUsage;
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
        // a genuinely empty log (freshly cleared, or a brand new install
        // that's never logged anything yet) crashed this page outright —
        // fread()'s $length must be > 0, and filesize() on an empty file is
        // exactly 0, so this simply skips the tail-read for that case
        // instead of ever calling fread() with nothing to read.
        if (is_file($logPath) && filesize($logPath) > 0) {
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

        // What the owners actually ran into: server errors a user saw (with the
        // reference code printed on their screen), JS errors reported by their
        // phones, and sessions the app ended. These are WARNING-level lines the
        // ERROR list above never showed — "scanner does nothing" and "logged out
        // again and again" left no trace there at all.
        $incidents = [];
        if (isset($tail)) {
            preg_match_all('/^\[(?<time>[\d\-: ]+)\]\s+\w+\.(?<level>ERROR|WARNING):\s*(?<message>\[(?:incident [A-Z0-9]{6}|client-js-error|forced-logout)[^\n]*)$/m', $tail, $im, PREG_SET_ORDER);
            $incidents = collect($im)->reverse()->take(40)->map(fn ($m) => [
                'time' => $m['time'],
                'kind' => str_contains($m['message'], '[incident') ? 'server' : (str_contains($m['message'], 'client-js-error') ? 'phone' : 'logout'),
                'ref' => preg_match('/\[incident ([A-Z0-9]{6})\]/', $m['message'], $r) ? $r[1] : null,
                'message' => \Illuminate\Support\Str::limit($m['message'], 400),
            ])->values();
        }

        $diskFree = @disk_free_space(storage_path());
        $diskTotal = @disk_total_space(storage_path());

        return Inertia::render('Admin/SystemHealth/Index', [
            'db' => ['ok' => $dbOk, 'error' => $dbError],
            'recentErrors' => $recentErrors,
            'incidents' => $incidents,
            // disk_free_space()/disk_total_space() report the WHOLE shared
            // host filesystem's stats on Hostinger's shared/Cloud hosting —
            // every other customer's usage on this physical node included,
            // not just this app's. Khaled live, confused by this exact
            // number: "ami 20gb space khai pechi?" over a 70%-of-21TB
            // figure that was never his own usage at all. Kept (clearly
            // labeled as the shared host) alongside the real, this-app-only
            // figure from ShopResourceUsage so neither replaces the other.
            'disk' => [
                'free_gb' => $diskFree ? round($diskFree / 1073741824, 1) : null,
                'total_gb' => $diskTotal ? round($diskTotal / 1073741824, 1) : null,
                'used_percent' => ($diskFree && $diskTotal) ? round((1 - $diskFree / $diskTotal) * 100, 1) : null,
            ],
            'appUsage' => ShopResourceUsage::appTotals(),
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'queue_connection' => config('queue.default'),
            'app_env' => config('app.env'),
            'app_debug' => config('app.debug'),
        ]);
    }
}
