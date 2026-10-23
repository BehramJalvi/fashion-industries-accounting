<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class AuditLogController extends Controller
{
    public function index(Request $request): View
    {
        Gate::authorize('audit.view');

        $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date', 'user_id' => 'nullable|integer', 'event' => 'nullable|string|max:50']);

        $logs = AuditLog::with('user')
            ->when($request->user_id, fn ($q, $v) => $q->where('user_id', $v))
            ->when($request->event, fn ($q, $v) => $q->where('event', $v))
            ->when($request->from, fn ($q, $v) => $q->whereDate('created_at', '>=', $v))
            ->when($request->to, fn ($q, $v) => $q->whereDate('created_at', '<=', $v))
            ->latest('id')->paginate(30)->withQueryString();

        return view('audit.index', [
            'logs' => $logs,
            'users' => User::orderBy('name')->get(['id', 'name']),
            'events' => AuditLog::query()->select('event')->distinct()->orderBy('event')->pluck('event'),
        ]);
    }
}
