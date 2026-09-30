# firefly-gnucash – GnuCash import and export for Firefly III

`firefly-gnucash.php` moves bookkeeping between **GnuCash** and **Firefly III**:

| Command   | What it does |
|-----------|--------------|
| `plan`    | Reads a GnuCash book and writes an editable account mapping, payee rules and reports. Changes nothing. |
| `import`  | Creates currencies, accounts, categories, counterparties and transactions in Firefly III. Safe to re-run. |
| `export`  | Writes the Firefly III data as a GnuCash XML book (`.gnucash`, gzip). |
| `compare` | Compares the account balances of two GnuCash books, e.g. the original and an export after the import. |
| `purge`   | Deletes what an import created – for test runs. |

`web.php` does the same in the browser (see [Web interface](#web-interface)).

It is a standalone PHP script that talks to the **Firefly III REST API** with a Personal Access
Token. It does not change Firefly III itself and works with every Firefly version that has the
v1 API. Unofficial, not supported by the Firefly III project. Created with AI (Claude by
Anthropic). License: GPL-3.0-or-later.

> Split transactions with several source accounts (e.g. paid partly with a voucher, partly in
> cash) are imported as **one** Firefly split transaction. That needs the fork
> [firefly-iii-multisource](https://github.com/Simon0Harms/firefly-iii-multisource). On
> upstream Firefly the importer notices the rejection and books them as separate transactions
> (or set `"multisource": false`).

## Requirements

* PHP ≥ 8.1 (CLI) with `curl`, `xml` (dom, xmlreader, xmlwriter), `zlib`, `mbstring`, `bcmath`.
  All of them are installed in every Firefly III installation, e.g. in the community-scripts LXC.
  `pdo_sqlite` only for GnuCash books saved as SQLite.
* A Firefly III **Personal Access Token**: *Options → Profile → OAuth → Personal Access Tokens → Create new token*.
  Creating a currency that Firefly does not know yet (e.g. **DEM**) needs the Firefly owner account.
* GnuCash books in XML (compressed or not) or SQLite format. Close GnuCash (or copy the file) first.

## Installation in the Firefly LXC

Keep the tool, the book, the mapping files and the token together in a directory that Firefly
updates leave alone – **not** in the Firefly code directory, which an update replaces. With the
update script `firefly-update` of [firefly-iii-multisource](https://github.com/Simon0Harms/firefly-iii-multisource)
use `/opt/firefly/shared/gnucash`: its first run moves everything else directly below
`/opt/firefly` into a release directory that later updates delete. Otherwise e.g. `/opt/firefly-gnucash`:

```bash
mkdir -p /opt/firefly-gnucash && cd /opt/firefly-gnucash
curl -fsSLO https://raw.githubusercontent.com/Simon0Harms/firefly-iii-gnucash/main/firefly-gnucash.php
curl -fsSLO https://raw.githubusercontent.com/Simon0Harms/firefly-iii-gnucash/main/web.php   # optional: web interface
printf '%s' 'PASTE-THE-TOKEN-HERE' > token && chmod 600 token
export FIREFLY_URL=http://localhost        # or https://host/firefly behind a reverse proxy
```

## Quick start

```bash
php firefly-gnucash.php plan  meinbuch.gnucash
#   -> meinbuch.import.json          account mapping + options      (review, edit)
#   -> meinbuch.payee-rules.txt      rules for counterparties       (edit)
#   -> meinbuch.payees.csv           resulting counterparties       (check)
#   -> meinbuch.payee-map.csv        booking text -> counterparty   (check)
#   -> meinbuch.payee-suggestions.txt  proposed merge rules         (copy what you like)
# edit, run plan again, repeat until the counterparties look right

php firefly-gnucash.php import meinbuch.gnucash --token-file=token --dry-run   # what would happen
php firefly-gnucash.php import meinbuch.gnucash --token-file=token             # do it
php firefly-gnucash.php export firefly.gnucash --token-file=token
php firefly-gnucash.php compare meinbuch.gnucash firefly.gnucash               # should say "Identical"
```

For a first test, import one year only (`--from=2025-01-01 --to=2025-12-31`, balances are then
incomplete) or a few transactions (`--limit=200`), look at the result in Firefly and remove it
again with `php firefly-gnucash.php purge --accounts --token-file=token`. Counterparty names are
always derived from the whole book, so importing year by year gives the same names as one run.

## Web interface

`web.php` offers the same steps in the browser, in German or English: upload a book, check the
counterparties, edit the payee rules and the account mapping, dry run, import in the background
with progress, export and compare, and delete test imports. **Create rule** turns a typed text
(e.g. `DB Hamburg`: words in this order, all words in any order, text begins with / contains it,
optionally only in the recipient name after the last `;`) into a rule and shows before saving
which booking texts it catches, which counterparty they have now and get then, and similar texts
it misses; suggestions and every row of the counterparty tables can be opened there to adjust. It calls `firefly-gnucash.php` next to it, so both files belong together. Every browser
only sees its own uploads.

**Quick start** on the machine with the files (from elsewhere: `ssh -L 8090:127.0.0.1:8090 host`):

```bash
php -d upload_max_filesize=200M -d post_max_size=200M -S 127.0.0.1:8090 web.php
# open http://127.0.0.1:8090 - the built-in server is fine for one user; for several use Apache/nginx
```

**Apache** (e.g. in the Firefly LXC), only `web.php` becomes reachable:

```apache
# /etc/apache2/conf-available/firefly-gnucash.conf, then: a2enconf firefly-gnucash && systemctl reload apache2
Alias /gnucash /opt/firefly-gnucash/web.php
# protect it with Basic Auth here or at the reverse proxy (Apache allows no comments after a directive)
<Location /gnucash>
    Require all granted
</Location>
php_admin_value upload_max_filesize 200M
php_admin_value post_max_size 200M
```

**nginx + PHP-FPM:**

```nginx
location = /gnucash {
    include fastcgi_params;
    fastcgi_param SCRIPT_FILENAME /opt/firefly-gnucash/web.php;
    fastcgi_pass unix:/run/php/php8.4-fpm.sock;
    client_max_body_size 200m;
}
```

Raise `upload_max_filesize` and `post_max_size` in the php.ini of the web server (default 2 MB)
and, behind a reverse proxy such as NPMplus, its upload limit too. Long imports need no long
timeouts: they run as a background process (`php firefly-gnucash.php import …`) and the page only
polls their progress; you can close the page and come back. The web server PHP needs `proc_open`
and the PHP command line binary (installed with Firefly; set `php_cli` if it is not found).

**Settings:** copy `web.config.example.php` to `web.config.php`:

| Key | Default | Meaning |
|---|---|---|
| `firefly_url` | empty | fixed Firefly URL; the field becomes read-only (recommended with several users) |
| `data_dir` | system temp dir | uploads, reports and logs; must **not** be inside the web root |
| `retention_hours` | `24` | workspaces not used for this long are deleted |
| `max_upload_mb` | `200` | upload limit (the PHP limits apply as well) |
| `php_cli` | auto | PHP command line binary for the background jobs |
| `cacert` | empty | CA bundle for a Firefly with a self-signed certificate |
| `timezone` | PHP setting | for old GnuCash dates without neutral time |

**Security.** `web.php` has no login of its own: bind it to 127.0.0.1 or protect it at the web
server or reverse proxy (Basic Auth). The Personal Access Token is typed in the browser, sent only
with the action that needs it (use HTTPS), and handed to the background process in its
environment – it is not written to disk or into logs. "Remember in this browser tab" keeps it in
the tab's `sessionStorage`. Each browser gets a random workspace (`data_dir/<id>`, mode 0700)
bound to a signed cookie; "Delete workspace" removes it at once. Requests are protected against
CSRF, the page has a strict Content Security Policy. Without `firefly_url` the server connects to
the Firefly URL a user enters.

## How GnuCash is mapped to Firefly III

### Accounts (`*.import.json`)

Every GnuCash account that has transactions gets an entry, keyed by its GnuCash GUID:

```json
"2425d341…": {"gnucash":"Aktiva:Barvermögen:Bargeld","type":"CASH","splits":2448,"as":"asset","name":"Bargeld","role":"cashWalletAsset","currency":"EUR","active":true},
"a1b2c3d4…": {"gnucash":"Aufwendungen:Lebensmittel:Getränke","type":"EXPENSE","splits":492,"as":"category","name":"Lebensmittel:Getränke"},
```

| GnuCash type | default `as` | Firefly |
|---|---|---|
| BANK, ASSET, RECEIVABLE, STOCK, MUTUAL | `asset` | asset account, role `defaultAsset` (`savingAsset` if the path contains Tagesgeld/Spar/Festgeld/Depot/Vorsorge…) |
| CASH | `asset` | asset account, role `cashWalletAsset` |
| CREDIT, and LIABILITY accounts named like a credit card | `asset` | asset account, role `ccAsset` (credit card – payments are transfers, as Firefly recommends) |
| LIABILITY, PAYABLE | `liability` | liability, `liability_type` `debt` (`loan` for "Kredit…/Darlehen"), `direction` `debit` (= "I owe this debt") |
| EXPENSE, INCOME | `category` | **category**, name = GnuCash path without the top level (`Lebensmittel:Getränke`); names that would be ambiguous (`Aufwendungen:Geschenke` / `Erträge:Geschenke`) keep the full path |
| EQUITY | `category` | category – except the first transaction of an asset account against equity, which becomes the Firefly **opening balance** |
| TRADING | `ignore` | GnuCash currency trading splits are dropped, the amounts in both currencies are kept |

You can change `as`, `name`, `role`, `liability_type`, `direction`, `currency`, `active`,
`include_net_worth`, `iban`, `account_number`. Two GnuCash accounts with the same `name` end up
in the same Firefly account/category (merging). `"as": "skip"` skips every transaction that
touches the account. If an asset/liability/category with that name already exists in Firefly,
the import **uses it** (the dry run lists these accounts – check that it does not hold the same
transactions already). Running `plan` again keeps your edits and adds new GnuCash accounts.

Imported accounts and categories carry a line `[GnuCash] <path> | <type> | <guid> | <currency>`
in their notes; `export` uses it to restore the GnuCash account tree.

### Counterparties (payees)

Firefly needs an expense account (withdrawal) or revenue account (deposit) on every booking,
GnuCash has none. The counterparty is derived from the booking text:

1. **Rules** in `*.payee-rules.txt`, first match wins:
   ```
   /^(AMZN|AMAZON|WWW\.AMAZON|Amazon\.de)/i  => Amazon
   REWE                                      => REWE
   /Ihr Einkauf bei ([^,;]+)/i               => $1
   iban:DE89370400440532013000               => Stadtwerke
   category:/^Lebensmittel/                  => {category}
   memo:/Gutschein/i                         => -
   ```
   Example – Amazon per country (tested with a real book):
   ```
   /pay\.amazon\.|\bP0[12]-\d{7}-\d{7}\b|amzn\.com\/pmts/i           => Amazon Pay
   /amazon\.co\.uk|AMZN\s*Mktp\s*(UK|GB)\b|AMAZON\.LUX\.UK/i          => Amazon UK
   /amazon\.fr\b|AMZN\s*Mktp\s*FR\b/i                                 => Amazon FR
   /amazon\.it\b|AMZN\s*Mktp\s*IT\b/i                                 => Amazon IT
   /amazon\.es\b|AMZN\s*Mktp\s*ES\b|MADRID\s*BRANCH/i                 => Amazon ES
   /amazon\.com\b|AMZN\s*Mktp\s*US\b/i                                => Amazon US
   /amazon\s*\.de\b|AMZN\s*Mktp\s*DE\b|EU-DE\b|NIEDERL\s*ASSUNG\s*DEUTSCHLAND|AMAZON\.LUX\.DE|Javari\s*DE\b|\b(028|30[2-6])-\d{7}-\d{7}\b/i => Amazon DE
   /^(?!.*(Gutschein|Punkte|verkauft)).*\b(AMAZON|AMZN)\b/i           => Amazon (Land unbekannt)
   ```
   In that book the order numbers `028-…` and `302-…` to `306-…` only ever appeared together
   with a German marker – check this against your own `*.payee-map.csv`. Vouchers, points and
   resales are left out of the last rule.

   Patterns: `/regex/flags`, plain text (case-insensitive substring), `iban:`, `category:`,
   `memo:`. The counterparty may use `$1…$9`, `{category}`, `{description}`; `-` means the
   fallback counterparty. A text rule changes only the bookings whose text it matches – other
   texts with the same IBAN keep their own counterparty; `iban:` moves all bookings of an IBAN.
   A text pattern also matches the purpose of a transfer to someone else
   (`ÜBERWEISUNG 6 SESAM - BÄCKER ERIKA MUSTER; MARTHA BEISPIEL`); `(?![^;]*;)` limits it to
   the name after the last `;`: `/\bErika\s+Muster\b(?![^;]*;)/i => Bäcker Erika Muster`.
2. **Automatic** – variants of the same booking text become one counterparty:
   * details in brackets are dropped, also without closing bracket:
     `Bäcker Erika Muster ( für 21.03)`, `Bäcker Erika Muster ( für 4.04`,
     `Bäcker Erika Muster (voraus für Samstag)` → `Bäcker Erika Muster`;
   * dates, times, amounts, reference numbers, country codes, card terminal suffixes
     (`//CITY/DE`) and `SAGT DANKE` are dropped, also words left dangling at the end (`für`, `vom` …);
   * `Name + extra words` → `Name` if `Name` (at least two words) is itself the text of at
     least two bookings: `Bäcker Erika Muster nachbezahlt` → `Bäcker Erika Muster`
     (option `payee_merge_prefix`);
   * bank texts `PURPOSE; NAME` → `NAME`, and `NAME//CITY/DE; Basislastschrift …` → `NAME`;
     card payments booked against the card issuer (`Kartenverfügung HERMES GERMANY/HAMBURG/DE …;
     DZ BANK AG`, `Gutschrift von eBay …/LU …`) → the merchant; manual texts `Payee - details` →
     `Payee`; PayPal/SumUp merchants (`Ihr Einkauf bei X`, `PAYPAL *X`) → `X`;
   * texts with the same counterparty IBAN (from the GnuCash bank memo `Konto <IBAN> Bank <BIC>`)
     and texts that only differ in case, spaces, digits or punctuation are merged. IBANs of card
     payments and IBANs that appear with unrelated names (settlement accounts of card issuers or
     payment providers) are not used for merging.
3. Automatic counterparties used by fewer than `payee_min_count` transactions (default 2) get
   the fallback `payee_fallback` (default `(diverse)`; `{category}` is also possible).

`plan` writes the result to `*.payees.csv` (one row per counterparty) and `*.payee-map.csv`
(one row per booking text), both open in LibreOffice/Excel, and proposes merge rules in
`*.payee-suggestions.txt` – by first word (`REWE Musterstadt`, `Rewe - Musterstadt`, `REWE Kartenwelt`
→ `REWE`) and by two shared words (`Bäckerei Erika Muster Bäckerwagen` → `Bäcker Erika Muster` via
`/\bErika\s+Muster\b/i`).
The booking text itself is always kept as the Firefly description. The counterparty accounts get
the IBAN where it is unambiguous – the Firefly Data Importer can then match future bank imports.

### Transactions

A GnuCash transaction has any number of splits; a Firefly journal always goes from one account
to another, and a Firefly transaction group has only one type. The importer therefore splits
every GnuCash transaction into money flows and books them as

* one **withdrawal** group (expenses, also paid from several accounts at once),
* one **deposit** group (income and refunds),
* one **transfer** group per pair of asset/liability accounts.

Examples: an itemised receipt → one split withdrawal (the split memos become the descriptions);
a pay slip (gross salary, taxes, net payment) → deposit of the gross amount plus withdrawals of the
deductions; bottle deposit returned while shopping → withdrawal plus deposit; a refund (credit on
an expense account) → deposit with the expense category; a re-booking between income and expense
accounts without any bank account → via the technical asset account `GnuCash-Umbuchungen`
(balance always 0, not in net worth); DEM/EUR transactions → amount in the account currency plus
foreign amount. `plan` checks for **every** transaction that each account and category moves
exactly the GnuCash amounts ("Self-check: OK") before anything is sent to Firefly.

| GnuCash | Firefly |
|---|---|
| description | description (title of the split transaction) |
| split memo | description of the split, otherwise a line `GnuCash-Memo [account amount]: memo` in the notes |
| notes | notes |
| number (num) | internal reference |
| transaction GUID | external ID (used to detect what was already imported) |
| reconciled state `y` | reconciled (option `reconciled_states`, e.g. `"cy"` to include cleared) |
| – | tag `GnuCash-Import` (option `import_tag`, empty = no tag; deleting the tag in Firefly later keeps the transactions) |

Not imported (no Firefly equivalent): budgets, scheduled transactions, invoices/bills, customers,
vendors, prices, lots, split actions, online-banking settings, GnuCash import match data.
Transactions whose amounts are all zero are skipped; zero-amount splits are kept as memo lines.

### Re-running

The import is idempotent: transactions whose GUID already exists in Firefly with all their
splits are skipped, incomplete ones (e.g. after Ctrl-C) are deleted and created again. Accounts,
counterparties and categories are matched by name. So you can stop and restart at any time, or
import a GnuCash book again after adding transactions in GnuCash.

After changing the payee rules, a new run lists the already imported transactions whose
counterparty would now be different, but does not change them. `--update-payees` moves them
to the new counterparties in place (same Firefly transactions, amounts, notes and reconciled
state; counterparties you changed by hand in Firefly are set back to the rule result) and
deletes counterparties created by the import that are left without transactions. Try it with
`--dry-run` first. Only counterparties and categories needed by the transactions of the run are
created. After a full import the
balances of all imported accounts are compared with GnuCash. Every transaction is logged to
`*.import-log.jsonl` (with the payload of failed ones). Speed is roughly 5–10 transactions per
second (rules and webhooks are switched off during the import: options `apply_rules`, `fire_webhooks`).

If you change the mapping or rules after an import, `purge --accounts` and import again.

## Export: Firefly III → GnuCash

```bash
php firefly-gnucash.php export firefly.gnucash --token-file=token [--from=2025-01-01 --to=2025-12-31] [--uncompressed]
```

* Asset accounts and liabilities → GnuCash accounts. Imported ones get back their original path,
  type and GUID; others go below `Aktiva` / `Fremdkapital` (type BANK, CASH, CREDIT or LIABILITY).
* **Categories → expense/income accounts**, again with the original path for imported ones,
  otherwise `Aufwendungen:<category>` or `Erträge:<category>` (depending on whether the category
  is mostly used for withdrawals or deposits; `:` in names creates sub-accounts). Withdrawals and
  deposits without category go to `Aufwendungen:Ohne Kategorie` / `Erträge:Ohne Kategorie`.
* Opening balances → `Anfangsbestand` (other currencies `Anfangsbestand - DEM`), reconciliations
  → `Kontoabgleich`.
* Journals with the same GnuCash GUID are merged into one GnuCash transaction again and the
  original splits are restored from the `GnuCash-Memo` lines; other Firefly transactions become
  one GnuCash transaction each. For transactions created in Firefly the counterparty is written
  into the split memo (GnuCash has no payee), tags into the notes.
* Not exported: budgets, bills/subscriptions, piggy banks, rules, recurring transactions, attachments.

After `import` + `export`, `compare original.gnucash export.gnucash --by-year` shows every account
whose balance differs per year (trading accounts are ignored). Differences that remain by design:
the reconcile state `c` (cleared) unless `reconciled_states` contains it, split actions, empty
placeholder accounts, GUIDs of parent accounts, and splits of the same account within one
transaction may be combined.

## Options (`options` in `*.import.json`)

| Option | Default | Meaning |
|---|---|---|
| `category_names` | `strip-root` | `full-path` = category names with the top-level account (only for new accounts) |
| `payee_min_count` | `2` | automatic counterparties need at least this many transactions |
| `payee_fallback` | `(diverse)` | counterparty for everything else, may contain `{category}` |
| `payee_split_dash` | `true` | `Payee - details` → `Payee` |
| `payee_merge_prefix` | `true` | `Name extra words` → `Name` when `Name` occurs on its own |
| `payee_group_by_iban` | `true` | same counterparty IBAN = same counterparty |
| `payee_set_iban` | `true` | store the IBAN on the Firefly counterparty account |
| `reconciled_states` | `y` | GnuCash reconcile states that mean "reconciled" in Firefly |
| `opening_balances` | `true` | first equity transaction of an asset account → Firefly opening balance |
| `clearing_account` | `GnuCash-Umbuchungen` | technical account for income ↔ expense re-bookings |
| `import_tag` | `GnuCash-Import` | tag on every imported transaction (`purge` uses it) |
| `multisource` | `true` | one split transaction with several source accounts (needs the multisource fork) |
| `apply_rules`, `fire_webhooks` | `false` | Firefly rules / webhooks during the import |

Command line options: `--config=FILE`, `--rules=FILE`, `--from/--to=YYYY-MM-DD`, `--limit=N`,
`--dry-run`, `--yes`, `--update-payees`, `--stop-on-error`, `--no-multisource`, `--log=FILE`, `--url=URL`,
`--token-file=FILE` (or `FIREFLY_URL` / `FIREFLY_TOKEN`), `--cacert=FILE`, `--timezone=TZ`,
`--verbose`, `--quiet`; export: `--reconciled-state=y|c`, `--payee-fallback=NAME`; compare:
`--by-year`, `--ignore=/regex/`; purge: `--tag=TAG`, `--accounts`.

## Tests

```bash
php tests/run-tests.php
php tests/web-tests.php     # web.php over HTTP (built-in server, no Firefly needed)
```

Synthetic GnuCash books covering split receipts, several source accounts, pay slips, refunds,
re-bookings, credit cards, loans, DEM with trading accounts, rounding, opening balances, payee
rules and an export read-back; `web-tests.php` drives `web.php` through its HTTP API. The
workflow `.github/workflows/tests.yml` runs both.

## Troubleshooting

* **HTTP 401** – token wrong or expired. **Redirect** – use the exact base URL (https, subfolder).
* **Cannot create currency DEM** – log in as Firefly owner, create *Deutsche Mark* (code `DEM`,
  symbol `DM`) under *Options → Currencies*, run the import again.
* **HTTP 422 for single transactions** – see `*.import-log.jsonl`; the rest continues, re-running
  the import retries only the failed ones.
* **Self-check failed** – please report it with the listed transaction (a bug in the tool).
* Privacy: the tool only talks to the Firefly URL you give it.

License: GPL-3.0-or-later.
