<?php
/*
 * Regression tests for firefly-gnucash.php (offline part: reading, mapping, decomposition,
 * payees, export XML). Run: php tests/run-tests.php
 * License: GPL-3.0-or-later
 */

declare(strict_types=1);

const FIREFLY_GNUCASH_LIBRARY = true;

require __DIR__.'/../firefly-gnucash.php';

use FireflyGnuCash\Args;
use FireflyGnuCash\Group;
use FireflyGnuCash\Journal;
use FireflyGnuCash\Pipeline;
use FireflyGnuCash\TxPlan;
use FireflyGnuCash\Util;

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

/** Build a GnuCash XML book. $accounts: key => [path, type, commodity]; $txs: [date, description, currency, [[account key, value, quantity, memo, state]...], notes] */
function book(array $accounts, array $txs): string
{
    $g   = static fn (string $s): string => md5('test:'.$s);
    $xml = '<?xml version="1.0" encoding="utf-8" ?>'."\n".'<gnc-v2 xmlns:gnc="http://www.gnucash.org/XML/gnc" xmlns:act="http://www.gnucash.org/XML/act" xmlns:book="http://www.gnucash.org/XML/book" xmlns:cd="http://www.gnucash.org/XML/cd" xmlns:cmdty="http://www.gnucash.org/XML/cmdty" xmlns:slot="http://www.gnucash.org/XML/slot" xmlns:split="http://www.gnucash.org/XML/split" xmlns:trn="http://www.gnucash.org/XML/trn" xmlns:ts="http://www.gnucash.org/XML/ts">'."\n";
    $xml .= '<gnc:book version="2.0.0"><book:id type="guid">'.$g('book').'</book:id>'."\n";
    $acc = static function (string $guid, string $name, string $type, string $cmdty, ?string $parent, bool $placeholder = false): string {
        $s = '<gnc:account version="2.0.0"><act:name>'.htmlspecialchars($name).'</act:name><act:id type="guid">'.$guid.'</act:id><act:type>'.$type.'</act:type>'
            .'<act:commodity><cmdty:space>CURRENCY</cmdty:space><cmdty:id>'.$cmdty.'</cmdty:id></act:commodity><act:commodity-scu>100</act:commodity-scu>';
        if ($placeholder) {
            $s .= '<act:slots><slot><slot:key>placeholder</slot:key><slot:value type="string">true</slot:value></slot></act:slots>';
        }
        if (null !== $parent) {
            $s .= '<act:parent type="guid">'.$parent.'</act:parent>';
        }

        return $s."</gnc:account>\n";
    };
    $xml .= $acc($g('root'), 'Root Account', 'ROOT', 'EUR', null);
    $made = [];
    foreach ($accounts as $key => [$path, $type, $cmdty]) {
        $parts  = explode(':', $path);
        $parent = $g('root');
        for ($i = 1, $c = count($parts); $i < $c; ++$i) {
            $pp = implode(':', array_slice($parts, 0, $i));
            if (!isset($made[$pp])) {
                $xml       .= $acc($g('path:'.$pp), $parts[$i - 1], 'TRADING' === $type ? 'TRADING' : (in_array($type, ['EXPENSE', 'INCOME'], true) ? $type : ('LIABILITY' === $type || 'CREDIT' === $type ? 'LIABILITY' : 'ASSET')), 'EUR', $parent, true);
                $made[$pp]  = true;
            }
            $parent = $g('path:'.$pp);
        }
        $xml .= $acc($g('acct:'.$key), end($parts), $type, $cmdty, $parent);
    }
    foreach ($txs as $n => $t) {
        [$date, $desc, $cur, $splits] = $t;
        $notes = $t[4] ?? '';
        $xml  .= '<gnc:transaction version="2.0.0"><trn:id type="guid">'.$g('tx:'.$n).'</trn:id><trn:currency><cmdty:space>CURRENCY</cmdty:space><cmdty:id>'.$cur.'</cmdty:id></trn:currency>'
            .'<trn:date-posted><ts:date>'.$date.' 10:59:00 +0000</ts:date></trn:date-posted><trn:date-entered><ts:date>'.$date.' 12:00:00 +0000</ts:date></trn:date-entered>'
            .'<trn:description>'.htmlspecialchars($desc).'</trn:description>';
        if ('' !== $notes) {
            $xml .= '<trn:slots><slot><slot:key>notes</slot:key><slot:value type="string">'.htmlspecialchars($notes).'</slot:value></slot></trn:slots>';
        }
        $xml .= '<trn:splits>';
        foreach ($splits as $i => $s) {
            $xml .= '<trn:split><split:id type="guid">'.$g("split:{$n}:{$i}").'</split:id>'.('' !== ($s[3] ?? '') ? '<split:memo>'.htmlspecialchars($s[3]).'</split:memo>' : '')
                .'<split:reconciled-state>'.($s[4] ?? 'n').'</split:reconciled-state><split:value>'.$s[1].'</split:value><split:quantity>'.$s[2].'</split:quantity>'
                .'<split:account type="guid">'.$g('acct:'.$s[0]).'</split:account></trn:split>';
        }
        $xml .= "</trn:splits></gnc:transaction>\n";
    }

    return $xml."</gnc:book>\n</gnc-v2>\n";
}

function run(array $accounts, array $txs, array $options = [], string $rules = '', ?string $from = null, ?string $to = null): Pipeline
{
    $dir  = sys_get_temp_dir().'/ffgc-test-'.getmypid();
    @mkdir($dir);
    $file = $dir.'/book.gnucash';
    file_put_contents($file, gzencode(book($accounts, $txs)));
    @unlink($dir.'/book.import.json');
    file_put_contents($dir.'/book.payee-rules.txt', $rules);
    if ([] !== $options) {
        file_put_contents($dir.'/book.import.json', json_encode(['options' => $options, 'accounts' => []]));
    }
    $p = new Pipeline($file, new Args([]), new DateTimeZone('Europe/Berlin'));
    FireflyGnuCash\Out::$quiet = true;
    $p->run($from, $to);

    return $p;
}

/** @return list<Journal> */
function journals(TxPlan $plan): array
{
    $out = [];
    foreach ($plan->groups as $g) {
        foreach ($g->journals as $j) {
            $out[] = $j;
        }
    }

    return $out;
}

