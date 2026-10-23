<?php

namespace App\Services\Purchasing;

use App\Models\Purchase;
use App\Models\PurchaseInvoicePrint;
use App\Models\User;
use App\Support\AuditLogger;
use DomainException;
use Illuminate\Support\Facades\DB;

class PurchaseInvoiceService
{
    public function assertPrintable(Purchase $purchase): void
    {
        if (! in_array($purchase->status, ['posted', 'cancelled'], true)) {
            throw new DomainException('Only a posted purchase can be printed. Post the purchase first.');
        }
    }

    public function logPrint(Purchase $purchase, User $user): PurchaseInvoicePrint
    {
        return DB::transaction(function () use ($purchase, $user): PurchaseInvoicePrint {
            $lockedPurchase = Purchase::query()->lockForUpdate()->findOrFail($purchase->id);
            $this->assertPrintable($lockedPurchase);

            $print = PurchaseInvoicePrint::query()
                ->where('purchase_id', $lockedPurchase->id)
                ->lockForUpdate()
                ->first();

            if ($print) {
                $print->forceFill([
                    'print_count' => $print->print_count + 1,
                    'printed_by' => $user->id,
                    'printed_at' => now(),
                ])->save();
            } else {
                $print = PurchaseInvoicePrint::create([
                    'purchase_id' => $lockedPurchase->id,
                    'print_count' => 1,
                    'printed_by' => $user->id,
                    'printed_at' => now(),
                ]);
            }

            AuditLogger::log('purchase_invoice_printed', $lockedPurchase, null, [
                'purchase_no' => $lockedPurchase->purchase_no,
                'times' => $print->print_count,
            ]);

            return $print;
        });
    }
}
