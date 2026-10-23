<?php

namespace App\Services\Reports;

use App\Models\FiscalYear;
use Carbon\Carbon;

class ReportPeriod
{
    /** Default period: start of the fiscal year containing "to" (or Jan 1) through today. @return array{0:string,1:string} */
    public static function resolve(?string $from, ?string $to): array
    {
        $to = $to ?: Carbon::today()->toDateString();
        $from = $from ?: (FiscalYear::forDate($to)?->start_date?->toDateString() ?? Carbon::parse($to)->startOfYear()->toDateString());

        return [$from, $to];
    }
}
