# Fashion Industries INC — Accounting & Inventory

A Laravel 13 accounting and inventory application. It requires PHP 8.3 or newer, Composer, and Node.js/npm. MySQL 8.0.16+ (or a compatible MariaDB release) is recommended for production; local development defaults to SQLite.

## Get started

1. Clone this repository and enter its directory.
2. Run `composer run setup`. This installs dependencies, creates `.env` and the local SQLite database when missing, generates the application key, runs migrations, and builds the frontend.
3. Run `php artisan db:seed` to create the initial roles, accounts, settings, and users. The default admin and accountant emails are in `.env.example`. Set unique `ADMIN_PASSWORD` and `ACCOUNTANT_PASSWORD` values in `.env` before seeding if desired; otherwise, the seeder generates passwords and prints them once.
4. Run `php artisan storage:link` to enable product image uploads.
5. Run `php artisan serve` and open [http://127.0.0.1:8000/login](http://127.0.0.1:8000/login).

For MySQL, configure the `DB_*` values in `.env` before running setup. Never commit `.env` or production credentials.

For cPanel deployment, see [CPANEL_DEPLOYMENT.md](CPANEL_DEPLOYMENT.md).

## Phase 4 contents
- Login/logout with per-email+IP throttling (5 tries), generic failure message, session regeneration, inactive users blocked (also mid-session).
- Permissions: `App\Support\Permissions` is the single catalogue. Each permission is a Gate ability:
  `->middleware('can:sales.post')`, `@can('sales.cancel')`, `Gate::authorize('sales.post')`. Admin passes everything.
- Accountant defaults (editable at Roles & Permissions): view/create/update masters; create/update/post transactions;
  view accounts, journal, inventory, ledgers, reports (+export), print invoices.
  NOT granted: any cancel/reversal, delete masters, chart-of-accounts changes, manual journals,
  owner capital/drawings and stock-adjustment posting, users, roles, settings, audit log, import.
- Users module: create/edit, roles, deactivate (never delete), password min 10 chars with letters+numbers,
  cannot deactivate yourself or the last active admin.
- Audit log: every create/update/delete on masters and documents, plus posted/cancelled, login, login_failed, logout,
  roles_changed, permissions_changed, password_changed. Passwords/tokens are never logged. Rows are immutable.
- Layout: Bootstrap 5 sidebar built from `config/menu.php`; an item shows only if its route exists and the user holds
  the permission, so later phases light up automatically.

## Phase 5 contents (accounting engine)
- `PostingService` (app/Services/Accounting) is the only way journal entries are created:
  `post([...])` and `reverse($entry, $reason)`. Exact paisa arithmetic (`App\Support\Money`), debits = credits,
  one side per line, active accounts, control accounts must carry the right party (customer / supplier / employee / owner),
  date inside an open fiscal year, all-or-nothing in a DB transaction.
- Cancelling = mirror entry + original flagged `cancelled`; nothing is deleted. Reports must sum ALL lines regardless of status.
  Reversal date = original date (so the period shows no trace), or today if that fiscal year is closed.
- Account codes the engine relies on: `App\Support\AccountCodes`. Reports (Phase 12) group by `accounts.sub_type`.
- Seeders (run by `php artisan migrate --seed`, safe to re-run, never overwrite your edits): chart of accounts (30 accounts),
  PKR currency, fiscal year 2026-27 (1 Jul - 30 Jun), categories and units, company settings (NTN / STRN / Faisalabad address
  from the sample invoice), number sequences (sales invoices continue from 258).
- Screens: Chart of Accounts (live balances from the journal; code/type fixed after creation; system accounts protected)
  and Journal Entries (list, view, manual entry with live balance check, cancel & reverse manual entries only; entries created by
  a sale/receipt/etc. are cancelled from that document).
- New migration `..._000010_add_party_type_to_accounts_table` (run `php artisan migrate`).

## Phase 6 contents (products & inventory)
- `InventoryService` (app/Services/Inventory) is the stock ledger: `receive()`, `issue()`, `reverseSource()`.
  MOVING weighted-average cost in exact integers (qty in thousandths, value in paisa). Example: 100 x 100 then 50 x 120 =>
  150 units, value 16,000, average 106.6667 (covered by a test). Issuing all stock removes exactly the remaining value, so
  rounding never leaves residue. Writers are serialised per product (row lock); rows are append-only; a cancel adds a reversing
  row at the ORIGINAL cost. Cancelling a receipt whose stock was already used is refused. Negative stock only if
  Settings > allow_negative_stock is on.
- Chain order is entry order; txn_date is the business date used for filtering. A back-dated entry costs against the balance
  at the time it is entered (Phase 12 reports can recompute history if you ever need strict date-order costing).
- Stock is never a stored number: quantity, average cost and value come from the latest ledger row.
- Products (image upload: jpg/png/webp, 2 MB, random server-side name; run `php artisan storage:link`), categories, units,
  optional opening stock on product create (Dr Inventory / Cr Opening Balance Equity, atomic).
- Inventory screen: stock value per product + a control check that stock-ledger value equals the Inventory account (1200).
  Inventory Ledger screen: opening, movements by type, closing for a date range.
- Stock adjustments: draft -> posted -> cancelled. In = Dr Inventory / Cr Inventory Gain-Loss at the cost you enter;
  Out = Dr Gain-Loss / Cr Inventory at the average cost. Accountant can save drafts; posting/cancelling is admin-only by default.
- `Money` now handles scaled integers (paisa / thousandths / ten-thousandths); `Qty` wraps it for quantities.

## Phase 7 contents (customers, suppliers, employees, owners)
- Four party screens share one engine (`Parties\PartyController`): list with search, create / edit, ledger, deactivate-or-delete.
  Customer fields include NTN, STRN, CNIC, credit limit, payment terms, default sales-tax rate (18%, changeable per customer)
  and a tax-exempt flag; the invoice (Phase 11) will take buyer details and the tax rate from here.
- Balances are never stored: `PartyLedger::balances()` (one query for a whole list) and `statement()` (running balance,
  opening balance for a date range, cancelled documents shown with their reversal) read journal_entry_lines.
  Positive = natural side (customer owes us / we owe supplier / we owe employee / owner's net capital); negative is labelled
  Advance / Owes us / Overdrawn. Statements are print-friendly.
- Deleting: a party with any journal line or document is deactivated, never deleted.
- Opening balances (customer, supplier, employee): journal entry against Opening Balance Equity, entered on create or from the
  edit page, once per party; cancel (reversing entry) and re-enter to change. Needs journal.create + journal.post (admin by default).
- Owners are separate from customers/suppliers. Owner Capital / Drawings: draft -> posted -> cancelled.
  Capital = Dr Cash/Bank, Cr Owner Capital (owner); Drawings = Dr Owner Drawings (owner), Cr Cash/Bank.
  Only active cash/bank accounts are accepted. Accountant saves drafts; admin posts and cancels.
- Employees are two-way (salary and money they spend for the business raise what we owe; advances and overpayments lower it).

## Phase 8 contents (purchases & purchase returns)
- New migration `..._000011_add_settlement_columns_to_purchases` (paid_now / paid_from_account_id on purchases, refund_now /
  refund_account_id on purchase returns). Run `php artisan migrate`.
- Purchase = draft -> posted -> cancelled. Drafts are editable and deletable; posted purchases are locked.
  Posting is one DB transaction: stock in at each line's NET cost (qty x unit cost - discount, tax excluded) -> moving average updates,
  plus ONE balanced journal entry:  Dr Inventory (per line) / Dr Sales Tax Receivable (input tax) /
  Cr Cash-Bank (paid now) / Cr Accounts Payable (supplier) -- or Cr Employee Payable when an employee paid from his own pocket.
- Walk-in cash purchases need no supplier. Anything not paid in full needs a supplier or the funding employee.
  The same supplier invoice number cannot be entered twice for one supplier (cancelled ones don't count).
- Paid / balance / status are derived (PurchaseSummary, two queries per page): paid = paid at purchase + payments allocated to it;
  balance = total - returns - paid + cash refunds; status unpaid / partial / paid. For a purchase on credit the balance always equals
  its share of the supplier ledger.
- Purchase returns are made against a posted purchase and go back at the ORIGINAL cost (discount and input tax in proportion),
  so inventory value and the journal agree to the paisa; repeated partial returns split cents exactly (3.33 + 3.34 + 3.33 = 10.00).
  Refund: cash/bank now, the rest is a credit note against what we owe (walk-in purchases must be refunded in full).
  Cancelling a purchase is refused if its stock was already used, if payments are applied, or if it has posted returns.
- Cancelling a purchase or a return reverses stock and journal; the original rows are kept.
- Fixed in this phase: deleting a non-draft document used to remove its lines before refusing (purchases, returns and stock
  adjustments); now the status is checked first and the delete is atomic. New users default to is_active = true (matches the column).

## Phase 9 contents (sales & sales returns)
- New migration `..._000012_add_settlement_and_cost_columns_to_sales` (received_now / received_into_account_id on sales,
  cogs_amount on sale items and return items, refund_now / refund_account_id on sales returns). Run `php artisan migrate`.
- Sale (tax invoice) = draft -> posted -> cancelled. Posting is ONE DB transaction: goods out of stock at the moving-average cost
  (refused if not enough stock unless negative stock is enabled) -> next invoice number -> ONE balanced journal entry:
  Dr Cash/Bank (received now), Dr Receivable (customer, the rest), Dr Sales Discounts; Cr Sales (per line, gross), Cr Sales Tax Payable;
  Dr Cost of Goods Sold, Cr Inventory (per line at cost).
- Invoice numbers are assigned when POSTING (drafts carry a throw-away DRAFT-xxxx placeholder), so drafts and deleted drafts never
  leave gaps; a cancelled invoice keeps its number. Numbering continues from 258 (your sample is 257): change it in number_sequences.
- Tax: per line, defaults to the customer's rate (0% if exempt) when the Tax % box is left blank; typing 0 gives a tax-free line.
  Buyer name / address / NTN / STRN are copied onto the invoice when it is posted, so later customer edits never change it.
  OGP / gate-pass number is stored on the sale.
- Credit limit (customer.credit_limit, 0 = unlimited): posting is refused if what the customer already owes + the unpaid part of this
  invoice would exceed it. Raise the limit on the customer record to proceed.
- Cancelling is refused if receipts are applied or returns exist; otherwise stock goes back at the cost it left at and the journal is reversed.
- Sales returns are made against a posted invoice: customer credited the price paid (after discount, tax in proportion), goods return at
  the ORIGINAL cost (stored per line as cogs_amount), cash refund now and/or a credit note against the receivable. Repeated partial
  returns split cents exactly. A return is refused if it exceeds what was sold; cancelling a return is refused if the goods were sold again.
- Paid / balance / status are derived (SaleSummary), never stored. Printing the invoice (original / duplicate / cancelled) is Phase 11.

## Phase 10 contents (receipts, payments, expenses, fund transfers)
- No new migration. Receipts, payments, expenses and transfers are all draft -> posted -> cancelled documents with one balanced journal entry each.
- Receipt: Dr Cash/Bank, Cr Receivable (customer). Payment: Dr Payable (supplier) or Employee Payable (employee), Cr Cash/Bank.
  The WHOLE amount always reaches the party's account; how much is applied to specific invoices / bills is stored as ALLOCATIONS
  (payment_allocations) and replaces the workbook's retype-the-reference matching.
- Allocations: manual per-invoice amounts, or "oldest first" (auto). Drafts hold allocations switched off; posting re-checks what each
  invoice still owes under a row lock (so two people cannot over-apply) and switches them on; cancelling switches them off, so the invoices
  become unpaid again and the journal is reversed (allocation rows are kept as history). Money not applied stays ON ACCOUNT (customer advance,
  supplier advance, employee advance) and can be applied later from the receipt / payment page.
- A purchase an employee paid for is owed to the EMPLOYEE (even if a supplier is recorded on it): pay it with an employee payment.
  Salaries, processing bills and other expenses owed to someone are not bills: payments to them reduce the party balance (balance-forward).
- Expense: paid now from cash/bank OR left owing to a supplier / employee (not both): Dr Expense (+ Dr Input Tax) / Cr Cash-Bank or Payable.
  Only active expense / cost-of-goods accounts are accepted (not bank, sales, receivable ...). Salary = expense on Salaries owed to an employee.
- Fund transfer: Dr receiving cash/bank, Cr paying cash/bank (e.g. the 40,090 cheque cashed at the bank); no profit or loss; must be two different cash/bank accounts.
- An invoice / purchase with receipts or payments applied cannot be cancelled until those are cancelled (existing rule, now exercised end to end).

## Phase 11 contents (invoices & settings)
- No new migration. Printable Sales Tax Invoice at `sales/{id}/invoice`, laid out like your sample PDF: logo (or business name), tagline, address,
  contact line, ORIGINAL / DUPLICATE / CANCELLED label, title, invoice no., your NTN and STRN, date, OGP box, buyer block, the eight-column table
  (Sr #, Description, Quantity, Unit Rate, Sales Tax, Value Ex-Sales Tax, Sales Tax Payable, Value Inc. Sales Tax) padded to ten rows, TOTAL row, footer.
  A Discount column appears only when a line is discounted; "Received" and "Balance" appear under the totals only when part was paid at the sale
  (switchable in Settings). Use the browser's Print -> Save as PDF for a PDF file (A4 print styles included).
- Copies: ORIGINAL is the customer's copy, printed once; printing it again needs the `invoices.reprint` permission (administrator).
  DUPLICATE any number of times. A cancelled invoice has only the CANCELLED copy (red watermark, cancel date and reason). Drafts cannot be printed.
  Looking at the page logs nothing; pressing Print records it (invoices table: one row per copy type with a counter, plus an `invoice_printed` audit entry).
  Viewing needs `invoices.view`; printing needs `invoices.print`.
- Settings screen (administrator): company name / tagline / address / phone / email / NTN / STRN, logo upload (PNG/JPG/WebP, 1 MB, no SVG, random name),
  invoice title, footer, minimum rows, show Received/Balance, default sales-tax rate for new customers, allow negative stock, and invoice numbering
  (prefix, next number, minimum digits). The next invoice number must be higher than any already used and cannot collide.
- Applied from your fix list: complete MorphMap (every audited model), HasFactory on User, Gate::before only short-circuits permission abilities so policy
  guards (no self-deactivation) still apply to administrators, no private test helper named post(), and all Blade `@php(...)` one-liners are now `@php ... @endphp` blocks.
  Rule going forward: any model that is audited or used as a morph subject must have an alias in `App\Support\MorphMap`.

## Phase 12 contents (ledgers and reports)
- No new migration. Every report is a query over `journal_entry_lines` (or the stock ledger), never a stored figure; cancelled documents appear together
  with their reversal, so nothing is hidden and every balance is the true one. All money is added in paisa.
- Ledgers (`/ledgers`): any account, with presets Cash book, Bank book, Expense ledger, Owner capital, Inventory, Receivable / Payable control accounts; filter by
  date, customer / supplier / employee / owner and transaction type (a transaction-type filter shows no running balance, because the rows are then not continuous).
  Customer and supplier statements stay on each party's page (Ledger button); inventory by product is the Inventory ledger.
- Trial Balance (opening / movement / closing, each as debit or credit, with an in-balance check), Profit & Loss (net sales = gross sales less returns and discounts;
  gross profit after cost of goods sold incl. stock gains/losses and processing charges; net profit after operating expenses), Balance Sheet as at a date
  (the profit of all periods is shown on its own equity line until a year-end close exists; Assets = Liabilities + Equity is checked on screen),
  Cash Flow (direct method by kind of transaction, reconciles to cash + bank), Day Book.
- Outstanding Receivables / Payables aged against each invoice's due date (not yet due, 1-30, 31-60, 61-90, over 90). Open amounts are today's position; the date
  only chooses the band. "Opening / on account" is the part of a balance not on an open invoice, so each total equals the party's ledger balance and the grand total
  equals the control account. Payables show suppliers and employees separately. Customer / supplier / employee / owner balances as at any date.
- Stock Valuation at weighted average (as at a date, by category), checked against the Inventory account.
- Listing reports with date range, status and customer / supplier filters: sales (with cost and gross profit), purchases, sales returns, purchase returns,
  expenses, receipts, payments.
- Print (browser, A4-friendly) and CSV on every report. CSV needs `reports.export`; cells that could be read as spreadsheet formulas are neutralised.
- Tests build a month of trading through the real services (tests/Concerns/BooksScenario.php) and check every report against hand-worked figures
  (net LOSS of 2,000; assets 607,700 = liabilities 109,700 + equity 498,000).

## Phase 13 contents (dashboard)
- `/dashboard` now shows (period = this month / this fiscal year / last 30 days / custom): net sales (with invoice count and gross profit), purchases net of
  returns, net profit or loss with expenses, sales tax payable (output less input); and as at today: receivables (with the overdue part), payables
  (suppliers + employees), cash, bank, inventory value; a six-month sales/profit trend; every cash and bank account; low-stock products;
  drafts waiting to be posted (linked to the filtered lists); recent sales, purchases and money movements.
- Everything uses the same sources as the reports (journal, stock ledger), so the dashboard always agrees with them.
- Red banner if the stock ledger and the Inventory account ever disagree, or the journal is out of balance (neither should happen).
- Money cards need `reports.view`; a role with only `dashboard.view` sees stock warnings, drafts and the recent lists its permissions allow.
- Works on an empty installation (no accounts, no data).

## Tests

Run `php artisan test`. The suite uses an in-memory SQLite database by default.
