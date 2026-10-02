<?php
/*
 * Tests of web.php over HTTP: starts the PHP built-in server, uploads the synthetic book of
 * run-tests.php and exercises the API (no browser, no Firefly). Run: php tests/web-tests.php
 * License: GPL-3.0-or-later
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$tmp  = sys_get_temp_dir().'/ffgc-webtest-'.getmypid();
@mkdir($tmp);
$book = $tmp.'/Mein Buch.gnucash';
passthru(escapeshellarg(PHP_BINARY).' '.escapeshellarg(__DIR__.'/run-tests.php').' --write-book='.escapeshellarg($book), $rc);
if (0 !== $rc || !is_file($book)) {
    fwrite(STDERR, "cannot build the test book\n");
    exit(2);
}
$cfg = $tmp.'/web.config.php';
file_put_contents($cfg, '<?php return '.var_export(['data_dir' => $tmp.'/data', 'firefly_url' => ''], true).';');

$failures = 0;
$checks   = 0;
function check(string $name, bool $ok, string $detail = ''): void
{
    global $failures, $checks;
    ++$checks;
    if (!$ok) {
        ++$failures;
        fwrite(STDERR, "FAIL {$name}".('' !== $detail ? ": {$detail}" : '')."\n");
    }
}

// ---- server
$port = random_int(18100, 18999);
$env  = getenv() + ['FFGC_WEB_CONFIG' => $cfg];
$env['FFGC_WEB_CONFIG'] = $cfg;
$srv  = proc_open([PHP_BINARY, '-d', 'upload_max_filesize=20M', '-d', 'post_max_size=20M', '-S', '127.0.0.1:'.$port, $root.'/web.php'],
    [0 => ['file', '/dev/null', 'r'], 1 => ['file', $tmp.'/server.log', 'w'], 2 => ['file', $tmp.'/server.log', 'a']], $pipes, $root, $env);
register_shutdown_function(static function () use ($srv, $tmp): void {
    proc_terminate($srv);
    exec('rm -rf '.escapeshellarg($tmp));
});
$base = 'http://127.0.0.1:'.$port.'/';
$jar  = $tmp.'/cookies.txt';

/** @return array{0:int, 1:string, 2:string} status, headers, body */
function http(string $method, string $query, array $opt = []): array
{
    global $base, $jar, $csrf;
    $ch = curl_init($base.$query);
    curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_HEADER => true, CURLOPT_COOKIEJAR => $jar, CURLOPT_COOKIEFILE => $jar, CURLOPT_TIMEOUT => 60]);
    $headers = ['X-CSRF-Token: '.($opt['csrf'] ?? $csrf ?? '')];
    if (isset($opt['json'])) {
        $headers[] = 'Content-Type: application/json';
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($opt['json']));
    }
    if (isset($opt['form'])) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, $opt['form']);
    }
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $res = (string) curl_exec($ch);
    $hs  = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);

    return [(int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE), substr($res, 0, $hs), substr($res, $hs)];
}

function api(string $method, string $query, array $opt = []): array
{
    [$status, , $body] = http($method, $query, $opt);
    $data = json_decode($body, true);

    return is_array($data) ? $data + ['_status' => $status] : ['ok' => false, 'error' => 'no JSON: '.substr($body, 0, 200), '_status' => $status];
}

function waitJob(string $type, int $seconds = 60): array
{
    $t0 = time();
    do {
        usleep(250000);
        $st = api('GET', '?a=status');
    } while (time() - $t0 < $seconds && (($st['job']['type'] ?? '') !== $type || 'running' === ($st['job']['state'] ?? 'running')));

    return $st;
}

for ($i = 0; $i < 50; ++$i) {
    if (@fsockopen('127.0.0.1', $port)) {
        break;
    }
    usleep(100000);
}

