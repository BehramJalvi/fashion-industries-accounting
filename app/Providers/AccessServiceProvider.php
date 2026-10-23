<?php

namespace App\Providers;

use App\Models;
use App\Models\User;
use App\Observers\AuditObserver;
use App\Support\MorphMap;
use App\Support\Permissions;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AccessServiceProvider extends ServiceProvider
{
    /** Models written to the audit log. Journal lines / inventory rows are append-only already, so are not observed. */
    private const AUDITED = [
        User::class, Models\Role::class, Models\Account::class, Models\Setting::class,
        Models\Category::class, Models\Unit::class, Models\Product::class, Models\Customer::class,
        Models\Supplier::class, Models\Owner::class, Models\Employee::class,
        Models\Purchase::class, Models\PurchaseReturn::class, Models\Sale::class, Models\SalesReturn::class,
        Models\Receipt::class, Models\Payment::class, Models\Expense::class, Models\FundTransfer::class,
        Models\OwnerTransaction::class, Models\StockAdjustment::class, Models\ProductionOrder::class,
        Models\JournalEntry::class, Models\SalaryPeriod::class, Models\PayrollRun::class,
        Models\PayrollItem::class, Models\PayrollComponent::class, Models\PayrollSettlement::class,
        Models\FiscalYearCloseEvent::class, Models\RecurringTransactionTemplate::class,
        Models\RecurringTransactionExecution::class, Models\BankReconciliation::class,
        Models\BankReconciliationLine::class,
    ];

    public function boot(): void
    {
        Relation::enforceMorphMap(MorphMap::MAP);
        Paginator::useBootstrapFive();

        // Admin passes every PERMISSION ability. Policy abilities (viewAny, update, toggle ...) are deliberately not short-circuited,
        // so guards such as "you cannot deactivate yourself" still apply to administrators.
        Gate::before(function (User $user, string $ability) {
            return $user->is_active && $user->isAdmin() && in_array($ability, Permissions::all(), true) ? true : null;
        });

        foreach (Permissions::all() as $permission) {
            Gate::define($permission, fn (User $user) => $user->is_active && $user->hasPermission($permission));
        }

        foreach (self::AUDITED as $model) {
            $model::observe(AuditObserver::class);
        }
    }
}
