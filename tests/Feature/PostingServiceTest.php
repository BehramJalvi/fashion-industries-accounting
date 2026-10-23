<?php

namespace Tests\Feature;

use App\Exceptions\Accounting\PostingException;
use App\Models\Account;
use App\Models\Customer;
use App\Models\FiscalYear;
use App\Models\JournalEntry;
use App\Models\JournalEntryLine;
use App\Models\Owner;
use App\Models\Supplier;
use App\Services\Accounting\PostingService;
use App\Support\AccountCodes as A;
use Database\Seeders\ChartOfAccountsSeeder;
use Database\Seeders\FiscalYearSeeder;
use Database\Seeders\NumberSequenceSeeder;
use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PostingServiceTest extends TestCase
{
    use RefreshDatabase;

    private PostingService $posting;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([FiscalYearSeeder::class, ChartOfAccountsSeeder::class, NumberSequenceSeeder::class]);
        $this->posting = app(PostingService::class);
    }

    private function entry(array $lines, string $date = '2026-10-05', array $extra = []): array
    {
        return array_merge(['date' => $date, 'voucher_type' => 'journal', 'description' => 'test', 'lines' => $lines], $extra);
    }

    private function assertLedgerBalances(): void
    {
        $this->assertSame(
            (string) JournalEntryLine::sum('debit'), (string) JournalEntryLine::sum('credit'),
            'Total debits must equal total credits across the whole journal.'
        );
    }

    public function test_owner_investment_posts_balanced_entry(): void
    {
        $saqlain = Owner::create(['name' => 'Saqlain']);

        $je = $this->posting->post($this->entry([
            ['account' => A::BANK, 'debit' => '500000'],
            ['account' => A::OWNER_CAPITAL, 'credit' => '500000', 'party' => $saqlain],
        ]));

        $this->assertSame('JE-000001', $je->entry_no);
        $this->assertSame('posted', $je->status);
        $this->assertSame('500000.00', $je->total_amount);
        $this->assertTrue($je->isBalanced());
        $this->assertSame('500000.00', $saqlain->ledgerBalance());   // derived from the journal, no stored balance
    }

    public function test_unbalanced_entry_is_rejected_and_nothing_is_saved(): void
    {
        try {
            $this->posting->post($this->entry([
                ['account' => A::CASH, 'debit' => '100'],
                ['account' => A::SALES, 'credit' => '99.99'],
            ]));
            $this->fail('Expected PostingException');
        } catch (PostingException $e) {
            $this->assertStringContainsString('not balanced', $e->getMessage());
        }

        $this->assertSame(0, JournalEntry::count());
        $this->assertSame(0, JournalEntryLine::count());
    }

    public function test_line_validation(): void
    {
        $bad = [
            'both sides' => [['account' => A::CASH, 'debit' => 5, 'credit' => 5], ['account' => A::SALES, 'credit' => 5]],
            'no amount'  => [['account' => A::CASH], ['account' => A::SALES, 'credit' => 5]],
            'negative'   => [['account' => A::CASH, 'debit' => -5], ['account' => A::SALES, 'credit' => 5]],
            'one line'   => [['account' => A::CASH, 'debit' => 5]],
            'bad account' => [['account' => '9999', 'debit' => 5], ['account' => A::SALES, 'credit' => 5]],
        ];

        foreach ($bad as $label => $lines) {
            try {
                $this->posting->post($this->entry($lines));
                $this->fail("Expected PostingException for: {$label}");
            } catch (PostingException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(0, JournalEntry::count());
    }

    public function test_amounts_are_compared_exactly_in_paisa(): void
    {
        // 100.005 rounds half-up to 100.01, so this balances; floats would be unreliable here.
        $je = $this->posting->post($this->entry([
            ['account' => A::CASH, 'debit' => '100.005'],
            ['account' => A::SALES, 'credit' => '100.01'],
        ]));

        $this->assertSame('100.01', $je->total_amount);
    }

    public function test_control_accounts_need_the_right_party(): void
    {
        $customer = Customer::create(['code' => 'C001', 'name' => 'Multan Fabrics PVT Limited']);
        $supplier = Supplier::create(['code' => 'S001', 'name' => 'Al Zamin']);

        $cases = [
            'receivable without party' => [['account' => A::RECEIVABLE, 'debit' => 10], ['account' => A::SALES, 'credit' => 10]],
            'customer on payable'      => [['account' => A::INVENTORY, 'debit' => 10], ['account' => A::PAYABLE, 'credit' => 10, 'party' => $customer]],
            'supplier on receivable'   => [['account' => A::RECEIVABLE, 'debit' => 10, 'party' => $supplier], ['account' => A::SALES, 'credit' => 10]],
            'party on plain account'   => [['account' => A::CASH, 'debit' => 10, 'party' => $customer], ['account' => A::SALES, 'credit' => 10]],
        ];

        foreach ($cases as $label => $lines) {
            try {
                $this->posting->post($this->entry($lines));
                $this->fail("Expected PostingException for: {$label}");
            } catch (PostingException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_inactive_account_is_rejected(): void
    {
        Account::where('code', A::SALES)->update(['is_active' => false]);

        $this->expectException(PostingException::class);
        $this->posting->post($this->entry([['account' => A::CASH, 'debit' => 10], ['account' => A::SALES, 'credit' => 10]]));
    }

    public function test_date_must_fall_in_an_open_fiscal_year(): void
    {
        $lines = [['account' => A::CASH, 'debit' => 10], ['account' => A::SALES, 'credit' => 10]];

        try {
            $this->posting->post($this->entry($lines, '2025-03-29'));
            $this->fail('No fiscal year covers 2025-03-29');
        } catch (PostingException $e) {
            $this->assertStringContainsString('fiscal year', $e->getMessage());
        }

        FiscalYear::where('name', '2026-27')->update(['is_closed' => true]);
        $this->expectException(PostingException::class);
        $this->posting->post($this->entry($lines));
    }

    public function test_credit_sale_then_cancellation_nets_to_zero(): void
    {
        $customer = Customer::create(['code' => 'C001', 'name' => 'Multan Fabrics PVT Limited']);

        // Figures from sample invoice 257: 59,100 + 18% tax 10,638 = 69,738
        $sale = $this->posting->post($this->entry([
            ['account' => A::RECEIVABLE, 'debit' => '69738', 'party' => $customer],
            ['account' => A::SALES, 'credit' => '59100'],
            ['account' => A::OUTPUT_TAX, 'credit' => '10638'],
        ], extra: ['voucher_type' => 'sale', 'reference' => '257']));

        $this->assertSame('69738.00', $customer->ledgerBalance());

        $reversal = $this->posting->reverse($sale, 'wrong customer');

        $this->assertSame('cancelled', $sale->fresh()->status);
        $this->assertSame('posted', $reversal->status);
        $this->assertSame('reversal', $reversal->voucher_type);
        $this->assertSame($sale->id, $reversal->reverses_entry_id);
        $this->assertSame('69738.00', $reversal->total_amount);
        $this->assertSame('0.00', $customer->ledgerBalance());
        $this->assertSame('257', $reversal->reference);
        $this->assertLedgerBalances();

        // The original is kept, never deleted.
        $this->assertSame(3, $sale->lines()->count());
    }

    public function test_an_entry_can_only_be_reversed_once_and_reversals_cannot_be_reversed(): void
    {
        $je = $this->posting->post($this->entry([['account' => A::CASH, 'debit' => 10], ['account' => A::SALES, 'credit' => 10]]));
        $reversal = $this->posting->reverse($je);

        try {
            $this->posting->reverse($je);
            $this->fail('Expected already-cancelled error');
        } catch (PostingException $e) {
            $this->assertStringContainsString('already cancelled', $e->getMessage());
        }

        $this->expectException(PostingException::class);
        $this->posting->reverse($reversal);
    }

    public function test_posted_journal_rows_are_immutable(): void
    {
        $je = $this->posting->post($this->entry([['account' => A::CASH, 'debit' => 10], ['account' => A::SALES, 'credit' => 10]]));

        foreach ([
            fn () => $je->update(['description' => 'edited']),
            fn () => $je->delete(),
            fn () => $je->lines->first()->update(['debit' => 99]),
            fn () => $je->lines->first()->delete(),
        ] as $attempt) {
            try {
                $attempt();
                $this->fail('Expected DomainException');
            } catch (DomainException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
