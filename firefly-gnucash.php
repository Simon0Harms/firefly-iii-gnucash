<?php
/*
 * firefly-gnucash.php - import a GnuCash book into Firefly III and export Firefly III
 * as a GnuCash book, using the Firefly III REST API (no changes to Firefly itself).
 *
 * https://github.com/Simon0Harms/firefly-iii-gnucash
 * Created with AI (Claude by Anthropic). License: GPL-3.0-or-later
 *
 * Commands (run without arguments for the full help):
 *   plan    BOOK                analyse a GnuCash book, write the mapping + payee reports
 *   import  BOOK                import the book into Firefly III (idempotent, resumable)
 *   export  OUT.gnucash         export Firefly III into a GnuCash XML book
 *   compare A.gnucash B.gnucash compare account balances of two GnuCash books
 *   purge                       delete what an import created (for test runs)
 *   firefly-rules BOOK          create Firefly III rules from the payee rules
 *
 * Requirements: PHP >= 8.1 with curl, xmlreader, dom, zlib, mbstring, bcmath
 * (all present in every Firefly III installation). pdo_sqlite only for SQLite books.
 */

declare(strict_types=1);

namespace FireflyGnuCash;

const VERSION = '1.0.0';

// =====================================================================================
// Output / errors
// =====================================================================================

final class UserError extends \RuntimeException
{
}

final class Out
{
    public static bool $quiet   = false;
    public static bool $verbose = false;

    public static function info(string $msg): void
    {
        if (!self::$quiet) {
            fwrite(STDOUT, $msg."\n");
        }
    }

    public static function step(string $msg): void
    {
        if (!self::$quiet) {
            fwrite(STDOUT, "\033[1;34m==>\033[0m ".$msg."\n");
        }
    }

    public static function warn(string $msg): void
    {
        fwrite(STDERR, "\033[1;33mWARN:\033[0m ".$msg."\n");
    }

    public static function error(string $msg): void
    {
        fwrite(STDERR, "\033[1;31mERROR:\033[0m ".$msg."\n");
    }

    public static function debug(string $msg): void
    {
        if (self::$verbose) {
            fwrite(STDERR, '[debug] '.$msg."\n");
        }
    }

    public static function confirm(string $question, bool $assumeYes): bool
    {
        if ($assumeYes) {
            return true;
        }
        if (!stream_isatty(STDIN)) {
            throw new UserError('Confirmation required but no terminal available - rerun with --yes.');
        }
        fwrite(STDOUT, $question.' [y/N] ');
        $answer = strtolower(trim((string) fgets(STDIN)));

        return in_array($answer, ['y', 'yes', 'j', 'ja'], true);
    }
}

// =====================================================================================
// Small helpers: money in integer minor units, strings, GUIDs, IBANs
// =====================================================================================

final class Util
{
    /** GnuCash "num/denom" -> integer amount in units of 1/$fraction (half away from zero). */
    public static function fracToMinor(string $frac, int $fraction, ?bool &$inexact = null): int
    {
        $inexact = false;
        $frac    = trim($frac);
        if ('' === $frac) {
            return 0;
        }
        $parts = explode('/', $frac);
        $num   = $parts[0];
        $den   = $parts[1] ?? '1';
        if ('0' === $den || '' === $den) {
            return 0;
        }
        if ((string) $fraction === $den) {
            return (int) $num;
        }
        $scaled = bcdiv(bcmul($num, (string) $fraction, 0), $den, 12);
        $int    = self::bcRoundToInt($scaled);
        if (0 !== bccomp($scaled, (string) $int, 12)) {
            $inexact = true;
        }

        return $int;
    }

    public static function bcRoundToInt(string $number): int
    {
        $neg = str_starts_with($number, '-');
        $abs = $neg ? substr($number, 1) : $number;
        $r   = bcadd($abs, '0.5', 0);

        return $neg ? -(int) $r : (int) $r;
    }

    /** Parse a decimal string ("12.34", "-0.5") into minor units with $decimals places. */
    public static function decToMinor(string $dec, int $decimals): int
    {
        $dec = trim($dec);
        if ('' === $dec) {
            return 0;
        }
        $scaled = bcmul($dec, bcpow('10', (string) $decimals, 0), 12);

        return self::bcRoundToInt($scaled);
    }

    /** Integer minor units -> decimal string ("-12.34"). */
    public static function minorToDec(int $minor, int $decimals): string
    {
        $neg = $minor < 0;
        $abs = (string) abs($minor);
        if (0 === $decimals) {
            return ($neg ? '-' : '').$abs;
        }
        $abs  = str_pad($abs, $decimals + 1, '0', STR_PAD_LEFT);
        $int  = substr($abs, 0, -$decimals);
        $frac = substr($abs, -$decimals);

        return ($neg ? '-' : '').$int.'.'.$frac;
    }

    /** Proportional share: round($amount * $num / $den), half away from zero, exact integers. */
    public static function share(int $amount, int $num, int $den): int
    {
        if (0 === $den) {
            return 0;
        }
        $x = bcdiv(bcmul((string) $amount, (string) $num, 0), (string) $den, 12);

        return self::bcRoundToInt($x);
    }

    public static function fractionForDecimals(int $decimals): int
    {
        return (int) (10 ** $decimals);
    }

    public static function decimalsForFraction(int $fraction): int
    {
        $d = 0;
        while ($fraction > 1) {
            $fraction = intdiv($fraction, 10);
            ++$d;
        }

        return $d;
    }

    public static function collapse(string $s): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $s));
    }

    /** Trim and replace line breaks by spaces (keeps the original spacing otherwise). */
    public static function oneLine(string $s): string
    {
        return trim(str_replace(["\r\n", "\n", "\r", "\t"], ' ', $s));
    }

    public static function lower(string $s): string
    {
        return mb_strtolower($s, 'UTF-8');
    }

    /** Grouping key: lower case, umlauts transliterated, only letters a-z kept. */
    public static function key(string $s): string
    {
        $s = self::lower($s);
        $s = strtr($s, ['ä' => 'ae', 'ö' => 'oe', 'ü' => 'ue', 'ß' => 'ss', 'é' => 'e', 'è' => 'e', 'à' => 'a', 'á' => 'a', 'ç' => 'c', 'ñ' => 'n', 'ø' => 'o', 'å' => 'a']);

        return (string) preg_replace('/[^a-z]+/', '', $s);
    }

    public static function truncate(string $s, int $max): string
    {
        if (mb_strlen($s, 'UTF-8') <= $max) {
            return $s;
        }

        return mb_substr($s, 0, $max - 1, 'UTF-8').'…';
    }

    public static function guid(string $seed): string
    {
        return md5('firefly-gnucash:'.$seed);
    }

    public static function randomGuid(): string
    {
        return bin2hex(random_bytes(16));
    }

    public static function isGuid(?string $s): bool
    {
        return null !== $s && 1 === preg_match('/^[0-9a-f]{32}$/', $s);
    }

    public static function normalizeIban(string $s): string
    {
        return strtoupper((string) preg_replace('/\s+/', '', $s));
    }

    public static function isValidIban(string $iban): bool
    {
        $iban = self::normalizeIban($iban);
        if (1 !== preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/', $iban)) {
            return false;
        }
        $moved   = substr($iban, 4).substr($iban, 0, 4);
        $numeric = '';
        foreach (str_split($moved) as $ch) {
            $numeric .= ctype_alpha($ch) ? (string) (ord($ch) - 55) : $ch;
        }

        return '1' === bcmod($numeric, '97');
    }

    public static function csvLine(array $fields, string $sep = ';'): string
    {
        $out = [];
        foreach ($fields as $f) {
            $f = (string) $f;
            if (1 === preg_match('/['.preg_quote($sep, '/').'"\r\n]/', $f)) {
                $f = '"'.str_replace('"', '""', $f).'"';
            }
            $out[] = $f;
        }

        return implode($sep, $out)."\r\n";
    }

    /** Date (Y-m-d) in the local time zone for a GnuCash timestamp "Y-m-d H:i:s +hhmm". */
    public static function localDate(string $ts, \DateTimeZone $tz): string
    {
        $ts = trim($ts);
        if (1 === preg_match('/^\d{4}-\d{2}-\d{2}$/', $ts)) {
            return $ts;
        }
        try {
            $d = new \DateTimeImmutable($ts);
        } catch (\Exception) {
            return substr($ts, 0, 10);
        }
        // GnuCash >= 2.6.12 stores dates as 10:59 UTC ("neutral time"): keep the written date.
        if ('10:59:00' === $d->setTimezone(new \DateTimeZone('UTC'))->format('H:i:s')) {
            return $d->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d');
        }

        return $d->setTimezone($tz)->format('Y-m-d');
    }

    public static function jsonLine(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)."\n";
    }
}

/** Minimal command line parser: positional arguments plus --key=value / --flag options. */
final class Args
{
    /** @var list<string> */
    public array $positional = [];

    /** @var array<string, string|true> */
    public array $options = [];

    public function __construct(array $argv)
    {
        $noMore = false;
        foreach ($argv as $arg) {
            if (!$noMore && '--' === $arg) {
                $noMore = true;

                continue;
            }
            if (!$noMore && str_starts_with($arg, '--')) {
                $eq = strpos($arg, '=');
                if (false === $eq) {
                    $this->options[substr($arg, 2)] = true;
                } else {
                    $this->options[substr($arg, 2, $eq - 2)] = substr($arg, $eq + 1);
                }

                continue;
            }
            $this->positional[] = $arg;
        }
    }

    public function get(string $name, ?string $default = null): ?string
    {
        $v = $this->options[$name] ?? null;
        if (null === $v || true === $v) {
            return true === $v ? '' : $default;
        }

        return $v;
    }

    public function has(string $name): bool
    {
        return array_key_exists($name, $this->options);
    }

    /** @param list<string> $allowed */
    public function check(array $allowed): void
    {
        foreach (array_keys($this->options) as $k) {
            if (!in_array($k, $allowed, true)) {
                throw new UserError(sprintf('Unknown option --%s (allowed: %s)', $k, implode(', ', array_map(static fn ($a) => '--'.$a, $allowed))));
            }
        }
    }
}

// =====================================================================================
// GnuCash book model and readers (XML, gzip-compressed XML, SQLite)
// =====================================================================================

final class GAccount
{
    public string $path     = '';
    public int $splitCount  = 0;

    /** @var list<string> */
    public array $children  = [];

    public function __construct(
        public string $guid,
        public string $name,
        public string $type,
        public string $cmdtySpace,
        public string $cmdtyId,
        public int $scu,
        public ?string $parent,
        public string $code = '',
        public string $description = '',
        public string $notes = '',
        public bool $placeholder = false,
        public bool $hidden = false,
    ) {
    }

    public function isCurrency(): bool
    {
        return 'CURRENCY' === $this->cmdtySpace || 'ISO4217' === $this->cmdtySpace;
    }
}

final class GSplit
{
    public function __construct(
        public string $guid,
        public string $account,
        public string $value,
        public string $quantity,
        public string $memo = '',
        public string $action = '',
        public string $state = 'n',
    ) {
    }
}

final class GTransaction
{
    /** @param list<GSplit> $splits */
    public function __construct(
        public string $guid,
        public string $currency,
        public string $date,
        public string $dateEntered,
        public string $description,
        public string $num = '',
        public string $notes = '',
        public array $splits = [],
    ) {
    }
}

final class Book
{
    /** @var array<string, GAccount> */
    public array $accounts = [];

    /** @var list<GTransaction> */
    public array $transactions = [];

    public string $rootGuid        = '';
    public string $guid            = '';
    public string $defaultCurrency = 'EUR';

    /** @var array<string, int> other GnuCash objects that are not imported, e.g. budgets */
    public array $otherObjects = [];

    public function __construct(public string $file)
    {
    }

    public function finish(): void
    {
        foreach ($this->accounts as $acc) {
            if ('ROOT' === $acc->type && null === $acc->parent && '' === $this->rootGuid) {
                $this->rootGuid = $acc->guid;
            }
        }
        foreach ($this->accounts as $acc) {
            if (null !== $acc->parent && isset($this->accounts[$acc->parent])) {
                $this->accounts[$acc->parent]->children[] = $acc->guid;
            }
        }
        foreach ($this->accounts as $acc) {
            $parts = [];
            $g     = $acc->guid;
            $guard = 0;
            while (null !== $g && isset($this->accounts[$g]) && 'ROOT' !== $this->accounts[$g]->type && $guard++ < 100) {
                array_unshift($parts, $this->accounts[$g]->name);
                $g = $this->accounts[$g]->parent;
            }
            $acc->path = implode(':', $parts);
        }
        foreach ($this->transactions as $t) {
            foreach ($t->splits as $s) {
                if (isset($this->accounts[$s->account])) {
                    ++$this->accounts[$s->account]->splitCount;
                }
            }
        }
        if ('' !== $this->rootGuid && $this->accounts[$this->rootGuid]->isCurrency()) {
            $this->defaultCurrency = $this->accounts[$this->rootGuid]->cmdtyId;
        }
        usort($this->transactions, static fn (GTransaction $a, GTransaction $b): int => [$a->date, $a->dateEntered, $a->guid] <=> [$b->date, $b->dateEntered, $b->guid]);
    }

    public function account(string $guid): GAccount
    {
        if (!isset($this->accounts[$guid])) {
            throw new UserError(sprintf('Split references unknown account %s', $guid));
        }

        return $this->accounts[$guid];
    }
}

final class BookReader
{
    public static function read(string $file, \DateTimeZone $tz): Book
    {
        if (!is_file($file) || !is_readable($file)) {
            throw new UserError(sprintf('Cannot read GnuCash file "%s"', $file));
        }
        $fh   = fopen($file, 'rb');
        $head = (string) fread($fh, 16);
        fclose($fh);
        if (str_starts_with($head, 'SQLite format 3')) {
            return self::readSqlite($file, $tz);
        }

        return self::readXml($file, $tz);
    }

    private static function readXml(string $file, \DateTimeZone $tz): Book
    {
        $book   = new Book($file);
        $reader = new \XMLReader();
        if (!@$reader->open('compress.zlib://'.$file, null, LIBXML_PARSEHUGE | LIBXML_NONET)) {
            throw new UserError(sprintf('"%s" is neither a GnuCash XML file nor a GnuCash SQLite file', $file));
        }
        $inBook = false;
        $ok     = @$reader->read();
        while ($ok) {
            if (\XMLReader::ELEMENT !== $reader->nodeType) {
                $ok = @$reader->read();

                continue;
            }
            $name = $reader->name;
            if ('gnc:book' === $name) {
                $inBook = true;
                $ok     = @$reader->read();

                continue;
            }
            if (!$inBook) {
                $ok = @$reader->read();

                continue;
            }
            switch ($name) {
                case 'book:id':
                    $book->guid = trim($reader->readString());
                    $ok         = $reader->next();

                    break;

                case 'gnc:account':
                    $el = $reader->expand();
                    if ($el instanceof \DOMElement) {
                        $acc                        = self::xmlAccount($el);
                        $book->accounts[$acc->guid] = $acc;
                    }
                    $ok = $reader->next();

                    break;

                case 'gnc:transaction':
                    $el = $reader->expand();
                    if ($el instanceof \DOMElement) {
                        $book->transactions[] = self::xmlTransaction($el, $tz);
                    }
                    $ok = $reader->next();

                    break;

                case 'gnc:template-transactions':
                    $book->otherObjects['scheduled transaction templates'] = ($book->otherObjects['scheduled transaction templates'] ?? 0) + 1;
                    $ok                                                    = $reader->next();

                    break;

                case 'gnc:pricedb':
                    $count = substr_count((string) $reader->readOuterXml(), '<price>');
                    if ($count > 0) {
                        $book->otherObjects['prices'] = $count;
                    }
                    $ok = $reader->next();

                    break;

                case 'gnc:budget':
                case 'gnc:schedxaction':
                case 'gnc:GncInvoice':
                case 'gnc:GncCustomer':
                case 'gnc:GncVendor':
                case 'gnc:GncEmployee':
                case 'gnc:GncJob':
                case 'gnc:GncEntry':
                case 'gnc:GncTaxTable':
                case 'gnc:GncBillTerm':
                case 'gnc:lot':
                    $key                      = [
                        'gnc:budget'      => 'budgets', 'gnc:schedxaction' => 'scheduled transactions', 'gnc:GncInvoice' => 'invoices/bills',
                        'gnc:GncCustomer' => 'customers', 'gnc:GncVendor' => 'vendors', 'gnc:GncEmployee' => 'employees', 'gnc:GncJob' => 'jobs',
                        'gnc:GncEntry'    => 'invoice entries', 'gnc:GncTaxTable' => 'tax tables', 'gnc:GncBillTerm' => 'billing terms', 'gnc:lot' => 'lots',
                    ][$name];
                    $book->otherObjects[$key] = ($book->otherObjects[$key] ?? 0) + 1;
                    $ok                       = $reader->next();

                    break;

                default:
                    $ok = @$reader->read();
            }
        }
        $reader->close();
        if (0 === count($book->accounts)) {
            throw new UserError(sprintf('"%s" contains no GnuCash accounts (not a GnuCash XML book?)', $file));
        }
        $book->finish();

        return $book;
    }

    /** @return array<string, \DOMElement> first child element per qualified name */
    private static function kids(\DOMElement $el): array
    {
        $out = [];
        foreach ($el->childNodes as $c) {
            if ($c instanceof \DOMElement && !isset($out[$c->nodeName])) {
                $out[$c->nodeName] = $c;
            }
        }

        return $out;
    }

    /** @return array<string, string> top-level slots (key => string value, gdate as Y-m-d) */
    private static function slots(?\DOMElement $el): array
    {
        $out = [];
        if (null === $el) {
            return $out;
        }
        foreach ($el->childNodes as $slot) {
            if (!$slot instanceof \DOMElement || 'slot' !== $slot->nodeName) {
                continue;
            }
            $k   = self::kids($slot);
            $key = isset($k['slot:key']) ? trim($k['slot:key']->textContent) : '';
            if ('' === $key || !isset($k['slot:value'])) {
                continue;
            }
            $val  = $k['slot:value'];
            $type = $val->getAttribute('type');
            if ('frame' === $type) {
                continue;
            }
            if ('gdate' === $type) {
                $out[$key] = trim($val->textContent);

                continue;
            }
            $out[$key] = $val->textContent;
        }

        return $out;
    }

    private static function xmlAccount(\DOMElement $el): GAccount
    {
        $k     = self::kids($el);
        $cmd   = isset($k['act:commodity']) ? self::kids($k['act:commodity']) : [];
        $slots = self::slots($k['act:slots'] ?? null);

        return new GAccount(
            guid: trim($k['act:id']->textContent ?? ''),
            name: $k['act:name']->textContent ?? '',
            type: trim($k['act:type']->textContent ?? 'ASSET'),
            cmdtySpace: isset($cmd['cmdty:space']) ? trim($cmd['cmdty:space']->textContent) : 'CURRENCY',
            cmdtyId: isset($cmd['cmdty:id']) ? trim($cmd['cmdty:id']->textContent) : 'EUR',
            scu: isset($k['act:commodity-scu']) ? max(1, (int) $k['act:commodity-scu']->textContent) : 100,
            parent: isset($k['act:parent']) ? trim($k['act:parent']->textContent) : null,
            code: isset($k['act:code']) ? trim($k['act:code']->textContent) : '',
            description: isset($k['act:description']) ? trim($k['act:description']->textContent) : '',
            notes: trim($slots['notes'] ?? ''),
            placeholder: 'true' === ($slots['placeholder'] ?? ''),
            hidden: 'true' === ($slots['hidden'] ?? ''),
        );
    }

    private static function xmlTransaction(\DOMElement $el, \DateTimeZone $tz): GTransaction
    {
        $k      = self::kids($el);
        $slots  = self::slots($k['trn:slots'] ?? null);
        $cur    = isset($k['trn:currency']) ? self::kids($k['trn:currency']) : [];
        $posted = isset($k['trn:date-posted']) ? trim($k['trn:date-posted']->textContent) : '';
        $date   = $slots['date-posted'] ?? '';
        if (1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            $date = Util::localDate($posted, $tz);
        }
        $splits = [];
        if (isset($k['trn:splits'])) {
            foreach ($k['trn:splits']->childNodes as $sp) {
                if (!$sp instanceof \DOMElement || 'trn:split' !== $sp->nodeName) {
                    continue;
                }
                $s        = self::kids($sp);
                $splits[] = new GSplit(
                    guid: isset($s['split:id']) ? trim($s['split:id']->textContent) : '',
                    account: isset($s['split:account']) ? trim($s['split:account']->textContent) : '',
                    value: isset($s['split:value']) ? trim($s['split:value']->textContent) : '0/1',
                    quantity: isset($s['split:quantity']) ? trim($s['split:quantity']->textContent) : '0/1',
                    memo: isset($s['split:memo']) ? $s['split:memo']->textContent : '',
                    action: isset($s['split:action']) ? $s['split:action']->textContent : '',
                    state: isset($s['split:reconciled-state']) ? trim($s['split:reconciled-state']->textContent) : 'n',
                );
            }
        }

        return new GTransaction(
            guid: trim($k['trn:id']->textContent ?? ''),
            currency: isset($cur['cmdty:id']) ? trim($cur['cmdty:id']->textContent) : 'EUR',
            date: $date,
            dateEntered: isset($k['trn:date-entered']) ? trim($k['trn:date-entered']->textContent) : '',
            description: isset($k['trn:description']) ? $k['trn:description']->textContent : '',
            num: isset($k['trn:num']) ? trim($k['trn:num']->textContent) : '',
            notes: trim($slots['notes'] ?? ''),
            splits: $splits,
        );
    }

    private static function readSqlite(string $file, \DateTimeZone $tz): Book
    {
        if (!in_array('sqlite', \PDO::getAvailableDrivers(), true)) {
            throw new UserError('This is a GnuCash SQLite book, but PHP has no pdo_sqlite driver (apt install php-sqlite3), or save the book as XML in GnuCash.');
        }
        $book = new Book($file);
        $db   = new \PDO('sqlite:'.$file, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $bk   = $db->query('SELECT guid, root_account_guid FROM books LIMIT 1')->fetch(\PDO::FETCH_ASSOC);
        if (false === $bk) {
            throw new UserError('SQLite file has no GnuCash book');
        }
        $book->guid = (string) $bk['guid'];
        $cmdty      = [];
        foreach ($db->query('SELECT guid, namespace, mnemonic FROM commodities') as $r) {
            $cmdty[$r['guid']] = [(string) $r['namespace'], (string) $r['mnemonic']];
        }
        $slot = static function (string $name) use ($db): array {
            $out = [];
            $st  = $db->prepare('SELECT obj_guid, string_val, gdate_val, slot_type FROM slots WHERE name = ?');
            $st->execute([$name]);
            foreach ($st as $r) {
                $out[$r['obj_guid']] = (string) ($r['string_val'] ?? $r['gdate_val'] ?? '');
                if (null !== $r['gdate_val'] && '' !== (string) $r['gdate_val']) {
                    $g                   = preg_replace('/^(\d{4})-?(\d{2})-?(\d{2}).*$/', '$1-$2-$3', (string) $r['gdate_val']);
                    $out[$r['obj_guid']] = (string) $g;
                }
            }

            return $out;
        };
        $notes  = $slot('notes');
        $posted = $slot('date-posted');
        foreach ($db->query('SELECT * FROM accounts') as $r) {
            [$space, $id]               = $cmdty[(string) $r['commodity_guid']] ?? ['CURRENCY', 'EUR'];
            $acc                        = new GAccount(
                guid: (string) $r['guid'],
                name: (string) $r['name'],
                type: (string) $r['account_type'],
                cmdtySpace: $space,
                cmdtyId: $id,
                scu: max(1, (int) $r['commodity_scu']),
                parent: null === $r['parent_guid'] ? null : (string) $r['parent_guid'],
                code: (string) ($r['code'] ?? ''),
                description: (string) ($r['description'] ?? ''),
                notes: trim($notes[$r['guid']] ?? ''),
                placeholder: 1 === (int) $r['placeholder'],
                hidden: 1 === (int) $r['hidden'],
            );
            $book->accounts[$acc->guid] = $acc;
        }
        // only accounts below the book root (the template root holds scheduled-transaction templates)
        $root  = (string) $bk['root_account_guid'];
        $valid = [];
        foreach ($book->accounts as $acc) {
            $g     = $acc->guid;
            $guard = 0;
            while (null !== $g && isset($book->accounts[$g]) && $g !== $root && $guard++ < 100) {
                $g = $book->accounts[$g]->parent;
            }
            if ($g === $root) {
                $valid[$acc->guid] = true;
            }
        }
        foreach (array_keys($book->accounts) as $g) {
            if (!isset($valid[$g])) {
                unset($book->accounts[$g]);
            }
        }
        $book->rootGuid = $root;
        $splits         = [];
        foreach ($db->query('SELECT * FROM splits') as $r) {
            $splits[$r['tx_guid']][] = new GSplit(
                guid: (string) $r['guid'],
                account: (string) $r['account_guid'],
                value: $r['value_num'].'/'.$r['value_denom'],
                quantity: $r['quantity_num'].'/'.$r['quantity_denom'],
                memo: (string) ($r['memo'] ?? ''),
                action: (string) ($r['action'] ?? ''),
                state: (string) ($r['reconcile_state'] ?? 'n'),
            );
        }
        foreach ($db->query('SELECT * FROM transactions') as $r) {
            $sp = $splits[$r['guid']] ?? [];
            if ([] === $sp || !isset($book->accounts[$sp[0]->account])) {
                continue; // template transaction or empty
            }
            [, $cur] = $cmdty[(string) $r['currency_guid']] ?? ['CURRENCY', 'EUR'];
            $date    = $posted[$r['guid']] ?? '';
            if (1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                $raw  = (string) $r['post_date'];
                $raw  = (string) preg_replace('/^(\d{4})(\d{2})(\d{2})(\d{2})(\d{2})(\d{2})$/', '$1-$2-$3 $4:$5:$6', $raw);
                $date = Util::localDate($raw.' +0000', $tz);
            }
            $book->transactions[] = new GTransaction(
                guid: (string) $r['guid'],
                currency: $cur,
                date: $date,
                dateEntered: (string) $r['enter_date'],
                description: (string) ($r['description'] ?? ''),
                num: (string) ($r['num'] ?? ''),
                notes: trim($notes[$r['guid']] ?? ''),
                splits: $sp,
            );
        }
        foreach (['budgets' => 'budgets', 'schedxactions' => 'scheduled transactions', 'invoices' => 'invoices/bills', 'customers' => 'customers', 'vendors' => 'vendors', 'prices' => 'prices', 'lots' => 'lots'] as $table => $label) {
            try {
                $n = (int) $db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();
                if ($n > 0) {
                    $book->otherObjects[$label] = $n;
                }
            } catch (\PDOException) {
                // table does not exist in this GnuCash version
            }
        }
        $book->finish();

        return $book;
    }
}

// =====================================================================================
// Import configuration: account mapping (GnuCash account -> Firefly asset/liability/
// category) and options. Written by "plan", editable by the user, read by "import".
// =====================================================================================

final class ImportConfig
{
    public const ASSET_TYPES     = ['ASSET', 'BANK', 'CASH', 'CREDIT', 'RECEIVABLE', 'STOCK', 'MUTUAL', 'CURRENCY'];
    public const LIABILITY_TYPES = ['LIABILITY', 'PAYABLE'];
    public const PL_TYPES        = ['EXPENSE', 'INCOME', 'EQUITY'];
    public const ROLES           = ['defaultAsset', 'savingAsset', 'sharedAsset', 'cashWalletAsset', 'ccAsset'];

    public const DEFAULT_OPTIONS = [
        'category_names'      => 'strip-root',
        'reconciled_states'   => 'y',
        'opening_balances'    => true,
        'clearing_account'    => 'GnuCash-Umbuchungen',
        'import_tag'          => 'GnuCash-Import',
        'selfflow_tag'        => 'GnuCash-Durchlauf',
        'payee_min_count'     => 2,
        'payee_fallback'      => '(diverse)',
        'payee_split_dash'    => true,
        'payee_merge_prefix'  => true,
        'payee_group_by_iban' => true,
        'payee_set_iban'      => true,
        'multisource'         => true,
        'apply_rules'         => false,
        'fire_webhooks'       => false,
    ];

    /** @var array<string, mixed> */
    public array $options = self::DEFAULT_OPTIONS;

    /** @var array<string, array<string, mixed>> guid => mapping */
    public array $accounts = [];

    /** @var list<string> notes produced while merging (new / vanished accounts) */
    public array $messages = [];

    public bool $existed = false;

    public function __construct(public string $file)
    {
    }

    public static function load(string $file): self
    {
        $cfg = new self($file);
        if (!is_file($file)) {
            return $cfg;
        }
        $data = json_decode((string) file_get_contents($file), true);
        if (!is_array($data)) {
            throw new UserError(sprintf('Config "%s" is not valid JSON: %s', $file, json_last_error_msg()));
        }
        $cfg->existed  = true;
        $cfg->options  = array_merge(self::DEFAULT_OPTIONS, is_array($data['options'] ?? null) ? $data['options'] : []);
        $cfg->accounts = is_array($data['accounts'] ?? null) ? $data['accounts'] : [];

        return $cfg;
    }

    /** Add defaults for new accounts, refresh informational fields, drop vanished accounts. */
    public function syncWithBook(Book $book): void
    {
        $defaults = self::defaultMapping($book, $this->options, $this->accounts);
        foreach ($defaults as $guid => $def) {
            if (!isset($this->accounts[$guid])) {
                $this->accounts[$guid] = $def;
                if ($this->existed) {
                    $this->messages[] = sprintf('new GnuCash account "%s" -> %s "%s"', $def['gnucash'], $def['as'], $def['name'] ?? '');
                }

                continue;
            }
            // keep user choices, refresh the informational fields
            $this->accounts[$guid] = array_merge(
                ['gnucash' => $def['gnucash'], 'type' => $def['type'], 'splits' => $def['splits']],
                array_diff_key($this->accounts[$guid], ['gnucash' => 1, 'type' => 1, 'splits' => 1])
            );
        }
        foreach (array_keys($this->accounts) as $guid) {
            if (!isset($book->accounts[$guid])) {
                $this->messages[] = sprintf('GnuCash account "%s" no longer exists - removed from config', $this->accounts[$guid]['gnucash'] ?? $guid);
                unset($this->accounts[$guid]);
            }
        }
        $this->validate();
    }

    public function validate(): void
    {
        $seen = [];
        foreach ($this->accounts as $guid => $m) {
            $as = $m['as'] ?? '';
            if (!in_array($as, ['asset', 'liability', 'category', 'ignore', 'skip'], true)) {
                throw new UserError(sprintf('%s: account "%s": "as" must be asset|liability|category|ignore|skip, got "%s"', $this->file, $m['gnucash'] ?? $guid, $as));
            }
            if (in_array($as, ['asset', 'liability', 'category'], true)) {
                $name = trim((string) ($m['name'] ?? ''));
                if ('' === $name) {
                    throw new UserError(sprintf('%s: account "%s" needs a "name"', $this->file, $m['gnucash'] ?? $guid));
                }
                if ('category' === $as && mb_strlen($name) > 100) {
                    throw new UserError(sprintf('%s: category name "%s" is longer than 100 characters (Firefly limit)', $this->file, $name));
                }
                if ('asset' === $as && isset($m['role']) && !in_array($m['role'], self::ROLES, true)) {
                    throw new UserError(sprintf('%s: account "%s": role must be one of %s', $this->file, $m['gnucash'] ?? $guid, implode(', ', self::ROLES)));
                }
                if ('liability' === $as) {
                    if (!in_array($m['liability_type'] ?? 'debt', ['debt', 'loan', 'mortgage'], true)) {
                        throw new UserError(sprintf('%s: account "%s": liability_type must be debt|loan|mortgage', $this->file, $m['gnucash'] ?? $guid));
                    }
                    if (!in_array($m['direction'] ?? 'debit', ['credit', 'debit'], true)) {
                        throw new UserError(sprintf('%s: account "%s": direction must be debit (I owe) or credit (I am owed)', $this->file, $m['gnucash'] ?? $guid));
                    }
                }
                // same Firefly object from several GnuCash accounts is fine (merging), but
                // an asset and a liability must not share a name with a different kind.
                $seen[$as][Util::lower($name)][] = $guid;
            }
        }
    }

