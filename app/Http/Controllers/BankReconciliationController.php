<?php

namespace App\Http\Controllers;

use App\Http\Requests\MatchBankReconciliationLineRequest;
use App\Http\Requests\PreviewBankStatementRequest;
use App\Http\Requests\StoreBankReconciliationLineRequest;
use App\Models\Account;
use App\Models\BankReconciliation;
use App\Models\BankReconciliationLine;
use App\Services\Banking\BankReconciliationService;
use App\Support\Money;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\View\View;

class BankReconciliationController extends Controller
{
    public function __construct(private BankReconciliationService $service) {}

    public function index(Request $request): View
    {
        Gate::authorize('bank_reconciliations.view');
        $request->validate(['status' => ['nullable', 'in:draft,reconciled']]);

        return view('bank-reconciliations.index', [
            'reconciliations' => BankReconciliation::query()
                ->with('account:id,code,name')
                ->when($request->status, fn ($query, $status) => $query->where('status', $status))
                ->orderByDesc('period_end')
                ->orderByDesc('id')
                ->paginate(20)
                ->withQueryString(),
        ]);
    }

    public function create(): View
    {
        Gate::authorize('bank_reconciliations.create');

        return view('bank-reconciliations.create', [
            'accounts' => Account::active()->where('sub_type', 'bank')->orderBy('code')->get(['id', 'code', 'name']),
        ]);
    }

    public function preview(PreviewBankStatementRequest $request): View
    {
        $data = $request->validated();
        $lines = $this->service->parseCsv($data['statement_file'], $data['period_start'], $data['period_end']);
        $previewToken = (string) Str::uuid();
        $header = [
            'account_id' => (int) $data['account_id'],
            'period_start' => $data['period_start'],
            'period_end' => $data['period_end'],
            'opening_balance' => $data['opening_balance'],
            'closing_balance' => $data['closing_balance'],
        ];
        $request->session()->put("bank-reconciliation-preview.{$previewToken}", [
            'user_id' => $request->user()->id,
            'created_at' => now()->timestamp,
            'header' => $header,
            'lines' => $lines,
        ]);
        $statementTotalCents = collect($lines)->sum(fn (array $line) => Money::toCents($line['signed_amount']));
        $openingCents = Money::toCents($header['opening_balance']);
        $closingCents = Money::toCents($header['closing_balance']);

        return view('bank-reconciliations.preview', [
            'header' => $header,
            'account' => Account::findOrFail($header['account_id']),
            'lines' => $lines,
            'previewToken' => $previewToken,
            'statementTotalCents' => $statementTotalCents,
            'expectedCloseCents' => $openingCents + $statementTotalCents,
            'differenceCents' => $closingCents - ($openingCents + $statementTotalCents),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        Gate::authorize('bank_reconciliations.create');
        $data = $request->validate([
            'account_id' => ['required', 'integer', 'exists:accounts,id'],
            'period_start' => ['required', 'date'],
            'period_end' => ['required', 'date', 'after_or_equal:period_start'],
            'opening_balance' => ['required', 'numeric', 'decimal:0,2', 'between:-9999999999999,9999999999999', 'regex:/^-?\d+(?:\.\d{1,2})?$/'],
            'closing_balance' => ['required', 'numeric', 'decimal:0,2', 'between:-9999999999999,9999999999999', 'regex:/^-?\d+(?:\.\d{1,2})?$/'],
        ]);
        try {
            $reconciliation = $this->service->createStatement($data, [], 'manual');
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['reconciliation' => $exception->getMessage()]);
        }

        return redirect()->route('bank-reconciliations.show', $reconciliation)->with('success', 'Manual reconciliation started. Add statement lines to continue.');
    }

    public function import(Request $request): RedirectResponse
    {
        Gate::authorize('bank_reconciliations.create');
        $data = $request->validate(['preview_token' => ['required', 'uuid']]);
        $sessionKey = "bank-reconciliation-preview.{$data['preview_token']}";
        $preview = $request->session()->pull($sessionKey);

        if (! is_array($preview)
            || ($preview['user_id'] ?? null) !== $request->user()->id
            || ($preview['created_at'] ?? 0) < now()->subMinutes(30)->timestamp
            || ! isset($preview['header'], $preview['lines'])) {
            return back()->withErrors(['preview_token' => 'The statement preview expired. Upload and preview the file again.']);
        }

        try {
            $reconciliation = $this->service->createStatement($preview['header'], $preview['lines'], 'csv');
        } catch (DomainException $exception) {
            return back()->withErrors(['reconciliation' => $exception->getMessage()]);
        }

        return redirect()->route('bank-reconciliations.show', $reconciliation)
            ->with('success', 'Statement validated and recorded. Review matches and unmatched items before reconciling.');
    }

    public function show(BankReconciliation $bankReconciliation): View
    {
        Gate::authorize('bank_reconciliations.view');
        $bankReconciliation->load(['account:id,code,name', 'creator', 'reconciler']);
        $lines = $bankReconciliation->lines()
            ->with(['matchedJournalLine.entry', 'matcher'])
            ->orderBy('statement_date')
            ->orderBy('id')
            ->paginate(50);
        $candidates = collect();
        if ($bankReconciliation->status === 'draft') {
            foreach ($lines as $line) {
                if (! $line->matched_journal_entry_line_id) {
                    $candidates->put($line->id, $this->service->candidatesForLine($bankReconciliation, $line));
                }
            }
        }

        return view('bank-reconciliations.show', [
            'reconciliation' => $bankReconciliation,
            'lines' => $lines,
            'summary' => $this->service->summary($bankReconciliation),
            'candidates' => $candidates,
            'unmatchedBookItems' => $this->service->unmatchedBookLines($bankReconciliation),
        ]);
    }

    public function storeLine(StoreBankReconciliationLineRequest $request, BankReconciliation $bankReconciliation): RedirectResponse
    {
        try {
            $this->service->addManualLine($bankReconciliation, $request->validated());
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['statement_line' => $exception->getMessage()]);
        }

        return back()->with('success', 'Statement line added. Any unique exact match was linked automatically.');
    }

