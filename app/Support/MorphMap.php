<?php

namespace App\Support;

use App\Models\Account;
use App\Models\AuditLog;
use App\Models\BankReconciliation;
use App\Models\BankReconciliationLine;
use App\Models\Category;
use App\Models\Currency;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Expense;
use App\Models\FiscalYear;
use App\Models\FiscalYearCloseEvent;
use App\Models\FundTransfer;
use App\Models\InventoryTransaction;
use App\Models\Invoice;
use App\Models\JournalEntry;
use App\Models\NumberSequence;
use App\Models\Owner;
use App\Models\OwnerTransaction;
use App\Models\Payment;
use App\Models\PayrollComponent;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\PayrollSettlement;
use App\Models\Permission;
use App\Models\Product;
use App\Models\ProductionOrder;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Receipt;
use App\Models\RecurringTransactionExecution;
use App\Models\RecurringTransactionTemplate;
use App\Models\Role;
use App\Models\SalaryPeriod;
use App\Models\Sale;
use App\Models\SalesReturn;
use App\Models\Setting;
use App\Models\StockAdjustment;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;

/**
 * Short aliases stored in party_type / source_type columns instead of class names.
 * Register in AppServiceProvider::boot():  Relation::enforceMorphMap(MorphMap::MAP);
 */
class MorphMap
{
    public const MAP = [
        'customer' => Customer::class,
        'supplier' => Supplier::class,
        'employee' => Employee::class,
        'owner' => Owner::class,
        'sale' => Sale::class,
        'sales_return' => SalesReturn::class,
        'purchase' => Purchase::class,
        'purchase_return' => PurchaseReturn::class,
        'receipt' => Receipt::class,
        'payment' => Payment::class,
        'expense' => Expense::class,
        'fund_transfer' => FundTransfer::class,
        'owner_transaction' => OwnerTransaction::class,
        'stock_adjustment' => StockAdjustment::class,
        'production_order' => ProductionOrder::class,
        'salary_period' => SalaryPeriod::class,
        'payroll_run' => PayrollRun::class,
        'payroll_item' => PayrollItem::class,
        'payroll_component' => PayrollComponent::class,
        'payroll_settlement' => PayrollSettlement::class,
        'product' => Product::class,
        'account' => Account::class,
        'journal_entry' => JournalEntry::class,
        'user' => User::class,
        'role' => Role::class,
        'permission' => Permission::class,
        'setting' => Setting::class,
        'category' => Category::class,
        'unit' => Unit::class,
        'currency' => Currency::class,
        'fiscal_year' => FiscalYear::class,
        'fiscal_year_close_event' => FiscalYearCloseEvent::class,
        'number_sequence' => NumberSequence::class,
        'invoice' => Invoice::class,
        'inventory_transaction' => InventoryTransaction::class,
        'audit_log' => AuditLog::class,
        'recurring_transaction_template' => RecurringTransactionTemplate::class,
        'recurring_transaction_execution' => RecurringTransactionExecution::class,
        'bank_reconciliation' => BankReconciliation::class,
        'bank_reconciliation_line' => BankReconciliationLine::class,
    ];
}