    public function save(): void
    {
        $lines   = [];
        $lines[] = '{';
        $lines[] = '  "_help": "Mapping of GnuCash accounts to Firefly III. as = asset | liability | category | ignore | skip. Edit names/roles and run plan again; see README.md.",';
        $lines[] = '  "version": 1,';
        $lines[] = '  "options": '.self::indentJson(json_encode($this->options, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), '  ').',';
        $lines[] = '  "accounts": {';
        $sorted  = $this->accounts;
        uasort($sorted, static fn (array $a, array $b): int => strcmp((string) $a['gnucash'], (string) $b['gnucash']));
        $n = count($sorted);
        $i = 0;
        foreach ($sorted as $guid => $m) {
            ++$i;
            $order   = ['gnucash', 'type', 'splits', 'as', 'name', 'role', 'liability_type', 'direction', 'currency', 'active', 'include_net_worth', 'iban', 'account_number'];
            $ordered = [];
            foreach ($order as $k) {
                if (array_key_exists($k, $m)) {
                    $ordered[$k] = $m[$k];
                }
            }
            foreach ($m as $k => $v) {
                if (!array_key_exists($k, $ordered)) {
                    $ordered[$k] = $v;
                }
            }
            $lines[] = '    '.json_encode((string) $guid).': '.json_encode($ordered, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES).($i < $n ? ',' : '');
        }
        $lines[] = '  }';
        $lines[] = '}';
        file_put_contents($this->file, implode("\n", $lines)."\n");
    }

    private static function indentJson(string $json, string $indent): string
    {
        return str_replace("\n", "\n".$indent, $json);
    }

    public function opt(string $key): mixed
    {
        return $this->options[$key] ?? self::DEFAULT_OPTIONS[$key] ?? null;
    }

    /**
     * Default mapping for every account of the book.
     *
     * @param array<string, array<string, mixed>> $existing current config entries (their names are kept)
     *
     * @return array<string, array<string, mixed>>
     */
    public static function defaultMapping(Book $book, array $options, array $existing): array
    {
        $out     = [];
        $bsNames = [];
        $cats    = [];
        foreach ($book->accounts as $acc) {
            if ('ROOT' === $acc->type || (0 === $acc->splitCount && !isset($existing[$acc->guid]))) {
                continue; // unused accounts (e.g. placeholders) are not needed in Firefly
            }
            $base = ['gnucash' => $acc->path, 'type' => $acc->type, 'splits' => $acc->splitCount];
            if ('TRADING' === $acc->type) {
                $out[$acc->guid] = $base + ['as' => 'ignore'];

                continue;
            }
            if (in_array($acc->type, self::PL_TYPES, true)) {
                $cats[$acc->guid] = $acc;
                $out[$acc->guid]  = $base + ['as' => 'category'];

                continue;
            }
            $isLiab = in_array($acc->type, self::LIABILITY_TYPES, true);
            $m      = $base;
            // heuristics look at the account name and its parent only
            $parent = null !== $acc->parent && isset($book->accounts[$acc->parent]) && 'ROOT' !== $book->accounts[$acc->parent]->type ? $book->accounts[$acc->parent]->name : '';
            $near   = $acc->name.' | '.$parent;
            $isCard = 'CREDIT' === $acc->type || 1 === preg_match('/kredit ?karte|credit ?card|\bvisa\b|mastercard|\bamex\b|barclay/iu', $near);
            if ($isLiab && !$isCard) {
                $m['as']             = 'liability';
                $m['liability_type'] = 1 === preg_match('/kredit(?!karte)|darlehen|\bloan|hypothek|mortgage|baufinanz/iu', $near) ? (1 === preg_match('/hypothek|mortgage|baufinanz/iu', $near) ? 'mortgage' : 'loan') : 'debt';
                $m['direction']      = 'debit';   // Firefly: debit = "I owe this debt", credit = "I am owed this debt"
            } else {
                $m['as']   = 'asset';
                $m['role'] = match (true) {
                    'CASH' === $acc->type => 'cashWalletAsset',
                    $isCard               => 'ccAsset',
                    1 === preg_match('/\b(tagesgeld\w*|spar(konto|buch|plan|brief|en)?|\w*sparkonto|festgeld\w*|bauspar\w*|depot\w*|\w*vorsorge\w*|geldanlage\w*|savings?)\b/iu', $near) => 'savingAsset',
                    default => 'defaultAsset',
                };
            }
            $m['currency'] = $acc->isCurrency() ? $acc->cmdtyId : $book->defaultCurrency;
            $m['active']   = !$acc->hidden;
            if ('' !== $acc->code) {
                if (Util::isValidIban($acc->code)) {
                    $m['iban'] = Util::normalizeIban($acc->code);
                } else {
                    $m['account_number'] = $acc->code;
                }
            }
            $out[$acc->guid]      = $m;
            $bsNames[$acc->guid]  = $acc;
        }

        // names for balance-sheet accounts: GnuCash leaf name, "Leaf (Parent)" on collisions
        $taken = [];
        foreach ($existing as $guid => $m) {
            if (isset($m['name']) && in_array($m['as'] ?? '', ['asset', 'liability'], true)) {
                $taken[Util::lower((string) $m['name'])] = $guid;
            }
        }
        $byName = [];
        foreach ($bsNames as $guid => $acc) {
            if (isset($existing[$guid]['name'])) {
                continue;
            }
            $byName[Util::lower($acc->name)][] = $guid;
        }
        foreach ($byName as $lname => $guids) {
            foreach ($guids as $guid) {
                $acc  = $book->accounts[$guid];
                $name = $acc->name;
                if (count($guids) > 1 || isset($taken[$lname])) {
                    $parent = null !== $acc->parent && isset($book->accounts[$acc->parent]) && 'ROOT' !== $book->accounts[$acc->parent]->type ? $book->accounts[$acc->parent]->name : '';
                    $name   = '' !== $parent ? sprintf('%s (%s)', $acc->name, $parent) : $acc->name;
                }
                $name = self::unique($name, $taken);
                $taken[Util::lower($name)] = $guid;
                $out[$guid]['name']        = $name;
            }
        }

        // category names: path without the top-level account ("Lebensmittel:Getränke"),
        // the full path for names that would otherwise be ambiguous.
        $strip   = 'full-path' !== ($options['category_names'] ?? 'strip-root');
        $wanted  = [];
        foreach ($cats as $guid => $acc) {
            if (isset($existing[$guid]['name'])) {
                continue;
            }
            $parts = explode(':', $acc->path);
            $short = $strip && count($parts) > 1 ? implode(':', array_slice($parts, 1)) : $acc->path;
            $wanted[Util::lower($short)][] = [$guid, $short];
        }
        $catTaken = [];
        foreach ($existing as $guid => $m) {
            if ('category' === ($m['as'] ?? '') && isset($m['name'])) {
                $catTaken[Util::lower((string) $m['name'])] = $guid;
            }
        }
        foreach ($wanted as $lname => $list) {
            foreach ($list as [$guid, $short]) {
                $name = (count($list) > 1 || isset($catTaken[$lname])) ? $book->accounts[$guid]->path : $short;
                $name = self::unique(Util::truncate($name, 96), $catTaken);
                $catTaken[Util::lower($name)] = $guid;
                $out[$guid]['name']           = $name;
            }
        }

        return $out;
    }

    private static function unique(string $name, array $taken): string
    {
        $candidate = $name;
        $i         = 2;
        while (isset($taken[Util::lower($candidate)])) {
            $candidate = sprintf('%s (%d)', $name, $i++);
        }

        return $candidate;
    }
}

// =====================================================================================
// Counterparties ("payees"): Firefly needs an expense/revenue account on every
// withdrawal/deposit. GnuCash has none, so it is derived from the booking text and can
// be merged ("zusammengefasst") with rules.
// =====================================================================================

final class PayeeRules
{
    /** @var list<array{line:int, side:string, field:string, conds:list<array{field:string, regex:?string, text:?string, raw:string}>, payee:string, src:string}> */
    public array $rules = [];

    public const TEMPLATE = <<<'TXT'
        # Payee rules for firefly-gnucash: map GnuCash booking texts to Firefly counterparties
        # (expense accounts for withdrawals, revenue accounts for deposits).
        #
        #   <pattern>  =>  <counterparty>
        #
        # The first matching rule wins. <pattern> is
        #   /regex/i            PCRE on the booking text (description)
        #   some text           case-insensitive substring of the booking text
        #   iban:DE12...        counterparty IBAN from the bank memo ("Konto <IBAN> Bank <BIC>")
        #   category:/regex/    the Firefly category (GnuCash account) of the split
        #   memo:/regex/        any split memo of the transaction
        #   konto:/regex/       any GnuCash account of the transaction (full path), e.g. the
        #                       cash or card account it was paid from
        #   auto:/regex/        the AUTOMATIC counterparty name (only bookings no other rule
        #                       catches); blocks or renames it, e.g.  auto:/paypal/i => -
        # Put "ausgabe:" (expense) or "einnahme:" (revenue) in front to limit a rule to
        # withdrawals or deposits, e.g.  ausgabe:Platinum  or  einnahme:category:/^Erträge/
        # "&&" joins conditions that must all match:  /Abrechnung/i && konto:/:Bankgebühren/
        # ($1..$9 then come from the first condition).
        # <counterparty> may use $1..$9 (regex groups), {category} and {description};
        # "-" means: use the fallback counterparty (option payee_fallback).
        # Transactions without a matching rule get an automatic counterparty (name after ";"
        # in bank texts, text before " - ", reference numbers removed, same IBAN = same payee).
        # Run "plan" again after editing and check <book>.payees.csv / <book>.payee-map.csv.
        #
        # Examples:
        # /^(AMZN|AMAZON|WWW\.AMAZON|Amazon\.(de|co\.uk))/i   => Amazon
        # REWE                                               => REWE
        # /Ihr Einkauf bei ([^,;]+)/i                        => $1
        # /^Geldautomat/i                                    => Geldautomat
        # category:/^Lebensmittel/                           => {category}
        # ausgabe:Platinum                                   => Platinum
        # konto:/:Kantine$/                                  => Kantine

        TXT;

    public const AUTO_HEADER = '# --- auto: rules - always applied after all other rules, to automatic names only';

    /**
     * "auto:" rules act after all other rules anyway; move the ones standing above other
     * rules to the end of the file so the order in the file matches. Returns [text, moved].
     *
     * @return array{0: string, 1: int}
     */
    public static function autoLast(string $text): array
    {
        $lines  = explode("\n", $text);
        $isRule = static fn (string $l): bool => '' !== trim($l) && !str_starts_with(trim($l), '#');
        $isAuto = static fn (string $l): bool => 1 === preg_match('/^\s*(?:(?:ausgaben?|expense|einnahmen?|revenue):\s*)?auto:/i', $l);
        $last   = -1;
        foreach ($lines as $i => $l) {
            if ($isRule($l) && !$isAuto($l)) {
                $last = $i;
            }
        }
        $move = [];
        foreach ($lines as $i => $l) {
            if ($i < $last && $isRule($l) && $isAuto($l)) {
                $move[$i] = $l;
            }
        }
        if ([] === $move) {
            return [$text, 0];
        }
        $rest = array_values(array_diff_key($lines, $move));
        while ([] !== $rest && '' === trim((string) end($rest))) {
            array_pop($rest);
        }
        if (!in_array(self::AUTO_HEADER, $rest, true)) {
            array_push($rest, '', self::AUTO_HEADER);
        }
        $out = implode("\n", array_merge($rest, array_values($move)))."\n";

        return [$out, count($move)];
    }

    public static function load(?string $file): self
    {
        $r = new self();
        if (null === $file || !is_file($file)) {
            return $r;
        }
        foreach (file($file, FILE_IGNORE_NEW_LINES) as $no => $raw) {
            $line = trim($raw);
            if ('' === $line || str_starts_with($line, '#')) {
                continue;
            }
            $pos = strrpos($line, '=>');
            if (false === $pos) {
                throw new UserError(sprintf('%s:%d: rule needs "pattern => payee"', $file, $no + 1));
            }
            $pattern = trim(substr($line, 0, $pos));
            $payee   = trim(substr($line, $pos + 2));
            if ('' === $pattern || '' === $payee) {
                throw new UserError(sprintf('%s:%d: empty pattern or payee', $file, $no + 1));
            }
            // "cond && cond": all conditions must match; "ausgabe:"/"einnahme:" may lead any of them
            $side  = '';
            $conds = [];
            foreach (preg_split('/\s+&&\s+/', $pattern) ?: [] as $c) {
                $c     = trim($c);
                $field = 'desc';
                if (1 === preg_match('/^(ausgaben?|expense|einnahmen?|revenue):\s*(.*)$/is', $c, $m)) {
                    $cs = 1 === preg_match('/^(ausgaben?|expense)$/i', $m[1]) ? 'expense' : 'revenue';
                    if ('' !== $side && $side !== $cs) {
                        throw new UserError(sprintf('%s:%d: rule is limited to both expenses and revenues', $file, $no + 1));
                    }
                    $side = $cs;
                    $c    = trim($m[2]);
                }
                if (1 === preg_match('/^(desc|iban|category|memo|konto|account|auto):(.*)$/s', $c, $m)) {
                    $field = 'account' === $m[1] ? 'konto' : $m[1];
                    $c     = trim($m[2]);
                }
                if ('' === $c) {
                    throw new UserError(sprintf('%s:%d: empty pattern or payee', $file, $no + 1));
                }
                $regex = null;
                $text  = null;
                if ('iban' === $field) {
                    $text = Util::normalizeIban($c);
                } elseif (1 === preg_match('~^/.*/[a-zA-Z]*$~s', $c)) {
                    $regex = $c;
                    if (!str_contains(substr($regex, strrpos($regex, '/')), 'u')) {
                        $regex .= 'u';
                    }
                    if (false === @preg_match($regex, '')) {
                        throw new UserError(sprintf('%s:%d: invalid regular expression %s', $file, $no + 1, $c));
                    }
                } else {
                    $text = Util::lower($c);
                }
                $conds[] = ['field' => $field, 'regex' => $regex, 'text' => $text, 'raw' => $c];
            }
            $autoConds = array_filter($conds, static fn ($c) => 'auto' === $c['field']);
            if ([] !== $autoConds && count($autoConds) !== count($conds)) {
                throw new UserError(sprintf('%s:%d: "auto:" cannot be combined with other conditions', $file, $no + 1));
            }
            $r->rules[] = ['line' => $no + 1, 'side' => $side, 'field' => $conds[0]['field'], 'conds' => $conds, 'payee' => $payee, 'src' => $line];
        }

        return $r;
    }

    /**
     * @param list<string> $ibans
     * @param list<string> $memos
     *
     * @return null|array{0:string, 1:int} [payee, rule line]
     */
    /**
     * Rule pattern for text typed by a user (rule assistant of web.php):
     *   words  the words in this order, as whole words   "DB Hamburg" -> /\bDB\s+Hamburg\b/i
     *   all    all words as whole words, in any order     /^(?=.*?\bDB\b)(?=.*?\bHamburg\b)/i
     *   start  the booking text begins with the words     /^\s*DB\s+Hamburg\b/i
     *   text   contains the text, also inside words       /DB\s+Hamburg/i
     * $nameOnly limits it to the name after the last ";" of bank texts. An IBAN becomes "iban:...".
     * With /u (added by load()) \b also knows umlauts.
     */
    public static function build(string $query, string $mode = 'words', bool $nameOnly = false): string
    {
        $query = Util::collapse($query);
        if ('' === $query) {
            return '';
        }
        $compact = strtoupper(str_replace(' ', '', $query));
        if (1 === preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{10,30}$/', $compact) && Util::isValidIban($compact)) {
            return 'iban:'.$compact;
        }
        $tokens = preg_split('/\s+/u', $query) ?: [];
        $isWord = static fn (string $c): bool => 1 === preg_match('/^[\p{L}\p{N}_]$/u', $c);
        $left   = static fn (string $t): string => $isWord(mb_substr($t, 0, 1)) ? '\b' : '';
        $right  = static fn (string $t): string => $isWord(mb_substr($t, -1)) ? '\b' : '';
        // escape only what is special outside character classes: "->" stays readable
        $quoted = array_map(static fn (string $t): string => (string) preg_replace('~[\\\\.+*?\[\]^$(){}|/]~', '\\\\$0', $t), $tokens);
        $phrase = implode('\s+', $quoted);
        $first  = $tokens[0];
        $last   = $tokens[count($tokens) - 1];
        $body   = match ($mode) {
            'all'   => ($nameOnly ? '(?:^|;)(?![^;]*;)' : '^').implode('', array_map(
                static fn (string $t, string $q): string => '(?='.($nameOnly ? '[^;]*?' : '.*?').$left($t).$q.$right($t).')', $tokens, $quoted)),
            'start' => ($nameOnly ? '(?:^|;)\s*' : '^\s*').$phrase.$right($last).($nameOnly ? '(?![^;]*;)' : ''),
            'text'  => ($nameOnly ? '(?:^|;)(?![^;]*;)[^;]*?' : '').$phrase,
            default => $left($first).$phrase.$right($last).($nameOnly ? '(?![^;]*;)' : ''),
        };

        return '/'.$body.'/i'.(1 === preg_match('/[^\x00-\x7f]/', $body) ? 'u' : '');
    }

    /**
     * $side 'expense'|'revenue': rules limited with "ausgabe:"/"einnahme:" only match that side.
     * $accounts: full GnuCash paths of all accounts of the transaction ("konto:" rules).
     *
     * @param list<string> $accounts
     */
    public function match(string $description, array $ibans, string $category, array $memos, string $side = '', array $accounts = []): ?array
    {
        foreach ($this->rules as $rule) {
            $groups = $this->ruleHit($rule, $description, $ibans, $category, $memos, $side, $accounts);
            if (null === $groups) {
                continue;
            }
            $payee = $rule['payee'];
            if ('-' === $payee) {
                return ['-', $rule['line']];
            }
            $payee = (string) preg_replace_callback('/\$(\d)/', static fn ($m) => $groups[(int) $m[1]] ?? '', $payee);
            $payee = str_replace(['{category}', '{description}'], [$category, $description], $payee);
            $payee = Util::collapse($payee);

            return ['' === $payee ? '-' : $payee, $rule['line']];
        }

        return null;
    }

    /**
     * Lines of ALL rules that match (not only the first) - for the check of unused rules.
     *
     * @return list<int>
     */
    public function matchAll(string $description, array $ibans, string $category, array $memos, string $side = '', array $accounts = []): array
    {
        $lines = [];
        foreach ($this->rules as $rule) {
            if (null !== $this->ruleHit($rule, $description, $ibans, $category, $memos, $side, $accounts)) {
                $lines[] = $rule['line'];
            }
        }

        return $lines;
    }

    /** @return null|array regex groups of the first condition when the rule matches, else null ("auto:" rules never) */
    private function ruleHit(array $rule, string $description, array $ibans, string $category, array $memos, string $side, array $accounts): ?array
    {
        if (('' !== $rule['side'] && $rule['side'] !== $side) || 'auto' === $rule['field']) {
            return null;
        }
        $groups = null;                                  // regex groups of the first condition
        foreach ($rule['conds'] as $cond) {
            $subjects = match ($cond['field']) {
                'desc'     => [$description],
                'category' => [$category],
                'memo'     => $memos,
                'konto'    => $accounts,
                'iban'     => $ibans,
            };
            $hit = false;
            foreach ($subjects as $subject) {
                $g = [];
                if ('iban' === $cond['field']) {
                    $hit = $subject === $cond['text'];
                } elseif (null !== $cond['regex']) {
                    $hit = 1 === preg_match($cond['regex'], $subject, $g);
                } else {
                    $hit = str_contains(Util::lower($subject), (string) $cond['text']);
                }
                if ($hit) {
                    $groups ??= $g;

                    break;
                }
            }
            if (!$hit) {
                return null;
            }
        }

        return $groups ?? [];
    }

    /**
     * "auto:" rules: applied to an automatically derived counterparty name.
     *
     * @return null|array{0:string, 1:int} [payee or "-", rule line]
     */
    public function matchAuto(string $name, string $side = ''): ?array
    {
        foreach ($this->rules as $rule) {
            if ('auto' !== $rule['field'] || ('' !== $rule['side'] && $rule['side'] !== $side)) {
                continue;
            }
            $c = $rule['conds'][0];
            $g = [];
            $hit = null !== $c['regex'] ? 1 === preg_match($c['regex'], $name, $g) : str_contains(Util::lower($name), (string) $c['text']);
            if (!$hit) {
                continue;
            }
            $payee = $rule['payee'];
            if ('-' !== $payee) {
                $payee = Util::collapse(str_replace('{name}', $name, (string) preg_replace_callback('/\$(\d)/', static fn ($m) => $g[(int) $m[1]] ?? '', $payee)));
            }

            return ['' === $payee ? '-' : $payee, $rule['line']];
        }

        return null;
    }
}

// =====================================================================================
// Payee rules -> Firefly III rules ("firefly-rules"). Firefly rule triggers compare plain
// texts (contains / starts / ends / is, case-insensitive except "is"), IBANs, categories and
// accounts - no regular expressions. Simple expressions are expanded into the plain texts
// they stand for: /^Foo(bar|baz)?$/i -> "Foo", "Foobar", "Foobaz". Anything with wildcards
// or character classes stays in this tool. One payee rule can become several Firefly rules:
// one per side (withdrawal: set_destination_account, deposit: set_source_account) and one
// per alternative (Firefly rules can not combine "any of" with "all of").
// =====================================================================================

/** A payee rule (or one condition of it) Firefly can not express. */
final class RuleTranslationError extends \RuntimeException
{
    public function __construct(public string $reason, public string $detail = '')
    {
        parent::__construct('' === $detail ? $reason : $reason.' '.$detail);
    }
}

/**
 * The plain texts a simple regular expression stands for. Supported: literal characters,
 * escaped punctuation, alternatives and groups ((a|b), (?:a|b)), "?" after a character or
 * group, \s (one space), anchors ^ and $ at the ends, ".*" at the ends, and a leading
 * ^(?!.*(a|b)) exclusion. Approximations (recorded in $approx): \b is dropped, \s+ and \s*
 * become one or no space. Everything else throws RuleTranslationError('regex', construct).
 */
final class RegexLiterals
{
    public const MAX = 64;

    /** @var array<string, true> approximation codes: b, ws */
    public array $approx = [];

    /** @var list<string> */
    private array $c;
    private int $i = 0;

    public function __construct(string $body)
    {
        $this->c = mb_str_split($body, 1, 'UTF-8');
    }

    /** @return list<array{start:bool, end:bool, variants:list<string>, not:list<string>}> one entry per top-level alternative */
    public function parse(): array
    {
        $alts = [];
        while (true) {
            $alts[] = $this->alternative();
            if ('|' !== $this->peek()) {
                break;
            }
            ++$this->i;
        }
        if (null !== $this->peek()) {
            throw new RuleTranslationError('regex', (string) $this->peek());   // unbalanced ")"
        }

        return $alts;
    }

    private function peek(int $offset = 0): ?string
    {
        return $this->c[$this->i + $offset] ?? null;
    }

    private function alternative(): array
    {
        $start = false;
        $not   = [];
        if ('^' === $this->peek()) {
            ++$this->i;
            $start = true;
            // ^(?!.*(a|b)) - the text must not contain a or b (only valid right after ^)
            while ('(' === $this->peek() && '?' === $this->peek(1) && '!' === $this->peek(2)) {
                $this->i += 3;
                if ('.' !== $this->peek() || '*' !== $this->peek(1)) {
                    throw new RuleTranslationError('regex', '(?!');
                }
                $this->i += 2;
                foreach ($this->inner() as $v) {
                    if ('' === $v) {
                        throw new RuleTranslationError('empty');
                    }
                    $not[] = $v;
                }
                $this->expect(')');
            }
        }
        if ('.' === $this->peek() && '*' === $this->peek(1)) {        // ".*" at the start: anywhere
            $this->i += 2;
            $start = false;
        }
        [$variants, $end] = $this->sequence(true);

        return ['start' => $start, 'end' => $end, 'variants' => $variants, 'not' => array_values(array_unique($not))];
    }

    /** @return array{0: list<string>, 1: bool} variants, anchored at the end */
    private function sequence(bool $top): array
    {
        $variants = [''];
        $end      = false;
        while (null !== ($ch = $this->peek()) && '|' !== $ch && ')' !== $ch) {
            if ('$' === $ch) {
                $next = $this->peek(1);
                if ($top && (null === $next || '|' === $next)) {
                    ++$this->i;
                    $end = true;

                    break;
                }

                throw new RuleTranslationError('regex', '$');
            }
            if ($top && '.' === $ch && '*' === $this->peek(1)) {       // ".*" at the end: nothing to compare
                $next = $this->peek(2);
                if (null === $next || '|' === $next) {
                    $this->i += 2;

                    break;
                }
                if ('$' === $next && (null === $this->peek(3) || '|' === $this->peek(3))) {
                    $this->i += 3;

                    break;
                }
            }
            $variants = $this->product($variants, $this->quantified($this->atom()));
        }

        return [array_values(array_unique($variants)), $end];
    }

    /** @return array{0: list<string>, 1: string} choices and kind (char|space|group|none) */
    private function atom(): array
    {
        $ch = $this->c[$this->i++];
        switch ($ch) {
            case '\\':
                $e = $this->c[$this->i++] ?? null;
                if (null === $e) {
                    throw new RuleTranslationError('regex', '\\');
                }
                if ('b' === $e) {
                    $this->approx['b'] = true;

                    return [[''], 'none'];
                }
                if ('s' === $e) {
                    return [[' '], 'space'];
                }
                if (1 === preg_match('/^[\p{L}\p{N}]$/u', $e)) {
                    throw new RuleTranslationError('regex', '\\'.$e);
                }

                return [[$e], 'char'];

            case '(':
                if ('?' === $this->peek()) {
                    if (':' !== $this->peek(1)) {
                        throw new RuleTranslationError('regex', '(?'.$this->peek(1));
                    }
                    $this->i += 2;
                }
                $inner = $this->inner();
                $this->expect(')');

                return [$inner, 'group'];

            case '[':
                throw new RuleTranslationError('regex', '[…]');

            case '.':
            case '*':
            case '+':
            case '?':
            case '{':
            case '^':
            case '$':
                throw new RuleTranslationError('regex', $ch);

            default:
                return [[$ch], 'char'];
        }
    }

    /** @param array{0: list<string>, 1: string} $atom */
    private function quantified(array $atom): array
    {
        [$choices, $kind] = $atom;
        $q = $this->peek();
        if (!in_array($q, ['?', '*', '+', '{'], true)) {
            return $choices;
        }
        ++$this->i;
        if (in_array($this->peek(), ['?', '+'], true)) {
            throw new RuleTranslationError('regex', $q.$this->peek());   // lazy / possessive
        }
        if ('?' === $q && 'none' !== $kind) {
            return array_values(array_unique(array_merge([''], $choices)));
        }
        if ('space' === $kind && '+' === $q) {
            $this->approx['ws'] = true;

            return [' '];
        }
        if ('space' === $kind && '*' === $q) {
            $this->approx['ws'] = true;

            return ['', ' '];
        }

        throw new RuleTranslationError('regex', $q);
    }

    /** @return list<string> the alternatives of a group (until ")") */
    private function inner(): array
    {
        $out = [];
        while (true) {
            [$variants] = $this->sequence(false);
            $out        = array_merge($out, $variants);
            if ('|' !== $this->peek()) {
                break;
            }
            ++$this->i;
        }

        return array_values(array_unique($out));
    }

    private function expect(string $ch): void
    {
        if ($ch !== $this->peek()) {
            throw new RuleTranslationError('regex', '(');
        }
        ++$this->i;
    }

    /** @param list<string> $a @param list<string> $b @return list<string> */
    private function product(array $a, array $b): array
    {
        $out = [];
        foreach ($a as $x) {
            foreach ($b as $y) {
                $out[$x.$y] = true;
                if (count($out) > self::MAX) {
                    throw new RuleTranslationError('variants', '>'.self::MAX);
                }
            }
        }

        return array_map('strval', array_keys($out));
    }
}

final class FireflyRuleTranslator
{
    /** Firefly rules per payee rule and side at most (alternatives x accounts) */
    public const MAX_VARIANTS = 12;

    public const REASONS = [
        'auto'                 => '"auto:" rules have no Firefly equivalent',
        'memo'                 => 'Firefly rules can not check GnuCash split memos',
        'placeholder'          => 'the counterparty uses a placeholder (%s) - Firefly sets fixed names only',
        'fallback_placeholder' => 'the fallback counterparty "%s" contains a placeholder',
        'expression'           => 'the name starts with "=" (a Firefly rule expression)',
        'regex'                => 'the regular expression uses %s - Firefly rules have no patterns',
        'flags'                => 'regular expression option /%s',
        'empty'                => 'the pattern matches every text',
        'variants'             => 'too many alternatives (%s)',
        'konto_none'           => '"konto:" matches no account that exists in Firefly',
        'quote'                => 'the text contains " or \\',
        'too_long'             => 'the text is longer than 1024 characters',
    ];

    public const APPROX = [
        'b'             => '\b (word boundary) dropped - matches a bit more',
        'ws'            => '\s+ / \s* became one / no space',
        'case'          => 'no /i: Firefly ignores upper/lower case',
        'case_is'       => '^...$ with /i: Firefly compares "is" exactly (depends on the database)',
        'trim'          => 'spaces at the start/end removed',
        'like'          => '% or _ act as wildcards in Firefly',
        'konto_partial' => '"konto:" also matches accounts that do not exist in Firefly',
    ];

    /**
     * @param array<string, array<string, mixed>> $accounts GnuCash path => mapping (as, name) from import.json
     */
    public function __construct(private array $accounts, private string $fallback)
    {
    }

    /**
     * @return array{specs: list<array>, rules: list<array>}
     *   specs: the Firefly rules to create, in order (line, side, title, triggers, action, sim)
     *   rules: one entry per payee rule (line, src, payee, status ok|approx|approx_skipped|skipped,
     *          reason, detail, approx, firefly = number of Firefly rules)
     */
    public function translate(PayeeRules $rules, bool $withApprox): array
    {
        $specs = [];
        $info  = [];
        foreach ($rules->rules as $r) {
            $entry = ['line' => $r['line'], 'src' => (string) ($r['src'] ?? ''), 'payee' => $r['payee'], 'status' => 'ok', 'reason' => '', 'detail' => '', 'approx' => [], 'firefly' => 0];

            try {
                [$list, $approx] = $this->rule($r);
            } catch (RuleTranslationError $e) {
                $info[] = ['status' => 'skipped', 'reason' => $e->reason, 'detail' => $e->detail] + $entry;

                continue;
            }
            $entry['approx'] = $approx;
            if ([] !== $approx) {
                $entry['status'] = $withApprox ? 'approx' : 'approx_skipped';
                if (!$withApprox) {
                    $info[] = $entry;

                    continue;
                }
            }
            $entry['firefly'] = count($list);
            array_push($specs, ...$list);
            $info[] = $entry;
        }

        return ['specs' => $specs, 'rules' => $info];
    }

