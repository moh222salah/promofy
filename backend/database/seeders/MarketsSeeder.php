<?php

namespace Database\Seeders;

use App\Models\Country;
use App\Models\Currency;
use Illuminate\Database\Seeder;

/** Reference data only: Egypt and the GCC markets plus the currencies they use. Idempotent. */
class MarketsSeeder extends Seeder
{
    public function run(): void
    {
        $currencies = [
            ['EGP', 'جنيه مصري', 'Egyptian Pound', 'ج.م', 'EGP', 2],
            ['SAR', 'ريال سعودي', 'Saudi Riyal', 'ر.س', 'SAR', 2],
            ['AED', 'درهم إماراتي', 'UAE Dirham', 'د.إ', 'AED', 2],
            ['QAR', 'ريال قطري', 'Qatari Riyal', 'ر.ق', 'QAR', 2],
            ['KWD', 'دينار كويتي', 'Kuwaiti Dinar', 'د.ك', 'KWD', 3],
            ['BHD', 'دينار بحريني', 'Bahraini Dinar', 'د.ب', 'BHD', 3],
            ['OMR', 'ريال عماني', 'Omani Rial', 'ر.ع', 'OMR', 3],
            ['USD', 'دولار أمريكي', 'US Dollar', '$', '$', 2],
        ];
        foreach ($currencies as $i => [$code, $ar, $en, $symAr, $symEn, $decimals]) {
            Currency::query()->firstOrCreate(['code' => $code], [
                'name_ar' => $ar, 'name_en' => $en, 'symbol_ar' => $symAr, 'symbol_en' => $symEn,
                'decimals' => $decimals, 'is_active' => true, 'sort_order' => $i + 1,
            ]);
        }

        $countries = [
            ['EG', 'مصر', 'Egypt', '+20', 'EGP', '🇪🇬'],
            ['SA', 'السعودية', 'Saudi Arabia', '+966', 'SAR', '🇸🇦'],
            ['AE', 'الإمارات', 'United Arab Emirates', '+971', 'AED', '🇦🇪'],
            ['QA', 'قطر', 'Qatar', '+974', 'QAR', '🇶🇦'],
            ['KW', 'الكويت', 'Kuwait', '+965', 'KWD', '🇰🇼'],
            ['BH', 'البحرين', 'Bahrain', '+973', 'BHD', '🇧🇭'],
            ['OM', 'عُمان', 'Oman', '+968', 'OMR', '🇴🇲'],
        ];
        foreach ($countries as $i => [$code, $ar, $en, $phone, $cur, $flag]) {
            Country::query()->firstOrCreate(['code' => $code], [
                'name_ar' => $ar, 'name_en' => $en, 'phone_code' => $phone, 'currency_code' => $cur,
                'flag' => $flag, 'is_active' => true, 'is_market' => true, 'sort_order' => $i + 1,
            ]);
        }
    }
}
