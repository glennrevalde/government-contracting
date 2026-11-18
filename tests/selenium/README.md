# G-SMaRT Selenium tests

Automated browser tests of the G-SMaRT Integrated Accounting and Government Contracting System,
written in Python with Selenium. They log in as each role, open every page, fill in and save the
forms, and check what the system shows back. By default Chrome opens on the screen and works at a
person's pace: it types one letter at a time, moves to each button and outlines it in blue before
clicking, outlines each field before filling it, and looks at each page for a moment. Each check
prints a mark in the terminal:

    ✔  passed
    ✘  failed (the reason under it, a screenshot and the page saved in results/failures/)
    ○  skipped (something it needs failed earlier, or it does not apply)

```
Log-in, passwords and sessions
  ✔ The log-in page opens with its fields  0.4s
  ✔ An unknown username is refused  1.2s
  ✔ Every account in accounts.py logs in and sees its own name: 15 passed, 0 failed  18.5s
      ✔ admin (Administrator)  1.2s
      ✔ accountant (Accountant)  1.5s
  ...
Summary
✔ 1077 passed   ✘ 0 failed   ○ 1 skipped   (1078 checks in 28m 05s)
```

## 1. What you need

- Python 3.9 or newer (python.org; tick "Add python.exe to PATH" when installing on Windows).
- Google Chrome (or Microsoft Edge).
- The system running (XAMPP: Apache and MySQL started) with the **demo database**
  (`database/government_contracting_system.sql`) or a test copy of it. Several tests use the demo
  records (customers, items, barangays, the sample contracts). The tests add customers,
  transactions, contracts, users, roles and backups of their own: never run them on the live
  database.

Install Selenium once, from this folder (wherever you put the program, for example):

    cd C:\xampp\htdocs\government-contracting-system\tests\selenium
    pip install -r requirements.txt

Every command below is typed in that `tests\selenium` folder: `run_tests.py` is there, not in the
program's main folder.

Selenium 4.11 or newer finds or downloads the browser driver by itself the first time (it needs the
internet for that once).

The ✔ ✘ ○ marks show in Windows Terminal and in PowerShell. In the old Command Prompt, if they show
as boxes or question marks, run `chcp 65001` first, or add `--no-color` and read `results/run_*.log`.

## 2. Settings

`config.py` holds the settings; an environment variable of the same name, or an option of
`run_tests.py`, overrides each one. In PowerShell: `$env:BASE_URL = "http://localhost/accounting-system"`;
in the Command Prompt: `set BASE_URL=http://localhost/accounting-system`. Slower typing, for
example: `$env:TYPE_DELAY = "0.1-0.25"`.

| Setting | Default | What it is |
|---|---|---|
| `BASE_URL` | `http://localhost/government-contracting-system` | Where the system opens (the folder in htdocs) |
| `BROWSER` | `chrome` | `chrome` or `edge` |
| `HEADLESS` | `0` | `0` shows the Chrome window; `1` hides it (or use `--headless`) |
| `PACE` | `human` | `human`: a person's pace, letter by letter, each button and field outlined before it is used; `fast`: as fast as the browser goes (or use `--fast`) |
| `TYPE_DELAY` | `0.05-0.13` | Seconds between two letters typed (a random time in that span) |
| `TYPE_LETTERS` | `40` | A longer text has its first 40 letters typed one by one and the rest pasted |
| `CLICK_DELAY` | `0.35-0.75` | Seconds on a button or link, outlined, before clicking it |
| `FOCUS_DELAY` | `0.25-0.5` | Seconds on a field, outlined, before filling it or choosing from its list |
| `AFTER_ACTION` | `0.15-0.3` | Seconds after a click or a filled field |
| `PAGE_PAUSE` | `0.5-0.8` | Seconds looking at each new page |
| `BROWSER_BINARY`, `DRIVER_PATH` | empty | Only for a browser or driver in an unusual place |
| `PHP_BIN` | `php` | For `--reset-db`; XAMPP: `C:\xampp\php\php.exe` |
| `PROJECT_DIR` | the folder two levels up | For `--reset-db`: the program folder whose `config\config.php` names the database |
| `TIMEOUT` | `15` | Seconds to wait for a page or an element |