    public static function reasonText(string $reason, string $detail): string
    {
        return sprintf(self::REASONS[$reason] ?? $reason, $detail);
    }

    /** @return array{0: list<array>, 1: list<string>} Firefly rule specs and approximation codes */
    private function rule(array $r): array
    {
        foreach ($r['conds'] as $c) {
            if ('auto' === $c['field'] || 'memo' === $c['field']) {
                throw new RuleTranslationError($c['field']);
            }
        }
        $target = (string) $r['payee'];
        if ('-' === $target) {
            if (str_contains($this->fallback, '{')) {
                throw new RuleTranslationError('fallback_placeholder', $this->fallback);
            }
            $target = '' === trim($this->fallback) ? '(diverse)' : $this->fallback;
        } elseif (1 === preg_match('/\$\d|\{(category|description|name)\}/', $target, $m)) {
            throw new RuleTranslationError('placeholder', $m[0]);
        }
        $target = Util::truncate(Util::collapse($target), 255);
        if (str_starts_with($target, '=')) {
            throw new RuleTranslationError('expression');
        }
        $sides  = '' === $r['side'] ? ['expense', 'revenue'] : [$r['side']];
        $approx = [];
        $out    = [];
        foreach ($sides as $side) {
            $combos = [['triggers' => [], 'sim' => []]];
            foreach ($r['conds'] as $c) {
                [$opts, $ap] = $this->condOptions($c, $side);
                $approx      = array_merge($approx, $ap);
                $next        = [];
                foreach ($combos as $a) {
                    foreach ($opts as $b) {
                        $next[] = ['triggers' => array_merge($a['triggers'], $b['triggers']), 'sim' => array_merge($a['sim'], $b['sim'])];
                        if (count($next) > self::MAX_VARIANTS) {
                            throw new RuleTranslationError('variants', '>'.self::MAX_VARIANTS);
                        }
                    }
                }
                $combos = $next;
            }
            $n = count($combos);
            foreach ($combos as $k => $combo) {
                $out[] = [
                    'line'     => $r['line'],
                    'side'     => $side,
                    'title'    => self::title($r['line'], $target, $side, $k + 1, $n),
                    'triggers' => array_merge([['type' => 'transaction_type', 'value' => 'expense' === $side ? 'withdrawal' : 'deposit', 'prohibited' => false]], $combo['triggers']),
                    'action'   => ['type' => 'expense' === $side ? 'set_destination_account' : 'set_source_account', 'value' => $target],
                    'sim'      => $combo['sim'],
                ];
            }
        }

        return [$out, array_values(array_unique($approx))];
    }

    private static function title(int $line, string $target, string $side, int $k, int $n): string
    {
        $suffix = sprintf(' (%s%s)', 'expense' === $side ? 'Ausgabe' : 'Einnahme', $n > 1 ? sprintf(' %d/%d', $k, $n) : '');
        $prefix = sprintf('GnuCash Z.%d: ', $line);

        return $prefix.Util::truncate($target, 100 - mb_strlen($prefix.$suffix, 'UTF-8')).$suffix;
    }

    /** @return array{0: list<array{triggers: list<array>, sim: list<array>}>, 1: list<string>} alternatives (any of) and approximations */
    private function condOptions(array $c, string $side): array
    {
        switch ($c['field']) {
            case 'iban':
                $type = 'expense' === $side ? 'destination_account_nr_is' : 'source_account_nr_is';

                return [[['triggers' => [['type' => $type, 'value' => (string) $c['text'], 'prohibited' => false]], 'sim' => [['f' => 'iban', 'v' => (string) $c['text']]]]], []];

            case 'konto':
                return $this->kontoOptions($c);

            case 'category':
                return $this->textOptions((string) ($c['raw'] ?? ''), null !== $c['regex'], 'category', 'cat');

            default:
                return $this->textOptions((string) ($c['raw'] ?? ''), null !== $c['regex'], 'description', 'desc');
        }
    }

    private function kontoOptions(array $c): array
    {
        $out     = [];
        $partial = false;
        foreach ($this->accounts as $path => $m) {
            $path = (string) $path;
            $hit  = null !== $c['regex'] ? 1 === preg_match($c['regex'], $path) : str_contains(Util::lower($path), (string) $c['text']);
            if (!$hit) {
                continue;
            }
            $as   = (string) ($m['as'] ?? '');
            $name = (string) ($m['name'] ?? '');
            if ('' === $name || !in_array($as, ['asset', 'liability', 'category'], true)) {
                $partial = true;

                continue;
            }
            $out[] = ['triggers' => [['type' => 'category' === $as ? 'category_is' : 'account_is', 'value' => $name, 'prohibited' => false]], 'sim' => [['f' => 'acct', 'v' => $path]]];
        }
        if ([] === $out) {
            throw new RuleTranslationError('konto_none');
        }

        return [$out, $partial ? ['konto_partial'] : []];
    }

    /** @return array{0: list<array>, 1: list<string>} */
    private function textOptions(string $raw, bool $isRegex, string $kind, string $f): array
    {
        if (!$isRegex) {
            [$trigger, $sim, $ap] = self::value($kind, 'contains', trim($raw), $f, false);

            return [[['triggers' => [$trigger], 'sim' => [$sim]]], $ap];
        }
        if (1 !== preg_match('~^/(.*)/([a-zA-Z]*)$~s', $raw, $m)) {
            throw new RuleTranslationError('regex', $raw);
        }
        if (1 === preg_match('/[^ius]/', $m[2], $bad)) {
            throw new RuleTranslationError('flags', $bad[0]);
        }
        $ci     = str_contains($m[2], 'i');
        $parser = new RegexLiterals($m[1]);
        $alts   = $parser->parse();
        $approx = array_keys($parser->approx);
        $opts   = [];
        foreach ($alts as $alt) {
            $op = $alt['start'] && $alt['end'] ? 'is' : ($alt['start'] ? 'starts' : ($alt['end'] ? 'ends' : 'contains'));
            if ('is' === $op && $ci) {
                $approx[] = 'case_is';
            }
            if ('is' !== $op && !$ci) {
                $approx[] = 'case';
            }
            $notTriggers = [];
            $notSims     = [];
            foreach ($alt['not'] as $nv) {
                [$trigger, $sim, $ap] = self::value($kind, 'contains', $nv, $f, true);
                $notTriggers[]        = $trigger;
                $notSims[]            = $sim;
                $approx               = array_merge($approx, $ap);
            }
            foreach ($alt['variants'] as $v) {
                [$trigger, $sim, $ap] = self::value($kind, $op, $v, $f, false);
                $approx               = array_merge($approx, $ap);
                $opts[]               = ['triggers' => array_merge([$trigger], $notTriggers), 'sim' => array_merge([$sim], $notSims)];
            }
        }

        return [$opts, array_values(array_unique($approx))];
    }

    /** @return array{0: array, 1: array, 2: list<string>} trigger, simulation condition, approximations */
    private static function value(string $kind, string $op, string $v, string $f, bool $prohibited): array
    {
        $t  = trim($v);
        $ap = $t !== $v ? ['trim'] : [];
        if ('' === $t) {
            throw new RuleTranslationError('empty');
        }
        if (str_contains($t, '"') || str_contains($t, '\\')) {
            throw new RuleTranslationError('quote');
        }
        if (mb_strlen($t, 'UTF-8') > 1024) {
            throw new RuleTranslationError('too_long');
        }
        if (str_contains($t, '%') || str_contains($t, '_')) {
            $ap[] = 'like';
        }

        return [['type' => $kind.'_'.$op, 'value' => $t, 'prohibited' => $prohibited], ['f' => $f, 'op' => $op, 'v' => $t, 'not' => $prohibited], $ap];
    }

    /**
     * Which counterparty the Firefly rules would set for the bookings of the book, compared
     * with the import (first matching rule wins, like stop_processing in one rule group).
     *
     * @param list<array> $specs
     * @param list<array{tx:string, desc:string, side:string, category:string, accounts:list<string>, ibans:list<string>, name:string, source:string}> $cases
     *
     * @return array{total:int, same:int, other:int, missing:int, free:int, lines: array<int, array{hits:int, other:int, against:array<string, int>}>, missing_lines: array<int, int>, examples: list<array>}
     *   against: for the bookings where a Firefly rule sets another name - which payee rule
     *   ("37"), "auto" or "fallback" gave them their name in the import
     */
    public static function simulate(array $specs, array $cases): array
    {
        $buckets  = ['same' => [], 'other' => [], 'missing' => [], 'free' => []];
        $lines    = [];
        $missing  = [];
        $examples = [];
        $all      = [];
        foreach ($cases as $c) {
            $all[$c['tx']] = true;
            $hit           = null;
            foreach ($specs as $s) {
                if (self::matches($s, $c)) {
                    $hit = $s;

                    break;
                }
            }
            if (null === $hit) {
                if (1 === preg_match('/^rule:(?:fallback:)?(\d+)/', $c['source'], $m)) {
                    $buckets['missing'][$c['tx']]          = true;
                    $missing[(int) $m[1]][$c['tx']] = true;
                } else {
                    $buckets['free'][$c['tx']] = true;
                }

                continue;
            }
            $line                           = (int) $hit['line'];
            $lines[$line]['hits'][$c['tx']] = true;
            if ($hit['action']['value'] === $c['name']) {
                $buckets['same'][$c['tx']] = true;

                continue;
            }
            $buckets['other'][$c['tx']]      = true;
            $lines[$line]['other'][$c['tx']] = true;
            $lines[$line]['against'][preg_replace('/^rule:(?:fallback:)?/', '', $c['source'])][$c['tx']] = true;
            $key                             = $c['desc'].'|'.$c['side'].'|'.$line;
            if (!isset($examples[$key]) && count($examples) < 200) {
                $examples[$key] = ['desc' => $c['desc'], 'side' => $c['side'], 'ours' => $c['name'], 'source' => $c['source'], 'firefly' => $hit['action']['value'], 'line' => $line, 'count' => 0];
            }
            if (isset($examples[$key])) {
                ++$examples[$key]['count'];
            }
        }
        $out = ['total' => count($all), 'lines' => [], 'missing_lines' => [], 'examples' => array_values($examples)];
        foreach ($buckets as $k => $set) {
            $out[$k] = count($set);
        }
        foreach ($lines as $line => $l) {
            $out['lines'][$line] = ['hits' => count($l['hits'] ?? []), 'other' => count($l['other'] ?? []), 'against' => array_map('count', $l['against'] ?? [])];
        }
        foreach ($missing as $line => $set) {
            $out['missing_lines'][$line] = count($set);
        }
        usort($out['examples'], static fn ($a, $b) => $b['count'] <=> $a['count']);

        return $out;
    }

    private static function matches(array $spec, array $c): bool
    {
        if ($spec['side'] !== $c['side']) {
            return false;
        }
        foreach ($spec['sim'] as $cond) {
            $ok = match ($cond['f']) {
                'desc'  => self::compare($cond['op'], $c['desc'], $cond['v']),
                'cat'   => self::compare($cond['op'], $c['category'], $cond['v']),
                'iban'  => in_array($cond['v'], $c['ibans'], true),
                'acct'  => in_array($cond['v'], $c['accounts'], true),
                default => false,
            };
            if ($cond['not'] ?? false) {
                $ok = !$ok;
            }
            if (!$ok) {
                return false;
            }
        }

        return true;
    }

    /** Firefly: contains/starts/ends with LIKE (case-insensitive), "is" with = (exact). */
    private static function compare(string $op, string $subject, string $v): bool
    {
        if ('is' === $op) {
            return $subject === $v;
        }
        $s = Util::lower($subject);
        $v = Util::lower($v);

        return match ($op) {
            'starts' => str_starts_with($s, $v),
            'ends'   => str_ends_with($s, $v),
            default  => str_contains($s, $v),
        };
    }
}

final class PayeeResolver
{
    private const COUNTRY = ['DE', 'LU', 'NL', 'IE', 'GB', 'UK', 'US', 'FR', 'AT', 'CH', 'IT', 'ES', 'BE', 'DK', 'SE', 'PL', 'CZ', 'NO', 'FI', 'PT', 'GR', 'HU'];

    /** An IBAN seen with at least this many different counterparty names is a settlement account. */
    private const HUB_NAMES = 15;

    private const GENERIC = ['lastschrift', 'folgelastschrift', 'basislastschrift', 'ueberweisung', 'uberweisung', 'ueberw', 'gutschrift', 'gutschr', 'kartenzahlung',
        'dauerauftrag', 'abbuchung', 'einzahlung', 'auszahlung', 'geld', 'visa', 'mastercard', 'gmbh', 'und', 'der', 'die', 'das', 'von', 'fuer', 'mit', 'bank', 'konto'];

    /**
     * Several spellings of one counterparty share a word ("Bundeskasse Trier", "BUNDESKASSE IN
     * TRIER"); a card acquirer or payment provider account links unrelated names
     * ("eBay.O...", "Visa.Geld.zurueck.Aktio", "GUTSCHR. UEBERWEISUNG").
     *
     * @param list<string> $names
     */
    public static function isSettlementAccount(array $names): bool
    {
        $n = count($names);
        if ($n >= self::HUB_NAMES) {
            return true;
        }
        if ($n < 3) {
            return false;
        }
        $wordIn = [];
        foreach ($names as $i => $name) {
            foreach (preg_split('/[^\p{L}]+/u', $name) ?: [] as $w) {
                $k = Util::key($w);
                if (strlen($k) >= 3 && !in_array($k, self::GENERIC, true)) {
                    $wordIn[$k][$i] = true;
                }
            }
        }
        foreach ($wordIn as $in) {
            if (2 * count($in) >= $n) {
                return false;
            }
        }

        return true;
    }

    /** @var list<string> IBANs treated as settlement accounts (see HUB_NAMES) */
    public array $hubIbans = [];

    /** @var array<string, array<string, mixed>> usage key => usage */
    private array $usages = [];

    /** @var array<string, array{name:string, iban:?string, source:string}> */
    private array $resolved = [];

    public function __construct(private PayeeRules $rules, private ImportConfig $config)
    {
    }

    public static function ibansOf(GTransaction $t): array
    {
        $found = [];
        $texts = [$t->description];
        foreach ($t->splits as $s) {
            $texts[] = $s->memo;
        }
        foreach ($texts as $text) {
            if (preg_match_all('/(?:Konto|IBAN:?)\s+([A-Z]{2}\d{2}[A-Z0-9]{10,30})\b/u', $text, $m)) {
                foreach ($m[1] as $iban) {
                    if (Util::isValidIban($iban)) {
                        $found[Util::normalizeIban($iban)] = true;
                    }
                }
            }
        }

        return array_keys($found);
    }

    /**
     * Register that transaction $t needs a counterparty on $side ('expense'|'revenue') for $category.
     *
     * @param list<string> $accounts full paths of all accounts of $t (for "konto:" rules)
     */
    public function add(GTransaction $t, string $side, string $category, array $accounts = []): string
    {
        $key = $t->guid.'|'.$side.'|'.$category;
        if (!isset($this->usages[$key])) {
            $this->usages[$key] = ['tx' => $t, 'side' => $side, 'category' => $category, 'accounts' => $accounts];
        }

        return $key;
    }

    /**
     * Automatic counterparty for a booking text.
     *
     * @return array{0:string, 1:bool} [name, name belongs to the IBAN of the bank memo]
     */
    public function autoName(string $description): array
    {
        $d = Util::collapse($description);
        if ('' === $d) {
            return ['', false];
        }
        // card payments ("MERCHANT//CITY/DE; KARTENZAHLUNG ..."): the IBAN in the bank memo is
        // the card settlement account shared by all merchants, not the merchant's own
        $card = str_contains($d, '//') || 1 === preg_match('/KARTENZAHLUNG|Kartenverf(ü|ue)gung|girocard|Debitk\./iu', $d);
        [$name, $link] = $this->autoNameRaw($d);

        return [$name, $link && !$card];
    }

    /** @return array{0:string, 1:bool} */
    private function autoNameRaw(string $d): array
    {
        // card payments/refunds booked with the card issuer as counterparty:
        // "Kartenverfügung HERMES GERMANY/HAMBURG/DE/0 ...; DZ BANK AG" -> "HERMES GERMANY"
        if (1 === preg_match('~(?:Kartenverf(?:ü|ue)gung|Gutschrift von)\s+(.+?)/[^/;]*/[A-Z]{2}(?:/|\b)~u', $d, $m)) {
            $name = self::clean($m[1]);
            if (1 === preg_match('/\p{L}{2,}/u', $name)) {
                return [$name, false];
            }
        }
        // payment providers: the merchant is more useful than "PayPal"
        if (1 === preg_match('/Ihr Einkauf bei ([^,;]+)/iu', $d, $m) && 1 === preg_match('/\p{L}{2,}/u', $m[1])) {
            return [self::clean($m[1]), false];
        }
        if (1 === preg_match('/^(?:PAYPAL|PP|SUMUP|SQ|ZETTLE_?|IZ|STRIPE)\s?\*\s?([^;]+)/iu', $d, $m)) {
            $name = self::clean(explode(' - ', $m[1])[0]);
            if ('' !== $name) {
                return [$name, false];
            }
        }
        $splitDash = (bool) $this->config->opt('payee_split_dash');
        if (str_contains($d, ';')) {
            $before = trim(substr($d, 0, strpos($d, ';')));
            $after  = trim(substr($d, strpos($d, ';') + 1));
            // some banks write "NAME//CITY/DE; Basislastschrift ..." - then the name comes first
            if (1 === preg_match(self::PURPOSE, $after) && 0 === preg_match(self::PURPOSE, $before)) {
                $name = self::clean(explode('//', $before)[0]);
                if (1 === preg_match('/\p{L}{2,}/u', $name)) {
                    return [Util::truncate($name, 200), true];
                }
            }
            $after = explode(';', $after)[0];
            $after = explode('//', $after)[0];
            if ($splitDash && str_contains($after, ' - ')) {
                $after = explode(' - ', $after)[0];
            }
            $after = self::clean($after);
            if (1 === preg_match('/\p{L}{2,}/u', $after)) {
                return [Util::truncate($after, 200), true];
            }
            $d = trim(substr($d, 0, strpos($d, ';')));
        }
        if ($splitDash && str_contains($d, ' - ')) {
            $first = trim(explode(' - ', $d)[0]);
            if (1 === preg_match('/\p{L}{2,}/u', $first)) {
                $d = $first;
            }
        }
        $name = self::clean($d);

        return [Util::truncate('' === $name ? $d : $name, 200), true];
    }

    /** Booking texts that start like this are a purpose, not a counterparty name. */
    private const PURPOSE = '/^(SEPA[- ])?(basis|folge|erst|einmal)?lastschrift|^(echtzeit)?(ü|ue|u)berweisung|^gutschr|^dauerauftrag|^(debit)?kartenzahlung|^girocard|^abbuchung|^einzahlung|^auszahlung|^entgelt|^abschluss|^zinsen/iu';

    /** Words that end a counterparty name when they remain at its end ("Bäcker Erika Muster für"). */
    private const TRAILING = ['fuer', 'am', 'an', 'vom', 'von', 'bis', 'ab', 'zum', 'zur', 'zu', 'bei', 'mit', 'und', 'u', 'inkl', 'incl', 'per', 'wg', 'wegen',
        'nr', 'no', 'kw', 'aus', 'auf', 'in', 'im', 'for', 'of', 'at', 'to', 'and', 'via', 'ca', 'eur', 'euro', 'usd', 'dm', 'x'];

    /**
     * Remove what varies between bookings of the same counterparty: details in brackets
     * ("Bäcker Erika Muster ( für 21.03)" -> "Bäcker Erika Muster"), dates, times, amounts,
     * reference numbers, country codes and card-terminal noise.
     */
    private static function clean(string $s): string
    {
        do {                                                                           // (details), [details], nested
            $before = $s;
            $s      = (string) preg_replace('/\s*[(\[{][^()\[\]{}]*[)\]}]/u', ' ', $s);
        } while ($s !== $before);
        $s = (string) preg_replace('/\s*[(\[{].*$/u', ' ', $s);                            // unclosed "( für 22.11"
        $s = str_replace([')', ']', '}'], ' ', $s);
        $s = (string) preg_replace('/\b\d{4}-\d{2}-\d{2}(T\d{2}:\d{2}(:\d{2})?)?/u', ' ', $s);
        $s = (string) preg_replace('/\b\d{1,2}\.\d{1,2}\.(\d{2,4})?/u', ' ', $s);
        $s = (string) preg_replace('/\bum\s+\d{1,2}[:.]\d{2}([:.]\d{2})?\s*Uhr\b/iu', ' ', $s);
        $s = (string) preg_replace('/\b\d{1,2}:\d{2}(:\d{2})?\b/u', ' ', $s);
        $s = (string) preg_replace('/\bSAGT DANKE\b\.?/iu', ' ', $s);
        $s = (string) preg_replace('~/[A-Z0-9]{4,}\b~u', ' ', $s);                     // "Aktion/VISACLP0504"
        $s = str_replace(['*', '€'], ' ', $s);
        $s = (string) preg_replace('/\.(?=[A-Z0-9-]*\d[A-Z0-9-]*\b)/u', ' ', $s);   // "DHL.1AB23CD456EF" -> "DHL 1AB23CD456EF"
        $tokens = preg_split('/\s+/u', trim($s)) ?: [];
        $keep   = [];
        foreach ($tokens as $tok) {
            $digits = preg_match_all('/\d/', $tok);
            if ($digits >= 3 && ($digits * 2 >= mb_strlen($tok) || 0 === preg_match('/\p{Ll}/u', $tok))) {
                continue; // reference numbers like 0J1F30075, 028-1234567-7654321, 20099
            }
            if ($digits > 0 && 1 === preg_match('/^[\d.,:;\/+\-%x×]+$/u', $tok)) {
                continue; // amounts, quantities and dates like 12,50  7.3  22-11  3x
            }
            $keep[] = $tok;
        }
        while (count($keep) > 1) {
            $last = (string) end($keep);
            if (in_array($last, self::COUNTRY, true) || in_array(Util::key($last), self::TRAILING, true) || 0 === preg_match('/\p{L}|\d/u', $last)
                || 1 === mb_strlen(trim($last, '.,;:-')) ) {
                array_pop($keep);

                continue;
            }

            break;
        }

        return Util::collapse(trim(implode(' ', $keep), " \t,.;:-*/+#&"));
    }

    /**
     * "Name + extra words" -> "Name", if "Name" (at least two words) is itself the text of at
     * least two bookings: "Bäcker Erika Muster Samstag" -> "Bäcker Erika Muster".
     *
     * @param array<string, int>    $count   transactions per candidate key
     * @param array<string, string> $display display name per candidate key
     */
    private static function basePrefix(string $cand, array $count, array $display): ?string
    {
        $words = preg_split('/\s+/u', $cand) ?: [];
        for ($n = count($words) - 1; $n >= 2; --$n) {
            $prefix = implode(' ', array_slice($words, 0, $n));
            $k      = Util::key($prefix);
            if (strlen($k) >= 5 && ($count[$k] ?? 0) >= 2) {
                return $display[$k];
            }
        }

        return null;
    }

    /** Resolve all registered usages (rules, prefix merge, settlement accounts, IBAN grouping, min-count fallback). */
    public function resolve(): void
    {
        $minCount = max(1, (int) $this->config->opt('payee_min_count'));
        $fallback = (string) $this->config->opt('payee_fallback');
        $byIban   = (bool) $this->config->opt('payee_group_by_iban');
        $auto     = [];
        foreach ($this->usages as $key => &$u) {
            /** @var GTransaction $t */
            $t          = $u['tx'];
            $u['ibans'] = self::ibansOf($t);
            $memos      = array_values(array_filter(array_map(static fn (GSplit $s) => $s->memo, $t->splits), static fn ($m) => '' !== $m));
            $hit        = $this->rules->match($t->description, $u['ibans'], $u['category'], $memos, $u['side'], $u['accounts'] ?? []);
            if (null !== $hit) {
                $u['name']     = '-' === $hit[0] ? $this->fallbackName($fallback, $u, $t) : $hit[0];
                $u['source']   = '-' === $hit[0] ? 'rule:fallback:'.$hit[1] : 'rule:'.$hit[1];
                // a rule only changes the texts it matches (use "iban:" rules for whole IBANs):
                // "ÜBERWEISUNG ... BÄCKER ERIKA MUSTER ...; MARTHA BEISPIEL" carries the IBAN of
                // the recipient, which must not turn all her bookings into "Bäcker Erika Muster"
                $u['linkIban'] = $this->autoName($t->description)[1];

                continue;
            }
            [$u['cand'], $u['linkIban']] = $this->autoName($t->description);
            if ('' === $u['cand']) {
                $u['name']   = $this->fallbackName($fallback, $u, $t);
                $u['source'] = 'fallback';

                continue;
            }
            $u['key']  = Util::key($u['cand']);
            $auto[]    = $key;
        }
        unset($u);

        // variants with extra words collapse onto the plain name ("Bäcker Erika Muster ...")
        if ((bool) $this->config->opt('payee_merge_prefix')) {
            $count   = [];
            $names   = [];
            foreach ($auto as $key) {
                $u                                  = $this->usages[$key];
                $count[$u['key']][$u['tx']->guid] = true;
                $names[$u['key']][$u['cand']]     = ($names[$u['key']][$u['cand']] ?? 0) + 1;
            }
            $count   = array_map('count', $count);
            $display = [];
            foreach ($names as $k => $list) {
                arsort($list);
                $display[$k] = (string) array_key_first($list);
            }
            foreach ($auto as $key) {
                $u    = &$this->usages[$key];
                $base = self::basePrefix($u['cand'], $count, $display);
                if (null !== $base) {
                    $u['cand'] = $base;
                    $u['key']  = Util::key($base);
                }
                unset($u);
            }
        }

        // an IBAN used with many different names is a clearing/settlement account (card
        // acquirer, payment provider): it must not merge those names
        $namesPerIban = [];
        foreach ($auto as $key) {
            $u = $this->usages[$key];
            if ($u['linkIban']) {
                foreach ($u['ibans'] as $iban) {
                    $namesPerIban[$iban][$u['key']] = $u['cand'];
                }
            }
        }
        $hub = [];
        foreach ($namesPerIban as $iban => $names) {
            if (self::isSettlementAccount(array_values($names))) {
                $hub[$iban] = true;
            }
        }
        foreach ($auto as $key) {
            if ([] !== $hub) {
                $this->usages[$key]['ibans'] = array_values(array_filter($this->usages[$key]['ibans'], static fn ($i) => !isset($hub[$i])));
            }
        }
        foreach ($this->usages as $key => $u) {
            if (isset($u['source']) && [] !== $hub) {
                $this->usages[$key]['ibans'] = array_values(array_filter($u['ibans'], static fn ($i) => !isset($hub[$i])));
            }
        }
        $this->hubIbans = array_keys($hub);

        $rest = $auto;

        // union-find over name keys and IBANs
        $parent = [];
        $find   = static function (string $x) use (&$parent, &$find): string {
            while ($parent[$x] !== $x) {
                $parent[$x] = $parent[$parent[$x]];
                $x          = $parent[$x];
            }

            return $x;
        };
        $union = static function (string $a, string $b) use (&$parent, $find): void {
            $ra = $find($a);
            $rb = $find($b);
            if ($ra !== $rb) {
                $parent[$ra] = $rb;
            }
        };
        foreach ($rest as $key) {
            $u     = $this->usages[$key];
            $nodes = [strlen($u['key']) >= 3 ? 'k:'.$u['key'] : 'u:'.$key];
            if ($byIban && $u['linkIban']) {
                foreach ($u['ibans'] as $iban) {
                    $nodes[] = 'i:'.$iban;
                }
            }
            foreach ($nodes as $n) {
                $parent[$n] ??= $n;
            }
            for ($i = 1, $c = count($nodes); $i < $c; ++$i) {
                $union($nodes[0], $nodes[$i]);
            }
            $this->usages[$key]['node'] = $nodes[0];
        }
        $components = [];
        foreach ($rest as $key) {
            $root                        = $find($this->usages[$key]['node']);
            $components[$root]['keys'][] = $key;
            $cand                        = $this->usages[$key]['cand'];
            $components[$root]['names'][$cand] = ($components[$root]['names'][$cand] ?? 0) + 1;
            $components[$root]['tx'][$this->usages[$key]['tx']->guid] = true;
        }
        foreach ($components as $comp) {
            $names = $comp['names'];
            uksort($names, static fn ($a, $b) => [$names[$b], mb_strlen($a), $a] <=> [$names[$a], mb_strlen($b), $b]);
            $name   = (string) array_key_first($names);
            $enough = count($comp['tx']) >= $minCount;
            foreach ($comp['keys'] as $key) {
                $u = &$this->usages[$key];
                // "auto:" rules block or rename automatic names ("auto:/paypal/i => -")
                $block = $enough ? $this->rules->matchAuto($name, $u['side']) : null;
                if (null !== $block) {
                    $u['name']   = '-' === $block[0] ? $this->fallbackName($fallback, $u, $u['tx']) : $block[0];
                    $u['source'] = '-' === $block[0] ? 'rule:fallback:'.$block[1] : 'rule:'.$block[1];
                } elseif ($enough) {
                    $u['name']   = $name;
                    $u['source'] = 'auto';
                } else {
                    $u['name']   = $this->fallbackName($fallback, $u, $u['tx']);
                    $u['source'] = 'fallback';
                }
                unset($u);
            }
        }

        // IBAN of the counterparty account: the most frequent one, unique per side
        $ibanCount = [];
        foreach ($this->usages as $u) {
            if (str_contains($u['source'], 'fallback') || !($u['linkIban'] ?? true)) {
                continue;
            }
            foreach ($u['ibans'] as $iban) {
                $ibanCount[$u['side']][$u['name']][$iban] = ($ibanCount[$u['side']][$u['name']][$iban] ?? 0) + 1;
            }
        }
        $chosen = [];
        if ((bool) $this->config->opt('payee_set_iban')) {
            foreach ($ibanCount as $side => $names) {
                $owners = [];
                foreach ($names as $name => $ibans) {
                    foreach (array_keys($ibans) as $iban) {
                        $owners[$iban][$name] = true;
                    }
                }
                foreach ($names as $name => $ibans) {
                    arsort($ibans);
                    foreach (array_keys($ibans) as $iban) {
                        if (1 === count($owners[$iban])) {   // only IBANs that belong to this payee alone
                            $chosen[$side][$name] = (string) $iban;

                            break;
                        }
                    }
                }
            }
        }
        foreach ($this->usages as $key => $u) {
            $this->resolved[$key] = [
                'name'   => Util::truncate($u['name'], 255),
                'iban'   => $chosen[$u['side']][$u['name']] ?? null,
                'source' => $u['source'],
            ];
        }
    }

    private function fallbackName(string $fallback, array $u, GTransaction $t): string
    {
        $name = str_replace(['{category}', '{description}'], [$u['category'], $t->description], $fallback);
        $name = Util::collapse($name);

        return '' === $name ? '(diverse)' : $name;
    }

    /** @return array{name:string, iban:?string, source:string} */
    public function get(string $key): array
    {
        return $this->resolved[$key] ?? throw new \LogicException('payee not resolved: '.$key);
    }