function summary(TxPlan $plan, Pipeline $p): string
{
    $parts = [];
    foreach ($plan->groups as $g) {
        $js = [];
        foreach ($g->journals as $j) {
            $side = static function (array $ref) use ($p): string {
                return match ($ref[0]) {
                    'bs'       => $p->config->accounts[$ref[1]]['name'],
                    'clearing' => 'CLR',
                    'payee'    => '@'.$p->payees->get($ref[1])['name'],
                };
            };
            $js[] = sprintf('%s>%s %s%s%s%s', $side($j->src), $side($j->dst), Util::minorToDec($j->amount, $j->decimals), $j->currency,
                null !== $j->foreignCurrency ? '/'.Util::minorToDec((int) $j->foreignAmount, $j->foreignDecimals).$j->foreignCurrency : '', null !== $j->category ? ' ['.$j->category.']' : '');
        }
        $parts[] = $g->type.'('.implode(', ', $js).')';
    }

    return implode(' + ', $parts);
}

$A = [
    'bank'    => ['Aktiva:Barvermögen:Girokonto', 'BANK', 'EUR'],
    'cash'    => ['Aktiva:Barvermögen:Bargeld', 'CASH', 'EUR'],
    'voucher' => ['Aktiva:Gutscheine', 'ASSET', 'EUR'],
    'dm'      => ['Aktiva:Barvermögen:DM', 'ASSET', 'DEM'],
    'card'    => ['Fremdkapital:Kreditkarte', 'CREDIT', 'EUR'],
    'loan'    => ['Fremdkapital:Kredite:Eltern', 'LIABILITY', 'EUR'],
    'food'    => ['Aufwendungen:Lebensmittel', 'EXPENSE', 'EUR'],
    'drinks'  => ['Aufwendungen:Lebensmittel:Getränke', 'EXPENSE', 'EUR'],
    'bottle'  => ['Aufwendungen:Lebensmittel:Leergut', 'EXPENSE', 'EUR'],
    'tax'     => ['Aufwendungen:Steuern', 'EXPENSE', 'EUR'],
    'rent'    => ['Aufwendungen:Wohnen:Miete', 'EXPENSE', 'EUR'],
    'giftx'   => ['Aufwendungen:Geschenke', 'EXPENSE', 'EUR'],
    'gifti'   => ['Erträge:Geschenke', 'INCOME', 'EUR'],
    'salary'  => ['Erträge:Gehalt', 'INCOME', 'EUR'],
    'bonus'   => ['Erträge:Gehalt:Zulagen', 'INCOME', 'EUR'],
    'equity'  => ['Anfangsbestand', 'EQUITY', 'EUR'],
    'eqdem'   => ['Anfangsbestand - DEM', 'EQUITY', 'DEM'],
    'trdem'   => ['Devisenhandel:CURRENCY:DEM', 'TRADING', 'DEM'],
    'treur'   => ['Devisenhandel:CURRENCY:EUR', 'TRADING', 'EUR'],
];
$T = [
    /* 0 */ ['2000-11-14', 'Anfangsbestand', 'DEM', [['dm', '4000/100', '4000/100'], ['eqdem', '-4000/100', '-4000/100']]],
    /* 1 */ ['2026-01-02', 'REWE Musterstadt', 'EUR', [['food', '1234/100', '1234/100', 'Brot'], ['drinks', '566/100', '566/100', 'Cola'], ['bank', '-1800/100', '-1800/100', 'Konto DE89370400440532013000 Bank COBADEFFXXX']]],
    /* 2 */ ['2026-01-03', 'Kiosk', 'EUR', [['food', '5000/100', '5000/100', 'Einkauf'], ['voucher', '-3000/100', '-3000/100'], ['cash', '-2000/100', '-2000/100']]],
    /* 3 */ ['2026-01-31', 'Entgeltabrechnung Januar', 'EUR', [['bank', '150000/100', '150000/100'], ['tax', '30000/100', '30000/100', 'Lohnsteuer'], ['tax', '20000/100', '20000/100', 'Soli'], ['salary', '-200000/100', '-200000/100', 'Brutto']]],
    /* 4 */ ['2026-02-01', 'Pfand und Einkauf', 'EUR', [['cash', '-800/100', '-800/100'], ['food', '1000/100', '1000/100'], ['bottle', '-200/100', '-200/100', 'Leergut']]],
    /* 5 */ ['2026-02-02', 'Umbuchung Reisekosten', 'EUR', [['bonus', '3100/100', '3100/100'], ['tax', '-3100/100', '-3100/100']]],
    /* 6 */ ['2026-02-03', 'Geldautomat', 'EUR', [['cash', '5000/100', '5000/100'], ['bank', '-5000/100', '-5000/100']]],
    /* 7 */ ['2026-02-04', 'Kreditkartenabrechnung', 'EUR', [['card', '10000/100', '10000/100'], ['bank', '-10000/100', '-10000/100']]],
    /* 8 */ ['2026-02-05', 'Einkauf mit Karte', 'EUR', [['food', '4550/100', '4550/100'], ['card', '-4550/100', '-4550/100']]],
    /* 9 */ ['2026-02-06', 'Darlehen Eltern', 'EUR', [['bank', '50000/100', '50000/100'], ['loan', '-50000/100', '-50000/100']]],
    /* 10 */ ['2026-02-07', 'Rate Eltern', 'EUR', [['loan', '10000/100', '10000/100'], ['bank', '-10000/100', '-10000/100']]],
    /* 11 */ ['2000-12-05', 'Knesebeck', 'DEM', [['cash', '2000/100', '1023/100'], ['trdem', '2000/100', '2000/100'], ['dm', '-2000/100', '-2000/100'], ['treur', '-2000/100', '-1023/100']]],
    /* 12 */ ['2001-04-18', 'Media Markt', 'DEM', [['food', '4244/100', '2170/100'], ['dm', '-4244/100', '-4244/100']]],
    /* 13 */ ['2026-02-08', 'Nullbuchung', 'EUR', [['bank', '0/100', '0/100']]],
    /* 14 */ ['2026-02-09', 'Burger', 'EUR', [['food', '200/100', '200/100', 'Menü'], ['food', '0/100', '0/100', 'Ketchup'], ['cash', '-200/100', '-200/100']]],
    /* 15 */ ['2026-02-10', 'Tablet zurück', 'EUR', [['voucher', '376/100', '376/100'], ['food', '1104/100', '1104/100'], ['bank', '15160/100', '15160/100'], ['voucher', '-16640/100', '-16640/100']]],
    /* 16 */ ['2026-02-11', 'Erstattung', 'EUR', [['bank', '1999/100', '1999/100'], ['giftx', '-1999/100', '-1999/100']], 'Notiz zur Buchung'],
    /* 17 */ ['2026-02-12', 'Geschenk von Oma', 'EUR', [['cash', '5000/100', '5000/100'], ['gifti', '-5000/100', '-5000/100']]],
    /* 18 */ ['2026-02-13', 'Drei Positionen DM', 'DEM', [['food', '100/100', '51/100'], ['drinks', '100/100', '51/100'], ['bottle', '100/100', '52/100'], ['dm', '-300/100', '-300/100']]],
    /* 19 */ ['2026-02-14', 'Abgleich', 'EUR', [['bank', '396/100', '396/100', '', 'y'], ['equity', '-396/100', '-396/100']]],
    /* 20 */ ['2026-03-01', 'Bäcker Erika Muster', 'EUR', [['food', '350/100', '350/100'], ['cash', '-350/100', '-350/100']]],
    /* 21 */ ['2026-03-02', 'Bäcker Erika Muster ( für 21.03)', 'EUR', [['food', '420/100', '420/100'], ['cash', '-420/100', '-420/100']]],
    /* 22 */ ['2026-03-03', 'Bäcker Erika Muster ( für 4.04', 'EUR', [['food', '180/100', '180/100'], ['cash', '-180/100', '-180/100']]],
    /* 23 */ ['2026-03-04', 'Bäcker Erika Muster (voraus für Samstag)', 'EUR', [['food', '600/100', '600/100'], ['cash', '-600/100', '-600/100']]],
    /* 24 */ ['2026-03-05', 'Bäcker Erika Muster nachbezahlt', 'EUR', [['food', '90/100', '90/100'], ['cash', '-90/100', '-90/100']]],
    /* 25 */ ['2026-03-06', 'REWE//MUSTERSTADT/DE; KARTENZAHLUNG 2026-03-06T10:00 Debitk.1', 'EUR', [['food', '1500/100', '1500/100'], ['bank', '-1500/100', '-1500/100', 'Konto DE75999999990000127589 Bank GENODEFFXXX']]],
    /* 26 */ ['2026-03-07', 'BURGER KING//MUSTERSTADT/DE; KARTENZAHLUNG 2026-03-07T12:00 Debitk.1', 'EUR', [['food', '800/100', '800/100'], ['bank', '-800/100', '-800/100', 'Konto DE75999999990000127589 Bank GENODEFFXXX']]],
    /* 27 */ ['2026-03-08', 'DAUERAUFTRAG MIETE; MARTHA UND KARL BEISPIEL', 'EUR', [['rent', '50000/100', '50000/100'], ['bank', '-50000/100', '-50000/100', 'Konto DE44500105175407324931 Bank INGDDEFFXXX']]],
    /* 28 */ ['2026-04-08', 'DAUERAUFTRAG MIETE; MARTHA UND KARL BEISPIEL', 'EUR', [['rent', '50000/100', '50000/100'], ['bank', '-50000/100', '-50000/100', 'Konto DE44500105175407324931 Bank INGDDEFFXXX']]],
    /* 29 */ ['2026-04-09', 'ÜBERWEISUNG 6 SESAM - BÄCKER ERIKA MUSTER; MARTHA BEISPIEL', 'EUR', [['food', '300/100', '300/100'], ['bank', '-300/100', '-300/100', 'Konto DE44500105175407324931 Bank INGDDEFFXXX']]],
];

