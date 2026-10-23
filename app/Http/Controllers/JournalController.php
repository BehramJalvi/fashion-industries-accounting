<?php

namespace App\Http\Controllers;

use App\Exceptions\Accounting\PostingException;
use App\Http\Requests\StoreJournalRequest;
use App\Models\Account;
use App\Models\Customer;
use App\Models\Employee;
use App\Models\JournalEntry;
use App\Models\Owner;
use App\Models\Supplier;
use App\Services\Accounting\PostingService;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class JournalController extends Controller
{
    public function __construct(private PostingService $posting)
    {
    }

    public function index(Request $request): View
    {
        Gate::authorize('journal.view');

        $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date', 'status' => 'nullable|in:posted,cancelled']);

        $entries = JournalEntry::query()
            ->when($request->from, fn ($q, $v) => $q->whereDate('entry_date', '>=', $v))
            ->when($request->to, fn ($q, $v) => $q->whereDate('entry_date', '<=', $v))
            ->when($request->voucher_type, fn ($q, $v) => $q->where('voucher_type', $v))
            ->when($request->status, fn ($q, $v) => $q->where('status', $v))
            ->when($request->q, fn ($q, $t) => $q->where(fn ($w) => $w->where('entry_no', 'like', "%$t%")
                ->orWhere('reference', 'like', "%$t%")->orWhere('description', 'like', "%$t%")))
            ->orderByDesc('entry_date')->orderByDesc('id')->paginate(25)->withQueryString();

        return view('journal.index', [
            'entries' => $entries,
            'voucherTypes' => JournalEntry::query()->select('voucher_type')->distinct()->orderBy('voucher_type')->pluck('voucher_type'),
        ]);
    }

    public function show(JournalEntry $entry): View
    {
        Gate::authorize('journal.view');

        return view('journal.show', ['entry' => $entry->load(['lines.account', 'creator', 'reverses', 'reversal'])]);
    }

    public function create(): View
    {
        Gate::authorize('journal.create');
        Gate::authorize('journal.post');

        return view('journal.create', [
            'accounts' => Account::active()->orderBy('code')->get(['id', 'code', 'name', 'party_type']),
            'parties' => [
                'customer' => Customer::orderBy('name')->get(['id', 'name']),
                'supplier' => Supplier::orderBy('name')->get(['id', 'name']),
                'employee' => Employee::orderBy('name')->get(['id', 'name']),
                'owner'    => Owner::orderBy('name')->get(['id', 'name']),
            ],
        ]);
    }

    public function store(StoreJournalRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $lines = collect($data['lines'])->map(function (array $l) {
            $party = null;
            if (! empty($l['party'])) {
                [$type, $id] = explode(':', $l['party']);
                $party = Relation::getMorphedModel($type)::find($id);
            }

            return [
                'account' => (int) $l['account_id'], 'debit' => $l['debit'] ?? 0, 'credit' => $l['credit'] ?? 0,
                'party' => $party, 'narration' => $l['narration'] ?? null,
            ];
        })->all();

        try {
            $entry = $this->posting->post([
                'date' => $data['entry_date'], 'voucher_type' => 'journal',
                'reference' => $data['reference'] ?? null, 'description' => $data['description'], 'lines' => $lines,
            ]);
        } catch (PostingException $e) {
            return back()->withInput()->withErrors(['entry' => $e->getMessage()]);
        }

        return redirect()->route('journal.show', $entry)->with('success', "Journal entry {$entry->entry_no} posted.");
    }

    public function cancel(Request $request, JournalEntry $entry): RedirectResponse
    {
        Gate::authorize('journal.cancel');
        $request->validate(['reason' => ['required', 'string', 'max:200']]);

        // Entries created by a document (sale, receipt ...) must be cancelled from that document so its status stays in step.
        if ($entry->voucher_type !== 'journal' || $entry->source_type !== null) {
            return back()->with('error', 'Only manual journal entries can be cancelled here. Cancel the source transaction instead.');
        }

        try {
            $reversal = $this->posting->reverse($entry, $request->string('reason')->toString());
        } catch (PostingException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('journal.show', $entry)->with('success', "Cancelled. Reversal entry {$reversal->entry_no} posted.");
    }
}