// ---- page, cookie, CSRF
[$status, $headers, $page] = http('GET', '');
check('page loads', 200 === $status && str_contains($page, 'GnuCash ⇄ Firefly III'), (string) $status);
check('CSP with nonce', 1 === preg_match("/Content-Security-Policy: .*script-src 'nonce-/", $headers));
check('workspace cookie is HttpOnly and SameSite', 1 === preg_match('/Set-Cookie: ffgc_ws=[a-f0-9]{32}\.[a-f0-9]{64};.*HttpOnly; SameSite=Strict/i', $headers), $headers);
$csrf = 1 === preg_match('~<script type="application/json" id="boot">(.*?)</script>~s', $page, $m) ? (string) (json_decode($m[1], true)['csrf'] ?? '') : '';
check('boot data with CSRF token', 64 === strlen($csrf));
check('no inline style attributes (CSP)', !str_contains($page, 'style="'));
$r = api('POST', '?a=reset', ['json' => [], 'csrf' => 'wrong']);
check('POST without valid CSRF token is refused', 403 === $r['_status'] && false === $r['ok']);

// ---- upload + plan
$r = api('POST', '?a=upload', ['form' => ['book' => new CURLFile(__FILE__, 'text/plain', 'x.gnucash')]]);
check('not a GnuCash file is refused', false === $r['ok'] && 400 === $r['_status'], json_encode($r));
file_put_contents($tmp.'/broken.gnucash', gzencode('<?xml version="1.0"?><gnc-v2><broken'));
$r  = api('POST', '?a=upload', ['form' => ['book' => new CURLFile($tmp.'/broken.gnucash', 'application/gzip', 'broken.gnucash')]]);
$st = waitJob('plan');
check('broken book: plan fails and the error is shown', true === $r['ok'] && 'failed' === ($st['job']['state'] ?? '') && null === $st['summary'] && str_contains((string) $st['job']['log'], 'ERROR'), json_encode($st['job'] ?? null));
$r = api('POST', '?a=upload', ['form' => ['book' => new CURLFile($book, 'application/gzip', 'Mein Buch.gnucash'), 'keep' => '0']]);
check('upload accepted', true === $r['ok'], json_encode($r));
$st = waitJob('plan');
check('plan job ok', 'ok' === ($st['job']['state'] ?? ''), json_encode($st['job'] ?? null));
check('summary: 28 importable transactions, self-check ok', 28 === ($st['summary']['importable']['transactions'] ?? null) && true === ($st['summary']['selfcheck']['ok'] ?? null), json_encode($st['summary']['importable'] ?? null));
check('book name kept', 'Mein Buch.gnucash' === ($st['book']['name'] ?? null));
check('log without server paths', !str_contains((string) ($st['job']['log'] ?? ''), $tmp));
check('not stale after plan', false === ($st['stale'] ?? null));

$t = api('GET', '?a=table&what=payees');
check('payee table rows', ($t['ok'] ?? false) && count($t['rows']) >= 3 && isset($t['rows'][0]['payee'], $t['rows'][0]['transactions']), (string) count($t['rows'] ?? []));
$t = api('GET', '?a=table&what=map');
check('payee map rows', ($t['ok'] ?? false) && count($t['rows']) > 5 && isset($t['rows'][0]['booking_text']));
$t = api('GET', '?a=suggestions');
check('suggestions', ($t['ok'] ?? false) && is_array($t['items']));

// ---- rules editor
$rules = api('GET', '?a=text&what=rules');
check('rules template', ($rules['ok'] ?? false) && str_contains($rules['text'], 'Payee rules'));
$r = api('POST', '?a=save', ['json' => ['what' => 'rules', 'text' => "/broken(/i => X\n"]]);
check('invalid rule refused with line number', false === $r['ok'] && str_contains((string) $r['error'], 'payee-rules.txt:1'), json_encode($r));
$r = api('POST', '?a=save', ['json' => ['what' => 'rules', 'text' => $rules['text']."/^REWE/i => REWE\n"]]);
check('valid rules saved', true === $r['ok'], json_encode($r));
$st = waitJob('plan');
$t  = api('GET', '?a=table&what=map');
$rewe = array_values(array_filter($t['rows'], static fn ($x) => str_starts_with($x['booking_text'], 'REWE Musterstadt')));
check('rule applied after re-plan', 'ok' === ($st['job']['state'] ?? '') && 'REWE' === ($rewe[0]['payee'] ?? null), json_encode($rewe[0] ?? null));

