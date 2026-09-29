<?php
declare(strict_types=1);

// Compatible with PHP 8.0+. Apache runs many worker processes, so the
// counter and probe state live in files in the temp directory instead of memory.

const STATE_FILE = '/s2i-php-state.json';

function state(?callable $mutate = null): array
{
    $fh = fopen(sys_get_temp_dir() . STATE_FILE, 'c+');
    flock($fh, LOCK_EX);
    $state = json_decode(stream_get_contents($fh) ?: '', true)
        ?: ['requests' => 0, 'failUntil' => ['live' => 0, 'ready' => 0]];
    if ($mutate) {
        $state = $mutate($state);
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, json_encode($state));
    }
    flock($fh, LOCK_UN);
    fclose($fh);
    return $state;
}

function probes(array $state): array
{
    $now = microtime(true);
    return ['live' => $now >= $state['failUntil']['live'], 'ready' => $now >= $state['failUntil']['ready']];
}

function json(mixed $data, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function env(string $name): ?string
{
    $v = getenv($name);
    return $v === false ? null : $v;
}

function secondsParam(int $default, int $max = 300): int
{
    return max(0, min($max, (int) ($_GET['seconds'] ?? $default)));
}

// Age of the container's main process: /proc/1 start time vs. system uptime.
function uptimeSeconds(): ?int
{
    $stat = @file_get_contents('/proc/1/stat');
    $uptime = @file_get_contents('/proc/uptime');
    if (!$stat || !$uptime) {
        return null;
    }
    $fields = explode(' ', substr($stat, strrpos($stat, ')') + 2));
    return (int) ((float) $uptime - ((int) $fields[19]) / 100);
}

function rssMb(): ?int
{
    $status = @file_get_contents('/proc/self/status');
    return $status && preg_match('/VmRSS:\s+(\d+)/', $status, $m) ? intdiv((int) $m[1], 1024) : null;
}

function podIp(): ?string
{
    $ip = gethostbyname(gethostname());
    return filter_var($ip, FILTER_VALIDATE_IP) && $ip !== '127.0.0.1' ? $ip : ($_SERVER['SERVER_ADDR'] ?? null);
}

function podNamespace(): ?string
{
    $ns = @file_get_contents('/var/run/secrets/kubernetes.io/serviceaccount/namespace');
    return $ns !== false ? trim($ns) : (env('POD_NAMESPACE') ?? env('OPENSHIFT_BUILD_NAMESPACE'));
}

// APP_* variables, with secret-looking names masked.
function appEnv(): array|object
{
    $out = [];
    foreach (getenv() as $k => $v) {
        if (str_starts_with($k, 'APP_')) {
            $out[$k] = preg_match('/SECRET|PASSWORD|TOKEN|KEY/i', $k) ? '••••••••' : $v;
        }
    }
    return $out ?: new stdClass();
}

$path   = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

$state = state(function (array $s) {
    $s['requests']++;
    return $s;
});

if ($path === '/health' || $path === '/ready') {
    $ok = probes($state)[$path === '/health' ? 'live' : 'ready'];
    json(['status' => $ok ? 'UP' : 'DOWN'], $ok ? 200 : 503);
}

if ($method === 'POST' && preg_match('#^/api/fail/(live|ready)$#', $path, $m)) {
    $seconds = secondsParam(60);
    $state = state(function (array $s) use ($m, $seconds) {
        $s['failUntil'][$m[1]] = microtime(true) + $seconds;
        return $s;
    });
    error_log("{$m[1]} probe failing for {$seconds}s");
    json(['probe' => $m[1], 'seconds' => $seconds, 'probes' => probes($state)]);
}

if ($method === 'POST' && $path === '/api/stress') {
    $seconds = secondsParam(30, 60);
    set_time_limit($seconds + 10);
    error_log("Burning CPU for {$seconds}s");
    $end = microtime(true) + $seconds;
    while (microtime(true) < $end) {
        sqrt(mt_rand());
    }
    json(['seconds' => $seconds, 'pod' => gethostname()]);
}

if ($path === '/api/info') {
    json([
        'stack'     => 'PHP',
        'framework' => ($_SERVER['SERVER_SOFTWARE'] ?? PHP_SAPI),
        'runtime'   => PHP_VERSION,
        'accent'    => env('APP_COLOR') ?? '#777bb3',
        'message'   => env('APP_MESSAGE') ?? 'Hello from PHP on OpenShift!',
        'pod'       => [
            'hostname'  => gethostname() ?: null,
            'ip'        => podIp(),
            'namespace' => podNamespace(),
            'node'      => env('NODE_NAME'),
        ],
        'build'     => [
            'name'      => env('OPENSHIFT_BUILD_NAME'),
            'source'    => env('OPENSHIFT_BUILD_SOURCE'),
            'reference' => env('OPENSHIFT_BUILD_REFERENCE'),
            'commit'    => env('OPENSHIFT_BUILD_COMMIT'),
        ],
        'process'   => [
            'pid'           => getmypid(),
            'platform'      => PHP_OS . ' ' . php_uname('m'),
            'cpus'          => (int) (@shell_exec('nproc') ?: 0) ?: null,
            'memoryMB'      => rssMb(),
            'uptimeSeconds' => uptimeSeconds(),
        ],
        'request'   => [
            'method'         => $method,
            'host'           => $_SERVER['HTTP_HOST'] ?? null,
            'clientIp'       => $_SERVER['REMOTE_ADDR'] ?? null,
            'forwardedFor'   => $_SERVER['HTTP_X_FORWARDED_FOR'] ?? null,
            'forwardedProto' => $_SERVER['HTTP_X_FORWARDED_PROTO'] ?? null,
            'userAgent'      => $_SERVER['HTTP_USER_AGENT'] ?? null,
        ],
        'requests'  => $state['requests'],
        'probes'    => probes($state),
        'env'       => appEnv(),
        'time'      => gmdate('c'),
    ]);
}

if ($path === '/' || $path === '/index.php') {
    header('Content-Type: text/html; charset=utf-8');
    readfile(__DIR__ . '/public/index.html');
    exit;
}

json(['error' => 'Not found', 'path' => $path], 404);