// "php run-tests.php --write-book=FILE" only writes the synthetic book (used by web-tests.php)
foreach (array_slice($argv, 1) as $arg) {
    if (str_starts_with($arg, '--write-book=')) {
        file_put_contents(substr($arg, 13), gzencode(book($A, $T)));
        exit(0);
    }
}

$p = run($A, $T);
check('self-check', [] === $p->verifier->errors, implode("\n", $p->verifier->errors));
$index = array_flip(array_map(static fn ($i) => md5('test:tx:'.$i), range(0, count($T) - 1)));
$plans = [];
foreach ($p->plans as $plan) {
    $plans[$index[$plan->tx->guid]] = $plan;
}
$s = static fn (int $i): string => summary($plans[$i], $p);

// names: leaf names for accounts, category = path without the top level; ambiguous -> full path
$cats = [];
foreach ($p->config->accounts as $m) {
    if ('category' === $m['as']) {
        $cats[] = $m['name'];
    }
}
sort($cats);
check('category names', ['Anfangsbestand', 'Anfangsbestand - DEM', 'Aufwendungen:Geschenke', 'Erträge:Geschenke', 'Gehalt', 'Gehalt:Zulagen', 'Lebensmittel', 'Lebensmittel:Getränke', 'Lebensmittel:Leergut', 'Steuern', 'Wohnen:Miete'] === $cats, implode(', ', $cats));
check('card is credit card asset', 'ccAsset' === ($p->config->accounts[md5('test:acct:card')]['role'] ?? null));
check('loan is liability', 'liability' === $p->config->accounts[md5('test:acct:loan')]['as'] && 'loan' === $p->config->accounts[md5('test:acct:loan')]['liability_type'] && 'debit' === $p->config->accounts[md5('test:acct:loan')]['direction']);
check('trading ignored', 'ignore' === $p->config->accounts[md5('test:acct:trdem')]['as']);