// ---- rule assistant
$pv = static fn (array $b): array => api('POST', '?a=rulepreview', ['json' => $b]);
$r  = $pv(['query' => 'REWE Musterstadt', 'mode' => 'words', 'target' => 'REWE Markt', 'position' => 'end']);
check('assistant builds the rule', '/\bREWE\s+Musterstadt\b/i' === ($r['pattern'] ?? null) && str_contains((string) ($r['line'] ?? ''), '=> REWE Markt'), json_encode($r['pattern'] ?? $r));
check('assistant: earlier rule keeps its texts at the end', ($r['totals']['texts'] ?? 0) > 0 && $r['totals']['kept'] === $r['totals']['bookings'] && 0 === $r['totals']['changed'], json_encode($r['totals'] ?? null));
$r = $pv(['query' => 'REWE Musterstadt', 'mode' => 'words', 'target' => 'REWE Markt', 'position' => 'top']);
check('assistant: before all rules it wins', ($r['totals']['changed'] ?? 0) === ($r['totals']['bookings'] ?? -1) && 'REWE Markt' === ($r['matches'][0]['new'] ?? null) && 'REWE' === ($r['matches'][0]['payee'] ?? null), json_encode($r['matches'][0] ?? null));
$r = $pv(['query' => 'REWE Musterstadt', 'mode' => 'words', 'target' => 'REWE Markt', 'position' => 'end', 'only_changed' => true]);
check('assistant: only_changed hides unaffected texts, totals stay', [] === ($r['matches'] ?? ['x']) && ($r['totals']['kept'] ?? 0) > 0, json_encode($r['totals'] ?? null));
$r = $pv(['query' => 'Musterstadt REWE', 'mode' => 'words', 'target' => 'X']);
check('assistant: wrong order finds nothing, but similar texts', 0 === ($r['totals']['texts'] ?? -1) && [] !== ($r['similar'] ?? []), json_encode($r['similar'] ?? null));
$r = $pv(['query' => 'Musterstadt REWE', 'mode' => 'all', 'target' => 'X', 'position' => 'top']);
check('assistant: any order finds them', ($r['totals']['texts'] ?? 0) >= 2, json_encode($r['totals'] ?? null));
$r = $pv(['rule' => '/broken(/i', 'target' => 'X']);
check('assistant: invalid hand-written rule is reported', '' !== (string) ($r['error'] ?? '') && '' === (string) ($r['line'] ?? 'x'), json_encode($r));
$r = $pv(['query' => 'Kiosk', 'target' => 'A => B']);
check('assistant: "=>" in the name is refused', '' !== (string) ($r['error'] ?? ''));
$r = $pv(['rule' => 'memo:/Brot/', 'target' => 'Bäcker']);
check('assistant: memo rules are previewed from the split memos', null === $r['error'] && ($r['totals']['texts'] ?? 0) >= 1, json_encode($r['totals'] ?? null));
$r = $pv(['rule' => 'memo:/Brot/ && konto:/^Nichtda$/', 'target' => 'Bäcker']);
check('assistant: memo && konto preview', null === $r['error'] && 0 === ($r['totals']['texts'] ?? -1), json_encode($r['totals'] ?? null));
$prows = api('GET', '?a=table&what=payees')['rows'] ?? [];
$name  = (string) ($prows[0]['payee'] ?? '');
$want  = array_sum(array_map(static fn ($x) => $x['payee'] === $name ? (int) $x['transactions'] : 0, $prows));
$r     = $pv(['query' => 'Kiosk', 'target' => mb_strtoupper($name)]);
check('assistant: existing counterparty is reported (case-insensitive)', $want > 0 && ($r['existing']['expense'] ?? 0) + ($r['existing']['revenue'] ?? 0) === $want, json_encode([$name, $want, $r['existing'] ?? null]));
$r = $pv(['query' => '', 'target' => '']);
check('assistant: empty input', '' === ($r['pattern'] ?? 'x') && [] === ($r['matches'] ?? ['x']));