    /**
     * Rules that give no booking its counterparty: they match nothing, or every booking they
     * match already gets its name from an earlier rule (by: earlier rule line => bookings).
     *
     * @return list<array{line:int, src:string, matches:int, by:array<int, int>}>
     */
    public function unusedRules(): array
    {
        $used = [];
        foreach ($this->resolved as $r) {
            if (1 === preg_match('/^rule:(?:fallback:)?(\d+)$/', $r['source'], $m)) {
                $used[(int) $m[1]] = true;
            }
        }
        $unused = [];
        foreach ($this->rules->rules as $rule) {
            if (!isset($used[$rule['line']])) {
                $unused[$rule['line']] = $rule;
            }
        }
        if ([] === $unused) {
            return [];
        }
        $matches = [];
        $by      = [];
        foreach ($this->usages as $key => $u) {
            /** @var GTransaction $t */
            $t     = $u['tx'];
            $memos = array_values(array_filter(array_map(static fn (GSplit $sp) => $sp->memo, $t->splits), static fn ($m) => '' !== $m));
            $lines = $this->rules->matchAll($t->description, self::ibansOf($t), $u['category'], $memos, $u['side'], $u['accounts'] ?? []);
            if ([] === $lines) {
                continue;
            }
            $winner = 1 === preg_match('/^rule:(?:fallback:)?(\d+)$/', $this->resolved[$key]['source'], $m) ? (int) $m[1] : 0;
            foreach ($lines as $line) {
                if (isset($unused[$line])) {
                    $matches[$line][$t->guid]       = true;
                    $by[$line][$winner][$t->guid]   = true;
                }
            }
        }
        $out = [];
        foreach ($unused as $line => $rule) {
            $b = array_map('count', $by[$line] ?? []);
            arsort($b);
            $out[] = ['line' => $line, 'src' => (string) ($rule['src'] ?? ''), 'matches' => count($matches[$line] ?? []), 'by' => $b, 'auto' => 'auto' === $rule['field']];
        }

        return $out;
    }

    /** @return array<string, array<string, mixed>> */
    public function usages(): array
    {
        return $this->usages;
    }

    /** Remove IBANs that Firefly would reject (used by an asset/liability account). */
    public function dropIbans(array $taken): void
    {
        foreach ($this->resolved as &$r) {
            if (null !== $r['iban'] && isset($taken[$r['iban']])) {
                $r['iban'] = null;
            }
        }
        unset($r);
    }
}

// =====================================================================================
// Decomposition: one balanced GnuCash transaction (n splits) -> Firefly transaction
// groups (withdrawals / deposits / transfers, each journal = source -> destination).
// =====================================================================================

final class Journal
{
    public string $type        = 'withdrawal';

    /** @var array{0:string, 1:?string} ['bs', guid] | ['clearing', null] | ['payee', usage key] */
    public array $src          = ['bs', null];
    public array $dst          = ['bs', null];
    public int $amount         = 0;
    public string $currency    = 'EUR';
    public int $decimals       = 2;
    public ?int $foreignAmount = null;
    public ?string $foreignCurrency = null;
    public int $foreignDecimals = 2;
    public string $description = '';
    public ?string $category   = null;

    /** GnuCash commodity and signed quantity of the P&L side (for verification). */
    public ?string $plCurrency = null;
    public int $plQty          = 0;
    public string $notes       = '';
    public bool $reconciled    = false;

    /** @var list<int> indices of the GnuCash splits this journal came from */
    public array $splits       = [];

    /** part of a transfer of an account to itself, booked as two transactions via the clearing account */
    public bool $self          = false;
}

final class Group
{
    public ?string $title = null;

    /** half of a transfer of an account to itself (see Journal::$self) */
    public bool $self     = false;

    /** @var list<Journal> */
    public array $journals = [];

    public function __construct(public string $type)
    {
    }
}

final class TxPlan
{
    /** @var list<Group> */
    public array $groups     = [];
    public ?string $skip     = null;

    /** @var list<string> */
    public array $warnings   = [];
    public bool $usesClearing = false;

    /** @var list<string> transfers of an account to itself, booked via the clearing account */
    public array $selfNotes  = [];

    /** @var list<int> splits that could not be imported (quantity without value) */
    public array $dropped    = [];

    /** @var array<int, int> splits whose quantity was replaced (idx => quantity in minor units) */
    public array $adjusted   = [];

    public function __construct(public GTransaction $tx)
    {
    }

    public function journalCount(): int
    {
        $n = 0;
        foreach ($this->groups as $g) {
            $n += count($g->journals);
        }

        return $n;
    }
}

final class Decomposer
{
    /** ISO 4217 minor units that are not 2 */
    private const DECIMALS = ['JPY' => 0, 'KRW' => 0, 'ISK' => 0, 'CLP' => 0, 'VND' => 0, 'TWD' => 0, 'UGX' => 0, 'PYG' => 0, 'XAF' => 0, 'XOF' => 0,
        'BHD'                      => 3, 'KWD' => 3, 'JOD' => 3, 'OMR' => 3, 'TND' => 3, 'IQD' => 3, 'LYD' => 3];

    /** @var array<string, array{0:string, 1:string}> opening-balance transactions: tx guid => [account guid, amount] */
    public array $openingTx = [];

    /** @var array<string, array{amount:int, date:string, decimals:int}> account guid => opening balance */
    public array $openingBalance = [];

    private string $clearingName;
    private string $states;

    public function __construct(private Book $book, private ImportConfig $config, private PayeeResolver $payees, private bool $multisource = true)
    {
        $this->clearingName = (string) $config->opt('clearing_account');
        $this->states       = (string) $config->opt('reconciled_states');
        if ((bool) $config->opt('opening_balances')) {
            $this->detectOpeningBalances();
        }
    }

    /** @return list<string> full paths of all accounts of $t */
    private function accountPaths(GTransaction $t): array
    {
        return array_values(array_unique(array_map(fn (GSplit $sp): string => $this->book->account($sp->account)->path, $t->splits)));
    }

    public static function decimals(string $currency): int
    {
        return self::DECIMALS[$currency] ?? 2;
    }

    public function setMultisource(bool $on): void
    {
        $this->multisource = $on;
    }

    public function mapping(string $guid): array
    {
        return $this->config->accounts[$guid] ?? ['as' => 'skip'];
    }

    public function isBs(string $guid): bool
    {
        return in_array($this->mapping($guid)['as'] ?? '', ['asset', 'liability'], true);
    }

    /** First transaction of an asset account = 2-split transfer from an equity account -> opening balance. */
    private function detectOpeningBalances(): void
    {
        $first = [];
        foreach ($this->book->transactions as $t) {
            foreach ($t->splits as $s) {
                if (!isset($first[$s->account]) && '0' !== explode('/', $s->quantity)[0]) {
                    $first[$s->account] = $t;
                }
            }
        }
        foreach ($first as $guid => $t) {
            if ('asset' !== ($this->mapping($guid)['as'] ?? '')) {
                continue;
            }
            $nonZero = array_values(array_filter($t->splits, static fn (GSplit $s) => '0' !== explode('/', $s->value)[0] || '0' !== explode('/', $s->quantity)[0]));
            $nonZero = array_values(array_filter($nonZero, fn (GSplit $s) => 'TRADING' !== $this->book->account($s->account)->type));
            if (2 !== count($nonZero)) {
                continue;
            }
            $other = $nonZero[0]->account === $guid ? $nonZero[1] : $nonZero[0];
            $mine  = $nonZero[0]->account === $guid ? $nonZero[0] : $nonZero[1];
            if ('EQUITY' !== $this->book->account($other->account)->type || $other->account === $guid) {
                continue;
            }
            $acc                          = $this->book->account($guid);
            $this->openingTx[$t->guid]    = [$guid, $mine->quantity];
            $this->openingBalance[$guid]  = [
                'amount'   => Util::fracToMinor($mine->quantity, $acc->scu),
                'date'     => $t->date,
                'decimals' => Util::decimalsForFraction($acc->scu),
            ];
        }
    }

    public function decompose(GTransaction $t): TxPlan
    {
        $plan = new TxPlan($t);
        if (isset($this->openingTx[$t->guid])) {
            $plan->skip = 'opening balance (set on the Firefly account)';

            return $plan;
        }
        $txDec  = self::decimals($t->currency);
        $txFrac = Util::fractionForDecimals($txDec);

        // ---- classify splits
        $sp        = [];
        $zeroMemos = [];
        $inexact   = false;
        foreach ($t->splits as $idx => $s) {
            $acc = $this->book->account($s->account);
            $map = $this->mapping($s->account);
            $as  = $map['as'] ?? 'skip';
            if ('TRADING' === $acc->type && 'ignore' === $as) {
                continue;
            }
            $v = Util::fracToMinor($s->value, $txFrac, $ie1);
            if ('skip' === $as || 'ignore' === $as) {
                if (0 === $v) {
                    continue;
                }
                $plan->skip = sprintf('uses account "%s" which is mapped to "%s"', $acc->path, $as);

                return $plan;
            }
            $isBs    = in_array($as, ['asset', 'liability'], true);
            $ffCur   = $isBs ? (string) ($map['currency'] ?? $acc->cmdtyId) : $acc->cmdtyId;
            if ($acc->isCurrency() && $ffCur === $acc->cmdtyId) {
                $qCur = $acc->cmdtyId;
                $qDec = Util::decimalsForFraction($acc->scu);
                $q    = Util::fracToMinor($s->quantity, $acc->scu, $ie2);
            } else {
                // securities (Firefly has no share quantities) or a Firefly account in another
                // currency than in GnuCash: use the transaction value
                $qCur = $isBs ? $ffCur : $t->currency;
                $qDec = $txDec;
                $q    = $v;
                $ie2  = false;
                if ($qCur !== $t->currency) {
                    $plan->warnings[] = sprintf('account "%s" (%s) is mapped to %s, but the transaction is in %s - amount taken 1:1', $acc->path, $acc->cmdtyId, $qCur, $t->currency);
                } elseif (!$acc->isCurrency()) {
                    $plan->warnings[] = sprintf('account "%s" holds %s, imported with its value in %s', $acc->path, $acc->cmdtyId, $t->currency);
                }
            }
            $inexact = $inexact || $ie1 || $ie2;
            if (0 === $v && 0 === $q) {
                if ('' !== trim($s->memo)) {
                    $zeroMemos[] = $idx;
                }

                continue;
            }
            if (0 === $v) {
                $plan->warnings[] = sprintf('split on "%s" changes the quantity (%s) without a value - not importable, dropped', $acc->path, $s->quantity);
                $plan->dropped[]  = $idx;

                continue;
            }
            if (0 === $q || ($v > 0) !== ($q > 0)) {
                $plan->warnings[] = sprintf('split on "%s" has value %s but quantity %s - using the value', $acc->path, $s->value, $s->quantity);
                $q = Util::share($v, Util::fractionForDecimals($qDec), $txFrac);
                if (0 === $q) {
                    $q = $v > 0 ? 1 : -1;
                }
                $plan->adjusted[$idx] = $q;
            }
            $sp[$idx] = [
                'idx'  => $idx, 's' => $s, 'acc' => $acc, 'map' => $map,
                'kind' => $isBs ? 'bs' : 'pl', 'liab' => 'liability' === $as,
                'v'    => $v, 'q' => $q, 'qcur' => $qCur, 'qdec' => $qDec,
            ];
        }
        if ([] === $sp) {
            $plan->skip = 'all amounts are zero';

            return $plan;
        }
        $sum = array_sum(array_column($sp, 'v'));
        if (0 !== $sum) {
            if ($inexact && abs($sum) <= count($sp)) {
                // rounding of non-standard denominators: put the difference on the largest split
                $big = null;
                foreach ($sp as $i => $x) {
                    if (null === $big || abs($x['v']) > abs($sp[$big]['v'])) {
                        $big = $i;
                    }
                }
                $sp[$big]['v'] -= $sum;
                $plan->warnings[] = 'rounded values adjusted by '.Util::minorToDec(-$sum, $txDec);
            } else {
                $plan->skip = sprintf('transaction is not balanced (difference %s %s)', Util::minorToDec($sum, $txDec), $t->currency);

                return $plan;
            }
        }
        $bsAccounts = [];
        foreach ($sp as $x) {
            if ('bs' === $x['kind']) {
                $bsAccounts[$x['s']->account] = true;
            }
        }
        $hasPl = in_array('pl', array_column($sp, 'kind'), true);
        if (!$hasPl && count($bsAccounts) < 2) {
            $plan->skip = 'moves money only within one account';

            return $plan;
        }

        // ---- capacities and pivot
        $srcCap = [];
        $dstCap = [];
        $sBs    = $dPl = 0;
        foreach ($sp as $i => $x) {
            $srcCap[$i] = $x['v'] < 0 ? -$x['v'] : 0;
            $dstCap[$i] = $x['v'] > 0 ? $x['v'] : 0;
            if ('bs' === $x['kind']) {
                $sBs += $srcCap[$i];
            } else {
                $dPl += $dstCap[$i];
            }
        }
        $extra = max(0, $dPl - $sBs);
        $pivot = null;
        if ($extra > 0) {
            foreach ($sp as $i => $x) {
                if ('bs' !== $x['kind']) {
                    continue;
                }
                if (null === $pivot
                    || (!$x['liab'] && $sp[$pivot]['liab'])
                    || ($x['liab'] === $sp[$pivot]['liab'] && abs($x['v']) > abs($sp[$pivot]['v']))) {
                    $pivot = $i;
                }
            }
            if (null === $pivot) {
                $pivot              = 'C';
                $plan->usesClearing = true;
                $srcCap['C']        = 0;
                $dstCap['C']        = 0;
            }
            $srcCap[$pivot] += $extra;
            $dstCap[$pivot] += $extra;
        }

        $isBsNode = static fn ($i): bool => 'C' === $i || 'bs' === $sp[$i]['kind'];
        $acctOf   = static fn ($i): string => 'C' === $i ? '#clearing' : $sp[$i]['s']->account;
        $order    = array_keys($sp);
        if ('C' === $pivot) {
            $order[] = 'C';
        }
        $bsSrc = array_values(array_filter($order, static fn ($i) => $isBsNode($i) && $srcCap[$i] > 0));
        $bsDst = array_values(array_filter($order, static fn ($i) => $isBsNode($i) && $dstCap[$i] > 0));
        $plSrc = array_values(array_filter($order, static fn ($i) => !$isBsNode($i) && $srcCap[$i] > 0));
        $plDst = array_values(array_filter($order, static fn ($i) => !$isBsNode($i) && $dstCap[$i] > 0));
        // use the pivot last so that real money flows are paired first
        $pivotLast = static function (array $list) use ($pivot): array {
            if (null === $pivot || !in_array($pivot, $list, true)) {
                return $list;
            }
            $list   = array_values(array_filter($list, static fn ($i) => $i !== $pivot));
            $list[] = $pivot;

            return $list;
        };
        $bsSrc = $pivotLast($bsSrc);
        $bsDst = $pivotLast($bsDst);

        $flows = [];
        $pair  = static function (array $needs, array $providers, bool $needIsDst, bool $forbidSame) use (&$srcCap, &$dstCap, &$flows, $acctOf): void {
            // pass 1: exact amounts, pass 2: greedy in split order
            foreach ([true, false] as $exactPass) {
                foreach ($needs as $n) {
                    foreach ($providers as $p) {
                        $need = $needIsDst ? $dstCap[$n] : $srcCap[$n];
                        if ($need <= 0) {
                            break;
                        }
                        $have = $needIsDst ? $srcCap[$p] : $dstCap[$p];
                        if ($have <= 0 || ($forbidSame && $acctOf($n) === $acctOf($p))) {
                            continue;
                        }
                        if ($exactPass && $have !== $need) {
                            continue;
                        }
                        $f = min($need, $have);
                        if ($needIsDst) {
                            $dstCap[$n] -= $f;
                            $srcCap[$p] -= $f;
                            $flows[]     = [$p, $n, $f];
                        } else {
                            $srcCap[$n] -= $f;
                            $dstCap[$p] -= $f;
                            $flows[]     = [$n, $p, $f];
                        }
                        if ($exactPass) {
                            break;
                        }
                    }
                }
            }
        };
        $pair($plDst, $bsSrc, true, false);   // expenses (and income reversals) paid from asset/liability accounts
        $pair($plSrc, $bsDst, false, false);  // income (and refunds) received on asset/liability accounts
        $pair($bsDst, $bsSrc, true, true);    // transfers between asset/liability accounts
        // the pivot's virtual extra capacity that was not needed
        if (null !== $pivot && $extra > 0) {
            $virt             = min($srcCap[$pivot], $dstCap[$pivot], $extra);
            $srcCap[$pivot] -= $virt;
            $dstCap[$pivot] -= $virt;
        }
        // what is left moves money from an account to itself (e.g. +50 and -6.12 on the same
        // card): book it as two transactions via the clearing account, linked by tag and link
        $selfOut = [];
        $selfIn  = [];
        foreach ($order as $i) {
            if (($srcCap[$i] ?? 0) <= 0 && ($dstCap[$i] ?? 0) <= 0) {
                continue;
            }
            if (!$isBsNode($i) || 'C' === $i) {
                $plan->skip = 'internal error: unpaired split';

                return $plan;
            }
            if ($srcCap[$i] > 0) {
                $selfOut[$i] = $srcCap[$i];
            }
            if ($dstCap[$i] > 0) {
                $selfIn[$i] = $dstCap[$i];
            }
        }
        $selfFlows = [];
        if (array_sum($selfOut) !== array_sum($selfIn)) {
            $plan->skip = 'internal error: unbalanced transfer to itself';

            return $plan;
        }
        if ([] !== $selfOut) {
            $names = [];
            foreach ($selfOut + $selfIn as $i => $c) {
                $names[$acctOf($i)] = $this->book->account($acctOf($i))->path;
            }
            foreach ($selfOut as $i => $c) {
                $selfFlows[count($flows)] = true;
                $flows[]                  = [$i, 'C', $c];
            }
            foreach ($selfIn as $i => $c) {
                $selfFlows[count($flows)] = true;
                $flows[]                  = ['C', $i, $c];
            }
            $plan->usesClearing = true;
            $plan->selfNotes[]  = sprintf('%s %s moved from an account to itself (%s) - booked as two linked transactions via %s',
                Util::minorToDec(array_sum($selfOut), $txDec), $t->currency, implode(', ', $names), $this->clearingName);
        }
        if ([] === $flows) {
            $plan->skip = 'nothing left to import';

            return $plan;
        }

        // ---- quantities per flow (exact per split, rounding remainder on the last flow)
        $qtyOut = [];
        $qtyIn  = [];
        $byNode = [];
        foreach ($flows as $k => [$from, $to, $f]) {
            $byNode[$from]['out'][] = $k;
            $byNode[$to]['in'][]    = $k;
        }
        foreach ($byNode as $node => $dirs) {
            if ('C' === $node) {
                foreach ($dirs['out'] ?? [] as $k) {
                    $qtyOut[$k] = $flows[$k][2];
                }
                foreach ($dirs['in'] ?? [] as $k) {
                    $qtyIn[$k] = $flows[$k][2];
                }

                continue;
            }
            $x      = $sp[$node];
            $absV   = abs($x['v']);
            $absQ   = abs($x['q']);
            $major  = $x['v'] > 0 ? 'in' : 'out';
            $minor  = 'in' === $major ? 'out' : 'in';
            $minorQ = 0;
            $minorV = 0;
            foreach ($dirs[$minor] ?? [] as $k) {
                $q = Util::share($absQ, $flows[$k][2], $absV);
                'in' === $minor ? $qtyIn[$k] = $q : $qtyOut[$k] = $q;
                $minorQ += $q;
                $minorV += $flows[$k][2];
            }
            $keys   = $dirs[$major] ?? [];
            $total  = array_sum(array_map(static fn ($k) => $flows[$k][2], $keys));
            // net quantity of this split; less than the split if a part was dropped as a
            // transfer of the account to itself
            $netV   = $total - $minorV;
            $netQ   = $netV === $absV ? $absQ : Util::share($absQ, $netV, $absV);
            $target = $netQ + $minorQ;
            $done   = 0;
            foreach ($keys as $pos => $k) {
                $q = $pos === count($keys) - 1 ? $target - $done : Util::share($target, $flows[$k][2], $total);
                'in' === $major ? $qtyIn[$k] = $q : $qtyOut[$k] = $q;
                $done += $q;
            }
        }

        // ---- journals
        $desc       = Util::oneLine($t->description);
        if ('' === $desc) {
            foreach ($t->splits as $s) {
                if ('' !== trim($s->memo)) {
                    $desc = Util::oneLine($s->memo);

                    break;
                }
            }
        }
        if ('' === $desc) {
            $desc = '(ohne Beschreibung)';
        }
        $desc        = Util::truncate($desc, 1000);
        $journals    = [];
        $clearingCur = $t->currency;
        foreach ($flows as $k => [$from, $to, $f]) {
            $j       = new Journal();
            $fromBs  = $isBsNode($from);
            $toBs    = $isBsNode($to);
            $cur     = static fn ($i) => 'C' === $i ? [$clearingCur, $txDec] : [$sp[$i]['qcur'], $sp[$i]['qdec']];
            [$fc, $fd] = $cur($from);
            [$tc, $td] = $cur($to);
            $qf      = $qtyOut[$k];
            $qt      = $qtyIn[$k];
            if ($fromBs && $toBs) {
                $fromLiab = 'C' !== $from && $sp[$from]['liab'];
                $toLiab   = 'C' !== $to && $sp[$to]['liab'];
                $j->type  = $fromLiab === $toLiab ? 'transfer' : ($fromLiab ? 'deposit' : 'withdrawal');
                $j->src   = 'C' === $from ? ['clearing', null] : ['bs', $sp[$from]['s']->account];
                $j->dst   = 'C' === $to ? ['clearing', null] : ['bs', $sp[$to]['s']->account];
                [$j->amount, $j->currency, $j->decimals] = [$qf, $fc, $fd];
                if ($tc !== $fc) {
                    [$j->foreignAmount, $j->foreignCurrency, $j->foreignDecimals] = [$qt, $tc, $td];
                }
            } elseif ($fromBs) {
                $j->type     = 'withdrawal';
                $j->src      = 'C' === $from ? ['clearing', null] : ['bs', $sp[$from]['s']->account];
                $j->category = (string) $sp[$to]['map']['name'];
                $j->dst      = ['payee', $this->payees->add($t, 'expense', $j->category, $this->accountPaths($t))];
                [$j->amount, $j->currency, $j->decimals] = [$qf, $fc, $fd];
                if ($tc !== $fc) {
                    [$j->foreignAmount, $j->foreignCurrency, $j->foreignDecimals] = [$qt, $tc, $td];
                }
                $j->plCurrency = $tc;
                $j->plQty      = $qt;
            } else {
                $j->type     = 'deposit';
                $j->category = (string) $sp[$from]['map']['name'];
                $j->src      = ['payee', $this->payees->add($t, 'revenue', $j->category, $this->accountPaths($t))];
                $j->dst      = 'C' === $to ? ['clearing', null] : ['bs', $sp[$to]['s']->account];
                [$j->amount, $j->currency, $j->decimals] = [$qt, $tc, $td];
                if ($fc !== $tc) {
                    [$j->foreignAmount, $j->foreignCurrency, $j->foreignDecimals] = [$qf, $fc, $fd];
                }
                $j->plCurrency = $fc;
                $j->plQty      = -$qf;
            }
            if ($j->amount <= 0) {
                $plan->warnings[] = sprintf('a part of %s %s rounds to 0 in %s and was dropped', Util::minorToDec($f, $txDec), $t->currency, $j->currency);

                continue;
            }
            $j->splits = array_values(array_filter([$from, $to], static fn ($i) => 'C' !== $i));
            $j->self   = isset($selfFlows[$k]);
            $recon     = true;
            foreach ($j->splits as $i) {
                $state = $sp[$i]['s']->state;
                if ('bs' === $sp[$i]['kind'] && ('' === $state || !str_contains($this->states, $state))) {
                    $recon = false;
                }
            }
            $j->reconciled = $recon && '' !== $this->states;
            $journals[]    = $j;
        }

        // ---- groups: all withdrawals together (the multisource fork allows different source
        // accounts), all deposits together, transfers per account pair
        $groups = [];
        foreach ($journals as $j) {
            $key = $j->self ? 's|'.$j->type.'|'.implode(':', $j->src).'|'.implode(':', $j->dst) : match ($j->type) {
                'withdrawal' => 'w'.($this->multisource ? '' : '|'.implode(':', $j->src)),
                'deposit'    => 'd'.($this->multisource ? '' : '|'.implode(':', $j->dst)),
                default      => 't|'.implode(':', $j->src).'|'.implode(':', $j->dst),
            };
            $groups[$key] ??= new Group($j->type);
            $groups[$key]->self       = $j->self;
            $groups[$key]->journals[] = $j;
        }

        // ---- descriptions, memos and notes
        $usedMemo = [];
        foreach ($groups as $g) {
            $multi = count($g->journals) > 1;
            if ($multi) {
                $g->title = $desc;
            }
            foreach ($g->journals as $j) {
                $j->description = $desc;
                if ($multi) {
                    foreach ($j->splits as $i) {
                        if ('pl' === $sp[$i]['kind'] && '' !== Util::oneLine($sp[$i]['s']->memo)) {
                            $j->description = Util::truncate(Util::oneLine($sp[$i]['s']->memo), 1000);
                            $usedMemo[$i]   = true;
                        }
                    }
                }
            }
        }
        $lines = [];
        $first = [];
        foreach ($groups as $g) {
            foreach ($g->journals as $j) {
                foreach ($j->splits as $i) {
                    $first[$i] ??= $j;
                }
            }
        }
        // memo lines: "GnuCash-Memo [<account> <amount>]: memo", categories as "[Kategorie: <name> <amount>]"
        $nameOf = function (int $i) use ($t): string {
            $map  = $this->mapping($t->splits[$i]->account);
            $name = (string) ($map['name'] ?? $this->book->account($t->splits[$i]->account)->path);

            return 'category' === ($map['as'] ?? '') ? 'Kategorie: '.$name : $name;
        };
        $firstJournal = reset($groups)->journals[0];
        foreach ($sp as $i => $x) {
            $memo = Util::oneLine($x['s']->memo);
            if ('' === $memo || isset($usedMemo[$i])) {
                continue;
            }
            // splits that ended up without journal (moved to their own account) keep their memo too
            $spl = spl_object_id($first[$i] ?? $firstJournal);
            $lines[$spl][] = sprintf('GnuCash-Memo [%s %s]: %s', $nameOf($i), Util::minorToDec($x['q'], $x['qdec']), $memo);
        }
        foreach ($zeroMemos as $i) {
            $acc = $this->book->account($t->splits[$i]->account);
            $lines[spl_object_id($firstJournal)][] = sprintf('GnuCash-Memo [%s %s]: %s', $nameOf($i), Util::minorToDec(0, Util::decimalsForFraction($acc->scu)), Util::oneLine($t->splits[$i]->memo));
        }
        foreach ($groups as $g) {
            foreach ($g->journals as $j) {
                $parts = [];
                if ($j === $firstJournal && '' !== $t->notes) {
                    $parts[] = $t->notes;
                }
                if (isset($lines[spl_object_id($j)])) {
                    $parts[] = implode("\n", $lines[spl_object_id($j)]);
                }
                $j->notes = Util::truncate(implode("\n\n", $parts), 32000);
            }
        }
        $plan->groups = array_values($groups);

        return $plan;
    }
}

/** Checks that a decomposition moves exactly the GnuCash amounts per account and category. */
final class Verifier
{
    public int $checked = 0;

    /** @var list<string> */
    public array $errors = [];

    public function __construct(private Book $book, private Decomposer $dec)
    {
    }

    public function check(TxPlan $plan): void
    {
        if (null !== $plan->skip) {
            return;
        }
        ++$this->checked;
        $t        = $plan->tx;
        $expected = [];
        $actual   = [];
        $txFrac = Util::fractionForDecimals(Decomposer::decimals($t->currency));
        foreach ($t->splits as $idx => $s) {
            $acc = $this->book->account($s->account);
            $map = $this->dec->mapping($s->account);
            if (('TRADING' === $acc->type && 'ignore' === $map['as']) || in_array($idx, $plan->dropped, true)) {
                continue;
            }
            $isBs  = in_array($map['as'], ['asset', 'liability'], true);
            $ffCur = $isBs ? (string) ($map['currency'] ?? $acc->cmdtyId) : $acc->cmdtyId;
            if ($acc->isCurrency() && $ffCur === $acc->cmdtyId) {
                $q   = Util::fracToMinor($s->quantity, $acc->scu);
                $cur = $acc->cmdtyId;
            } else {
                $q   = Util::fracToMinor($s->value, $txFrac);
                $cur = $isBs ? $ffCur : $t->currency;
            }
            if (isset($plan->adjusted[$idx])) {
                $q = $plan->adjusted[$idx];
            }
            if ($isBs) {
                $key            = 'acct:'.$s->account.':'.$cur;
                $expected[$key] = ($expected[$key] ?? 0) + $q;
            } elseif ('category' === $map['as']) {
                $key            = 'cat:'.$map['name'].':'.$cur;
                $expected[$key] = ($expected[$key] ?? 0) + $q;
            }
        }
        foreach ($plan->groups as $g) {
            foreach ($g->journals as $j) {
                $srcKey = 'bs' === $j->src[0] ? 'acct:'.$j->src[1] : ('clearing' === $j->src[0] ? 'clearing' : null);
                $dstKey = 'bs' === $j->dst[0] ? 'acct:'.$j->dst[1] : ('clearing' === $j->dst[0] ? 'clearing' : null);
                if ('payee' === $j->src[0]) {        // deposit from income/refund
                    $k          = 'cat:'.$j->category.':'.$j->plCurrency;
                    $actual[$k] = ($actual[$k] ?? 0) + $j->plQty;
                    $k2         = $dstKey.':'.$j->currency;
                    $actual[$k2] = ($actual[$k2] ?? 0) + $j->amount;

                    continue;
                }
                if ('payee' === $j->dst[0]) {        // withdrawal to expense/income reversal
                    $k          = 'cat:'.$j->category.':'.$j->plCurrency;
                    $actual[$k] = ($actual[$k] ?? 0) + $j->plQty;
                    $k2         = $srcKey.':'.$j->currency;
                    $actual[$k2] = ($actual[$k2] ?? 0) - $j->amount;

                    continue;
                }
                $k1          = $srcKey.':'.$j->currency;
                $actual[$k1] = ($actual[$k1] ?? 0) - $j->amount;
                $inCur       = $j->foreignCurrency ?? $j->currency;
                $inAmt       = $j->foreignAmount ?? $j->amount;
                $k2          = $dstKey.':'.$inCur;
                $actual[$k2] = ($actual[$k2] ?? 0) + $inAmt;
            }
        }
        foreach ($actual as $k => $v) {
            if (str_starts_with($k, 'clearing') && 0 === $v) {
                unset($actual[$k]);
            }
        }
        $expected = array_filter($expected, static fn ($v) => 0 !== $v);
        $actual   = array_filter($actual, static fn ($v) => 0 !== $v);
        ksort($expected);
        ksort($actual);
        if ($expected !== $actual) {
            $this->errors[] = sprintf('%s %s "%s": expected %s, got %s', $t->date, $t->guid, $t->description, json_encode($expected, JSON_UNESCAPED_UNICODE), json_encode($actual, JSON_UNESCAPED_UNICODE));
        }
    }
}

// =====================================================================================
// Pipeline shared by "plan" and "import": book -> config -> decomposition -> payees
// =====================================================================================

final class Pipeline
{
    public Book $book;
    public ImportConfig $config;
    public PayeeRules $rules;
    public PayeeResolver $payees;
    public Decomposer $dec;
    public Verifier $verifier;

    /** @var list<TxPlan> transactions to import (within --from/--to) */
    public array $plans = [];

    /** @var list<TxPlan> every transaction of the book: the payee reports always cover the whole book */
    public array $allPlans = [];

    public string $configFile;
    public string $rulesFile;
    public string $base;

    public function __construct(public string $bookFile, Args $args, public \DateTimeZone $tz)
    {
        $this->base       = (string) preg_replace('/(\.gnucash|\.xml|\.gz|\.sqlite|\.sqlite3|\.db)+$/i', '', $bookFile);
        $this->configFile = $args->get('config') ?? $this->base.'.import.json';
        $this->rulesFile  = $args->get('rules') ?? $this->base.'.payee-rules.txt';
    }

