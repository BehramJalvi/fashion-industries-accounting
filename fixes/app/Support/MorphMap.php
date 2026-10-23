<?php

namespace App\Support;

/**
 * Short aliases stored in party_type / source_type columns instead of class names.
 * Register in AppServiceProvider::boot():  Relation::enforceMorphMap(MorphMap::MAP);
 */
class MorphMap
{
    public const MAP = [
        'customer' => \App\Models\Customer::class,
        'supplier' => \App\Models\Supplier::class,
        'employee' => \App\Models\Employee::class,
        'owner' => \App\Models\Owner::class,
        'sale' => \App\Models\Sale::class,
        'sales_return' => \App\Models\SalesReturn::class,
        'purchase' => \App\Models\Purchase::class,
        'purchase_return' => \App\Models\PurchaseReturn::class,
        'receipt' => \App\Models\Receipt::class,
        'payment' => \App\Models\Payment::class,
        'expense' => \App\Models\Expense::class,
        'fund_transfer' => \App\Models\FundTransfer::class,
        'owner_transaction' => \App\Models\OwnerTransaction::class,
        'stock_adjustment' => \App\Models\StockAdjustment::class,
        'production_order' => \App\Models\ProductionOrder::class,
        'product' => \App\Models\Product::class,
        'account' => \App\Models\Account::class,
        'journal_entry' => \App\Models\JournalEntry::class,
        'user' => \App\Models\User::class,
        'role' => \App\Models\Role::class,
        'permission' => \App\Models\Permission::class,
        'setting' => \App\Models\Setting::class,
        'category' => \App\Models\Category::class,
        'unit' => \App\Models\Unit::class,
        'currency' => \App\Models\Currency::class,
        'fiscal_year' => \App\Models\FiscalYear::class,
        'number_sequence' => \App\Models\NumberSequence::class,
        'invoice' => \App\Models\Invoice::class,
        'inventory_transaction' => \App\Models\InventoryTransaction::class,
        'audit_log' => \App\Models\AuditLog::class,
    ];
}
