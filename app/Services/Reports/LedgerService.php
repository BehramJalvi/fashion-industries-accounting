<?php

namespace App\Services\Reports;

use App\Models\Account;
use App\Models\JournalEntryLine;
use App\Support\Money;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * General ledger and its presets (cash book, bank book, expense ledger, owner capital ...), straight from journal_entry_lines.
 * Cancelled documents appear together with their reversing entries, so every balance is the true running balance.
 */
class LedgerService
{
    public const PRESETS = ['cash' => 'Cash book', 'bank' => 'Bank book', 'expense' => 'Expense ledger', 'owner' => 'Owner capital & drawings', 'inventory' => 'Inventory account', 'receivable' => 'Receivable control account', 'payable' => 'Payable control account'];

    /** @return int[] */
    public function presetAccountIds(string $preset): array
    {
        return match ($preset) {
            'cash' => Account::where('sub_type', 'cash')->pluck('id')->all(),
            'bank' => Account::where('sub_type', 'bank')->pluck('id')->all(),
            'expense' => Account::whereIn('type', ['expense', 'cogs'])->pluck('id')->all(),
            'owner' => Account::whereIn('sub_type', ['capital', 'drawings'])->pluck('id')->all(),
            'inventory' => Account::where('sub_type', 'inventory')->pluck('id')->all(),
            'receivable' => Account::where('sub_type', 'receivable')->pluck('id')->all(),
            'payable' => Account::where('sub_type', 'payable')->pluck('id')->all(),
            default => [],
        };
    }