    public function run(?string $from = null, ?string $to = null, bool $multisource = true): void
    {
        Out::step(sprintf('Reading %s', $this->bookFile));
        $this->book   = BookReader::read($this->bookFile, $this->tz);
        $this->config = ImportConfig::load($this->configFile);
        $this->config->syncWithBook($this->book);
        $this->rules  = PayeeRules::load($this->rulesFile);
        $this->payees = new PayeeResolver($this->rules, $this->config);
        $this->dec    = new Decomposer($this->book, $this->config, $this->payees, $multisource && (bool) $this->config->opt('multisource'));
        $this->verifier = new Verifier($this->book, $this->dec);
        foreach ($this->book->transactions as $t) {
            // every transaction registers its counterparties, also outside --from/--to: the
            // names (spelling, prefix merge, min count) must not change when importing in slices
            $plan              = $this->dec->decompose($t);
            $this->allPlans[] = $plan;
            if ((null !== $from && $t->date < $from) || (null !== $to && $t->date > $to)) {
                continue;
            }
            $this->verifier->check($plan);
            $this->plans[] = $plan;
        }
        $this->payees->resolve();
        $taken = [];
        foreach ($this->config->accounts as $m) {
            if (isset($m['iban'])) {
                $taken[Util::normalizeIban((string) $m['iban'])] = true;
            }
        }
        $this->payees->dropIbans($taken);
    }

    /** @return array<string, mixed> statistics for the summary */
    public function stats(): array
    {
        $s = ['tx' => 0, 'skipped' => [], 'groups' => ['withdrawal' => 0, 'deposit' => 0, 'transfer' => 0], 'journals' => 0,
            'multisource' => 0, 'clearing' => 0, 'selfflow' => 0, 'selfflows' => [], 'warnings' => [], 'dates' => [null, null]];
        foreach ($this->plans as $p) {
            if (null !== $p->skip) {
                $reason                  = (string) preg_replace('/"[^"]*"/', '"…"', $p->skip);
                $s['skipped'][$reason]   = ($s['skipped'][$reason] ?? 0) + 1;
                $s['skippedTx'][$reason][] = $this->txLine($p->tx);

                continue;
            }
            ++$s['tx'];
            $s['dates'][0] ??= $p->tx->date;
            $s['dates'][1]   = $p->tx->date;
            foreach ($p->groups as $g) {
                ++$s['groups'][$g->type];
                $s['journals'] += count($g->journals);
                $srcs = [];
                $dsts = [];
                foreach ($g->journals as $j) {
                    $srcs[implode(':', $j->src)] = true;
                    $dsts[implode(':', $j->dst)] = true;
                }
                if (('withdrawal' === $g->type && count($srcs) > 1) || ('deposit' === $g->type && count($dsts) > 1)) {
                    ++$s['multisource'];
                }
            }
            if ([] !== $p->selfNotes) {
                ++$s['selfflow'];
                foreach ($p->selfNotes as $n) {
                    $s['selfflows'][] = sprintf('%s %s: %s', $p->tx->date, Util::truncate($p->tx->description, 40), $n);
                }
            } elseif ($p->usesClearing) {
                ++$s['clearing'];
            }
            foreach ($p->warnings as $w) {
                $k                   = (string) preg_replace(['/"[^"]*"/', '/ \([^()]*\)(?= - )/', '/-?\d+(\.\d+)?/'], ['"…"', ' (…)', 'N'], $w);
                $s['warnings'][$k][] = sprintf('%s %s: %s', $p->tx->date, Util::truncate($p->tx->description, 40), $w);
            }
        }

        return $s;
    }

    /** "date description: account amount, ..." for lists of skipped transactions */
    private function txLine(GTransaction $t): string
    {
        $parts = [];
        foreach (array_slice($t->splits, 0, 6) as $sp) {
            [$num, $den] = array_map('intval', explode('/', $sp->value.'/1'));
            $acc         = $this->book->accounts[$sp->account] ?? null;
            $parts[]     = sprintf('%s %+.2f', null === $acc ? '?' : $acc->path, 0 === $den ? 0 : $num / $den);
        }
        if (count($t->splits) > 6) {
            $parts[] = sprintf('… (%d splits)', count($t->splits));
        }

        return sprintf('%s %s: %s', $t->date, Util::truncate(Util::oneLine($t->description), 60), implode(', ', $parts));
    }

    public function writeRulesTemplate(): bool
    {
        if (is_file($this->rulesFile)) {
            return false;
        }
        file_put_contents($this->rulesFile, PayeeRules::TEMPLATE);

        return true;
    }

    /** Transactions per booking text in <book>.payee-details.json. */
    private const DETAIL_TX = 12;

    /** @return array{date:string, desc:string, num:string, notes:string, cur:string, splits:list<array{account:string, amount:string, memo:string}>} */
    private function detailOf(GTransaction $t): array
    {
        $dec    = Decomposer::decimals($t->currency);
        $splits = [];
        foreach ($t->splits as $sp) {
            $splits[] = [
                'account' => $this->book->account($sp->account)->path,
                'amount'  => Util::minorToDec(Util::fracToMinor($sp->value, Util::fractionForDecimals($dec)), $dec),
                'memo'    => Util::collapse($sp->memo),
            ];
        }

        return ['date' => $t->date, 'desc' => Util::collapse($t->description), 'num' => $t->num, 'notes' => Util::collapse($t->notes), 'cur' => $t->currency,
            'ibans' => PayeeResolver::ibansOf($t), 'splits' => $splits];
    }

    /** Payee summary (one row per counterparty) and payee map (one row per booking text). */
    public function writePayeeReports(): array
    {
        $amounts = [];
        foreach ($this->allPlans as $p) {
            foreach ($p->groups as $g) {
                foreach ($g->journals as $j) {
                    foreach (['src', 'dst'] as $side) {
                        if ('payee' === $j->{$side}[0]) {
                            $key                                    = $j->{$side}[1];
                            $amounts[$key][$j->currency]            = ($amounts[$key][$j->currency] ?? 0) + $j->amount;
                            $amounts[$key]['#dec'][$j->currency]    = $j->decimals;
                        }
                    }
                }
            }
        }
        $summary = [];
        $map     = [];
        $details = [];
        foreach ($this->payees->usages() as $key => $u) {
            $r    = $this->payees->get($key);
            /** @var GTransaction $t */
            $t    = $u['tx'];
            $sk   = $r['name'].'|'.$u['side'];
            $summary[$sk] ??= ['name' => $r['name'], 'side' => $u['side'], 'source' => [], 'tx' => [], 'sum' => [], 'iban' => $r['iban'], 'cats' => [], 'ex' => [], 'first' => $t->date, 'last' => $t->date];
            $e             = &$summary[$sk];
            $e['source'][$r['source']] = true;   // with rule line ("rule:31"): web.php links to it
            $e['tx'][$t->guid] = true;
            $e['cats'][$u['category']] = ($e['cats'][$u['category']] ?? 0) + 1;
            $d                 = Util::collapse($t->description);
            $e['ex'][$d]       = ($e['ex'][$d] ?? 0) + 1;
            $e['first']        = min($e['first'], $t->date);
            $e['last']         = max($e['last'], $t->date);
            foreach ($amounts[$key] ?? [] as $cur => $amt) {
                if ('#dec' !== $cur) {
                    $e['sum'][$cur] = ($e['sum'][$cur] ?? 0) + $amt;
                    $e['dec'][$cur] = $amounts[$key]['#dec'][$cur];
                }
            }
            unset($e);
            // one row per booking text, side and Firefly counterparty: bookings with the same text
            // that get another counterparty (e.g. through a konto: rule) stay apart
            $mk   = substr(md5($d."\x1F".$u['side']."\x1F".$r['name']), 0, 16);
            $map[$mk] ??= ['desc' => $d, 'side' => $u['side'], 'tx' => [], 'payee' => $r['name'], 'source' => $r['source'], 'iban' => implode(' ', $u['ibans'] ?? []), 'cats' => []];
            $map[$mk]['tx'][$t->guid]           = true;
            $map[$mk]['cats'][$u['category']] = true;
            $details[$mk] ??= ['payee' => $r['name'], 'side' => $u['side'], 'text' => $d, 'tx' => [], 'find' => []];
            if (!isset($details[$mk]['tx'][$t->guid])) {
                $details[$mk]['tx'][$t->guid] = $this->detailOf($t);
                foreach ($details[$mk]['tx'][$t->guid]['splits'] as $sp) {
                    $details[$mk]['find'][$sp['account']] = true;
                    if ('' !== $sp['memo']) {
                        $details[$mk]['find'][$sp['memo']] = true;
                    }
                }
                foreach ([$t->num, $t->notes] as $x) {
                    if ('' !== trim($x)) {
                        $details[$mk]['find'][Util::collapse($x)] = true;
                    }
                }
            }
        }
        uasort($summary, static fn ($a, $b) => [count($b['tx']), $a['name']] <=> [count($a['tx']), $b['name']]);
        $sideName = ['expense' => 'Ausgabenkonto', 'revenue' => 'Einnahmenkonto'];
        $out      = "\xEF\xBB\xBF".Util::csvLine(['payee', 'firefly_type', 'source', 'transactions', 'amount', 'iban', 'first', 'last', 'categories', 'booking_texts']);
        foreach ($summary as $e) {
            arsort($e['cats']);
            arsort($e['ex']);
            $sum = [];
            foreach ($e['sum'] as $cur => $amt) {
                $sum[] = Util::minorToDec($amt, $e['dec'][$cur]).' '.$cur;
            }
            $out .= Util::csvLine([
                $e['name'], $sideName[$e['side']], implode(',', array_keys($e['source'])), count($e['tx']), implode(' + ', $sum), (string) $e['iban'],
                $e['first'], $e['last'], implode(' | ', array_slice(array_keys($e['cats']), 0, 5)),
                implode(' | ', array_map(static fn ($d, $n) => $n > 1 ? "{$d} ({$n}x)" : $d, array_slice(array_keys($e['ex']), 0, 5), array_slice(array_values($e['ex']), 0, 5))),
            ]);
        }
        $summaryFile = $this->base.'.payees.csv';
        file_put_contents($summaryFile, $out);
        uasort($map, static fn ($a, $b) => [count($b['tx']), $a['desc']] <=> [count($a['tx']), $b['desc']]);
        $out = "\xEF\xBB\xBF".Util::csvLine(['booking_text', 'firefly_type', 'transactions', 'payee', 'source', 'iban', 'categories', 'key']);
        foreach ($map as $mk => $e) {
            $out .= Util::csvLine([$e['desc'], $sideName[$e['side']], count($e['tx']), $e['payee'], $e['source'], $e['iban'], implode(' | ', array_keys($e['cats'])), $mk]);
        }
        $mapFile = $this->base.'.payee-map.csv';
        file_put_contents($mapFile, $out);
        // details per booking text for web.php (tooltip and search): newest transactions with
        // all their splits, and the memos/accounts/numbers/notes of all of them as search text
        $json = [];
        foreach ($details as $mk => $d) {
            $tx = array_values($d['tx']);
            usort($tx, static fn ($a, $b) => $b['date'] <=> $a['date']);
            $json[$mk] = ['payee' => $d['payee'], 'side' => $d['side'], 'text' => $d['text'], 'n' => count($tx), 'tx' => array_slice($tx, 0, self::DETAIL_TX),
                'acc' => array_values(array_unique(array_merge(...array_map(static fn ($x) => array_column($x['splits'], 'account'), $tx)))),
                'memo' => array_values(array_unique(array_filter(array_merge(...array_map(static fn ($x) => array_column($x['splits'], 'memo'), $tx)), static fn ($m) => '' !== $m))),
                'ibans' => array_values(array_unique(array_merge(...array_map(static fn ($x) => $x['ibans'], $tx)))),
                'find' => Util::truncate(implode(' | ', array_keys($d['find'])), 20000)];
        }
        $detailFile = $this->base.'.payee-details.json';
        file_put_contents($detailFile, json_encode($json, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE));
        $counts = ['expense' => 0, 'revenue' => 0];
        foreach ($summary as $e) {
            ++$counts[$e['side']];
        }

        return ['files' => [$summaryFile, $mapFile, $detailFile], 'counts' => $counts, 'top' => array_slice($summary, 0, 15)];
    }

    /**
     * Suggest merge rules: automatic counterparties whose name starts with the same word
     * (REWE Musterstadt, Rewe - Musterstadt, REWE Kartenwelt ... -> REWE).
     */
    public function writeSuggestions(): array
    {
        $stop = array_flip(['lastschrift', 'folgelastschrift', 'basislastschrift', 'erstlastschrift', 'ueberweisung', 'uberweisung', 'ueberweisungsgutschr',
            'ueberweisungsauftrag', 'gutschrift', 'gutschr', 'kartenzahlung', 'debitkartenzahlung', 'dauerauftrag', 'dauerauftragsbelast', 'abbuchung', 'einzahlung',
            'auszahlung', 'barauszahlung', 'bargeld', 'geld', 'der', 'die', 'das', 'und', 'von', 'vom', 'fuer', 'mit', 'bei', 'aus', 'zum', 'zur', 'the', 'and', 'for',
            'www', 'http', 'https', 'com', 'net', 'org', 'sepa', 'eref', 'mref', 'svwz', 'kref', 'iban', 'konto', 'kto', 'datum', 'betrag', 'rechnung', 'beitrag',
            'zahlung', 'einkauf', 'kauf', 'bestellung', 'abo', 'visa', 'mastercard', 'girocard', 'entgelt', 'zinsen', 'steuer', 'steuern', 'deutsche', 'deutschland',
            'gmbh', 'herr', 'frau', 'familie', 'online', 'shop', 'store', 'markt', 'mein', 'meine', 'neue', 'neuer', 'kleines', 'kleine', 'grosse', 'grosses', 'diverse']);
        // groups by the first significant word ("REWE ...") and by two words several names
        // share ("Bäcker Erika Muster", "Bäckerei Erika Muster Bäckerwagen" -> "Erika Muster")
        $groups   = [];
        $fallback = (string) $this->config->opt('payee_fallback');
        foreach ($this->payees->usages() as $key => $u) {
            $r = $this->payees->get($key);
            if ('auto' !== $r['source'] && 'fallback' !== $r['source']) {
                continue;
            }
            $words = [];
            foreach (preg_split('/[^\p{L}]+/u', (string) ($u['cand'] ?? '')) ?: [] as $w) {
                if (mb_strlen($w) >= 3 && !isset($stop[Util::key($w)])) {
                    $words[] = $w;
                }
            }
            if ([] === $words) {
                continue;
            }
            $keys = ['1:'.Util::key($words[0]) => [$words[0]]];
            for ($i = 0, $c = count($words) - 1; $i < $c; ++$i) {
                $keys['2:'.Util::key($words[$i]).' '.Util::key($words[$i + 1])] = [$words[$i], $words[$i + 1]];
            }
            foreach ($keys as $k => $spell) {
                $sp                                = implode(' ', $spell);
                $groups[$k]['spell'][$sp]          = ($groups[$k]['spell'][$sp] ?? 0) + 1;
                $groups[$k]['words']               = count($spell);
                $groups[$k]['names'][$r['name']]   = ($groups[$k]['names'][$r['name']] ?? 0) + 1;
                $groups[$k]['tx'][$u['tx']->guid] = true;
            }
        }
        $rows = [];
        $seen = [];
        uasort($groups, static fn ($a, $b) => [count($b['tx']), $a['words']] <=> [count($a['tx']), $b['words']]);
        foreach ($groups as $g) {
            $real = array_filter($g['names'], static fn ($n) => $n !== $fallback, ARRAY_FILTER_USE_KEY);
            if (count($g['names']) < 2 || count($g['tx']) < 3 || [] === $real) {
                continue;
            }
            $set = array_keys($g['names']);
            sort($set);
            $sig = implode("\n", $set);
            if (isset($seen[$sig])) {
                continue;   // same counterparties as a suggestion already made
            }
            $seen[$sig] = true;
            arsort($g['spell']);
            arsort($real);
            $spell  = (string) array_key_first($g['spell']);
            $target = 1 === $g['words'] ? $spell : (string) array_key_first($real);
            $parts  = array_map(static fn ($w) => preg_quote($w, '/'), explode(' ', $spell));
            $ascii  = 1 === preg_match('/^[A-Za-z ]+$/', $spell);
            $body   = implode('\\s+', $parts);
            $regex  = $ascii ? sprintf('/\\b%s\\b/i', $body) : sprintf('/(?<!\\p{L})%s(?!\\p{L})/iu', $body);
            arsort($g['names']);
            $rows[] = [count($g['tx']), sprintf('%-40s => %s', $regex, $target), implode(', ', array_slice(array_keys($g['names']), 0, 6))];
        }
        usort($rows, static fn ($a, $b) => $b[0] <=> $a[0]);
        $out = "# Suggested merge rules (generated by plan, overwritten on every run).\n"
            ."# Copy the lines you like into the payee rules file and adjust the counterparty name.\n\n";
        foreach (array_slice($rows, 0, 200) as [$n, $rule, $names]) {
            $out .= sprintf("# %d transactions: %s\n%s\n\n", $n, Util::truncate($names, 150), $rule);
        }
        $file = $this->base.'.payee-suggestions.txt';
        file_put_contents($file, $out);

        return ['file' => $file, 'count' => count($rows)];
    }

    /**
     * The summary of printSummary() as data (plan --summary-json, used by web.php).
     *
     * @param array{counts: array<string, int>, top: list<array<string, mixed>>} $payeeReport
     * @param array{file: string, count: int}                                    $sugg
     *
     * @return array<string, mixed>
     */
    public function summaryData(array $payeeReport, array $sugg): array
    {
        $b    = $this->book;
        $st   = $this->stats();
        $used = ['asset' => 0, 'liability' => 0, 'category' => 0];
        foreach ($this->config->accounts as $m) {
            if (isset($used[$m['as']]) && ($m['splits'] ?? 0) > 0) {
                ++$used[$m['as']];
            }
        }
        $warnings = [];
        foreach ($st['warnings'] as $examples) {
            // all occurrences for web.php (expandable list), capped to keep summary.json small
            $warnings[] = ['count' => count($examples), 'example' => $examples[0], 'all' => array_slice($examples, 0, 1000)];
        }

        return [
            'version'          => VERSION,
            'book'             => ['accounts' => count($b->accounts), 'transactions' => count($b->transactions), 'currency' => $b->defaultCurrency,
                'first' => $b->transactions[0]->date ?? null, 'last' => [] === $b->transactions ? null : end($b->transactions)->date],
            'mapping'          => $used,
            'importable'       => ['transactions' => $st['tx'], 'journals' => $st['journals'], 'multisource' => $st['multisource'], 'clearing' => $st['clearing'], 'selfflow' => $st['selfflow']] + $st['groups'],
            'opening_balances' => count($this->dec->openingBalance),
            'skipped'          => $st['skipped'],
            'skipped_tx'       => array_map(static fn ($l) => array_slice($l, 0, 1000), $st['skippedTx'] ?? []),
            'selfflows'        => array_slice($st['selfflows'], 0, 1000),
            'counterparties'   => ['expense' => $payeeReport['counts']['expense'] ?? 0, 'revenue' => $payeeReport['counts']['revenue'] ?? 0, 'rules' => count($this->rules->rules),
                'suggestions' => $sugg['count'], 'top' => array_values(array_map(static fn ($e) => ['name' => $e['name'], 'side' => $e['side'], 'transactions' => count($e['tx'])], $payeeReport['top']))],
            'warnings'         => $warnings,
            'unused_rules'     => $this->payees->unusedRules(),
            'not_imported'     => $b->otherObjects,
            'selfcheck'        => ['ok' => [] === $this->verifier->errors, 'checked' => $this->verifier->checked, 'errors' => array_slice($this->verifier->errors, 0, 20)],
            'config_messages'  => $this->config->messages,
        ];
    }

    public function printSummary(array $payeeReport): void
    {
        $b  = $this->book;
        $st = $this->stats();
        $kinds = ['asset' => 0, 'liability' => 0, 'category' => 0, 'ignore' => 0, 'skip' => 0];
        $used  = ['asset' => 0, 'liability' => 0, 'category' => 0];
        foreach ($this->config->accounts as $m) {
            ++$kinds[$m['as']];
            if (isset($used[$m['as']]) && ($m['splits'] ?? 0) > 0) {
                ++$used[$m['as']];
            }
        }
        $firstDate = $b->transactions[0]->date ?? '-';
        $lastDate  = [] === $b->transactions ? '-' : end($b->transactions)->date;
        Out::info(sprintf('GnuCash book:     %d accounts, %d transactions (%s .. %s), book currency %s', count($b->accounts), count($b->transactions), $firstDate, $lastDate, $b->defaultCurrency));
        Out::info(sprintf('Mapping:          %d asset + %d liability accounts, %d categories with transactions (config: %s)', $used['asset'], $used['liability'], $used['category'], $this->configFile));
        Out::info(sprintf('Importable:       %d transactions -> %d withdrawals, %d deposits, %d transfers (%d journals)', $st['tx'], $st['groups']['withdrawal'], $st['groups']['deposit'], $st['groups']['transfer'], $st['journals']));
        if ($st['multisource'] > 0) {
            Out::info(sprintf('                  %d split transactions use several source/destination accounts (needs the multisource fork)', $st['multisource']));
        }
        if ($st['clearing'] > 0) {
            Out::info(sprintf('                  %d transactions without asset account are booked via "%s"', $st['clearing'], $this->config->opt('clearing_account')));
        }
        if ($st['selfflow'] > 0) {
            Out::info(sprintf('                  %d transactions move money from an account to itself: booked as two transactions via "%s", tagged "%s" and linked',
                $st['selfflow'], $this->config->opt('clearing_account'), $this->config->opt('selfflow_tag')));
            foreach (array_slice($st['selfflows'], 0, Out::$verbose ? 1000 : 3) as $n) {
                Out::info('                    '.$n);
            }
        }
        if ([] !== $this->dec->openingBalance) {
            Out::info(sprintf('Opening balances: %d accounts', count($this->dec->openingBalance)));
        }
        foreach ($st['skipped'] as $reason => $n) {
            Out::info(sprintf('Skipped:          %5d  %s', $n, $reason));
            if (Out::$verbose) {
                foreach ($st['skippedTx'][$reason] ?? [] as $l) {
                    Out::info('                    '.$l);
                }
            }
        }
        Out::info(sprintf('Counterparties:   %d expense accounts, %d revenue accounts (rules: %s, %d rules)', $payeeReport['counts']['expense'], $payeeReport['counts']['revenue'], $this->rulesFile, count($this->rules->rules)));
        foreach ($this->payees->unusedRules() as $u) {
            $why = 0 === $u['matches']
                ? ($u['auto'] ? 'matches no automatic counterparty name' : 'matches no booking')
                : sprintf('all %d bookings it matches get their name from earlier rules (%s)', $u['matches'], implode(', ', array_map(static fn ($l, $n) => sprintf('line %d: %d', $l, $n), array_keys($u['by']), $u['by'])));
            Out::warn(sprintf('unused rule, line %d: %s - %s', $u['line'], Util::truncate($u['src'], 80), $why));
        }
        $top = array_map(static fn ($e) => sprintf('%s (%d)', $e['name'], count($e['tx'])), $payeeReport['top']);
        Out::info('                  top: '.implode(', ', $top));
        foreach ($st['warnings'] as $examples) {
            Out::warn(sprintf('%dx %s', count($examples), $examples[0]));
        }
        if ([] !== $b->otherObjects) {
            $list = [];
            foreach ($b->otherObjects as $k => $n) {
                $list[] = "{$n} {$k}";
            }
            Out::info('Not imported:     '.implode(', ', $list).' (no Firefly equivalent)');
        }
        if ([] === $this->verifier->errors) {
            Out::info(sprintf('Self-check:       OK - every account and category moves exactly the GnuCash amounts (%d transactions)', $this->verifier->checked));
        } else {
            foreach (array_slice($this->verifier->errors, 0, 10) as $e) {
                Out::error('self-check: '.$e);
            }

            throw new UserError(sprintf('Self-check failed for %d transactions - please report this as a bug.', count($this->verifier->errors)));
        }
    }
}

// =====================================================================================
// Firefly III REST API client
// =====================================================================================

final class ApiError extends \RuntimeException
{
    /** @param array<string, list<string>> $errors */
    public function __construct(string $message, public int $status = 0, public array $errors = [], public string $body = '')
    {
        parent::__construct($message);
    }

    public function details(): string
    {
        $parts = [];
        foreach ($this->errors as $field => $msgs) {
            $parts[] = $field.': '.implode(' ', (array) $msgs);
        }

        return [] === $parts ? $this->getMessage() : $this->getMessage().' ('.implode('; ', $parts).')';
    }
}

final class FireflyClient
{
    public int $requests = 0;
    public float $seconds = 0.0;

    /** @var \CurlHandle */
    private $ch;

    public function __construct(private string $base, private string $token, private ?string $cacert = null, private int $timeout = 120)
    {
        $this->base = rtrim($base, '/');
        $this->ch   = curl_init();
    }

    public static function fromArgs(Args $args): self
    {
        $url   = $args->get('url') ?? (getenv('FIREFLY_URL') ?: null);
        $token = $args->get('token') ?? null;
        $file  = $args->get('token-file');
        if (null !== $file) {
            if (!is_readable($file)) {
                throw new UserError(sprintf('Cannot read token file "%s"', $file));
            }
            $token = trim((string) file_get_contents($file));
        }
        $token ??= getenv('FIREFLY_TOKEN') ?: null;
        if (null === $url || '' === $url) {
            throw new UserError('Firefly URL missing: --url=https://firefly.example.org or FIREFLY_URL');
        }
        if (null === $token || '' === $token) {
            throw new UserError('Personal Access Token missing: --token-file=FILE or FIREFLY_TOKEN (Firefly: Options > Profile > OAuth > Personal Access Tokens)');
        }
        $url = (string) preg_replace('~/api/v1/?$~', '', rtrim($url, '/'));

        return new self($url, $token, $args->get('cacert'), (int) ($args->get('timeout') ?? 120));
    }

    public function get(string $path, array $query = []): array
    {
        return $this->request('GET', $path, $query);
    }

    public function post(string $path, array $body): array
    {
        return $this->request('POST', $path, [], $body);
    }

    public function put(string $path, array $body): array
    {
        return $this->request('PUT', $path, [], $body);
    }

    public function delete(string $path): void
    {
        $this->request('DELETE', $path);
    }

    /** All pages of a list endpoint. @return list<array> */
    public function all(string $path, array $query = [], ?callable $progress = null): array
    {
        $out  = [];
        $page = 1;
        do {
            $res   = $this->get($path, $query + ['page' => $page, 'limit' => 500]);
            $data  = $res['data'] ?? [];
            $out   = array_merge($out, $data);
            $pages = (int) ($res['meta']['pagination']['total_pages'] ?? 1);
            if (null !== $progress) {
                $progress($page, $pages);
            }
            ++$page;
        } while ($page <= $pages && [] !== $data);

        return $out;
    }

    private function request(string $method, string $path, array $query = [], ?array $body = null): array
    {
        $url = $this->base.'/api/v1/'.ltrim($path, '/');
        if ([] !== $query) {
            $url .= '?'.http_build_query($query);
        }
        $headers = ['Authorization: Bearer '.$this->token, 'Accept: application/vnd.api+json', 'Content-Type: application/json'];
        $payload = null === $body ? null : json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE);
        for ($attempt = 1; ; ++$attempt) {
            curl_reset($this->ch);
            $opts = [
                CURLOPT_URL            => $url,
                CURLOPT_CUSTOMREQUEST  => $method,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_TIMEOUT        => $this->timeout,
                CURLOPT_CONNECTTIMEOUT => 20,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_ENCODING       => '',
            ];
            if (null !== $payload) {
                $opts[CURLOPT_POSTFIELDS] = $payload;
            }
            if (null !== $this->cacert) {
                $opts[CURLOPT_CAINFO] = $this->cacert;
            }
            curl_setopt_array($this->ch, $opts);
            $start = microtime(true);
            $raw   = curl_exec($this->ch);
            $this->seconds += microtime(true) - $start;
            ++$this->requests;
            $status = (int) curl_getinfo($this->ch, CURLINFO_RESPONSE_CODE);
            if (false === $raw) {
                $err = curl_error($this->ch);
                if ($attempt < 5) {
                    Out::warn(sprintf('%s %s failed (%s), retrying in %ds', $method, $path, $err, 2 ** $attempt));
                    sleep(2 ** $attempt);

                    continue;
                }

                throw new ApiError(sprintf('Cannot reach Firefly at %s: %s', $this->base, $err));
            }
            if (in_array($status, [429, 502, 503, 504], true) && $attempt < 5) {
                Out::warn(sprintf('%s %s: HTTP %d, retrying in %ds', $method, $path, $status, 2 ** $attempt));
                sleep(2 ** $attempt);

                continue;
            }

            break;
        }
        $json = '' === $raw ? [] : json_decode((string) $raw, true);
        if ($status >= 200 && $status < 300) {
            return is_array($json) ? $json : [];
        }
        if (301 === $status || 302 === $status) {
            throw new ApiError(sprintf('HTTP %d redirect from %s - is the URL right (https, subfolder)?', $status, $url), $status);
        }
        if (401 === $status) {
            throw new ApiError('Firefly rejected the token (HTTP 401) - create a Personal Access Token under Options > Profile > OAuth', 401);
        }
        $message = is_array($json) ? (string) ($json['message'] ?? $json['exception'] ?? '') : '';
        if ('' === $message) {
            $message = Util::truncate(trim(strip_tags((string) $raw)), 300);
        }

        throw new ApiError(sprintf('%s %s: HTTP %d %s', $method, $path, $status, $message), $status, is_array($json) && is_array($json['errors'] ?? null) ? $json['errors'] : [], (string) $raw);
    }
}

// =====================================================================================
// Import into Firefly III
// =====================================================================================

final class Importer
{
    public const PAYEE_MARKER    = '[GnuCash-Import] counterparty';
    public const CLEARING_MARKER = '[GnuCash-Import] clearing account';

    private FireflyClient $api;
    private Pipeline $p;

    /** @var array<string, array{id:string, enabled:bool, decimals:int}> */
    private array $currencies = [];

    /** @var array<string, string> 'asset:<lower name>' / 'liability:..' / 'expense:..' / 'revenue:..' => id */
    private array $existing = [];

    /** @var array<string, string> account notes by id (to recognise earlier imports) */
    private array $existingNotes = [];

    /** @var array<string, string> 'bs:<guid>' / 'clearing:<CUR>' / 'expense:<lname>' / 'revenue:<lname>' => id */
    private array $ids = [];

    /** @var array<string, string> lower category name => id */
    private array $categories = [];

    /** @var array<string, list<array{0:int, 1:int, 2:list<string>, 3:list<string>, 4:array}>> GnuCash tx guid => [[group id, journal count, amounts, counterparties, group], ...] */
    private array $existingTx = [];

    /** @var resource */
    private $log;

    private bool $multisource;

    public function __construct(private Args $args, private \DateTimeZone $tz)
    {
    }

