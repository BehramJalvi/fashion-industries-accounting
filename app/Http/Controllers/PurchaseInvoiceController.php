<?php

namespace App\Http\Controllers;

use App\Models\Purchase;
use App\Services\Purchasing\PurchaseInvoiceService;
use App\Support\CompanyProfile;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class PurchaseInvoiceController extends Controller
{
    public function show(Request $request, Purchase $purchase, PurchaseInvoiceService $invoices): View|RedirectResponse
    {
        Gate::authorize('purchases.view');

        try {
            $invoices->assertPrintable($purchase);
        } catch (DomainException $exception) {
            return redirect()->route('purchases.show', $purchase)->with('error', $exception->getMessage());
        }

        $purchase->load([
            'items.product.unit',
            'supplier',
            'fundedByEmployee',
            'paidFromAccount',
            'invoicePrint.printedBy',
            'journalEntry',
            'reversalEntry',
        ]);

        return view('purchases.invoice', [
            'purchase' => $purchase,
            'summary' => $purchase->summary(),
            'co' => CompanyProfile::current(),
            'canPrint' => $request->user()->can('purchases.print'),
        ]);
    }

    public function printed(Request $request, Purchase $purchase, PurchaseInvoiceService $invoices): JsonResponse
    {
        Gate::authorize('purchases.print');

        try {
            $record = $invoices->logPrint($purchase, $request->user());
        } catch (DomainException $exception) {
            return response()->json(['ok' => false, 'message' => $exception->getMessage()], 422);
        }

        return response()->json(['ok' => true, 'times' => $record->print_count]);
    }
}