    /**
     * @return array<int, array{account: Account, statement: array}>
     */
    public function allAccountStatements(string $from, string $to): array
    {
        $accounts = Account::orderBy('code')->get(['id', 'code', 'name', 'normal_balance']);
        $accountIds = $accounts->modelKeys();
        $statements = [];

        foreach ($accounts as $account) {
            $statements[$account->id] = [
                'sign' => $account->normal_balance === 'credit' ? -1 : 1,
                'opening' => 0,
                'rows' => [],
                'debit' => 0,
                'credit' => 0,
                'closing' => 0,
                'running' => true,
            ];
        }

        if (! $accountIds) {
            return [];
        }

        $base = fn () => JournalEntryLine::query()
            ->join('journal_entries as e', 'e.id', '=', 'journal_entry_lines.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'journal_entry_lines.account_id')
            ->whereIn('journal_entry_lines.account_id', $accountIds);

        $openings = $base()
            ->where('e.entry_date', '<', $from)
            ->select('journal_entry_lines.account_id')
            ->selectRaw('SUM(journal_entry_lines.debit) as d, SUM(journal_entry_lines.credit) as c')
            ->groupBy('journal_entry_lines.account_id')
            ->get()
            ->keyBy('account_id');

        foreach ($openings as $accountId => $opening) {
            $sign = $statements[$accountId]['sign'];
            $balance = $sign * (Money::toCents($opening->d) - Money::toCents($opening->c));
            $statements[$accountId]['opening'] = $balance;
            $statements[$accountId]['closing'] = $balance;
        }

        $lines = $base()
            ->where('e.entry_date', '>=', $from)
            ->where('e.entry_date', '<=', $to)
            ->orderBy('journal_entry_lines.account_id')
            ->orderBy('e.entry_date')
            ->orderBy('e.id')
            ->orderBy('journal_entry_lines.line_no')
            ->get(['journal_entry_lines.account_id', 'e.id as entry_id', 'e.entry_no', 'e.entry_date', 'e.voucher_type', 'e.reference', 'e.description', 'e.status',
                'a.code as account_code', 'a.name as account_name', 'journal_entry_lines.debit', 'journal_entry_lines.credit',
                'journal_entry_lines.narration', 'journal_entry_lines.party_type', 'journal_entry_lines.party_id']);

        $names = $this->partyNames($lines);
        foreach ($lines as $line) {
            $accountId = $line->account_id;
            $statement = &$statements[$accountId];
            $debit = Money::toCents($line->debit);
            $credit = Money::toCents($line->credit);
            $statement['closing'] += $statement['sign'] * ($debit - $credit);
            $statement['debit'] += $debit;
            $statement['credit'] += $credit;
            $statement['rows'][] = [
                'entry_id' => $line->entry_id,
                'entry_no' => $line->entry_no,
                'date' => substr((string) $line->entry_date, 0, 10),
                'voucher_type' => $line->voucher_type,
                'reference' => $line->reference,
                'description' => $line->narration ?: $line->description,
                'status' => $line->status,
                'account' => $line->account_code.' '.$line->account_name,
                'party' => $line->party_type ? ucfirst($line->party_type).': '.($names[$line->party_type][$line->party_id] ?? '#'.$line->party_id) : '',
                'debit' => $debit,
                'credit' => $credit,
                'balance' => $statement['closing'],
            ];
            unset($statement);
        }

        $allStatements = [];
        foreach ($accounts as $account) {
            $allStatements[] = ['account' => $account, 'statement' => $statements[$account->id]];
        }

        return $allStatements;
    }

    /**
     * @param  int[]  $accountIds
     * @return array{sign:int, opening:int, rows:array<int,array>, debit:int, credit:int, closing:?int, running:bool}
     *                                                                                                                sign: +1 when a debit increases the balance (assets, expenses), -1 for credit-natured accounts. Mixed accounts use debit-positive.
     *                                                                                                                The running balance is left out (running=false) when a transaction-type filter is applied, because the rows are then not continuous.
     */
    public function statement(array $accountIds, ?string $from = null, ?string $to = null, ?string $voucher = null, ?string $partyType = null, ?int $partyId = null): array
    {
        $natures = Account::whereIn('id', $accountIds)->pluck('normal_balance')->unique();
        $sign = $natures->count() === 1 && $natures->first() === 'credit' ? -1 : 1;

        $base = fn () => JournalEntryLine::query()
            ->join('journal_entries as e', 'e.id', '=', 'journal_entry_lines.journal_entry_id')
            ->join('accounts as a', 'a.id', '=', 'journal_entry_lines.account_id')
            ->whereIn('journal_entry_lines.account_id', $accountIds)
            ->when($partyType && $partyId, fn ($q) => $q->where('journal_entry_lines.party_type', $partyType)->where('journal_entry_lines.party_id', $partyId));

        $opening = 0;
        if ($from) {
            $o = $base()->where('e.entry_date', '<', $from)->selectRaw('SUM(journal_entry_lines.debit) as d, SUM(journal_entry_lines.credit) as c')->first();
            $opening = $sign * (Money::toCents($o->d) - Money::toCents($o->c));
        }

        $lines = $base()
            ->when($from, fn ($q, $v) => $q->where('e.entry_date', '>=', $v))
            ->when($to, fn ($q, $v) => $q->where('e.entry_date', '<=', $v))
            ->when($voucher, fn ($q, $v) => $q->where('e.voucher_type', $v))
            ->orderBy('e.entry_date')->orderBy('e.id')->orderBy('journal_entry_lines.line_no')
            ->get(['e.id as entry_id', 'e.entry_no', 'e.entry_date', 'e.voucher_type', 'e.reference', 'e.description', 'e.status',
                'a.code as account_code', 'a.name as account_name', 'journal_entry_lines.debit', 'journal_entry_lines.credit',
                'journal_entry_lines.narration', 'journal_entry_lines.party_type', 'journal_entry_lines.party_id']);

        $names = $this->partyNames($lines);
        $running = $opening;
        $debitTotal = $creditTotal = 0;
        $rows = [];
        foreach ($lines as $l) {
            $d = Money::toCents($l->debit);
            $c = Money::toCents($l->credit);
            $running += $sign * ($d - $c);
            $debitTotal += $d;
            $creditTotal += $c;
            $rows[] = [
                'entry_id' => $l->entry_id, 'entry_no' => $l->entry_no, 'date' => substr((string) $l->entry_date, 0, 10), 'voucher_type' => $l->voucher_type,
                'reference' => $l->reference, 'description' => $l->narration ?: $l->description, 'status' => $l->status,
                'account' => $l->account_code.' '.$l->account_name, 'party' => $l->party_type ? ucfirst($l->party_type).': '.($names[$l->party_type][$l->party_id] ?? '#'.$l->party_id) : '',
                'debit' => $d, 'credit' => $c, 'balance' => $voucher ? null : $running,
            ];
        }

        return ['sign' => $sign, 'opening' => $opening, 'rows' => $rows, 'debit' => $debitTotal, 'credit' => $creditTotal,
            'closing' => $voucher ? null : $running, 'running' => ! $voucher];
    }

    /** @return array<string, array<int,string>> type => id => name */
    private function partyNames($lines): array
    {
        $names = [];
        foreach ($lines->filter(fn ($l) => $l->party_type)->groupBy('party_type') as $type => $group) {
            $class = Relation::getMorphedModel($type);
            if ($class) {
                $names[$type] = $class::withTrashed()->whereIn('id', $group->pluck('party_id')->unique())->pluck('name', 'id')->all();
            }
        }

        return $names;
    }
}
