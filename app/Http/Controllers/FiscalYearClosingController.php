<?php

namespace App\Http\Controllers;

use App\Http\Requests\ReopenFiscalYearRequest;
use App\Models\FiscalYear;
use App\Services\Accounting\FiscalYearClosingService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class FiscalYearClosingController extends Controller
{
    public function __construct(private FiscalYearClosingService $service) {}

    public function index(): View
    {
        Gate::authorize('fiscal_years.view');

        return view('fiscal-years.index', [
            'fiscalYears' => FiscalYear::with([
                'closeEvents.creator',
                'closeEvents.journalEntry',
                'closeEvents.reversalEntry',
            ])->orderByDesc('start_date')->orderByDesc('id')->paginate(20),
        ]);
    }

    public function close(FiscalYear $fiscalYear): RedirectResponse
    {
        Gate::authorize('fiscal_years.close');

        try {
            $event = $this->service->close($fiscalYear);
        } catch (DomainException $exception) {
            return back()->withErrors(['fiscal_year' => $exception->getMessage()]);
        }

        return back()->with('success', "Fiscal year {$fiscalYear->name} closed (cycle {$event->cycle}).");
    }

    public function reopen(ReopenFiscalYearRequest $request, FiscalYear $fiscalYear): RedirectResponse
    {
        try {
            $event = $this->service->reopen($fiscalYear, $request->validated()['reason']);
        } catch (DomainException $exception) {
            return back()->withErrors(['fiscal_year' => $exception->getMessage()]);
        }

        return back()->with('success', "Fiscal year {$fiscalYear->name} reopened (cycle {$event->cycle}).");
    }
}
