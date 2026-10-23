<?php

namespace App\Services\Sales;

use App\Models\Invoice;
use App\Models\Sale;
use App\Models\User;
use App\Support\AuditLogger;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * Which copy of an invoice may be printed, and the print log.
 *   ORIGINAL   the customer's copy: printed once; printing it again needs the 'invoices.reprint' permission (administrator)
 *   DUPLICATE  any number of times, for a posted invoice
 *   CANCELLED  the only copy a cancelled invoice has
 * A draft has no invoice number and cannot be printed. The page can be previewed freely; a print is logged when the user presses Print.
 */
class InvoiceService
{
    public const COPIES = ['original', 'duplicate', 'cancelled'];

    /** The copy to show, applying the rules above. @throws DomainException with a message safe to show */
    public function resolveCopy(Sale $sale, ?string $requested, User $user): string
    {
        if (! in_array($sale->status, ['posted', 'cancelled'], true)) {
            throw new DomainException('Only a posted invoice can be printed. Post the invoice first.');
        }
        if ($requested !== null && $requested !== '' && ! in_array($requested, self::COPIES, true)) {
            throw new DomainException('Unknown invoice copy.');
        }
        $requested = $requested ?: null;

        if ($sale->isCancelled()) {
            if ($requested && $requested !== 'cancelled') {
                throw new DomainException('This invoice is cancelled; only the CANCELLED copy can be printed.');
            }

            return 'cancelled';
        }
        if ($requested === 'cancelled') {
            throw new DomainException('Only a cancelled invoice has a CANCELLED copy.');
        }

        $originalPrinted = $sale->invoices()->where('copy_type', 'original')->exists();
        $copy = $requested ?? ($originalPrinted ? 'duplicate' : 'original');

        if ($copy === 'original' && $originalPrinted && ! $user->can('invoices.reprint')) {
            throw new DomainException('The original of this invoice has already been printed. Print a duplicate, or ask an administrator to reprint the original.');
        }

        return $copy;
    }

    public function logPrint(Sale $sale, string $copy, User $user): Invoice
    {
        return DB::transaction(function () use ($sale, $copy, $user) {
            $row = Invoice::where('sale_id', $sale->id)->where('copy_type', $copy)->lockForUpdate()->first();

            if ($row) {
                $row->forceFill(['print_count' => $row->print_count + 1, 'printed_by' => $user->id, 'printed_at' => now()])->save();
            } else {
                $row = Invoice::create(['sale_id' => $sale->id, 'copy_type' => $copy, 'print_count' => 1, 'printed_by' => $user->id, 'printed_at' => now()]);
            }

            AuditLogger::log('invoice_printed', $sale, null, ['invoice_no' => $sale->invoice_no, 'copy' => $copy, 'times' => $row->print_count]);

            return $row;
        });
    }
}
