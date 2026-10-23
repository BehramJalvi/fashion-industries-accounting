<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\AuditLogController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\BankReconciliationController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\FiscalYearClosingController;
use App\Http\Controllers\FundTransferController;
use App\Http\Controllers\InventoryController;
use App\Http\Controllers\InvoiceController;
use App\Http\Controllers\JournalController;
use App\Http\Controllers\LedgerController;
use App\Http\Controllers\OwnerTransactionController;
use App\Http\Controllers\Parties\CustomerController;
use App\Http\Controllers\Parties\EmployeeController;
use App\Http\Controllers\Parties\OwnerController;
use App\Http\Controllers\Parties\PartyOpeningController;
use App\Http\Controllers\Parties\SupplierController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PayrollController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductionOrderController;
use App\Http\Controllers\PurchaseController;
use App\Http\Controllers\PurchaseInvoiceController;
use App\Http\Controllers\PurchaseReturnController;
use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\RecurringTransactionController;
use App\Http\Controllers\ReportController;
use App\Http\Controllers\RoleController;
use App\Http\Controllers\SaleController;
use App\Http\Controllers\SaleReturnController;
use App\Http\Controllers\SettingsController;
use App\Http\Controllers\StockAdjustmentController;
use App\Http\Controllers\UnitController;
use App\Http\Controllers\UserController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store']);
});

