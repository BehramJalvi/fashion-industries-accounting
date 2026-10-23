<?php

/**
 * Sidebar definition. An item is shown only when (a) its route exists and (b) the user holds its permission.
 * Later phases just register the route and the item appears; nothing here needs editing.
 */
return [
    ['label' => 'Dashboard', 'icon' => 'speedometer2', 'route' => 'dashboard', 'can' => 'dashboard.view'],

    ['heading' => 'Master Data'],
    ['label' => 'Products',    'icon' => 'box-seam',       'route' => 'products.index',    'can' => 'products.view'],
    ['label' => 'Categories',  'icon' => 'tags',           'route' => 'categories.index',  'can' => 'categories.view'],
    ['label' => 'Customers',   'icon' => 'people',         'route' => 'customers.index',   'can' => 'customers.view'],
    ['label' => 'Suppliers',   'icon' => 'truck',          'route' => 'suppliers.index',   'can' => 'suppliers.view'],
    ['label' => 'Owners / Capital', 'icon' => 'person-badge', 'route' => 'owners.index',   'can' => 'owners.view'],
    ['label' => 'Employees',   'icon' => 'person-workspace', 'route' => 'employees.index', 'can' => 'employees.view'],

    ['heading' => 'Transactions'],
    ['label' => 'Purchases',         'icon' => 'cart-plus',    'route' => 'purchases.index',        'can' => 'purchases.view'],
    ['label' => 'Purchase Returns',  'icon' => 'arrow-return-left', 'route' => 'purchase-returns.index', 'can' => 'purchase_returns.view'],
    ['label' => 'Sales',             'icon' => 'receipt',      'route' => 'sales.index',            'can' => 'sales.view'],
    ['label' => 'Sales Returns',     'icon' => 'arrow-return-right', 'route' => 'sales-returns.index', 'can' => 'sales_returns.view'],
    ['label' => 'Receipts',          'icon' => 'cash-coin',    'route' => 'receipts.index',         'can' => 'receipts.view'],
    ['label' => 'Payments',          'icon' => 'wallet2',      'route' => 'payments.index',         'can' => 'payments.view'],
    ['label' => 'Expenses',          'icon' => 'cash-stack',   'route' => 'expenses.index',         'can' => 'expenses.view'],
    ['label' => 'Fund Transfers',    'icon' => 'arrow-left-right', 'route' => 'fund-transfers.index', 'can' => 'fund_transfers.view'],
    ['label' => 'Recurring Transactions', 'icon' => 'arrow-repeat', 'route' => 'recurring-transactions.index', 'can' => 'recurring_transactions.view'],
    ['label' => 'Bank Reconciliation', 'icon' => 'bank', 'route' => 'bank-reconciliations.index', 'can' => 'bank_reconciliations.view'],
    ['label' => 'Owner Capital / Drawings', 'icon' => 'bank', 'route' => 'owner-transactions.index', 'can' => 'owner_transactions.view'],
    ['label' => 'Production',        'icon' => 'gear-wide-connected', 'route' => 'production-orders.index', 'can' => 'production_orders.view'],
    ['label' => 'Payroll',           'icon' => 'cash-coin', 'route' => 'payroll.index', 'can' => 'payroll.view'],
    ['label' => 'Journal Entries',   'icon' => 'journal-text', 'route' => 'journal.index',          'can' => 'journal.view'],

    ['heading' => 'Inventory'],
    ['label' => 'Inventory',         'icon' => 'boxes',        'route' => 'inventory.index',        'can' => 'inventory.view'],
    ['label' => 'Stock Adjustments', 'icon' => 'sliders',      'route' => 'stock-adjustments.index', 'can' => 'stock_adjustments.view'],

    ['heading' => 'Accounting & Reports'],
    ['label' => 'Chart of Accounts', 'icon' => 'diagram-3',    'route' => 'accounts.index',         'can' => 'accounts.view'],
    ['label' => 'Fiscal Year Closing', 'icon' => 'calendar-check', 'route' => 'fiscal-years.index', 'can' => 'fiscal_years.view'],
    ['label' => 'Ledgers',           'icon' => 'book',         'route' => 'ledgers.index',          'can' => 'ledgers.view'],
    ['label' => 'Trial Balance',     'icon' => 'columns-gap',  'route' => 'reports.trial-balance',  'can' => 'reports.view'],
    ['label' => 'Profit & Loss',     'icon' => 'graph-up-arrow', 'route' => 'reports.profit-loss',  'can' => 'reports.view'],
    ['label' => 'Balance Sheet',     'icon' => 'layout-text-sidebar-reverse', 'route' => 'reports.balance-sheet', 'can' => 'reports.view'],
    ['label' => 'Receivables',       'icon' => 'arrow-down-circle', 'route' => 'reports.receivables', 'can' => 'reports.view'],
    ['label' => 'Payables',          'icon' => 'arrow-up-circle', 'route' => 'reports.payables',    'can' => 'reports.view'],
    ['label' => 'Stock Valuation',   'icon' => 'coin',         'route' => 'reports.stock-valuation', 'can' => 'reports.view'],
    ['label' => 'Cash Flow',         'icon' => 'cash-coin',    'route' => 'reports.cash-flow',       'can' => 'reports.view'],
    ['label' => 'Day Book',          'icon' => 'calendar-day', 'route' => 'reports.day-book',       'can' => 'reports.view'],
    ['label' => 'All Reports',       'icon' => 'file-earmark-bar-graph', 'route' => 'reports.index', 'can' => 'reports.view'],

    ['heading' => 'Administration'],
    ['label' => 'Users',        'icon' => 'person-gear',   'route' => 'users.index',       'can' => 'users.view'],
    ['label' => 'Roles & Permissions', 'icon' => 'shield-lock', 'route' => 'roles.index',  'can' => 'roles.manage'],
    ['label' => 'Settings',     'icon' => 'gear',          'route' => 'settings.index',    'can' => 'settings.view'],
    ['label' => 'Audit Log',    'icon' => 'clipboard-data', 'route' => 'audit-logs.index', 'can' => 'audit.view'],
];