// ---- account mapping editor
$c   = api('GET', '?a=text&what=config');
$cfgData = json_decode((string) ($c['text'] ?? ''), true);
check('config loaded', is_array($cfgData['accounts'] ?? null));
$bad = $cfgData;
$first = array_key_first($bad['accounts']);
$bad['accounts'][$first]['as'] = 'nonsense';
$r = api('POST', '?a=save', ['json' => ['what' => 'config', 'text' => json_encode($bad)]]);
check('invalid mapping refused', false === $r['ok'] && str_contains((string) $r['error'], '"as" must be'), json_encode($r));
$r = api('POST', '?a=save', ['json' => ['what' => 'config', 'text' => '{no json']]);
check('broken JSON refused', false === $r['ok']);
$good = $cfgData;
foreach ($good['accounts'] as $g => $a) {
    if ('Aktiva:Barvermögen:Bargeld' === ($a['gnucash'] ?? '')) {
        $good['accounts'][$g]['name'] = 'Portemonnaie';
    }
}
$r  = api('POST', '?a=save', ['json' => ['what' => 'config', 'text' => json_encode($good, JSON_PRETTY_PRINT)]]);
$st = waitJob('plan');
$c  = api('GET', '?a=text&what=config');
check('mapping saved and kept by plan', true === $r['ok'] && str_contains((string) $c['text'], '"Portemonnaie"'));

// ---- downloads
[$status, $headers, $body] = http('GET', '?a=dl&what=config');
check('download with original name', 200 === $status && str_contains($headers, "filename*=UTF-8''Mein%20Buch.import.json"), $headers);
[$status, $headers, $body] = http('GET', '?a=dl&what=log-plan');
check('log download without server paths', 200 === $status && str_contains($body, 'Reading book.gnucash') && !str_contains($body, $tmp));
[$status] = http('GET', '?a=dl&what=../../etc/passwd');
check('unknown download refused', 404 === $status);

// ---- Firefly jobs need URL and token (no Firefly here)
$r = api('POST', '?a=run', ['json' => ['type' => 'import', 'url' => 'http://127.0.0.1:9', 'token' => '']]);
check('import without token refused', false === $r['ok'] && 400 === $r['_status']);
$r = api('POST', '?a=run', ['json' => ['type' => 'import', 'url' => 'ftp://x', 'token' => 'abc']]);
check('import with bad URL refused', false === $r['ok']);
$r = api('POST', '?a=run', ['json' => ['type' => 'dryrun', 'url' => 'http://127.0.0.1:9', 'token' => 'abc']]);
$st = waitJob('dryrun');
check('dry run against an unreachable Firefly fails cleanly', true === $r['ok'] && 'failed' === ($st['job']['state'] ?? '') && str_contains((string) $st['job']['log'], 'ERROR'), json_encode($st['job'] ?? null));
check('token not in the job data', !str_contains(json_encode($st), '"abc"'));
$r = api('POST', '?a=connect', ['json' => ['url' => 'http://127.0.0.1:9', 'token' => 'abc']]);
check('connection test reports the error', false === $r['ok'] && '' !== (string) $r['error']);

// ---- a second browser does not see the workspace
$jarA = $jar;
$jar  = $tmp.'/cookies-b.txt';
http('GET', '');
$other = api('GET', '?a=status');
check('other browser has an empty workspace', array_key_exists('book', $other) && null === $other['book'], json_encode($other['book'] ?? null));
$jar = $jarA;

// ---- reset
$r  = api('POST', '?a=reset', ['json' => []]);
$st = api('GET', '?a=status');
check('workspace deleted', true === $r['ok'] && array_key_exists('book', $st) && null === $st['book']);

echo "{$checks} checks, {$failures} failures\n";
exit($failures > 0 ? 1 : 0);