    public function updateLine(StoreBankReconciliationLineRequest $request, BankReconciliation $bankReconciliation, BankReconciliationLine $line): RedirectResponse
    {
        try {
            $this->service->updateLine($bankReconciliation, $line, $request->validated());
        } catch (DomainException $exception) {
            return back()->withInput()->withErrors(['statement_line' => $exception->getMessage()]);
        }

        return back()->with('success', 'Statement line updated. Its previous match was cleared and an exact unique match was reconsidered.');
    }

    public function editLine(BankReconciliation $bankReconciliation, BankReconciliationLine $line): View
    {
        Gate::authorize('bank_reconciliations.update');
        abort_unless($bankReconciliation->status === 'draft', 404);
        abort_unless($line->reconciliation_id === $bankReconciliation->id, 404);

        return view('bank-reconciliations.edit-line', compact('bankReconciliation', 'line'));
    }

    public function deleteLine(BankReconciliation $bankReconciliation, BankReconciliationLine $line): RedirectResponse
    {
        Gate::authorize('bank_reconciliations.update');

        try {
            $this->service->deleteLine($bankReconciliation, $line);
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Statement line removed from the draft reconciliation. No journal entry was changed.');
    }

    public function autoMatch(BankReconciliation $bankReconciliation): RedirectResponse
    {
        Gate::authorize('bank_reconciliations.update');

        try {
            $matched = $this->service->autoMatch($bankReconciliation);
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', "Automatically matched {$matched} statement line(s).");
    }

    public function match(
        MatchBankReconciliationLineRequest $request,
        BankReconciliation $bankReconciliation,
        BankReconciliationLine $line
    ): RedirectResponse {
        try {
            $this->service->matchLine($bankReconciliation, $line, $request->integer('journal_entry_line_id'));
        } catch (DomainException $exception) {
            return back()->withErrors(['match' => $exception->getMessage()]);
        }

        return back()->with('success', 'Statement line matched to the selected posted bank transaction.');
    }

    public function reconcile(BankReconciliation $bankReconciliation): RedirectResponse
    {
        Gate::authorize('bank_reconciliations.reconcile');

        try {
            $this->service->reconcile($bankReconciliation);
        } catch (DomainException $exception) {
            return back()->with('error', $exception->getMessage());
        }

        return back()->with('success', 'Bank statement reconciled. The statement and matches are now immutable.');
    }
}