Route::middleware(['auth', 'active'])->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');

    Route::redirect('/', '/dashboard');
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard')->middleware('can:dashboard.view');

    // Users & Permissions
    Route::get('users', [UserController::class, 'index'])->name('users.index');
    Route::get('users/create', [UserController::class, 'create'])->name('users.create');
    Route::post('users', [UserController::class, 'store'])->name('users.store');
    Route::get('users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
    Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
    Route::patch('users/{user}/toggle', [UserController::class, 'toggle'])->name('users.toggle');

    Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
    Route::get('roles/{role}/edit', [RoleController::class, 'edit'])->name('roles.edit');
    Route::put('roles/{role}', [RoleController::class, 'update'])->name('roles.update');

    // Accounting core (Phase 5)
    Route::resource('accounts', AccountController::class)->except('show');
    Route::get('journal', [JournalController::class, 'index'])->name('journal.index');
    Route::get('journal/create', [JournalController::class, 'create'])->name('journal.create');
    Route::post('journal', [JournalController::class, 'store'])->name('journal.store');
    Route::get('journal/{entry}', [JournalController::class, 'show'])->name('journal.show');
    Route::post('journal/{entry}/cancel', [JournalController::class, 'cancel'])->name('journal.cancel');

    // Products & inventory (Phase 6)
    Route::resource('products', ProductController::class)->except('show');
    Route::resource('categories', CategoryController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::resource('units', UnitController::class)->only(['index', 'store', 'update', 'destroy']);
    Route::get('inventory', [InventoryController::class, 'index'])->name('inventory.index');
    Route::get('inventory/ledger', [InventoryController::class, 'ledger'])->name('inventory.ledger');
    Route::resource('stock-adjustments', StockAdjustmentController::class)->only(['index', 'create', 'store', 'show', 'destroy']);
    Route::post('stock-adjustments/{stock_adjustment}/post', [StockAdjustmentController::class, 'post'])->name('stock-adjustments.post');
    Route::post('stock-adjustments/{stock_adjustment}/cancel', [StockAdjustmentController::class, 'cancel'])->name('stock-adjustments.cancel');
    Route::resource('production-orders', ProductionOrderController::class)->only(['index', 'create', 'store', 'show', 'destroy']);
    Route::post('production-orders/{production_order}/post', [ProductionOrderController::class, 'post'])->name('production-orders.post');
    Route::post('production-orders/{production_order}/cancel', [ProductionOrderController::class, 'cancel'])->name('production-orders.cancel');
    Route::get('payroll', [PayrollController::class, 'index'])->name('payroll.index');
    Route::get('payroll/periods/create', [PayrollController::class, 'createPeriod'])->name('payroll.periods.create');
    Route::post('payroll/periods', [PayrollController::class, 'storePeriod'])->name('payroll.periods.store');
    Route::get('payroll/periods/{salary_period}', [PayrollController::class, 'showPeriod'])->name('payroll.periods.show');
    Route::post('payroll/periods/{salary_period}/run', [PayrollController::class, 'storeRun'])->name('payroll.runs.store');
    Route::post('payroll/runs/{payroll_run}/post', [PayrollController::class, 'post'])->name('payroll.runs.post');
    Route::post('payroll/runs/{payroll_run}/cancel', [PayrollController::class, 'cancel'])->name('payroll.runs.cancel');
    Route::get('payroll/payslips/{payroll_item}', [PayrollController::class, 'payslip'])->name('payroll.payslips.show');
    Route::post('payroll/payslips/{payroll_item}/settle', [PayrollController::class, 'settle'])->name('payroll.payslips.settle');

    // Customers, suppliers, employees, owners (Phase 7)
    foreach (['customers' => CustomerController::class, 'suppliers' => SupplierController::class,
        'employees' => EmployeeController::class, 'owners' => OwnerController::class] as $prefix => $controller) {
        Route::resource($prefix, $controller)->except('show');
        Route::get($prefix.'/{id}/ledger', [$controller, 'ledger'])->whereNumber('id')->name($prefix.'.ledger');
    }
    Route::post('parties/{type}/{id}/opening', [PartyOpeningController::class, 'store'])->whereIn('type', ['customer', 'supplier', 'employee'])->whereNumber('id')->name('parties.opening.store');
    Route::delete('parties/{type}/{id}/opening', [PartyOpeningController::class, 'destroy'])->whereIn('type', ['customer', 'supplier', 'employee'])->whereNumber('id')->name('parties.opening.destroy');

    Route::resource('owner-transactions', OwnerTransactionController::class)->only(['index', 'create', 'store', 'show', 'destroy']);
    Route::post('owner-transactions/{owner_transaction}/post', [OwnerTransactionController::class, 'post'])->name('owner-transactions.post');
    Route::post('owner-transactions/{owner_transaction}/cancel', [OwnerTransactionController::class, 'cancel'])->name('owner-transactions.cancel');

    // Purchasing (Phase 8)
    Route::resource('purchases', PurchaseController::class);
    Route::post('purchases/{purchase}/post', [PurchaseController::class, 'post'])->name('purchases.post');
    Route::post('purchases/{purchase}/cancel', [PurchaseController::class, 'cancel'])->name('purchases.cancel');
    Route::get('purchases/{purchase}/invoice', [PurchaseInvoiceController::class, 'show'])->name('purchases.invoice');
    Route::post('purchases/{purchase}/invoice/printed', [PurchaseInvoiceController::class, 'printed'])->name('purchases.invoice.printed');
    Route::resource('purchase-returns', PurchaseReturnController::class)->only(['index', 'create', 'store', 'show', 'destroy']);
    Route::post('purchase-returns/{purchase_return}/post', [PurchaseReturnController::class, 'post'])->name('purchase-returns.post');
    Route::post('purchase-returns/{purchase_return}/cancel', [PurchaseReturnController::class, 'cancel'])->name('purchase-returns.cancel');

    // Sales (Phase 9)
    Route::resource('sales', SaleController::class);
    Route::post('sales/{sale}/post', [SaleController::class, 'post'])->name('sales.post');
    Route::post('sales/{sale}/cancel', [SaleController::class, 'cancel'])->name('sales.cancel');
    Route::resource('sales-returns', SaleReturnController::class)->only(['index', 'create', 'store', 'show', 'destroy']);
    Route::post('sales-returns/{sales_return}/post', [SaleReturnController::class, 'post'])->name('sales-returns.post');
    Route::post('sales-returns/{sales_return}/cancel', [SaleReturnController::class, 'cancel'])->name('sales-returns.cancel');

    // Receipts, payments, expenses, transfers (Phase 10)
    Route::resource('receipts', ReceiptController::class)->only(['index', 'create', 'store', 'show', 'destroy']);
    Route::post('receipts/{receipt}/post', [ReceiptController::class, 'post'])->name('receipts.post');
    Route::post('receipts/{receipt}/allocate', [ReceiptController::class, 'allocate'])->name('receipts.allocate');
    Route::post('receipts/{receipt}/cancel', [ReceiptController::class, 'cancel'])->name('receipts.cancel');
    Route::resource('payments', PaymentController::class)->only(['index', 'create', 'store', 'show', 'destroy']);
    Route::post('payments/{payment}/post', [PaymentController::class, 'post'])->name('payments.post');
    Route::post('payments/{payment}/allocate', [PaymentController::class, 'allocate'])->name('payments.allocate');
    Route::post('payments/{payment}/cancel', [PaymentController::class, 'cancel'])->name('payments.cancel');
    Route::resource('expenses', ExpenseController::class)->only(['index', 'create', 'store', 'show', 'edit', 'update', 'destroy']);
    Route::post('expenses/{expense}/post', [ExpenseController::class, 'post'])->name('expenses.post');
    Route::post('expenses/{expense}/cancel', [ExpenseController::class, 'cancel'])->name('expenses.cancel');
    Route::resource('fund-transfers', FundTransferController::class)->only(['index', 'create', 'store', 'show', 'edit', 'update', 'destroy']);
    Route::post('fund-transfers/{fund_transfer}/post', [FundTransferController::class, 'post'])->name('fund-transfers.post');
    Route::post('fund-transfers/{fund_transfer}/cancel', [FundTransferController::class, 'cancel'])->name('fund-transfers.cancel');
    Route::get('recurring-transactions', [RecurringTransactionController::class, 'index'])->name('recurring-transactions.index');
    Route::get('recurring-transactions/create', [RecurringTransactionController::class, 'create'])->name('recurring-transactions.create');
    Route::post('recurring-transactions', [RecurringTransactionController::class, 'store'])->name('recurring-transactions.store');
    Route::post('recurring-transactions/run-due', [RecurringTransactionController::class, 'runDue'])->name('recurring-transactions.run-due');
    Route::get('recurring-transactions/{template}', [RecurringTransactionController::class, 'show'])->name('recurring-transactions.show');
    Route::patch('recurring-transactions/{template}/toggle', [RecurringTransactionController::class, 'toggle'])->name('recurring-transactions.toggle');
    Route::get('bank-reconciliations', [BankReconciliationController::class, 'index'])->name('bank-reconciliations.index');
    Route::get('bank-reconciliations/create', [BankReconciliationController::class, 'create'])->name('bank-reconciliations.create');
    Route::post('bank-reconciliations/preview', [BankReconciliationController::class, 'preview'])->name('bank-reconciliations.preview');
    Route::post('bank-reconciliations', [BankReconciliationController::class, 'store'])->name('bank-reconciliations.store');
    Route::post('bank-reconciliations/import', [BankReconciliationController::class, 'import'])->name('bank-reconciliations.import');
    Route::get('bank-reconciliations/{bankReconciliation}', [BankReconciliationController::class, 'show'])->name('bank-reconciliations.show');
    Route::post('bank-reconciliations/{bankReconciliation}/lines', [BankReconciliationController::class, 'storeLine'])->name('bank-reconciliations.lines.store');
    Route::get('bank-reconciliations/{bankReconciliation}/lines/{line}/edit', [BankReconciliationController::class, 'editLine'])->name('bank-reconciliations.lines.edit');
    Route::patch('bank-reconciliations/{bankReconciliation}/lines/{line}', [BankReconciliationController::class, 'updateLine'])->name('bank-reconciliations.lines.update');
    Route::delete('bank-reconciliations/{bankReconciliation}/lines/{line}', [BankReconciliationController::class, 'deleteLine'])->name('bank-reconciliations.lines.destroy');
    Route::post('bank-reconciliations/{bankReconciliation}/auto-match', [BankReconciliationController::class, 'autoMatch'])->name('bank-reconciliations.auto-match');
    Route::post('bank-reconciliations/{bankReconciliation}/lines/{line}/match', [BankReconciliationController::class, 'match'])->name('bank-reconciliations.lines.match');
    Route::post('bank-reconciliations/{bankReconciliation}/reconcile', [BankReconciliationController::class, 'reconcile'])->name('bank-reconciliations.reconcile');

    // Invoice printing and settings (Phase 11)
    Route::get('sales/{sale}/invoice', [InvoiceController::class, 'show'])->name('sales.invoice');
    Route::post('sales/{sale}/invoice/printed', [InvoiceController::class, 'printed'])->name('sales.invoice.printed');
    Route::get('settings', [SettingsController::class, 'index'])->name('settings.index');
    Route::put('settings', [SettingsController::class, 'update'])->name('settings.update');

    // Ledgers and reports (Phase 12)
    Route::get('ledgers', [LedgerController::class, 'index'])->name('ledgers.index');
    Route::get('reports', [ReportController::class, 'index'])->name('reports.index');
    Route::get('reports/trial-balance', [ReportController::class, 'trialBalance'])->name('reports.trial-balance');
    Route::get('reports/profit-loss', [ReportController::class, 'profitLoss'])->name('reports.profit-loss');
    Route::get('reports/balance-sheet', [ReportController::class, 'balanceSheet'])->name('reports.balance-sheet');
    Route::get('reports/receivables', [ReportController::class, 'receivables'])->name('reports.receivables');
    Route::get('reports/payables', [ReportController::class, 'payables'])->name('reports.payables');
    Route::get('reports/stock-valuation', [ReportController::class, 'stockValuation'])->name('reports.stock-valuation');
    Route::get('reports/cash-flow', [ReportController::class, 'cashFlow'])->name('reports.cash-flow');
    Route::get('reports/day-book', [ReportController::class, 'dayBook'])->name('reports.day-book');
    Route::get('reports/balances/{type}', [ReportController::class, 'balances'])->whereIn('type', ['customers', 'suppliers', 'employees', 'owners'])->name('reports.balances');
    Route::get('reports/documents/{type}', [ReportController::class, 'documents'])->name('reports.documents');

    Route::get('fiscal-years', [FiscalYearClosingController::class, 'index'])->name('fiscal-years.index');
    Route::post('fiscal-years/{fiscalYear}/close', [FiscalYearClosingController::class, 'close'])->name('fiscal-years.close');
    Route::post('fiscal-years/{fiscalYear}/reopen', [FiscalYearClosingController::class, 'reopen'])->name('fiscal-years.reopen');

    Route::get('audit-logs', [AuditLogController::class, 'index'])->name('audit-logs.index');
});
