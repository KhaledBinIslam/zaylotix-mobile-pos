<?php

/**
 * QA Phase 3 — G. Concurrency worker. ONE process = ONE request, fired at a shared start instant.
 *
 *   php worker.php '<json>'     {"user":9,"method":"POST","uri":"/app/pos/checkout","body":{...},"start":1700000000.5}
 *
 * It boots the real application against the QA database only (hard refusal otherwise), authenticates as the given
 * user, spins until `start`, pushes the request through the real HTTP kernel (middleware, policies, controllers,
 * transactions and row locks all run) and prints one JSON line: status, a body excerpt, timings.
 * Several of these started together are several genuinely parallel MariaDB connections — what PHPUnit on SQLite cannot give.
 */
$job = json_decode($argv[1] ?? '', true);
if (! is_array($job)) {
    fwrite(STDERR, "usage: php worker.php '<json job>'\n");
    exit(2);
}

foreach (['DB_DATABASE' => 'zaylotix_qa', 'SESSION_DRIVER' => 'array', 'CACHE_STORE' => 'file', 'QUEUE_CONNECTION' => 'sync'] as $k => $v) {
    putenv("$k=$v");
    $_ENV[$k] = $v;
    $_SERVER[$k] = $v;
}

$root = dirname(__DIR__, 3);
chdir($root);
require $root.'/vendor/autoload.php';
$app = require $root.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

if (DB::getDatabaseName() !== 'zaylotix_qa') {
    fwrite(STDERR, "REFUSING: not the QA database\n");
    exit(2);
}

\Illuminate\Foundation\Http\Middleware\VerifyCsrfToken::except(['*']);

$user = App\Models\User::withoutGlobalScopes()->findOrFail($job['user']);
Auth::guard('web')->setUser($user);
Auth::shouldUse('web');

$body = $job['body'] ?? [];
$request = Illuminate\Http\Request::create(
    $job['uri'], $job['method'] ?? 'POST', [], [], [],
    ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json', 'HTTP_X_REQUESTED_WITH' => 'XMLHttpRequest'],
    json_encode($body)
);
$request->setUserResolver(fn () => $user);

// everything is booted; now wait for the common start instant so the requests really overlap
while (microtime(true) < $job['start']) {
    usleep(200);
}
$t0 = microtime(true);
try {
    $response = $kernel->handle($request);
    $status = $response->getStatusCode();
    $content = $response->getContent();
} catch (Throwable $e) {
    $status = 599;
    $content = get_class($e).': '.$e->getMessage();
}
$t1 = microtime(true);

echo json_encode([
    'worker' => $job['label'] ?? null,
    'status' => $status,
    'body' => mb_substr((string) $content, 0, 600),
    'started_ms' => round(($t0 - $job['start']) * 1000, 1),
    'took_ms' => round(($t1 - $t0) * 1000, 1),
], JSON_UNESCAPED_UNICODE), "\n";
