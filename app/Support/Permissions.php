<?php

namespace App\Support;

/**
 * Single source of truth for permission names. Seeded into the `permissions` table and
 * registered as Gate abilities, so routes can use ->middleware('can:sales.post') and views @can('sales.post').
 */
class Permissions
{
    private const MASTERS = ['products', 'categories', 'customers', 'suppliers', 'owners', 'employees'];

    private const DOCUMENTS = [
        'purchases', 'purchase_returns', 'sales', 'sales_returns', 'receipts', 'payments',
        'expenses', 'fund_transfers', 'owner_transactions', 'stock_adjustments', 'production_orders',
        'payroll', 'recurring_transactions', 'bank_reconciliations',
    ];

    /** @return array<string, string[]>  group => actions */
    public static function groups(): array
    {
        $groups = [];
        foreach (self::MASTERS as $m) {
            $groups[$m] = ['view', 'create', 'update', 'delete'];
        }
        foreach (self::DOCUMENTS as $d) {
            $groups[$d] = match ($d) {
                'payroll' => ['view', 'create', 'update', 'post', 'cancel', 'settle'],
                'purchases' => ['view', 'create', 'update', 'post', 'cancel', 'print'],
                'recurring_transactions' => ['view', 'create', 'update', 'run'],
                'bank_reconciliations' => ['view', 'create', 'update', 'reconcile'],
                default => ['view', 'create', 'update', 'post', 'cancel'],
            };
        }

        return $groups + [
            'dashboard' => ['view'],
            'accounts' => ['view', 'create', 'update', 'delete'],   // chart of accounts
            'journal' => ['view', 'create', 'post', 'cancel'],     // manual journal entries
            'inventory' => ['view'],
            'invoices' => ['view', 'print', 'reprint'],
            'ledgers' => ['view'],
            'reports' => ['view', 'export'],
            'users' => ['view', 'manage'],
            'roles' => ['manage'],
            'settings' => ['view', 'manage'],
            'audit' => ['view'],
            'fiscal_years' => ['view', 'close', 'reopen'],
            'import' => ['run'],
        ];
    }

    /** @return string[] */
    public static function all(): array
    {
        $names = [];
        foreach (self::groups() as $group => $actions) {
            foreach ($actions as $action) {
                $names[] = "$group.$action";
            }
        }

        return $names;
    }

    /**
     * Starting permissions for the Accountant role (editable later in Users & Permissions).
     * Deliberately EXCLUDED: any cancel/reversal, deleting masters, chart of accounts changes,
     * manual journals, owner capital/drawings, stock adjustment posting, users, roles, settings, audit log, import.
     */
    public static function accountantDefaults(): array
    {
        $p = ['dashboard.view', 'accounts.view', 'journal.view', 'inventory.view', 'invoices.view', 'invoices.print',
            'fiscal_years.view', 'fiscal_years.close', 'purchases.print', 'ledgers.view', 'reports.view', 'reports.export'];

        foreach (self::MASTERS as $m) {
            array_push($p, "$m.view", "$m.create", "$m.update");
        }
        foreach (self::DOCUMENTS as $d) {
            array_push($p, "$d.view", "$d.create", "$d.update");
            if (! in_array($d, ['owner_transactions', 'stock_adjustments', 'recurring_transactions', 'bank_reconciliations'], true)) {
                $p[] = "$d.post";
            }
            if ($d === 'payroll') {
                $p[] = 'payroll.settle';
            }
            if ($d === 'recurring_transactions') {
                $p[] = 'recurring_transactions.run';
            }
            if ($d === 'bank_reconciliations') {
                $p[] = 'bank_reconciliations.reconcile';
            }
        }

        return $p;
    }
}
