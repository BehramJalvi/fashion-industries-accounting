<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateSettingsRequest;
use App\Models\NumberSequence;
use App\Models\Setting;
use App\Support\AuditLogger;
use App\Support\CompanyProfile;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class SettingsController extends Controller
{
    /** key => [group, type] */
    private const FIELDS = [
        'company_name' => ['company', 'string'], 'company_tagline' => ['company', 'string'], 'company_address' => ['company', 'string'],
        'company_phone' => ['company', 'string'], 'company_email' => ['company', 'string'], 'company_ntn' => ['company', 'string'], 'company_strn' => ['company', 'string'],
        'invoice_title' => ['invoice', 'string'], 'invoice_footer' => ['invoice', 'string'], 'invoice_min_rows' => ['invoice', 'int'], 'invoice_show_payment' => ['invoice', 'bool'],
        'default_sales_tax_rate' => ['accounting', 'number'], 'allow_negative_stock' => ['inventory', 'bool'],
    ];

    public function index(): View
    {
        Gate::authorize('settings.view');

        return view('settings.index', [
            'co' => CompanyProfile::current(),
            'values' => collect(self::FIELDS)->map(fn ($def, $key) => Setting::get($key))->all(),
            'sale' => NumberSequence::where('doc_type', 'sale')->first(),
            'others' => NumberSequence::where('doc_type', '!=', 'sale')->orderBy('doc_type')->get(),
        ]);
    }

    public function update(UpdateSettingsRequest $request): RedirectResponse
    {
        $data = $request->validated();

        DB::transaction(function () use ($data, $request) {
            foreach (self::FIELDS as $key => [$group, $type]) {
                $value = match ($type) {
                    'bool'   => $request->boolean($key),
                    'int'    => (int) ($data[$key] ?? 0),
                    'number' => (float) ($data[$key] ?? 0),
                    default  => $data[$key] ?? null,
                };
                Setting::set($key, $value, $group);          // audited by the Setting observer when it changes
            }

            $this->updateLogo($request);

            $sequence = NumberSequence::where('doc_type', 'sale')->lockForUpdate()->firstOrFail();
            $new = ['prefix' => (string) ($data['sale_prefix'] ?? ''), 'next_number' => (int) $data['sale_next_number'], 'padding' => (int) $data['sale_padding']];
            $old = $sequence->only(['prefix', 'next_number', 'padding']);
            if ($old != $new) {
                $sequence->update($new);
                AuditLogger::log('numbering_changed', $sequence, $old, $new);
            }
        });

        return redirect()->route('settings.index')->with('success', 'Settings saved.');
    }

    private function updateLogo(UpdateSettingsRequest $request): void
    {
        $current = Setting::get('company_logo_path');

        if ($request->hasFile('logo')) {
            $path = $request->file('logo')->store('branding', 'public');      // random server-side name
            Setting::set('company_logo_path', $path, 'company');
            if ($current) {
                Storage::disk('public')->delete($current);
            }
        } elseif ($request->boolean('remove_logo') && $current) {
            Storage::disk('public')->delete($current);
            Setting::set('company_logo_path', null, 'company');
        }
    }
}