check('opening balance', null !== $plans[0]->skip && isset($p->dec->openingBalance[md5('test:acct:dm')]) && 4000 === $p->dec->openingBalance[md5('test:acct:dm')]['amount']);
check('split receipt', 'withdrawal(Girokonto>@(diverse) 12.34EUR [Lebensmittel], Girokonto>@(diverse) 5.66EUR [Lebensmittel:Getränke])' === $s(1), $s(1));
check('split receipt memos', 'Brot' === journals($plans[1])[0]->description && 'REWE Musterstadt' === $plans[1]->groups[0]->title && str_contains(journals($plans[1])[0]->notes, 'GnuCash-Memo [Girokonto -18.00]: Konto DE89'));
check('multisource withdrawal', 'withdrawal(Gutscheine>@(diverse) 30.00EUR [Lebensmittel], Bargeld>@(diverse) 20.00EUR [Lebensmittel])' === $s(2), $s(2));
check('salary gross-up', 'withdrawal(Girokonto>@(diverse) 300.00EUR [Steuern], Girokonto>@(diverse) 200.00EUR [Steuern]) + deposit(@(diverse)>Girokonto 2000.00EUR [Gehalt])' === $s(3), $s(3));
check('refund inside purchase', 'withdrawal(Bargeld>@(diverse) 10.00EUR [Lebensmittel]) + deposit(@(diverse)>Bargeld 2.00EUR [Lebensmittel:Leergut])' === $s(4), $s(4));
check('income/expense reclassification via clearing', 'withdrawal(CLR>@(diverse) 31.00EUR [Gehalt:Zulagen]) + deposit(@(diverse)>CLR 31.00EUR [Steuern])' === $s(5) && $plans[5]->usesClearing, $s(5));
check('transfer', 'transfer(Girokonto>Bargeld 50.00EUR)' === $s(6), $s(6));
check('credit card payment is a transfer', 'transfer(Girokonto>Kreditkarte 100.00EUR)' === $s(7), $s(7));
check('credit card purchase', 'withdrawal(Kreditkarte>@(diverse) 45.50EUR [Lebensmittel])' === $s(8), $s(8));
check('borrowing is a deposit from the liability', 'deposit(Eltern>Girokonto 500.00EUR)' === $s(9), $s(9));
check('repayment is a withdrawal to the liability', 'withdrawal(Girokonto>Eltern 100.00EUR)' === $s(10), $s(10));
check('DEM -> EUR transfer (trading accounts)', 'transfer(DM>Bargeld 20.00DEM/10.23EUR)' === $s(11), $s(11));
check('DEM purchase, EUR expense account', 'withdrawal(DM>@(diverse) 42.44DEM/21.70EUR [Lebensmittel])' === $s(12), $s(12));
check('zero transaction skipped', null !== $plans[13]->skip);
check('zero split kept as memo', str_contains(journals($plans[14])[0]->notes, 'GnuCash-Memo [Kategorie: Lebensmittel 0.00]: Ketchup'), journals($plans[14])[0]->notes);
check('same account on both sides', 'withdrawal(Gutscheine>@(diverse) 11.04EUR [Lebensmittel]) + transfer(Gutscheine>Girokonto 151.60EUR)' === $s(15), $s(15));
check('refund of an expense', 'deposit(@(diverse)>Girokonto 19.99EUR [Aufwendungen:Geschenke])' === $s(16) && str_starts_with(journals($plans[16])[0]->notes, 'Notiz zur Buchung'), $s(16));
check('income category', 'deposit(@Geschenk von Oma>Bargeld 50.00EUR [Erträge:Geschenke])' === $s(17) || 'deposit(@(diverse)>Bargeld 50.00EUR [Erträge:Geschenke])' === $s(17), $s(17));
check('proportional DEM/EUR allocation', 'withdrawal(DM>@(diverse) 1.00DEM/0.51EUR [Lebensmittel], DM>@(diverse) 1.00DEM/0.51EUR [Lebensmittel:Getränke], DM>@(diverse) 1.00DEM/0.52EUR [Lebensmittel:Leergut])' === $s(18), $s(18));
check('reconciled state y', true === journals($plans[19])[0]->reconciled);
check('equity (not opening) as category', 'deposit(@(diverse)>Girokonto 3.96EUR [Anfangsbestand])' === $s(19), $s(19));
// "Buchungstext zusammenfassen": variants of one booking text end up in one counterparty
$baker = [];
foreach ([20, 21, 22, 23, 24] as $i) {
    $baker[] = $p->payees->get(journals($plans[$i])[0]->dst[1])['name'];
}
check('booking text variants -> one counterparty', ['Bäcker Erika Muster'] === array_values(array_unique($baker)), json_encode($baker, JSON_UNESCAPED_UNICODE));

// counterparty names must not depend on the imported date range (import in slices)
$p4    = run($A, $T, [], '', '2026-03-04');
$slice = [];
foreach ($p4->plans as $pl) {
    if (str_starts_with($pl->tx->description, 'Bäcker Erika Muster')) {
        $slice[] = $p4->payees->get(journals($pl)[0]->dst[1])['name'];
    }
}
check('date range: same counterparty names as for the whole book', ['Bäcker Erika Muster', 'Bäcker Erika Muster'] === $slice, json_encode($slice, JSON_UNESCAPED_UNICODE));
check('date range: only transactions in the range are planned', 7 === count($p4->plans), (string) count($p4->plans));
$p5  = run($A, $T);                                   // the same book without date range
$csv = static function (Pipeline $p): string {
    $p->writePayeeReports();

    return (string) file_get_contents($p->base.'.payees.csv').(string) file_get_contents($p->base.'.payee-map.csv');
};
check('date range: payee reports cover the whole book', $csv($p5) === $csv($p4));

// ---- no multisource (upstream Firefly): separate groups per source account
$p2 = run($A, $T, ['multisource' => false]);
$plan2 = null;
foreach ($p2->plans as $pl) {
    if ('Kiosk' === $pl->tx->description) {
        $plan2 = $pl;
    }
}
check('without multisource: one group per source', 2 === count($plan2->groups), summary($plan2, $p2));

// ---- payee rules and automatic names
$rules = "/^REWE/i => REWE\n/\\bErika\\s+Muster\\b/i => Bäcker Erika Muster\ncategory:/^Steuern/ => Finanzamt\n";
$p3    = run($A, $T, ['payee_min_count' => 1], $rules);
$names = [];
foreach ($p3->plans as $pl) {
    foreach (journals($pl) as $j) {
        foreach (['src', 'dst'] as $side) {
            if ('payee' === $j->{$side}[0]) {
                $names[$pl->tx->description][] = $p3->payees->get($j->{$side}[1])['name'];
            }
        }
    }
}
check('rule by text', ['REWE', 'REWE'] === $names['REWE Musterstadt'], json_encode($names['REWE Musterstadt'] ?? null));
check('rule by category', in_array('Finanzamt', $names['Entgeltabrechnung Januar'], true) && in_array('Entgeltabrechnung Januar', $names['Entgeltabrechnung Januar'], true), json_encode($names['Entgeltabrechnung Januar']));
check('min_count=1 keeps singletons', ['Kiosk', 'Kiosk'] === $names['Kiosk'], json_encode($names['Kiosk']));
check('a rule does not take over other texts with the same IBAN', 'Bäcker Erika Muster' === ($names['ÜBERWEISUNG 6 SESAM - BÄCKER ERIKA MUSTER; MARTHA BEISPIEL'][0] ?? null)
    && 'MARTHA UND KARL BEISPIEL' === ($names['DAUERAUFTRAG MIETE; MARTHA UND KARL BEISPIEL'][0] ?? null), json_encode([$names['ÜBERWEISUNG 6 SESAM - BÄCKER ERIKA MUSTER; MARTHA BEISPIEL'] ?? null, $names['DAUERAUFTRAG MIETE; MARTHA UND KARL BEISPIEL'] ?? null], JSON_UNESCAPED_UNICODE));
