<?php

namespace Database\Seeders;

use App\Models\Setting;
use Illuminate\Database\Seeder;

class SettingsSeeder extends Seeder
{
    /** Never overwrites a value you have already changed. NTN / STRN / address are taken from your sample invoice. */
    public function run(): void
    {
        $defaults = [
            // company / invoice header
            ['company_name', 'Fashion Industries INC', 'company'],
            ['company_tagline', 'Manufacturer of Sewing threads, Accessories & Trims', 'company'],
            ['company_address', 'P-11 Millat Industrial Estate, Millat Road, Faisalabad, Pakistan', 'company'],
            ['company_phone', null, 'company'],
            ['company_email', null, 'company'],
            ['company_ntn', '8544806-0', 'company'],
            ['company_strn', '3277876303861', 'company'],
            ['company_logo_path', null, 'company'],
            // money & tax
            ['base_currency', 'PKR', 'accounting'],
            ['default_sales_tax_rate', 18, 'accounting'],
            ['allow_negative_stock', false, 'inventory'],
            // invoice
            ['invoice_title', 'SALES TAX INVOICE', 'invoice'],
            ['invoice_min_rows', 10, 'invoice'],
            ['invoice_show_payment', true, 'invoice'],
            ['invoice_footer', 'This invoice is computer generated; signature and stamp are not required.', 'invoice'],
        ];

        foreach ($defaults as [$key, $value, $group]) {
            Setting::firstOrCreate(['key' => $key], ['value' => $value, 'group' => $group]);
        }
    }
}
