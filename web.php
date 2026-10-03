<?php

declare(strict_types=1);

/*
 * firefly-gnucash web interface: upload a GnuCash book, check and edit the counterparty rules and
 * the account mapping, import it into Firefly III, export Firefly III as a GnuCash book and remove
 * test imports again. Everything runs through firefly-gnucash.php next to this file.
 *
 * https://github.com/Simon0Harms/firefly-iii-gnucash
 * Created with AI (Claude by Anthropic). License: GPL-3.0-or-later
 *
 * Start:  php -d upload_max_filesize=200M -d post_max_size=200M -S 127.0.0.1:8090 web.php
 *         then open http://127.0.0.1:8090
 * or serve web.php with Apache/nginx + PHP (see README.md, "Web interface").
 *
 * There is no login of its own: bind it to 127.0.0.1 or protect it at the reverse proxy
 * (Basic Auth). Every browser session only sees its own uploads. The Firefly token is never
 * stored on the server; it is handed to the background job in its environment.
 */

namespace FireflyGnuCashWeb;

const TOOL = __DIR__.'/firefly-gnucash.php';

const FILES = [
    'book'        => 'book.gnucash',
    'config'      => 'book.import.json',
    'rules'       => 'book.payee-rules.txt',
    'payees'      => 'book.payees.csv',
    'map'         => 'book.payee-map.csv',
    'details'     => 'book.payee-details.json',
    'suggestions' => 'book.payee-suggestions.txt',
    'summary'     => 'summary.json',
    'importlog'   => 'book.import-log.jsonl',
    'export'      => 'export.gnucash',
];

const JOB_TYPES = ['plan', 'dryrun', 'import', 'export', 'compare', 'purge-preview', 'purge'];

/** Jobs that talk to Firefly and need URL + token. */
const FIREFLY_JOBS = ['dryrun', 'import', 'export', 'purge-preview', 'purge'];

final class WebError extends \RuntimeException
{
    public function __construct(string $message, public int $status = 400)
    {
        parent::__construct($message);
    }
}

// =====================================================================================
// Configuration (optional web.config.php next to this file, see README)
// =====================================================================================

function config(): array
{
    static $cfg = null;
    if (null !== $cfg) {
        return $cfg;
    }
    $cfg = [
        'data_dir'        => rtrim(sys_get_temp_dir(), '/').'/firefly-gnucash-web',
        'firefly_url'     => '',      // fixed Firefly URL: hides the field (recommended with several users)
        'php_cli'         => '',      // PHP CLI for the background jobs, default: auto-detect
        'cacert'          => '',      // CA bundle for a Firefly with a self-signed certificate
        'timezone'        => '',      // for old GnuCash dates without neutral time
        'retention_hours' => 24,      // workspaces unused for this long are deleted
        'max_upload_mb'   => 200,
    ];
    $file = getenv('FFGC_WEB_CONFIG') ?: __DIR__.'/web.config.php';
    if (is_file($file)) {
        $user = require $file;
        if (is_array($user)) {
            $cfg = array_merge($cfg, array_intersect_key($user, $cfg));
        }
    }

    return $cfg;
}

function dataDir(): string
{
    $dir = rtrim((string) config()['data_dir'], '/');
    if (!is_dir($dir)) {
        if (!@mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new WebError(sprintf('Data directory "%s" cannot be created (web.config.php: data_dir).', $dir), 500);
        }
        // in case it ends up below an Apache document root
        @file_put_contents($dir.'/.htaccess', "Require all denied\n");
        @file_put_contents($dir.'/index.html', '');
    }
    if (!is_writable($dir)) {
        throw new WebError(sprintf('Data directory "%s" is not writable.', $dir), 500);
    }

    return $dir;
}

function loadTool(): void
{
    if (!\defined('FIREFLY_GNUCASH_LIBRARY')) {
        \define('FIREFLY_GNUCASH_LIBRARY', true);
    }
    if (!class_exists(\FireflyGnuCash\PayeeRules::class, false)) {
        if (!is_file(TOOL)) {
            throw new WebError('firefly-gnucash.php not found next to web.php.', 500);
        }
        require_once TOOL;
    }
}

function toolVersion(): string
{
    if (!is_file(TOOL)) {
        return '?';
    }

    return 1 === preg_match("/const VERSION\\s*=\\s*'([^']+)'/", (string) file_get_contents(TOOL, false, null, 0, 20000), $m) ? $m[1] : '?';
}

/** PHP command line binary for the background jobs (PHP_BINARY is php-fpm/apache under a web server). */
function phpCli(): string
{
    $cfg = (string) config()['php_cli'];
    if ('' !== $cfg) {
        return $cfg;
    }
    if (\in_array(PHP_SAPI, ['cli', 'cli-server'], true) && '' !== PHP_BINARY) {
        return PHP_BINARY;
    }
    foreach ([PHP_BINDIR.'/php', '/usr/bin/php'.PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION, '/usr/bin/php', '/usr/local/bin/php'] as $c) {
        if (is_file($c) && is_executable($c)) {
            return $c;
        }
    }

    return 'php';
}

function iniBytes(string $v): int
{
    $v = trim($v);
    if ('' === $v || '-1' === $v) {
        return PHP_INT_MAX;
    }
    $n = (float) $v;

    return (int) match (strtolower(substr($v, -1))) {
        'g'     => $n * 1024 ** 3,
        'm'     => $n * 1024 ** 2,
        'k'     => $n * 1024,
        default => $n,
    };
}

function maxUpload(): int
{
    return min((int) config()['max_upload_mb'] * 1024 * 1024, iniBytes((string) ini_get('upload_max_filesize')), iniBytes((string) ini_get('post_max_size')));
}

/** @return list<string> problems of the server setup, shown as a banner */
function setupProblems(): array
{
    $p = [];
    if (\PHP_VERSION_ID < 80100) {
        $p[] = 'PHP 8.1 or newer is required (running '.PHP_VERSION.').';
    }
    foreach (['curl', 'bcmath', 'xmlreader', 'dom', 'zlib', 'mbstring', 'json'] as $ext) {
        if (!\extension_loaded($ext)) {
            $p[] = sprintf('PHP extension "%s" is missing in the web server PHP.', $ext);
        }
    }
    if (!\function_exists('proc_open')) {
        $p[] = 'proc_open() is disabled (disable_functions) - background jobs cannot start.';
    }
    if (!is_file(TOOL)) {
        $p[] = 'firefly-gnucash.php is missing next to web.php.';
    }

    return $p;
}

// =====================================================================================
// Session, workspace, language
// =====================================================================================

function isHttps(): bool
{
    return ('' !== ($_SERVER['HTTPS'] ?? '') && 'off' !== strtolower((string) $_SERVER['HTTPS']))
        || 'https' === strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''));
}

function cookiePath(): string
{
    if ('cli-server' === PHP_SAPI) {
        return '/';
    }
    $dir = str_replace('\\', '/', \dirname((string) ($_SERVER['SCRIPT_NAME'] ?? '/')));

    return rtrim($dir, '/').'/';
}

/** Server secret for the workspace cookie and the CSRF token (created on first use). */
function secret(): string
{
    $file = dataDir().'/.secret';
    if (!is_file($file)) {
        $fh = @fopen($file, 'x');           // exclusive: two first requests must not race
        if (false !== $fh) {
            fwrite($fh, bin2hex(random_bytes(32)));
            fclose($fh);
            @chmod($file, 0600);
        }
    }
    $secret = trim((string) @file_get_contents($file));
    if (64 !== \strlen($secret)) {
        throw new WebError('The secret in the data directory cannot be read.', 500);
    }

    return $secret;
}

/**
 * Workspace of this browser: a random id in a signed cookie (valid for retention_hours, renewed
 * on every page load). No PHP session: nothing expires during a long import.
 *
 * @return array{0:string, 1:string} [workspace id, CSRF token]
 */
function session(bool $renew): array
{
    $secret = secret();
    $cookie = (string) ($_COOKIE['ffgc_ws'] ?? '');
    $ws     = null;
    if (1 === preg_match('/^([a-f0-9]{32})\.([a-f0-9]{64})$/', $cookie, $m) && hash_equals(hash_hmac('sha256', $m[1], $secret), $m[2])) {
        $ws = $m[1];
    }
    if (null === $ws || $renew) {
        $ws ??= bin2hex(random_bytes(16));
        setcookie('ffgc_ws', $ws.'.'.hash_hmac('sha256', $ws, $secret), [
            'expires'  => time() + max(1, (int) config()['retention_hours']) * 3600,
            'path'     => cookiePath(),
            'secure'   => isHttps(),
            'httponly' => true,
            'samesite' => 'Strict',
        ]);
    }

    return [$ws, hash_hmac('sha256', 'csrf|'.$ws, $secret)];
}

function lang(): string
{
    $c = (string) ($_COOKIE['ffgc_lang'] ?? '');
    if (\in_array($c, ['de', 'en'], true)) {
        return $c;
    }
    foreach (explode(',', strtolower((string) ($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''))) as $tag) {
        $tag = trim(explode(';', $tag)[0]);
        if (str_starts_with($tag, 'de')) {
            return 'de';
        }
        if (str_starts_with($tag, 'en')) {
            return 'en';
        }
    }

    return 'en';
}

final class Workspace
{
    public readonly string $dir;

    public function __construct(public readonly string $id)
    {
        $this->dir = dataDir().'/'.$id;
    }

    public function ensure(): void
    {
        if (!is_dir($this->dir) && !@mkdir($this->dir, 0700, true) && !is_dir($this->dir)) {
            throw new WebError('Workspace cannot be created.', 500);
        }
        @touch($this->dir.'/.touch');
    }

    public function exists(): bool
    {
        return is_dir($this->dir);
    }

    public function path(string $file): string
    {
        return $this->dir.'/'.$file;
    }

    public function file(string $key): string
    {
        return $this->path(FILES[$key]);
    }

    public function has(string $key): bool
    {
        return is_file($this->file($key));
    }

    /** @return array<string, mixed> */
    public function meta(): array
    {
        $m = is_file($this->path('meta.json')) ? json_decode((string) file_get_contents($this->path('meta.json')), true) : null;

        return \is_array($m) ? $m : [];
    }