check('card settlement IBAN does not spread a rule to other merchants', ['BURGER KING'] === ($names['BURGER KING//MUSTERSTADT/DE; KARTENZAHLUNG 2026-03-07T12:00 Debitk.1'] ?? null),
    json_encode($names['BURGER KING//MUSTERSTADT/DE; KARTENZAHLUNG 2026-03-07T12:00 Debitk.1'] ?? null));

// rules: Amazon per country - the example block of the README, so the documented rules stay tested
$readme = (string) file_get_contents(__DIR__.'/../README.md');
$block  = 1 === preg_match('/Amazon per country[^\n]*\n\s*```\n(.*?)\n\s*```/s', $readme, $m) ? $m[1] : '';
$rf     = sys_get_temp_dir().'/ffgc-test-'.getmypid().'/amazon.txt';
file_put_contents($rf, $block);
$amazon = FireflyGnuCash\PayeeRules::load($rf);
check('README amazon example parsed', 8 === count($amazon->rules), (string) count($amazon->rules));
foreach ([
    'LASTSCHRIFT 404-1234567-7654321 AMAZON.FR 1AB2C DE3FG4HI5JK'                                    => 'Amazon FR',
    'LASTSCHRIFT 406-1234567-7654321 AMAZON.IT 1AB2C DE3FG4HI5JK; AMAZON EU S.A R.L.'                => 'Amazon IT',
    'LASTSCHRIFT 171-1234567-7654321 AMAZON.ES 1AB2C; AMAZON EU S.A R.L., MADRID BRANCH'             => 'Amazon ES',
    'AMZN Mktp DE*AB1CD2EF3 800-279-6620  LU'                                                        => 'Amazon DE',
    'Amazon.Services S.A.R.L - EU-DE'                                                                => 'Amazon DE',
    'Amazon 028-1234567-7654321'                                                                     => 'Amazon DE',
    'GUTSCHRIFT ÜBERW. 305-1234567-7654321 AMZ BEISPIEL GMBH 1AB2C3D4E5; AMAZON PAYMENTS EUROPE S.C.A.' => 'Amazon DE',
    'LASTSCHRIFT P02-1234567-7654321 AMZ BEISPIEL APOTHEKE 1AB2C3D4E5; AMAZON PAYMENTS EUROPE S.C.A.'  => 'Amazon Pay',
    'pay.amazon.com - 5 x Shelly 1PM Gen 3'                                                          => 'Amazon Pay',
    'AMAZON.CO.UK*1A2B3C4D5 AMAZON.CO.UK LU'                                                         => 'Amazon UK',
    'UEBERWSG.GUTSCHR /ORG GBP 39,82; KTO 12345678 AMAZON.LUX.UK/AMAZON EU SAR'                      => 'Amazon UK',
    'Amazon.com'                                                                                     => 'Amazon US',
    'WWW.AMAZON. AB1CD2EF3'                                                                          => 'Amazon (Land unbekannt)',
] as $text => $want) {
    $got = $amazon->match($text, [], '', [])[0] ?? null;
    check('amazon rule '.$want, $got === $want, $text.' -> '.var_export($got, true));
}
check('amazon rule leaves vouchers alone', null === $amazon->match('Amazon Gutschein von Qipu', [], '', []));
// "(?![^;]*;)" limits a text rule to the name part, not the purpose of a transfer to someone else
file_put_contents($rf, "/\\bErika\\s+Muster\\b(?![^;]*;)/i => Bäcker Erika Muster\n");
$only = FireflyGnuCash\PayeeRules::load($rf);
check('rule limited to the name after the last ";"', 'Bäcker Erika Muster' === ($only->match('Bäcker Erika Muster ( für 21.03)', [], '', [])[0] ?? null)
    && 'Bäcker Erika Muster' === ($only->match('KARTENZAHLUNG 2026-03-01; BÄCKEREI ERIKA MUSTER', [], '', [])[0] ?? null)
    && null === $only->match('ÜBERWEISUNG 6 SESAM - BÄCKER ERIKA MUSTER; MARTHA BEISPIEL', [], '', []));
// "ausgabe:" / "einnahme:" limit a rule to withdrawals / deposits, also combined with a field prefix
file_put_contents($rf, "ausgabe:Platinum => Platinum\neinnahme:/Platinum/i => Platinum Erstattung\nEinnahmen: category:/^Erträge/ => {category}\n");
$sided = FireflyGnuCash\PayeeRules::load($rf);
check('side rule: expense', 'Platinum' === ($sided->match('3xPlatinum', [], '', [], 'expense')[0] ?? null));
check('side rule: revenue', 'Platinum Erstattung' === ($sided->match('3xPlatinum', [], '', [], 'revenue')[0] ?? null));
check('side rule: unknown side skips limited rules', null === $sided->match('3xPlatinum', [], '', []));
check('side rule with category:', 'Erträge:Zinsen' === ($sided->match('Zinsen', [], 'Erträge:Zinsen', [], 'revenue')[0] ?? null)
    && null === $sided->match('Zinsen', [], 'Erträge:Zinsen', [], 'expense'));
// "konto:" matches any GnuCash account of the transaction (e.g. the cash account it was paid from)
file_put_contents($rf, "ausgabe:konto:/:Casino$/ => Casino\naccount:/^Aktiva:Bar/ => Bar\n");
$kr = FireflyGnuCash\PayeeRules::load($rf);
check('konto rule', 'Casino' === ($kr->match('Currywurst', [], 'Lebensmittel', [], 'expense', ['Aufwendungen:Lebensmittel', 'Aktiva:Barvermögen:Casino'])[0] ?? null)
    && 'Bar' === ($kr->match('Currywurst', [], 'Lebensmittel', [], 'revenue', ['Aktiva:Barvermögen:Casino'])[0] ?? null)
    && null === $kr->match('Currywurst', [], 'Lebensmittel', [], 'expense', ['Aktiva:Girokonto']));
// "&&": all conditions must match, $1 from the first one
file_put_contents($rf, "/Abrechnung (\\d+)/i && konto:/Bankgebühren:Musterbank$/ => Musterbank $1\nausgabe:/Abrechnung/i && memo:/Porto/i => Porto\n");
$and = FireflyGnuCash\PayeeRules::load($rf);
check('and rule: both match', 'Musterbank 2024' === ($and->match('Abrechnung 2024', [], '', [], 'expense', ['Aktiva:Giro', 'Aufwendungen:Bankgebühren:Musterbank'])[0] ?? null));
check('and rule: one missing', null === $and->match('Abrechnung 2024', [], '', [], 'expense', ['Aktiva:Giro', 'Aufwendungen:Sonstiges'])
    && null === $and->match('Zinsen', [], '', [], 'expense', ['Aufwendungen:Bankgebühren:Musterbank']));
