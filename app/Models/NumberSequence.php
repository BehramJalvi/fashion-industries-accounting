<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class NumberSequence extends Model
{
    protected $fillable = ['doc_type', 'prefix', 'next_number', 'padding'];

    /**
     * Next document number, e.g. next('sale') => "INV-0258". Row-locked, so concurrent users never get the same number.
     * Call inside the same DB transaction that saves the document so a rollback releases the number.
     */
    public static function next(string $docType): string
    {
        return DB::transaction(function () use ($docType) {
            $seq = static::where('doc_type', $docType)->lockForUpdate()->firstOrFail();
            $number = $seq->prefix . str_pad((string) $seq->next_number, $seq->padding, '0', STR_PAD_LEFT);
            $seq->increment('next_number');

            return $number;
        });
    }
}
