<?php

namespace App\Http\Controllers;

use App\Models\Account;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\JournalEntry;
use App\Models\Owner;
use App\Models\Supplier;
use App\Services\Reports\LedgerService;
use App\Services\Reports\ReportPeriod;
use App\Support\Csv;
use App\Support\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/** General ledger with presets: cash book, bank book, expense ledger, owner capital ... filter by date, account, customer / supplier, transaction type. */
class LedgerController extends Controller
{
    public function index(Request $request, LedgerService $service): mixed
    {
        Gate::authorize('ledgers.view');
        $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from', 'account_id' => 'nullable|integer|exists:accounts,id', 'all' => 'nullable|boolean',
            'party' => ['nullable', 'regex:/^(customer|supplier|employee|owner):\d+$/'], 'preset' => 'nullable|string|in:'.implode(',', array_keys(LedgerService::PRESETS))]);

        [$from, $to] = ReportPeriod::resolve($request->from, $request->to);
        $all = $request->boolean('all');
        $ids = $all ? [] : ($request->preset ? $service->presetAccountIds($request->preset) : ($request->account_id ? [(int) $request->account_id] : []));
        [$partyType, $partyId] = $request->party ? explode(':', $request->party) : [null, null];

        $statement = $ids ? $service->statement($ids, $from, $to, $request->voucher_type, $partyType, $partyId ? (int) $partyId : null) : null;
        $allLedgers = $all ? $service->allAccountStatements($from, $to) : null;

        if ($statement && $request->query('export') === 'csv') {
            Gate::authorize('reports.export');
            $rows = array_map(fn ($r) => [$r['date'], $r['entry_no'], $r['voucher_type'], $r['account'], $r['party'], $r['reference'], $r['description'], $r['status'],
                Money::fromCents($r['debit']), Money::fromCents($r['credit']), $r['balance'] === null ? '' : Money::fromCents($r['balance'])], $statement['rows']);

            return Csv::download("ledger-{$from}-to-{$to}.csv", ['Date', 'Entry', 'Type', 'Account', 'Party', 'Reference', 'Description', 'Status', 'Debit', 'Credit', 'Balance'], $rows);
        }

        return view('ledgers.index', [
            'statement' => $statement, 'allLedgers' => $allLedgers, 'from' => $from, 'to' => $to, 'preset' => $request->preset, 'presets' => LedgerService::PRESETS,
            'accounts' => Account::orderBy('code')->get(['id', 'code', 'name', 'type'])->groupBy('type'),
            'selected' => $ids ? Account::whereIn('id', $ids)->orderBy('code')->pluck('name')->all() : [],
            'parties' => ['Customers' => Customer::orderBy('name')->get(['id', 'name'])->map(fn ($c) => ['v' => "customer:{$c->id}", 'n' => $c->name])->all(),
                'Suppliers' => Supplier::orderBy('name')->get(['id', 'name'])->map(fn ($c) => ['v' => "supplier:{$c->id}", 'n' => $c->name])->all(),
                'Employees' => Employee::orderBy('name')->get(['id', 'name'])->map(fn ($c) => ['v' => "employee:{$c->id}", 'n' => $c->name])->all(),
                'Owners' => Owner::orderBy('name')->get(['id', 'name'])->map(fn ($c) => ['v' => "owner:{$c->id}", 'n' => $c->name])->all()],
            'voucherTypes' => JournalEntry::query()->select('voucher_type')->distinct()->orderBy('voucher_type')->pluck('voucher_type'),
            'customers' => Customer::orderBy('name')->get(['id', 'name']), 'suppliers' => Supplier::orderBy('name')->get(['id', 'name']),
        ]);
    }
}