check('and rule with side and memo', 'Porto' === ($and->match('Abrechnung Q1', [], '', ['Porto 0,70'], 'expense')[0] ?? null)
    && null === $and->match('Abrechnung Q1', [], '', ['Porto 0,70'], 'revenue'));
// rule assistant: patterns built from typed text (PayeeRules::build) and what they match
$bt = static function (string $query, string $mode = 'words', bool $nameOnly = false) use ($rf): FireflyGnuCash\PayeeRules {
    file_put_contents($rf, FireflyGnuCash\PayeeRules::build($query, $mode, $nameOnly)." => X\n");

    return FireflyGnuCash\PayeeRules::load($rf);
};
$hits = static fn (FireflyGnuCash\PayeeRules $r, array $texts): array => array_values(array_filter($texts, static fn ($x) => null !== $r->match($x, [], '', [])));
$route = ['DB Musterstadt(Nord) -> Beispielhausen', 'DB Beispieldorf -> Musterstadt', 'Beispieldorf -> Musterstadt', 'DBV Versicherung Musterstadt', 'Kauf DB Musterstadt'];
check('build: words in order', '/\bDB\s+Musterstadt\b/i' === FireflyGnuCash\PayeeRules::build('  DB   Musterstadt ') && ['DB Musterstadt(Nord) -> Beispielhausen', 'Kauf DB Musterstadt'] === $hits($bt('DB Musterstadt'), $route), json_encode($hits($bt('DB Musterstadt'), $route)));
check('build: all words, any order', ['DB Musterstadt(Nord) -> Beispielhausen', 'DB Beispieldorf -> Musterstadt', 'Kauf DB Musterstadt'] === $hits($bt('Musterstadt DB', 'all'), $route), json_encode($hits($bt('Musterstadt DB', 'all'), $route)));
check('build: begins with', ['DB Musterstadt(Nord) -> Beispielhausen', 'DB Beispieldorf -> Musterstadt'] === $hits($bt('db', 'start'), $route), json_encode($hits($bt('db', 'start'), $route)));
check('build: contains text, special characters', ['DB Musterstadt(Nord) -> Beispielhausen'] === $hits($bt('Musterstadt(Nord)', 'text'), $route) && 4 === count($hits($bt('->', 'text'), array_merge($route, ['A -> B']))));
check('build: umlauts and whole words', '/\bbäcker\b/iu' === FireflyGnuCash\PayeeRules::build('bäcker') && ['BÄCKER Erika Muster'] === $hits($bt('bäcker'), ['BÄCKER Erika Muster', 'Feinbäcker Erika', 'xBäcker']));
$bank = ['Bäcker Erika Muster ( für 21.03)', 'KARTENZAHLUNG 2026-03-01; BÄCKEREI ERIKA MUSTER', 'ÜBERWEISUNG 6 SESAM - BÄCKER ERIKA MUSTER; MARTHA BEISPIEL'];
check('build: only the name after the last ";"', array_slice($bank, 0, 2) === $hits($bt('Erika Muster', 'words', true), $bank)
    && array_slice($bank, 0, 2) === $hits($bt('Muster Erika', 'all', true), $bank) && [$bank[0]] === $hits($bt('Bäcker Erika', 'start', true), $bank)
    && [$bank[1]] === $hits($bt('Bäckerei', 'start', true), $bank) && array_slice($bank, 0, 2) === $hits($bt('ERIKA MUSTER', 'text', true), $bank));
check('build: readable escaping, "=>" inside the pattern', '/->/i' === FireflyGnuCash\PayeeRules::build('->', 'text') && '/\\bMusterstadt\\(Nord\\)/i' === FireflyGnuCash\PayeeRules::build('Musterstadt(Nord)')
    && ['Kurs A => B'] === $hits($bt('=> B', 'text'), ['Kurs A => B', 'A = B']), FireflyGnuCash\PayeeRules::build('Musterstadt(Nord)'));
check('build: IBAN and empty input', 'iban:DE89370400440532013000' === FireflyGnuCash\PayeeRules::build('DE89 3704 0044 0532 0130 00') && '' === FireflyGnuCash\PayeeRules::build('  '));
check('settlement account detection', FireflyGnuCash\PayeeResolver::isSettlementAccount(['eBay.O.12.34567.89012/Luxembourg', 'GUTSCHR. UEBERWEISUNG', 'Visa.Geld.zurueck.Aktio'])
    && !FireflyGnuCash\PayeeResolver::isSettlementAccount(['BUNDESKASSE TRIER', 'BUNDESKASSE IN TRIER', 'BUNDESKASSE'])
    && !FireflyGnuCash\PayeeResolver::isSettlementAccount(['Millionenchance.de', 'insic GmbH']));