For the most thorough run, let the system show PHP warnings in the pages while testing: set
`'debug' => true` in `config/config.php` (put it back to `false` afterwards). The tests then also
catch PHP warnings and notices, not only errors.

## 3. Accounts

`accounts.py` lists every login with its password and role (commented). The tests take their
logins from it. To see them:

    python accounts.py

admin logs in with `Jeglen24#`. On a fresh demo or clean database admin has the temporary password
`Admin@12345` and must replace it at the first log-in: the tests do that and set `Jeglen24#`, so
the same password works from then on. If you change a password by hand, change it in
`accounts.py` too.

With several companies (System Administration > Multi-Company Setup done), the Super Admin
`superadmin` / `Jeglen24##` is in `PLATFORM_ACCOUNTS`: the `platform` suite logs in with it and
checks the platform pages. On a one-company installation that suite is skipped (○).

## 4. Running

    python run_tests.py --suite sop           one suite, in a Chrome window, at a person's pace
    python run_tests.py                       everything at a person's pace (about 2 hours)
    python run_tests.py --fast                everything at full speed (about 30 minutes)
    python run_tests.py --fast --headless     the same with no window (to leave it running)
    python run_tests.py --quick               a shorter run (leaves out the long crawls)
    python run_tests.py --list                the suites and their cases
    python run_tests.py --suite auth,sop      only these suites
    python run_tests.py --suite sop --case canvass     only the cases whose name has "canvass"
    python run_tests.py --slow 1              one more second on each page
    python run_tests.py --base-url http://localhost/accounting-system
    python run_tests.py --reset-db --yes      re-install the demo database first (ERASES it)
    python run_tests.py --failfast            stop at the first failure

While Chrome works, leave its window alone: a click or a key pressed in it can make a check fail.
Closing the window stops the run.

`--reset-db` runs `php tools/install.php --drop --demo` on the database named in
`config/config.php`, so every run starts from the same demo data. Use it only on a test machine.
With several companies it re-installs only that company's database (Company 1): the platform
database, the Super Admin and the other companies stay as they are.

The exit code is 0 when nothing failed and 1 otherwise, so the run can be used in a scheduled task.

## 5. The suites