    public function run(): int
    {
        $args = $this->args;
        $args->check(['config', 'rules', 'from', 'to', 'timezone', 'verbose', 'quiet', 'url', 'token', 'token-file', 'cacert', 'timeout',
            'dry-run', 'yes', 'limit', 'stop-on-error', 'no-multisource', 'log', 'update-payees']);
        $from = self::date($args, 'from');
        $to   = self::date($args, 'to');
        $file = $args->positional[1] ?? throw new UserError('Missing GnuCash file');
        $this->p = $p = new Pipeline($file, $args, $this->tz);
        $this->multisource = !$args->has('no-multisource');
        $p->run($from, $to, $this->multisource);
        $p->config->save();
        $p->writeRulesTemplate();
        $report = $p->writePayeeReports();
        $p->writeSuggestions();
        $p->printSummary($report);
        $this->multisource = $this->multisource && (bool) $p->config->opt('multisource');

        $this->api = FireflyClient::fromArgs($args);
        $about     = $this->api->get('about');
        $user      = $this->api->get('about/user');
        Out::step(sprintf('Firefly III %s, user %s', $about['data']['version'] ?? '?', $user['data']['attributes']['email'] ?? '?'));
        $primary = $this->api->get('currencies/primary')['data']['attributes']['code'] ?? null;
        if (null !== $primary && $primary !== $p->book->defaultCurrency) {
            Out::warn(sprintf('Firefly primary currency is %s, the GnuCash book uses %s', $primary, $p->book->defaultCurrency));
        }

        // ---- what is needed (counterparties and categories: only for what is imported, see below)
        $needCur  = [];
        $needBs   = [];
        $needClr  = [];
        $dates    = [];
        foreach ($p->plans as $plan) {
            if (null !== $plan->skip) {
                continue;
            }
            $dates[] = $plan->tx->date;
            foreach ($plan->groups as $g) {
                foreach ($g->journals as $j) {
                    $needCur[$j->currency] = true;
                    if (null !== $j->foreignCurrency) {
                        $needCur[$j->foreignCurrency] = true;
                    }
                    foreach (['src', 'dst'] as $side) {
                        [$kind, $ref] = $j->{$side};
                        if ('bs' === $kind) {
                            $needBs[$ref] = true;
                        } elseif ('clearing' === $kind) {
                            $needClr[$this->clearingCurrency($j, $side)] = true;
                        }
                    }
                }
            }
        }
        $openingInRange = fn (string $guid): bool => isset($p->dec->openingBalance[$guid])
            && (null === $from || $p->dec->openingBalance[$guid]['date'] >= $from) && (null === $to || $p->dec->openingBalance[$guid]['date'] <= $to);
        foreach (array_keys($p->dec->openingBalance) as $guid) {
            if ($openingInRange($guid)) {
                $needBs[$guid] = true;
            }
        }
        foreach (array_keys($needBs) as $guid) {
            $needCur[(string) $p->config->accounts[$guid]['currency']] = true;
        }

        // ---- current state of Firefly
        Out::step('Reading Firefly state (currencies, accounts, categories, transactions)');
        foreach ($this->api->all('currencies') as $c) {
            $a = $c['attributes'];
            $this->currencies[$a['code']] = ['id' => (string) $c['id'], 'enabled' => (bool) $a['enabled'], 'decimals' => (int) $a['decimal_places']];
        }
        foreach (['asset' => 'asset', 'liabilities' => 'liability', 'expense' => 'expense', 'revenue' => 'revenue'] as $type => $key) {
            foreach ($this->api->all('accounts', ['type' => $type]) as $a) {
                $this->existing[$key.':'.Util::lower((string) $a['attributes']['name'])] = (string) $a['id'];
                $this->existingNotes[(string) $a['id']]                              = (string) ($a['attributes']['notes'] ?? '');
            }
        }
        foreach ($this->api->all('categories') as $c) {
            $this->categories[Util::lower((string) $c['attributes']['name'])] = (string) $c['id'];
        }
        $planned = [];
        foreach ($p->plans as $plan) {
            if (null === $plan->skip) {
                $planned[$plan->tx->guid] = true;
            }
        }
        if ([] !== $dates) {
            $groups = $this->api->all('transactions', ['type' => 'all', 'start' => min($dates), 'end' => max($dates)]);
            foreach ($groups as $g) {
                $ext = [];
                foreach ($g['attributes']['transactions'] as $j) {
                    $ext[(string) ($j['external_id'] ?? '')] = true;
                }
                if (1 === count($ext)) {
                    $guid = (string) array_key_first($ext);
                    if (isset($planned[$guid])) {
                        $sig = [];
                        $cp  = [];
                        foreach ($g['attributes']['transactions'] as $j) {
                            $sig[] = Util::decToMinor((string) $j['amount'], (int) $j['currency_decimal_places']).' '.$j['currency_code'];
                            if ('Expense account' === ($j['destination_type'] ?? '')) {
                                $cp[] = (string) $j['destination_name'];
                            }
                            if ('Revenue account' === ($j['source_type'] ?? '')) {
                                $cp[] = (string) $j['source_name'];
                            }
                        }
                        $this->existingTx[$guid][] = [(int) $g['id'], count($g['attributes']['transactions']), $sig, $cp, $g['attributes']];
                    }
                }
            }
        }

        // ---- preflight
        $missingCur  = array_values(array_filter(array_keys($needCur), fn ($c) => !isset($this->currencies[$c])));
        $disabledCur = array_values(array_filter(array_keys($needCur), fn ($c) => isset($this->currencies[$c]) && !$this->currencies[$c]['enabled']));
        $newBs       = [];
        $reusedBs    = [];
        foreach (array_keys($needBs) as $guid) {
            $m   = $p->config->accounts[$guid];
            $key = $m['as'].':'.Util::lower((string) $m['name']);
            if (isset($this->existing[$key])) {
                $this->ids['bs:'.$guid] = $this->existing[$key];
                $foreign                = !str_contains($this->existingNotes[$this->existing[$key]] ?? '', $guid);
                $reusedBs[]             = sprintf('%s "%s" (#%s)%s', $m['as'], $m['name'], $this->existing[$key], $foreign ? ' - existed before, not created by this import' : '');
            } else {
                $newBs[] = $guid;
            }
        }
        $todo   = [];
        $done   = 0;
        $repair = 0;
        $stale  = [];
        foreach ($p->plans as $plan) {
            if (null !== $plan->skip) {
                continue;
            }
            $have = $this->existingTx[$plan->tx->guid] ?? [];
            if ([] !== $have) {
                $haveSig = array_merge(...array_column($have, 2));
                $wantSig = [];
                foreach ($plan->groups as $g) {
                    foreach ($g->journals as $j) {
                        $wantSig[] = $this->amountSig($j);
                    }
                }
                sort($haveSig);
                sort($wantSig);
                if ($haveSig === $wantSig) {     // same journals and amounts: nothing to do
                    ++$done;
                    // counterparties are not changed on transactions that are already in Firefly
                    $old = array_merge(...array_column($have, 3));
                    $new = $this->plannedPayees($plan);
                    if (self::lowerSorted($old) !== self::lowerSorted($new)) {
                        $stale[] = [$plan, array_values(array_unique($old)), array_values(array_unique($new))];
                    }

                    continue;
                }
                ++$repair;
            }
            $todo[] = $plan;
        }
        $limit = $args->get('limit');
        if (null !== $limit && '' !== $limit) {
            $todo = array_slice($todo, 0, max(0, (int) $limit));
        }
        // --update-payees: move already imported journals to the counterparties of the current rules
        $update    = $args->has('update-payees');
        $renames   = [];
        $unmatched = 0;
        if ($update) {
            foreach ($stale as [$plan]) {
                $u = $this->payeeUpdates($plan, $this->existingTx[$plan->tx->guid]);
                if (null === $u) {
                    ++$unmatched;

                    continue;
                }
                array_push($renames, ...$u);
            }
        }
        // counterparties and categories only for the transactions that are imported now: after a
        // rule change, --limit or a partial run no unused accounts are created
        $needPay = [];
        $needCat = [];
        foreach ($renames as $r) {
            foreach ($r['set'] as $fields) {
                foreach ($fields as [$key, $name, , $iban]) {
                    $needPay[$key] ??= ['side' => explode(':', $key, 2)[0], 'name' => $name, 'iban' => $iban];
                }
            }
        }
        foreach ($todo as $plan) {
            foreach ($plan->groups as $g) {
                foreach ($g->journals as $j) {
                    foreach (['src', 'dst'] as $side) {
                        if ('payee' === $j->{$side}[0]) {
                            $r        = $p->payees->get((string) $j->{$side}[1]);
                            $sideName = 'src' === $side ? 'revenue' : 'expense';
                            $needPay[$sideName.':'.Util::lower($r['name'])] ??= ['side' => $sideName, 'name' => $r['name'], 'iban' => $r['iban']];
                        }
                    }
                    if (null !== $j->category) {
                        $needCat[Util::lower($j->category)] = $j->category;
                    }
                }
            }
        }
        $newPay = array_filter($needPay, fn ($x) => !isset($this->existing[$x['side'].':'.Util::lower($x['name'])]));
        $newCat = array_filter($needCat, fn ($name, $l) => !isset($this->categories[$l]), ARRAY_FILTER_USE_BOTH);
        Out::step('Plan for Firefly');
        if ([] !== $missingCur) {
            Out::info('  create currencies:   '.implode(', ', $missingCur));
        }
        if ([] !== $disabledCur) {
            Out::info('  enable currencies:   '.implode(', ', $disabledCur));
        }
        Out::info(sprintf('  asset/liability:     %d new, %d existing', count($newBs), count($reusedBs)));
        foreach ($reusedBs as $r) {
            Out::info('                       reuse '.$r);
        }
        Out::info(sprintf('  counterparties:      %d new, %d existing', count($newPay), count($needPay) - count($newPay)));
        Out::info(sprintf('  categories:          %d new, %d existing', count($newCat), count($needCat) - count($newCat)));
        Out::info(sprintf('  transactions:        %d to import%s, %d already imported%s', count($todo), null !== $limit && '' !== $limit ? ' (--limit)' : '', $done, $repair > 0 ? sprintf(', %d incomplete or changed ones are re-created', $repair) : ''));
        if ([] !== $stale) {
            if ($update) {
                Out::info(sprintf('  counterparties of already imported transactions: %d transactions are changed (--update-payees)%s', count($stale) - $unmatched,
                    $unmatched > 0 ? sprintf(', %d cannot be matched journal by journal and stay unchanged', $unmatched) : ''));
            } else {
                Out::warn(sprintf('%d already imported transactions have other counterparties in Firefly than with the current rules. They are not changed; to change them run import again with --update-payees (or purge --accounts and import again).', count($stale)));
            }
            foreach (array_slice($stale, 0, 5) as [$plan, $old, $new]) {
                Out::info(sprintf('    %s %s: %s -> %s', $plan->tx->date, Util::truncate($plan->tx->description, 50), implode(', ', $old), implode(', ', $new)));
            }
        }
        if ($args->has('dry-run')) {
            Out::info('Dry run - nothing was changed in Firefly.');

            return 0;
        }
        if ([] === $todo && [] === $renames && [] === $newBs && [] === $newPay && [] === $newCat && [] === $missingCur && [] === $disabledCur) {
            Out::info('Nothing to do.');

            return 0;
        }
        if (!Out::confirm('Start the import?', $args->has('yes'))) {
            Out::info('Aborted.');

            return 1;
        }
        $logFile   = $args->get('log') ?? $p->base.'.import-log.jsonl';
        $this->log = fopen($logFile, 'ab');

        // ---- currencies
        foreach ($missingCur as $code) {
            $name = ['DEM' => ['Deutsche Mark', 'DM'], 'FRF' => ['Französischer Franc', 'FF'], 'ATS' => ['Österreichischer Schilling', 'öS'], 'NLG' => ['Niederländischer Gulden', 'hfl'],
                'ITL'      => ['Italienische Lira', 'L.'], 'ESP' => ['Spanische Peseta', 'Pta'], 'BEF' => ['Belgischer Franc', 'bfr'], 'LUF' => ['Luxemburgischer Franc', 'lfr'],
                'FIM'      => ['Finnische Mark', 'mk'], 'IEP' => ['Irisches Pfund', 'IR£'], 'PTE' => ['Portugiesischer Escudo', 'Esc'], 'GRD' => ['Griechische Drachme', 'Dr.']][$code] ?? [$code, $code];
            try {
                $res = $this->api->post('currencies', ['code' => $code, 'name' => $name[0], 'symbol' => $name[1], 'decimal_places' => Decomposer::decimals($code), 'enabled' => true]);
            } catch (ApiError $e) {
                throw new UserError(sprintf('Cannot create currency %s (%s). Create it as Firefly owner under Options > Currencies and run import again.', $code, $e->details()));
            }
            $this->currencies[$code] = ['id' => (string) $res['data']['id'], 'enabled' => true, 'decimals' => Decomposer::decimals($code)];
            Out::info(sprintf('  created currency %s', $code));
        }
        foreach ($disabledCur as $code) {
            $this->api->post('currencies/'.$code.'/enable', []);
            Out::info(sprintf('  enabled currency %s', $code));
        }

        // ---- accounts
        foreach ($newBs as $guid) {
            $this->ids['bs:'.$guid] = $this->createBsAccount($guid, $openingInRange($guid));
        }
        foreach (array_keys($needClr) as $cur) {
            $name = (string) $p->config->opt('clearing_account').($cur === $p->book->defaultCurrency ? '' : ' ('.$cur.')');
            $key  = 'asset:'.Util::lower($name);
            if (isset($this->existing[$key])) {
                $this->ids['clearing:'.$cur] = $this->existing[$key];

                continue;
            }
            $res = $this->api->post('accounts', ['name' => $name, 'type' => 'asset', 'account_role' => 'defaultAsset', 'currency_code' => $cur,
                'include_net_worth' => false, 'active' => true, 'notes' => self::CLEARING_MARKER."\nTechnical account for GnuCash transactions without asset account (e.g. income <-> expense reclassifications). Its balance stays 0."]);
            $this->ids['clearing:'.$cur] = (string) $res['data']['id'];
            $this->existing[$key]        = (string) $res['data']['id'];
        }
        $n = 0;
        foreach ($needPay as $k => $x) {
            if (isset($this->existing[$k])) {
                $this->ids[$k] = $this->existing[$k];

                continue;
            }
            $body = ['name' => $x['name'], 'type' => $x['side'], 'notes' => self::PAYEE_MARKER];
            if (null !== $x['iban']) {
                $body['iban'] = $x['iban'];
            }

            try {
                $res = $this->api->post('accounts', $body);
            } catch (ApiError $e) {
                if (!isset($body['iban']) || 422 !== $e->status) {
                    throw $e;
                }
                unset($body['iban']);   // IBAN already used elsewhere in Firefly
                $res = $this->api->post('accounts', $body);
            }
            $this->ids[$k] = $this->existing[$k] = (string) $res['data']['id'];
            if (0 === ++$n % 100) {
                Out::info(sprintf('  %d/%d counterparties created', $n, count($newPay)));
            }
        }
        // category notes: GnuCash account(s) behind the category, used by "export"
        $footers = [];
        foreach ($p->config->accounts as $guid => $m) {
            if ('category' === $m['as'] && isset($p->book->accounts[$guid])) {
                $acc                                           = $p->book->accounts[$guid];
                $footers[Util::lower((string) $m['name'])][] = sprintf('[GnuCash] %s | %s | %s | %s', $acc->path, $acc->type, $guid, $acc->cmdtyId);
            }
        }
        foreach ($newCat as $lname => $name) {
            $res                       = $this->api->post('categories', ['name' => $name, 'notes' => implode("\n", $footers[$lname] ?? [])]);
            $this->categories[$lname]  = (string) $res['data']['id'];
        }
        Out::info(sprintf('  accounts and categories ready (%d API calls so far)', $this->api->requests));

        // ---- transactions
        $errors = [] === $todo && [] !== $renames ? 0 : $this->importTransactions($todo);
        if ([] !== $renames) {
            $errors += $this->updatePayees($renames);
        }
        fclose($this->log);
        Out::info(sprintf('Log: %s', $logFile));

        // ---- balance check
        if (null === $from && null === $to && null === $limit) {
            $this->checkBalances(array_keys($needBs), $dates);
        }

        return $errors > 0 ? 1 : 0;
    }

    private static function date(Args $args, string $name): ?string
    {
        $v = $args->get($name);

        return null === $v || '' === $v ? null : $v;
    }

    /** Currency of the clearing account on one side of a journal. */
    private function clearingCurrency(Journal $j, string $side): string
    {
        // the primary amount belongs to the source, except for deposits from income/refunds
        if ('src' === $side || ('deposit' === $j->type && 'payee' === $j->src[0])) {
            return $j->currency;
        }

        return $j->foreignCurrency ?? $j->currency;
    }

    private function createBsAccount(string $guid, bool $withOpening): string
    {
        $p   = $this->p;
        $m   = $p->config->accounts[$guid];
        $acc = $p->book->account($guid);
        $notes = array_filter([$acc->description, $acc->notes], static fn ($s) => '' !== $s);
        $notes[] = sprintf('[GnuCash] %s | %s | %s | %s', $acc->path, $acc->type, $guid, $acc->cmdtyId);
        $body  = [
            'name'          => $m['name'],
            'currency_code' => $m['currency'],
            'active'        => (bool) ($m['active'] ?? true),
            'notes'         => implode("\n\n", $notes),
        ];
        if (!empty($m['iban'])) {
            $body['iban'] = $m['iban'];
        }
        if (!empty($m['account_number'])) {
            $body['account_number'] = (string) $m['account_number'];
        }
        if ('asset' === $m['as']) {
            $body['type']              = 'asset';
            $body['account_role']      = $m['role'] ?? 'defaultAsset';
            $body['include_net_worth'] = (bool) ($m['include_net_worth'] ?? true);
            if ('ccAsset' === $body['account_role']) {
                $body['credit_card_type']     = 'monthlyFull';
                $body['monthly_payment_date'] = '2000-01-01';
            }
            if ($withOpening && isset($p->dec->openingBalance[$guid])) {
                $ob                           = $p->dec->openingBalance[$guid];
                $body['opening_balance']      = Util::minorToDec($ob['amount'], $ob['decimals']);
                $body['opening_balance_date'] = $ob['date'];
            }
        } else {
            $body['type']                = 'liability';
            $body['liability_type']      = $m['liability_type'] ?? 'debt';
            $body['liability_direction'] = $m['direction'] ?? 'debit';
            $body['interest']            = '0';
            $body['interest_period']     = 'monthly';
        }

        try {
            $res = $this->api->post('accounts', $body);
        } catch (ApiError $e) {
            throw new UserError(sprintf('Cannot create %s account "%s": %s', $m['as'], $m['name'], $e->details()));
        }
        Out::info(sprintf('  created %s "%s"', $m['as'], $m['name']));

        return (string) $res['data']['id'];
    }

    /** Amount of a planned journal as Firefly stores it ("1234 EUR", minor units). */
    private function amountSig(Journal $j): string
    {
        return Util::share($j->amount, Util::fractionForDecimals($this->currencies[$j->currency]['decimals'] ?? $j->decimals), Util::fractionForDecimals($j->decimals)).' '.$j->currency;
    }

    /** @param array<string, mixed> $e journal from the Firefly API */
    private static function fireflySig(array $e): string
    {
        return Util::decToMinor((string) $e['amount'], (int) $e['currency_decimal_places']).' '.$e['currency_code'];
    }

    /**
     * Counterparty changes for the Firefly groups of an already imported GnuCash transaction.
     *
     * @param list<array{0:int, 1:int, 2:list<string>, 3:list<string>, 4:array}> $have
     *
     * @return null|list<array{group:int, title:string, journals:list<string>, set:array<string, array<string, array{0:string, 1:string, 2:string, 3:?string}>>}>
     *                null if the journals cannot be matched reliably
     */
    private function payeeUpdates(TxPlan $plan, array $have): ?array
    {
        $used = [];
        $out  = [];
        foreach ($plan->groups as $g) {
            $want = array_map(fn (Journal $j): string => $this->amountSig($j), $g->journals);
            $sorted = $want;
            sort($sorted);
            $match = null;
            foreach ($have as $i => $h) {
                $sig = $h[2];
                sort($sig);
                if (!isset($used[$i]) && $g->type === (string) ($h[4]['transactions'][0]['type'] ?? '') && $sig === $sorted) {
                    $match = $i;

                    break;
                }
            }
            if (null === $match) {
                return null;
            }
            $used[$match] = true;
            $existing     = $have[$match][4]['transactions'];
            $pairs        = self::pairJournals($g->journals, $existing, $want);
            if (null === $pairs) {
                return null;
            }
            $set = [];
            foreach ($pairs as [$j, $e]) {
                foreach (['src' => 'source', 'dst' => 'destination'] as $side => $field) {
                    if ('payee' !== $j->{$side}[0]) {
                        continue;
                    }
                    if (('src' === $side ? 'Revenue account' : 'Expense account') !== ($e[$field.'_type'] ?? '')) {
                        return null;    // no counterparty there any more (changed by hand?)
                    }
                    $r    = $this->p->payees->get((string) $j->{$side}[1]);
                    $name = (string) $r['name'];
                    if (Util::lower($name) !== Util::lower((string) $e[$field.'_name'])) {
                        $key = ('src' === $side ? 'revenue' : 'expense').':'.Util::lower($name);
                        $set[(string) $e['transaction_journal_id']][$field] = [$key, $name, (string) $e[$field.'_id'], $r['iban']];
                    }
                }
            }
            if ([] !== $set) {
                $out[] = ['group' => $have[$match][0], 'title' => (string) ($have[$match][4]['group_title'] ?? ''),
                    'journals' => array_map(static fn (array $e): string => (string) $e['transaction_journal_id'], $existing), 'set' => $set];
            }
        }

        return $out;
    }

    /**
     * Planned journal <-> Firefly journal: by position ("order" is set by the import), otherwise
     * by amount, description and category.
     *
     * @param list<Journal>              $journals
     * @param list<array<string, mixed>> $existing
     * @param list<string>               $want     amounts of $journals
     *
     * @return null|list<array{0:Journal, 1:array<string, mixed>}>
     */
    private static function pairJournals(array $journals, array $existing, array $want): ?array
    {
        if (count($journals) !== count($existing)) {
            return null;
        }
        $byOrder = [];
        foreach ($existing as $e) {
            $byOrder[(int) ($e['order'] ?? -1)][] = $e;
        }
        $pairs = [];
        foreach ($journals as $k => $j) {
            $e = $byOrder[$k] ?? [];
            if (1 !== count($e) || self::fireflySig($e[0]) !== $want[$k] || Util::collapse((string) $e[0]['description']) !== Util::collapse($j->description)) {
                $pairs = null;

                break;
            }
            $pairs[] = [$j, $e[0]];
        }
        if (null !== $pairs) {
            return $pairs;
        }
        $pairs = [];
        $taken = [];
        foreach ([true, false] as $withCategory) {
            foreach ($journals as $k => $j) {
                if (isset($pairs[$k])) {
                    continue;
                }
                foreach ($existing as $i => $e) {
                    if (!isset($taken[$i]) && self::fireflySig($e) === $want[$k] && Util::collapse((string) $e['description']) === Util::collapse($j->description)
                        && (!$withCategory || Util::lower((string) ($e['category_name'] ?? '')) === Util::lower((string) $j->category))) {
                        $pairs[$k] = [$j, $e];
                        $taken[$i] = true;

                        break;
                    }
                }
            }
        }
        if (count($pairs) !== count($journals)) {
            return null;
        }
        ksort($pairs);

        return array_values($pairs);
    }

    /**
     * Moves already imported journals to other counterparties; counterparties created by the
     * import that end up without transactions are deleted.
     *
     * @param list<array{group:int, title:string, journals:list<string>, set:array<string, array<string, array{0:string, 1:string, 2:string, 3:?string}>>}> $renames
     */
    private function updatePayees(array $renames): int
    {
        Out::step(sprintf('Changing the counterparties of %d already imported transaction groups', count($renames)));
        $errors = 0;
        $old    = [];
        foreach ($renames as $n => $r) {
            $rows = [];
            foreach ($r['journals'] as $jid) {
                $row = ['transaction_journal_id' => $jid];
                foreach ($r['set'][$jid] ?? [] as $field => [$key, , $oldId]) {
                    $row[$field.'_id'] = $this->ids[$key];
                    $old[$oldId]       = true;
                }
                $rows[] = $row;
            }
            $body = ['apply_rules' => false, 'fire_webhooks' => false, 'transactions' => $rows];
            if (count($rows) > 1) {
                $body['group_title'] = $r['title'];
            }

            try {
                $this->api->put('transactions/'.$r['group'], $body);
                fwrite($this->log, Util::jsonLine(['group' => $r['group'], 'status' => 'counterparty changed', 'changes' => $r['set']]));
            } catch (ApiError $e) {
                ++$errors;
                Out::error(sprintf('transaction group #%d: %s', $r['group'], $e->details()));
                fwrite($this->log, Util::jsonLine(['group' => $r['group'], 'status' => 'error', 'error' => $e->details(), 'payload' => $body]));
            }
            if (0 === ($n + 1) % 100) {
                Out::info(sprintf('  %d/%d', $n + 1, count($renames)));
            }
        }
        $deleted = 0;
        foreach (array_keys($old) as $id) {
            if (!str_contains($this->existingNotes[(string) $id] ?? '', self::PAYEE_MARKER)) {
                continue;       // not created by the import: never deleted
            }

            try {
                $left = $this->api->get('accounts/'.$id.'/transactions', ['limit' => 1]);   // without dates: all
                if (0 === (int) ($left['meta']['pagination']['total'] ?? 1)) {
                    $this->api->delete('accounts/'.$id);
                    ++$deleted;
                }
            } catch (ApiError $e) {
                Out::warn(sprintf('counterparty #%s not checked/deleted: %s', $id, $e->details()));
            }
        }
        Out::info(sprintf('Done: %d transaction groups changed, %d errors, %d counterparties without transactions deleted', count($renames) - $errors, $errors, $deleted));

        return $errors;
    }

    /** @return list<string> counterparty names of the planned journals */
    private function plannedPayees(TxPlan $plan): array
    {
        $names = [];
        foreach ($plan->groups as $g) {
            foreach ($g->journals as $j) {
                foreach (['src', 'dst'] as $side) {
                    if ('payee' === $j->{$side}[0]) {
                        $names[] = (string) $this->p->payees->get((string) $j->{$side}[1])['name'];
                    }
                }
            }
        }

        return $names;
    }

    /**
     * @param list<string> $names
     *
     * @return list<string>
     */
    private static function lowerSorted(array $names): array
    {
        $names = array_map(static fn (string $n): string => Util::lower($n), $names);
        sort($names);

        return $names;
    }

    private function accountId(array $ref, Journal $j, string $side): string
    {
        [$kind, $key] = $ref;
        if ('bs' === $kind) {
            return $this->ids['bs:'.$key];
        }
        if ('clearing' === $kind) {
            return $this->ids['clearing:'.$this->clearingCurrency($j, $side)];
        }
        $r = $this->p->payees->get((string) $key);

        return $this->ids[('src' === $side ? 'revenue' : 'expense').':'.Util::lower($r['name'])];
    }

    private function payload(TxPlan $plan, Group $g): array
    {
        $tag = (string) $this->p->config->opt('import_tag');
        $txs = [];
        foreach ($g->journals as $order => $j) {
            $row = [
                'type'           => $j->type,
                'date'           => $plan->tx->date,
                'order'          => $order,
                'amount'         => Util::minorToDec($j->amount, $j->decimals),
                'currency_code'  => $j->currency,
                'description'    => $j->description,
                'source_id'      => $this->accountId($j->src, $j, 'src'),
                'destination_id' => $this->accountId($j->dst, $j, 'dst'),
                'external_id'    => $plan->tx->guid,
                'reconciled'     => $j->reconciled,
            ];
            if (null !== $j->foreignCurrency && null !== $j->foreignAmount) {
                $row['foreign_amount']        = Util::minorToDec($j->foreignAmount, $j->foreignDecimals);
                $row['foreign_currency_code'] = $j->foreignCurrency;
            }
            if (null !== $j->category) {
                $row['category_id'] = $this->categories[Util::lower($j->category)];
            }
            if ('' !== $j->notes) {
                $row['notes'] = $j->notes;
            }
            $tags = array_values(array_filter([$tag, $g->self ? (string) $this->p->config->opt('selfflow_tag') : ''], static fn ($x) => '' !== $x));
            if ([] !== $tags) {
                $row['tags'] = $tags;
            }
            if ('' !== $plan->tx->num) {
                $row['internal_reference'] = Util::truncate($plan->tx->num, 255);
            }
            $txs[] = $row;
        }
        $body = [
            'error_if_duplicate_hash' => false,
            'apply_rules'             => (bool) $this->p->config->opt('apply_rules'),
            'fire_webhooks'           => (bool) $this->p->config->opt('fire_webhooks'),
            'transactions'            => $txs,
        ];
        if (null !== $g->title) {
            $body['group_title'] = $g->title;
        }

        return $body;
    }

    /** @param list<TxPlan> $todo */
    private function importTransactions(array $todo): int
    {
        $total  = count($todo);
        $start  = microtime(true);
        $errors = 0;
        $stop   = $this->args->has('stop-on-error');
        Out::step(sprintf('Importing %d transactions', $total));
        foreach ($todo as $i => $plan) {
            $guid = $plan->tx->guid;
            foreach ($this->existingTx[$guid] ?? [] as [$gid]) {
                try {
                    $this->api->delete('transactions/'.$gid);
                } catch (ApiError $e) {
                    Out::warn(sprintf('could not delete incomplete transaction #%d: %s', $gid, $e->getMessage()));
                }
            }
            if (!$this->multisource && self::hasMultiAccountGroups($plan)) {
                $plan = $this->p->dec->decompose($plan->tx);
            }
            [$created, $error] = $this->postGroups($plan);
            if (null !== $error && $this->multisource && 422 === $error->status && self::hasMultiAccountGroups($plan)) {
                // maybe an upstream Firefly (without the multisource patch): try separate transactions
                $this->p->dec->setMultisource(false);
                $alt = $this->p->dec->decompose($plan->tx);
                [$altCreated, $altError] = $this->postGroups($alt);
                if (null === $altError) {
                    Out::warn('Firefly rejects split transactions with several source/destination accounts (upstream Firefly instead of the multisource fork?) - such transactions are imported as separate transactions from now on.');
                    $this->multisource = false;
                    [$plan, $created, $error] = [$alt, $altCreated, null];
                } else {
                    $this->p->dec->setMultisource(true);
                    $this->rollback($altCreated);
                }
            }
            if (null !== $error) {
                ++$errors;
                $this->rollback($created);          // keep Firefly consistent: all or nothing per GnuCash transaction
                Out::error(sprintf('%s "%s" (%s): %s', $plan->tx->date, Util::truncate($plan->tx->description, 60), $guid, $error->details()));
                fwrite($this->log, Util::jsonLine(['guid' => $guid, 'date' => $plan->tx->date, 'description' => $plan->tx->description, 'status' => 'error', 'error' => $error->details(),
                    'payload' => array_map(fn (Group $g) => $this->payload($plan, $g), $plan->groups)]));
                if ($stop) {
                    throw new UserError('Stopped after the first error (--stop-on-error).');
                }

                continue;
            }
            fwrite($this->log, Util::jsonLine(['guid' => $guid, 'date' => $plan->tx->date, 'status' => 'ok', 'groups' => $created]));
            if (0 === ($i + 1) % 100 || $i + 1 === $total) {
                $elapsed = microtime(true) - $start;
                $rate    = ($i + 1) / max(0.001, $elapsed);
                $eta     = (int) (($total - $i - 1) / max(0.001, $rate));
                Out::info(sprintf('  %d/%d (%.1f%%)  %.1f tx/s  ETA %02d:%02d:%02d%s', $i + 1, $total, 100 * ($i + 1) / max(1, $total), $rate, intdiv($eta, 3600), intdiv($eta % 3600, 60), $eta % 60, $errors > 0 ? "  errors: {$errors}" : ''));
            }
        }
        Out::info(sprintf('Done: %d transactions imported, %d errors, %.0f s', $total - $errors, $errors, microtime(true) - $start));

        return $errors;
    }