$resolver = new ReflectionMethod(FireflyGnuCash\PayeeResolver::class, 'autoName');
$auto     = static fn (string $d): string => $resolver->invoke($p->payees, $d)[0];
$cases    = [
    'LASTSCHRIFT 01-2345678-90 BEITRAG; WUERTT.VERSICHERG.AG'                              => 'WUERTT.VERSICHERG.AG',
    'DEBITKARTENZAHLUNG 2020-11-24T14:00DEBITK.1 2021-12; BURGER KING DEUTSCHLAND GMBH//MUSTERSTADT/DE' => 'BURGER KING DEUTSCHLAND GMBH',
    'AMZN Mktp DE*AB1CD2EF3 800-279-6620  LU'                                                  => 'AMZN Mktp',
    'Rewe - Musterstraße 12 Musterstadt'                                                    => 'Rewe',
    'PP.9697.PP . DORMANDO.DE, Ihr Einkauf bei DORMANDO.DE; PayPal Europe S.a.r.l. et C ie S.C.A' => 'DORMANDO.DE',
    'PAYPAL *SIMLINK 12345678901 DE  (200 USD)'                                                => 'SIMLINK',
    'DEBITKARTENZAHLUNG 2020-05-20T16:42DEBITK.1 2021-12; REWE SAGT DANKE. 12345678//MUSTERSTADT/DE' => 'REWE',
    'Amazon.de 1A2B34567'                                                                      => 'Amazon.de',
    'Bäcker Erika Muster'                                                                        => 'Bäcker Erika Muster',
    'Bäcker Erika Muster ( für 4.04)'                                                            => 'Bäcker Erika Muster',
    'Bäcker Erika Muster ( für 22.11'                                                            => 'Bäcker Erika Muster',
    'Bäcker Erika Muster (voraus für Samstag)'                                                   => 'Bäcker Erika Muster',
    'Bundeskasse Trier (Erstattung November)'                                                   => 'Bundeskasse Trier',
    'Kaufland Musterstadt//Must; Basislastschrift Kaufland Musterstadt/Musterstadt/DE 07.02.2024 um 21:08:58 Uhr 12345678/123456/CICC/FPIN Zahlung 12,34 EUR' => 'Kaufland Musterstadt',
    'Kartenverfügung HERMES GERMANY/HAMBURG/DE/002.10.2025 / 08:20 OrtszeitPHYS/FPIN 12345678 87654321/1234567890/0/1299 REF 123456; DZ BANK AG' => 'HERMES GERMANY',
    'Überweisungsgutschr. Gutschrift von eBay O.12-34567-89012/Luxembourg/LU/0 06.04.2025 10:00:00 EREF: FDN1; DZ BANK, wg. Debit Issuing' => 'eBay',
    'Kartenverfügung DHL.1AB23CD456EF/BONN/DE/0 04.10.2025 / 16:08 OrtszeitPHYS/FPIN 87654321; DZ BANK AG' => 'DHL',
];
foreach ($cases as $in => $want) {
    check('autoName '.$want, $auto($in) === $want, $auto($in));
}
check('payee key merges line-wrapped names', Util::key('Martha und Karl Beispie l') === Util::key('MARTHA UND KARL BEISPIEL'));
// --update-payees: planned journals <-> journals in Firefly (by order, else by amount/description)
$pair = new ReflectionMethod(FireflyGnuCash\Importer::class, 'pairJournals');
$js   = journals($plans[1]);                                   // "Brot" 12.34 + "Cola" 5.66
$ff   = static fn (int $order, string $amount, string $desc): array => ['order' => $order, 'amount' => $amount, 'currency_code' => 'EUR', 'currency_decimal_places' => 2, 'description' => $desc, 'category_name' => null];
$want = ['1234 EUR', '566 EUR'];
$byOrder  = $pair->invoke(null, $js, [$ff(0, '12.34', 'Brot'), $ff(1, '5.66', 'Cola')], $want);
$swapped  = $pair->invoke(null, $js, [$ff(0, '5.66', 'Cola'), $ff(1, '12.34', 'Brot ')], $want);
$mismatch = $pair->invoke(null, $js, [$ff(0, '12.34', 'Brot'), $ff(1, '5.67', 'Cola')], $want);
check('update-payees: journals paired by order', null !== $byOrder && 'Brot' === $byOrder[0][1]['description'] && 'Cola' === $byOrder[1][1]['description']);
check('update-payees: journals paired by amount and description', null !== $swapped && '12.34' === $swapped[0][1]['amount'] && '5.66' === $swapped[1][1]['amount']);
check('update-payees: no pairing when amounts differ', null === $mismatch);

check('iban check', Util::isValidIban('DE89 3704 0044 0532 0130 00') && !Util::isValidIban('DE89370400440532013001'));
check('money', '−' !== Util::minorToDec(-5, 2) && '-0.05' === Util::minorToDec(-5, 2) && 1023 === Util::fracToMinor('10230/1000', 100) && 1234 === Util::decToMinor('12.34', 2));

