<?php

namespace Tests\Concerns;

use App\Models\Account;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\Owner;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\Unit;
use App\Services\Inventory\StockAdjustmentService;
use App\Services\Accounting\OwnerTransactionService;
use App\Services\Payments\ExpenseService;
use App\Services\Payments\FundTransferService;
use App\Services\Payments\SettlementService;
use App\Services\Purchasing\PurchaseService;
use App\Services\Sales\SaleReturnService;
use App\Services\Sales\SaleService;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\FiscalYearSeeder;
use Database\Seeders\NumberSequenceSeeder;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;

/**
 * A small month of trading, built through the real services, with figures worked out by hand:
 *
 *   01-Oct  Saqlain puts 500,000 into the bank
 *   02-Oct  credit purchase from Al Zamin: 100 units @ 1,000 = 100,000
 *   03-Oct  walk-in purchase paid from bank: 50 units @ 1,000 = 50,000
 *   05-Oct  sale to Multan Fabrics: 120 units @ 1,500 = 180,000 + 18% tax 32,400 = 212,400 (cost 120,000)
 *   10-Oct  receipt 100,000 into bank, applied to the invoice
 *   11-Oct  payment to Al Zamin 60,000 from bank, applied to the bill
 *   12-Oct  40,000 moved from bank to cash
 *   13-Oct  rent 15,000 paid from cash
 *   14-Oct  salary 40,000 owed to Mohsin
 *   15-Oct  sales return of 10 units: 15,000 + tax 2,700 = 17,700 (cost 10,000), credit note
 *   16-Oct  stock adjustment: 2 units lost (cost 2,000)
 *
 * Expected at 31-Oct: net sales 165,000; cost of sales 112,000; gross profit 53,000; expenses 55,000; net LOSS 2,000.
 * Cash 25,000, bank 450,000, receivable 94,700, inventory 38,000 = 607,700.  Payable 40,000, Mohsin 40,000, sales tax 29,700 = 109,700.
 * Equity: capital 500,000 less the loss 2,000 = 498,000.   109,700 + 498,000 = 607,700.
 */
trait BooksScenario
{
    protected Product $yarn;
    protected Owner $saqlain;
    protected Supplier $alZamin;
    protected Customer $customer;
    protected Employee $mohsin;
    protected Purchase $bill;
    protected Sale $invoice;

    protected function buildScenario(): void
    {
        $this->seed([RolePermissionSeeder::class, FiscalYearSeeder::class, ChartOfAccountsSeeder::class, NumberSequenceSeeder::class, SettingsSeeder::class]);

        $this->yarn = Product::create(['code' => 'Y1', 'name' => 'Cotton Yarn', 'unit_id' => Unit::create(['name' => 'Cone'])->id, 'product_type' => 'trading', 'purchase_price' => 0, 'selling_price' => 0]);
        $this->saqlain = Owner::create(['name' => 'Saqlain']);
        $this->alZamin = Supplier::create(['code' => 'S001', 'name' => 'Al Zamin']);
        $this->customer = Customer::create(['code' => 'C001', 'name' => 'Multan Fabrics PVT Limited']);
        $this->mohsin = Employee::create(['code' => 'E001', 'name' => 'Mohsin']);
        $bank = Account::byCode('1010')->id;
        $cash = Account::byCode('1000')->id;

        $owner = app(OwnerTransactionService::class);
        $owner->post($owner->createDraft(['owner_id' => $this->saqlain->id, 'txn_type' => 'capital', 'txn_date' => '2026-10-01', 'account_id' => $bank, 'amount' => 500000]));

        $purchases = app(PurchaseService::class);
        $this->bill = $purchases->post($purchases->createDraft(['purchase_date' => '2026-10-02', 'supplier_id' => $this->alZamin->id, 'supplier_ref' => 'BILL-001'],
            [['product_id' => $this->yarn->id, 'qty' => 100, 'unit_cost' => 1000]]));
        $purchases->post($purchases->createDraft(['purchase_date' => '2026-10-03', 'paid_now' => 50000, 'paid_from_account_id' => $bank],
            [['product_id' => $this->yarn->id, 'qty' => 50, 'unit_cost' => 1000]]));

        $sales = app(SaleService::class);
        $this->invoice = $sales->post($sales->createDraft(['customer_id' => $this->customer->id, 'sale_date' => '2026-10-05', 'gate_pass_no' => '553'],
            [['product_id' => $this->yarn->id, 'qty' => 120, 'unit_price' => 1500]]));

        $settle = app(SettlementService::class);
        $settle->post($settle->createDraft('receipt', ['customer_id' => $this->customer->id, 'receipt_date' => '2026-10-10', 'method' => 'bank', 'account_id' => $bank, 'amount' => 100000], [$this->invoice->id => 100000]));
        $settle->post($settle->createDraft('payment', ['payee_type' => 'supplier', 'payee_id' => $this->alZamin->id, 'payment_date' => '2026-10-11', 'method' => 'bank', 'account_id' => $bank, 'amount' => 60000], [$this->bill->id => 60000]));

        $transfers = app(FundTransferService::class);
        $transfers->post($transfers->createDraft(['transfer_date' => '2026-10-12', 'from_account_id' => $bank, 'to_account_id' => $cash, 'amount' => 40000]));

        $expenses = app(ExpenseService::class);
        $expenses->post($expenses->createDraft(['expense_date' => '2026-10-13', 'expense_account_id' => Account::byCode('5300')->id, 'amount' => 15000, 'description' => 'Factory rent', 'paid_from_account_id' => $cash]));
        $expenses->post($expenses->createDraft(['expense_date' => '2026-10-14', 'expense_account_id' => Account::byCode('5200')->id, 'amount' => 40000, 'description' => 'Salary - Mohsin', 'payee_type' => 'employee', 'payee_id' => $this->mohsin->id]));

        $returns = app(SaleReturnService::class);
        $returns->post($returns->createDraft($this->invoice->fresh('items'), ['return_date' => '2026-10-15'], [$this->yarn->id => 10]));

        $adjust = app(StockAdjustmentService::class);
        $adjust->post($adjust->createDraft(['adjustment_date' => '2026-10-16', 'reason' => 'Count difference'], [['product_id' => $this->yarn->id, 'direction' => 'out', 'qty' => 2]]));
    }
}