    /** @return array{0:list<int>, 1:?ApiError} created group ids and the first error */
    private function postGroups(TxPlan $plan): array
    {
        $created = [];
        $self    = [];
        foreach ($plan->groups as $g) {
            try {
                $res       = $this->api->post('transactions', $this->payload($plan, $g));
                $created[] = (int) $res['data']['id'];
                if ($g->self) {
                    $self[] = (int) ($res['data']['attributes']['transactions'][0]['transaction_journal_id'] ?? 0);
                }
            } catch (ApiError $e) {
                $this->rollback($created);

                return [[], $e];
            }
        }
        // the two halves of a transfer of an account to itself: link them ("Related")
        for ($k = 1; $k < count($self); ++$k) {
            try {
                if (null !== ($lt = $this->relatedLinkType()) && $self[0] > 0 && $self[$k] > 0) {
                    $this->api->post('transaction-links', ['link_type_id' => $lt, 'outward_id' => $self[0], 'inward_id' => $self[$k],
                        'notes' => 'GnuCash '.$plan->tx->guid]);
                }
            } catch (ApiError $e) {
                Out::warn(sprintf('%s "%s": transactions not linked: %s', $plan->tx->date, Util::truncate($plan->tx->description, 60), $e->details()));
            }
        }

        return [$created, null];
    }

    private string|false|null $linkType = null;

    /** id of the Firefly link type "Related" (null if missing) */
    private function relatedLinkType(): ?string
    {
        if (null === $this->linkType) {
            $this->linkType = false;
            foreach ($this->api->all('link-types') as $lt) {
                if ('related' === Util::lower((string) ($lt['attributes']['name'] ?? ''))) {
                    $this->linkType = (string) $lt['id'];

                    break;
                }
            }
            if (false === $this->linkType) {
                Out::warn('Firefly has no link type "Related" - transfers of an account to itself are tagged but not linked');
            }
        }

        return false === $this->linkType ? null : $this->linkType;
    }

    /** @param list<int> $ids */
    private function rollback(array $ids): void
    {
        foreach ($ids as $gid) {
            try {
                $this->api->delete('transactions/'.$gid);
            } catch (ApiError $e) {
                Out::warn(sprintf('could not delete transaction #%d: %s', $gid, $e->getMessage()));
            }
        }
    }

    private static function hasMultiAccountGroups(TxPlan $plan): bool
    {
        foreach ($plan->groups as $g) {
            $s = $d = [];
            foreach ($g->journals as $j) {
                $s[implode(':', $j->src)] = true;
                $d[implode(':', $j->dst)] = true;
            }
            if (('withdrawal' === $g->type && count($s) > 1) || ('deposit' === $g->type && count($d) > 1)) {
                return true;
            }
        }

        return false;
    }

    /** Compare Firefly balances with the GnuCash balances of the imported accounts. */
    private function checkBalances(array $guids, array $dates): void
    {
        if ([] === $dates) {
            return;
        }
        Out::step('Checking balances against GnuCash');
        $p        = $this->p;
        $expected = [];
        foreach ($p->book->transactions as $t) {
            $txFrac = Util::fractionForDecimals(Decomposer::decimals($t->currency));
            foreach ($t->splits as $s) {
                $m = $p->config->accounts[$s->account] ?? null;
                if (null === $m || !in_array($m['as'], ['asset', 'liability'], true)) {
                    continue;
                }
                $acc = $p->book->account($s->account);
                $q   = $acc->isCurrency() && ($m['currency'] ?? $acc->cmdtyId) === $acc->cmdtyId ? Util::fracToMinor($s->quantity, $acc->scu) : Util::fracToMinor($s->value, $txFrac);
                $expected[$s->account] = ($expected[$s->account] ?? 0) + $q;
            }
        }
        $date = max($dates);
        $bad  = 0;
        $ok   = 0;
        foreach ($guids as $guid) {
            $id  = $this->ids['bs:'.$guid] ?? null;
            if (null === $id) {
                continue;
            }
            $m   = $p->config->accounts[$guid];
            $acc = $p->book->account($guid);
            $dec = $acc->isCurrency() ? Util::decimalsForFraction($acc->scu) : Decomposer::decimals((string) $m['currency']);
            $res = $this->api->get('accounts/'.$id, ['date' => $date]);
            $got = Util::decToMinor((string) ($res['data']['attributes']['current_balance'] ?? '0'), $dec);
            $exp = $expected[$guid] ?? 0;
            if ('liability' === $m['as'] && $got === -$exp && 0 !== $exp) {
                $got = -$got;   // Firefly shows liabilities as positive debt
            }
            if ($got === $exp) {
                ++$ok;

                continue;
            }
            ++$bad;
            Out::warn(sprintf('balance of "%s" in Firefly: %s, in GnuCash: %s %s', $m['name'], Util::minorToDec($got, $dec), Util::minorToDec($exp, $dec), $m['currency']));
        }
        Out::info(sprintf('  %d accounts match GnuCash%s', $ok, $bad > 0 ? sprintf(', %d differ (see warnings)', $bad) : ''));
    }
}

// =====================================================================================
// Export from Firefly III to a GnuCash XML book
// =====================================================================================

final class Exporter
{
    private const ROOT_NAMES = ['asset' => 'Aktiva', 'liability' => 'Fremdkapital', 'expense' => 'Aufwendungen', 'income' => 'Erträge'];
    private const BS_TYPES   = ['Asset account', 'Default account', 'Loan', 'Debt', 'Mortgage', 'Credit card'];
    private const PL_TYPES   = ['Expense account', 'Beneficiary account', 'Revenue account', 'Cash account', 'Import account'];

    private FireflyClient $api;
    private string $bookCur = 'EUR';

    /** @var array<string, int> currency => decimals */
    private array $decimals = [];

    /** @var array<string, array<string, mixed>> node key => GnuCash account */
    private array $nodes = [];

    /** @var array<string, string> Firefly account id => node key */
    private array $accountNode = [];

    /** @var array<string, string> Firefly account id => name */
    private array $accountName = [];

    /** @var array<string, bool> clearing account ids */
    private array $clearing = [];

    /** @var array<string, array<string, mixed>> lower category name => info */
    private array $categoryInfo = [];

    /** @var list<array<string, mixed>> GnuCash transactions */
    private array $transactions = [];

    private string $fallbackPayee = '(diverse)';
    private string $reconciledState = 'y';

    /** @var array<string, int> */
    private array $warnings = [];

    public function __construct(private Args $args, private \DateTimeZone $tz)
    {
    }

    public function run(): int
    {
        $args = $this->args;
        $args->check(['from', 'to', 'uncompressed', 'url', 'token', 'token-file', 'cacert', 'timeout', 'timezone', 'verbose', 'quiet', 'reconciled-state', 'payee-fallback', 'yes']);
        $out = $args->positional[1] ?? throw new UserError('Missing output file, e.g. export firefly.gnucash');
        if (is_file($out) && !Out::confirm(sprintf('"%s" exists - overwrite?', $out), $args->has('yes'))) {
            return 1;
        }
        $this->reconciledState = in_array($args->get('reconciled-state'), ['c', 'y'], true) ? (string) $args->get('reconciled-state') : 'y';
        $this->fallbackPayee   = $args->get('payee-fallback') ?? '(diverse)';
        $this->api             = FireflyClient::fromArgs($args);
        $about                 = $this->api->get('about');
        Out::step(sprintf('Reading Firefly III %s', $about['data']['version'] ?? '?'));
        $this->bookCur = (string) ($this->api->get('currencies/primary')['data']['attributes']['code'] ?? 'EUR');
        foreach ($this->api->all('currencies') as $c) {
            $this->decimals[(string) $c['attributes']['code']] = (int) $c['attributes']['decimal_places'];
        }
        $accounts   = $this->api->all('accounts', ['type' => 'all']);
        $categories = $this->api->all('categories');
        $query      = ['type' => 'all'];
        if (null !== ($f = $args->get('from'))) {
            $query['start'] = $f;
        }
        if (null !== ($t = $args->get('to'))) {
            $query['end'] = $t;
        }
        if (isset($query['start']) !== isset($query['end'])) {
            $query['start'] ??= '1900-01-01';
            $query['end']   ??= '2099-12-31';
        }
        $groups = $this->api->all('transactions', $query, static function (int $page, int $pages): void {
            if (0 === $page % 5 || $page === $pages) {
                Out::info(sprintf('  transactions: page %d/%d', $page, $pages));
            }
        });
        Out::info(sprintf('  %d accounts, %d categories, %d transaction groups', count($accounts), count($categories), count($groups)));

        $this->nodes['root'] = ['guid' => Util::guid('root'), 'name' => 'Root Account', 'type' => 'ROOT', 'cmdty' => $this->bookCur, 'parent' => null, 'path' => '', 'placeholder' => false];
        $this->buildAccounts($accounts);
        $this->buildCategories($categories, $groups);
        $this->buildTransactions($groups);
        $this->addParents();

        $xml = $this->xml();
        if ($args->has('uncompressed')) {
            file_put_contents($out, $xml);
        } else {
            file_put_contents($out, gzencode($xml, 6));
        }
        $n = count(array_filter($this->nodes, static fn ($x) => 'ROOT' !== $x['type']));
        Out::step(sprintf('Written %s: %d accounts, %d transactions', $out, $n, count($this->transactions)));
        foreach ($this->warnings as $w => $count) {
            Out::warn(sprintf('%dx %s', $count, $w));
        }
        Out::info('Not exported (no GnuCash equivalent in a plain book): budgets, bills/subscriptions, piggy banks, rules, recurring transactions, attachments, tags (kept in the notes).');

        return 0;
    }

    private function warn(string $w): void
    {
        $this->warnings[$w] = ($this->warnings[$w] ?? 0) + 1;
    }

    private function dec(string $cur): int
    {
        return $this->decimals[$cur] ?? Decomposer::decimals($cur);
    }

    /** @return null|array{path:string, type:string, guid:string, cmdty:string} */
    private static function footer(?string $notes): ?array
    {
        if (null !== $notes && 1 === preg_match('/^\[GnuCash\] (.+) \| ([A-Z]+) \| ([0-9a-f]{32}) \| (\S+)\s*$/m', $notes, $m)) {
            return ['path' => $m[1], 'type' => $m[2], 'guid' => $m[3], 'cmdty' => $m[4]];
        }

        return null;
    }

    private static function stripFooter(?string $notes): string
    {
        $lines = array_filter(explode("\n", (string) $notes), static fn ($l) => !str_starts_with($l, '[GnuCash'));

        return trim(implode("\n", $lines));
    }

    private function addNode(string $key, string $path, string $type, string $cmdty, ?string $guid, array $extra = []): void
    {
        $parts = explode(':', $path);
        // two different Firefly objects must not end up in the same GnuCash account by accident
        foreach ($this->nodes as $k => $n) {
            if ($n['path'] === $path && $n['cmdty'] === $cmdty && $k !== $key && 'ROOT' !== $n['type'] && empty($n['synthetic'])) {
                $parts[count($parts) - 1] .= ' (2)';
                $path                      = implode(':', $parts);
            }
        }
        $this->nodes[$key] = $extra + [
            'guid' => $guid ?? Util::guid('node:'.$key), 'name' => end($parts), 'type' => $type, 'cmdty' => $cmdty,
            'path' => $path, 'placeholder' => false, 'hidden' => false, 'code' => '', 'description' => '', 'notes' => '',
        ];
    }

    private function buildAccounts(array $accounts): void
    {
        foreach ($accounts as $a) {
            $id    = (string) $a['id'];
            $at    = $a['attributes'];
            $type  = (string) $at['type'];
            $name  = (string) $at['name'];
            $notes = (string) ($at['notes'] ?? '');
            $this->accountName[$id] = $name;
            if (!in_array($type, ['asset', 'liabilities', 'liability', 'loan', 'debt', 'mortgage'], true)) {
                continue;
            }
            if (str_contains($notes, Importer::CLEARING_MARKER)) {
                $this->clearing[$id] = true;
            }
            $cur    = (string) ($at['currency_code'] ?? $this->bookCur);
            $f      = self::footer($notes);
            $isLiab = 'asset' !== $type;
            if (null !== $f) {
                $path  = $f['path'];
                $gtype = $f['type'];
                $guid  = $f['guid'];
            } else {
                $path  = ($isLiab ? self::ROOT_NAMES['liability'] : self::ROOT_NAMES['asset']).':'.str_replace(':', '-', $name);
                $gtype = $isLiab ? 'LIABILITY' : match ((string) ($at['account_role'] ?? '')) {
                    'cashWalletAsset' => 'CASH',
                    'ccAsset'         => 'CREDIT',
                    default           => 'BANK',
                };
                $guid = null;
            }
            $rest = self::stripFooter($notes);
            $desc = '';
            if ('' !== $rest && !str_contains($rest, "\n") && null !== $f) {
                [$desc, $rest] = [$rest, ''];
            } elseif (null !== $f && str_contains($rest, "\n\n")) {
                [$desc, $rest] = explode("\n\n", $rest, 2);
            }
            $key = 'acct:'.$id;
            $this->addNode($key, $path, $gtype, $cur, $guid, [
                'hidden' => !(bool) ($at['active'] ?? true), 'code' => (string) ($at['iban'] ?? $at['account_number'] ?? ''),
                'description' => $desc, 'notes' => $rest,
            ]);
            $this->accountNode[$id] = $key;
        }
    }

    private function buildCategories(array $categories, array $groups): void
    {
        // which categories are mostly used for withdrawals (-> expense) or deposits (-> income)
        $usage = [];
        foreach ($groups as $g) {
            foreach ($g['attributes']['transactions'] as $j) {
                if (null === ($j['category_name'] ?? null)) {
                    continue;
                }
                $l                     = Util::lower((string) $j['category_name']);
                $amt                   = (float) $j['amount'];
                $usage[$l][$j['type']] = ($usage[$l][$j['type']] ?? 0) + $amt;
            }
        }
        foreach ($categories as $c) {
            $name = (string) $c['attributes']['name'];
            $l    = Util::lower($name);
            $f    = self::footer((string) ($c['attributes']['notes'] ?? ''));
            if (null !== $f) {
                $this->categoryInfo[$l] = $f + ['name' => $name];

                continue;
            }
            $isIncome               = ($usage[$l]['deposit'] ?? 0) > ($usage[$l]['withdrawal'] ?? 0);
            $root                   = $isIncome ? self::ROOT_NAMES['income'] : self::ROOT_NAMES['expense'];
            $this->categoryInfo[$l] = ['path' => $root.':'.$name, 'type' => $isIncome ? 'INCOME' : 'EXPENSE', 'guid' => Util::guid('category:'.$c['id']), 'cmdty' => $this->bookCur, 'name' => $name];
        }
    }

    /** GnuCash node for the income/expense side of a journal. */
    private function plNode(array $j, ?string $cmdty): string
    {
        $cat = $j['category_name'] ?? null;
        if (null !== $cat && isset($this->categoryInfo[Util::lower((string) $cat)])) {
            $info = $this->categoryInfo[Util::lower((string) $cat)];
        } else {
            $isIncome = 'deposit' === $j['type'];
            $info     = ['path' => ($isIncome ? self::ROOT_NAMES['income'] : self::ROOT_NAMES['expense']).':Ohne Kategorie', 'type' => $isIncome ? 'INCOME' : 'EXPENSE',
                'guid'    => Util::guid('uncategorized:'.($isIncome ? 'in' : 'out')), 'cmdty' => $this->bookCur];
        }
        if (null === $cmdty || $cmdty === $info['cmdty']) {
            $key = 'pl:'.$info['guid'];
            if (!isset($this->nodes[$key])) {
                $this->addNode($key, $info['path'], $info['type'], $info['cmdty'], $info['guid']);
            }

            return $key;
        }
        // amount only known in another currency: separate account per currency
        $key = 'pl:'.$info['guid'].':'.$cmdty;
        if (!isset($this->nodes[$key])) {
            $this->addNode($key, $info['path'].' ('.$cmdty.')', $info['type'], $cmdty, Util::guid('pl:'.$info['guid'].':'.$cmdty));
        }

        return $key;
    }

    private function equityNode(string $what, string $cmdty): string
    {
        $base = 'opening' === $what ? 'Anfangsbestand' : 'Kontoabgleich';
        $path = $cmdty === $this->bookCur ? $base : $base.' - '.$cmdty;
        foreach ($this->categoryInfo as $info) {        // an imported GnuCash equity account with that name
            if ('EQUITY' === $info['type'] && $info['path'] === $path && $info['cmdty'] === $cmdty) {
                $key = 'pl:'.$info['guid'];
                if (!isset($this->nodes[$key])) {
                    $this->addNode($key, $info['path'], 'EQUITY', $cmdty, $info['guid']);
                }

                return $key;
            }
        }
        $key = 'eq:'.$path;
        if (!isset($this->nodes[$key])) {
            $this->addNode($key, $path, 'EQUITY', $cmdty, Util::guid('equity:'.$path));
        }

        return $key;
    }

    /** Node + role of one side of a journal. */
    private function sideNode(array $j, string $side, string $cmdtyHint): array
    {
        $id   = (string) $j[$side.'_id'];
        $type = (string) $j[$side.'_type'];
        if (isset($this->clearing[$id])) {
            return ['clearing:'.$id, 'clr'];
        }
        if (isset($this->accountNode[$id])) {
            return [$this->accountNode[$id], 'bs'];
        }
        if ('Initial balance account' === $type || 'Liability credit account' === $type) {
            return [$this->equityNode('opening', $cmdtyHint), 'eq'];
        }
        if ('Reconciliation account' === $type) {
            return [$this->equityNode('reconciliation', $cmdtyHint), 'eq'];
        }

        return ['', 'pl'];   // resolved via the category
    }

    private function buildTransactions(array $groups): void
    {
        // journals of one GnuCash transaction: same GnuCash GUID (external ID), else one Firefly group
        $bundles = [];
        foreach ($groups as $g) {
            foreach ($g['attributes']['transactions'] as $j) {
                $ext              = (string) ($j['external_id'] ?? '');
                $key              = Util::isGuid($ext) ? 'g:'.$ext : 'f:'.$g['id'];
                $j['_group']      = (string) $g['id'];
                $j['_title']      = (string) ($g['attributes']['group_title'] ?? '');
                $j['_created']    = (string) $g['attributes']['created_at'];
                $bundles[$key][]  = $j;
            }
        }
        foreach ($bundles as $key => $journals) {
            // transaction currency: most common journal currency; journals that cannot be
            // converted into it become their own GnuCash transaction
            $count = [];
            foreach ($journals as $j) {
                $count[$j['currency_code']] = ($count[$j['currency_code']] ?? 0) + 1;
            }
            arsort($count);
            $txCur = (string) array_key_first($count);
            $main  = [];
            $other = [];
            foreach ($journals as $j) {
                if ($j['currency_code'] === $txCur || ($j['foreign_currency_code'] ?? null) === $txCur) {
                    $main[] = $j;
                } else {
                    $other[$j['currency_code']][] = $j;
                }
            }
            $this->addTransaction($key, $txCur, $main);
            foreach ($other as $cur => $list) {
                $this->warn('Firefly transaction with several currencies split into several GnuCash transactions');
                $this->addTransaction($key.'#'.$cur, $cur, $list);
            }
        }
        usort($this->transactions, static fn ($a, $b) => [$a['date'], $a['entered'], $a['guid']] <=> [$b['date'], $b['entered'], $b['guid']]);
    }

    private function addTransaction(string $key, string $txCur, array $journals): void
    {
        $txDec   = $this->dec($txCur);
        $fromGnc = str_starts_with($key, 'g:');
        $first   = $journals[0];
        $desc    = '' !== $first['_title'] ? $first['_title'] : (string) $first['description'];
        $splits  = [];
        $memoLines = [];
        $notes   = '';
        $tags    = [];
        foreach ($journals as $idx => $j) {
            $jn = (string) ($j['notes'] ?? '');
            $plain = [];
            foreach (explode("\n", $jn) as $line) {
                if (1 === preg_match('/^GnuCash-Memo \[(Kategorie: )?(.+) (-?\d+(?:\.\d+)?)\]: ?(.*)$/u', $line, $m)) {
                    $memoLines[] = ['cat' => '' !== $m[1], 'name' => $m[2], 'amount' => $m[3], 'memo' => $m[4], 'used' => false];
                } else {
                    $plain[] = $line;
                }
            }
            $plainText = trim(implode("\n", $plain));
            if ('' !== $plainText) {
                $notes .= ('' === $notes ? '' : "\n\n").$plainText;
            }
            foreach ((array) ($j['tags'] ?? []) as $t) {
                if (!in_array($t, ['GnuCash-Import', 'GnuCash-Durchlauf'], true)) {
                    $tags[$t] = true;
                }
            }
            $cur   = (string) $j['currency_code'];
            $amt   = Util::decToMinor((string) $j['amount'], $this->dec($cur));
            $fCur  = $j['foreign_currency_code'] ?? null;
            $fAmt  = null !== $fCur && null !== ($j['foreign_amount'] ?? null) ? Util::decToMinor((string) $j['foreign_amount'], $this->dec((string) $fCur)) : null;
            $value = $cur === $txCur ? $amt : (int) $fAmt;
            $qtyIn = static function (string $cmdty) use ($cur, $amt, $fCur, $fAmt, $txCur, $value): ?int {
                if ($cmdty === $cur) {
                    return $amt;
                }
                if (null !== $fAmt && $cmdty === $fCur) {
                    return $fAmt;
                }

                return $cmdty === $txCur ? $value : null;
            };
            $recon = (bool) ($j['reconciled'] ?? false);
            foreach (['source' => -1, 'destination' => 1] as $side => $sign) {
                [$node, $role] = $this->sideNode($j, $side, 'source' === $side ? $cur : ($fCur ?? $cur));
                if ('pl' === $role) {
                    $node = $this->plNode($j, null);
                    $q    = $qtyIn($this->nodes[$node]['cmdty']);
                    if (null === $q) {
                        $node = $this->plNode($j, $cur);
                        $q    = $amt;
                    }
                    $memo = (string) $j['description'] !== $desc ? (string) $j['description'] : '';
                    if (!$fromGnc && '' === $memo) {
                        $payee = (string) ('source' === $side ? $j['source_name'] : $j['destination_name']);
                        if ('' !== $payee && $payee !== $this->fallbackPayee && '(cash)' !== $payee && !str_contains(Util::lower($desc), Util::lower($payee))) {
                            $memo = $payee;
                        }
                    }
                    $splits[] = ['node' => $node, 'role' => 'pl', 'value' => $sign * $value, 'qty' => $sign * $q, 'memo' => $memo, 'recon' => false, 'j' => $idx];

                    continue;
                }
                if ('clr' === $role) {
                    $this->nodes[$node] ??= ['guid' => Util::guid('node:'.$node), 'name' => $this->accountName[substr($node, 9)] ?? 'GnuCash-Umbuchungen', 'type' => 'ASSET',
                        'cmdty' => $txCur, 'path' => self::ROOT_NAMES['asset'].':'.($this->accountName[substr($node, 9)] ?? 'GnuCash-Umbuchungen'), 'placeholder' => false,
                        'hidden' => false, 'code' => '', 'description' => '', 'notes' => '', 'unused' => true];
                }
                $q = $qtyIn($this->nodes[$node]['cmdty']);
                if (null === $q) {
                    $this->warn(sprintf('amount of "%s" not available in %s - value used', $this->nodes[$node]['path'], $this->nodes[$node]['cmdty']));
                    $q = $value;
                }
                $splits[] = ['node' => $node, 'role' => $role, 'value' => $sign * $value, 'qty' => $sign * $q, 'memo' => '', 'recon' => $recon, 'j' => $idx];
            }
        }

        // balance-sheet sides: net per account; the original GnuCash splits are restored from the
        // "GnuCash-Memo" lines, a remaining difference becomes one more split without memo
        $out   = [];
        $byAcc = [];
        foreach ($splits as $s) {
            if ('pl' !== $s['role']) {
                $byAcc[$s['node']][] = $s;
            }
        }
        foreach ($byAcc as $node => $list) {
            $netV  = array_sum(array_column($list, 'value'));
            $netQ  = array_sum(array_column($list, 'qty'));
            $recon = !in_array(false, array_column($list, 'recon'), true);
            if ('clr' === $list[0]['role']) {
                if (0 === $netV && 0 === $netQ) {
                    continue;   // technical clearing account: nets to zero within the transaction
                }
                unset($this->nodes[$node]['unused']);
            }
            $name = 'acct:' === substr($node, 0, 5) ? ($this->accountName[substr($node, 5)] ?? null) : null;
            $dec  = $this->dec((string) $this->nodes[$node]['cmdty']);
            $same = $this->nodes[$node]['cmdty'] === $txCur;
            $restV = $netV;
            $restQ = $netQ;
            foreach ($memoLines as $k => $ml) {
                if ($ml['used'] || $ml['cat'] || null === $name || $ml['name'] !== $name) {
                    continue;
                }
                $q = Util::decToMinor($ml['amount'], $dec);
                $v = $same ? $q : (0 === $netQ ? 0 : Util::share($netV, $q, $netQ));
                $out[]  = ['node' => $node, 'value' => $v, 'qty' => $q, 'memo' => $ml['memo'], 'recon' => 0 !== $q && $recon];
                $restV -= $v;
                $restQ -= $q;
                $memoLines[$k]['used'] = true;
            }
            if (0 !== $restV || 0 !== $restQ) {
                $out[] = ['node' => $node, 'value' => $restV, 'qty' => $restQ, 'memo' => '', 'recon' => $recon];
            }
        }
        // income/expense sides: one split per journal; equal ones paid from different accounts
        // are one GnuCash split that the import divided among its source accounts
        $counter = [];
        foreach ($splits as $s) {
            if ('pl' !== $s['role']) {
                $counter[$s['j']] = $s['node'];
            }
        }
        $pl = [];
        foreach ($splits as $s) {
            if ('pl' !== $s['role']) {
                continue;
            }
            $base = $s['node'].'|'.$s['memo'].'|'.($s['value'] < 0 ? '-' : '+');
            $c    = $counter[$s['j']] ?? '';
            $n    = 0;
            while (isset($pl[$base.'#'.$n]) && isset($pl[$base.'#'.$n]['counters'][$c])) {
                ++$n;
            }
            $k = $base.'#'.$n;
            if (isset($pl[$k])) {
                $pl[$k]['value']         += $s['value'];
                $pl[$k]['qty']           += $s['qty'];
                $pl[$k]['counters'][$c]   = true;
            } else {
                $pl[$k] = $s + ['counters' => [$c => true]];
            }
        }
        $plOut = [];
        foreach ($pl as $s) {
            $catName = $this->categoryNameForPath((string) ($this->nodes[$s['node']]['path'] ?? ''));
            if ('' === $s['memo'] && null !== $catName) {
                $dec = $this->dec((string) $this->nodes[$s['node']]['cmdty']);
                foreach ($memoLines as $k => $ml) {
                    if (!$ml['used'] && $ml['cat'] && $ml['name'] === $catName && Util::decToMinor($ml['amount'], $dec) === $s['qty']) {
                        $s['memo']             = $ml['memo'];
                        $memoLines[$k]['used'] = true;

                        break;
                    }
                }
            }
            $plOut[] = ['node' => $s['node'], 'value' => $s['value'], 'qty' => $s['qty'], 'memo' => $s['memo'], 'recon' => false];
        }
        $out = array_merge($plOut, $out);
        // zero-amount GnuCash splits; memos that cannot be placed go into the notes
        foreach ($memoLines as $ml) {
            if ($ml['used']) {
                continue;
            }
            $node = null;
            foreach ($this->nodes as $k => $n) {
                $isAcc = 'acct:' === substr($k, 0, 5) && ($this->accountName[substr($k, 5)] ?? null) === $ml['name'];
                $isCat = 'pl:' === substr($k, 0, 3) && $this->categoryNameForPath($n['path']) === $ml['name'];
                if (($ml['cat'] && $isCat) || (!$ml['cat'] && $isAcc)) {
                    $node = $k;

                    break;
                }
            }
            if (null === $node && $ml['cat']) {
                foreach ($this->categoryInfo as $info) {
                    if ($info['name'] === $ml['name']) {
                        $node = 'pl:'.$info['guid'];
                        if (!isset($this->nodes[$node])) {
                            $this->addNode($node, $info['path'], $info['type'], $info['cmdty'], $info['guid']);
                        }

                        break;
                    }
                }
            }
            if (null !== $node && 0 === Util::decToMinor($ml['amount'], 2) && '' !== $ml['memo']) {
                $out[] = ['node' => $node, 'value' => 0, 'qty' => 0, 'memo' => $ml['memo'], 'recon' => false];
            } else {
                $notes .= ('' === $notes ? '' : "\n").sprintf('Memo %s %s: %s', $ml['name'], $ml['amount'], $ml['memo']);
            }
        }
        if ([] !== $tags) {
            $notes .= ('' === $notes ? '' : "\n\n").'Tags: '.implode(', ', array_keys($tags));
        }
        if ([] === $out) {
            return;
        }
        $guid = $fromGnc && !str_contains($key, '#') ? substr($key, 2, 32) : Util::guid('tx:'.$key);
        $created = (new \DateTimeImmutable((string) $first['_created']))->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s +0000');
        $this->transactions[] = [
            'guid'    => $guid, 'currency' => $txCur, 'date' => substr((string) $first['date'], 0, 10), 'entered' => $created,
            'num'     => (string) ($first['internal_reference'] ?? ''), 'description' => $desc, 'notes' => $notes, 'splits' => $out,
        ];
    }

    /** Category name of an imported category node path (for GnuCash-Memo lines). */
    private function categoryNameForPath(string $path): ?string
    {
        foreach ($this->categoryInfo as $info) {
            if ($info['path'] === $path) {
                return $info['name'];
            }
        }

        return null;
    }

    /** Create placeholder parents for all account paths. */
    private function addParents(): void
    {
        // unused clearing nodes are dropped
        foreach ($this->nodes as $k => $n) {
            if (!empty($n['unused'])) {
                unset($this->nodes[$k]);
            }
        }
        $classOf = static fn (string $t): string => match ($t) {
            'LIABILITY', 'CREDIT', 'PAYABLE' => 'LIABILITY',
            'INCOME'  => 'INCOME',
            'EXPENSE' => 'EXPENSE',
            'EQUITY'  => 'EQUITY',
            'TRADING' => 'TRADING',
            default   => 'ASSET',
        };
        // type of synthesized parents: majority class below the same top-level name
        $votes = [];
        foreach ($this->nodes as $n) {
            if ('ROOT' === $n['type']) {
                continue;
            }
            $top                                      = explode(':', $n['path'])[0];
            $votes[$top][$classOf($n['type'])] = ($votes[$top][$classOf($n['type'])] ?? 0) + 1;
        }
        $byPath = [];
        foreach ($this->nodes as $k => $n) {
            $byPath[$n['path']] ??= $k;
        }
        $keys = array_keys($this->nodes);
        foreach ($keys as $k) {
            $n = $this->nodes[$k];
            if ('ROOT' === $n['type']) {
                continue;
            }
            $parts  = explode(':', $n['path']);
            $parent = 'root';
            for ($i = 1, $c = count($parts); $i < $c; ++$i) {
                $pp = implode(':', array_slice($parts, 0, $i));
                if (!isset($byPath[$pp])) {
                    $v = $votes[$parts[0]] ?? ['ASSET' => 1];
                    arsort($v);
                    $pk                = 'parent:'.$pp;
                    $this->nodes[$pk]  = ['guid' => Util::guid('path:'.$pp), 'name' => $parts[$i - 1], 'type' => (string) array_key_first($v), 'cmdty' => $this->bookCur,
                        'path'             => $pp, 'placeholder' => true, 'hidden' => false, 'code' => '', 'description' => '', 'notes' => '', 'parent' => $parent, 'synthetic' => true];
                    $byPath[$pp]       = $pk;
                }
                $parent = $byPath[$pp];
            }
            $this->nodes[$k]['parent'] = $parent;
        }
    }

