<?php

namespace Database\Seeders;

use App\Models\Account;
use Illuminate\Database\Seeder;

class ChartOfAccountsSeeder extends Seeder
{
    /**
     * Default chart. Reports (Phase 12) group by `sub_type`, the engine addresses accounts by code (App\Support\AccountCodes).
     * [code, name, type, sub_type, normal_balance, party_type, system]
     * Safe to re-run: existing accounts are left exactly as you have edited them.
     */
    private const CHART = [
        // ---- Assets
        ['1000', 'Cash in Hand',                 'asset',     'cash',               'debit',  null,       true],
        ['1010', 'Bank',                         'asset',     'bank',               'debit',  null,       true],
        ['1100', 'Accounts Receivable',          'asset',     'receivable',         'debit',  'customer', true],
        ['1200', 'Inventory',                    'asset',     'inventory',          'debit',  null,       true],
        ['1300', 'Equipment & Fixed Assets',     'asset',     'fixed_asset',        'debit',  null,       false],
        ['1400', 'Other Current Assets',         'asset',     'other_current_asset', 'debit', null,       false],
        ['1450', 'Sales Tax Receivable (Input)', 'asset',     'tax',                'debit',  null,       true],
        ['1460', 'Employee Advances',            'asset',     'advance',            'debit',  'employee', true],
        // ---- Liabilities
        ['2000', 'Accounts Payable',             'liability', 'payable',            'credit', 'supplier', true],
        ['2100', 'Employee Payables',            'liability', 'employee_payable',   'credit', 'employee', true],
        ['2400', 'Sales Tax Payable (Output)',   'liability', 'tax_payable',        'credit', null,       true],
        ['2500', 'Loans',                        'liability', 'loan',               'credit', null,       false],
        ['2600', 'Other Payables',               'liability', 'other_liability',    'credit', null,       false],
        // ---- Equity
        ['3000', 'Owner Capital',                'equity',    'capital',            'credit', 'owner',    true],
        ['3100', 'Owner Drawings',               'equity',    'drawings',           'debit',  'owner',    true],
        ['3200', 'Retained Earnings',            'equity',    'retained_earnings',  'credit', null,       true],
        ['3900', 'Opening Balance Equity',       'equity',    'opening_equity',     'credit', null,       true],
        // ---- Revenue (4100 / 4200 are contra-revenue: normal balance is debit)
        ['4000', 'Sales Revenue',                'revenue',   'sales',              'credit', null,       true],
        ['4100', 'Sales Returns',                'revenue',   'sales_return',       'debit',  null,       true],
        ['4200', 'Sales Discounts',              'revenue',   'discount',           'debit',  null,       true],
        // ---- Cost of goods sold (reduces gross profit)
        ['5000', 'Cost of Goods Sold',           'cogs',      'cogs',               'debit',  null,       true],
        ['5100', 'Inventory Gain / Loss',        'cogs',      'inventory_adjustment', 'debit', null,      true],
        ['5150', 'Processing / Dyeing Charges',  'cogs',      'processing',         'debit',  null,       true],
        // ---- Operating expenses
        ['5200', 'Salaries & Wages',             'expense',   'salary',             'debit',  null,       false],
        ['5300', 'Rent',                         'expense',   'rent',               'debit',  null,       false],
        ['5400', 'Electricity & Utilities',      'expense',   'utilities',          'debit',  null,       false],
        ['5500', 'Transportation',               'expense',   'transport',          'debit',  null,       false],
        ['5600', 'Office Expenses',              'expense',   'office',             'debit',  null,       false],
        ['5700', 'Other Expenses',               'expense',   'other_expense',      'debit',  null,       false],
        ['5800', 'Bank Charges',                 'expense',   'bank_charges',       'debit',  null,       false],
    ];

    public function run(): void
    {
        foreach (self::CHART as [$code, $name, $type, $sub, $normal, $party, $system]) {
            Account::firstOrCreate(['code' => $code], [
                'name' => $name, 'type' => $type, 'sub_type' => $sub, 'normal_balance' => $normal,
                'party_type' => $party, 'is_control' => $party !== null, 'is_system' => $system, 'is_active' => true,
            ]);
        }
    }
}
