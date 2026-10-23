<?php

namespace App\Services\Accounting;

use App\Models\JournalEntry;
use App\Support\AccountCodes;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Opening balance of a customer / supplier / employee, posted as a journal entry against Opening Balance Equity.
 * One live opening entry per party; to change it, cancel it and record a new one.
 *
 * side 'owes_us'  = party line is a DEBIT  (customer owes us, supplier/employee advance we gave)
 * side 'we_owe'   = party line is a CREDIT (supplier payable, salary / expenses we owe an employee, customer advance)
 */
class OpeningBalanceService
{
    /** type => side => [control account code, party line is debit?] */
    private const MAP = [
        'customer' => ['owes_us' => [AccountCodes::RECEIVABLE, true], 'we_owe' => [AccountCodes::RECEIVABLE, false]],
        'supplier' => ['we_owe' => [AccountCodes::PAYABLE, false], 'owes_us' => [AccountCodes::PAYABLE, true]],
        'employee' => ['we_owe' => [AccountCodes::EMPLOYEE_PAYABLE, false], 'owes_us' => [AccountCodes::EMPLOYEE_ADVANCE, true]],
    ];

    public function __construct(private PostingService $posting)
    {
    }

    public function supports(Model $party): bool
    {
        return isset(self::MAP[$party->getMorphClass()]);
    }

    public function existing(Model $party): ?JournalEntry
    {
        return JournalEntry::where('voucher_type', 'opening')->where('status', 'posted')
            ->where('source_type', $party->getMorphClass())->where('source_id', $party->getKey())->with('lines')->first();
    }

    public function record(Model $party, string|int|float $amount, string $side, string $date): JournalEntry
    {
        $map = self::MAP[$party->getMorphClass()][$side] ?? throw new DomainException('Opening balances are not supported for this record.');

        return DB::transaction(function () use ($party, $amount, $side, $date, $map) {
            if ($this->existing($party)) {
                throw new DomainException("{$party->name} already has an opening balance. Cancel it first to enter a new one.");
            }

            [$code, $partyIsDebit] = $map;
            $partyLine = ['account' => $code, 'party' => $party] + ($partyIsDebit ? ['debit' => $amount] : ['credit' => $amount]);
            $equityLine = ['account' => AccountCodes::OPENING_EQUITY] + ($partyIsDebit ? ['credit' => $amount] : ['debit' => $amount]);

            return $this->posting->post([
                'date' => $date, 'voucher_type' => 'opening', 'source' => $party,
                'description' => "Opening balance - {$party->name}",
                'lines' => [$partyLine, $equityLine],
            ]);
        });
    }

    public function cancel(Model $party, ?string $reason = null): JournalEntry
    {
        return DB::transaction(function () use ($party, $reason) {
            $entry = $this->existing($party) ?? throw new DomainException('There is no opening balance to cancel.');

            return $this->posting->reverse($entry, $reason ?: 'Opening balance cancelled');
        });
    }
}
