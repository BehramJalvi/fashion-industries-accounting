<?php

namespace App\Http\Controllers;

use App\Services\Reports\DashboardService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(Request $request, DashboardService $service): View
    {
        $request->validate(['period' => 'nullable|in:month,fy,last30,custom', 'from' => 'nullable|date', 'to' => 'nullable|date|after_or_equal:from']);

        $period = $request->query('period', 'month');
        [$from, $to, $label] = $service->period($period, $request->query('from'), $request->query('to'));

        // Financial figures need the reports permission; a role with only dashboard access sees the operational cards.
        $showMoney = $request->user()->can('reports.view');

        return view('dashboard', [
            'd' => $showMoney ? $service->build($from, $to) : $service->buildOperational(),
            'showMoney' => $showMoney, 'period' => $period, 'from' => $from, 'to' => $to, 'label' => $label,
        ]);
    }
}