    /** @param array<string, mixed> $meta */
    public function saveMeta(array $meta): void
    {
        file_put_contents($this->path('meta.json'), json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    /** Base name for downloads: the uploaded file name without extension. */
    public function baseName(): string
    {
        $name = (string) ($this->meta()['name'] ?? 'gnucash');
        $name = (string) preg_replace('/(\.gnucash|\.xml|\.gz|\.sqlite|\.sqlite3|\.db)+$/i', '', $name);

        return '' === $name ? 'gnucash' : $name;
    }

    public function delete(): void
    {
        rrmdir($this->dir);
    }
}

function rrmdir(string $dir): void
{
    if (!is_dir($dir) || is_link($dir)) {
        @unlink($dir);

        return;
    }
    foreach (scandir($dir) ?: [] as $f) {
        if ('.' !== $f && '..' !== $f) {
            rrmdir($dir.'/'.$f);
        }
    }
    @rmdir($dir);
}

/** Deletes workspaces that were not used for retention_hours (at most every 10 minutes). */
function gc(): void
{
    $dir    = dataDir();
    $marker = $dir.'/.gc';
    if (is_file($marker) && filemtime($marker) > time() - 600) {
        return;
    }
    @touch($marker);
    $limit = time() - max(1, (int) config()['retention_hours']) * 3600;
    foreach (glob($dir.'/*', GLOB_ONLYDIR) ?: [] as $d) {
        $id = basename($d);
        if (1 !== preg_match('/^[a-f0-9]{32}$/', $id)) {
            continue;
        }
        $t = @filemtime($d.'/.touch') ?: (int) @filemtime($d);
        if ($t > $limit || Job::running(new Workspace($id))) {
            continue;
        }
        rrmdir($d);
    }
}

// =====================================================================================
// Background jobs: "php firefly-gnucash.php ..." detached from the web request
// =====================================================================================

final class Job
{
    public static function alive(Workspace $w, int $pid): bool
    {
        if ($pid <= 0) {
            return false;
        }
        if (is_dir('/proc')) {
            $cmd = @file_get_contents('/proc/'.$pid.'/cmdline');

            return false !== $cmd && str_contains($cmd, $w->dir);     // not a reused PID
        }

        return \function_exists('posix_kill') && posix_kill($pid, 0);
    }

    public static function pid(Workspace $w): int
    {
        return is_file($w->path('job.pid')) ? (int) trim((string) file_get_contents($w->path('job.pid'))) : 0;
    }

    public static function running(Workspace $w): bool
    {
        if (!is_file($w->path('job.json')) || is_file($w->path('job.exit'))) {
            return false;
        }
        $job = json_decode((string) file_get_contents($w->path('job.json')), true);
        $pid = self::pid($w);
        if (0 === $pid) {
            return time() - (int) ($job['started'] ?? 0) < 15;   // still starting
        }

        return self::alive($w, $pid);
    }

    /** @return null|array<string, mixed> */
    public static function status(Workspace $w, bool $withLog = true): ?array
    {
        if (!is_file($w->path('job.json'))) {
            return null;
        }
        $job  = json_decode((string) file_get_contents($w->path('job.json')), true);
        if (!\is_array($job) || !\in_array($job['type'] ?? '', JOB_TYPES, true)) {
            return null;
        }
        $exit = is_file($w->path('job.exit')) ? (int) trim((string) file_get_contents($w->path('job.exit'))) : null;
        if (null !== $exit) {
            $state = 0 === $exit ? 'ok' : ('compare' === $job['type'] && 1 === $exit ? 'differences' : 'failed');
        } elseif (is_file($w->path('job.cancelled'))) {
            $state = self::running($w) ? 'running' : 'cancelled';
        } else {
            $state = self::running($w) ? 'running' : 'lost';
        }
        $log  = $w->path($job['type'].'.log');
        $tail = $withLog ? str_replace($w->dir.'/', '', self::tail($log)) : '';
        $job['state']    = $state;
        $job['exit']     = $exit;
        $job['finished'] = null === $exit ? null : (int) @filemtime($w->path('job.exit'));
        $job['log']      = $tail;
        $job['progress'] = self::progress($tail);
        $job['phase']    = self::phase($tail);

        return $job;
    }

    public static function tail(string $file, int $bytes = 65536, int $lines = 400): string
    {
        if (!is_file($file)) {
            return '';
        }
        $size = (int) filesize($file);
        $fh   = fopen($file, 'rb');
        if ($size > $bytes) {
            fseek($fh, -$bytes, SEEK_END);
        }
        $text = (string) stream_get_contents($fh);
        fclose($fh);
        $text = cleanLog($text);
        $all  = explode("\n", $text);
        if ($size > $bytes) {
            array_shift($all);      // partial first line
        }

        return implode("\n", \array_slice($all, -$lines));
    }

    /** @return null|array{done:int, total:int, pct:float, eta:?string} */
    public static function progress(string $log): ?array
    {
        $rows = explode("\n", $log);
        for ($i = \count($rows) - 1; $i >= 0; --$i) {
            $r = $rows[$i];
            if (1 === preg_match('/^\s+(\d+)\/(\d+)(?:\s+\((\d+(?:\.\d+)?)%\))?(?:.*?ETA (\d\d:\d\d:\d\d))?/', $r, $m)
                || 1 === preg_match('/page (\d+)\/(\d+)/', $r, $m)) {
                $done  = (int) $m[1];
                $total = max(1, (int) $m[2]);

                return ['done' => $done, 'total' => $total, 'pct' => isset($m[3]) && '' !== $m[3] ? (float) $m[3] : round(100 * $done / $total, 1), 'eta' => $m[4] ?? null];
            }
            if (str_starts_with($r, '==> ')) {
                return null;        // a new phase without progress yet
            }
        }

        return null;
    }

    public static function phase(string $log): string
    {
        $rows = explode("\n", $log);
        for ($i = \count($rows) - 1; $i >= 0; --$i) {
            if (str_starts_with($rows[$i], '==> ')) {
                return substr($rows[$i], 4);
            }
        }

        return '';
    }

    /**
     * @param list<string>          $args tool arguments (no secrets!)
     * @param array<string, string> $env  extra environment, e.g. FIREFLY_TOKEN
     * @param array<string, mixed>  $meta shown in the UI
     */
    public static function start(Workspace $w, string $type, array $args, array $env = [], array $meta = []): void
    {
        if (!\function_exists('proc_open')) {
            throw new WebError('proc_open() is disabled in this PHP - background jobs cannot start.', 500);
        }
        if (self::running($w)) {
            throw new WebError(t('err.job_running'), 409);
        }
        $w->ensure();
        foreach (['job.exit', 'job.pid', 'job.cancelled', $type.'.log'] as $f) {
            @unlink($w->path($f));
        }
        file_put_contents($w->path('job.json'), json_encode(['type' => $type, 'started' => time(), 'args' => $args, 'meta' => $meta], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $cmd = implode(' ', array_map('escapeshellarg', array_merge([phpCli(), TOOL], $args)));
        $q   = static fn (string $f): string => escapeshellarg($w->path($f));
        // the job shell writes its PID (= process group with setsid) and the exit code of the tool
        $script = 'echo $$ > '.$q('job.pid').'; '.$cmd.' > '.$q($type.'.log').' 2>&1 < /dev/null; echo $? > '.$q('job.exit.tmp').'; mv '.$q('job.exit.tmp').' '.$q('job.exit');
        $setsid = is_executable('/usr/bin/setsid') ? '/usr/bin/setsid ' : (is_executable('/bin/setsid') ? '/bin/setsid ' : '');
        $launch = $setsid.'/bin/sh -c '.escapeshellarg($script).' > /dev/null 2>&1 &';
        $base   = ['PATH' => getenv('PATH') ?: '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin', 'LANG' => 'C.UTF-8', 'LC_ALL' => 'C.UTF-8', 'HOME' => $w->dir];
        $proc   = proc_open(['/bin/sh', '-c', $launch], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes, $w->dir, $base + $env);
        if (!\is_resource($proc)) {
            throw new WebError('The background job could not be started.', 500);
        }
        proc_close($proc);
        for ($i = 0; $i < 50 && !is_file($w->path('job.pid')); ++$i) {
            usleep(20000);
        }
    }

    public static function cancel(Workspace $w): void
    {
        $pid = self::pid($w);
        if (!self::running($w) || $pid <= 0) {
            return;
        }
        touch($w->path('job.cancelled'));
        $pids = [$pid];
        // children (without setsid the process group is the one of the web server)
        foreach (glob('/proc/[0-9]*/stat') ?: [] as $stat) {
            $parts = explode(' ', (string) @file_get_contents($stat));
            if (($parts[3] ?? '') === (string) $pid) {
                $pids[] = (int) $parts[0];
            }
        }
        if (\function_exists('posix_kill')) {
            if (!@posix_kill(-$pid, 15)) {
                foreach (array_reverse($pids) as $p) {
                    @posix_kill($p, 15);
                }
            }
        } else {
            foreach (array_reverse($pids) as $p) {
                @exec('kill -TERM '.(int) $p.' 2>/dev/null');
            }
        }
    }
}

function cleanLog(string $text): string
{
    $text = (string) preg_replace('/\e\[[0-9;]*[A-Za-z]/', '', $text);

    return str_replace("\r", '', $text);
}

// =====================================================================================
// Helpers for the API
// =====================================================================================

/** @param array<string, mixed> $data */
function json(array $data, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

/** @return array<string, mixed> */
function body(): array
{
    $raw = (string) file_get_contents('php://input');
    if ('' === $raw) {
        return [];
    }
    $data = json_decode($raw, true);
    if (!\is_array($data)) {
        throw new WebError('Invalid request.');
    }

    return $data;
}

function t(string $key, array $vars = []): string
{
    $s = texts(lang())[$key] ?? texts('en')[$key] ?? $key;
    foreach ($vars as $k => $v) {
        $s = str_replace('{'.$k.'}', (string) $v, $s);
    }

    return $s;
}

/** @return list<array<string, string>> */
function readCsv(string $file): array
{
    $rows = [];
    $fh   = fopen($file, 'rb');
    if (false === $fh) {
        return [];
    }
    $head = null;
    while (false !== ($r = fgetcsv($fh, null, ';', '"', ''))) {
        if (null === $head) {
            $r[0] = (string) preg_replace('/^\xEF\xBB\xBF/', '', (string) $r[0]);
            $head = $r;

            continue;
        }
        if ([null] === $r) {
            continue;
        }
        $rows[] = array_combine($head, array_pad(\array_slice($r, 0, \count($head)), \count($head), ''));
    }
    fclose($fh);

    return $rows;
}

/** @return list<array{count:int, names:string, rule:string}> */
function readSuggestions(string $file): array
{
    $out   = [];
    $lines = file($file, FILE_IGNORE_NEW_LINES) ?: [];
    for ($i = 0, $n = \count($lines); $i < $n; ++$i) {
        if (1 === preg_match('/^# (\d+) transactions?: (.*)$/', $lines[$i], $m) && isset($lines[$i + 1]) && '' !== trim($lines[$i + 1]) && !str_starts_with($lines[$i + 1], '#')) {
            $out[] = ['count' => (int) $m[1], 'names' => $m[2], 'rule' => trim($lines[$i + 1])];
            ++$i;
        }
    }

    return $out;
}

function validateRules(string $text): ?string
{
    loadTool();
    $tmp = tempnam(sys_get_temp_dir(), 'ffgc');
    file_put_contents($tmp, $text);
    try {
        \FireflyGnuCash\PayeeRules::load($tmp);

        return null;
    } catch (\Throwable $e) {
        return str_replace($tmp, 'payee-rules.txt', $e->getMessage());
    } finally {
        @unlink($tmp);
    }
}

function validateConfig(string $text): ?string
{
    $data = json_decode($text, true);
    if (!\is_array($data)) {
        return 'import.json: '.json_last_error_msg();
    }
    if (!\is_array($data['accounts'] ?? null)) {
        return 'import.json: "accounts" is missing.';
    }
    loadTool();
    $tmp = tempnam(sys_get_temp_dir(), 'ffgc');
    file_put_contents($tmp, $text);
    try {
        \FireflyGnuCash\ImportConfig::load($tmp)->validate();

        return null;
    } catch (\Throwable $e) {
        return str_replace($tmp, 'import.json', $e->getMessage());
    } finally {
        @unlink($tmp);
    }
}

function fireflyUrl(array $b): string
{
    $fixed = trim((string) config()['firefly_url']);
    $url   = '' !== $fixed ? $fixed : trim((string) ($b['url'] ?? ''));
    $url   = (string) preg_replace('~/api/v1/?$~', '', rtrim($url, '/'));
    if (1 !== preg_match('~^https?://[^\s/?#]+~i', $url)) {
        throw new WebError(t('err.url'));
    }

    return $url;
}

function token(array $b): string
{
    $token = trim((string) ($b['token'] ?? ''));
    if ('' === $token || 1 === preg_match('/\s/', $token)) {
        throw new WebError(t('err.token'));
    }

    return $token;
}

function dateArg(array $o, string $key): ?string
{
    $v = trim((string) ($o[$key] ?? ''));
    if ('' === $v) {
        return null;
    }
    if (1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
        throw new WebError(t('err.date'));
    }

    return $v;
}

/** @return list<string> */
function commonArgs(): array
{
    $a  = [];
    $tz = trim((string) config()['timezone']);
    if ('' !== $tz) {
        $a[] = '--timezone='.$tz;
    }

    return $a;
}

/** @return list<string> */
function fireflyArgs(string $url): array
{
    $a      = ['--url='.$url];
    $cacert = trim((string) config()['cacert']);
    if ('' !== $cacert) {
        $a[] = '--cacert='.$cacert;
    }

    return $a;
}

function startPlan(Workspace $w): void
{
    Job::start($w, 'plan', array_merge(['plan', $w->file('book'), '--summary-json='.$w->file('summary')], commonArgs()));
}

function sendFile(string $file, string $name, string $type): never
{
    if (!is_file($file)) {
        throw new WebError(t('err.no_file'), 404);
    }
    $name  = (string) preg_replace('/[\x00-\x1f"\\\\\/]+/', '_', $name);
    $ascii = (string) preg_replace('/[^\x20-\x7e]/', '_', $name);
    header('Content-Type: '.$type);
    header('Content-Length: '.filesize($file));
    header("Content-Disposition: attachment; filename=\"{$ascii}\"; filename*=UTF-8''".rawurlencode($name));
    header('Cache-Control: no-store');
    readfile($file);
    exit;
}

// =====================================================================================
// Request handling
// =====================================================================================

function securityHeaders(string $nonce): void
{
    header("Content-Security-Policy: default-src 'none'; script-src 'nonce-{$nonce}'; style-src 'nonce-{$nonce}'; img-src 'self' data:; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: DENY');
    header('Referrer-Policy: no-referrer');
}

function main(): void
{
    $nonce = base64_encode(random_bytes(16));
    securityHeaders($nonce);
    $action = (string) ($_GET['a'] ?? '');
    $isApi  = '' !== $action;

    try {
        if ('cli-server' === PHP_SAPI && '/favicon.ico' === parse_url((string) ($_SERVER['REQUEST_URI'] ?? '/'), PHP_URL_PATH)) {
            http_response_code(204);

            return;
        }
        [$wsId, $csrf] = session('' === $action);
        $w = new Workspace($wsId);
        if ('POST' === ($_SERVER['REQUEST_METHOD'] ?? 'GET')) {
            if (!hash_equals($csrf, (string) ($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) {
                throw new WebError(t('err.csrf'), 403);
            }
            if (0 === \count($_FILES) && 'upload' === $action && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > iniBytes((string) ini_get('post_max_size'))) {
                throw new WebError(t('err.too_big', ['max' => round(maxUpload() / 1048576).' MB']), 413);
            }
        }
        if ($w->exists()) {
            @touch($w->path('.touch'));
        }
        match ($action) {
            ''            => page($w, $csrf, $nonce),
            'status'      => apiStatus($w),
            'table'       => apiTable($w),
            'details'     => apiDetails($w),
            'suggestions' => json(['ok' => true, 'items' => $w->has('suggestions') ? readSuggestions($w->file('suggestions')) : []]),
            'text'        => apiText($w),
            'rulepreview' => apiRulePreview($w),
            'dl'          => apiDownload($w),
            'upload'      => apiUpload($w),
            'save'        => apiSave($w),
            'connect'     => apiConnect(),
            'run'         => apiRun($w),
            'cancel'      => apiCancel($w),
            'reset'       => apiReset($w),
            default       => throw new WebError('Unknown action.', 404),
        };
    } catch (WebError $e) {
        $isApi ? json(['ok' => false, 'error' => $e->getMessage()], $e->status) : errorPage($e->getMessage(), $nonce);
    } catch (\Throwable $e) {
        error_log('firefly-gnucash web: '.$e->getMessage().' in '.$e->getFile().':'.$e->getLine());   // no trace: it could contain the token
        $isApi ? json(['ok' => false, 'error' => $e->getMessage()], 500) : errorPage($e->getMessage(), $nonce);
    }
}

function requirePost(): void
{
    if ('POST' !== ($_SERVER['REQUEST_METHOD'] ?? 'GET')) {
        throw new WebError('POST required.', 405);
    }
}

function apiStatus(Workspace $w): never
{
    if (!$w->exists()) {
        json(['ok' => true, 'book' => null, 'summary' => null, 'job' => null, 'files' => [], 'logs' => []]);
    }
    $meta    = $w->meta();
    $summary = null;
    if ($w->has('summary')) {
        $summary = json_decode((string) file_get_contents($w->file('summary')), true);
    }
    $files = [];
    foreach (FILES as $k => $f) {
        $files[$k] = is_file($w->path($f)) ? ['size' => (int) filesize($w->path($f)), 'time' => (int) filemtime($w->path($f))] : null;
    }
    $logs = [];
    foreach (JOB_TYPES as $type) {
        if (is_file($w->path($type.'.log'))) {
            $logs[$type] = (int) filemtime($w->path($type.'.log'));
        }
    }
    json([
        'ok'      => true,
        'book'    => $w->has('book') ? ['name' => (string) ($meta['name'] ?? 'book.gnucash'), 'size' => (int) filesize($w->file('book')), 'uploaded' => (int) ($meta['uploaded'] ?? 0)] : null,
        'summary' => \is_array($summary) ? $summary : null,
        'files'   => $files,
        'logs'    => $logs,
        'job'     => Job::status($w),
        'last'    => $meta['last'] ?? [],
        'stale'   => null !== $files['summary'] && !Job::running($w) && (int) ($meta['edited'] ?? 0) > $files['summary']['time'],
    ]);
}

function apiTable(Workspace $w): never
{
    $what = (string) ($_GET['what'] ?? '');
    if (!\in_array($what, ['payees', 'map'], true)) {
        throw new WebError('Unknown table.', 404);
    }
    json(['ok' => true, 'rows' => $w->has($what) ? readCsv($w->file($what)) : [], 'time' => $w->has($what) ? filemtime($w->file($what)) : 0]);
}

/** Splits per booking text (tooltips, search), gzip-compressed when the browser accepts it. */
function apiDetails(Workspace $w): never
{
    $body = '{"ok":true,"details":'.($w->has('details') ? (string) file_get_contents($w->file('details')) : '{}').'}';
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    if (\function_exists('gzencode') && str_contains((string) ($_SERVER['HTTP_ACCEPT_ENCODING'] ?? ''), 'gzip') && !\ini_get('zlib.output_compression')) {
        header('Content-Encoding: gzip');
        header('Vary: Accept-Encoding');
        $body = (string) gzencode($body, 6);
    }
    echo $body;
    exit;
}

function apiText(Workspace $w): never
{
    $what = (string) ($_GET['what'] ?? '');
    if (!\in_array($what, ['rules', 'config'], true)) {
        throw new WebError('Unknown file.', 404);
    }
    json(['ok' => true, 'text' => $w->has($what) ? (string) file_get_contents($w->file($what)) : '', 'time' => $w->has($what) ? filemtime($w->file($what)) : 0]);
}

function apiDownload(Workspace $w): never
{
    $what = (string) ($_GET['what'] ?? '');
    $base = $w->baseName();
    if (str_starts_with($what, 'log-') && \in_array(substr($what, 4), JOB_TYPES, true)) {
        $log = $w->path(substr($what, 4).'.log');
        if (!is_file($log)) {
            throw new WebError(t('err.no_file'), 404);
        }
        $clean = $w->path('download.tmp');
        file_put_contents($clean, str_replace($w->dir.'/', '', cleanLog((string) file_get_contents($log))));
        register_shutdown_function(static fn () => @unlink($clean));
        sendFile($clean, $base.'-'.substr($what, 4).'.log', 'text/plain; charset=utf-8');
    }
    $map = [
        'config'      => [$base.'.import.json', 'application/json'],
        'rules'       => [$base.'.payee-rules.txt', 'text/plain; charset=utf-8'],
        'payees'      => [$base.'.payees.csv', 'text/csv; charset=utf-8'],
        'map'         => [$base.'.payee-map.csv', 'text/csv; charset=utf-8'],
        'suggestions' => [$base.'.payee-suggestions.txt', 'text/plain; charset=utf-8'],
        'importlog'   => [$base.'.import-log.jsonl', 'application/x-ndjson'],
        'export'      => [($w->has('book') ? $base.'-' : '').'firefly-export.gnucash', 'application/octet-stream'],
    ];
    if (!isset($map[$what])) {
        throw new WebError(t('err.no_file'), 404);
    }
    if (!$w->exists()) {
        throw new WebError(t('err.no_file'), 404);
    }
    sendFile($w->file($what), $map[$what][0], $map[$what][1]);
}

function uploadError(int $code): string
{
    return match ($code) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => t('err.too_big', ['max' => round(maxUpload() / 1048576).' MB']),
        UPLOAD_ERR_PARTIAL                        => t('err.upload_partial'),
        UPLOAD_ERR_NO_FILE                        => t('err.upload_none'),
        default                                   => t('err.upload_failed', ['code' => $code]),
    };
}

function apiUpload(Workspace $w): never
{
    requirePost();
    if (Job::running($w)) {
        throw new WebError(t('err.job_running'), 409);
    }
    $f = $_FILES['book'] ?? null;
    if (!\is_array($f) || UPLOAD_ERR_OK !== (int) $f['error']) {
        throw new WebError(uploadError(\is_array($f) ? (int) $f['error'] : UPLOAD_ERR_NO_FILE));
    }
    if ((int) $f['size'] > maxUpload()) {
        throw new WebError(t('err.too_big', ['max' => round(maxUpload() / 1048576).' MB']));
    }
    $head = (string) file_get_contents((string) $f['tmp_name'], false, null, 0, 512);
    $isGz = str_starts_with($head, "\x1f\x8b");
    $isSq = str_starts_with($head, "SQLite format 3\0");
    $isXm = 1 === preg_match('/^\s*(<\?xml|<gnc-v2)/', $head);
    if (!$isGz && !$isSq && !$isXm) {
        throw new WebError(t('err.not_gnucash'));
    }
    // optional rules / import.json from an earlier run
    $rules  = null;
    $config = null;
    foreach (['rules' => 'rules', 'config' => 'config'] as $field => $kind) {
        $x = $_FILES[$field] ?? null;
        if (\is_array($x) && UPLOAD_ERR_NO_FILE !== (int) $x['error']) {
            if (UPLOAD_ERR_OK !== (int) $x['error']) {
                throw new WebError(uploadError((int) $x['error']));
            }
            $text  = (string) file_get_contents((string) $x['tmp_name']);
            $error = 'rules' === $kind ? validateRules($text) : validateConfig($text);
            if (null !== $error) {
                throw new WebError($error);
            }
            'rules' === $kind ? $rules = $text : $config = $text;
        }
    }
    $w->ensure();
    $keep = '1' === (string) ($_POST['keep'] ?? '0');
    $drop = ['payees', 'map', 'details', 'suggestions', 'summary', 'importlog', 'export'];
    if (!$keep) {
        $drop[] = 'config';
        $drop[] = 'rules';
    }
    foreach ($drop as $k) {
        @unlink($w->file($k));
    }
    foreach (JOB_TYPES as $type) {
        @unlink($w->path($type.'.log'));
    }
    foreach (['job.json', 'job.exit', 'job.pid', 'job.cancelled'] as $jf) {
        @unlink($w->path($jf));
    }
    if (!move_uploaded_file((string) $f['tmp_name'], $w->file('book'))) {
        throw new WebError(t('err.upload_failed', ['code' => 'move']), 500);
    }
    if (null !== $rules) {
        file_put_contents($w->file('rules'), $rules);
    }
    if (null !== $config) {
        file_put_contents($w->file('config'), $config);
    }
    $name = basename(str_replace('\\', '/', (string) $f['name']));
    $name = (string) preg_replace('/[\x00-\x1f]+/', '', $name);
    $w->saveMeta(['name' => '' === $name ? 'book.gnucash' : mb_substr($name, 0, 200), 'uploaded' => time(), 'edited' => time(), 'last' => []]);
    startPlan($w);
    json(['ok' => true]);
}

/** Options of the book's import.json (defaults of the tool for missing keys). */
function bookOptions(Workspace $w): array
{
    loadTool();
    $opt  = \FireflyGnuCash\ImportConfig::DEFAULT_OPTIONS;
    $data = $w->has('config') ? json_decode((string) file_get_contents($w->file('config')), true) : null;

    return \is_array($data['options'] ?? null) ? array_merge($opt, $data['options']) : $opt;
}

/**
 * Rule assistant: builds a rule from typed text (or takes one written by hand) and shows which
 * booking texts it catches and which counterparty they get. Based on the last calculation:
 * a text already caught by an earlier rule keeps it unless the rule goes before all rules.
 */
function apiRulePreview(Workspace $w): never
{
    requirePost();
    if (!$w->has('map')) {
        throw new WebError(t('err.no_book'));
    }
    loadTool();
    $b        = body();
    $mode     = \in_array($b['mode'] ?? '', ['words', 'all', 'start', 'text'], true) ? (string) $b['mode'] : 'words';
    $nameOnly = !empty($b['name_only']);
    $onlyChg  = !empty($b['only_changed']);
    $query    = trim((string) ($b['query'] ?? ''));
    $custom   = trim((string) ($b['rule'] ?? ''));
    $target   = trim((string) ($b['target'] ?? ''));
    $top      = 'top' === ($b['position'] ?? '');
    $pattern  = '' !== $custom ? $custom : \FireflyGnuCash\PayeeRules::build($query, $mode, $nameOnly);
    $out      = ['ok' => true, 'pattern' => $pattern, 'line' => '', 'error' => null, 'notes' => [], 'matches' => [], 'more' => 0, 'similar' => [],
        'totals' => ['texts' => 0, 'bookings' => 0, 'changed' => 0, 'kept' => 0], 'affected' => [], 'names' => [], 'existing' => ['expense' => 0, 'revenue' => 0]];
    if ('' === $pattern) {
        json($out);
    }
    if (1 === preg_match('/[\r\n]/', $pattern.$target)) {
        json(['error' => t('asst.err_newline')] + $out);
    }
    if (str_contains($target, '=>')) {
        json(['error' => t('asst.err_arrow')] + $out);
    }
    $line        = sprintf('%-40s => %s', $pattern, $target);
    $out['line'] = '' === $target ? '' : $line;
    $tmp         = tempnam(sys_get_temp_dir(), 'ffgc');
    file_put_contents($tmp, sprintf('%s => %s', $pattern, '' === $target ? 'X' : $target)."\n");
    try {
        $rules = \FireflyGnuCash\PayeeRules::load($tmp);
    } catch (\Throwable $e) {
        json(['error' => str_replace($tmp.':1: ', '', $e->getMessage()), 'line' => ''] + $out);
    } finally {
        @unlink($tmp);
    }
    $fields = array_column($rules->rules[0]['conds'] ?? [], 'field');
    if (\in_array('category', $fields, true)) {
        $out['notes'][] = t('asst.note_category');
    }
    if (\in_array('konto', $fields, true)) {
        $out['notes'][] = t('asst.note_konto');
    }
    $accOf  = [];
    $memoOf = [];
    if (([] !== array_intersect(['konto', 'memo'], $fields)) && $w->has('details')) {
        foreach ((array) json_decode((string) file_get_contents($w->file('details')), true) as $k => $d) {
            $accOf[$k]  = (array) ($d['acc'] ?? []);
            $memoOf[$k] = (array) ($d['memo'] ?? []);
        }
    }
    $opts     = bookOptions($w);
    $fallback = (string) ($opts['payee_fallback'] ?? '(diverse)');
    $side     = static fn (string $v): string => 1 === preg_match('/^(Ausgaben|expense)/i', $v) ? 'expense' : 'revenue';
    $lower    = static fn (string $v): string => mb_strtolower($v, 'UTF-8');
    $tokens   = array_values(array_filter(array_map($lower, preg_split('/\s+/u', $query) ?: []), static fn ($x) => '' !== $x));
    $matches  = [];
    $similar  = [];
    $affected = [];
    $names    = [];
    foreach (readCsv($w->file('map')) as $r) {
        $text  = (string) $r['booking_text'];
        $cats  = '' === (string) $r['categories'] ? [''] : explode(' | ', (string) $r['categories']);
        $ibans = array_values(array_filter(explode(' ', (string) $r['iban'])));
        $hit   = null;
        $sd    = $side((string) $r['firefly_type']);
        foreach ($cats as $c) {
            if (null !== ($hit = $rules->match($text, $ibans, $c, $memoOf[$text.'|'.$sd] ?? [], $sd, $accOf[$text.'|'.$sd] ?? []))) {
                break;
            }
        }
        $count = (int) $r['transactions'];
        if (null === $hit) {
            if ([] !== $tokens && \count($similar) < 60) {
                $lt = $lower($text);
                if ([] === array_filter($tokens, static fn ($x) => !str_contains($lt, $x))) {
                    $similar[] = ['text' => $text, 'side' => $sd, 'count' => $count, 'payee' => (string) $r['payee']];
                }
            }

            continue;
        }
        $new = '-' === $hit[0] ? str_replace('{category}', $cats[0], $fallback) : $hit[0];
        if ('' === $target) {
            $new = '';
        }
        $kept = !$top && str_starts_with((string) $r['source'], 'rule');
        $now  = (string) $r['payee'];
        $to   = $kept ? $now : $new;
        $chg  = '' !== $to && $lower($to) !== $lower($now);
        $matches[] = ['text' => $text, 'side' => $sd, 'count' => $count, 'payee' => $now, 'source' => (string) $r['source'], 'new' => $to, 'kept' => $kept, 'changes' => $chg];
        $out['totals']['texts']++;
        $out['totals']['bookings'] += $count;
        if ($kept) {
            $out['totals']['kept'] += $count;
        } elseif ($chg) {
            $out['totals']['changed'] += $count;
            $affected[$now.'|'.$sd] = ($affected[$now.'|'.$sd] ?? 0) + $count;
        }
        if ($now !== $fallback) {
            $names[$now] = ($names[$now] ?? 0) + $count;
        }
    }
    usort($matches, static fn ($a, $b) => [$b['count'], $a['text']] <=> [$a['count'], $b['text']]);
    usort($similar, static fn ($a, $b) => [$b['count'], $a['text']] <=> [$a['count'], $b['text']]);
    arsort($affected);
    arsort($names);
    if ($onlyChg) {
        $matches = array_values(array_filter($matches, static fn (array $m): bool => $m['changes']));
        $similar = [];
    }
    $out['more']     = max(0, \count($matches) - 300);
    $out['matches']  = \array_slice($matches, 0, 300);
    $out['similar']  = \array_slice($similar, 0, 30);
    $out['affected'] = array_map(static fn ($k, $n) => ['name' => explode('|', $k)[0], 'side' => explode('|', $k)[1], 'count' => $n], array_keys($affected), $affected);
    $out['names']    = \array_slice(array_keys($names), 0, 8);
    if ('' !== $target && $w->has('payees')) {
        foreach (readCsv($w->file('payees')) as $r) {
            if ($lower((string) $r['payee']) === $lower($target)) {
                $out['existing'][$side((string) $r['firefly_type'])] += (int) $r['transactions'];
            }
        }
    }
    json($out);
}

function apiSave(Workspace $w): never
{
    requirePost();
    if (!$w->has('book')) {
        throw new WebError(t('err.no_book'));
    }
    if (Job::running($w)) {
        throw new WebError(t('err.job_running'), 409);
    }
    $b    = body();
    $what = (string) ($b['what'] ?? '');
    $text = str_replace("\r\n", "\n", (string) ($b['text'] ?? ''));
    if (\strlen($text) > 5 * 1024 * 1024) {
        throw new WebError(t('err.too_big', ['max' => '5 MB']));
    }
    if ('rules' === $what) {
        $error = validateRules($text);
    } elseif ('config' === $what) {
        $error = validateConfig($text);
    } else {
        throw new WebError('Unknown file.', 404);
    }
    if (null !== $error) {
        json(['ok' => false, 'error' => $error, 'invalid' => true]);
    }
    file_put_contents($w->file($what), $text);
    $m           = $w->meta();
    $m['edited'] = time();
    $w->saveMeta($m);
    startPlan($w);
    json(['ok' => true]);
}

function apiConnect(): never
{
    requirePost();
    $b   = body();
    $url = fireflyUrl($b);
    $tok = token($b);
    loadTool();
    try {
        $cacert = trim((string) config()['cacert']);
        $api    = new \FireflyGnuCash\FireflyClient($url, $tok, '' === $cacert ? null : $cacert, 20);
        $about  = $api->get('about');
        $user   = $api->get('about/user');
    } catch (\FireflyGnuCash\ApiError $e) {
        json(['ok' => false, 'error' => t('err.firefly', ['msg' => $e->details()])], 200);
    } catch (\Throwable $e) {
        json(['ok' => false, 'error' => t('err.firefly', ['msg' => $e->getMessage()])], 200);
    }
    json(['ok' => true, 'url' => $url, 'version' => (string) ($about['data']['version'] ?? '?'), 'email' => (string) ($user['data']['attributes']['email'] ?? '?')]);
}

function apiRun(Workspace $w): never
{
    requirePost();
    $b    = body();
    $type = (string) ($b['type'] ?? '');
    $o    = \is_array($b['options'] ?? null) ? $b['options'] : [];
    if (!\in_array($type, JOB_TYPES, true)) {
        throw new WebError('Unknown job.', 404);
    }
    if (\in_array($type, ['plan', 'dryrun', 'import', 'compare'], true) && !$w->has('book')) {
        throw new WebError(t('err.no_book'));
    }
    $env  = [];
    $meta = [];
    $args = [];
    if (\in_array($type, FIREFLY_JOBS, true)) {
        $url               = fireflyUrl($b);
        $env['FIREFLY_TOKEN'] = token($b);
        $meta['url']       = $url;
        $args              = fireflyArgs($url);
    }
    switch ($type) {
        case 'plan':
            startPlan($w);

            json(['ok' => true]);
        case 'dryrun':
        case 'import':
            $args = array_merge(['import', $w->file('book')], $args, commonArgs(), 'import' === $type ? ['--yes'] : ['--dry-run']);
            foreach (['from', 'to'] as $k) {
                if (null !== ($d = dateArg($o, $k))) {
                    $args[] = '--'.$k.'='.$d;
                    $meta[$k] = $d;
                }
            }
            if ('' !== trim((string) ($o['limit'] ?? ''))) {
                $limit = (int) $o['limit'];
                if ($limit < 1) {
                    throw new WebError(t('err.limit'));
                }
                $args[] = '--limit='.$limit;
                $meta['limit'] = $limit;
            }
            if (!empty($o['update_payees'])) {
                $args[] = '--update-payees';
                $meta['update_payees'] = true;
            }
            if (!empty($o['no_multisource'])) {
                $args[] = '--no-multisource';
                $meta['no_multisource'] = true;
            }
            break;
        case 'export':
            @unlink($w->file('export'));
            $args = array_merge(['export', $w->file('export')], $args, commonArgs(), ['--yes']);
            foreach (['from', 'to'] as $k) {
                if (null !== ($d = dateArg($o, $k))) {
                    $args[] = '--'.$k.'='.$d;
                    $meta[$k] = $d;
                }
            }
            if (!empty($o['uncompressed'])) {
                $args[] = '--uncompressed';
            }
            break;
        case 'compare':
            if (!$w->has('export')) {
                throw new WebError(t('err.no_export'));
            }
            $args = array_merge(['compare', $w->file('book'), $w->file('export')], commonArgs(), !empty($o['by_year']) ? ['--by-year'] : []);
            break;
        case 'purge-preview':
        case 'purge':
            $tag = trim((string) ($o['tag'] ?? ''));
            if ('' !== $tag) {
                $args[]      = '--tag='.$tag;
                $meta['tag'] = $tag;
            }
            if (!empty($o['accounts'])) {
                $args[]           = '--accounts';
                $meta['accounts'] = true;
            }
            $args = array_merge(['purge'], $args, ['purge' === $type ? '--yes' : '--dry-run']);
            break;
    }
    Job::start($w, $type, $args, $env, $meta);
    $m = $w->meta();
    $m['last'][$type] = ['time' => time()] + $meta;
    $w->saveMeta($m);
    json(['ok' => true]);
}

function apiCancel(Workspace $w): never
{
    requirePost();
    Job::cancel($w);
    json(['ok' => true]);
}

function apiReset(Workspace $w): never
{
    requirePost();
    if ($w->exists()) {
        Job::cancel($w);
        $w->delete();
    }
    json(['ok' => true]);
}

// =====================================================================================
// Texts (German / English). `code` in a text is shown as code.
// =====================================================================================

/** @return array<string, string> */
function texts(string $lang): array
{
    static $all = null;
    $all ??= ['de' => textsDe(), 'en' => textsEn()];

    return $all[$lang] ?? $all['en'];
}

/** @return array<string, string> */
function textsDe(): array
{
    return [
        'app.sub'      => 'Import, Regeln und Export',
        'app.language' => 'Sprache',
        'app.steps'    => 'Schritte',
        'app.footer'   => 'Inoffizielles Werkzeug, nicht vom Firefly-III-Projekt. Der Token wird nicht auf dem Server gespeichert.',
        'app.problems' => 'Die Server-Einrichtung ist unvollständig:',
        'tab.book'     => 'GnuCash-Buch',
        'tab.payees'   => 'Gegenkonten & Regeln',
        'tab.accounts' => 'Konten & Optionen',
        'tab.firefly'  => 'Firefly III',

        'book.upload_title'  => 'GnuCash-Buch hochladen',
        'book.upload_other'  => 'Andere oder neuere Datei hochladen',
        'book.upload_anyway' => 'Trotzdem hochladen',
        'book.drop'          => 'GnuCash-Datei hierher ziehen oder klicken',
        'book.formats'       => 'XML (auch komprimiert) oder SQLite, bis {max}. Die Datei bleibt in deiner Sitzung und wird nach Ablauf gelöscht.',
        'book.more'          => 'Weitere Optionen',
        'book.keep'          => 'Regeln und Kontenzuordnung vom bisherigen Buch behalten',
        'book.rules_file'    => 'Regeldatei (payee-rules.txt, optional)',
        'book.config_file'   => 'Kontenzuordnung (import.json, optional)',
        'book.more_help'     => 'Hast du schon Regeln oder eine Kontenzuordnung von der Kommandozeile, wähle sie hier aus, bevor du das Buch hochlädst.',
        'book.uploaded'      => 'Hochgeladen am {time}',
        'book.uploaded_ok'   => 'Hochgeladen – die Vorschau wird berechnet.',
        'book.replan'        => 'Neu berechnen',
        'book.reset'         => 'Arbeitsbereich löschen',
        'book.reset_title'   => 'Arbeitsbereich löschen?',
        'book.reset_body'    => 'Das Buch, Regeln, Kontenzuordnung, Berichte und Logs dieser Sitzung werden sofort gelöscht. In Firefly ändert sich nichts.',
        'book.stale'         => 'Regeln oder Kontenzuordnung wurden nach der letzten Berechnung geändert. Klicke auf „Neu berechnen“.',
        'book.planning'      => 'Die Vorschau wird berechnet …',
        'book.plan_failed'   => 'Die Berechnung ist fehlgeschlagen:',

        'sum.importable'  => 'importierbare Buchungen (von {total})',
        'sum.withdrawals' => 'Ausgaben',
        'sum.deposits'    => 'Einnahmen',
        'sum.transfers'   => 'Umbuchungen',
        'sum.payees'      => 'Gegenkonten ({expense} Ausgaben-, {revenue} Einnahmenkonten)',
        'sum.ok'          => 'OK',
        'sum.failed'      => 'Fehler',
        'sum.checked'     => 'Selbstprüfung: alle Beträge stimmen ({n} Buchungen)',
        'sum.failed_help' => 'Selbstprüfung fehlgeschlagen – bitte als Fehler melden',
        'sum.book'        => 'Buch: {accounts} Konten, {transactions} Buchungen vom {first} bis {last}, Buchwährung {currency}',
        'sum.mapping'     => 'Zuordnung: {asset} Bestandskonten, {liability} Verbindlichkeiten, {category} Kategorien mit Buchungen',
        'sum.multisource' => '{n} Split-Buchungen mit mehreren Quellkonten (braucht den Multisource-Fork)',
        'sum.clearing'    => '{n} Buchungen ohne Bestandskonto laufen über das Umbuchungskonto',
        'sum.opening'     => 'Konten mit Anfangssaldo: {n}',
        'sum.skipped'     => 'Übersprungen: {n} × {reason}',
        'sum.not_imported'=> 'Nicht importiert (gibt es in Firefly nicht): {list}',
        'sum.rules'       => '{rules} Regeln aktiv, {sugg} Vorschläge zum Zusammenfassen',
        'sum.warnings'    => 'Warnungen ({n})',
        'sum.warn_more'   => '… und {n} weitere',
        'sum.config_msgs' => 'Änderungen an der Kontenzuordnung ({n})',
        'sum.top'         => 'Häufigste Gegenkonten',
        'sum.downloads'   => 'Dateien herunterladen',

        'skip.opening balance (set on the Firefly account)'       => 'Anfangssaldo (wird am Firefly-Konto gesetzt)',
        'skip.all amounts are zero'                               => 'alle Beträge sind 0',
        'skip.moves money only within one account'                => 'bewegt Geld nur innerhalb eines Kontos',
        'skip.nothing left to import'                             => 'nach dem Zusammenfassen bleibt nichts übrig',
        'skip.uses account "…" which is mapped to "…"'            => 'nutzt ein Konto, das auf „skip“ steht',
        'skip.internal error: unpaired income/expense split'      => 'interner Fehler: Einnahme/Ausgabe ohne Gegenstück',
        'other.budgets'                        => 'Budgets',
        'other.scheduled transactions'         => 'geplante Buchungen',
        'other.scheduled transaction templates'=> 'Vorlagen geplanter Buchungen',
        'other.invoices/bills'                 => 'Rechnungen',
        'other.invoice entries'                => 'Rechnungsposten',
        'other.customers'                      => 'Kunden',
        'other.vendors'                        => 'Lieferanten',
        'other.employees'                      => 'Mitarbeiter',
        'other.jobs'                           => 'Aufträge',
        'other.tax tables'                     => 'Steuertabellen',
        'other.billing terms'                  => 'Zahlungsbedingungen',
        'other.lots'                           => 'Lose',
        'other.prices'                         => 'Kurse',

        'dl.config'       => 'Kontenzuordnung (import.json)',
        'dl.rules'        => 'Regeln (payee-rules.txt)',
        'dl.payees'       => 'Gegenkonten (CSV)',
        'dl.map'          => 'Buchungstexte (CSV)',
        'dl.suggestions'  => 'Vorschläge',
        'dl.planlog'      => 'Ausgabe der Berechnung',
        'dl.drylog'       => 'Log Probelauf',
        'dl.importlog'    => 'Log Import',
        'dl.importjsonl'  => 'Import-Protokoll (JSONL)',

        'sub.payees'      => 'Gegenkonten',
        'sub.map'         => 'Buchungstexte',
        'sub.suggestions' => 'Vorschläge',
        'sub.rules'       => 'Regeln',
        'tbl.search'          => 'Suchen',
        'tbl.search_payees'   => 'Gegenkonto, Buchungstext, Konto, Split-Memo oder IBAN suchen',
        'tbl.search_map'      => 'Buchungstext, Gegenkonto, GnuCash-Konto oder Split-Memo suchen',
        'det.head'            => '{n} Buchungen → {payee}',
        'det.head.one'        => '1 Buchung → {payee}',
        'det.split'           => 'Mehrfachbuchung, {n} Splits',
        'det.more'            => '… und {n} ältere Buchungen (werden auch durchsucht)',
        'det.none'            => 'Keine Details – bitte neu berechnen.',
        'det.loading'         => 'Lade Details …',
        'det.acc_rule'        => 'Regel für alle Buchungen dieses Kontos erstellen (konto:)',
        'det.memo_rule'       => 'Regel für Buchungen mit dieser Bemerkung erstellen (memo:)',
        'tbl.side'            => 'Art',
        'tbl.all_sides'       => 'Ausgaben und Einnahmen',
        'tbl.source'          => 'Herkunft',
        'tbl.all_sources'     => 'jede Herkunft',
        'tbl.more'            => 'Mehr anzeigen',
        'tbl.count'           => '{total} Einträge',
        'tbl.count.one'       => '1 Eintrag',
        'tbl.count_part'      => '{shown} von {total} Einträgen',
        'tbl.payee_filter'    => 'Gegenkonto: {name}',
        'tbl.payees_help'     => 'Klick auf eine Zeile zeigt die Buchungstexte dieses Gegenkontos. Namen änderst du mit Regeln.',
        'col.payee'       => 'Gegenkonto',
        'col.side'        => 'Art',
        'col.source'      => 'Herkunft',
        'col.count'       => 'Buchungen',
        'col.amount'      => 'Summe',
        'col.period'      => 'Zeitraum',
        'col.categories'  => 'Kategorien',
        'col.examples'    => 'Beispieltexte',
        'col.text'        => 'Buchungstext',
        'side.expense'    => 'Ausgabenkonto',
        'side.revenue'    => 'Einnahmenkonto',
        'kind.expense'    => 'Ausgabe',
        'kind.revenue'    => 'Einnahme',
        'range.from'      => 'Von',
        'range.to'        => 'Bis',
        'src.rule'        => 'Regel',
        'src.auto'        => 'automatisch',
        'src.fallback'    => 'Sammelkonto',
        'src.rule_line'   => 'Regel (Zeile {n})',
        'src.rule_fallback' => 'Regel → Sammelkonto',

        'sub.assistant'   => 'Regel erstellen',
        'asst.title'      => 'Regel erstellen',
        'asst.help'       => 'Tippe einen Text aus deinen Buchungen ein, z. B. „DB Hamburg“. Darunter erscheint sofort die passende Regel und welche Buchungstexte sie erfasst – bevor du etwas speicherst.',
        'asst.query'      => 'Suchtext',
        'asst.query_ph'   => 'z. B. DB Hamburg',
        'asst.mode'       => 'Suche',
        'asst.mode_words' => 'Wörter in dieser Reihenfolge',
        'asst.mode_all'   => 'alle Wörter, beliebige Reihenfolge',
        'asst.mode_start' => 'Buchungstext beginnt damit',
        'asst.mode_text'  => 'enthält den Text (auch in Wörtern)',
        'asst.target'     => 'Gegenkonto in Firefly',
        'asst.target_ph'  => 'z. B. Deutsche Bahn',
        'asst.position'   => 'Einfügen',
        'asst.pos_end'    => 'nach meinen Regeln',
        'asst.pos_top'    => 'vor meinen Regeln (hat Vorrang)',
        'asst.name_only'      => 'Nur im Empfängernamen (nach dem letzten „;“)',
        'asst.name_only_help' => 'Für Bankbuchungen „Verwendungszweck; Name“: Überweisungen an jemand anderen, die den Text nur im Verwendungszweck nennen, bleiben unberührt.',
        'asst.names'      => 'Name übernehmen:',
        'asst.rule'       => 'Regel',
        'asst.rule_custom'=> 'von Hand geändert',
        'asst.rule_reset' => 'Aus Suchtext erzeugen',
        'asst.line_label' => 'Diese Zeile kommt in deine Regeln:',
        'asst.insert'     => 'Nur in den Editor',
        'asst.save'       => 'Übernehmen & neu berechnen',
        'asst.none'       => 'Kein Buchungstext passt zu dieser Regel.',
        'asst.only_changed'      => 'Nicht betroffene Buchungen ausblenden',
        'asst.only_changed_help' => 'Zeigt in der Trefferliste nur Buchungstexte, deren Gegenkonto sich durch die Regel ändert; unveränderte, von früheren Regeln behaltene und ähnliche Texte werden ausgeblendet.',
        'asst.summary'    => 'Erfasst {texts} Buchungstexte mit {bookings} Buchungen.',
        'asst.summary.one'=> 'Erfasst 1 Buchungstext mit {bookings} Buchungen.',
        'asst.changed'    => '{n} Buchungen bekommen das Gegenkonto „{target}“.',
        'asst.unchanged'  => 'Sie haben dieses Gegenkonto schon.',
        'asst.kept'       => '{n} Buchungen behalten ihr Gegenkonto, weil eine frühere Regel greift. Mit „vor meinen Regeln“ hat diese Regel Vorrang.',
        'asst.from'       => 'Bisher:',
        'asst.existing'   => 'Das Gegenkonto „{name}“ gibt es schon ({n} Buchungen) – die Buchungen kommen dazu.',
        'asst.need_target'=> 'Gib noch an, wie das Gegenkonto heißen soll.',
        'asst.dup'        => 'Diese Regel steht schon in deinen Regeln (Zeile {n}).',
        'asst.dirty'      => 'Der Regeleditor hat ungespeicherte Änderungen; die Vorschau zeigt den zuletzt berechneten Stand.',
        'asst.matches'    => 'Erfasste Buchungstexte',
        'asst.col_now'    => 'bisher',
        'asst.col_new'    => 'neu',
        'asst.kept_badge' => 'frühere Regel',
        'asst.more'       => '… und {n} weitere Buchungstexte.',
        'asst.similar'      => 'Ähnlich, aber nicht erfasst',
        'asst.similar_help' => 'Diese Texte enthalten alle Suchwörter, passen aber nicht zur Regel – etwa wegen einer anderen Reihenfolge. Probier eine andere Suche.',
        'asst.inserted'   => 'Regel eingefügt – im Reiter „Regeln“ speichern nicht vergessen.',
        'asst.err_newline'=> 'Regel und Gegenkonto dürfen keinen Zeilenumbruch enthalten.',
        'asst.err_arrow'  => 'Das Gegenkonto darf kein „=>“ enthalten.',
        'asst.note_konto'    => 'konto: prüft alle GnuCash-Konten der Buchung, z. B. das Bargeld- oder Kartenkonto, von dem bezahlt wurde.',
        'asst.note_category' => 'Bei category:-Regeln ist die Vorschau ungefähr: ein Buchungstext zählt, wenn eine seiner Kategorien passt.',
        'asst.open'       => 'Regel …',
        'asst.open_title' => 'Regel für diesen Eintrag erstellen',
        'sugg.adjust'     => 'Anpassen …',
        'sugg.help'       => 'Vom Tool erkannte Varianten desselben Gegenkontos. „Übernehmen“ hängt die Regel unten an deine Regeln an (danach im Reiter „Regeln“ speichern); „Anpassen …“ öffnet sie zum Ändern im Reiter „Regel erstellen“ mit Vorschau.',
        'sugg.item'       => '{n} Buchungen: {names}',
        'sugg.adopt'      => 'Übernehmen',
        'sugg.added_label'=> 'Übernommen',
        'sugg.added'      => 'Regel angefügt – im Reiter „Regeln“ speichern nicht vergessen.',

        'rules.title'        => 'Regeln für Gegenkonten',
        'rules.help_title'   => 'So funktionieren Regeln',
        'rules.help_order'   => 'Eine Regel pro Zeile: `Muster => Gegenkonto`. Die erste passende Regel gewinnt. Zeilen mit `#` sind Kommentare.',
        'rules.help_regex'   => 'regulärer Ausdruck auf den Buchungstext',
        'rules.help_text'    => 'Text irgendwo im Buchungstext (Groß-/Kleinschreibung egal)',
        'rules.help_iban'    => 'alle Buchungen mit dieser IBAN',
        'rules.help_category'=> 'nach Kategorie (GnuCash-Konto)',
        'rules.help_memo'    => 'nach Memo einer Teilbuchung',
        'rules.help_target'  => 'Rechts darf `$1` … `$9`, `{category}` oder `{description}` stehen; `-` bedeutet Sammelkonto. Beispiel:',
        'rules.help_semicolon'=> 'Soll eine Regel nur den Namen hinter dem letzten `;` treffen (nicht den Verwendungszweck einer Überweisung an jemand anderen), hänge `(?![^;]*;)` an das Muster an.',
        'rules.help_comments'=> 'Speichern prüft die Regeln und berechnet die Vorschau neu.',
        'ed.open'     => 'Datei öffnen …',
        'ed.download' => 'Herunterladen',
        'ed.revert'   => 'Verwerfen',
        'ed.save'     => 'Speichern & neu berechnen',
        'ed.saved'    => 'Gespeichert – die Vorschau wird neu berechnet.',
        'ed.opened'   => '„{name}“ geladen – noch nicht gespeichert.',
        'ed.discard'  => 'Ungespeicherte Änderungen an Regeln oder Kontenzuordnung gehen dabei verloren.',

        'acc.title'      => 'Kontenzuordnung',
        'acc.help'       => 'Wie jedes GnuCash-Konto in Firefly ankommt: Bestandskonto, Verbindlichkeit, Kategorie, ignorieren (Teilbuchungen weglassen) oder überspringen (ganze Buchungen weglassen). Gleicher Name = gleiches Firefly-Konto.',
        'acc.search'     => 'GnuCash-Konto oder Name suchen',
        'acc.kind'       => 'Filter',
        'acc.all'        => 'alle Konten',
        'acc.bs'         => 'Bestandskonten & Verbindlichkeiten',
        'acc.categories' => 'Kategorien',
        'acc.off'        => 'ignoriert / übersprungen',
        'acc.changed'    => 'geändert',
        'acc.raw'        => 'Als JSON bearbeiten',
        'acc.structured' => 'Tabelle anzeigen',
        'acc.raw_help'   => 'Die komplette import.json. Unbekannte Felder bleiben erhalten.',
        'acc.invalid_json'=> 'Die import.json ist kein gültiges JSON.',
        'acc.col_gnucash'=> 'GnuCash-Konto',
        'acc.col_splits' => 'Teilbuchungen',
        'acc.col_as'     => 'In Firefly als',
        'acc.col_name'   => 'Name in Firefly',
        'acc.col_details'=> 'Details',
        'acc.col_active' => 'Aktiv',
        'as.asset'       => 'Bestandskonto',
        'as.liability'   => 'Verbindlichkeit',
        'as.category'    => 'Kategorie',
        'as.ignore'      => 'ignorieren',
        'as.skip'        => 'überspringen',
        'role.defaultAsset'   => 'Girokonto',
        'role.savingAsset'    => 'Sparkonto',
        'role.sharedAsset'    => 'Gemeinschaftskonto',
        'role.cashWalletAsset'=> 'Bargeld',
        'role.ccAsset'        => 'Kreditkarte',
        'liab.debt'      => 'Schuld',
        'liab.loan'      => 'Darlehen',
        'liab.mortgage'  => 'Hypothek',
        'dir.debit'      => 'ich schulde',
        'dir.credit'     => 'mir wird geschuldet',
        'opt.title'      => 'Optionen',
        'opt.payee_min_count'     => 'Mindestzahl Buchungen',
        'opt.payee_fallback'      => 'Sammelkonto',
        'opt.payee_split_dash'    => 'Text vor „ - “ nehmen',
        'opt.payee_merge_prefix'  => 'Zusätze abschneiden',
        'opt.payee_group_by_iban' => 'Gleiche IBAN zusammenfassen',
        'opt.payee_set_iban'      => 'IBAN am Gegenkonto speichern',
        'opt.category_names'      => 'Kategorienamen',
        'opt.reconciled_states'   => 'Abgeglichen-Status',
        'opt.opening_balances'    => 'Anfangssalden',
        'opt.clearing_account'    => 'Umbuchungskonto',
        'opt.import_tag'          => 'Import-Tag',
        'opt.multisource'         => 'Mehrere Quellkonten',
        'opt.apply_rules'         => 'Firefly-Regeln ausführen',
        'opt.fire_webhooks'       => 'Webhooks auslösen',
        'optd.payee_min_count'    => 'Automatische Gegenkonten mit weniger Buchungen landen im Sammelkonto.',
        'optd.payee_fallback'     => 'Name des Sammelkontos, z. B. `(diverse)` oder `{category}`.',
        'optd.payee_split_dash'   => '`Händler - Details` wird zu `Händler`.',
        'optd.payee_merge_prefix' => '`Name Zusatz` wird zu `Name`, wenn `Name` selbst vorkommt.',
        'optd.payee_group_by_iban'=> 'Texte mit derselben Gegen-IBAN werden ein Gegenkonto (Sammel-IBANs von Kartenanbietern ausgenommen).',
        'optd.payee_set_iban'     => 'Hilft dem Firefly Data Importer, künftige Bankbuchungen zuzuordnen.',
        'optd.category_names'     => 'Nur für neue Konten: mit oder ohne oberste Kontoebene.',
        'optd.reconciled_states'  => 'GnuCash-Status, die in Firefly als abgeglichen gelten, z. B. `y` oder `yc`.',
        'optd.opening_balances'   => 'Erste Eigenkapital-Buchung eines Kontos wird Anfangssaldo in Firefly.',
        'optd.clearing_account'   => 'Technisches Konto für Umbuchungen zwischen Einnahmen und Ausgaben.',
        'optd.import_tag'         => 'Tag an jeder importierten Buchung; das Löschen nutzt ihn.',
        'optd.multisource'        => 'Split-Buchungen mit mehreren Quellkonten als eine Buchung (braucht den Multisource-Fork).',
        'optd.apply_rules'        => 'Firefly-Regeln beim Import anwenden.',
        'optd.fire_webhooks'      => 'Webhooks beim Import auslösen.',
        'optv.strip-root'         => 'ohne oberste Ebene',
        'optv.full-path'          => 'mit vollem Pfad',

        'ff.connection' => 'Verbindung zu Firefly III',
        'ff.url'        => 'Firefly-URL',
        'ff.fixed'      => 'Vom Administrator fest eingestellt',
        'ff.token'      => 'Personal Access Token',
        'ff.token_ph'   => 'Token einfügen',
        'ff.remember'   => 'In diesem Browser-Tab merken',
        'ff.test'       => 'Verbindung prüfen',
        'ff.testing'    => 'Prüfe …',
        'ff.ok'         => 'Verbunden: Firefly III {version}, Benutzer {email}',
        'ff.token_help' => 'Den Token erstellst du in Firefly unter Optionen → Profil → OAuth → Personal Access Tokens. Er wird nur für die jeweilige Aktion an den Server geschickt und dort nicht gespeichert.',

        'imp.title'      => 'In Firefly importieren',
        'imp.need_book'  => 'Lade zuerst ein GnuCash-Buch hoch.',
        'imp.need_plan'  => 'Die Vorschau ist noch nicht berechnet.',
        'imp.hint'       => '{n} Buchungen sind importierbar. Mach zuerst einen Probelauf: Er zeigt, was angelegt würde, und ändert nichts.',
        'imp.limit'      => 'Höchstens … Buchungen',
        'imp.limit_ph'   => 'alle',
        'imp.update_payees'      => 'Gegenkonten bereits importierter Buchungen aktualisieren',
        'imp.update_payees_help' => 'Nach geänderten Regeln: hängt schon importierte Buchungen auf die neuen Gegenkonten um und löscht leer gewordene. Von Hand in Firefly geänderte Gegenkonten werden dabei zurückgesetzt.',
        'imp.no_multisource'     => 'Ohne Multisource',
        'imp.no_multisource_help'=> 'Für ein normales Firefly ohne den Fork: Splits mit mehreren Quellkonten werden getrennte Buchungen.',
        'imp.dry'        => 'Probelauf',
        'imp.run'        => 'Importieren',
        'imp.confirm_title' => 'Jetzt importieren?',
        'imp.confirm_body'  => 'Bis zu {n} Buchungen werden in {url} angelegt. Bereits importierte Buchungen werden erkannt und übersprungen.',
        'imp.confirm_range' => 'Nur Buchungen vom {from} bis {to}.',
        'imp.confirm_limit' => 'Höchstens {n} Buchungen.',
        'imp.confirm_update'=> 'Gegenkonten bereits importierter Buchungen werden auf die aktuellen Regeln umgestellt.',
        'imp.confirm_note'  => 'Ein kompletter Import kann lange dauern. Du kannst die Seite schließen, der Import läuft weiter; abbrechen ist jederzeit möglich und ein erneuter Start macht dort weiter.',

        'exp.title'       => 'Aus Firefly exportieren',
        'exp.help'        => 'Schreibt alle Firefly-Buchungen als GnuCash-Buch. Importierte Konten bekommen ihre GnuCash-Struktur zurück.',
        'exp.uncompressed'=> 'Unkomprimiertes XML',
        'exp.run'         => 'Exportieren',
        'exp.download'    => 'Export herunterladen',
        'exp.compare'     => 'Mit hochgeladenem Buch vergleichen',
        'exp.compare_need_book' => 'Dafür muss ein GnuCash-Buch hochgeladen sein.',
        'exp.by_year'     => 'auch pro Jahr',
        'exp.info'        => 'Letzter Export: {time}, {size}',

        'pur.title'   => 'Importierte Daten löschen',
        'pur.help'    => 'Für Testimporte: löscht alle Buchungen mit dem Import-Tag in Firefly. Das lässt sich nicht rückgängig machen.',
        'pur.tag'     => 'Import-Tag',
        'pur.accounts'=> 'Auch die vom Import angelegten Konten, Gegenkonten und Kategorien löschen',
        'pur.accounts_help' => 'Konten, die es in Firefly schon vorher gab, bleiben erhalten.',
        'pur.preview' => 'Vorschau',
        'pur.run'     => 'Endgültig löschen …',
        'pur.word'    => 'LÖSCHEN',
        'pur.confirm_title'    => 'Importierte Daten löschen?',
        'pur.confirm_body'     => 'Alle Buchungen mit dem Tag „{tag}“ in {url} werden endgültig gelöscht.',
        'pur.confirm_accounts' => 'Außerdem die vom Import angelegten Konten, Gegenkonten und Kategorien.',

        'job.plan'          => 'Vorschau berechnen',
        'job.dryrun'        => 'Probelauf',
        'job.import'        => 'Import',
        'job.export'        => 'Export',
        'job.compare'       => 'Vergleich',
        'job.purge-preview' => 'Vorschau Löschen',
        'job.purge'         => 'Löschen',
        'job.cancel'        => 'Abbrechen',
        'job.cancel_title'  => 'Vorgang abbrechen?',
        'job.cancel_body'   => 'Ein abgebrochener Import lässt sich später einfach neu starten; er macht dort weiter, wo er aufgehört hat.',
        'job.close'         => 'Schließen',
        'job.download_log'  => 'Log herunterladen',
        'job.show_log'      => 'Ausgabe anzeigen',
        'job.running_for'   => 'läuft seit {time}',
        'job.finished'      => 'beendet {time} · Dauer {dur}',
        'job.progress'      => '{done} von {total} ({pct} %)',
        'job.eta'           => 'noch ca. {eta}',
        'job.done_ok'       => '{job}: fertig.',
        'job.done_diff'     => '{job}: Unterschiede gefunden.',
        'job.done_failed'   => '{job}: fehlgeschlagen – siehe Ausgabe.',
        'job.done_cancelled'=> '{job}: abgebrochen.',
        'state.running'     => 'läuft',
        'state.ok'          => 'fertig',
        'state.failed'      => 'Fehler',
        'state.lost'        => 'unerwartet beendet',
        'state.cancelled'   => 'abgebrochen',
        'state.differences' => 'Unterschiede',

        'dlg.cancel'    => 'Abbrechen',
        'dlg.type_word' => 'Zum Bestätigen „{word}“ eingeben:',

        'err.job_running'   => 'Es läuft schon ein Vorgang. Warte, bis er fertig ist, oder brich ihn ab.',
        'err.url'           => 'Bitte eine gültige Firefly-URL angeben (http:// oder https://).',
        'err.token'         => 'Bitte den Personal Access Token angeben.',
        'err.date'          => 'Datum bitte als JJJJ-MM-TT.',
        'err.limit'         => 'Die Anzahl muss mindestens 1 sein.',
        'err.no_file'       => 'Die Datei gibt es (noch) nicht.',
        'err.no_book'       => 'Lade zuerst ein GnuCash-Buch hoch.',
        'err.no_export'     => 'Es gibt noch keinen Export.',
        'err.csrf'          => 'Die Sitzung ist abgelaufen. Bitte die Seite neu laden.',
        'err.too_big'       => 'Die Datei ist zu groß (höchstens {max}).',
        'err.upload_partial'=> 'Die Datei kam nur teilweise an. Bitte noch einmal versuchen.',
        'err.upload_none'   => 'Es wurde keine Datei übertragen.',
        'err.upload_failed' => 'Hochladen fehlgeschlagen ({code}).',
        'err.not_gnucash'   => 'Das ist keine GnuCash-Datei (XML, komprimiertes XML oder SQLite).',
        'err.firefly'       => 'Firefly antwortet nicht wie erwartet: {msg}',
        'err.server'        => 'Serverfehler (HTTP {status}).',
        'err.network'       => 'Netzwerkfehler – ist der Server erreichbar?',
    ];
}

/** @return array<string, string> */
function textsEn(): array
{
    return [
        'app.sub'      => 'import, rules and export',
        'app.language' => 'Language',
        'app.steps'    => 'Steps',
        'app.footer'   => 'Unofficial tool, not part of the Firefly III project. The token is not stored on the server.',
        'app.problems' => 'The server setup is incomplete:',
        'tab.book'     => 'GnuCash book',
        'tab.payees'   => 'Counterparties & rules',
        'tab.accounts' => 'Accounts & options',
        'tab.firefly'  => 'Firefly III',

        'book.upload_title'  => 'Upload a GnuCash book',
        'book.upload_other'  => 'Upload another or newer file',
        'book.upload_anyway' => 'Upload anyway',
        'book.drop'          => 'Drop a GnuCash file here or click',
        'book.formats'       => 'XML (compressed or not) or SQLite, up to {max}. The file stays in your session and is deleted when it expires.',
        'book.more'          => 'More options',
        'book.keep'          => 'Keep the rules and the account mapping of the current book',
        'book.rules_file'    => 'Rules file (payee-rules.txt, optional)',
        'book.config_file'   => 'Account mapping (import.json, optional)',
        'book.more_help'     => 'If you already have rules or an account mapping from the command line, select them here before uploading the book.',
        'book.uploaded'      => 'Uploaded {time}',
        'book.uploaded_ok'   => 'Uploaded – the preview is being calculated.',
        'book.replan'        => 'Recalculate',
        'book.reset'         => 'Delete workspace',
        'book.reset_title'   => 'Delete the workspace?',
        'book.reset_body'    => 'The book, rules, account mapping, reports and logs of this session are deleted right away. Nothing changes in Firefly.',
        'book.stale'         => 'Rules or account mapping changed after the last calculation. Click “Recalculate”.',
        'book.planning'      => 'Calculating the preview …',
        'book.plan_failed'   => 'The calculation failed:',

        'sum.importable'  => 'importable transactions (of {total})',
        'sum.withdrawals' => 'withdrawals',
        'sum.deposits'    => 'deposits',
        'sum.transfers'   => 'transfers',
        'sum.payees'      => 'counterparties ({expense} expense, {revenue} revenue accounts)',
        'sum.ok'          => 'OK',
        'sum.failed'      => 'Error',
        'sum.checked'     => 'self-check: all amounts match ({n} transactions)',
        'sum.failed_help' => 'self-check failed – please report this as a bug',
        'sum.book'        => 'Book: {accounts} accounts, {transactions} transactions from {first} to {last}, book currency {currency}',
        'sum.mapping'     => 'Mapping: {asset} asset accounts, {liability} liabilities, {category} categories with transactions',
        'sum.multisource' => '{n} split transactions with several source accounts (need the multisource fork)',
        'sum.clearing'    => '{n} transactions without asset account go through the clearing account',
        'sum.opening'     => 'Accounts with opening balance: {n}',
        'sum.skipped'     => 'Skipped: {n} × {reason}',
        'sum.not_imported'=> 'Not imported (no Firefly equivalent): {list}',
        'sum.rules'       => '{rules} rules active, {sugg} merge suggestions',
        'sum.warnings'    => 'Warnings ({n})',
        'sum.warn_more'   => '… and {n} more',
        'sum.config_msgs' => 'Changes to the account mapping ({n})',
        'sum.top'         => 'Most used counterparties',
        'sum.downloads'   => 'Download files',

        'skip.uses account "…" which is mapped to "…"' => 'uses an account set to “skip”',
        'other.invoices/bills' => 'invoices/bills',

        'dl.config'       => 'Account mapping (import.json)',
        'dl.rules'        => 'Rules (payee-rules.txt)',
        'dl.payees'       => 'Counterparties (CSV)',
        'dl.map'          => 'Booking texts (CSV)',
        'dl.suggestions'  => 'Suggestions',
        'dl.planlog'      => 'Calculation output',
        'dl.drylog'       => 'Dry run log',
        'dl.importlog'    => 'Import log',
        'dl.importjsonl'  => 'Import record (JSONL)',

        'sub.payees'      => 'Counterparties',
        'sub.map'         => 'Booking texts',
        'sub.suggestions' => 'Suggestions',
        'sub.rules'       => 'Rules',
        'tbl.search'          => 'Search',
        'tbl.search_payees'   => 'Search counterparty, booking text, account, split memo or IBAN',
        'tbl.search_map'      => 'Search booking text, counterparty, GnuCash account or split memo',
        'det.head'            => '{n} transactions → {payee}',
        'det.head.one'        => '1 transaction → {payee}',
        'det.split'           => 'split transaction, {n} splits',
        'det.more'            => '… and {n} older transactions (searched as well)',
        'det.none'            => 'No details – please recalculate.',
        'det.loading'         => 'Loading details …',
        'det.acc_rule'        => 'Create a rule for all bookings of this account (konto:)',
        'det.memo_rule'       => 'Create a rule for bookings with this memo (memo:)',
        'tbl.side'            => 'Kind',
        'tbl.all_sides'       => 'expenses and revenues',
        'tbl.source'          => 'Source',
        'tbl.all_sources'     => 'any source',
        'tbl.more'            => 'Show more',
        'tbl.count'           => '{total} entries',
        'tbl.count.one'       => '1 entry',
        'tbl.count_part'      => '{shown} of {total} entries',
        'tbl.payee_filter'    => 'Counterparty: {name}',
        'tbl.payees_help'     => 'Click a row to see the booking texts of that counterparty. Names are changed with rules.',
        'col.payee'       => 'Counterparty',
        'col.side'        => 'Kind',
        'col.source'      => 'Source',
        'col.count'       => 'Transactions',
        'col.amount'      => 'Total',
        'col.period'      => 'Period',
        'col.categories'  => 'Categories',
        'col.examples'    => 'Example texts',
        'col.text'        => 'Booking text',
        'side.expense'    => 'expense account',
        'side.revenue'    => 'revenue account',
        'kind.expense'    => 'expense',
        'kind.revenue'    => 'revenue',
        'range.from'      => 'From',
        'range.to'        => 'To',
        'src.rule'        => 'rule',
        'src.auto'        => 'automatic',
        'src.fallback'    => 'fallback',
        'src.rule_line'   => 'rule (line {n})',
        'src.rule_fallback' => 'rule → fallback',

        'sub.assistant'   => 'Create rule',
        'asst.title'      => 'Create a rule',
        'asst.help'       => 'Type a text from your bookings, e.g. “DB Hamburg”. The matching rule and the booking texts it catches appear right away – before anything is saved.',
        'asst.query'      => 'Search text',
        'asst.query_ph'   => 'e.g. DB Hamburg',
        'asst.mode'       => 'Search',
        'asst.mode_words' => 'words in this order',
        'asst.mode_all'   => 'all words, any order',
        'asst.mode_start' => 'booking text starts with it',
        'asst.mode_text'  => 'contains the text (also inside words)',
        'asst.target'     => 'Counterparty in Firefly',
        'asst.target_ph'  => 'e.g. Deutsche Bahn',
        'asst.position'   => 'Insert',
        'asst.pos_end'    => 'after my rules',
        'asst.pos_top'    => 'before my rules (takes precedence)',
        'asst.name_only'      => 'Only in the name of the recipient (after the last “;”)',
        'asst.name_only_help' => 'For bank texts “purpose; name”: transfers to someone else that only mention the text in the purpose are left alone.',
        'asst.names'      => 'Use name:',
        'asst.rule'       => 'Rule',
        'asst.rule_custom'=> 'edited by hand',
        'asst.rule_reset' => 'Build from search text',
        'asst.line_label' => 'This line goes into your rules:',
        'asst.insert'     => 'Only into the editor',
        'asst.save'       => 'Adopt & recalculate',
        'asst.none'       => 'No booking text matches this rule.',
        'asst.only_changed'      => 'Hide unaffected bookings',
        'asst.only_changed_help' => 'Lists only booking texts whose counterparty the rule changes; unchanged texts, texts kept by earlier rules and similar texts are hidden.',
        'asst.summary'    => 'Catches {texts} booking texts with {bookings} transactions.',
        'asst.summary.one'=> 'Catches 1 booking text with {bookings} transactions.',
        'asst.changed'    => '{n} transactions get the counterparty “{target}”.',
        'asst.unchanged'  => 'They already have this counterparty.',
        'asst.kept'       => '{n} transactions keep their counterparty because an earlier rule matches. Choose “before my rules” to give this rule precedence.',
        'asst.from'       => 'So far:',
        'asst.existing'   => 'The counterparty “{name}” already exists ({n} transactions) – these are added to it.',
        'asst.need_target'=> 'Enter the name of the counterparty.',
        'asst.dup'        => 'This rule is already in your rules (line {n}).',
        'asst.dirty'      => 'The rules editor has unsaved changes; the preview shows the last calculated state.',
        'asst.matches'    => 'Booking texts caught',
        'asst.col_now'    => 'so far',
        'asst.col_new'    => 'new',
        'asst.kept_badge' => 'earlier rule',
        'asst.more'       => '… and {n} more booking texts.',
        'asst.similar'      => 'Similar, but not caught',
        'asst.similar_help' => 'These texts contain all search words but do not match the rule – e.g. in another order. Try another search.',
        'asst.inserted'   => 'Rule inserted – remember to save it in the “Rules” tab.',
        'asst.err_newline'=> 'Rule and counterparty must not contain a line break.',
        'asst.err_arrow'  => 'The counterparty must not contain “=>”.',
        'asst.note_konto'    => 'konto: checks all GnuCash accounts of the transaction, e.g. the cash or card account it was paid from.',
        'asst.note_category' => 'For category: rules the preview is approximate: a booking text counts when one of its categories matches.',
        'asst.open'       => 'Rule …',
        'asst.open_title' => 'Create a rule for this entry',
        'sugg.adjust'     => 'Adjust …',
        'sugg.help'       => 'Variants of the same counterparty found by the tool. “Adopt” appends the rule to your rules (then save in the “Rules” tab); “Adjust …” opens it for changes in “Create rule” with a preview.',
        'sugg.item'       => '{n} transactions: {names}',
        'sugg.adopt'      => 'Adopt',
        'sugg.added_label'=> 'Adopted',
        'sugg.added'      => 'Rule appended – remember to save it in the “Rules” tab.',

        'rules.title'        => 'Counterparty rules',
        'rules.help_title'   => 'How rules work',
        'rules.help_order'   => 'One rule per line: `pattern => counterparty`. The first matching rule wins. Lines starting with `#` are comments.',
        'rules.help_regex'   => 'regular expression on the booking text',
        'rules.help_text'    => 'text anywhere in the booking text (case-insensitive)',
        'rules.help_iban'    => 'all transactions with this IBAN',
        'rules.help_category'=> 'by category (GnuCash account)',
        'rules.help_memo'    => 'by the memo of a split',
        'rules.help_target'  => 'The right side may use `$1` … `$9`, `{category}` or `{description}`; `-` means the fallback. Example:',
        'rules.help_semicolon'=> 'To match only the name after the last `;` (not the purpose of a transfer to someone else), append `(?![^;]*;)` to the pattern.',
        'rules.help_comments'=> 'Saving checks the rules and recalculates the preview.',
        'ed.open'     => 'Open file …',
        'ed.download' => 'Download',
        'ed.revert'   => 'Discard',
        'ed.save'     => 'Save & recalculate',
        'ed.saved'    => 'Saved – the preview is being recalculated.',
        'ed.opened'   => '“{name}” loaded – not saved yet.',
        'ed.discard'  => 'Unsaved changes to the rules or the account mapping will be lost.',

        'acc.title'      => 'Account mapping',
        'acc.help'       => 'How each GnuCash account arrives in Firefly: asset account, liability, category, ignore (drop its splits) or skip (drop whole transactions). Same name = same Firefly account.',
        'acc.search'     => 'Search GnuCash account or name',
        'acc.kind'       => 'Filter',
        'acc.all'        => 'all accounts',
        'acc.bs'         => 'asset accounts & liabilities',
        'acc.categories' => 'categories',
        'acc.off'        => 'ignored / skipped',
        'acc.changed'    => 'changed',
        'acc.raw'        => 'Edit as JSON',
        'acc.structured' => 'Show table',
        'acc.raw_help'   => 'The complete import.json. Unknown fields are kept.',
        'acc.invalid_json'=> 'The import.json is not valid JSON.',
        'acc.col_gnucash'=> 'GnuCash account',
        'acc.col_splits' => 'Splits',
        'acc.col_as'     => 'In Firefly as',
        'acc.col_name'   => 'Name in Firefly',
        'acc.col_details'=> 'Details',
        'acc.col_active' => 'Active',
        'as.asset'       => 'asset account',
        'as.liability'   => 'liability',
        'as.category'    => 'category',
        'as.ignore'      => 'ignore',
        'as.skip'        => 'skip',
        'role.defaultAsset'   => 'default account',
        'role.savingAsset'    => 'savings account',
        'role.sharedAsset'    => 'shared account',
        'role.cashWalletAsset'=> 'cash wallet',
        'role.ccAsset'        => 'credit card',
        'liab.debt'      => 'debt',
        'liab.loan'      => 'loan',
        'liab.mortgage'  => 'mortgage',
        'dir.debit'      => 'I owe',
        'dir.credit'     => 'I am owed',
        'opt.title'      => 'Options',
        'opt.payee_min_count'     => 'Minimum transactions',
        'opt.payee_fallback'      => 'Fallback counterparty',
        'opt.payee_split_dash'    => 'Use the text before “ - ”',
        'opt.payee_merge_prefix'  => 'Cut off additions',
        'opt.payee_group_by_iban' => 'Merge by IBAN',
        'opt.payee_set_iban'      => 'Store IBAN on counterparty',
        'opt.category_names'      => 'Category names',
        'opt.reconciled_states'   => 'Reconciled states',
        'opt.opening_balances'    => 'Opening balances',
        'opt.clearing_account'    => 'Clearing account',
        'opt.import_tag'          => 'Import tag',
        'opt.multisource'         => 'Several source accounts',
        'opt.apply_rules'         => 'Run Firefly rules',
        'opt.fire_webhooks'       => 'Fire webhooks',
        'optd.payee_min_count'    => 'Automatic counterparties with fewer transactions go to the fallback.',
        'optd.payee_fallback'     => 'Name of the fallback, e.g. `(diverse)` or `{category}`.',
        'optd.payee_split_dash'   => '`Payee - details` becomes `Payee`.',
        'optd.payee_merge_prefix' => '`Name extra` becomes `Name` when `Name` occurs on its own.',
        'optd.payee_group_by_iban'=> 'Texts with the same counterparty IBAN become one counterparty (settlement IBANs of card issuers excluded).',
        'optd.payee_set_iban'     => 'Helps the Firefly Data Importer to match future bank transactions.',
        'optd.category_names'     => 'Only for new accounts: with or without the top-level account.',
        'optd.reconciled_states'  => 'GnuCash states that mean reconciled in Firefly, e.g. `y` or `yc`.',
        'optd.opening_balances'   => 'The first equity transaction of an account becomes its Firefly opening balance.',
        'optd.clearing_account'   => 'Technical account for re-bookings between income and expenses.',
        'optd.import_tag'         => 'Tag on every imported transaction; deleting uses it.',
        'optd.multisource'        => 'Split transactions with several source accounts as one transaction (needs the multisource fork).',
        'optd.apply_rules'        => 'Apply Firefly rules during the import.',
        'optd.fire_webhooks'      => 'Fire webhooks during the import.',
        'optv.strip-root'         => 'without top level',
        'optv.full-path'          => 'full path',

        'ff.connection' => 'Connection to Firefly III',
        'ff.url'        => 'Firefly URL',
        'ff.fixed'      => 'Set by the administrator',
        'ff.token'      => 'Personal Access Token',
        'ff.token_ph'   => 'Paste token',
        'ff.remember'   => 'Remember in this browser tab',
        'ff.test'       => 'Test connection',
        'ff.testing'    => 'Testing …',
        'ff.ok'         => 'Connected: Firefly III {version}, user {email}',
        'ff.token_help' => 'Create the token in Firefly under Options → Profile → OAuth → Personal Access Tokens. It is sent to the server only for the action you start and is not stored there.',

        'imp.title'      => 'Import into Firefly',
        'imp.need_book'  => 'Upload a GnuCash book first.',
        'imp.need_plan'  => 'The preview has not been calculated yet.',
        'imp.hint'       => '{n} transactions can be imported. Start with a dry run: it shows what would be created and changes nothing.',
        'imp.limit'      => 'At most … transactions',
        'imp.limit_ph'   => 'all',
        'imp.update_payees'      => 'Update counterparties of already imported transactions',
        'imp.update_payees_help' => 'After changing rules: moves already imported transactions to the new counterparties and deletes emptied ones. Counterparties you changed by hand in Firefly are set back.',
        'imp.no_multisource'     => 'Without multisource',
        'imp.no_multisource_help'=> 'For a regular Firefly without the fork: splits with several source accounts become separate transactions.',
        'imp.dry'        => 'Dry run',
        'imp.run'        => 'Import',
        'imp.confirm_title' => 'Import now?',
        'imp.confirm_body'  => 'Up to {n} transactions are created in {url}. Transactions that are already there are recognised and skipped.',
        'imp.confirm_range' => 'Only transactions from {from} to {to}.',
        'imp.confirm_limit' => 'At most {n} transactions.',
        'imp.confirm_update'=> 'Counterparties of already imported transactions are changed to the current rules.',
        'imp.confirm_note'  => 'A full import can take a while. You can close the page, the import keeps running; you can cancel at any time and a new start continues where it stopped.',

        'exp.title'       => 'Export from Firefly',
        'exp.help'        => 'Writes all Firefly transactions as a GnuCash book. Imported accounts get their GnuCash structure back.',
        'exp.uncompressed'=> 'Uncompressed XML',
        'exp.run'         => 'Export',
        'exp.download'    => 'Download export',
        'exp.compare'     => 'Compare with the uploaded book',
        'exp.compare_need_book' => 'Upload a GnuCash book for this.',
        'exp.by_year'     => 'also per year',
        'exp.info'        => 'Last export: {time}, {size}',

        'pur.title'   => 'Delete imported data',
        'pur.help'    => 'For test imports: deletes all transactions with the import tag in Firefly. This cannot be undone.',
        'pur.tag'     => 'Import tag',
        'pur.accounts'=> 'Also delete the accounts, counterparties and categories created by the import',
        'pur.accounts_help' => 'Accounts that existed in Firefly before are kept.',
        'pur.preview' => 'Preview',
        'pur.run'     => 'Delete permanently …',
        'pur.word'    => 'DELETE',
        'pur.confirm_title'    => 'Delete imported data?',
        'pur.confirm_body'     => 'All transactions with the tag “{tag}” in {url} are deleted permanently.',
        'pur.confirm_accounts' => 'Also the accounts, counterparties and categories created by the import.',

        'job.plan'          => 'Calculating the preview',
        'job.dryrun'        => 'Dry run',
        'job.import'        => 'Import',
        'job.export'        => 'Export',
        'job.compare'       => 'Comparison',
        'job.purge-preview' => 'Delete preview',
        'job.purge'         => 'Delete',
        'job.cancel'        => 'Cancel',
        'job.cancel_title'  => 'Cancel?',
        'job.cancel_body'   => 'A cancelled import can simply be started again later; it continues where it stopped.',
        'job.close'         => 'Close',
        'job.download_log'  => 'Download log',
        'job.show_log'      => 'Show output',
        'job.running_for'   => 'running for {time}',
        'job.finished'      => 'finished {time} · took {dur}',
        'job.progress'      => '{done} of {total} ({pct} %)',
        'job.eta'           => 'about {eta} left',
        'job.done_ok'       => '{job}: done.',
        'job.done_diff'     => '{job}: differences found.',
        'job.done_failed'   => '{job}: failed – see the output.',
        'job.done_cancelled'=> '{job}: cancelled.',
        'state.running'     => 'running',
        'state.ok'          => 'done',
        'state.failed'      => 'error',
        'state.lost'        => 'ended unexpectedly',
        'state.cancelled'   => 'cancelled',
        'state.differences' => 'differences',

        'dlg.cancel'    => 'Cancel',
        'dlg.type_word' => 'Type “{word}” to confirm:',

        'err.job_running'   => 'Another action is running. Wait for it to finish or cancel it.',
        'err.url'           => 'Please enter a valid Firefly URL (http:// or https://).',
        'err.token'         => 'Please enter the Personal Access Token.',
        'err.date'          => 'Please enter dates as YYYY-MM-DD.',
        'err.limit'         => 'The number must be at least 1.',
        'err.no_file'       => 'This file does not exist (yet).',
        'err.no_book'       => 'Upload a GnuCash book first.',
        'err.no_export'     => 'There is no export yet.',
        'err.csrf'          => 'The session has expired. Please reload the page.',
        'err.too_big'       => 'The file is too large (at most {max}).',
        'err.upload_partial'=> 'The file arrived only partially. Please try again.',
        'err.upload_none'   => 'No file was sent.',
        'err.upload_failed' => 'Upload failed ({code}).',
        'err.not_gnucash'   => 'This is not a GnuCash file (XML, compressed XML or SQLite).',
        'err.firefly'       => 'Firefly did not answer as expected: {msg}',
        'err.server'        => 'Server error (HTTP {status}).',
        'err.network'       => 'Network error – is the server reachable?',
    ];
}

// =====================================================================================
// Page
// =====================================================================================

function h(string $s): string
{
    return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function errorPage(string $message, string $nonce): void
{
    http_response_code(500);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>GnuCash ⇄ Firefly III</title>',
        '<style nonce="', h($nonce), '">body{font:15px/1.5 system-ui,sans-serif;margin:3rem auto;max-width:40rem;padding:0 1rem;color:#1b1f24;background:#f6f7f9}',
        '@media (prefers-color-scheme:dark){body{color:#e6e9ee;background:#0f1216}}code{font-family:ui-monospace,monospace}</style></head><body>',
        '<h1>GnuCash ⇄ Firefly III</h1><p>', h($message), '</p></body></html>';
}

function page(Workspace $w, string $csrf, string $nonce): void
{
    gc();
    $lang = lang();
    $boot = [
        'csrf'       => $csrf,
        'lang'       => $lang,
        'texts'      => texts($lang),
        'fixedUrl'   => trim((string) config()['firefly_url']),
        'maxUpload'  => maxUpload(),
        'version'    => toolVersion(),
        'problems'   => setupProblems(),
        'cookiePath' => cookiePath(),
        'secure'     => isHttps(),
    ];
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    $n = h($nonce);
    $L = static fn (string $k): string => (string) preg_replace('/`([^`]+)`/', '<code>$1</code>', h(t($k))); ?>
<!doctype html>
<html lang="<?= $lang ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light dark">
<title>GnuCash ⇄ Firefly III</title>
<link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='7' fill='%232f6fdb'/%3E%3Cpath d='M8 12h13l-4-4M24 20H11l4 4' fill='none' stroke='white' stroke-width='2.6' stroke-linecap='round' stroke-linejoin='round'/%3E%3C/svg%3E">
<style nonce="<?= $n ?>">
:root {
  --bg: #f5f6f8; --panel: #ffffff; --panel-2: #f9fafb; --text: #1b1f24; --muted: #5d6673; --faint: #8a939e;
  --border: #dfe3e8; --border-strong: #c9cfd6; --accent: #2f6fdb; --accent-hover: #2159ba; --accent-soft: #e8f0fd; --on-accent: #ffffff;
  --ok: #1d7f46; --ok-soft: #e5f4eb; --warn: #9a6100; --warn-soft: #fdf3dc; --bad: #c0362c; --bad-soft: #fbe9e7;
  --shadow: 0 1px 2px rgba(20, 30, 45, .06), 0 2px 8px rgba(20, 30, 45, .04);
  --mono: ui-monospace, SFMono-Regular, Menlo, Consolas, "Liberation Mono", monospace;
  --radius: 10px;
}
@media (prefers-color-scheme: dark) {
  :root {
    --bg: #0e1116; --panel: #161a21; --panel-2: #1b2029; --text: #e5e8ed; --muted: #a0a9b5; --faint: #6f7884;
    --border: #2a3039; --border-strong: #3a424d; --accent: #6b9dfc; --accent-hover: #8ab2ff; --accent-soft: #1c2a44; --on-accent: #0b1220;
    --ok: #53c98a; --ok-soft: #13291d; --warn: #e3b457; --warn-soft: #2e2511; --bad: #ff8577; --bad-soft: #321614;
    --shadow: none;
  }
}
* { box-sizing: border-box; }
html { -webkit-text-size-adjust: 100%; }
body { margin: 0; background: var(--bg); color: var(--text); font: 15px/1.5 system-ui, -apple-system, "Segoe UI", Roboto, "Helvetica Neue", Arial, sans-serif; }
a { color: var(--accent); }
a:hover { color: var(--accent-hover); }
h1, h2, h3 { line-height: 1.25; margin: 0; }
h2 { font-size: 1.08rem; font-weight: 650; }
h3 { font-size: .95rem; font-weight: 650; }
p { margin: .4rem 0; }
code, pre, textarea.code { font-family: var(--mono); font-size: 13px; }
[hidden] { display: none !important; }
.muted { color: var(--muted); }
.small { font-size: 13px; }
.nowrap { white-space: nowrap; }

header.top { background: var(--panel); border-bottom: 1px solid var(--border); }
header.top .in { max-width: 1180px; margin: 0 auto; padding: .7rem 16px; display: flex; align-items: center; gap: 1rem; }
.brand { display: flex; align-items: center; gap: .6rem; font-weight: 700; font-size: 1.05rem; }
.brand svg { width: 28px; height: 28px; flex: none; }
.brand .sub { font-weight: 400; color: var(--muted); font-size: 13px; }
.spacer { flex: 1; }
.seg { display: inline-flex; border: 1px solid var(--border-strong); border-radius: 8px; overflow: hidden; }
.seg button { border: 0; background: transparent; color: var(--muted); padding: .25rem .6rem; font: inherit; font-size: 13px; cursor: pointer; }
.seg button[aria-pressed="true"] { background: var(--accent-soft); color: var(--accent); font-weight: 650; }

main { max-width: 1180px; margin: 0 auto; padding: 1rem 16px 3rem; }
.tabs { display: flex; gap: .4rem; flex-wrap: wrap; margin: .4rem 0 1rem; }
.tabs button { display: inline-flex; align-items: center; gap: .5rem; border: 1px solid var(--border); background: var(--panel); color: var(--text); border-radius: 999px; padding: .4rem .9rem .4rem .45rem; font: inherit; font-size: 14px; cursor: pointer; }
.tabs button .num { display: inline-grid; place-items: center; width: 1.45rem; height: 1.45rem; border-radius: 50%; background: var(--panel-2); border: 1px solid var(--border); font-size: 12px; font-weight: 700; color: var(--muted); }
.tabs button[aria-selected="true"] { border-color: var(--accent); background: var(--accent-soft); color: var(--accent); font-weight: 650; }
.tabs button[aria-selected="true"] .num { background: var(--accent); border-color: var(--accent); color: var(--on-accent); }
.tabs button:disabled { opacity: .45; cursor: not-allowed; }
.subtabs { display: flex; gap: 0; border-bottom: 1px solid var(--border); margin-bottom: 1rem; overflow-x: auto; }
.subtabs button { border: 0; border-bottom: 2px solid transparent; background: none; color: var(--muted); padding: .5rem .9rem; font: inherit; font-size: 14px; cursor: pointer; white-space: nowrap; }
.subtabs button[aria-selected="true"] { color: var(--text); border-bottom-color: var(--accent); font-weight: 650; }
.badge { display: inline-block; padding: 0 .45rem; border-radius: 999px; font-size: 12px; background: var(--panel-2); border: 1px solid var(--border); color: var(--muted); font-weight: 600; }
.badge.ok { background: var(--ok-soft); color: var(--ok); border-color: transparent; }
.badge.warn { background: var(--warn-soft); color: var(--warn); border-color: transparent; }
.badge.bad { background: var(--bad-soft); color: var(--bad); border-color: transparent; }
.badge.accent { background: var(--accent-soft); color: var(--accent); border-color: transparent; }

.card { background: var(--panel); border: 1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow); padding: 1rem 1.1rem; margin-bottom: 1rem; }
.card > header { display: flex; align-items: center; gap: .6rem; flex-wrap: wrap; margin-bottom: .6rem; }
.card.danger { border-color: color-mix(in srgb, var(--bad) 45%, var(--border)); }
.grid2 { display: grid; grid-template-columns: repeat(auto-fit, minmax(320px, 1fr)); gap: 1rem; }
.grid2 > .card { margin-bottom: 0; }
.stack > * + * { margin-top: .6rem; }
.row { display: flex; gap: .6rem; align-items: center; flex-wrap: wrap; }
.row.end { justify-content: flex-end; }

.btn { display: inline-flex; align-items: center; justify-content: center; gap: .4rem; border: 1px solid var(--border-strong); background: var(--panel); color: var(--text); border-radius: 8px; padding: .45rem .9rem; font: inherit; font-size: 14px; font-weight: 550; cursor: pointer; text-decoration: none; white-space: nowrap; }
.btn:hover { border-color: var(--accent); color: var(--accent); }
.btn.primary { background: var(--accent); border-color: var(--accent); color: var(--on-accent); }
.btn.primary:hover { background: var(--accent-hover); border-color: var(--accent-hover); color: var(--on-accent); }
.btn.danger { color: var(--bad); border-color: color-mix(in srgb, var(--bad) 55%, var(--border)); }
.btn.danger.solid { background: var(--bad); border-color: var(--bad); color: #fff; }
.btn.small { padding: .2rem .55rem; font-size: 13px; }
.btn:disabled, .btn[aria-disabled="true"] { opacity: .45; cursor: not-allowed; pointer-events: none; }
button:focus-visible, a:focus-visible, input:focus-visible, select:focus-visible, textarea:focus-visible, summary:focus-visible { outline: 2px solid var(--accent); outline-offset: 2px; }

label.field { display: flex; flex-direction: column; gap: .2rem; font-size: 13px; color: var(--muted); font-weight: 550; }
label.field input, label.field select, label.field textarea { font-weight: 400; }
label.field .badge { align-self: flex-start; }
input[type=text], input[type=url], input[type=password], input[type=number], input[type=date], input[type=search], select, textarea {
  font: inherit; font-size: 14px; color: var(--text); background: var(--panel); border: 1px solid var(--border-strong); border-radius: 7px; padding: .4rem .55rem; min-width: 0; }
input[readonly] { background: var(--panel-2); color: var(--muted); }
textarea.code { width: 100%; min-height: 420px; resize: vertical; line-height: 1.45; white-space: pre; tab-size: 4; padding: .6rem .7rem; }
.check { display: flex; gap: .5rem; align-items: flex-start; font-size: 14px; color: var(--text); font-weight: 400; }
.check input { margin-top: .25rem; }
.fields { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: .7rem; }

.drop { display: block; border: 2px dashed var(--border-strong); border-radius: var(--radius); padding: 2rem 1rem; text-align: center; background: var(--panel-2); cursor: pointer; transition: border-color .15s, background .15s; }
.drop:hover, .drop.over, .drop:focus-visible { border-color: var(--accent); background: var(--accent-soft); }
.drop strong { display: block; font-size: 1.05rem; margin-bottom: .2rem; }
.drop-icon { width: 34px; height: 34px; color: var(--accent); display: block; margin: 0 auto .5rem; }
section.has-book .drop-icon { display: none; }
#upload-more { margin-top: .8rem; }
.drop input { display: none; }
.progress { height: 8px; border-radius: 999px; background: var(--panel-2); border: 1px solid var(--border); overflow: hidden; }
.progress > div { height: 100%; width: 0; background: var(--accent); transition: width .3s; }
.progress.indet > div { width: 30%; animation: indet 1.3s ease-in-out infinite; }
@keyframes indet { 0% { margin-left: -30%; } 100% { margin-left: 100%; } }
@media (prefers-reduced-motion: reduce) { .progress.indet > div { animation: none; width: 100%; opacity: .5; } }

.stats { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr)); gap: .7rem; margin: .4rem 0 .8rem; }
.stat { border: 1px solid var(--border); border-radius: 9px; padding: .65rem .8rem; background: var(--panel-2); }
.stat .v { font-size: 1.45rem; font-weight: 700; font-variant-numeric: tabular-nums; line-height: 1.2; }
.stat .l { font-size: 13px; color: var(--muted); }
.stat.ok .v { color: var(--ok); }
.stat.bad .v { color: var(--bad); }
ul.facts { margin: .2rem 0; padding-left: 1.1rem; }
ul.facts li { margin: .15rem 0; }
.chips { display: flex; flex-wrap: wrap; gap: .35rem; }
.chip { display: inline-flex; gap: .35rem; align-items: center; border: 1px solid var(--border); background: var(--panel-2); border-radius: 999px; padding: .1rem .55rem; font-size: 13px; cursor: pointer; color: var(--text); font: inherit; font-size: 13px; }
.chip:hover { border-color: var(--accent); }
.chip b { font-variant-numeric: tabular-nums; color: var(--muted); font-weight: 600; }
.downloads { display: flex; flex-wrap: wrap; gap: .4rem; }

.tablewrap { overflow: auto; border: 1px solid var(--border); border-radius: 9px; max-height: 70vh; background: var(--panel); }
table { border-collapse: collapse; width: 100%; font-size: 13px; }
th, td { text-align: left; padding: .38rem .55rem; border-bottom: 1px solid var(--border); vertical-align: top; }
th { position: sticky; top: 0; background: var(--panel-2); z-index: 1; font-weight: 650; color: var(--muted); white-space: nowrap; cursor: pointer; user-select: none; }
th.sorted::after { content: " ▾"; }
th.sorted.asc::after { content: " ▴"; }
td.num, th.num { text-align: right; font-variant-numeric: tabular-nums; }
tbody tr:hover { background: var(--accent-soft); }
tbody tr.click { cursor: pointer; }
td .ex { color: var(--muted); max-width: 42ch; overflow: hidden; text-overflow: ellipsis; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; }
td.text { min-width: 16ch; max-width: 46ch; overflow-wrap: break-word; }
.hov { cursor: help; text-decoration: underline dotted var(--muted); text-underline-offset: 3px; }
.hov:focus-visible { outline: 2px solid var(--accent, #2563eb); outline-offset: 2px; border-radius: 3px; }
#tip { position: absolute; z-index: 60; width: max-content; max-width: min(680px, calc(100vw - 32px)); max-height: 60vh; overflow: auto;
  background: var(--panel, #fff); color: var(--text, inherit); border: 1px solid var(--border); border-radius: 8px; box-shadow: 0 8px 28px rgba(0,0,0,.18); padding: .6rem .75rem; font-size: 13px; }
#tip .tip-h { font-weight: 650; margin-bottom: .35rem; }
#tip .tip-tx { border-top: 1px solid var(--border); padding: .4rem 0 .2rem; }
#tip .tip-meta { display: flex; flex-wrap: wrap; gap: .25rem .5rem; align-items: baseline; }
#tip .tip-meta .d { font-variant-numeric: tabular-nums; color: var(--muted); }
#tip table { border-collapse: collapse; margin-top: .2rem; width: 100%; }
#tip td { padding: .1rem .5rem .1rem 0; vertical-align: top; border: 0; }
#tip td.num { text-align: right; white-space: nowrap; font-variant-numeric: tabular-nums; }
#tip td.acc { white-space: nowrap; }
#tip .linkish { background: none; border: 0; padding: 0; font: inherit; color: inherit; cursor: pointer; text-align: left; }
#tip .linkish:hover, #tip .linkish:focus-visible { text-decoration: underline; color: var(--accent, #2563eb); }
#tip tr.hit td.acc { font-weight: 650; }
#tip td.memo { color: var(--muted); overflow-wrap: anywhere; min-width: 14ch; }
.badge:empty { display: none; }
tr.changed td { background: color-mix(in srgb, var(--warn-soft) 70%, transparent); }
.tabletools { display: flex; gap: .6rem; flex-wrap: wrap; align-items: center; margin-bottom: .6rem; }
.tabletools input[type=search] { flex: 1 1 260px; }
.more { text-align: center; padding: .6rem; }

.job { border-left: 4px solid var(--accent); }
.job.ok { border-left-color: var(--ok); }
.job.bad { border-left-color: var(--bad); }
.job .meta { font-size: 13px; color: var(--muted); }
pre.log { margin: .6rem 0 0; background: var(--panel-2); border: 1px solid var(--border); border-radius: 8px; padding: .6rem .7rem; max-height: 340px; overflow: auto; white-space: pre-wrap; overflow-wrap: anywhere; }
pre.log .w { color: var(--warn); }
pre.log .e { color: var(--bad); font-weight: 600; }
pre.log .s { color: var(--accent); font-weight: 600; }
details > summary { cursor: pointer; color: var(--muted); font-size: 14px; }
.warnlist details > summary { font-size: inherit; color: inherit; }
.warnlist ol { margin: .3rem 0 .5rem; padding-left: 2.2rem; max-height: 40vh; overflow: auto; overflow-wrap: anywhere; }
details[open] > summary { margin-bottom: .5rem; }

.sugg { border: 1px solid var(--border); border-radius: 9px; padding: .55rem .7rem; display: flex; gap: .8rem; align-items: center; background: var(--panel); }
.sugg + .sugg { margin-top: .5rem; }
.sugg .body { flex: 1; min-width: 0; }
.sugg code { display: block; overflow-x: auto; white-space: pre; color: var(--text); }
.sugg .names { font-size: 13px; color: var(--muted); overflow-wrap: anywhere; }
.sugg.added { opacity: .55; }
.help { font-size: 13px; color: var(--muted); }
.help code { background: var(--panel-2); border: 1px solid var(--border); border-radius: 5px; padding: 0 .3rem; font-size: 12.5px; }
.help table td { border: 0; padding: .1rem .5rem .1rem 0; }
.asst-grid { display: grid; grid-template-columns: minmax(170px, 1.5fr) minmax(250px, 1.3fr) minmax(190px, 1.5fr) minmax(260px, 1.2fr); gap: .7rem; }
.chip i { font-style: normal; color: var(--faint); font-size: 12px; }
@media (max-width: 900px) { .asst-grid { grid-template-columns: 1fr 1fr; } }
@media (max-width: 560px) { .asst-grid { grid-template-columns: 1fr; } }
input.code { font-family: var(--mono); font-size: 13px; }
.grow { flex: 1 1 auto; }
pre.rule-line { margin: 0; background: var(--panel-2); border: 1px solid var(--border); border-radius: 8px; padding: .5rem .7rem; white-space: pre-wrap; overflow-wrap: anywhere; min-height: 2.3rem; }
pre.rule-line:empty { display: none; }
td.arrow { color: var(--faint); padding-left: 0; padding-right: 0; }
td.new { font-weight: 600; }
tr.same td { color: var(--muted); }
tr.same td.new { font-weight: 400; }
.summary-line { margin: .25rem 0; }
.sr-only { position: absolute; width: 1px; height: 1px; padding: 0; margin: -1px; overflow: hidden; clip: rect(0 0 0 0); white-space: nowrap; border: 0; }
td.act { width: 1%; white-space: nowrap; }
.editor-grid { display: grid; grid-template-columns: minmax(0, 1fr) 320px; gap: 1rem; align-items: start; }
@media (max-width: 900px) { .editor-grid { grid-template-columns: 1fr; } }
.dirty-dot { width: .55rem; height: .55rem; border-radius: 50%; background: var(--warn); display: inline-block; }
.notice { border-radius: 9px; padding: .6rem .8rem; font-size: 14px; border: 1px solid var(--border); background: var(--panel-2); }
.notice.warn { background: var(--warn-soft); border-color: transparent; color: var(--text); }
.notice.bad { background: var(--bad-soft); border-color: transparent; color: var(--text); }
.notice.ok { background: var(--ok-soft); border-color: transparent; color: var(--text); }
.conn-state { font-size: 14px; }
.opt { display: grid; grid-template-columns: minmax(180px, 260px) 1fr; gap: .3rem 1rem; align-items: center; padding: .45rem 0; border-bottom: 1px solid var(--border); }
.opt:last-child { border-bottom: 0; }
.opt .d { font-size: 13px; color: var(--muted); grid-column: 2; }
.opt .k { font-family: var(--mono); font-size: 13px; }
@media (max-width: 640px) { .opt { grid-template-columns: 1fr; } .opt .d { grid-column: 1; } }
td select, td input[type=text] { font-size: 13px; padding: .2rem .35rem; }
td input[type=text] { width: 100%; min-width: 12ch; }

dialog { border: 1px solid var(--border); border-radius: 12px; background: var(--panel); color: var(--text); padding: 0; width: min(520px, calc(100vw - 32px)); box-shadow: 0 10px 40px rgba(0, 0, 0, .25); }
dialog::backdrop { background: rgba(10, 14, 20, .45); }
dialog .dh { padding: 1rem 1.1rem .2rem; }
dialog .db { padding: .4rem 1.1rem 1rem; }
dialog .df { padding: .8rem 1.1rem; border-top: 1px solid var(--border); display: flex; gap: .5rem; justify-content: flex-end; background: var(--panel-2); border-radius: 0 0 12px 12px; }
#toasts { position: fixed; right: 16px; bottom: 16px; display: flex; flex-direction: column; gap: .5rem; z-index: 50; max-width: min(420px, calc(100vw - 32px)); }
.toast { background: var(--text); color: var(--bg); border-radius: 9px; padding: .6rem .8rem; font-size: 14px; box-shadow: 0 4px 20px rgba(0, 0, 0, .25); }
.toast.bad { background: var(--bad); color: #fff; }
.mt1 { margin-top: .6rem; }
.mt2 { margin-top: .7rem; }
.mt3 { margin-top: 1.2rem; }
.mt4 { margin-top: .9rem; }
.mt5 { margin-top: 1rem; }
section[data-panel="book"] { display: flex; flex-direction: column; }
section.has-book #upload-card { order: 2; }
section.has-book .drop { padding: 1.1rem 1rem; }
footer.foot { max-width: 1180px; margin: 0 auto; padding: 0 16px 2rem; color: var(--faint); font-size: 12.5px; }
footer.foot a { color: var(--muted); }
@media (max-width: 640px) {
  .card { padding: .85rem .8rem; }
  .brand .sub { display: none; }
  textarea.code { min-height: 300px; }
}
</style>
</head>
<body>
<header class="top"><div class="in">
  <div class="brand">
    <svg viewBox="0 0 32 32" aria-hidden="true"><rect width="32" height="32" rx="7" fill="var(--accent)"/><path d="M8 12h13l-4-4M24 20H11l4 4" fill="none" stroke="var(--on-accent)" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round"/></svg>
    <span>GnuCash ⇄ Firefly III <span class="sub"><?= $L('app.sub') ?></span></span>
  </div>
  <div class="spacer"></div>
  <div class="seg" role="group" aria-label="<?= $L('app.language') ?>">
    <button type="button" data-lang="de" aria-pressed="<?= 'de' === $lang ? 'true' : 'false' ?>">DE</button>
    <button type="button" data-lang="en" aria-pressed="<?= 'en' === $lang ? 'true' : 'false' ?>">EN</button>
  </div>
</div></header>

<main>
  <div id="problems" class="notice bad" hidden></div>

  <nav class="tabs" role="tablist" aria-label="<?= $L('app.steps') ?>">
    <button type="button" role="tab" data-tab="book" aria-selected="true"><span class="num">1</span><?= $L('tab.book') ?></button>
    <button type="button" role="tab" data-tab="payees" aria-selected="false"><span class="num">2</span><?= $L('tab.payees') ?></button>
    <button type="button" role="tab" data-tab="accounts" aria-selected="false"><span class="num">3</span><?= $L('tab.accounts') ?></button>
    <button type="button" role="tab" data-tab="firefly" aria-selected="false"><span class="num">4</span><?= $L('tab.firefly') ?></button>
  </nav>

  <section id="job" class="card job" hidden aria-live="polite">
    <header>
      <h2 id="job-title"></h2><span id="job-badge" class="badge"></span>
      <div class="spacer"></div>
      <span id="job-time" class="meta"></span>
      <button type="button" class="btn small danger" id="job-cancel"><?= $L('job.cancel') ?></button>
      <a class="btn small" id="job-log-dl" href="#"><?= $L('job.download_log') ?></a>
      <button type="button" class="btn small" id="job-close" aria-label="<?= $L('job.close') ?>">✕</button>
    </header>
    <div class="progress" id="job-progress"><div></div></div>
    <p class="meta" id="job-phase"></p>
    <details id="job-details"><summary><?= $L('job.show_log') ?></summary><pre class="log" id="job-log"></pre></details>
  </section>

  <!-- 1: book -->
  <section data-panel="book">
    <div class="card" id="upload-card">
      <header><h2 id="upload-title"><?= $L('book.upload_title') ?></h2></header>
      <label class="drop" id="drop" tabindex="0" role="button" aria-describedby="drop-hint">
        <input type="file" id="book-file" accept=".gnucash,.gz,.xml,.sqlite,.sqlite3,.db,application/gzip,application/xml">
        <svg class="drop-icon" viewBox="0 0 24 24" aria-hidden="true"><path d="M12 16V4m0 0L7 9m5-5 5 5M5 15v3a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2v-3" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>
        <strong><?= $L('book.drop') ?></strong>
        <span class="muted small" id="drop-hint"></span>
      </label>
      <div class="progress" id="upload-progress" hidden><div></div></div>
      <details class="small" id="upload-more">
        <summary><?= $L('book.more') ?></summary>
        <div class="stack">
          <label class="check" id="keep-row"><input type="checkbox" id="keep" checked> <span><?= $L('book.keep') ?></span></label>
          <div class="fields">
            <label class="field"><?= $L('book.rules_file') ?><input type="file" id="rules-file" accept=".txt,text/plain"></label>
            <label class="field"><?= $L('book.config_file') ?><input type="file" id="config-file" accept=".json,application/json"></label>
          </div>
          <p class="help"><?= $L('book.more_help') ?></p>
        </div>
      </details>
    </div>

    <div id="book-card" class="card" hidden>
      <header>
        <h2 id="book-name"></h2><span class="badge" id="book-size"></span>
        <div class="spacer"></div>
        <button type="button" class="btn small" id="replan"><?= $L('book.replan') ?></button>
        <button type="button" class="btn small danger" id="reset"><?= $L('book.reset') ?></button>
      </header>
      <p class="muted small" id="book-info"></p>
      <div id="stale" class="notice warn" hidden><?= $L('book.stale') ?></div>
      <div id="summary"></div>
    </div>
  </section>

  <!-- 2: counterparties and rules -->
  <section data-panel="payees" hidden>
    <div class="subtabs" role="tablist">
      <button type="button" role="tab" data-sub="payees" aria-selected="true"><?= $L('sub.payees') ?> <span class="badge" id="n-payees"></span></button>
      <button type="button" role="tab" data-sub="map" aria-selected="false"><?= $L('sub.map') ?> <span class="badge" id="n-map"></span></button>
      <button type="button" role="tab" data-sub="suggestions" aria-selected="false"><?= $L('sub.suggestions') ?> <span class="badge" id="n-sugg"></span></button>
      <button type="button" role="tab" data-sub="assistant" aria-selected="false"><?= $L('sub.assistant') ?></button>
      <button type="button" role="tab" data-sub="rules" aria-selected="false"><?= $L('sub.rules') ?> <span class="dirty-dot" id="rules-dirty" hidden></span></button>
    </div>

    <div data-subpanel="payees">
      <div class="tabletools">
        <input type="search" id="payees-q" placeholder="<?= $L('tbl.search_payees') ?>" aria-label="<?= $L('tbl.search') ?>">
        <select id="payees-side" aria-label="<?= $L('tbl.side') ?>"><option value=""><?= $L('tbl.all_sides') ?></option><option value="expense"><?= $L('side.expense') ?></option><option value="revenue"><?= $L('side.revenue') ?></option></select>
        <select id="payees-src" aria-label="<?= $L('tbl.source') ?>"><option value=""><?= $L('tbl.all_sources') ?></option><option value="rule"><?= $L('src.rule') ?></option><option value="auto"><?= $L('src.auto') ?></option><option value="fallback"><?= $L('src.fallback') ?></option></select>
        <span class="muted small" id="payees-count"></span>
      </div>
      <p class="help"><?= $L('tbl.payees_help') ?></p>
      <div class="tablewrap"><table id="payees-table"></table></div>
      <div class="more" id="payees-more" hidden><button type="button" class="btn small"><?= $L('tbl.more') ?></button></div>
    </div>

    <div data-subpanel="map" hidden>
      <div class="tabletools">
        <input type="search" id="map-q" placeholder="<?= $L('tbl.search_map') ?>" aria-label="<?= $L('tbl.search') ?>">
        <select id="map-side" aria-label="<?= $L('tbl.side') ?>"><option value=""><?= $L('tbl.all_sides') ?></option><option value="expense"><?= $L('side.expense') ?></option><option value="revenue"><?= $L('side.revenue') ?></option></select>
        <select id="map-src" aria-label="<?= $L('tbl.source') ?>"><option value=""><?= $L('tbl.all_sources') ?></option><option value="rule"><?= $L('src.rule') ?></option><option value="auto"><?= $L('src.auto') ?></option><option value="fallback"><?= $L('src.fallback') ?></option></select>
        <span id="map-payee" hidden><button type="button" class="chip" id="map-payee-clear"></button></span>
        <span class="muted small" id="map-count"></span>
      </div>
      <div class="tablewrap"><table id="map-table"></table></div>
      <div class="more" id="map-more" hidden><button type="button" class="btn small"><?= $L('tbl.more') ?></button></div>
    </div>

    <div data-subpanel="suggestions" hidden>
      <p class="help"><?= $L('sugg.help') ?></p>
      <div class="tabletools"><input type="search" id="sugg-q" placeholder="<?= $L('tbl.search') ?>" aria-label="<?= $L('tbl.search') ?>"><span class="muted small" id="sugg-count"></span></div>
      <div id="sugg-list"></div>
      <div class="more" id="sugg-more" hidden><button type="button" class="btn small"><?= $L('tbl.more') ?></button></div>
    </div>

    <div data-subpanel="assistant" hidden>
      <div class="card" id="asst">
        <header><h2><?= $L('asst.title') ?></h2></header>
        <p class="help"><?= $L('asst.help') ?></p>
        <div class="asst-grid">
          <label class="field"><?= $L('asst.query') ?><input type="search" id="as-q" placeholder="<?= $L('asst.query_ph') ?>" autocomplete="off" spellcheck="false"></label>
          <label class="field"><?= $L('asst.mode') ?>
            <select id="as-mode">
              <option value="words"><?= $L('asst.mode_words') ?></option>
              <option value="all"><?= $L('asst.mode_all') ?></option>
              <option value="start"><?= $L('asst.mode_start') ?></option>
              <option value="text"><?= $L('asst.mode_text') ?></option>
            </select>
          </label>
          <label class="field"><?= $L('asst.target') ?><input type="text" id="as-target" list="payee-names" placeholder="<?= $L('asst.target_ph') ?>" autocomplete="off" spellcheck="false"></label>
          <label class="field"><?= $L('asst.position') ?>
            <select id="as-pos"><option value="end"><?= $L('asst.pos_end') ?></option><option value="top"><?= $L('asst.pos_top') ?></option></select>
          </label>
        </div>
        <label class="check mt2"><input type="checkbox" id="as-name"> <span><?= $L('asst.name_only') ?><br><span class="help"><?= $L('asst.name_only_help') ?></span></span></label>
        <label class="check mt2"><input type="checkbox" id="as-only"> <span><?= $L('asst.only_changed') ?><br><span class="help"><?= $L('asst.only_changed_help') ?></span></span></label>
        <div class="chips mt1" id="as-names"></div>
        <div class="mt2">
          <label class="field" for="as-rule"><?= $L('asst.rule') ?> <span class="badge" id="as-custom" hidden><?= $L('asst.rule_custom') ?></span></label>
          <div class="row">
            <input type="text" class="code grow" id="as-rule" spellcheck="false" autocomplete="off" aria-label="<?= $L('asst.rule') ?>">
            <button type="button" class="btn small" id="as-rule-reset" hidden><?= $L('asst.rule_reset') ?></button>
          </div>
        </div>
        <p class="muted small mt1" id="as-line-label" hidden><?= $L('asst.line_label') ?></p>
        <pre class="rule-line" id="as-line" aria-live="polite"></pre>
        <div id="as-error" class="notice bad mt1" hidden></div>
        <div id="as-notes" class="notice warn mt1" hidden></div>
        <div id="as-summary" class="mt1"></div>
        <div class="row end mt4">
          <button type="button" class="btn" id="as-insert" disabled><?= $L('asst.insert') ?></button>
          <button type="button" class="btn primary" id="as-save" disabled><?= $L('asst.save') ?></button>
        </div>
      </div>
      <div class="card" id="as-results" hidden>
        <header><h3><?= $L('asst.matches') ?></h3><span class="badge" id="as-n"></span></header>
        <div class="tablewrap"><table id="as-table"></table></div>
        <p class="muted small" id="as-more" hidden></p>
        <div id="as-similar-box" hidden>
          <h3 class="mt3"><?= $L('asst.similar') ?></h3>
          <p class="help"><?= $L('asst.similar_help') ?></p>
          <div class="tablewrap"><table id="as-similar"></table></div>
        </div>
      </div>
    </div>

    <div data-subpanel="rules" hidden>
      <div class="editor-grid">
        <div class="card">
          <header>
            <h2><?= $L('rules.title') ?></h2><div class="spacer"></div>
            <input type="file" id="rules-open" accept=".txt,text/plain" hidden><button type="button" class="btn small" data-pick="#rules-open"><?= $L('ed.open') ?></button>
            <a class="btn small" href="?a=dl&amp;what=rules"><?= $L('ed.download') ?></a>
            <button type="button" class="btn small" id="rules-revert"><?= $L('ed.revert') ?></button>
            <button type="button" class="btn small primary" id="rules-save"><?= $L('ed.save') ?></button>
          </header>
          <div id="rules-error" class="notice bad" hidden></div>
          <textarea class="code" id="rules-text" spellcheck="false" autocapitalize="off" autocomplete="off" wrap="off" aria-label="<?= $L('rules.title') ?>"></textarea>
        </div>
        <aside class="card help">
          <h3><?= $L('rules.help_title') ?></h3>
          <p><?= $L('rules.help_order') ?></p>
          <table>
            <tr><td><code>/regex/i</code></td><td><?= $L('rules.help_regex') ?></td></tr>
            <tr><td><code>REWE</code></td><td><?= $L('rules.help_text') ?></td></tr>
            <tr><td><code>iban:DE…</code></td><td><?= $L('rules.help_iban') ?></td></tr>
            <tr><td><code>category:/…/</code></td><td><?= $L('rules.help_category') ?></td></tr>
            <tr><td><code>memo:/…/</code></td><td><?= $L('rules.help_memo') ?></td></tr>
          </table>
          <p><?= $L('rules.help_target') ?></p>
          <p><code>/\bErika\s+Muster\b/i =&gt; Bäcker Erika Muster</code></p>
          <p><?= $L('rules.help_semicolon') ?></p>
          <p><?= $L('rules.help_comments') ?></p>
        </aside>
      </div>
    </div>
  </section>

  <!-- 3: account mapping and options -->
  <section data-panel="accounts" hidden>
    <div class="card">
      <header>
        <h2><?= $L('acc.title') ?></h2><span class="dirty-dot" id="config-dirty" hidden></span><div class="spacer"></div>
        <input type="file" id="config-open" accept=".json,application/json" hidden><button type="button" class="btn small" data-pick="#config-open"><?= $L('ed.open') ?></button>
        <a class="btn small" href="?a=dl&amp;what=config"><?= $L('ed.download') ?></a>
        <button type="button" class="btn small" id="config-raw-toggle"><?= $L('acc.raw') ?></button>
        <button type="button" class="btn small" id="config-revert"><?= $L('ed.revert') ?></button>
        <button type="button" class="btn small primary" id="config-save"><?= $L('ed.save') ?></button>
      </header>
      <div id="config-error" class="notice bad" hidden></div>
      <div id="config-structured">
        <p class="help"><?= $L('acc.help') ?></p>
        <div class="tabletools">
          <input type="search" id="acc-q" placeholder="<?= $L('acc.search') ?>" aria-label="<?= $L('tbl.search') ?>">
          <select id="acc-kind" aria-label="<?= $L('acc.kind') ?>">
            <option value=""><?= $L('acc.all') ?></option><option value="bs"><?= $L('acc.bs') ?></option><option value="category"><?= $L('acc.categories') ?></option><option value="off"><?= $L('acc.off') ?></option><option value="changed"><?= $L('acc.changed') ?></option>
          </select>
          <span class="muted small" id="acc-count"></span>
        </div>
        <div class="tablewrap"><table id="acc-table"></table></div>
        <h3 class="mt3"><?= $L('opt.title') ?></h3>
        <div id="opt-list"></div>
      </div>
      <div id="config-rawbox" hidden>
        <p class="help"><?= $L('acc.raw_help') ?></p>
        <textarea class="code" id="config-text" spellcheck="false" autocapitalize="off" autocomplete="off" wrap="off" aria-label="import.json"></textarea>
      </div>
    </div>
  </section>

  <!-- 4: Firefly -->
  <section data-panel="firefly" hidden>
    <div class="card">
      <header><h2><?= $L('ff.connection') ?></h2></header>
      <div class="fields">
        <label class="field"><?= $L('ff.url') ?><input type="url" id="ff-url" placeholder="https://firefly.example.org" autocomplete="off" spellcheck="false"></label>
        <label class="field"><?= $L('ff.token') ?>
          <input type="password" id="ff-token" autocomplete="off" spellcheck="false" placeholder="<?= $L('ff.token_ph') ?>">
        </label>
      </div>
      <div class="mt2 row">
        <label class="check"><input type="checkbox" id="ff-remember"> <span><?= $L('ff.remember') ?></span></label>
        <div class="spacer"></div>
        <button type="button" class="btn" id="ff-test"><?= $L('ff.test') ?></button>
      </div>
      <p class="conn-state" id="ff-state"></p>
      <p class="help"><?= $L('ff.token_help') ?></p>
    </div>

    <div class="grid2">
      <div class="card" id="import-card">
        <header><h2><?= $L('imp.title') ?></h2></header>
        <p class="help" id="import-hint"></p>
        <div class="fields">
          <label class="field"><?= $L('range.from') ?><input type="date" id="imp-from"></label>
          <label class="field"><?= $L('range.to') ?><input type="date" id="imp-to"></label>
          <label class="field"><?= $L('imp.limit') ?><input type="number" id="imp-limit" min="1" step="1" placeholder="<?= $L('imp.limit_ph') ?>"></label>
        </div>
        <div class="mt2 stack">
          <label class="check"><input type="checkbox" id="imp-update"> <span><?= $L('imp.update_payees') ?><br><span class="help"><?= $L('imp.update_payees_help') ?></span></span></label>
          <label class="check"><input type="checkbox" id="imp-nomulti"> <span><?= $L('imp.no_multisource') ?><br><span class="help"><?= $L('imp.no_multisource_help') ?></span></span></label>
        </div>
        <div class="mt4 row end">
          <button type="button" class="btn" id="imp-dry"><?= $L('imp.dry') ?></button>
          <button type="button" class="btn primary" id="imp-run"><?= $L('imp.run') ?></button>
        </div>
        <div class="mt1 downloads" id="imp-downloads"></div>
      </div>

      <div class="card" id="export-card">
        <header><h2><?= $L('exp.title') ?></h2></header>
        <p class="help"><?= $L('exp.help') ?></p>
        <div class="fields">
          <label class="field"><?= $L('range.from') ?><input type="date" id="exp-from"></label>
          <label class="field"><?= $L('range.to') ?><input type="date" id="exp-to"></label>
        </div>
        <div class="mt2 stack">
          <label class="check"><input type="checkbox" id="exp-plain"> <span><?= $L('exp.uncompressed') ?></span></label>
        </div>
        <div class="mt4 row end">
          <button type="button" class="btn primary" id="exp-run"><?= $L('exp.run') ?></button>
        </div>
        <div id="exp-result" class="mt1 stack" hidden>
          <div class="row">
            <a class="btn" href="?a=dl&amp;what=export" id="exp-dl"><?= $L('exp.download') ?></a>
            <button type="button" class="btn" id="exp-compare"><?= $L('exp.compare') ?></button>
            <label class="check small"><input type="checkbox" id="exp-byyear"> <span><?= $L('exp.by_year') ?></span></label>
          </div>
          <p class="help" id="exp-info"></p>
        </div>
      </div>
    </div>

    <div class="mt5 card danger" id="purge-card">
      <header><h2><?= $L('pur.title') ?></h2></header>
      <p class="help"><?= $L('pur.help') ?></p>
      <div class="fields">
        <label class="field"><?= $L('pur.tag') ?><input type="text" id="pur-tag" value="GnuCash-Import" spellcheck="false"></label>
      </div>
      <div class="mt2 stack">
        <label class="check"><input type="checkbox" id="pur-accounts"> <span><?= $L('pur.accounts') ?><br><span class="help"><?= $L('pur.accounts_help') ?></span></span></label>
      </div>
      <div class="mt4 row end">
        <button type="button" class="btn" id="pur-preview"><?= $L('pur.preview') ?></button>
        <button type="button" class="btn danger solid" id="pur-run"><?= $L('pur.run') ?></button>
      </div>
    </div>
  </section>
</main>

<footer class="foot">
  firefly-gnucash <?= h(toolVersion()) ?> · <a href="https://github.com/Simon0Harms/firefly-iii-gnucash" rel="noopener noreferrer" target="_blank">GitHub</a> · <?= $L('app.footer') ?>
</footer>

<dialog id="dlg">
  <form method="dialog">
    <div class="dh"><h2 id="dlg-title"></h2></div>
    <div class="db" id="dlg-body"></div>
    <div class="df">
      <button class="btn" value="cancel" id="dlg-cancel"><?= $L('dlg.cancel') ?></button>
      <button class="btn primary" value="ok" id="dlg-ok"></button>
    </div>
  </form>
</dialog>
<div id="toasts" aria-live="polite"></div>
<datalist id="payee-names"></datalist>

<script type="application/json" id="boot"><?= json_encode($boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<script nonce="<?= $n ?>">
<?= appJs() ?>
</script>
</body>
</html>
<?php
}

function appJs(): string
{
    return <<<'JS'
'use strict';
(() => {
const B = JSON.parse(document.getElementById('boot').textContent);
const T = B.texts;
const t = (k, v = {}) => String(T[k] ?? k).replace(/\{(\w+)\}/g, (m, key) => (key in v ? String(v[key]) : m));
const $ = (s, r = document) => r.querySelector(s);
const $$ = (s, r = document) => [...r.querySelectorAll(s)];
const locale = B.lang === 'de' ? 'de-DE' : 'en-GB';
const nf = new Intl.NumberFormat(locale);
const n = x => nf.format(Number(x) || 0);
const fmtTime = ts => (ts ? new Date(ts * 1000).toLocaleString(locale, {dateStyle: 'medium', timeStyle: 'short'}) : '');
const fmtSize = b => (b >= 1048576 ? (b / 1048576).toLocaleString(locale, {maximumFractionDigits: 1}) + ' MB' : Math.max(1, Math.round(b / 1024)).toLocaleString(locale) + ' KB');
const fmtDur = s => { s = Math.max(0, Math.round(s)); const h = Math.floor(s / 3600), m = Math.floor(s % 3600 / 60), x = s % 60; return (h ? h + ':' + String(m).padStart(2, '0') : m) + ':' + String(x).padStart(2, '0'); };
/** text with a singular variant "key.one" for exactly 1 */
const tn = (k, count, v = {}) => t(count === 1 && (k + '.one') in T ? k + '.one' : k, v);
const debounce = (fn, ms = 160) => { let h; return (...a) => { clearTimeout(h); h = setTimeout(() => fn(...a), ms); }; };

function el(tag, attrs, ...kids) {
  const e = document.createElement(tag);
  for (const [k, v] of Object.entries(attrs || {})) {
    if (v === null || v === undefined || v === false) continue;
    if (k === 'class') e.className = v;
    else if (k === 'text') e.textContent = v;
    else if (k.startsWith('on')) e.addEventListener(k.slice(2), v);
    else e.setAttribute(k, v === true ? '' : v);
  }
  for (const c of kids.flat()) {
    if (c === null || c === undefined || c === false) continue;
    e.append(c instanceof Node ? c : document.createTextNode(String(c)));
  }
  return e;
}
/** "Text with `code`" -> nodes */
function rich(text) {
  return String(text).split(/`([^`]+)`/).map((p, i) => (i % 2 ? el('code', {}, p) : p));
}

function toast(msg, bad = false) {
  const x = el('div', {class: 'toast' + (bad ? ' bad' : ''), role: bad ? 'alert' : 'status'}, msg);
  $('#toasts').append(x);
  setTimeout(() => x.remove(), bad ? 9000 : 4500);
}

async function api(a, {method = 'GET', params = {}, json, raw = false} = {}) {
  const q = new URLSearchParams({a, ...params});
  const opt = {method, headers: {'X-CSRF-Token': B.csrf}, credentials: 'same-origin', cache: 'no-store'};
  if (json !== undefined) { opt.headers['Content-Type'] = 'application/json'; opt.body = JSON.stringify(json); }
  const r = await fetch('?' + q.toString(), opt);
  let data;
  try { data = await r.json(); } catch (e) { throw new Error(t('err.server', {status: r.status})); }
  if (!data.ok && !raw) { const err = new Error(data.error || t('err.server', {status: r.status})); err.data = data; throw err; }
  return data;
}

// ------------------------------------------------------------------ dialog
function ask({title, body = [], ok, danger = false, word = null}) {
  const dlg = $('#dlg'), okBtn = $('#dlg-ok');
  $('#dlg-title').textContent = title;
  const box = $('#dlg-body');
  box.replaceChildren(...(Array.isArray(body) ? body : [body]).map(x => (typeof x === 'string' ? el('p', {}, rich(x)) : x)));
  okBtn.textContent = ok;
  okBtn.className = 'btn ' + (danger ? 'danger solid' : 'primary');
  okBtn.disabled = false;
  if (word) {
    const inp = el('input', {type: 'text', autocomplete: 'off', spellcheck: 'false'});
    box.append(el('label', {class: 'field'}, t('dlg.type_word', {word}), inp));
    okBtn.disabled = true;
    inp.addEventListener('input', () => { okBtn.disabled = inp.value.trim().toUpperCase() !== word.toUpperCase(); });
    setTimeout(() => inp.focus(), 50);
  }
  dlg.returnValue = '';
  dlg.showModal();
  return new Promise(res => dlg.addEventListener('close', () => res(dlg.returnValue === 'ok'), {once: true}));
}

// ------------------------------------------------------------------ state
const S = {
  st: null, tab: 'book', sub: 'payees', dataTime: -1,
  payees: null, map: null, sugg: null, mapPayee: null, det: null,
  rules: {server: '', time: -1, loaded: false, dirty: false},
  cfg: {server: '', time: -1, loaded: false, dirty: false, raw: false, obj: null, orig: null},
  poll: null, jobHidden: null, jobOpen: {}, running: false, lastJobKey: null,
};

// ------------------------------------------------------------------ tabs
function showTab(name) {
  if (!['book', 'payees', 'accounts', 'firefly'].includes(name)) name = 'book';
  const btn = $(`.tabs [data-tab="${name}"]`);
  if (btn.disabled) name = 'book';
  S.tab = name;
  for (const b of $$('.tabs [data-tab]')) b.setAttribute('aria-selected', String(b.dataset.tab === name));
  for (const p of $$('[data-panel]')) p.hidden = p.dataset.panel !== name;
  if (location.hash.slice(1) !== name) history.replaceState(null, '', '#' + name);
  loadVisible();
}
function showSub(name) {
  S.sub = name;
  for (const b of $$('.subtabs [data-sub]')) b.setAttribute('aria-selected', String(b.dataset.sub === name));
  for (const p of $$('[data-subpanel]')) p.hidden = p.dataset.subpanel !== name;
  loadVisible();
}
function renderTabs() {
  const hasBook = !!S.st?.book;
  for (const k of ['payees', 'accounts']) $(`.tabs [data-tab="${k}"]`).disabled = !hasBook;
  if (!hasBook && (S.tab === 'payees' || S.tab === 'accounts')) showTab('book');
}
function loadVisible() {
  if (!S.st || S.running) return;
  if (S.tab === 'payees') {
    if (S.sub === 'payees') loadPayees();
    if (S.sub === 'map') loadMap();
    if (S.sub === 'suggestions') loadSugg();
    if (S.sub === 'rules') loadRules();
    if (S.sub === 'assistant') { if (!S.rules.loaded) loadRules(); if (!S.cfg.loaded) loadConfig(); if (A.dataTime !== S.dataTime && ($('#as-q').value || A.custom)) { A.dataTime = S.dataTime; preview(); } }
  }
  if (S.tab === 'accounts') loadConfig();
}

// ------------------------------------------------------------------ status / polling
function schedule(ms) { clearTimeout(S.poll); S.poll = setTimeout(refresh, ms); }

async function refresh() {
  let st;
  try { st = await api('status'); } catch (e) { toast(e.message, true); schedule(5000); return; }
  const before = S.st?.job;
  S.st = st;
  S.running = st.job?.state === 'running';
  renderJob(st.job);
  renderBook();
  renderTabs();
  renderFirefly();
  if (!S.running) {
    const f = st.files || {};
    const dt = Math.max(f.payees?.time || 0, f.map?.time || 0, f.summary?.time || 0, f.suggestions?.time || 0);
    if (dt !== S.dataTime) { S.dataTime = dt; S.payees = S.map = S.sugg = S.det = null; }
    const rt = f.rules?.time || 0;
    if (rt !== S.rules.time && !S.rules.dirty) { S.rules.time = rt; S.rules.loaded = false; }
    const ct = f.config?.time || 0;
    if (ct !== S.cfg.time && !S.cfg.dirty) { S.cfg.time = ct; S.cfg.loaded = false; }
    loadVisible();
  }
  const key = st.job ? st.job.type + ':' + st.job.started : null;
  if (before && key === S.lastJobKey && before.state === 'running' && !S.running) jobFinished(st.job);
  S.lastJobKey = key;
  if (S.running) schedule(1000); else clearTimeout(S.poll);
}

function jobFinished(job) {
  const name = t('job.' + job.type);
  if (job.state === 'ok') toast(t('job.done_ok', {job: name}));
  else if (job.state === 'differences') toast(t('job.done_diff', {job: name}), true);
  else if (job.state === 'cancelled') toast(t('job.done_cancelled', {job: name}));
  else toast(t('job.done_failed', {job: name}), true);
}

function logLines(text) {
  return text.split('\n').map(line => {
    const cls = /^(WARN:)/.test(line) ? 'w' : /^(ERROR:)/.test(line) ? 'e' : /^==> /.test(line) ? 's' : null;
    return cls ? el('span', {class: cls}, line + '\n') : line + '\n';
  });
}

/** The line of a finished job's output that tells the result. */
function resultLine(job) {
  const lines = (job.log || '').split('\n').map(l => l.trim()).filter(Boolean);
  const want = job.state === 'ok' || job.state === 'differences'
    ? [/^Done:/, /^Dry run/, /^Nothing (to|was)/, /^Identical:/, /balances differ/, /accounts match GnuCash/, /^==> Written /, /^\d+ transactions, \d+ accounts/]
    : [/^ERROR:/];
  for (let i = lines.length - 1; i >= 0; i--) if (want.some(r => r.test(lines[i]))) return lines[i].replace(/^==> /, '');
  return job.state === 'ok' ? '' : t('state.' + job.state);
}

function renderJob(job) {
  const box = $('#job');
  if (!job) { box.hidden = true; return; }
  const key = job.type + ':' + job.started;
  const running = job.state === 'running';
  // a successful preview needs no panel: the book tab shows the result
  if (!running && (S.jobHidden === key || (job.type === 'plan' && job.state === 'ok'))) { box.hidden = true; box.dataset.key = key; box.dataset.state = job.state; return; }
  box.hidden = false;
  box.dataset.key = key;
  box.dataset.state = job.state;
  const bad = ['failed', 'lost', 'cancelled', 'differences'].includes(job.state);
  box.className = 'card job' + (job.state === 'ok' ? ' ok' : bad ? ' bad' : '');
  $('#job-title').textContent = t('job.' + job.type);
  const badge = $('#job-badge');
  badge.textContent = t('state.' + job.state);
  badge.className = 'badge ' + (running ? 'accent' : job.state === 'ok' ? 'ok' : bad ? 'bad' : '');
  const now = Date.now() / 1000;
  $('#job-time').textContent = running ? t('job.running_for', {time: fmtDur(now - job.started)}) : t('job.finished', {time: fmtTime(job.finished || job.started), dur: fmtDur((job.finished || now) - job.started)});
  $('#job-cancel').hidden = !running;
  $('#job-close').hidden = running;
  $('#job-log-dl').href = '?a=dl&what=log-' + encodeURIComponent(job.type);
  const bar = $('#job-progress'), fill = bar.firstElementChild;
  const p = job.progress;
  bar.hidden = !running;
  bar.classList.toggle('indet', running && !p);
  fill.style.width = running && p ? Math.min(100, p.pct) + '%' : '';
  let phase = job.phase || '';
  if (running && p) phase += (phase ? ' – ' : '') + t('job.progress', {done: n(p.done), total: n(p.total), pct: p.pct.toLocaleString(locale)}) + (p.eta ? ' · ' + t('job.eta', {eta: p.eta}) : '');
  if (!running) phase = resultLine(job);
  if (job.meta?.url) phase += (phase ? ' · ' : '') + job.meta.url;
  $('#job-phase').textContent = phase;
  const det = $('#job-details');
  if (!(key in S.jobOpen)) S.jobOpen[key] = job.type !== 'plan';
  if (!running && bad) S.jobOpen[key] = true;
  det.open = S.jobOpen[key];
  det.ontoggle = () => { S.jobOpen[key] = det.open; };
  const pre = $('#job-log');
  const stick = pre.scrollTop + pre.clientHeight >= pre.scrollHeight - 30;
  pre.replaceChildren(...logLines(job.log || ''));
  if (stick) pre.scrollTop = pre.scrollHeight;
}

// ------------------------------------------------------------------ book / summary
const SKIP = r => t('skip.' + r) === 'skip.' + r ? r : t('skip.' + r);
const OTHER = k => t('other.' + k) === 'other.' + k ? k : t('other.' + k);

function stat(v, l, cls = '') { return el('div', {class: 'stat ' + cls}, el('div', {class: 'v'}, v), el('div', {class: 'l'}, l)); }

function renderBook() {
  const st = S.st, book = st.book;
  const sec = $('[data-panel="book"]');
  sec.classList.toggle('has-book', !!book);
  $('#book-card').hidden = !book;
  $('#upload-title').textContent = book ? t('book.upload_other') : t('book.upload_title');
  $('#keep-row').hidden = !book;
  $('#drop-hint').textContent = t('book.formats', {max: fmtSize(B.maxUpload)});
  if (!book) return;
  $('#book-name').textContent = book.name;
  $('#book-size').textContent = fmtSize(book.size);
  $('#book-info').textContent = t('book.uploaded', {time: fmtTime(book.uploaded)});
  $('#stale').hidden = !st.stale;
  $('#replan').disabled = S.running;
  renderSummary(st.summary, st.job);
}

function renderSummary(sum, job) {
  const box = $('#summary');
  const planRunning = job && job.type === 'plan' && job.state === 'running';
  if (planRunning) { box.replaceChildren(el('p', {class: 'muted'}, t('book.planning'))); return; }
  const planFailed = job && job.type === 'plan' && ['failed', 'lost'].includes(job.state);
  const kids = [];
  if (planFailed) kids.push(el('div', {class: 'notice bad'}, t('book.plan_failed'), el('pre', {class: 'log'}, (job.log || '').trim().split('\n').slice(-8).join('\n'))));
  if (!sum) { box.replaceChildren(...kids); return; }
  const imp = sum.importable || {}, cp = sum.counterparties || {}, sc = sum.selfcheck || {}, bk = sum.book || {}, mp = sum.mapping || {};
  kids.push(el('div', {class: 'stats'},
    stat(n(imp.transactions), t('sum.importable', {total: n(bk.transactions)})),
    stat(n(imp.withdrawal), t('sum.withdrawals')),
    stat(n(imp.deposit), t('sum.deposits')),
    stat(n(imp.transfer), t('sum.transfers')),
    stat(n((cp.expense || 0) + (cp.revenue || 0)), t('sum.payees', {expense: n(cp.expense), revenue: n(cp.revenue)})),
    stat(sc.ok ? t('sum.ok') : t('sum.failed'), sc.ok ? t('sum.checked', {n: n(sc.checked)}) : t('sum.failed_help'), sc.ok ? 'ok' : 'bad')));
  const facts = [
    t('sum.book', {accounts: n(bk.accounts), transactions: n(bk.transactions), first: bk.first || '–', last: bk.last || '–', currency: bk.currency || ''}),
    t('sum.mapping', {asset: n(mp.asset), liability: n(mp.liability), category: n(mp.category)}),
  ];
  if (imp.multisource) facts.push(t('sum.multisource', {n: n(imp.multisource)}));
  if (imp.clearing) facts.push(t('sum.clearing', {n: n(imp.clearing)}));
  if (sum.opening_balances) facts.push(t('sum.opening', {n: n(sum.opening_balances)}));
  for (const [r, c] of Object.entries(sum.skipped || {})) facts.push(t('sum.skipped', {n: n(c), reason: SKIP(r)}));
  const other = Object.entries(sum.not_imported || {}).map(([k, c]) => `${n(c)} ${OTHER(k)}`);
  if (other.length) facts.push(t('sum.not_imported', {list: other.join(', ')}));
  facts.push(t('sum.rules', {rules: n(cp.rules), sugg: n(cp.suggestions)}));
  kids.push(el('ul', {class: 'facts'}, facts.map(x => el('li', {}, x))));
  if ((sum.warnings || []).length) {
    kids.push(el('details', {}, el('summary', {}, t('sum.warnings', {n: n(sum.warnings.length)})),
      el('ul', {class: 'facts small warnlist'}, sum.warnings.map(w => (w.all || []).length > 1
        ? el('li', {}, el('details', {}, el('summary', {}, `${n(w.count)}× ${w.example}`),
            el('ol', {class: 'small'}, w.all.map(x => el('li', {}, x))),
            w.count > w.all.length ? el('div', {class: 'muted small'}, t('sum.warn_more', {n: n(w.count - w.all.length)})) : null))
        : el('li', {}, `${n(w.count)}× ${w.example}`)))));
  }
  if ((sum.config_messages || []).length) {
    kids.push(el('details', {}, el('summary', {}, t('sum.config_msgs', {n: n(sum.config_messages.length)})),
      el('ul', {class: 'facts small'}, sum.config_messages.map(m => el('li', {}, m)))));
  }
  if (!sc.ok && (sc.errors || []).length) kids.push(el('ul', {class: 'facts small'}, sc.errors.map(e => el('li', {}, e))));
  if ((cp.top || []).length) {
    kids.push(el('h3', {class: 'mt3'}, t('sum.top')));
    kids.push(el('div', {class: 'chips'}, cp.top.map(p => el('button', {type: 'button', class: 'chip', title: t('side.' + p.side), onclick: () => { showTab('payees'); showSub('payees'); $('#payees-q').value = p.name; $('#payees-side').value = p.side; payeeGrid.render(); }}, p.name, el('b', {}, n(p.transactions))))));
  }
  const f = S.st.files || {};
  const dl = [['config', 'dl.config'], ['rules', 'dl.rules'], ['payees', 'dl.payees'], ['map', 'dl.map'], ['suggestions', 'dl.suggestions']]
    .filter(([k]) => f[k]).map(([k, l]) => el('a', {class: 'btn small', href: '?a=dl&what=' + k}, t(l)));
  if (S.st.logs?.plan) dl.push(el('a', {class: 'btn small', href: '?a=dl&what=log-plan'}, t('dl.planlog')));
  kids.push(el('h3', {class: 'mt3'}, t('sum.downloads')), el('div', {class: 'downloads'}, dl));
  box.replaceChildren(...kids);
}

// ------------------------------------------------------------------ upload
function upload(file) {
  if (!file) return;
  if (S.running) { toast(t('err.job_running'), true); return; }
  if (file.size > B.maxUpload) { toast(t('err.too_big', {max: fmtSize(B.maxUpload)}), true); return; }
  const go = () => {
    const fd = new FormData();
    fd.append('book', file);
    fd.append('keep', S.st?.book && $('#keep').checked ? '1' : '0');
    for (const [id, field] of [['#rules-file', 'rules'], ['#config-file', 'config']]) if ($(id).files[0]) fd.append(field, $(id).files[0]);
    const xhr = new XMLHttpRequest();
    const bar = $('#upload-progress');
    bar.hidden = false;
    bar.firstElementChild.style.width = '0';
    xhr.upload.onprogress = e => { if (e.lengthComputable) bar.firstElementChild.style.width = Math.round(100 * e.loaded / e.total) + '%'; };
    xhr.onload = () => {
      bar.hidden = true;
      let d = null;
      try { d = JSON.parse(xhr.responseText); } catch (e) { /* not JSON */ }
      if (!d || !d.ok) { toast(d?.error || t('err.server', {status: xhr.status}), true); return; }
      $('#rules-file').value = ''; $('#config-file').value = '';
      S.rules.dirty = S.cfg.dirty = false; S.rules.loaded = S.cfg.loaded = false;
      markDirty();
      toast(t('book.uploaded_ok'));
      refresh();
    };
    xhr.onerror = () => { bar.hidden = true; toast(t('err.network'), true); };
    xhr.open('POST', '?a=upload');
    xhr.setRequestHeader('X-CSRF-Token', B.csrf);
    xhr.send(fd);
  };
  if (S.rules.dirty || S.cfg.dirty) {
    ask({title: t('book.upload_other'), body: [t('ed.discard')], ok: t('book.upload_anyway')}).then(yes => yes && go());
  } else go();
}

// ------------------------------------------------------------------ tables
class Grid {
  constructor(id, cols, {sort, dir = 'desc', filter = null, onRow = null, page = 200} = {}) {
    this.table = $('#' + id + '-table'); this.more = $('#' + id + '-more'); this.count = $('#' + id + '-count'); this.q = $('#' + id + '-q');
    this.cols = cols; this.sort = sort; this.dir = dir; this.filter = filter; this.onRow = onRow; this.page = page; this.limit = page; this.rows = [];
    this.q.addEventListener('input', debounce(() => { this.limit = this.page; this.render(); }));
    this.more.querySelector('button').addEventListener('click', () => { this.limit += this.page * 2; this.render(); });
  }
  setRows(rows) {
    this.rows = rows.map(r => Object.assign(r, {_s: this.cols.filter(c => !c.nosort).map(c => String(c.text ? c.text(r) : r[c.key] ?? '')).concat(r._x || '').join('\u0001').toLowerCase()}));
    this.limit = this.page; this.render();
  }
  /** re-index after r._x (extra search text) changed, keeping page and scroll */
  reindex() { const l = this.limit; this.setRows(this.rows); this.limit = l; this.render(); }
  render() {
    const terms = this.q.value.toLowerCase().trim().split(/\s+/).filter(Boolean);
    let rows = this.rows.filter(r => terms.every(x => r._s.includes(x)) && (!this.filter || this.filter(r)));
    const c = this.cols.find(x => x.key === this.sort);
    if (c) {
      const f = this.dir === 'asc' ? 1 : -1;
      const val = c.sortv || (r => r[c.key]);
      rows = rows.slice().sort((a, b) => { const x = val(a), y = val(b); return (typeof x === 'number' && typeof y === 'number' ? x - y : String(x).localeCompare(String(y), locale)) * f; });
    }
    this.count.textContent = rows.length > this.limit ? t('tbl.count_part', {shown: n(this.limit), total: n(rows.length)}) : tn('tbl.count', rows.length, {total: n(rows.length)});
    const head = el('tr', {}, this.cols.map(col => {
      if (col.nosort) return el('th', {scope: 'col'}, el('span', {class: 'sr-only'}, col.label));
      const th = el('th', {class: (col.num ? 'num ' : '') + (col.key === this.sort ? 'sorted ' + this.dir : ''), scope: 'col', tabindex: '0'}, col.label);
      const go = () => { if (this.sort === col.key) this.dir = this.dir === 'asc' ? 'desc' : 'asc'; else { this.sort = col.key; this.dir = col.num ? 'desc' : 'asc'; } this.render(); };
      th.addEventListener('click', go);
      th.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); go(); } });
      return th;
    }));
    const body = el('tbody', {}, rows.slice(0, this.limit).map(r => {
      const tr = el('tr', {class: this.onRow ? 'click' : ''}, this.cols.map(col => {
        const v = col.render ? col.render(r) : (col.text ? col.text(r) : r[col.key]);
        return el('td', {class: (col.num ? 'num ' : '') + (col.cls || '')}, v instanceof Node ? v : String(v ?? ''));
      }));
      if (this.onRow) tr.addEventListener('click', () => this.onRow(r));
      return tr;
    }));
    this.table.replaceChildren(el('thead', {}, head), body);
    this.more.hidden = rows.length <= this.limit;
  }
}

const sideOf = v => (/^Ausgaben|expense/i.test(v) ? 'expense' : /^Einnahmen|revenue/i.test(v) ? 'revenue' : v);
const srcLabel = s => String(s || '').split(',').filter(Boolean).map(x => {
  const m = /^rule:(\d+)$/.exec(x);
  if (m) return t('src.rule_line', {n: m[1]});
  if (x === 'rule:fallback') return t('src.rule_fallback');
  return t('src.' + x) === 'src.' + x ? x : t('src.' + x);
}).join(', ');
const srcKind = s => (/rule/.test(s) ? 'rule' : /fallback/.test(s) ? 'fallback' : 'auto');
const ex = v => el('div', {class: 'ex', title: v}, v);
const actBtn = fn => el('button', {type: 'button', class: 'btn small', title: t('asst.open_title'), onclick: e => { e.stopPropagation(); fn(); }}, t('asst.open'));

const payeeGrid = new Grid('payees', [
  {key: 'payee', label: t('col.payee'), cls: 'text'},
  {key: 'side', label: t('col.side'), text: r => t('kind.' + r.side)},
  {key: 'source', label: t('col.source'), text: r => srcLabel(r.source)},
  {key: 'transactions', label: t('col.count'), num: true, text: r => n(r.transactions), sortv: r => r.transactions},
  {key: 'amount', label: t('col.amount'), num: true, cls: 'nowrap'},
  {key: 'iban', label: 'IBAN', cls: 'nowrap'},
  {key: 'period', label: t('col.period'), cls: 'nowrap', text: r => (r.first === r.last ? r.first : `${r.first} – ${r.last}`), sortv: r => r.last},
  {key: 'categories', label: t('col.categories'), render: r => ex(r.categories)},
  {key: 'booking_texts', label: t('col.examples'), render: r => ex(r.booking_texts)},
  {key: '_act', label: t('asst.open_title'), nosort: true, cls: 'act', render: r => actBtn(() => openAssistant(fromPayee(r.payee, r.booking_texts)))},
], {sort: 'transactions', filter: r => (!$('#payees-side').value || r.side === $('#payees-side').value) && (!$('#payees-src').value || srcKind(r.source) === $('#payees-src').value),
    onRow: r => { S.mapPayee = {name: r.payee, side: r.side}; showSub('map'); renderMapFilter(); mapGrid.render(); }});

const mapGrid = new Grid('map', [
  {key: 'booking_text', label: t('col.text'), cls: 'text', render: r => hov(r.booking_text, r.side)},
  {key: 'side', label: t('col.side'), text: r => t('kind.' + r.side)},
  {key: 'transactions', label: t('col.count'), num: true, text: r => n(r.transactions), sortv: r => r.transactions},
  {key: 'payee', label: t('col.payee'), cls: 'text'},
  {key: 'source', label: t('col.source'), text: r => srcLabel(r.source)},
  {key: 'iban', label: 'IBAN', cls: 'nowrap'},
  {key: 'categories', label: t('col.categories'), render: r => ex(r.categories)},
  {key: '_act', label: t('asst.open_title'), nosort: true, cls: 'act', render: r => actBtn(() => openAssistant(fromPayee(r.payee, r.booking_text)))},
], {sort: 'transactions', filter: r => (!S.mapPayee || (r.payee === S.mapPayee.name && r.side === S.mapPayee.side)) && (!$('#map-side').value || r.side === $('#map-side').value) && (!$('#map-src').value || srcKind(r.source) === $('#map-src').value)});

for (const id of ['#payees-side', '#payees-src']) $(id).addEventListener('change', () => payeeGrid.render());
for (const id of ['#map-side', '#map-src']) $(id).addEventListener('change', () => mapGrid.render());
function renderMapFilter() {
  $('#map-payee').hidden = !S.mapPayee;
  if (S.mapPayee) $('#map-payee-clear').textContent = t('tbl.payee_filter', {name: S.mapPayee.name}) + ' ✕';
}
$('#map-payee-clear').addEventListener('click', () => { S.mapPayee = null; renderMapFilter(); mapGrid.render(); });

async function loadPayees() {
  if (S.payees) return;
  S.payees = 'loading';
  try {
    const d = await api('table', {params: {what: 'payees'}});
    S.payees = d.rows.map(r => ({...r, side: sideOf(r.firefly_type), transactions: Number(r.transactions) || 0}));
    payeeGrid.setRows(S.payees);
    loadDetails().then(addDetailSearch);
    $('#n-payees').textContent = n(S.payees.length);
    const names = [...new Set(S.payees.map(r => r.payee))].sort((a, b) => a.localeCompare(b, locale));
    $('#payee-names').replaceChildren(...names.map(x => el('option', {value: x})));
  } catch (e) { S.payees = null; toast(e.message, true); }
}
async function loadMap() {
  if (S.map) return;
  S.map = 'loading';
  try {
    const d = await api('table', {params: {what: 'map'}});
    S.map = d.rows.map(r => ({...r, side: sideOf(r.firefly_type), transactions: Number(r.transactions) || 0}));
    mapGrid.setRows(S.map);
    loadDetails().then(addDetailSearch);
    renderMapFilter();
    $('#n-map').textContent = n(S.map.length);
  } catch (e) { S.map = null; toast(e.message, true); }
}

// ------------------------------------------------------------------ booking text details
// <book>.payee-details.json: per "booking text|side" the newest transactions with all splits
// (tooltip) and the accounts/memos/numbers/notes of all of them (search).
function loadDetails() {
  if (S.det && typeof S.det === 'object' && !(S.det instanceof Promise)) return Promise.resolve(S.det);
  if (S.det instanceof Promise) return S.det;
  const p = api('details').then(d => (S.det === p ? (S.det = d.details || {}) : d.details || {}))
    .catch(e => { if (S.det === p) S.det = null; toast(e.message, true); return {}; });
  S.det = p;
  return p;
}
function addDetailSearch(det) {
  if (!det) return;
  if (Array.isArray(S.map)) {
    for (const r of S.map) { const d = det[r.booking_text + '|' + r.side]; r._x = d ? d.find : ''; }
    mapGrid.reindex();
  }
  if (Array.isArray(S.payees)) {
    const by = {};
    for (const [k, d] of Object.entries(det)) {
      const side = k.slice(k.lastIndexOf('|') + 1);
      const key = d.payee + '|' + side;
      (by[key] ??= []).push(k.slice(0, k.lastIndexOf('|')), d.find);
    }
    for (const r of S.payees) r._x = (by[r.payee + '|' + r.side] || []).join('\u0001');
    payeeGrid.reindex();
  }
}
const hov = (text, side) => el('span', {class: 'hov', tabindex: '0', 'data-text': text, 'data-side': side}, text);
const money = (a, cur) => { const v = Number(a); return (Number.isFinite(v) ? v.toLocaleString(locale, {minimumFractionDigits: 2, maximumFractionDigits: 4}) : a) + ' ' + cur; };
function detailBox(text, side, det) {
  const d = det && det[text + '|' + side];
  if (!d) return [el('div', {class: 'muted'}, det ? t('det.none') : t('det.loading'))];
  const out = [el('div', {class: 'tip-h'}, tn('det.head', d.n, {n: n(d.n), payee: d.payee}), ' ', el('span', {class: 'badge'}, t('kind.' + side)))];
  for (const x of d.tx) {
    const meta = [el('span', {class: 'd'}, x.date)];
    if (x.num) meta.push(el('span', {class: 'muted'}, '#' + x.num));
    meta.push(el('b', {}, x.desc));
    if (x.splits.length > 2) meta.push(el('span', {class: 'badge'}, t('det.split', {n: x.splits.length})));
    out.push(el('div', {class: 'tip-tx'}, el('div', {class: 'tip-meta'}, meta),
      x.notes ? el('div', {class: 'muted small'}, x.notes) : null,
      el('table', {}, el('tbody', {}, x.splits.map(sp => el('tr', {class: /^(Aufwendungen|Erträge|Expenses?|Income)\b/i.test(sp.account) ? 'hit' : ''},
        el('td', {class: 'acc'}, el('button', {type: 'button', class: 'linkish', title: t('det.acc_rule'), onclick: () => { hideTip(); openAssistant(fromAccount(sp.account, side)); }}, sp.account)), el('td', {class: 'num'}, money(sp.amount, x.cur)), el('td', {class: 'memo'}, sp.memo ? el('button', {type: 'button', class: 'linkish', title: t('det.memo_rule'), onclick: () => { hideTip(); openAssistant(fromMemo(sp.memo, side)); }}, sp.memo) : '')))))));
  }
  if (d.n > d.tx.length) out.push(el('div', {class: 'muted small'}, t('det.more', {n: n(d.n - d.tx.length)})));
  return out;
}
/** assistant start values for a "konto:" rule on a GnuCash account (limited to the side) */
function fromAccount(path, side) {
  const esc = path.replace(/[\\.+*?\[\]^$(){}|\/]/g, '\\$&');
  return {query: '', mode: 'words', target: path.split(':').pop(), rule: (side === 'revenue' ? 'einnahme:' : 'ausgabe:') + 'konto:/^' + esc + '$/'};
}
/** assistant start values for a "memo:" rule: the memo's words, in this order, as whole words */
function fromMemo(memo, side) {
  const words = (memo.match(/[\p{L}\p{N}]+/gu) || []).slice(0, 4);
  const esc = words.join('\\W+');
  return {query: '', mode: 'words', target: '', rule: (side === 'revenue' ? 'einnahme:' : 'ausgabe:') + 'memo:/\\b' + esc + '\\b/i'};
}
const tip = el('div', {id: 'tip', role: 'tooltip', hidden: true});
document.body.append(tip);
let tipFor = null, tipTimer = null;
function placeTip(a) {
  const r = a.getBoundingClientRect(), w = tip.offsetWidth, h = tip.offsetHeight;
  const left = Math.max(8, Math.min(r.left, document.documentElement.clientWidth - w - 8));
  const below = r.bottom + 6 + h <= innerHeight || r.top - 6 - h < 0;
  tip.style.left = (left + scrollX) + 'px';
  tip.style.top = ((below ? r.bottom + 6 : r.top - 6 - h) + scrollY) + 'px';
}
function showTip(a) {
  tipFor = a;
  const fill = det => { if (tipFor !== a) return; tip.replaceChildren(...detailBox(a.dataset.text, a.dataset.side, det)); tip.hidden = false; placeTip(a); };
  fill(S.det && !(S.det instanceof Promise) ? S.det : null);
  if (!S.det || S.det instanceof Promise) loadDetails().then(fill);
  a.setAttribute('aria-describedby', 'tip');
}
function hideTip() { clearTimeout(tipTimer); if (tipFor) tipFor.removeAttribute('aria-describedby'); tipFor = null; tip.hidden = true; }
document.addEventListener('mouseover', e => {
  const a = e.target.closest && e.target.closest('.hov');
  if (a) { clearTimeout(tipTimer); if (tipFor !== a) tipTimer = setTimeout(() => showTip(a), 250); return; }
  if (e.target.closest && e.target.closest('#tip')) { clearTimeout(tipTimer); return; }
  if (tipFor || tipTimer) { clearTimeout(tipTimer); tipTimer = setTimeout(hideTip, 200); }
});
document.addEventListener('focusin', e => { const a = e.target.closest && e.target.closest('.hov'); if (a) { clearTimeout(tipTimer); showTip(a); } });
document.addEventListener('focusout', e => { if (e.target.closest && e.target.closest('.hov')) { clearTimeout(tipTimer); tipTimer = setTimeout(hideTip, 200); } });
document.addEventListener('keydown', e => { if (e.key === 'Escape' && tipFor) hideTip(); });
addEventListener('scroll', () => { if (tipFor && !tip.hidden) placeTip(tipFor); }, {passive: true});

// ------------------------------------------------------------------ suggestions
let suggLimit = 100;
async function loadSugg() {
  if (!S.sugg) {
    S.sugg = 'loading';
    try { S.sugg = (await api('suggestions')).items; } catch (e) { S.sugg = null; toast(e.message, true); return; }
    $('#n-sugg').textContent = n(S.sugg.length);
    suggLimit = 100;
  }
  if (!S.rules.loaded) await loadRules();
  renderSugg();
}
function renderSugg() {
  if (!Array.isArray(S.sugg)) return;
  const terms = $('#sugg-q').value.toLowerCase().trim().split(/\s+/).filter(Boolean);
  const items = S.sugg.filter(s => terms.every(x => (s.names + ' ' + s.rule).toLowerCase().includes(x)));
  const have = new Set($('#rules-text').value.split('\n').map(l => l.trim()));
  $('#sugg-count').textContent = tn('tbl.count', items.length, {total: n(items.length)});
  $('#sugg-list').replaceChildren(...items.slice(0, suggLimit).map(s => {
    const added = have.has(s.rule.trim());
    return el('div', {class: 'sugg' + (added ? ' added' : '')},
      el('div', {class: 'body'}, el('div', {class: 'names'}, t('sugg.item', {n: n(s.count), names: s.names})), el('code', {}, s.rule)),
      el('div', {class: 'row'},
        el('button', {type: 'button', class: 'btn small', onclick: () => openAssistant(fromRule(s.rule))}, t('sugg.adjust')),
        el('button', {type: 'button', class: 'btn small', disabled: added, onclick: () => adoptRule(s.rule)}, added ? t('sugg.added_label') : t('sugg.adopt'))));
  }));
  $('#sugg-more').hidden = items.length <= suggLimit;
}
$('#sugg-q').addEventListener('input', debounce(renderSugg));
$('#sugg-more button').addEventListener('click', () => { suggLimit += 200; renderSugg(); });
function adoptRule(rule) {
  const ta = $('#rules-text');
  let v = ta.value;
  if (v && !v.endsWith('\n')) v += '\n';
  ta.value = v + rule + '\n';
  rulesChanged();
  renderSugg();
  toast(t('sugg.added'));
}

// ------------------------------------------------------------------ rule assistant
const A = {custom: false, targetTouched: false, seq: 0, last: null};
const fallbackName = () => S.cfg.obj?.options?.payee_fallback || '(diverse)';
/** start values from a counterparty name (or, for the fallback, from its first booking text) */
function fromPayee(name, texts) {
  if (name && name !== fallbackName() && !/^\(.*\)$/.test(name)) return {query: name, mode: 'all', target: name};
  const words = String(texts || '').split(/[|;]/)[0].match(/[\p{L}][\p{L}.&'-]+/gu) || [];
  return {query: words.slice(0, 3).join(' '), mode: 'words', target: ''};
}
/** start values from a rule line "pattern => target" */
function fromRule(line) {
  const i = line.lastIndexOf('=>');
  const pattern = (i < 0 ? line : line.slice(0, i)).trim(), target = i < 0 ? '' : line.slice(i + 2).trim();
  const query = pattern.replace(/^\/|\/[a-z]*$/g, '').replace(/\(\?<?[!=]\\p\{L\}\)|\\b/g, '').replace(/\\s\+/g, ' ').replace(/\\(.)/g, '$1').trim();
  return {query, mode: 'words', target, rule: pattern};
}
function openAssistant(v) {
  A.dataTime = S.dataTime;
  showTab('payees');
  showSub('assistant');
  $('#as-q').value = v.query || '';
  $('#as-mode').value = v.mode || 'words';
  $('#as-name').checked = !!v.nameOnly;
  $('#as-target').value = v.target || '';
  A.targetTouched = !!v.target;
  setCustom(!!v.rule);
  if (v.rule) $('#as-rule').value = v.rule;
  preview();
  $('#as-q').focus();
  $('#asst').scrollIntoView({behavior: 'smooth', block: 'start'});
}
function setCustom(on) {
  A.custom = on;
  $('#as-custom').hidden = !on;
  $('#as-rule-reset').hidden = !on;
}
const previewSoon = debounce(() => preview(), 250);
async function preview() {
  if (!S.st?.book || S.running) return;
  loadPayees();
  if (!S.rules.loaded) loadRules();
  const q = $('#as-q').value;
  if (!A.targetTouched) $('#as-target').value = q.trim();
  const seq = ++A.seq;
  const body = {query: q, mode: $('#as-mode').value, name_only: $('#as-name').checked, only_changed: $('#as-only').checked, rule: A.custom ? $('#as-rule').value : '',
    target: $('#as-target').value, position: $('#as-pos').value};
  let d;
  try { d = await api('rulepreview', {method: 'POST', json: body, raw: true}); } catch (e) { toast(e.message, true); return; }
  if (seq !== A.seq) return;        // an older answer
  A.last = d;
  renderAssistant(d);
}
function ruleLineNo(pattern) {
  const lines = $('#rules-text').value.split('\n');
  for (let i = 0; i < lines.length; i++) {
    const l = lines[i].trim();
    if (!l || l.startsWith('#')) continue;
    const j = l.lastIndexOf('=>');
    if (j > 0 && l.slice(0, j).trim() === pattern) return i + 1;
  }
  return 0;
}
function renderAssistant(d) {
  if (!A.custom && document.activeElement !== $('#as-rule')) $('#as-rule').value = d.pattern || '';
  $('#as-line').textContent = d.line || '';
  $('#as-line-label').hidden = !d.line;
  const err = $('#as-error');
  err.hidden = !d.error;
  err.textContent = d.error || '';
  const notes = [...(d.notes || [])];
  const dup = d.pattern && !d.error ? ruleLineNo(d.pattern) : 0;
  if (dup) notes.push(t('asst.dup', {n: dup}));
  if (S.rules.dirty) notes.push(t('asst.dirty'));
  $('#as-notes').hidden = !notes.length;
  $('#as-notes').replaceChildren(...notes.map(x => el('div', {}, x)));
  const target = $('#as-target').value.trim();
  const tot = d.totals || {};
  const sum = [];
  if (d.pattern && !d.error) {
    if (!tot.texts) sum.push(el('p', {class: 'summary-line'}, t('asst.none')));
    else {
      sum.push(el('p', {class: 'summary-line'}, el('strong', {}, tn('asst.summary', tot.texts, {texts: n(tot.texts), bookings: n(tot.bookings)}))));
      if (!target) sum.push(el('p', {class: 'summary-line muted'}, t('asst.need_target')));
      else if (tot.changed) sum.push(el('p', {class: 'summary-line'}, t('asst.changed', {n: n(tot.changed), target})));
      else if (!tot.kept) sum.push(el('p', {class: 'summary-line muted'}, t('asst.unchanged')));
      if (tot.kept) sum.push(el('p', {class: 'summary-line'}, t('asst.kept', {n: n(tot.kept)})));
      if ((d.affected || []).length) {
        sum.push(el('div', {class: 'chips'}, el('span', {class: 'muted small'}, t('asst.from')),
          d.affected.slice(0, 8).map(a => el('span', {class: 'chip', title: t('side.' + a.side)}, a.name, el('i', {}, t('kind.' + a.side)), el('b', {}, n(a.count))))));
      }
      const ex = (d.existing?.expense || 0) + (d.existing?.revenue || 0);
      if (target && ex) sum.push(el('p', {class: 'summary-line muted'}, t('asst.existing', {name: target, n: n(ex)})));
    }
  }
  $('#as-summary').replaceChildren(...sum);
  const names = [...new Set([...(d.names || []), $('#as-q').value.trim()].filter(x => x && x !== target))].slice(0, 8);
  $('#as-names').replaceChildren(...(names.length && d.pattern && !d.error ? [el('span', {class: 'muted small'}, t('asst.names')),
    ...names.map(x => el('button', {type: 'button', class: 'chip', onclick: () => { $('#as-target').value = x; A.targetTouched = true; preview(); }}, x))] : []));
  const ok = !!d.line && !d.error;
  $('#as-insert').disabled = !ok || !!dup;
  $('#as-save').disabled = !ok || !!dup || S.running;
  // tables
  const box = $('#as-results');
  box.hidden = !(d.pattern && !d.error && ((d.matches || []).length || (d.similar || []).length));
  $('#as-n').textContent = tot.texts ? n(tot.texts) : '';
  const head = el('tr', {}, [t('col.text'), t('col.side'), t('col.count'), t('asst.col_now'), '', t('asst.col_new')].map((x, i) => el('th', {class: i === 2 ? 'num' : ''}, x)));
  $('#as-table').replaceChildren(el('thead', {}, head), el('tbody', {}, (d.matches || []).map(m => el('tr', {class: m.changes ? '' : 'same'},
    el('td', {class: 'text'}, hov(m.text, m.side)), el('td', {}, t('kind.' + m.side)), el('td', {class: 'num'}, n(m.count)),
    el('td', {class: 'text'}, m.payee, ' ', el('span', {class: 'muted small'}, srcLabel(m.source))), el('td', {class: 'arrow'}, '→'),
    el('td', {class: 'text new'}, m.kept ? [m.payee, ' ', el('span', {class: 'badge warn'}, t('asst.kept_badge'))] : (m.new || '?'))))));
  $('#as-more').hidden = !d.more;
  $('#as-more').textContent = d.more ? t('asst.more', {n: n(d.more)}) : '';
  const sim = d.similar || [];
  $('#as-similar-box').hidden = !sim.length;
  $('#as-similar').replaceChildren(el('thead', {}, el('tr', {}, [t('col.text'), t('col.side'), t('col.count'), t('col.payee')].map((x, i) => el('th', {class: i === 2 ? 'num' : ''}, x)))),
    el('tbody', {}, sim.map(m => el('tr', {}, el('td', {class: 'text'}, hov(m.text, m.side)), el('td', {}, t('kind.' + m.side)), el('td', {class: 'num'}, n(m.count)), el('td', {class: 'text'}, m.payee)))));
}
/** Inserts a rule line after all rules or before the first one (and its comment block). */
function insertRule(line, position) {
  const ta = $('#rules-text');
  const lines = ta.value.replace(/\n+$/, '').split('\n');
  if (lines.length === 1 && lines[0] === '') lines.length = 0;
  const first = lines.findIndex(l => l.trim() !== '' && !l.trim().startsWith('#'));
  if (position === 'top' && first >= 0) {
    let at = first;
    while (at > 0 && lines[at - 1].trim().startsWith('#') && first - at < 4) at--;
    if (at > 0 && lines[at - 1].trim().startsWith('#')) at = first;   // a long comment block (the file header): directly before the rule
    lines.splice(at, 0, line, ...(at < first ? [''] : []));
  } else {
    if (lines.length && lines[lines.length - 1].trim() !== '') lines.push('');
    lines.push(line);
  }
  ta.value = lines.join('\n') + '\n';
  rulesChanged();
}
async function adoptAssistant(save) {
  const d = A.last;
  if (!d || !d.line || d.error) return;
  if (!S.rules.loaded) await loadRules();
  insertRule(d.line.trim(), $('#as-pos').value);
  if (save) {
    await saveRules();
  } else {
    toast(t('asst.inserted'));
  }
  renderAssistant(d);
}
$('#as-q').addEventListener('input', previewSoon);
$('#as-mode').addEventListener('change', () => { setCustom(false); preview(); });
$('#as-name').addEventListener('change', () => { setCustom(false); preview(); });
$('#as-pos').addEventListener('change', () => preview());
try { $('#as-only').checked = localStorage.getItem('ffgc.as-only') === '1'; } catch (e) {}
$('#as-only').addEventListener('change', () => { try { localStorage.setItem('ffgc.as-only', $('#as-only').checked ? '1' : '0'); } catch (e) {} preview(); });
$('#as-target').addEventListener('input', () => { A.targetTouched = true; previewSoon(); });
$('#as-rule').addEventListener('input', () => { setCustom(true); previewSoon(); });
$('#as-rule-reset').addEventListener('click', () => { setCustom(false); preview(); });
$('#as-insert').addEventListener('click', () => adoptAssistant(false));
$('#as-save').addEventListener('click', () => adoptAssistant(true));

// ------------------------------------------------------------------ rules editor
function markDirty() {
  $('#rules-dirty').hidden = !S.rules.dirty;
  $('#config-dirty').hidden = !S.cfg.dirty;
}
function rulesChanged() { S.rules.dirty = $('#rules-text').value !== S.rules.server; markDirty(); }
async function loadRules(force = false) {
  if ((S.rules.loaded && !force) || S.rules.dirty) return;
  try {
    const d = await api('text', {params: {what: 'rules'}});
    S.rules.server = d.text; S.rules.loaded = true; S.rules.time = d.time;
    $('#rules-text').value = d.text;
    rulesChanged();
  } catch (e) { toast(e.message, true); }
}
async function saveRules() {
  if (S.running) { toast(t('err.job_running'), true); return; }
  const text = $('#rules-text').value;
  const errBox = $('#rules-error');
  try {
    const d = await api('save', {method: 'POST', json: {what: 'rules', text}, raw: true});
    if (!d.ok) { errBox.hidden = false; errBox.textContent = d.error; return; }
    errBox.hidden = true;
    S.rules.server = text; S.rules.dirty = false; markDirty();
    toast(t('ed.saved'));
    refresh();
  } catch (e) { toast(e.message, true); }
}
$('#rules-text').addEventListener('input', rulesChanged);
$('#rules-save').addEventListener('click', saveRules);
$('#rules-revert').addEventListener('click', () => { $('#rules-text').value = S.rules.server; rulesChanged(); $('#rules-error').hidden = true; });
$('#rules-open').addEventListener('change', async e => { const f = e.target.files[0]; if (!f) return; $('#rules-text').value = await f.text(); rulesChanged(); e.target.value = ''; toast(t('ed.opened', {name: f.name})); });

// ------------------------------------------------------------------ account mapping / options
const AS = ['asset', 'liability', 'category', 'ignore', 'skip'];
const ROLES = ['defaultAsset', 'savingAsset', 'sharedAsset', 'cashWalletAsset', 'ccAsset'];
const OPTS = [
  ['payee_min_count', 'number'], ['payee_fallback', 'text'], ['payee_split_dash', 'bool'], ['payee_merge_prefix', 'bool'],
  ['payee_group_by_iban', 'bool'], ['payee_set_iban', 'bool'], ['category_names', ['strip-root', 'full-path']], ['reconciled_states', 'text'],
  ['opening_balances', 'bool'], ['clearing_account', 'text'], ['import_tag', 'text'], ['multisource', 'bool'], ['apply_rules', 'bool'], ['fire_webhooks', 'bool'],
];
const clone = o => JSON.parse(JSON.stringify(o));

async function loadConfig(force = false) {
  if ((S.cfg.loaded && !force) || S.cfg.dirty) return;
  try {
    const d = await api('text', {params: {what: 'config'}});
    S.cfg.server = d.text; S.cfg.loaded = true; S.cfg.time = d.time;
    setConfigText(d.text, false);
  } catch (e) { toast(e.message, true); }
}
function setConfigText(text, dirty) {
  let obj = null;
  try { obj = JSON.parse(text); } catch (e) { obj = null; }
  $('#config-text').value = text;
  if (!obj || typeof obj.accounts !== 'object') {
    S.cfg.obj = null; setRaw(true);
    $('#config-error').hidden = !text; $('#config-error').textContent = t('acc.invalid_json');
  } else {
    S.cfg.obj = obj; if (!dirty) S.cfg.orig = clone(obj);
    $('#config-error').hidden = true;
    renderAccounts(); renderOptions();
    const tag = obj.options?.import_tag; if (tag && !$('#pur-tag').dataset.touched) $('#pur-tag').value = tag;
  }
  S.cfg.dirty = dirty; markDirty();
}
function cfgChanged() { S.cfg.dirty = true; markDirty(); }
function setRaw(on) {
  S.cfg.raw = on;
  $('#config-structured').hidden = on; $('#config-rawbox').hidden = !on;
  $('#config-raw-toggle').textContent = on ? t('acc.structured') : t('acc.raw');
}
$('#config-raw-toggle').addEventListener('click', () => {
  if (!S.cfg.raw) {
    if (S.cfg.obj && S.cfg.dirty) $('#config-text').value = JSON.stringify(S.cfg.obj, null, 2) + '\n';
    setRaw(true);
    return;
  }
  const text = $('#config-text').value;
  let obj;
  try { obj = JSON.parse(text); } catch (e) { $('#config-error').hidden = false; $('#config-error').textContent = t('acc.invalid_json') + ' ' + e.message; return; }
  S.cfg.obj = obj; $('#config-error').hidden = true; renderAccounts(); renderOptions(); setRaw(false);
});
$('#config-text').addEventListener('input', cfgChanged);

function accKind(a) { return ['asset', 'liability'].includes(a.as) ? 'bs' : a.as === 'category' ? 'category' : 'off'; }
function accChanged(g) { const o = S.cfg.orig?.accounts?.[g]; return !o || JSON.stringify(o) !== JSON.stringify(S.cfg.obj.accounts[g]); }
function sel(values, value, labels, onchange) {
  const s = el('select', {onchange: e => onchange(e.target.value)}, values.map(v => el('option', {value: v, selected: v === value}, labels ? labels(v) : v)));
  return s;
}
function renderAccounts() {
  const obj = S.cfg.obj; if (!obj) return;
  const q = $('#acc-q').value.toLowerCase().trim(), kind = $('#acc-kind').value;
  const entries = Object.entries(obj.accounts).filter(([g, a]) => (!q || (a.gnucash + ' ' + (a.name || '')).toLowerCase().includes(q))
    && (!kind || (kind === 'changed' ? accChanged(g) : accKind(a) === kind)));
  $('#acc-count').textContent = tn('tbl.count', entries.length, {total: n(entries.length)});
  const head = el('tr', {}, [t('acc.col_gnucash'), t('acc.col_splits'), t('acc.col_as'), t('acc.col_name'), t('acc.col_details'), t('acc.col_active')].map((x, i) => el('th', {class: i === 1 ? 'num' : ''}, x)));
  const rows = entries.map(([g, a]) => {
    const tr = el('tr', {class: accChanged(g) ? 'changed' : ''});
    const upd = (k, v) => { if (v === null) delete a[k]; else a[k] = v; tr.className = accChanged(g) ? 'changed' : ''; cfgChanged(); };
    const details = el('div', {class: 'row'});
    const fill = () => {
      details.replaceChildren();
      if (a.as === 'asset') details.append(sel(ROLES, a.role || 'defaultAsset', v => t('role.' + v), v => upd('role', v)));
      if (a.as === 'liability') {
        details.append(sel(['debt', 'loan', 'mortgage'], a.liability_type || 'debt', v => t('liab.' + v), v => upd('liability_type', v)),
          sel(['debit', 'credit'], a.direction || 'debit', v => t('dir.' + v), v => upd('direction', v)));
      }
      if (a.currency && a.currency !== 'EUR') details.append(el('span', {class: 'badge'}, a.currency));
    };
    fill();
    const name = el('input', {type: 'text', value: a.name || '', 'aria-label': t('acc.col_name'), disabled: ['ignore', 'skip'].includes(a.as)});
    name.addEventListener('input', () => upd('name', name.value));
    const active = el('input', {type: 'checkbox', checked: a.active !== false, 'aria-label': t('acc.col_active'), disabled: !['asset', 'liability'].includes(a.as)});
    active.addEventListener('change', () => upd('active', active.checked));
    const as = sel(AS, a.as, v => t('as.' + v), v => {
      a.as = v;
      if (v === 'asset' && !a.role) a.role = 'defaultAsset';
      if (v === 'liability') { a.liability_type ||= 'debt'; a.direction ||= 'debit'; }
      if (['asset', 'liability', 'category'].includes(v) && !a.name) { a.name = a.gnucash.split(':').pop(); name.value = a.name; }
      name.disabled = ['ignore', 'skip'].includes(v); active.disabled = !['asset', 'liability'].includes(v);
      fill(); upd('as', v);
    });
    tr.append(el('td', {class: 'text'}, a.gnucash, ' ', el('span', {class: 'badge'}, a.type || '')), el('td', {class: 'num'}, n(a.splits || 0)), el('td', {}, as), el('td', {}, name), el('td', {}, details), el('td', {}, active));
    return tr;
  });
  $('#acc-table').replaceChildren(el('thead', {}, head), el('tbody', {}, rows));
}
$('#acc-q').addEventListener('input', debounce(renderAccounts));
$('#acc-kind').addEventListener('change', renderAccounts);

function renderOptions() {
  const obj = S.cfg.obj; if (!obj) return;
  obj.options ||= {};
  $('#opt-list').replaceChildren(...OPTS.map(([k, type]) => {
    const v = obj.options[k];
    let input;
    const set = val => { obj.options[k] = val; cfgChanged(); };
    if (type === 'bool') { input = el('input', {type: 'checkbox', checked: !!v, id: 'opt-' + k}); input.addEventListener('change', () => set(input.checked)); }
    else if (Array.isArray(type)) input = sel(type, v, x => t('optv.' + x), val => set(val));
    else { input = el('input', {type: type === 'number' ? 'number' : 'text', value: v ?? '', min: type === 'number' ? '1' : null, id: 'opt-' + k}); input.addEventListener('input', () => set(type === 'number' ? Math.max(1, parseInt(input.value, 10) || 1) : input.value)); }
    return el('div', {class: 'opt'}, el('label', {for: 'opt-' + k}, t('opt.' + k), ' ', el('span', {class: 'k muted'}, k)), el('div', {}, input), el('div', {class: 'd'}, rich(t('optd.' + k))));
  }));
}

async function saveConfig() {
  if (S.running) { toast(t('err.job_running'), true); return; }
  let text;
  if (S.cfg.raw) text = $('#config-text').value;
  else if (S.cfg.obj) text = JSON.stringify(S.cfg.obj, null, 2) + '\n';
  else return;
  const errBox = $('#config-error');
  try {
    const d = await api('save', {method: 'POST', json: {what: 'config', text}, raw: true});
    if (!d.ok) { errBox.hidden = false; errBox.textContent = d.error; return; }
    errBox.hidden = true;
    S.cfg.server = text; S.cfg.dirty = false; S.cfg.loaded = false; markDirty();
    toast(t('ed.saved'));
    refresh();
  } catch (e) { toast(e.message, true); }
}
$('#config-save').addEventListener('click', saveConfig);
$('#config-revert').addEventListener('click', () => { setConfigText(S.cfg.server, false); if (S.cfg.obj) setRaw(false); });
$('#config-open').addEventListener('change', async e => { const f = e.target.files[0]; if (!f) return; setConfigText(await f.text(), true); e.target.value = ''; toast(t('ed.opened', {name: f.name})); });

// ------------------------------------------------------------------ Firefly
const CONN_KEY = 'ffgc_conn';
function conn() { return {url: B.fixedUrl || $('#ff-url').value.trim(), token: $('#ff-token').value.trim()}; }
function saveConn() {
  try {
    if ($('#ff-remember').checked) sessionStorage.setItem(CONN_KEY, JSON.stringify(conn()));
    else sessionStorage.removeItem(CONN_KEY);
  } catch (e) { /* storage unavailable */ }
}
function initConn() {
  if (B.fixedUrl) { $('#ff-url').value = B.fixedUrl; $('#ff-url').readOnly = true; $('#ff-url').title = t('ff.fixed'); }
  try {
    const c = JSON.parse(sessionStorage.getItem(CONN_KEY) || 'null');
    if (c) { if (!B.fixedUrl) $('#ff-url').value = c.url || ''; $('#ff-token').value = c.token || ''; $('#ff-remember').checked = true; }
  } catch (e) { /* ignore */ }
  for (const id of ['#ff-url', '#ff-token', '#ff-remember']) $(id).addEventListener('change', saveConn);
}
function needConn() {
  const c = conn();
  if (!c.url || !/^https?:\/\//i.test(c.url)) { toast(t('err.url'), true); $('#ff-url').focus(); return null; }
  if (!c.token) { toast(t('err.token'), true); $('#ff-token').focus(); return null; }
  return c;
}
$('#ff-test').addEventListener('click', async () => {
  const c = needConn(); if (!c) return;
  const box = $('#ff-state');
  box.className = 'conn-state muted'; box.textContent = t('ff.testing');
  try {
    const d = await api('connect', {method: 'POST', json: c, raw: true});
    if (d.ok) { box.className = 'conn-state notice ok'; box.textContent = t('ff.ok', {version: d.version, email: d.email, url: d.url}); }
    else { box.className = 'conn-state notice bad'; box.textContent = d.error; }
  } catch (e) { box.className = 'conn-state notice bad'; box.textContent = e.message; }
});

async function runJob(type, options = {}) {
  if (S.running) { toast(t('err.job_running'), true); return false; }
  const firefly = ['dryrun', 'import', 'export', 'purge-preview', 'purge'].includes(type);
  const c = firefly ? needConn() : {};
  if (firefly && !c) return false;
  try {
    await api('run', {method: 'POST', json: {type, url: c.url, token: c.token, options}});
    S.jobHidden = null;
    await refresh();
    window.scrollTo({top: 0, behavior: 'smooth'});
    return true;
  } catch (e) { toast(e.message, true); return false; }
}
function importOptions() {
  return {from: $('#imp-from').value, to: $('#imp-to').value, limit: $('#imp-limit').value, update_payees: $('#imp-update').checked, no_multisource: $('#imp-nomulti').checked};
}
$('#imp-dry').addEventListener('click', () => runJob('dryrun', importOptions()));
$('#imp-run').addEventListener('click', async () => {
  const c = needConn(); if (!c) return;
  const o = importOptions(), sum = S.st?.summary;
  const body = [t('imp.confirm_body', {n: n(sum?.importable?.transactions || 0), url: c.url})];
  if (o.from || o.to) body.push(t('imp.confirm_range', {from: o.from || '…', to: o.to || '…'}));
  if (o.limit) body.push(t('imp.confirm_limit', {n: o.limit}));
  if (o.update_payees) body.push(t('imp.confirm_update'));
  body.push(t('imp.confirm_note'));
  if (await ask({title: t('imp.confirm_title'), body, ok: t('imp.run')})) runJob('import', o);
});
$('#exp-run').addEventListener('click', () => runJob('export', {from: $('#exp-from').value, to: $('#exp-to').value, uncompressed: $('#exp-plain').checked}));
$('#exp-compare').addEventListener('click', () => runJob('compare', {by_year: $('#exp-byyear').checked}));
$('#pur-tag').addEventListener('input', () => { $('#pur-tag').dataset.touched = '1'; });
const purgeOptions = () => ({tag: $('#pur-tag').value.trim(), accounts: $('#pur-accounts').checked});
$('#pur-preview').addEventListener('click', () => runJob('purge-preview', purgeOptions()));
$('#pur-run').addEventListener('click', async () => {
  const c = needConn(); if (!c) return;
  const o = purgeOptions();
  const body = [t('pur.confirm_body', {tag: o.tag || 'GnuCash-Import', url: c.url})];
  if (o.accounts) body.push(t('pur.confirm_accounts'));
  if (await ask({title: t('pur.confirm_title'), body, ok: t('pur.run'), danger: true, word: t('pur.word')})) runJob('purge', o);
});

function renderFirefly() {
  const st = S.st, sum = st.summary, f = st.files || {};
  const hint = $('#import-hint');
  if (!st.book) hint.textContent = t('imp.need_book');
  else if (!sum) hint.textContent = t('imp.need_plan');
  else hint.textContent = t('imp.hint', {n: n(sum.importable?.transactions || 0)});
  const canImport = !!st.book && !!sum && sum.selfcheck?.ok !== false;
  $('#imp-dry').disabled = S.running || !canImport;
  $('#imp-run').disabled = S.running || !canImport;
  for (const id of ['#exp-run', '#pur-preview', '#pur-run', '#ff-test']) $(id).disabled = S.running && id !== '#ff-test';
  const dls = [];
  if (st.logs?.dryrun) dls.push(el('a', {class: 'btn small', href: '?a=dl&what=log-dryrun'}, t('dl.drylog')));
  if (st.logs?.import) dls.push(el('a', {class: 'btn small', href: '?a=dl&what=log-import'}, t('dl.importlog')));
  if (f.importlog) dls.push(el('a', {class: 'btn small', href: '?a=dl&what=importlog'}, t('dl.importjsonl')));
  $('#imp-downloads').replaceChildren(...dls);
  const exp = f.export && !(st.job?.type === 'export' && S.running);
  $('#exp-result').hidden = !exp;
  if (exp) {
    $('#exp-info').textContent = t('exp.info', {time: fmtTime(f.export.time), size: fmtSize(f.export.size)});
    $('#exp-compare').disabled = S.running || !st.book;
    $('#exp-compare').title = st.book ? '' : t('exp.compare_need_book');
  }
}

// ------------------------------------------------------------------ misc wiring
$('#job-cancel').addEventListener('click', async () => {
  if (!(await ask({title: t('job.cancel_title'), body: [t('job.cancel_body')], ok: t('job.cancel'), danger: true}))) return;
  try { await api('cancel', {method: 'POST', json: {}}); refresh(); } catch (e) { toast(e.message, true); }
});
$('#job-close').addEventListener('click', () => {
  const j = S.st?.job;
  if (j) { S.jobHidden = j.type + ':' + j.started; try { sessionStorage.setItem('ffgc_job_hidden', S.jobHidden); } catch (e) { /* ignore */ } }
  $('#job').hidden = true;
});
try { S.jobHidden = sessionStorage.getItem('ffgc_job_hidden'); } catch (e) { /* ignore */ }
$('#replan').addEventListener('click', () => runJob('plan'));
$('#reset').addEventListener('click', async () => {
  if (!(await ask({title: t('book.reset_title'), body: [t('book.reset_body')], ok: t('book.reset'), danger: true}))) return;
  try {
    await api('reset', {method: 'POST', json: {}});
    S.rules = {server: '', time: -1, loaded: false, dirty: false};
    S.cfg = {server: '', time: -1, loaded: false, dirty: false, raw: false, obj: null, orig: null};
    S.payees = S.map = S.sugg = S.det = null; S.dataTime = -1;
    $('#rules-text').value = ''; markDirty();
    showTab('book');
    refresh();
  } catch (e) { toast(e.message, true); }
});
const drop = $('#drop');
drop.addEventListener('keydown', e => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); $('#book-file').click(); } });
for (const b of $$('[data-pick]')) b.addEventListener('click', () => $(b.dataset.pick).click());
$('#book-file').addEventListener('change', e => { upload(e.target.files[0]); e.target.value = ''; });
drop.addEventListener('dragover', e => { e.preventDefault(); drop.classList.add('over'); });
drop.addEventListener('dragleave', () => drop.classList.remove('over'));
drop.addEventListener('drop', e => { e.preventDefault(); drop.classList.remove('over'); upload(e.dataTransfer.files[0]); });
for (const b of $$('.tabs [data-tab]')) b.addEventListener('click', () => showTab(b.dataset.tab));
window.addEventListener('hashchange', () => { if (location.hash.slice(1) !== S.tab) showTab(location.hash.slice(1)); });
for (const b of $$('.subtabs [data-sub]')) b.addEventListener('click', () => showSub(b.dataset.sub));
for (const b of $$('[data-lang]')) {
  b.addEventListener('click', () => {
    document.cookie = `ffgc_lang=${b.dataset.lang}; path=${B.cookiePath}; max-age=31536000; SameSite=Strict${B.secure ? '; Secure' : ''}`;
    location.reload();
  });
}
document.addEventListener('keydown', e => {
  if ((e.ctrlKey || e.metaKey) && e.key === 's') {
    if (S.tab === 'payees' && S.sub === 'rules') { e.preventDefault(); saveRules(); }
    if (S.tab === 'accounts') { e.preventDefault(); saveConfig(); }
  }
});
window.addEventListener('beforeunload', e => { if (S.rules.dirty || S.cfg.dirty) { e.preventDefault(); e.returnValue = ''; } });

if (B.problems.length) {
  const p = $('#problems');
  p.hidden = false;
  p.replaceChildren(el('strong', {}, t('app.problems')), el('ul', {class: 'facts'}, B.problems.map(x => el('li', {}, x))));
}
initConn();
showSub('payees');
showTab(location.hash.slice(1) || 'book');
refresh();
})();
JS;
}

main();
