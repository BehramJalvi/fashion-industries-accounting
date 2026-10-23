<?php

namespace App\Http\Controllers;

use App\Http\Requests\StorePaymentRequest;
use App\Models\Account;
use App\Models\Employee;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Services\Payments\SettlementService;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\View\View;

class PaymentController extends Controller
{
    public function __construct(private SettlementService $service)
    {
    }

    public function index(Request $request): View
    {
        Gate::authorize('payments.view');
        $request->validate(['from' => 'nullable|date', 'to' => 'nullable|date']);

        $payments = Payment::with('account')
            ->when($request->payee, fn ($q, $v) => $q->where('payee_type', explode(':', $v)[0])->where('payee_id', (int) (explode(':', $v)[1] ?? 0)))
            ->when($request->status, fn ($q, $v) => $q->where('status', $v))
            ->when($request->from, fn ($q, $v) => $q->whereDate('payment_date', '>=', $v))
            ->when($request->to, fn ($q, $v) => $q->whereDate('payment_date', '<=', $v))
            ->orderByDesc('payment_date')->orderByDesc('id')->paginate(20)->withQueryString();

        $applied = PaymentAllocation::where('document_type', 'payment')->where('is_active', true)->whereIn('document_id', $payments->pluck('id'))
            ->selectRaw('document_id, SUM(amount) as a')->groupBy('document_id')->pluck('a', 'document_id');

        return view('payments.index', ['payments' => $payments, 'applied' => $applied, 'payees' => $this->payees(), 'names' => $this->names($payments->getCollection())]);
    }

    /** Step 1: pick the supplier or employee. Step 2 (?payee=supplier:3): amount, account and how it is applied to open bills. */
    public function create(Request $request): View
    {
        Gate::authorize('payments.create');

        if (! $request->payee) {
            return view('payments.choose', ['payees' => $this->payees()]);
        }

        [$type, $id] = $this->parsePayee($request->payee);
        $payee = $type === 'employee' ? Employee::findOrFail($id) : Supplier::findOrFail($id);

        return view('payments.create', [
            'payee' => $payee, 'type' => $type, 'open' => $this->service->openFor('payment', $payee->id, $type),
            'accounts' => Account::active()->whereIn('sub_type', ['cash', 'bank'])->orderBy('code')->get(['id', 'code', 'name']),
            'owed' => $payee->ledgerBalance(),
        ]);
    }

    public function store(StorePaymentRequest $request): RedirectResponse
    {
        $data = $request->validated();
        $postNow = ($data['action'] ?? 'draft') === 'post';
        if ($postNow) {
            Gate::authorize('payments.post');
        }

        try {
            $payment = $this->service->createDraft('payment', $data, $data['alloc'] ?? [], $request->boolean('auto_allocate'));
        } catch (DomainException $e) {
            return back()->withInput()->withErrors(['payment' => $e->getMessage()]);
        }

        if ($postNow) {
            try {
                $this->service->post($payment);
            } catch (DomainException $e) {
                return redirect()->route('payments.show', $payment)->with('error', "Saved as a draft but not posted: {$e->getMessage()}");
            }
        }

        return redirect()->route('payments.show', $payment)->with('success', "{$payment->payment_no} " . ($postNow ? 'posted.' : 'saved as a draft.'));
    }

    public function show(Payment $payment): View
    {
        Gate::authorize('payments.view');
        $payment->load(['account', 'journalEntry', 'reversalEntry', 'creator', 'canceller']);

        $allocations = PaymentAllocation::where('document_type', 'payment')->where('document_id', $payment->id)->orderBy('id')->get();
        $bills = Purchase::whereIn('id', $allocations->pluck('target_id'))->get()->keyBy('id');
        $unallocated = $payment->isPosted() ? $payment->unallocatedCents() : 0;

        return view('payments.show', [
            'payment' => $payment, 'payee' => $payment->payee(), 'allocations' => $allocations, 'bills' => $bills, 'unallocated' => $unallocated,
            'open' => $unallocated > 0 ? $this->service->openFor('payment', $payment->payee_id, $payment->payee_type) : collect(),
        ]);
    }

    public function post(Payment $payment): RedirectResponse
    {
        Gate::authorize('payments.post');

        try {
            $this->service->post($payment);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Payment posted.');
    }

    public function allocate(Request $request, Payment $payment): RedirectResponse
    {
        Gate::authorize('payments.post');
        $data = $request->validate(['alloc' => ['required', 'array'], 'alloc.*' => ['nullable', 'numeric', 'min:0', 'max:9999999999999']]);

        try {
            $this->service->applyLater($payment, $data['alloc']);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Applied to the selected bills.');
    }

    public function cancel(Request $request, Payment $payment): RedirectResponse
    {
        Gate::authorize('payments.cancel');
        $request->validate(['reason' => ['required', 'string', 'max:200']]);

        try {
            $this->service->cancel($payment, $request->string('reason')->toString());
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Payment cancelled. The bills it paid are unpaid again.');
    }

    public function destroy(Payment $payment): RedirectResponse
    {
        Gate::authorize('payments.update');

        try {
            $this->service->deleteDraft($payment);
        } catch (DomainException $e) {
            return back()->with('error', $e->getMessage());
        }

        return redirect()->route('payments.index')->with('success', 'Draft deleted.');
    }

    /** @return array{0:string,1:int} */
    private function parsePayee(string $value): array
    {
        [$type, $id] = array_pad(explode(':', $value, 2), 2, null);
        abort_unless(in_array($type, ['supplier', 'employee'], true) && ctype_digit((string) $id), 404);

        return [$type, (int) $id];
    }

    private function payees(): array
    {
        return ['Suppliers' => Supplier::orderBy('name')->get(['id', 'name'])->map(fn ($s) => ['value' => "supplier:{$s->id}", 'name' => $s->name])->all(),
                'Employees' => Employee::orderBy('name')->get(['id', 'name'])->map(fn ($e) => ['value' => "employee:{$e->id}", 'name' => $e->name])->all()];
    }

    /** "supplier:3" => name, for the list page */
    private function names($payments): array
    {
        $names = [];
        foreach ($payments->groupBy('payee_type') as $type => $group) {
            $model = $type === 'employee' ? Employee::class : Supplier::class;
            foreach ($model::withTrashed()->whereIn('id', $group->pluck('payee_id'))->get(['id', 'name']) as $p) {
                $names["{$type}:{$p->id}"] = $p->name;
            }
        }

        return $names;
    }
}