// ---- export XML of a synthetic Firefly state can be read back
$ref = new ReflectionClass(FireflyGnuCash\Exporter::class);
$exp = $ref->newInstanceWithoutConstructor();
foreach (['bookCur' => 'EUR', 'decimals' => ['EUR' => 2, 'DEM' => 2], 'fallbackPayee' => '(diverse)', 'reconciledState' => 'y', 'warnings' => []] as $prop => $val) {
    $ref->getProperty($prop)->setValue($exp, $val);
}
$ref->getProperty('nodes')->setValue($exp, ['root' => ['guid' => Util::guid('root'), 'name' => 'Root Account', 'type' => 'ROOT', 'cmdty' => 'EUR', 'parent' => null, 'path' => '', 'placeholder' => false]]);
$accounts = [
    ['id' => '1', 'attributes' => ['type' => 'asset', 'name' => 'Girokonto', 'notes' => "[GnuCash] Aktiva:Barvermögen:Girokonto | BANK | ".md5('test:acct:bank').' | EUR', 'currency_code' => 'EUR', 'active' => true, 'account_role' => 'defaultAsset', 'iban' => null, 'account_number' => null]],
    ['id' => '2', 'attributes' => ['type' => 'asset', 'name' => 'Bargeld', 'notes' => '', 'currency_code' => 'EUR', 'active' => true, 'account_role' => 'cashWalletAsset', 'iban' => null, 'account_number' => null]],
    ['id' => '3', 'attributes' => ['type' => 'expense', 'name' => 'REWE', 'notes' => '']],
    ['id' => '4', 'attributes' => ['type' => 'revenue', 'name' => 'Arbeitgeber', 'notes' => '']],
];
$categories = [
    ['id' => '10', 'attributes' => ['name' => 'Lebensmittel', 'notes' => '[GnuCash] Aufwendungen:Lebensmittel | EXPENSE | '.md5('test:acct:food').' | EUR']],
    ['id' => '11', 'attributes' => ['name' => 'Gehalt', 'notes' => '']],
];
$j = static fn (array $x): array => $x + ['currency_code' => 'EUR', 'foreign_currency_code' => null, 'foreign_amount' => null, 'reconciled' => false, 'notes' => null, 'tags' => [], 'external_id' => null, 'internal_reference' => null];
$groups = [
    ['id' => '100', 'attributes' => ['group_title' => 'REWE Musterstadt', 'created_at' => '2026-01-02T12:00:00+01:00', 'transactions' => [
        $j(['type' => 'withdrawal', 'date' => '2026-01-02T00:00:00+01:00', 'amount' => '12.34', 'description' => 'Brot', 'source_id' => '1', 'source_type' => 'Asset account', 'source_name' => 'Girokonto', 'destination_id' => '3', 'destination_type' => 'Expense account', 'destination_name' => 'REWE', 'category_name' => 'Lebensmittel', 'external_id' => md5('test:tx:1'), 'notes' => "GnuCash-Memo [Girokonto -20.00]: Karte\nGnuCash-Memo [Kategorie: Lebensmittel 0.00]: Tüte gratis"]),
        $j(['type' => 'withdrawal', 'date' => '2026-01-02T00:00:00+01:00', 'amount' => '5.66', 'description' => 'Cola', 'source_id' => '1', 'source_type' => 'Asset account', 'source_name' => 'Girokonto', 'destination_id' => '3', 'destination_type' => 'Expense account', 'destination_name' => 'REWE', 'category_name' => 'Lebensmittel', 'external_id' => md5('test:tx:1')]),
    ]]],
    ['id' => '102', 'attributes' => ['group_title' => 'Bäcker', 'created_at' => '2026-01-03T12:00:00+01:00', 'transactions' => [
        $j(['type' => 'withdrawal', 'date' => '2026-01-03T00:00:00+01:00', 'amount' => '1.30', 'description' => '2 Sesam', 'source_id' => '2', 'source_type' => 'Asset account', 'source_name' => 'Bargeld', 'destination_id' => '3', 'destination_type' => 'Expense account', 'destination_name' => 'REWE', 'category_name' => 'Lebensmittel', 'external_id' => md5('test:tx:77')]),
        $j(['type' => 'withdrawal', 'date' => '2026-01-03T00:00:00+01:00', 'amount' => '1.30', 'description' => '2 Sesam', 'source_id' => '2', 'source_type' => 'Asset account', 'source_name' => 'Bargeld', 'destination_id' => '3', 'destination_type' => 'Expense account', 'destination_name' => 'REWE', 'category_name' => 'Lebensmittel', 'external_id' => md5('test:tx:77')]),
        $j(['type' => 'withdrawal', 'date' => '2026-01-03T00:00:00+01:00', 'amount' => '3.00', 'description' => 'Torte', 'source_id' => '2', 'source_type' => 'Asset account', 'source_name' => 'Bargeld', 'destination_id' => '3', 'destination_type' => 'Expense account', 'destination_name' => 'REWE', 'category_name' => 'Lebensmittel', 'external_id' => md5('test:tx:77')]),
        $j(['type' => 'withdrawal', 'date' => '2026-01-03T00:00:00+01:00', 'amount' => '2.00', 'description' => 'Torte', 'source_id' => '1', 'source_type' => 'Asset account', 'source_name' => 'Girokonto', 'destination_id' => '3', 'destination_type' => 'Expense account', 'destination_name' => 'REWE', 'category_name' => 'Lebensmittel', 'external_id' => md5('test:tx:77')]),
    ]]],
    ['id' => '101', 'attributes' => ['group_title' => null, 'created_at' => '2026-01-31T12:00:00+01:00', 'transactions' => [
        $j(['type' => 'deposit', 'date' => '2026-01-31T00:00:00+01:00', 'amount' => '2000.00', 'description' => 'Gehalt Januar', 'source_id' => '4', 'source_type' => 'Revenue account', 'source_name' => 'Arbeitgeber', 'destination_id' => '2', 'destination_type' => 'Asset account', 'destination_name' => 'Bargeld', 'category_name' => 'Gehalt', 'reconciled' => true, 'tags' => ['Lohn']]),
    ]]],
];
$call = static function (string $m, ...$a) use ($exp, $ref) {
    $meth = $ref->getMethod($m);

    return $meth->invoke($exp, ...$a);
};
$call('buildAccounts', $accounts);
$call('buildCategories', $categories, $groups);
$call('buildTransactions', $groups);
$call('addParents');
$xml  = $call('xml');
$file = sys_get_temp_dir().'/ffgc-test-'.getmypid().'/export.gnucash';
file_put_contents($file, $xml);
$book = FireflyGnuCash\BookReader::read($file, new DateTimeZone('Europe/Berlin'));
$bal  = [];
foreach ($book->transactions as $t) {
    foreach ($t->splits as $sp) {
        $bal[$book->account($sp->account)->path] = ($bal[$book->account($sp->account)->path] ?? 0) + Util::fracToMinor($sp->quantity, 100);
    }
}
check('export balances', ['Aufwendungen:Lebensmittel' => 1800 + 760, 'Aktiva:Barvermögen:Girokonto' => -1800 - 200, 'Aktiva:Bargeld' => 200000 - 560, 'Erträge:Gehalt' => -200000] == $bal, json_encode($bal, JSON_UNESCAPED_UNICODE));
$baker = null;
foreach ($book->transactions as $t) {
    if ('Bäcker' === $t->description) {
        $baker = $t;
    }
}
$items = [];
foreach ($baker->splits as $sp) {
    if ('' !== $sp->memo) {
        $items[] = $sp->memo.' '.$sp->quantity;
    }
}
sort($items);
check('export: equal items stay separate, divided item is merged', ['2 Sesam 130/100', '2 Sesam 130/100', 'Torte 500/100'] === $items, json_encode($items, JSON_UNESCAPED_UNICODE));
check('export keeps GnuCash GUIDs', isset($book->accounts[md5('test:acct:bank')], $book->accounts[md5('test:acct:food')]) && in_array(md5('test:tx:1'), array_map(static fn ($t) => $t->guid, $book->transactions), true));
$rewe = null;
foreach ($book->transactions as $t) {
    if ('REWE Musterstadt' === $t->description) {
        $rewe = $t;
    }
}
$memos = array_map(static fn ($sp) => $sp->memo, $rewe->splits);
sort($memos);
check('export memos (memo split + remainder + zero split)', ['', 'Brot', 'Cola', 'Karte', 'Tüte gratis'] === $memos, json_encode($memos, JSON_UNESCAPED_UNICODE));
$byMemo = [];
foreach ($rewe->splits as $sp) {
    $byMemo[$sp->memo] = [$book->account($sp->account)->path, $sp->quantity];
}
check('export remainder split', ['Aktiva:Barvermögen:Girokonto', '200/100'] === $byMemo[''] && ['Aktiva:Barvermögen:Girokonto', '-2000/100'] === $byMemo['Karte'] && 'Aufwendungen:Lebensmittel' === $byMemo['Tüte gratis'][0], json_encode($byMemo, JSON_UNESCAPED_UNICODE));
$salary = null;
foreach ($book->transactions as $t) {
    if ('Gehalt Januar' === $t->description) {
        $salary = $t;
    }
}
check('export payee memo + tags + reconciled', 'Tags: Lohn' === $salary->notes && in_array('Arbeitgeber', array_map(static fn ($sp) => $sp->memo, $salary->splits), true)
    && in_array('y', array_map(static fn ($sp) => $sp->state, $salary->splits), true), json_encode([$salary->notes, array_map(static fn ($sp) => [$sp->memo, $sp->state], $salary->splits)], JSON_UNESCAPED_UNICODE));

printf("%d checks, %d failures\n", $checks, $failures);
exit($failures > 0 ? 1 : 0);
