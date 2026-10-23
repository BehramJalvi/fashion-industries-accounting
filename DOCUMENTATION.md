# Fashion Industries — Application Documentation

This guide describes the application as implemented in the current source tree. It is intended for both day-to-day users and developers. Menu labels below are the labels in the authenticated sidebar; an item is visible only if its route exists and the signed-in user has the corresponding permission.

## Contents

1. [Overview](#1-overview)
2. [Installation and operation](#2-installation-and-operation)
3. [Navigation and modules](#3-navigation-and-modules)
4. [User guide and procedures](#4-user-guide-and-procedures)
5. [Business workflows and accounting effects](#5-business-workflows-and-accounting-effects)
6. [Database and relationships](#6-database-and-relationships)
7. [Calculations and business rules](#7-calculations-and-business-rules)
8. [Roles, permissions, and security](#8-roles-permissions-and-security)
9. [Reports](#9-reports)
10. [Troubleshooting](#10-troubleshooting)
11. [Worked examples](#11-worked-examples)
12. [Maintenance and deployment](#12-maintenance-and-deployment)
13. [Limitations and implementation notes](#13-limitations-and-implementation-notes)
14. [Developer reference map](#14-developer-reference-map)

## 1. Overview

Fashion Industries is a browser-based, single-company inventory, sales, purchasing, production, and bookkeeping application. It is built with PHP 8.4 and Laravel 13.34, uses Laravel routes/controllers/Form Requests/Eloquent models and Blade screens, and stores accounting and inventory activity in ledgers. The configured database at the time of inspection is MySQL; the project also configures SQLite, MariaDB, PostgreSQL, and SQL Server connections. The checked-in `.env.example` selects SQLite, so the actual database is determined by `.env`.

The browser UI uses Bootstrap 5.3.3 and Bootstrap Icons from CDNs, with local frontend assets built through Vite 8 and Tailwind CSS 4. The application is served behind Laravel's authenticated web routes. Its main capabilities are:

- Master records for products, categories, units, customers, suppliers, employees, and owners.
- Purchases and purchase returns; sales invoices and sales returns.
- Stock valuation, inventory ledgers, stock adjustments, and yarn-to-thread production transactions.
- Customer receipts, supplier/employee payments, expenses, cash/bank transfers, and owner capital/drawings.
- Double-entry chart of accounts, journal entries, party ledgers, and financial/operational reports.
- User/role administration, company/invoice settings, and audit-log browsing.

The application uses one shared database and accounting chart; it does not create separate databases for these modules.

## 2. Installation and operation

### Prerequisites

- PHP 8.3 or later (the inspected environment runs PHP 8.4).
- Composer.
- Node.js and npm for Vite asset installation/building.
- A database supported by `config/database.php`. For MySQL/MariaDB, enable the relevant PDO extension and create the database/user before migration.
- A web server or PHP's local development server.

### Fresh local setup

From the project root:

1. Install PHP dependencies:
   ```powershell
   composer install
   ```
2. Create `.env` from `.env.example` if it does not exist. Set `APP_NAME`, `APP_URL`, and a database connection appropriate to your environment.
3. For MySQL, set `DB_CONNECTION=mysql`, `DB_HOST`, `DB_PORT`, `DB_DATABASE`, `DB_USERNAME`, and `DB_PASSWORD` in `.env`; create that database first. For SQLite, set `DB_CONNECTION=sqlite` and ensure the configured `database/database.sqlite` file exists.
4. Generate the application key:
   ```powershell
   php artisan key:generate
   ```
5. Run migrations and seed baseline application data:
   ```powershell
   php artisan migrate
   php artisan db:seed
   ```
   Seeding creates roles/permissions, the chart of accounts, default settings, categories, number sequences, currency, a fiscal year, and initial users. Configure `ADMIN_EMAIL`/`ADMIN_PASSWORD` and `ACCOUNTANT_EMAIL`/`ACCOUNTANT_PASSWORD` in `.env` before seeding if you want controlled credentials. Without a supplied password, the seeder generates one and prints it once; there is no hard-coded default password.
6. Install and compile browser assets:
   ```powershell
   npm install
   npm run build
   ```
7. For a basic local server, run:
   ```powershell
   php artisan serve
   ```
   In a second terminal, `npm run dev` can be used while changing frontend assets. In XAMPP/Apache, configure the virtual host/document root to serve the Laravel `public` directory, not the project root.
8. Open the configured `APP_URL` and sign in using the seeded administrator or accountant credentials.

`composer run setup` is defined in `composer.json`; it installs Composer and npm dependencies, generates a key, migrates, and builds assets. It does **not** call `db:seed`, so run `php artisan db:seed` separately when setting up a fresh database. Review the command before using it with a production `.env`.

### Environment and defaults

- `APP_DEBUG` should be `false` outside local development. Keep `.env` and secrets out of source control.
- `.env.example` defaults to SQLite, database-backed sessions/cache/queue, and log mail. Configure services to suit your deployment.
- Seeded application defaults include base currency PKR, default sales tax 18%, and `allow_negative_stock=false`. Company branding, tax, stock, invoice layout, and invoice numbering can be changed under **Administration → Settings**.
- The fiscal-year seeder creates an open Pakistan-style year from 1 July 2026 through 30 June 2027. Journal posting requires the transaction date to fall within an existing open fiscal year; create/maintain appropriate fiscal-year data before posting dates outside the seeded year.

## 3. Navigation and modules

The sidebar structure comes from [`config/menu.php`](config/menu.php). A user's role can hide links, but direct requests are also authorized server-side.

| Sidebar section | Menu items and purpose |
|---|---|
| Dashboard | **Dashboard** — period figures (when Reports permission is present), stock warnings, recent transactions, and attention items. |
| Master Data | **Products**, **Categories**, **Customers**, **Suppliers**, **Owners / Capital**, **Employees**. Units of measure are managed from Categories. |
| Transactions | **Purchases**, **Purchase Returns**, **Sales**, **Sales Returns**, **Receipts**, **Payments**, **Expenses**, **Fund Transfers**, **Recurring Transactions**, **Bank Reconciliation**, **Owner Capital / Drawings**, **Production**, **Payroll**, **Journal Entries**. |
| Inventory | **Inventory**, **Stock Adjustments**. |
| Accounting & Reports | **Chart of Accounts**, **Fiscal Year Closing**, **Ledgers**, **Trial Balance**, **Profit & Loss**, **Balance Sheet**, **Receivables**, **Payables**, **Stock Valuation**, **Cash Flow**, **Day Book**, **All Reports**. The All Reports screen also links customer/supplier balance reports and document listings. |
| Administration | **Users**, **Roles & Permissions**, **Settings**, **Audit Log**. |

### Module catalogue

- **Products and stock masters:** Products have a unit, optional category, type (`raw_material`, `finished_good`, or `trading`), default purchase/selling prices, tax rate, minimum stock, description, optional image, and active flag. The purchase price is a default, not the inventory valuation cost. Category and unit screens are inline CRUD lists. Products with stock history cannot be deleted; deactivate them instead.
- **Customers and suppliers:** Contact/tax details, payment terms and active status; their running balances are derived from journal lines. Customer records include credit limit, tax rate, and tax-exempt flag. Supplier records include company/contact details, tax IDs, and terms.
- **Employees and payroll:** Employee contact/designation, monthly salary, bank-account number, notes and active status. Payroll periods capture attendance, paid/unpaid leave, allowances, deductions and optional employee-advance recovery; posted runs create salary payables and payslips. Employee payments settle the payable.
- **Owners:** Contact information and optional share percentage. Capital and drawings are separate posted transactions and are not customer/supplier balances.
- **Purchasing and sales:** Documents store headers and item rows. Purchases receive stock; sales issue stock and create an invoice number only when posted. Both support line discounts and tax.
- **Returns:** Each return must refer to a posted source purchase or sale. Quantities are limited to the remaining returnable quantity, and financial amounts/costs are allocated from the source document.
- **Settlements:** Receipts are money received from a customer; Payments are money paid to a supplier or employee. Allocations match those amounts to invoices/bills. The unallocated remainder remains on the party account as an advance/on-account amount.
- **Expenses:** Record an expense as immediately paid from cash/bank or as owed to a supplier/employee. Later settlement is recorded in Payments.
- **Fund transfers:** Move money between two different cash/bank accounts; not income or expense.
- **Production:** Consume one or more raw-material products and receive multiple finished products. The processing fee and consumed-material cost are allocated by each output's estimated production-cost basis, with exact-cent allocation, inventory movements and a balanced journal.
- **Stock adjustments:** Record stock gain or loss with a reason. Inbound quantity requires a unit cost; outbound quantity is costed at moving average.
- **Accounting:** Chart of Accounts, manual journal entries, general ledger, party ledgers, and standard financial reports. Document-generated journal entries must be corrected from their source transaction, not directly from Journal Entries.
- **Purchase and sales documents:** Both purchase documents and sales invoices have printable itemized documents with company/party details, taxes, payment status, cancellation marking and print tracking.
- **Recurring transactions and bank reconciliation:** Schedules generate reviewable expense/transfer drafts and keep execution history. Bank statements can be entered manually or previewed from CSV, matched against posted bank-account journal lines, and reconciled without changing posted entries.
- **Administration:** Manage users, role permissions, company/invoice settings, and audit history.

## 4. User guide and procedures

### 4.1 General document lifecycle

Most transaction forms provide **Save as draft** and **Save & post** (if the user has the post permission). A draft is stored but has not yet changed stock or posted accounting. Purchases, sales, expenses and fund transfers have draft edit screens; other draft-capable modules may only support deletion/recreation. A posted transaction affects its module ledgers and usually has a journal entry. It is not edited or deleted; use **Cancel** and provide a reason when supported. Cancellation appends reversing journal and/or stock movements and marks the source document cancelled. The original remains available for audit. There is no separate approval/approval-pending workflow: posting is the authorized action that makes the transaction effective.

Some modules (manual Journal Entries, opening balances, and opening stock) post immediately rather than going through a document draft.

### 4.2 Set up units, categories, and products

1. Open **Categories**. Use **Add category** with `Name` and optional `Description`; edit a row and press **Save** to rename, update its description, or change `Active`. Delete is available only if no products use it.
2. From Categories choose **Manage units of measure**. Use **Add unit** with `Name` and optional `Short name` (for example, kg). Edit with **Save**; delete is refused while products use the unit.
3. Open **Products → Create product**. Enter `Code` (optional/auto-generated), `Name`, `Category`, `Unit`, `Type`, `Purchase price`, `Selling price`, optional `Tax rate %`, `Minimum stock`, `Description`, `Active`, and optional image. The image accepts JPG/PNG/WebP, maximum 2 MB.
4. Optionally enter `Opening stock` `Quantity`, `Unit cost`, and `As of date` when creating the product. This posts an opening stock movement and an opening-equity journal entry atomically. Do not enter opening quantity again later; use a stock adjustment.
5. Press **Create product**. Search the Products list by name/code and use category/status/low-stock filters. Use **Edit** to update master data. A product with inventory history cannot be deleted.

### 4.3 Create parties and enter opening balances

1. Open **Customers**, **Suppliers**, or **Employees**, then choose the create action.
2. Enter the displayed party fields. Customer fields include `Code`, `Name`, company/contact, phone/email/address, NTN/STRN/CNIC, `Credit limit`, `Payment terms (days)`, `Sales tax rate %`, `Tax exempt`, `Active`, and notes. Supplier fields include code/name, company/contact, phone/email/address, NTN/STRN, payment terms, active, and notes. Employee fields include code/name, designation, phone, `Monthly salary (PKR)`, bank account number, active, and notes.
3. If available and authorized, the new-party form has an optional `Opening balance (PKR)`, `Direction`, and `As of` date. Choose the direction carefully: customer owes us; we owe customer (advance received); we owe supplier; supplier owes us (advance paid); we owe employee; or employee owes us (advance given). It posts against Opening Balance Equity.
4. Press **Create**. Open the party list and choose **Ledger** for a dated, printable statement. Search by name/code and filter by active status. Party records with transaction history cannot be deleted; mark them inactive.
5. To replace an opening balance, use the opening entry section on the party edit screen to cancel the existing opening entry, then enter a new opening balance. The opening entry itself is not edited.

Owners are created under **Owners / Capital**. Owner records have `Name`, `Share %`, phone/email, active, and notes. Opening-balance entry is not supported for owners; use Owner Capital / Drawings transactions.

### 4.4 Enter a purchase

1. Open **Purchases → New purchase**.
2. Enter `Supplier` (or choose Walk-in / cash purchase), `Date`, optional supplier invoice/reference and due date, and optional notes. If an employee paid personally, select `Paid by an employee from his own pocket?`; this routes the unpaid amount to that employee rather than a supplier.
3. Add one or more item rows: `Product`, `Qty`, `Unit cost`, optional `Discount`, and `Tax %`. Product purchase price may be used as a form default. Stock cost is the line amount after discount and before tax.
4. Enter `Paid now (PKR)` and `Paid from` cash/bank account if part was paid immediately. Leave paid now at zero for an on-credit purchase. The remainder is payable to the supplier or funding employee. A walk-in purchase should be settled in cash/bank because it has no supplier account.
5. Press **Save as draft** or **Save & post**. Open the purchase to review/post a draft, view the generated accounting/stock information, or cancel a posted purchase. Purchase drafts have edit/update support; posted documents are corrected by cancellation.
6. On the purchase detail, choose **Print purchase document** to open the printable supplier/company document. It includes item totals, taxes, paid/returned amounts, balance and cancellation status; print access is permission-controlled and recorded.

### 4.5 Process a supplier purchase return

1. Open **Purchase Returns → Create** and select a posted purchase, then press **Continue**.
2. Enter the return quantity against each product, `Return date`, optional `Reason` and `Notes`, and any `Cash refunded now (PKR)` plus `Refund received into` cash/bank account.
3. Any amount not refunded immediately reduces the amount payable to the supplier or employee associated with the purchase. A walk-in purchase with neither supplier nor funding employee must be refunded in full.
4. Save as draft or post. The app rejects quantities over what remains from the original purchase. It returns items at original purchase cost and reverses proportional input tax.

### 4.6 Create and post a sales invoice

1. Open **Sales → New sales invoice**.
2. Choose `Customer`; set `Date`, optional `Due date`, `OGP / gate pass no.`, and notes.
3. Add item rows with `Product / description`, `Qty`, `Rate`, optional `Discount`, and `Tax %`. Blank tax uses the customer's effective tax rate; entering `0` explicitly makes that line tax-free. The customer may be marked tax-exempt.
4. Enter `Received now (PKR)` and `Received into` cash/bank account for money collected immediately. The remainder becomes the customer's receivable; the form displays an in-stock hint.
5. Press **Save as draft** or **Save & post**. Posting checks available stock (unless negative stock is enabled), costs goods out at moving average, creates the invoice number, and posts accounting. Insufficient stock/credit-limit failures leave the document draft rather than partially posting.
6. On the posted sale, use **Print original**, **Duplicate**, or, when permitted, **Reprint original**. A cancelled sale can show a **Cancelled copy**. Company name/address/tax IDs/logo, title, footer, table minimum rows, and received/balance display are controlled by **Settings**. Invoice print access is permission-controlled and tracked by copy type.
7. To correct a posted sale, cancel it from its detail screen if permitted; this reverses the journal and stock movements. Do not cancel its journal entry directly.

### 4.7 Record a sales return

1. Open **Sales Returns → Create**, choose a posted invoice, and press **Continue**.
2. Enter return quantities only up to `Can still return` for each item, `Return date`, optional `Reason`/`Notes`, and `Cash refunded to the customer now (PKR)` plus `Refund paid from` if refunding cash/bank.
3. Any remaining credit reduces the customer's receivable. Save as draft or post.
4. Posting brings the returned items back at the exact cost recorded when sold, reverses the corresponding revenue/tax/COGS, and applies the refund/credit. Subsequent returns are limited by quantities already returned on posted returns.

### 4.8 Convert yarn into thread and record processing fees

1. Ensure yarn and thread exist as active products, with appropriate units and types. Purchase/receive yarn first, or record its opening stock. Confirm the yarn stock is sufficient unless negative stock is intentionally enabled.
2. Open **Production → New production**.
3. Enter `Date` and optional batch/job reference in `Notes`.
4. Under **Raw material consumed**, add one or more rows of yarn/raw-material `Product` and `Quantity consumed`.
5. Under **Finished products produced**, add each distinct finished product and its `Quantity produced`. For multiple outputs, enter each product's positive estimated production cost as the allocation basis; the combined material and processing cost is then distributed by those bases, not by raw quantity.
6. Under **Processing fee**, enter `Fee (PKR)`. If paid immediately, choose `Paid now from (cash / bank)`. If unpaid, leave it blank and select `Processor / supplier`; a supplier is required for a positive fee left payable. Do not also record the same fee as a separate expense.
7. Press **Save as draft** to review before posting or **Save & post** to post immediately. The detail page lists the order, journal link, and stock movements. To post later, open the draft and press **Post**. To correct a posted conversion, press **Cancel**, provide the required reason, and let the system reverse it.
8. Posting consumes raw materials at the current moving-average value, receives every finished product at its allocated cost, and either credits cash/bank or creates a supplier payable for the fee. Inventory movements and the accounting entry post atomically.

### 4.9 Stock and inventory operations

- **Inventory:** Search by product name/code, category, and **Low stock only**. The list displays current quantity, minimum, average cost and value. **Ledger** opens movement details. The summary compares stock-ledger value to the Inventory general-ledger control account.
- **Inventory Ledger:** Select a product and optional `From`/`To`; inspect opening, movements and closing balances. Stock movements include opening, purchase, return, sale, adjustment, production, and reversal.
- **Stock Adjustments → Create:** Enter `Date`, required `Reason`, optional notes, and item rows with `Product`, `Direction` (Out/loss or In/gain), and `Quantity`. For stock-in rows, enter `Unit cost`; stock-out cost comes from the moving average. Save draft or post. Posted adjustments can be cancelled only if their stock movements can be reversed (for example, added stock has not since been consumed).
- **Negative stock:** It is disabled by default. With it disabled, sales/production/other stock issues cannot take inventory below zero. Enabling it in Settings permits a negative balance; uncovered issues use the last known average/default purchase cost.

### 4.10 Receipts, payments, expenses, transfers, and owner transactions

- **Receipt:** Start at **Receipts → Create**, select the customer, then enter `Date`, `Method` (cash, bank transfer, cheque, online), `Received into`, amount, optional cheque/transaction reference, and notes. Apply amounts to listed open invoices manually or choose **Ignore the boxes above and apply the amount to the oldest invoices first**. Any unallocated excess becomes a customer advance. Post the receipt; later open its detail and press **Apply** to allocate an on-account amount to invoices created later.
- **Payment:** Start at **Payments → Create**, select a supplier or employee, then enter `Date`, `Method`, `Paid from`, amount, optional reference and notes. Allocate to listed open bills or apply to the oldest bills. Excess/unallocated payment remains on that party's account as an advance. Open the posted payment and press **Apply** to allocate an on-account amount to a later bill. Payments to employees can also settle salary or reimbursements owed without an invoice.
- **Expense:** Open **Expenses → Create**. Enter `Date`, `Expense type` (an active expense or COGS account), `Amount`, optional `Input tax`, `Description`, and notes. Choose exactly one of `Paid now from` cash/bank or `…or owed to (pay later)` supplier/employee. Save draft or post. Pay a credit expense later through Payments. A processing fee already capitalized through Production must not be duplicated as an expense.
- **Fund Transfer:** Open **Fund Transfers → Create**. Enter date, `From` and `To` cash/bank accounts (must be different), amount, reference and notes. Save draft/post. This transfers value between accounts without affecting profit.
- **Owner Capital / Drawings:** Open **Owner Capital / Drawings → Create**. Select owner, `Type` (Capital introduced or Drawings), date, cash/bank account, amount, and notes. Save draft/post. Capital debits cash/bank and credits that owner's capital; drawings debit that owner's drawings and credit cash/bank.

### 4.11 Employee advances and salary-related entries

Employee advances can be recorded as an opening balance or as an unallocated employee Payment. For payroll, open **Payroll → New salary period**, enter a unique period name, date range and working days, then create a run by including employees and recording present, paid-leave and unpaid-leave days. Base pay is monthly salary multiplied by (present + paid leave) divided by working days. Enter any allowance/deduction and optional advance recovery (limited to the outstanding employee advance). Review the draft, post it once, and use the per-employee payslips and **Settle** action to pay from cash/bank. Posting creates salary expense and employee payable; a duplicate run for that salary period is refused. Posted runs are cancelled by reversal, and already-settled payables must be addressed first.

### 4.12 Journal entries and chart of accounts

- **Chart of Accounts:** Open **Chart of Accounts** to view balances. Create accounts with code, name, type, normal balance, and optional subtype. On edit, code/type/normal balance are fixed; system accounts cannot be deactivated or deleted. Accounts with posted history cannot be deleted.
- **Journal Entries:** Choose **New journal entry**. Enter `Date`, optional `Reference`, `Description`, and at least two lines. Each line needs an `Account`, one of debit/credit, and optionally party/narration when appropriate. Totals must balance. **Post entry** posts immediately; there is no journal draft. A manual journal can be cancelled only with the required permission and reason, creating a reversal. Entries generated by another document must be cancelled from that document.
- Do not use manual journals to change inventory quantity. Use purchases, returns, production, or stock adjustments so the stock ledger stays aligned with accounting.

### 4.13 Search, edit, delete, and cancellation patterns

- Product and party lists support search and active/inactive filtering; product/inventory screens support category and low-stock filters. Most transaction indexes have date/status filters and pagination.
- Purchases, sales, expenses and fund transfers support editing while draft, with the same validation as creation. Other transaction types expose draft deletion but do not all expose an edit route; inspect the document detail/list actions for the module.
- Delete is not a way to remove posted history. Posted documents are cancelled/reversed if the module supports cancellation. Masters with history (products/parties/categories/units/accounts) are protected from deletion and should be deactivated where the UI allows it.
- The shared **All Reports → Transaction listings** section supports listed document reports; see [Reports](#9-reports).

### 4.14 Fiscal close, recurring schedules, and bank reconciliation

- **Fiscal year closing:** Open **Fiscal Year Closing**, review the period and close history, then choose **Close year**. The application validates closing conditions and posts a balanced entry that clears the period's revenue/expense balances to Retained Earnings. The close is idempotent and has an audit-history row. Reopening requires the separate `fiscal_years.reopen` permission and a reason; it posts a reversal and retains both events in history. Closing/reopening is an accounting control, not a replacement for review of the Trial Balance and Inventory-to-GL comparison.
- **Recurring schedules:** Open **Recurring Transactions → Create schedule** and choose Expense or Fund transfer, weekly/monthly/yearly frequency, first due date and optional end date, plus the transaction details. Paused schedules can be resumed. The daily `recurring:generate-drafts` scheduler records each scheduled occurrence once and creates a draft for review; neither scheduled nor manual generation posts transactions automatically.
- **Bank statement:** Open **Bank Reconciliation → New reconciliation**. Either record a manual period or upload a CSV. CSV requires `date,description,reference,amount` columns (in any order), `YYYY-MM-DD` dates, and signed amounts (deposit positive, withdrawal negative); files are limited to 5,000 lines and 2 MB. Inspect the preview before confirming. Correct or remove draft statement lines as needed. Unique exact amount/date candidates (and reference when supplied) may auto-match; ambiguous or unmatched lines can be matched manually to a posted journal line in the same bank account/period. Reconciliation requires opening balance + statement movements = closing balance and every statement line matched. Unmatched book transactions may represent outstanding items; they remain visible in the summary. Reconciling locks the statement and stores its summary. This workflow only annotates match metadata; it never edits or silently cancels a posted journal transaction.

### 4.15 Users, roles, settings, and audit log

- **Users:** Search by name/email, filter by role, create or edit name/email/password/roles, and activate/deactivate users. Users are deactivated rather than deleted. The final active administrator cannot lose the admin role or be deactivated.
- **Roles & Permissions:** Select a role and update its permission checkboxes. The `admin` role cannot be edited and is a super-role. Permissions changes are logged.
- **Settings:** With settings management permission, edit business name/tagline/address/phone/email/NTN/STRN/logo; invoice title/footer/row count/payment display; next invoice prefix/number/padding; default sales tax; and whether negative stock is allowed. Save with **Save settings**. The next invoice number must exceed numbers already used. Settings can also be viewed without edit permission.
- **Audit Log:** Filter by date range, user, or event; open **Audit Log**. It is a paginated history, not an edit screen. Events include logins/logouts and selected changes such as role/permission/settings/numbering changes.
- Log out using the user menu → **Log out**.

## 5. Business workflows and accounting effects

### Purchase-to-stock-to-payable

A purchase draft has no stock or GL effect. Posting adds purchased stock at net line cost (after discount, before tax), debits Inventory and input tax as applicable, credits cash/bank for `Paid now`, and credits Accounts Payable for the unpaid supplier amount. When an employee funded it, the payable is instead assigned to that employee. A later Payment reduces cash/bank and the appropriate payable; allocations link it to purchase bills.

### Sale-to-receivable-to-cash

Posting a sale issues stock at moving average, assigns an invoice number, debits cash/bank for money received and Accounts Receivable for the balance, credits sales and output tax, and records COGS/Inventory. Receipts later reduce the customer receivable. Sales returns reverse proportional revenue/tax/COGS and either pay a refund or credit the customer.

### Production cost flow

Posting production credits Inventory for consumed raw material, debits Inventory for finished goods at consumed cost plus processing fee, and credits cash/bank or supplier payable for the fee. Because the fee is included in the output inventory value, it is not also an expense in this workflow. Cancelling reverses both stock movement(s) and journal entry; cancellation is refused if the consumed output stock can no longer be safely reversed.

### Expense, transfer, owner, and employee flows

- A paid expense debits its selected expense/COGS account and any input tax, and credits cash/bank. A credit expense credits supplier payable or employee payable; a later Payment settles it.
- A fund transfer debits the destination and credits the source cash/bank account; no P&L account changes.
- Owner capital is debit cash/bank and credit owner-specific capital; drawings are debit owner-specific drawings and credit cash/bank.
- Employee balances are journal-derived: credit means payable to the employee (salary/reimbursement); debit means an advance given to the employee. No automatic payroll accrual is created from monthly salary.

### Shared posting and cancellation

All normal journals pass through `PostingService`: debit and credit totals must match exactly, each line is one-sided and nonnegative, accounts must be active, control-account lines require the matching party, and the business date must fall in an open fiscal year. Cancellation does not delete the original journal; it creates a mirror reversal and marks the original cancelled. Inventory corrections likewise append reversal rows. Reports calculate net effect by considering the original and reversal.

## 6. Database and relationships

Migrations are the authoritative schema definition. Core table families:

| Area | Tables | What connects them |
|---|---|---|
| Framework/runtime | `users`, `password_reset_tokens`, `sessions`, `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `migrations` | Laravel authentication, session/cache/queue/runtime state. |
| Access/configuration | `roles`, `permissions`, `role_user`, `permission_role`, `settings`, `audit_logs`, `currencies`, `fiscal_years`, `number_sequences` | Users ↔ roles ↔ permissions; audit rows optionally reference user; sequences issue document IDs; fiscal years govern posting dates. |
| Masters | `categories`, `units`, `products`, `customers`, `suppliers`, `owners`, `employees` | Products belong to a unit and optionally category; categories can have a parent. |
| Accounting | `accounts`, `journal_entries`, `journal_entry_lines` | Entry has many lines; each line references account and may reference party/product. Journal source and party references use polymorphic type/id pairs. |
| Inventory | `inventory_transactions`, `stock_adjustments`, `stock_adjustment_items` | A movement belongs to product and may identify its source document; an adjustment has item rows and corresponding movements. |
| Purchasing | `purchases`, `purchase_items`, `purchase_returns`, `purchase_return_items` | Purchase has item rows and optional supplier/employee funder; a return links to original purchase and its products. |
| Sales | `sales`, `sale_items`, `sales_returns`, `sales_return_items`, `invoices` | Sale has item rows and customer; return links to sale/customer; invoice print record belongs to sale. |
| Money movement | `receipts`, `payments`, `payment_allocations`, `expenses`, `fund_transfers`, `owner_transactions` | Settlements reference party and cash/bank account; allocations connect receipt/payment to sale/purchase; expenses use account or party; transfers connect two accounts; owner transactions connect owner and account. |
| Production | `production_orders`, `production_inputs`, `production_outputs` | Production order has raw-material input rows, output rows, optional processor supplier, fee, and optional cash/bank account. |
| Payroll | `salary_periods`, `payroll_runs`, `payroll_items`, `payroll_components`, `payroll_settlements` | Each salary period can have one run; items hold attendance and net-pay snapshots, components hold allowances/deductions, and settlements link to posted employee payments. |
| Fiscal close | `fiscal_year_close_events` | Append-only close/reopen cycles link close and reversal journal entries and retain the actor and reason. |
| Recurring | `recurring_transaction_templates`, `recurring_transaction_executions` | Templates hold schedule/payload; unique template/date execution rows link generated expense or transfer drafts. |
| Bank reconciliation | `bank_reconciliations`, `bank_reconciliation_lines` | Statement header and summary are preserved; statement lines may match each posted journal line at most once. |

Important schema conventions:

- Product quantity is **not** stored on `products`. `inventory_transactions` is the stock ledger; each row stores quantity in/out, movement cost, running quantity, average cost and value.
- Account balances and party balances are derived from `journal_entry_lines`, not stored balance columns.
- Most source documents share `status`, `journal_entry_id`, `reversal_entry_id`, posted/cancelled timestamps, user audit columns, timestamps, and soft deletes. The exact shared columns are added by [`DocumentColumns`](app/Support/DocumentColumns.php).
- `journal_entry_lines.party_type/party_id`, journal `source_type/source_id`, expense payee, payment payee, and inventory source are polymorphic/pair references and are not ordinary foreign keys to each party/document table.
- Monetary values use decimal columns (normally two fractional digits); quantities use three; unit prices/costs use four. Service calculations convert money to paisa and quantities to thousandths for predictable rounding.

Migration map:

- `0001_01_01_000000_create_users_table.php` and adjacent Laravel migrations: users and framework tables.
- [`2026_10_01_000002_create_access_and_system_tables.php`](database/migrations/2026_10_01_000002_create_access_and_system_tables.php): roles, permissions, settings, audit, currency, fiscal years, sequences.
- [`2026_10_01_000003_create_master_data_tables.php`](database/migrations/2026_10_01_000003_create_master_data_tables.php): products and parties.
- [`2026_10_01_000004_create_accounting_core_tables.php`](database/migrations/2026_10_01_000004_create_accounting_core_tables.php): accounts and journals.
- [`2026_10_01_000005_create_inventory_tables.php`](database/migrations/2026_10_01_000005_create_inventory_tables.php): inventory ledger and adjustments.
- [`2026_10_01_000006_create_purchasing_tables.php`](database/migrations/2026_10_01_000006_create_purchasing_tables.php) and [`2026_10_01_000007_create_sales_tables.php`](database/migrations/2026_10_01_000007_create_sales_tables.php): purchases, sales, returns and invoice print history.
- [`2026_10_01_000008_create_money_movement_tables.php`](database/migrations/2026_10_01_000008_create_money_movement_tables.php): settlements, allocations, expenses, transfers, owner transactions.
- [`2026_10_01_000009_create_production_tables.php`](database/migrations/2026_10_01_000009_create_production_tables.php), [`2026_10_01_000010_add_party_type_to_accounts_table.php`](database/migrations/2026_10_01_000010_add_party_type_to_accounts_table.php), `000011`/`000012` settlement extensions, and [`2026_10_09_121956_add_processing_fee_fields_to_production_orders_table.php`](database/migrations/2026_10_09_121956_add_processing_fee_fields_to_production_orders_table.php): production and later feature extensions.
- [`2026_10_09_132539_add_allocation_basis_to_production_outputs_table.php`](database/migrations/2026_10_09_132539_add_allocation_basis_to_production_outputs_table.php), [`2026_10_09_133557_create_payroll_tables.php`](database/migrations/2026_10_09_133557_create_payroll_tables.php), and [`2026_10_09_135418_create_fiscal_year_close_events_table.php`](database/migrations/2026_10_09_135418_create_fiscal_year_close_events_table.php): multi-output production costing, payroll and year-end close history.
- [`2026_10_09_141850_create_recurring_transaction_templates_table.php`](database/migrations/2026_10_09_141850_create_recurring_transaction_templates_table.php), [`2026_10_09_141851_create_recurring_transaction_executions_table.php`](database/migrations/2026_10_09_141851_create_recurring_transaction_executions_table.php), [`2026_10_09_142644_create_bank_reconciliations_table.php`](database/migrations/2026_10_09_142644_create_bank_reconciliations_table.php), and [`2026_10_09_142645_create_bank_reconciliation_lines_table.php`](database/migrations/2026_10_09_142645_create_bank_reconciliation_lines_table.php): recurring draft schedules and bank statement reconciliation.

## 7. Calculations and business rules

- **Moving weighted-average stock cost:** receipt adds cost to stock value and quantity; issue uses current average cost. The ledger stores every resulting balance. A backdated movement that would make the valuation chain unsafe is rejected; posted movements are not silently reordered or recalculated.
- **Purchase line:** gross = quantity × unit cost; net stock cost = gross − discount; tax = tax rate × net; document total = gross − discount + tax. Input tax is separate from inventory value.
- **Sales line:** gross = quantity × price; net = gross − discount; tax = tax rate × net; total = net + tax. Blank line tax uses customer effective rate; explicit zero overrides it. Cost of goods sold uses inventory cost at the point of sale.
- **Customer credit limit:** posting tests the unpaid portion against the customer's credit limit when configured; available stock and negative-stock setting are also validated before a sale posts.
- **Payment/receipt allocations:** requested amounts cannot exceed the settlement amount or the currently outstanding target document balance. Posting locks and rechecks targets because another settlement may have changed the amount since the draft was saved. Unallocated amount remains on account and can be applied later.
- **Returns:** quantities cannot exceed quantity remaining on the original posted transaction. Sales return price/tax/COGS are proportionally drawn from original invoice values; purchase return cost/tax are proportionally drawn from original purchase values. Final partial return takes the exact remaining value to avoid rounding residue.
- **Production unit cost:** total finished-output value = sum of raw material issue costs + processing fee. For multiple outputs, that total is apportioned using their positive estimated production-cost bases; integer minor-unit allocation assigns any residual cents deterministically. Each output's unit cost is then combined with any existing stock of that product through the inventory service.
- **Payroll:** base pay = monthly salary × (present days + paid-leave days) ÷ period working days. Allowances increase gross pay, deductions and advance recovery reduce net pay; posted runs debit salary expense and credit employee payables. Settlements debit the payable and credit the selected cash/bank account.
- **Bank reconciliation:** statement signed amount is compared in cents to the bank account journal-line debit minus credit. Automatic matching requires one unique candidate with the same transaction date and (when provided) reference. Finalization checks the statement arithmetic and that all statement rows are matched; it never writes to a posted journal line.
- **Recurring schedules:** each due date has a unique execution row and corresponding draft document. Schedule generation never posts a journal.
- **Stock adjustment:** inbound quantity uses entered unit cost and credits Inventory Adjustment gain/loss; outbound quantity uses moving average and debits Inventory Adjustment gain/loss.
- **Opening records:** opening inventory debits Inventory and credits Opening Balance Equity. Supported customer/supplier/employee balances post the correct control account against Opening Balance Equity.
- **Financial reports and close:** Trial Balance derives debit/credit movement; P&L reports net sales, deductions, other income, COGS, expenses, and net profit; Balance Sheet computes assets versus liabilities/equity and presents cumulative unclosed profit separately until it is closed to Retained Earnings.
- **Aging:** current open invoice balances are bucketed by due date as not-yet-due, 1–30, 31–60, 61–90, over 90 days; the selected as-of date controls only the due-date bucket. On-account/opening amounts are shown separately so report party totals reconcile to ledger balances.

## 8. Roles, permissions, and security

The `roles`, `permissions`, and pivot tables hold role assignments. Gates enforce permissions on server routes and view actions; hiding a menu item alone is not the security control. All operational routes require authentication and an active user. Deactivated users are logged out on their next authenticated request.

- **admin:** a super-role accepted by the Gate for every ability. The role permission list is informational and cannot be edited from the Roles screen.
- **accountant:** seeded with viewing and routine create/update/post rights for most business documents, plus reports/export. Defaults intentionally omit cancellation/reversal, master deletion, chart-of-account changes, manual journal entry, owner transactions, stock-adjustment posting, users, roles, settings, audit, and import. Administrators can change non-admin role permissions.
- **User safety:** users are deactivated, not deleted. The final active administrator cannot be deactivated or stripped of admin role.
- **Authentication:** login regenerates the session; logout invalidates it. Login/logout are audit events. Laravel CSRF tokens are included in web forms; validated Form Requests/controllers and authorization gates protect write actions.
- **Posting:** the shared posting service enforces balanced entries, active accounts, appropriate party dimensions on control accounts, and open fiscal year. Source documents must be cancelled at source so status, stock, allocations, and journals remain consistent.

Permission names follow `module.action`, for example `sales.view`, `sales.post`, `sales.cancel`, `reports.export`. Full permission definitions and accountant defaults are in [`app/Support/Permissions.php`](app/Support/Permissions.php).

## 9. Reports

Open **All Reports** or use the direct sidebar report items. Most report periods default to the fiscal year containing the selected `To` date, or calendar year if no fiscal year covers it; the end date defaults to today. Reports can be printed using browser print. **Export CSV** appears to users with `reports.export`.

| Report | Main filters / output |
|---|---|
| Trial Balance | `From`/`To`; opening, period debit/credit and closing debit/credit by account; includes a balanced check. |
| Profit & Loss | `From`/`To`; sales, returns/discounts, other income, net sales, COGS, gross profit, expenses and net profit. |
| Balance Sheet | `To` date; assets, liabilities, equity, and cumulative profit not yet closed to retained earnings. |
| Cash Flow | `From`/`To`; opening/closing cash and bank, movements grouped by voucher type; transfers between own accounts net to zero overall. |
| Outstanding Receivables | `As of` date; customer invoices grouped by due-date aging plus on-account balances. Open amounts represent the current outstanding position; `As of` changes the aging bucket date, it does not reconstruct historical allocations. |
| Outstanding Payables | `As of` date; supplier and employee balances; employee section includes purchases funded personally. As with receivables, balances are current and the date determines aging buckets. |
| Customer/Supplier/Employee/Owner Balances | Route supports type and `To` date; All Reports visibly links customers and suppliers. Balance signs are labeled for the selected party. |
| Stock Valuation | `As of`, category, and show-zero option; quantity, average cost, value per product, and inventory-account comparison. |
| Inventory Ledger | Product, `From`, `To`; opening, movements and closing quantity/value. |
| General Ledger / Cash Book / Bank Book / Expense Ledger / Owner Capital Ledger | Select account/preset, dates, optional party and voucher type; line-level debit/credit and running balance unless voucher filter is used. Choose **All account ledgers** to print a separate, independently balanced ledger for every chart-of-accounts account in one print job. |
| Day Book | `From`, `To`, optional voucher type; journal entries grouped by type. Query is capped at 5,000 entries. |
| Transaction listings | Sales, purchases, sales returns, purchase returns, expenses, receipts, payments; date range, status (`posted` default, draft/cancelled/all), and party where applicable. Limited to 5,000 rows and indicates truncation. |

Financial report figures come from journal lines; stock values come from the inventory ledger. Investigate reconciliation warnings before relying on period close figures.

## 10. Troubleshooting

| Symptom | Likely cause and action |
|---|---|
| Login works but menu is missing or request gives 403 | User is inactive or their role lacks the corresponding `module.action` permission. Ask an administrator to check Users and Roles & Permissions. |
| Posting fails with a fiscal-year error | The document date has no fiscal year or its fiscal year is closed. Add/open the correct fiscal year before posting. |
| Sale or production posting reports insufficient stock | Receive/opening-stock the required product or correct the quantity. Only enable negative stock if this is an intentional business policy. |
| Sale cannot post beyond credit limit | Check customer credit limit and existing receivable/advance balance; receive payment or have an authorized administrator review the limit. |
| Draft settlement cannot post because an invoice/bill changed | Another receipt/payment/return may have changed its open balance. Reopen the settlement, correct allocations to current open amounts, and retry. |
| Return quantity is rejected | Return only remaining quantity on the posted source transaction; cancelled returns do not count as posted returns. |
| Posted document cannot be edited/deleted | This is the audit-preserving lifecycle. Use the source document's **Cancel** action with reason, then enter a corrected document. |
| Purchase return cannot be refunded or settled | Walk-in purchase has no supplier/employee payable; the refund must be made in full. |
| Production fee validation fails | For a positive unpaid fee, select a processor supplier. For a paid fee, select a valid cash/bank account. |
| Inventory value differs from the Inventory account | Compare Inventory ledger movements with journals, check cancellations/reversals and opening entries, and inspect the shown difference before period close. Do not patch the balance with an unrelated journal. |
| Image/logo upload does not display | Confirm `public` filesystem storage permissions and run `php artisan storage:link`; verify the file meets its type/size limits. |
| Vite manifest is missing or UI assets are stale | Run `npm install` when dependencies are not installed, then `npm run build`; use `npm run dev` during development. |
| Database connection/migration failure | Confirm `.env` connection name, host, port, database, credentials, PDO extension, and that the database exists. Check migration status before retrying. |
| Invoice number was not consumed by a draft | Invoice numbers are assigned at posting, not when a sales draft is created. Check invoice numbering under Settings if the next number is unexpected. |

For diagnosis, inspect Laravel's application log under `storage/logs`, browser console/browser logs for frontend errors, and the Laravel route list with `php artisan route:list`. Do not expose `.env`, passwords, or production database exports when requesting support.

## 11. Worked examples

### Buy yarn, convert it, and pay the processor

Assume the business buys 100 kg of yarn at PKR 500/kg, without tax or discount, on supplier credit. Post the purchase: stock receives PKR 50,000 value and the supplier ledger is credited PKR 50,000. Then create Production dated after the receipt: consume 20 kg yarn, produce 18 kg thread, pay a PKR 1,800 processing fee from Bank. At a PKR 500/kg moving average, consumed yarn cost is PKR 10,000; output thread cost is PKR 11,800, or approximately PKR 655.56/kg. Yarn falls by 20 kg/Pkr 10,000; thread rises by 18 kg/Pkr 11,800; bank decreases PKR 1,800. If unpaid, select the processor supplier instead of a bank account; supplier payable increases PKR 1,800. Do not enter that same fee as a separate Expense.

### Give an employee an advance and later settle salary

Create the employee. Either record an opening debit balance using **Employee owes us (advance given)** if the amount predates system use, or use **Payments → Create** and leave the payment unallocated for an advance paid after go-live. When salary becomes due, record an **Expense** to the salary expense account owed to that employee. A later **Payment** reduces the employee payable; if the account has an advance debit, the employee ledger displays the net position. There is no automatic salary schedule or payroll calculation.

### Sell thread partly on credit

Create a sales invoice for the customer, choose thread and quantity, set rate and tax, and enter only the cash received now. Posting issues thread at its moving-average cost, recognizes sales/tax and COGS, and leaves the unpaid amount in Accounts Receivable. Later post a Receipt and allocate it to the invoice; the invoice balance and customer ledger reduce.

### Correct a posted conversion

Open the posted Production record, choose **Cancel**, and enter a reason. The system appends reverse inventory movements and a reversing journal entry, then marks the production order cancelled. If the finished thread has already been consumed/sold and cannot be removed without invalid stock, cancellation is rejected; correct dependent transactions first.

## 12. Maintenance and deployment

- **Backups:** back up the configured database and the `storage/app/public` files (product images/company logo) together. Use database-native backup tooling appropriate to the selected engine, retain encrypted off-site copies, and test restoring to a non-production environment. No in-app backup screen was found.
- **Before deployment:** use production `.env` values, `APP_DEBUG=false`, a unique `APP_KEY`, HTTPS, least-privilege database credentials, and an Apache/Nginx document root at `public`. Run migrations during a planned deployment after backup; build assets with `npm run build`.
- **Filesystem:** ensure Laravel can write to `storage` and `bootstrap/cache`. Configure public uploads with `php artisan storage:link` where needed.
- **After code updates:** deploy matching source and dependencies (`composer install --no-dev --optimize-autoloader` in production; npm build as required), run new migrations, and clear/rebuild cached configuration/routes/views according to the deployment setup. Do not run `migrate:fresh` or `db:wipe` on a database containing business data.
- **Fiscal periods:** create/maintain fiscal years and close them only through a deliberate accounting process. A closed or missing fiscal year prevents journal posting for dates within it.
- **Reconciliation:** regularly compare stock valuation to the Inventory account, review Trial Balance and party ledgers, and retain audit history. Keep backup retention and restore testing in the operational runbook.
- **Scheduled drafts:** configure the Laravel scheduler to run `php artisan schedule:run` every minute (cron or Windows Task Scheduler). The application's daily `recurring:generate-drafts` event creates reviewable drafts only; check the execution log and review/post each document under the normal permissions.
- **Offline frontend:** run `npm install` and `npm run build` as part of deployment. Bootstrap, icons and Bootstrap JavaScript are bundled locally by Vite; no CDN is required when loading the interface.

## 13. Limitations and implementation notes

These are the remaining boundaries of the implemented workflows:

- **Payroll rules:** payroll supports attendance prorating, allowances, deductions, employee-advance recovery, salary payables, payslips and payment settlement. Tax-withholding tables, jurisdiction-specific payroll taxes, and multiple allowance/deduction rows per employee are not configured; the current entry form provides one allowance and one deduction row per employee.
- **Production reference:** multiple distinct finished products are supported and all costs are allocated; the optional customer/job-work field is not exposed in the current form.
- **Recurring transaction types:** only Expenses and Fund Transfers are available as recurring templates. All generated items remain drafts for review; other transaction types are not generated.
- **Bank statement formats:** reconciliation accepts manual entries or CSV only (up to 5,000 lines / 2 MB), not XLS/XLSX. Automatic matching requires a unique exact signed amount and same-date candidate, and exact reference when one is supplied. Statement lines must all be matched before locking; unmatched posted book movements may remain as outstanding items. A reconciled statement is immutable.
- **General data import:** no generic import module/workflow has been added. Statement-file parsing is scoped to bank reconciliation; the legacy `import.run` permission is not an import endpoint.
- **Draft editing:** Purchases, Sales, Expenses and Fund Transfers have draft editing. Receipts, Payments, Returns, Production, Stock Adjustments, Owner Transactions and Payroll do not currently expose draft edit screens; delete/recreate a draft where permitted, or use cancellation/reversal for posted documents.
- **History behavior:** document cancellation is a compensating reversal, not deletion. Inventory movements are append-only. Unsafe backdated stock changes are rejected rather than used to rewrite historical moving-average costs and journals.
- **Defaults are not universal settings:** `.env.example` selects SQLite while the inspected live configuration used MySQL. Confirm `.env` and database migration status for the actual installation.

## 14. Developer reference map

| Concern | Source |
|---|---|
| Authenticated routes and route names | [`routes/web.php`](routes/web.php) |
| Sidebar/menu labels and permission mapping | [`config/menu.php`](config/menu.php) |
| Permissions and accountant baseline | [`app/Support/Permissions.php`](app/Support/Permissions.php), role seeder |
| Migrations / schema | [`database/migrations`](database/migrations) |
| Shared document lifecycle fields | [`app/Support/DocumentColumns.php`](app/Support/DocumentColumns.php) |
| Journal creation and reversals | [`app/Services/Accounting/PostingService.php`](app/Services/Accounting/PostingService.php) |
| Inventory ledger and weighted-average costing | [`app/Services/Inventory/InventoryService.php`](app/Services/Inventory/InventoryService.php) |
| Purchase and sales calculations/posting | [`app/Services/Purchasing/PurchaseService.php`](app/Services/Purchasing/PurchaseService.php), [`app/Services/Sales/SaleService.php`](app/Services/Sales/SaleService.php) |
| Settlements and allocations | [`app/Services/Payments/SettlementService.php`](app/Services/Payments/SettlementService.php), `AllocationService.php` |
| Production conversion and processing fee | [`app/Services/Production/ProductionOrderService.php`](app/Services/Production/ProductionOrderService.php) |
| Reports | [`app/Services/Reports`](app/Services/Reports) and [`app/Http/Controllers/ReportController.php`](app/Http/Controllers/ReportController.php) |
| App configuration/dependencies | [`composer.json`](composer.json), [`package.json`](package.json), [`.env.example`](.env.example), [`bootstrap/app.php`](bootstrap/app.php) |
| Production regression tests | [`tests/Feature/ProductionOrderTest.php`](tests/Feature/ProductionOrderTest.php) |

For code changes, keep transaction posting inside the domain services and preserve atomic database transactions across source documents, inventory movements, and journals. Add or update feature tests for user-visible lifecycle, accounting, inventory, and permission behavior.
