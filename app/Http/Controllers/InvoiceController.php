<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Services\Sales\InvoiceService;
use App\Support\CompanyProfile;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class InvoiceController extends Controller
{
    /** The printable Sales Tax Invoice (no logging yet: that happens when Print is pressed). */
    public function show(Request $request, Sale $sale, InvoiceService $invoices): View|RedirectResponse
    {
        Gate::authorize('invoices.view');

        try {
            $copy = $invoices->resolveCopy($sale, $request->query('copy'), $request->user());
        } catch (DomainException $e) {
            return redirect()->route('sales.show', $sale)->with('error', $e->getMessage());
        }

        return view('sales.invoice', [
            'sale' => $sale->load(['items.product', 'customer']), 'copy' => $copy, 'co' => CompanyProfile::current(),
            'canPrint' => $request->user()->can('invoices.print'),
        ]);
    }

    /** Called by the Print button just before the browser print dialog opens. */
    public function printed(Request $request, Sale $sale, InvoiceService $invoices): JsonResponse
    {
        Gate::authorize('invoices.print');
        $data = $request->validate(['copy' => ['required', 'string']]);

        try {
            $copy = $invoices->resolveCopy($sale, $data['copy'], $request->user());
            $row = $invoices->logPrint($sale, $copy, $request->user());
        } catch (DomainException $e) {
            return response()->json(['ok' => false, 'message' => $e->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'copy' => $copy, 'times' => $row->print_count]);
    }
}