    private function xml(): string
    {
        $w = new \XMLWriter();
        $w->openMemory();
        $w->setIndent(true);
        $w->setIndentString('  ');
        $w->startDocument('1.0', 'utf-8');
        $w->startElement('gnc-v2');
        foreach (['gnc', 'act', 'book', 'cd', 'cmdty', 'price', 'slot', 'split', 'sx', 'trn', 'ts', 'fs', 'bgt', 'recurrence', 'lot', 'addr', 'billterm', 'bt-days', 'bt-prox',
            'cust', 'employee', 'entry', 'invoice', 'job', 'order', 'owner', 'taxtable', 'tte', 'vendor'] as $ns) {
            $w->writeAttribute('xmlns:'.$ns, 'http://www.gnucash.org/XML/'.$ns);
        }
        $count = static function (\XMLWriter $w, string $type, int $n): void {
            $w->startElement('gnc:count-data');
            $w->writeAttribute('cd:type', $type);
            $w->text((string) $n);
            $w->endElement();
        };
        $count($w, 'book', 1);
        $w->startElement('gnc:book');
        $w->writeAttribute('version', '2.0.0');
        $this->guidElement($w, 'book:id', Util::guid('book'));
        $currencies = [];
        foreach ($this->nodes as $n) {
            $currencies[$n['cmdty']] = true;
        }
        foreach ($this->transactions as $t) {
            $currencies[$t['currency']] = true;
        }
        ksort($currencies);
        $count($w, 'commodity', count($currencies));
        $count($w, 'account', count($this->nodes));
        $count($w, 'transaction', count($this->transactions));
        foreach (array_keys($currencies) as $cur) {
            $w->startElement('gnc:commodity');
            $w->writeAttribute('version', '2.0.0');
            $w->writeElement('cmdty:space', 'CURRENCY');
            $w->writeElement('cmdty:id', (string) $cur);
            $w->writeElement('cmdty:get_quotes');
            $w->writeElement('cmdty:quote_source', 'currency');
            $w->writeElement('cmdty:quote_tz');
            $w->endElement();
        }
        $w->startElement('gnc:commodity');
        $w->writeAttribute('version', '2.0.0');
        $w->writeElement('cmdty:space', 'template');
        $w->writeElement('cmdty:id', 'template');
        $w->writeElement('cmdty:name', 'template');
        $w->writeElement('cmdty:xcode', 'template');
        $w->writeElement('cmdty:fraction', '1');
        $w->endElement();

        // accounts, parents first
        $ordered = [];
        $visit   = function (string $key) use (&$visit, &$ordered): void {
            if (isset($ordered[$key])) {
                return;
            }
            $p = $this->nodes[$key]['parent'] ?? null;
            if (null !== $p && isset($this->nodes[$p])) {
                $visit($p);
            }
            $ordered[$key] = true;
        };
        $keys = array_keys($this->nodes);
        usort($keys, fn ($a, $b) => strcmp($this->nodes[$a]['path'], $this->nodes[$b]['path']));
        foreach ($keys as $k) {
            $visit($k);
        }
        foreach (array_keys($ordered) as $k) {
            $n = $this->nodes[$k];
            $w->startElement('gnc:account');
            $w->writeAttribute('version', '2.0.0');
            $w->writeElement('act:name', (string) $n['name']);
            $this->guidElement($w, 'act:id', (string) $n['guid']);
            $w->writeElement('act:type', (string) $n['type']);
            $w->startElement('act:commodity');
            $w->writeElement('cmdty:space', 'CURRENCY');
            $w->writeElement('cmdty:id', (string) $n['cmdty']);
            $w->endElement();
            $w->writeElement('act:commodity-scu', (string) Util::fractionForDecimals($this->dec((string) $n['cmdty'])));
            if ('' !== ($n['code'] ?? '')) {
                $w->writeElement('act:code', (string) $n['code']);
            }
            if ('' !== ($n['description'] ?? '')) {
                $w->writeElement('act:description', (string) $n['description']);
            }
            $slots = [];
            if (!empty($n['placeholder'])) {
                $slots['placeholder'] = 'true';
            }
            if (!empty($n['hidden'])) {
                $slots['hidden'] = 'true';
            }
            if ('' !== ($n['notes'] ?? '')) {
                $slots['notes'] = (string) $n['notes'];
            }
            $this->slots($w, 'act:slots', $slots);
            if (null !== ($n['parent'] ?? null) && 'ROOT' !== $n['type']) {
                $this->guidElement($w, 'act:parent', (string) $this->nodes[$n['parent']]['guid']);
            }
            $w->endElement();
        }

        foreach ($this->transactions as $t) {
            $txDec = $this->dec($t['currency']);
            $w->startElement('gnc:transaction');
            $w->writeAttribute('version', '2.0.0');
            $this->guidElement($w, 'trn:id', $t['guid']);
            $w->startElement('trn:currency');
            $w->writeElement('cmdty:space', 'CURRENCY');
            $w->writeElement('cmdty:id', $t['currency']);
            $w->endElement();
            if ('' !== $t['num']) {
                $w->writeElement('trn:num', $t['num']);
            }
            $w->startElement('trn:date-posted');
            $w->writeElement('ts:date', $t['date'].' 10:59:00 +0000');
            $w->endElement();
            $w->startElement('trn:date-entered');
            $w->writeElement('ts:date', $t['entered']);
            $w->endElement();
            $w->writeElement('trn:description', $t['description']);
            $w->startElement('trn:slots');
            $w->startElement('slot');
            $w->writeElement('slot:key', 'date-posted');
            $w->startElement('slot:value');
            $w->writeAttribute('type', 'gdate');
            $w->writeElement('gdate', $t['date']);
            $w->endElement();
            $w->endElement();
            if ('' !== $t['notes']) {
                $w->startElement('slot');
                $w->writeElement('slot:key', 'notes');
                $w->startElement('slot:value');
                $w->writeAttribute('type', 'string');
                $w->text($t['notes']);
                $w->endElement();
                $w->endElement();
            }
            $w->endElement();
            $w->startElement('trn:splits');
            foreach ($t['splits'] as $i => $s) {
                $n   = $this->nodes[$s['node']];
                $dec = $this->dec((string) $n['cmdty']);
                $w->startElement('trn:split');
                $this->guidElement($w, 'split:id', Util::guid('split:'.$t['guid'].':'.$i));
                if ('' !== $s['memo']) {
                    $w->writeElement('split:memo', $s['memo']);
                }
                $state = $s['recon'] ? $this->reconciledState : 'n';
                $w->writeElement('split:reconciled-state', $state);
                if ('y' === $state) {
                    $w->startElement('split:reconcile-date');
                    $w->writeElement('ts:date', $t['date'].' 10:59:00 +0000');
                    $w->endElement();
                }
                $w->writeElement('split:value', $s['value'].'/'.Util::fractionForDecimals($txDec));
                $w->writeElement('split:quantity', $s['qty'].'/'.Util::fractionForDecimals($dec));
                $this->guidElement($w, 'split:account', (string) $n['guid']);
                $w->endElement();
            }
            $w->endElement();
            $w->endElement();
        }
        $w->endElement(); // gnc:book
        $w->endElement(); // gnc-v2
        $w->endDocument();

        return $w->outputMemory();
    }

    private function guidElement(\XMLWriter $w, string $name, string $guid): void
    {
        $w->startElement($name);
        $w->writeAttribute('type', 'guid');
        $w->text($guid);
        $w->endElement();
    }

    private function slots(\XMLWriter $w, string $name, array $slots): void
    {
        if ([] === $slots) {
            return;
        }
        $w->startElement($name);
        foreach ($slots as $k => $v) {
            $w->startElement('slot');
            $w->writeElement('slot:key', (string) $k);
            $w->startElement('slot:value');
            $w->writeAttribute('type', 'string');
            $w->text((string) $v);
            $w->endElement();
            $w->endElement();
        }
        $w->endElement();
    }
}

// =====================================================================================
// Compare two GnuCash books (verification of import/export round trips)
// =====================================================================================

final class Comparer
{
    public static function run(Args $args, \DateTimeZone $tz): int
    {
        $args->check(['by-year', 'timezone', 'verbose', 'quiet', 'ignore']);
        $a = $args->positional[1] ?? null;
        $b = $args->positional[2] ?? null;
        if (null === $a || null === $b) {
            throw new UserError('compare needs two GnuCash files');
        }
        $byYear = $args->has('by-year');
        $ignore = null !== $args->get('ignore') && '' !== $args->get('ignore') ? (string) $args->get('ignore') : null;
        $ba     = self::balances(BookReader::read($a, $tz), $byYear, $ignore);
        $bb     = self::balances(BookReader::read($b, $tz), $byYear, $ignore);
        $keys   = array_unique(array_merge(array_keys($ba), array_keys($bb)));
        sort($keys);
        $diff = 0;
        foreach ($keys as $k) {
            $x = $ba[$k] ?? 0;
            $y = $bb[$k] ?? 0;
            if ($x !== $y) {
                ++$diff;
                [$path, $cur] = explode("\t", $k) + [1 => ''];
                Out::info(sprintf('%-70s %14s %14s  %s', Util::truncate($path, 70), Util::minorToDec($x, 2), Util::minorToDec($y, 2), $cur));
            }
        }
        if (0 === $diff) {
            Out::info(sprintf('Identical: %d account balances%s match.', count($keys), $byYear ? ' (per year)' : ''));

            return 0;
        }
        Out::info(sprintf('%d of %d balances differ (columns: %s | %s)', $diff, count($keys), basename($a), basename($b)));

        return 1;
    }

    /** @return array<string, int> "path\tcommodity[\tyear]" => quantity in 1/100 */
    private static function balances(Book $book, bool $byYear, ?string $ignore): array
    {
        $out = [];
        foreach ($book->transactions as $t) {
            foreach ($t->splits as $s) {
                $acc = $book->account($s->account);
                if ('TRADING' === $acc->type || (null !== $ignore && 1 === preg_match($ignore, $acc->path))) {
                    continue;
                }
                $q = Util::fracToMinor($s->quantity, 100);
                if (0 === $q) {
                    continue;
                }
                $k       = $acc->path."\t".$acc->cmdtyId.($byYear ? "\t".substr($t->date, 0, 4) : '');
                $out[$k] = ($out[$k] ?? 0) + $q;
            }
        }

        return array_filter($out, static fn ($v) => 0 !== $v);
    }
}

// =====================================================================================
// Purge what an import created (test runs)
// =====================================================================================

final class Purger
{
    public static function run(Args $args): int
    {
        $args->check(['tag', 'accounts', 'yes', 'dry-run', 'url', 'token', 'token-file', 'cacert', 'timeout', 'verbose', 'quiet']);
        $api = FireflyClient::fromArgs($args);
        $tag = $args->get('tag') ?? 'GnuCash-Import';
        Out::step(sprintf('Looking for transactions with tag "%s"%s', $tag, $args->has('accounts') ? ' and for accounts/categories created by the import' : ''));
        $tagged = 0;
        try {
            $tagged = (int) ($api->get('tags/'.rawurlencode($tag).'/transactions', ['limit' => 1])['meta']['pagination']['total'] ?? 0);
        } catch (ApiError $e) {
            if (404 !== $e->status) {
                throw $e;
            }
        }
        $accounts = [];
        $cats     = [];
        if ($args->has('accounts')) {
            foreach ($api->all('accounts', ['type' => 'all']) as $a) {
                $notes = (string) ($a['attributes']['notes'] ?? '');
                if (str_contains($notes, '[GnuCash] ') || str_contains($notes, Importer::PAYEE_MARKER) || str_contains($notes, Importer::CLEARING_MARKER)) {
                    $accounts[] = [(string) $a['id'], (string) $a['attributes']['name']];
                }
            }
            foreach ($api->all('categories') as $c) {
                if (str_contains((string) ($c['attributes']['notes'] ?? ''), '[GnuCash] ')) {
                    $cats[] = (string) $c['id'];
                }
            }
        }
        Out::info(sprintf('  %d transactions, %d accounts (their transactions are deleted with them), %d categories', $tagged, count($accounts), count($cats)));
        if (0 === $tagged && [] === $accounts && [] === $cats) {
            Out::info('Nothing to delete.');

            return 0;
        }
        if ($args->has('dry-run')) {
            Out::info('Dry run - nothing was deleted.');

            return 0;
        }
        if (!Out::confirm('Delete them permanently in Firefly?', $args->has('yes'))) {
            return 1;
        }
        // accounts first: Firefly deletes their transactions with them, which is much faster
        $n = 0;
        foreach ($accounts as [$id, $name]) {
            try {
                $api->delete('accounts/'.$id);
            } catch (ApiError $e) {
                if (404 !== $e->status) {
                    Out::warn(sprintf('account "%s" (#%s): %s', $name, $id, $e->getMessage()));
                }
            }
            if (0 === ++$n % 100) {
                Out::info(sprintf('  %d/%d accounts deleted', $n, count($accounts)));
            }
        }
        foreach ($cats as $id) {
            try {
                $api->delete('categories/'.$id);
            } catch (ApiError $e) {
                if (404 !== $e->status) {
                    Out::warn(sprintf('category #%s: %s', $id, $e->getMessage()));
                }
            }
        }
        // remaining tagged transactions (e.g. on accounts that existed before the import)
        $ids = [];
        try {
            foreach ($api->all('tags/'.rawurlencode($tag).'/transactions') as $g) {
                $ids[(int) $g['id']] = true;
            }
        } catch (ApiError $e) {
            if (404 !== $e->status) {
                throw $e;
            }
        }
        $n = 0;
        foreach (array_keys($ids) as $id) {
            try {
                $api->delete('transactions/'.$id);
            } catch (ApiError $e) {
                if (404 !== $e->status) {
                    throw $e;
                }
            }
            if (0 === ++$n % 500) {
                Out::info(sprintf('  %d/%d transactions deleted', $n, count($ids)));
            }
        }
        try {
            $api->delete('tags/'.rawurlencode($tag));
        } catch (ApiError) {
        }
        Out::info('Done.');

        return 0;
    }
}

// =====================================================================================
// firefly-rules: payee rules -> Firefly III rule group
// =====================================================================================

final class RuleExporter
{
    public const GROUP = 'GnuCash-Import';

    public static function run(Args $args, \DateTimeZone $tz, string $bookFile): int
    {
        $args->check(['config', 'rules', 'timezone', 'verbose', 'quiet', 'dry-run', 'yes', 'group', 'approx', 'keep-conflicts', 'report-json', 'url', 'token', 'token-file', 'cacert', 'timeout']);
        $group = trim((string) ($args->get('group') ?? ''));
        $group = '' === $group ? self::GROUP : Util::truncate($group, 100);
        $p     = new Pipeline($bookFile, $args, $tz);
        $p->run();
        $accounts = [];
        foreach ($p->config->accounts as $m) {
            if (isset($m['gnucash'])) {
                $accounts[(string) $m['gnucash']] = $m;
            }
        }
        $approx = $args->has('approx');
        $tr     = new FireflyRuleTranslator($accounts, (string) $p->config->opt('payee_fallback'));
        $res    = $tr->translate($p->rules, $approx);
        $cases  = [];
        foreach ($p->payees->usages() as $key => $u) {
            /** @var GTransaction $t */
            $t       = $u['tx'];
            $r       = $p->payees->get($key);
            $cases[] = ['tx' => $t->guid, 'desc' => Util::collapse($t->description), 'side' => $u['side'], 'category' => (string) $u['category'],
                'accounts' => $u['accounts'] ?? [], 'ibans' => PayeeResolver::ibansOf($t), 'name' => $r['name'], 'source' => $r['source']];
        }
        $sim = FireflyRuleTranslator::simulate($res['specs'], $cases);
        // a Firefly rule that would give bookings another counterparty than the import (an
        // earlier payee rule is missing in Firefly, or an approximation catches more) is left
        // out - unless --keep-conflicts. Leaving one out can expose a later one: repeat.
        while (!$args->has('keep-conflicts')) {
            $bad = array_filter($sim['lines'], static fn ($l) => $l['other'] > 0);
            if ([] === $bad) {
                break;
            }
            foreach ($res['rules'] as &$r) {
                if (isset($bad[$r['line']])) {
                    $against     = $bad[$r['line']]['against'];
                    arsort($against);
                    $r['status']  = 'conflict';
                    $r['reason']  = 'conflict';
                    $r['detail']  = (string) $bad[$r['line']]['other'];
                    $r['against'] = $against;
                    $r['firefly'] = 0;
                }
            }
            unset($r);
            $res['specs'] = array_values(array_filter($res['specs'], static fn ($sp) => !isset($bad[$sp['line']])));
            $sim          = FireflyRuleTranslator::simulate($res['specs'], $cases);
        }

        Out::step(sprintf('Payee rules -> Firefly III rules (%s, group "%s")', $p->rulesFile, $group));
        $count = ['ok' => 0, 'approx' => 0, 'approx_skipped' => 0, 'skipped' => 0, 'conflict' => 0];
        foreach ($res['rules'] as $r) {
            ++$count[$r['status']];
            $what = match ($r['status']) {
                'ok'             => sprintf('%d Firefly rule%s', $r['firefly'], 1 === $r['firefly'] ? '' : 's'),
                'approx'         => sprintf('%d Firefly rule%s, approximated: %s', $r['firefly'], 1 === $r['firefly'] ? '' : 's', implode('; ', array_map(static fn ($c) => FireflyRuleTranslator::APPROX[$c] ?? $c, $r['approx']))),
                'approx_skipped' => 'only approximately possible (--approx): '.implode('; ', array_map(static fn ($c) => FireflyRuleTranslator::APPROX[$c] ?? $c, $r['approx'])),
                'conflict'       => sprintf('left out (--keep-conflicts): would give %s bookings another counterparty than the import (%s)', $r['detail'],
                    implode(', ', array_map(static fn ($k, $v) => sprintf('%d from %s', $v, ctype_digit((string) $k) ? 'line '.$k : $k), array_keys($r['against']), $r['against']))),
                default          => 'not translatable: '.FireflyRuleTranslator::reasonText($r['reason'], $r['detail']),
            };
            if ('ok' === $r['status'] && !Out::$verbose) {
                continue;
            }
            Out::info(sprintf('  line %-4d %s', $r['line'], Util::truncate($r['src'], 110)));
            Out::info('            -> '.$what);
        }
        Out::info(sprintf('  %d of %d payee rules -> %d Firefly rules (%d approximated); %d only approximately possible, %d not translatable, %d left out (conflicts)',
            $count['ok'] + $count['approx'], count($res['rules']), count($res['specs']), $count['approx'], $count['approx_skipped'], $count['skipped'], $count['conflict']));

        Out::step(sprintf('Check against the book (%d transactions with a counterparty)', $sim['total']));
        Out::info(sprintf('  same counterparty as the import:          %6d', $sim['same']));
        Out::info(sprintf('  another counterparty than the import:     %6d', $sim['other']));
        Out::info(sprintf('  no Firefly rule (payee rule not exported): %5d', $sim['missing']));
        Out::info(sprintf('  no Firefly rule (automatic name/fallback): %5d', $sim['free']));
        foreach (array_slice($sim['examples'], 0, 10) as $e) {
            Out::warn(sprintf('%dx "%s" (%s): import "%s" (%s), Firefly "%s" (line %d)', $e['count'], Util::truncate($e['desc'], 60), $e['side'], $e['ours'], $e['source'], $e['firefly'], $e['line']));
        }

        $report = ['version' => VERSION, 'group' => $group, 'approx' => $approx, 'keep_conflicts' => $args->has('keep-conflicts'), 'rules_file' => basename($p->rulesFile), 'rules' => $res['rules'],
            'specs' => array_map(static fn ($s) => array_diff_key($s, ['sim' => true]), $res['specs']), 'sim' => $sim, 'firefly' => null];
        $write  = static function () use ($args, &$report): void {
            if (null !== ($json = $args->get('report-json')) && '' !== $json) {
                file_put_contents($json, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)."\n");
            }
        };
        $write();
        if ($args->has('dry-run')) {
            Out::info(sprintf('Dry run - nothing was sent to Firefly (%d rules would be created in group "%s").', count($res['specs']), $group));

            return 0;
        }
        if ([] === $res['specs']) {
            Out::info('Nothing to send: no payee rule can be expressed as a Firefly rule.');

            return 0;
        }
        $api = FireflyClient::fromArgs($args);
        Out::step(sprintf('Firefly rule group "%s"', $group));
        $gid = null;
        foreach ($api->all('rule-groups') as $g) {
            if ((string) ($g['attributes']['title'] ?? '') === $group) {
                $gid = (string) $g['id'];

                break;
            }
        }
        $old = [];
        if (null !== $gid) {
            foreach ($api->all('rule-groups/'.$gid.'/rules') as $rule) {
                $old[] = (string) $rule['id'];
            }
        }
        Out::info(null === $gid ? '  new rule group' : sprintf('  exists with %d rules - they are replaced', count($old)));
        $question = sprintf('Create %d Firefly rules in rule group "%s"%s?', count($res['specs']), $group, [] === $old ? '' : sprintf(' (replaces its %d rules)', count($old)));
        if (!Out::confirm($question, $args->has('yes'))) {
            return 1;
        }
        $desc = sprintf('Created by firefly-gnucash from %s - "firefly-rules" replaces all rules of this group. Order matters: the first matching rule wins (stop processing).', basename($p->rulesFile));
        if (null === $gid) {
            $gid = (string) ($api->post('rule-groups', ['title' => $group, 'description' => $desc, 'active' => true])['data']['id'] ?? '');
            if ('' === $gid) {
                throw new ApiError('Firefly did not return the new rule group');
            }
        }
        foreach ($old as $id) {
            try {
                $api->delete('rules/'.$id);
            } catch (ApiError $e) {
                if (404 !== $e->status) {
                    throw $e;
                }
            }
        }
        $taken = [];
        foreach ($api->all('rules') as $rule) {
            $taken[Util::lower((string) ($rule['attributes']['title'] ?? ''))] = true;   // titles are unique per user
        }
        Out::step(sprintf('Creating %d Firefly rules', count($res['specs'])));
        $srcByLine = array_column($res['rules'], 'src', 'line');
        $n         = 0;
        foreach ($res['specs'] as $s) {
            $title = $s['title'];
            for ($k = 2; isset($taken[Util::lower($title)]); ++$k) {
                $title = Util::truncate($s['title'], 94).' #'.$k;
            }
            $taken[Util::lower($title)] = true;
            $api->post('rules', [
                'title'           => $title,
                'description'     => Util::truncate(sprintf("firefly-gnucash, %s line %d:\n%s", basename($p->rulesFile), $s['line'], $srcByLine[$s['line']] ?? ''), 32000),
                'rule_group_id'   => $gid,
                'trigger'         => 'store-journal',
                'active'          => true,
                'strict'          => true,
                'stop_processing' => true,
                'triggers'        => array_map(static fn ($t) => $t + ['active' => true, 'stop_processing' => false], $s['triggers']),
                'actions'         => [$s['action'] + ['active' => true, 'stop_processing' => false]],
            ]);
            ++$n;
            if (0 === $n % 10 || $n === count($res['specs'])) {
                Out::info(sprintf('  %d/%d rules created', $n, count($res['specs'])));
            }
        }
        $report['firefly'] = ['group_id' => $gid, 'created' => $n, 'replaced' => count($old)];
        $write();
        Out::info(sprintf('Done: %d Firefly rules in rule group "%s"%s. They act on new transactions (Firefly: Rules > "%s" can also apply them to existing ones).', $n, $group, [] === $old ? '' : sprintf(' (%d old rules replaced)', count($old)), $group));

        return 0;
    }
}

// @@END@@

// =====================================================================================
// Commands
// =====================================================================================

final class App
{
    public const HELP = <<<'TXT'
        firefly-gnucash VERSION - GnuCash <-> Firefly III via the Firefly III API

        Usage:
          php firefly-gnucash.php plan    BOOK.gnucash [--config=FILE] [--rules=FILE] [--summary-json=FILE]
          php firefly-gnucash.php import  BOOK.gnucash [--dry-run] [--yes] [--from=DATE] [--to=DATE] [--limit=N] [--update-payees]
          php firefly-gnucash.php export  OUT.gnucash  [--from=DATE] [--to=DATE] [--uncompressed]
          php firefly-gnucash.php compare A.gnucash B.gnucash [--by-year]
          php firefly-gnucash.php purge   [--tag=TAG] [--accounts] [--dry-run] [--yes]
          php firefly-gnucash.php firefly-rules BOOK.gnucash [--dry-run] [--yes] [--group=TITLE] [--approx] [--keep-conflicts] [--report-json=FILE]

        plan     Reads the GnuCash book (XML/compressed XML/SQLite) and writes next to it:
                   BOOK.import.json      account mapping + options (edit, then run plan again)
                   BOOK.payee-rules.txt  rules that merge booking texts into counterparties
                   BOOK.payees.csv       resulting counterparties (one row per payee)
                   BOOK.payee-map.csv    booking text -> counterparty (one row per text)
                   BOOK.payee-suggestions.txt  proposed rules to merge similar counterparties
                 Nothing is sent to Firefly. Includes a self-check of all amounts.
        import   Everything "plan" does, then creates currencies, accounts, categories,
                 counterparties and transactions in Firefly III. Transactions carry the
                 GnuCash GUID as external ID: a second run skips what already exists and
                 re-creates half-imported ones or ones with changed amounts (safe to restart
                 after an abort). Counterparties of transactions that are already in Firefly
                 are only changed with --update-payees (after editing the payee rules).
        export   Writes all Firefly III transactions as a GnuCash XML book (gzip unless
                 --uncompressed). Accounts/categories created by "import" get their
                 original GnuCash account path and GUID back.
        compare  Compares the account balances of two GnuCash books (e.g. the original and
                 an export after import), --by-year also per year. Exit code 1 on differences.
        purge    Deletes the transactions with the import tag (default "GnuCash-Import");
                 --accounts also deletes the accounts, counterparties and categories the
                 import created. For test runs.
        firefly-rules  Translates the payee rules into Firefly III rules for new transactions
                 (one rule group, default "GnuCash-Import"; a second run replaces its rules).
                 Firefly rules compare plain texts, IBANs, categories and accounts: rules with
                 wildcards (\d, [...], .) or placeholders ($1, {category}) are listed and skipped,
                 --approx also takes rules that only fit approximately (\b dropped, \s+ -> " ").
                 Checks every booking of the book: a Firefly rule that would set another
                 counterparty than the import (e.g. because an earlier payee rule can not be
                 translated) is left out, unless --keep-conflicts.
                 --dry-run only shows the translation (no Firefly connection needed).

        Firefly connection (import/export/purge/firefly-rules):
          --url=URL           Firefly III base URL, e.g. https://firefly.example.org  (or FIREFLY_URL)
          --token-file=FILE   file containing a Personal Access Token                (or FIREFLY_TOKEN)
          --token=TOKEN       the token itself (visible in the process list - prefer the file)
          --cacert=FILE       CA bundle for a self-signed certificate
        Other options:
          --timezone=TZ       for old GnuCash dates without neutral time (default: PHP setting or Europe/Berlin)
          --verbose / --quiet
        TXT;

    public static function main(array $argv): int
    {
        $args = new Args(array_slice($argv, 1));
        $cmd  = $args->positional[0] ?? 'help';
        $mem  = (string) ini_get('memory_limit');
        if ('-1' !== $mem && (int) $mem > 0 && (int) $mem < 2048 && 1 === preg_match('/^\d+M$/i', $mem)) {
            ini_set('memory_limit', '2048M');   // large books: ~10 MB per 1000 transactions
        }
        if ($args->has('verbose')) {
            Out::$verbose = true;
        }
        if ($args->has('quiet')) {
            Out::$quiet = true;
        }
        foreach (['bcmath', 'xmlreader', 'dom', 'zlib', 'mbstring'] as $ext) {
            if (!extension_loaded($ext)) {
                Out::error(sprintf('PHP extension "%s" is missing (apt install php-%s)', $ext, 'xmlreader' === $ext || 'dom' === $ext ? 'xml' : $ext));

                return 2;
            }
        }
        $tzName = $args->get('timezone') ?? (ini_get('date.timezone') ?: 'Europe/Berlin');

        try {
            $tz = new \DateTimeZone($tzName);
            date_default_timezone_set($tz->getName());

            return match ($cmd) {
                'plan'    => self::plan($args, $tz),
                'import'  => self::import($args, $tz),
                'export'  => self::export($args, $tz),
                'compare' => self::compare($args, $tz),
                'purge'   => self::purge($args),
                'firefly-rules' => RuleExporter::run($args, $tz, self::bookArg($args)),
                'version', '--version' => self::out('firefly-gnucash '.VERSION),
                default   => self::out(str_replace('VERSION', VERSION, self::HELP), 'help' === $cmd || '--help' === $cmd || $args->has('help') ? 0 : 2),
            };
        } catch (UserError $e) {
            Out::error($e->getMessage());

            return 1;
        } catch (ApiError $e) {
            Out::error($e->getMessage());

            return 1;
        }
    }

    private static function out(string $text, int $code = 0): int
    {
        fwrite(0 === $code ? STDOUT : STDERR, $text."\n");

        return $code;
    }

    private static function bookArg(Args $args, int $pos = 1): string
    {
        $file = $args->positional[$pos] ?? null;
        if (null === $file) {
            throw new UserError('Missing GnuCash file. Run without arguments for help.');
        }

        return $file;
    }

    private static function date(Args $args, string $name): ?string
    {
        $v = $args->get($name);
        if (null === $v || '' === $v) {
            return null;
        }
        if (1 !== preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) {
            throw new UserError(sprintf('--%s must be YYYY-MM-DD', $name));
        }

        return $v;
    }

    private static function import(Args $args, \DateTimeZone $tz): int
    {
        return (new Importer($args, $tz))->run();
    }

    private static function export(Args $args, \DateTimeZone $tz): int
    {
        return (new Exporter($args, $tz))->run();
    }

    private static function compare(Args $args, \DateTimeZone $tz): int
    {
        return Comparer::run($args, $tz);
    }

    private static function purge(Args $args): int
    {
        return Purger::run($args);
    }

    private static function plan(Args $args, \DateTimeZone $tz): int
    {
        $args->check(['config', 'rules', 'from', 'to', 'timezone', 'verbose', 'quiet', 'summary-json']);
        $p = new Pipeline(self::bookArg($args), $args, $tz);
        $p->run(self::date($args, 'from'), self::date($args, 'to'));
        $p->config->save();
        $newRules = $p->writeRulesTemplate();
        $report   = $p->writePayeeReports();
        $sugg     = $p->writeSuggestions();
        if (null !== ($json = $args->get('summary-json')) && '' !== $json) {
            file_put_contents($json, json_encode($p->summaryData($report, $sugg), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE)."\n");
        }
        $p->printSummary($report);
        foreach ($p->config->messages as $m) {
            Out::info('Config:           '.$m);
        }
        Out::step('Written:');
        Out::info('  '.$p->configFile.($p->config->existed ? ' (updated)' : ' (new - please review)'));
        Out::info('  '.$p->rulesFile.($newRules ? ' (new template)' : ' (unchanged)'));
        foreach ($report['files'] as $f) {
            Out::info('  '.$f);
        }
        Out::info(sprintf('  %s (%d suggested merge rules)', $sugg['file'], $sugg['count']));

        return 0;
    }
}

if (!\defined('FIREFLY_GNUCASH_LIBRARY')) {
    if ('cli' !== PHP_SAPI && 'phpdbg' !== PHP_SAPI) {    // served by a web server by mistake
        http_response_code(403);
        exit("firefly-gnucash.php is a command line tool - use web.php for the web interface.\n");
    }
    exit(App::main($argv));
}
