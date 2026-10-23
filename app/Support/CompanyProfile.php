<?php

namespace App\Support;

use App\Models\Setting;

/** Company details and invoice options, read from Settings in one place (invoice print, headers, future reports). */
class CompanyProfile
{
    /** @return array<string, mixed> */
    public static function current(): array
    {
        $logo = Setting::get('company_logo_path');

        return [
            'name'        => Setting::get('company_name', 'Fashion Industries INC'),
            'tagline'     => Setting::get('company_tagline'),
            'address'     => Setting::get('company_address'),
            'phone'       => Setting::get('company_phone'),
            'email'       => Setting::get('company_email'),
            'ntn'         => Setting::get('company_ntn'),
            'strn'        => Setting::get('company_strn'),
            'logoUrl'     => $logo ? asset('storage/'.$logo) : null,
            'title'       => Setting::get('invoice_title', 'SALES TAX INVOICE'),
            'footer'      => Setting::get('invoice_footer'),
            'minRows'     => (int) Setting::get('invoice_min_rows', 10),
            'showPayment' => (bool) Setting::get('invoice_show_payment', true),
        ];
    }
}