| Key | Suite | What it checks |
|---|---|---|
| `auth` | Log-in, passwords and sessions | Log-in page; unknown user and wrong password refused (attempts left shown); every account in accounts.py logs in; admin's first log-in replaces the temporary password; pages need a log-in; log-out; password rules (wrong current, mismatch, length, case, number, symbol, same as before); a form without its security token is refused; unknown page |
| `platform` | Platform of several companies (Super Admin) | The Super Admin logs in on the log-in page (and a wrong password is refused); every platform page opens; every company database is up to date; a company is opened and left again; a wrong current password is refused on My account; the platform audit log; the pages on a phone; log-out; company users kept out. Skipped on a one-company installation |
| `pages` | Every page opens | All 226 pages open for the administrator with no server, PHP or browser (JavaScript) error; in a full run also every record, tab and form one click away from each page |
| `access` | Page access by role | For each of the 15 accounts: every page of its menu opens, every page outside it is refused (Access denied, recorded in the audit trail), and its role reaches the pages it is described with and not those of other roles |
| `mobile` | Every page on a phone | The log-in page and every page fit a 390 px phone without sideways scrolling; the menu opens from its button |
| `reports` | Every report | All 129 reports run, take a date filter, print, export to a spreadsheet and open their first drill-down; reports with demo data show rows; the trial balance and the balance sheet balance |
| `admin` | Home, Administration, Company and Manual | Dashboard, Report Center, memorized reports, saved report layouts, Search and Find, transaction history; users (create, first log-in, deactivate, lock-out after failed log-ins and unlock), roles and page access, effective access, settings, closing date, audit trail, verify data, CSV import, backup, multi-company page, company pages; the User's Manual and the Help buttons |
| `accounting` | Chart of Accounts, Banking, BIR and Fixed Assets | Accounts, sub-accounts, classes, names, lists, journal entries (unbalanced refused), memorized transactions, budgets, default accounts; checks, cash voucher, deposits, transfers, register, a full bank reconciliation; withholding tax table and BIR Form 2307; asset categories, locations, register, depreciation, maintenance, preventive maintenance, disposal; what the auditor and the A/R clerk may not do |
| `sales_purchases` | Customers and Vendors | Customers and vendors; estimate to sales order to invoice, payments (partial, full, over), sales receipts, statement charges, credit memos and refunds, batch invoices, statements, finance charges, forecast; purchase orders, receiving, bills, bill tracker, pay bills, debit memos, advances, receipt management; void and delete; print views; what the sales rep, A/P clerk and auditor may not do |
| `inventory_pos` | Inventory and Point of Sale | Categories, sites, U/M sets, items, adjustments, transfers, assembly builds, processing formulas and orders, physical count, prices and price levels; POS terminals, approval codes, shifts, cash sale, return and void with the supervisor, cash drop, X- and Z-readings, receipts; what the cashier and the inventory clerk may not do |
| `barangay` | Barangay Contracts | A contract through the twelve phases: procuring entity and officials, opportunity, compliance checklist from the vault, price schedule and ABC, bid review and approval, submission, award and security, delivery and acceptance, billing pack and invoice, collection with taxes withheld and 2307, closeout; dashboard, approval queue, vault, lists, settings, the 19 reports; the refusals (over quantity, over ABC, own approval ...); Word, Excel and PowerPoint files (new and 97-2003, from `tests/fixtures/office`) uploaded to the vault and shown in the quick view, with the sheet tabs |
| `sop` | Barangay SOP, Forms and Templates | A small value procurement through all 9 SOP steps (transaction form, papers prepared and signed copies attached, canvass and award, payment hold and cheque, deliver and bill, review by another person, forwarding, transmittal, release, closeout); the types Cash Out (purchases) and Kaliwaan (purchases) and the box ticked on the transaction form; a bidding transaction to step 8 with the additional requirements prepared from the company's forms; skip, reopen, not needed, extra papers, ZIP of signed copies; SOP Steps & Papers (a step limited to some types); forms (formats, both print modes, Word, signed copy); templates (format, letterhead of its own with a picture uploaded, signatories added, moved and removed, footer at the bottom of the page, {{letterhead}} put in the text, pre-printed paper positions, scan, test print, versions and put back); Contracting Settings (types, the people on the forms, the letterheads); monitoring report; the Process tab and the Format tab on a phone |

## 6. Results

Each run writes to `results/`:

- `run_<date>_<time>.log`: everything printed in the terminal, without colours;
- `run_<date>_<time>.json`: each check with its result, time and reason;
- `failures/`: for each ✘, a screenshot (`.png`) and the page as it was (`.html`);
- `downloads/` and `fixtures/`: files the tests downloaded or uploaded (sample PDF, JPG, PNG, CSV).

## 7. When the system changes

- New pages: run `php tests/selenium/gen_pages.php` so the page crawls include them.
- A new case: add a function to the suite file of the module, like this:

```python
from core.runner import suite
from core.fixtures import unique

S = suite('sales_purchases', 'Customers and Vendors', order=60)

@S.case('Create a customer and find it in the Customer Center')
def create_customer(t):
    a = t.app
    a.as_user('arclerk')                 # log in as the A/R clerk (accounts.py)
    a.go('cust.center', a='new')         # index.php?p=cust.center&a=new
    name = unique('Selenium Customer')
    a.fill_form({'name': name, 'terms_id': 'Net 30'})
    a.submit()
    a.expect_saved()                     # the green message
    t.contains(a.main_text(), name)      # ✔ or ✘ with the reason
    t.state['customer'] = name           # for the next cases of the suite
```

`core/app.py` has the moves: `as_user`, `go`, `fill`, `fill_form`, `select`, `submit`, `click`,
`click_and_wait`, `modal`, `expect_saved`, `expect_refused`, `assert_healthy`, `is_denied`, `fetch`
(downloads), `phone` / `desktop`, `overflow_x`.
