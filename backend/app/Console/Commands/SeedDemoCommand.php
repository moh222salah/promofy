<?php

namespace App\Console\Commands;

use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Opt-in DEMO data so the admin dashboard can be reviewed with colour and charts before real traffic exists.
 * Every record is tagged ("[Demo]" name / @demo.promofy.test e-mail / DEMO- number or SKU) and removed with --purge.
 * Public-facing claims (testimonials, case studies, portfolio, client logos) are never generated.
 */
class SeedDemoCommand extends Command
{
    protected $signature = 'promofy:seed-demo {--purge : Remove all demo records} {--force : Allow in production}';

    protected $description = 'Insert (or --purge) clearly tagged demo records for dashboard review';

    private const DOMAIN = 'demo.promofy.test';

    public function handle(): int
    {
        if (app()->isProduction() && ! $this->option('force')) {
            $this->error('Refusing to run in production without --force.');

            return self::FAILURE;
        }
        $this->purge();
        if ($this->option('purge')) {
            $this->info('Demo records removed.');

            return self::SUCCESS;
        }
        mt_srand(20261107);
        DB::transaction(fn () => $this->seed());
        $this->info('Demo records inserted (tagged [Demo] / @'.self::DOMAIN.'). Remove with: php artisan promofy:seed-demo --purge');

        return self::SUCCESS;
    }

    private function purge(): void
    {
        $demoProjects = DB::table('projects')->where('name', 'like', '[Demo]%')->pluck('id');
        $demoLeads = DB::table('leads')->where('email', 'like', '%@'.self::DOMAIN)->pluck('id');
        $demoCustomers = DB::table('customers')->where('email', 'like', '%@'.self::DOMAIN)->pluck('id');
        DB::table('tasks')->where('title', 'like', '[Demo]%')->delete();
        DB::table('crm_activities')->where('body', 'like', '[Demo]%')->delete();
        DB::table('projects')->whereIn('id', $demoProjects)->delete();
        DB::table('quotations')->where('number', 'like', 'DEMO-%')->delete();
        DB::table('orders')->where('number', 'like', 'DEMO-%')->delete();
        DB::table('leads')->whereIn('id', $demoLeads)->delete();
        DB::table('customers')->whereIn('id', $demoCustomers)->delete();
        DB::table('contact_messages')->where('email', 'like', '%@'.self::DOMAIN)->delete();
        DB::table('products')->where('sku', 'like', 'DEMO-%')->delete();
        DB::table('product_categories')->where('slug_en', 'like', 'demo-%')->delete();
        DB::table('notifications')->where('data', 'like', '%"demo":true%')->delete();
    }

    private function pick(array $a): mixed
    {
        return $a[mt_rand(0, count($a) - 1)];
    }

    /** Recent days are more likely, so trend charts look like a growing business. */
    private function when(int $maxDays = 75): CarbonImmutable
    {
        $d = (int) floor($maxDays * (mt_rand(0, 1000) / 1000) ** 1.6);

        return CarbonImmutable::now()->subDays($d)->setTime(mt_rand(8, 22), mt_rand(0, 59), mt_rand(0, 59));
    }

    private function seed(): void
    {
        $admin = User::query()->where('type', User::TYPE_STAFF)->orderBy('id')->value('id');
        $services = DB::table('services')->pluck('id')->all();
        $svcRows = DB::table('services')->get(['id', 'name_ar', 'name_en'])->keyBy('id');
        $countries = ['EG' => 'EGP', 'SA' => 'SAR', 'AE' => 'AED', 'QA' => 'QAR', 'KW' => 'KWD'];
        $weights = ['EG', 'EG', 'EG', 'EG', 'SA', 'SA', 'SA', 'AE', 'AE', 'QA', 'KW'];
        $industries = ['Food & Beverage', 'Real Estate', 'Fashion', 'Healthcare', 'Education', 'Technology', 'Hospitality'];
        $companies = ['Nile Foods', 'Gulf Bloom', 'Dune Realty', 'Atlas Fashion', 'Oasis Clinics', 'Pyramid Tech', 'Falcon Hospitality', 'Delta Academy', 'Marina Estates', 'Sahara Coffee', 'Cedar Interiors', 'Orbit Logistics', 'Pearl Jewels', 'Zenith Gym'];
        $people = ['Omar', 'Layla', 'Hassan', 'Nour', 'Khaled', 'Mariam', 'Yousef', 'Salma', 'Tariq', 'Huda', 'Ibrahim', 'Rana', 'Faisal', 'Dina'];
        $sources = ['instagram', 'instagram', 'facebook', 'google', 'google', 'website', 'website', 'whatsapp', 'referral', 'direct'];
        $campaigns = ['ramadan_launch', 'summer_sale', 'brand_refresh', 'reels_boost', 'google_search_q4'];
        $attr = fn () => ['utm_source' => $this->pick(['instagram', 'google', 'facebook', 'newsletter', 'direct']), 'utm_medium' => $this->pick(['cpc', 'social', 'email', 'organic']), 'utm_campaign' => $this->pick($campaigns)];
        $now = now();

        // customers
        $customerIds = [];
        foreach ($companies as $i => $co) {
            $cc = $this->pick($weights);
            $customerIds[] = DB::table('customers')->insertGetId([
                'name' => '[Demo] '.$people[$i].' — '.$co, 'company' => $co, 'email' => 'client'.($i + 1).'@'.self::DOMAIN, 'phone' => '+2010'.mt_rand(10000000, 99999999),
                'country_code' => $cc, 'industry' => $this->pick($industries), 'source' => $this->pick($sources), 'attribution' => json_encode($attr()),
                'created_by' => $admin, 'created_at' => $when = $this->when(), 'updated_at' => $when,
            ]);
        }

        // product catalogue
        $cats = [];
        foreach ([['Marketing Templates', 'قوالب تسويقية'], ['Creative Packages', 'باقات إبداعية'], ['Brand Kits', 'حقائب الهوية'], ['Learning Resources', 'موارد تعليمية']] as $i => [$en, $ar]) {
            $cats[] = DB::table('product_categories')->insertGetId(['slug_en' => 'demo-'.Str::slug($en), 'slug_ar' => 'demo-'.($i + 1).'-'.Str::slug($en), 'name_en' => '[Demo] '.$en, 'name_ar' => '[تجريبي] '.$ar, 'sort_order' => $i, 'is_active' => true, 'created_at' => $now, 'updated_at' => $now]);
        }
        $catalogue = [
            ['Social Media Template Pack', 'باقة قوالب السوشيال ميديا', 'template', 0, 450], ['Instagram Reels Cover Set', 'مجموعة أغلفة ريلز', 'digital_download', 0, 220],
            ['Brand Identity Starter Kit', 'حقيبة الهوية البصرية', 'creative_package', 2, 1800], ['Product Photography Package', 'باقة تصوير المنتجات', 'fixed_package', 1, 3500],
            ['Ad Creatives Bundle', 'حزمة تصاميم إعلانية', 'creative_package', 1, 950], ['Marketing Plan Workbook', 'كراسة الخطة التسويقية', 'digital_download', 3, 300],
            ['Monthly Content Retainer', 'اشتراك المحتوى الشهري', 'subscription', 1, 4200], ['Logo Motion Intro', 'مقدمة شعار متحرك', 'fixed_package', 2, 1200],
        ];
        $products = [];
        foreach ($catalogue as $i => [$en, $ar, $type, $cat, $price]) {
            $lowStock = $i === 3 || $i === 7; // exercise the low-stock widget
            $products[] = (object) ['id' => DB::table('products')->insertGetId([
                'product_category_id' => $cats[$cat], 'type' => $type, 'sku' => 'DEMO-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT), 'slug_en' => 'demo-'.Str::slug($en), 'slug_ar' => 'demo-'.($i + 1).'-'.Str::slug($en),
                'name_en' => '[Demo] '.$en, 'name_ar' => '[تجريبي] '.$ar, 'price' => $price, 'currency_code' => 'EGP', 'track_stock' => $lowStock, 'stock_quantity' => $lowStock ? 2 : 0, 'low_stock_threshold' => 5,
                'status' => 'draft', 'is_featured' => false, 'sort_order' => $i, 'created_at' => $now, 'updated_at' => $now]), 'en' => '[Demo] '.$en, 'ar' => '[تجريبي] '.$ar, 'price' => $price, 'sku' => 'DEMO-'.str_pad((string) ($i + 1), 3, '0', STR_PAD_LEFT), 'digital' => in_array($type, ['digital_download', 'template'])];
        }

        // orders + payments
        $statusPool = array_merge(array_fill(0, 34, 'paid'), array_fill(0, 8, 'processing'), array_fill(0, 8, 'in_progress'), array_fill(0, 10, 'completed'), array_fill(0, 6, 'awaiting_payment'), array_fill(0, 4, 'pending'), ['cancelled', 'cancelled', 'refunded', 'refunded']);
        $rate = ['EGP' => 1, 'SAR' => 0.08, 'AED' => 0.075, 'QAR' => 0.075, 'KWD' => 0.006];
        for ($n = 1; $n <= 76; $n++) {
            $cc = $this->pick($weights);
            $cur = mt_rand(1, 10) <= 7 ? 'EGP' : $countries[$cc];
            $created = $this->when();
            $status = $this->pick($statusPool);
            $paid = in_array($status, ['paid', 'processing', 'in_progress', 'completed', 'refunded'], true);
            $lines = [];
            $subtotal = 0;
            foreach ((array) array_rand($products, mt_rand(1, 3)) as $k) {
                $p = $products[$k];
                $unit = round($p->price * $rate[$cur], 2);
                $qty = mt_rand(1, 2);
                $lines[] = [$p, $unit, $qty, round($unit * $qty, 2)];
                $subtotal += $unit * $qty;
            }
            $tax = round($subtotal * 0.14, 2);
            $total = round($subtotal + $tax, 2);
            $person = $this->pick($people);
            $orderId = DB::table('orders')->insertGetId([
                'number' => 'DEMO-O-'.str_pad((string) $n, 4, '0', STR_PAD_LEFT), 'customer_name' => '[Demo] '.$person, 'customer_email' => 'buyer'.$n.'@'.self::DOMAIN, 'customer_phone' => '+9665'.mt_rand(10000000, 99999999),
                'country_code' => $cc, 'method' => mt_rand(1, 10) <= 7 ? 'online' : 'whatsapp', 'status' => $status, 'currency_code' => $cur, 'subtotal' => $subtotal, 'tax_rate' => 14, 'tax_total' => $tax, 'total' => $total,
                'assigned_to' => $admin, 'attribution' => json_encode($attr()), 'lookup_token' => Str::random(40), 'paid_at' => $paid ? $created->addHours(mt_rand(1, 20)) : null, 'created_at' => $created, 'updated_at' => $created,
            ]);
            foreach ($lines as [$p, $unit, $qty, $lt]) {
                DB::table('order_items')->insert(['order_id' => $orderId, 'product_id' => $p->id, 'sku' => $p->sku, 'name_ar' => $p->ar, 'name_en' => $p->en, 'unit_price' => $unit, 'quantity' => $qty, 'line_total' => $lt, 'is_digital' => $p->digital]);
            }
            if ($paid) {
                $refunded = $status === 'refunded';
                DB::table('payments')->insert(['order_id' => $orderId, 'provider' => 'paymob', 'provider_reference' => 'DEMO-PAY-'.$n, 'amount' => $total, 'currency_code' => $cur, 'status' => $refunded ? 'refunded' : 'succeeded',
                    'refunded_total' => $refunded ? $total : 0, 'verified_at' => $created->addHours(2), 'note' => 'Demo data', 'created_at' => $created->addHours(2), 'updated_at' => $created->addHours(2)]);
            } elseif ($status === 'awaiting_payment' && mt_rand(1, 10) <= 5) {
                DB::table('payments')->insert(['order_id' => $orderId, 'provider' => 'paymob', 'provider_reference' => 'DEMO-PAY-'.$n, 'amount' => $total, 'currency_code' => $cur, 'status' => 'failed', 'note' => 'Demo data', 'created_at' => $created, 'updated_at' => $created]);
            }
        }

        // leads
        $leadStatus = array_merge(array_fill(0, 14, 'new'), array_fill(0, 12, 'contacted'), array_fill(0, 10, 'qualified'), array_fill(0, 7, 'proposal_sent'), array_fill(0, 5, 'negotiation'), array_fill(0, 7, 'won'), array_fill(0, 5, 'lost'));
        $leadIds = [];
        for ($n = 1; $n <= 64; $n++) {
            $created = $this->when();
            $status = $this->pick($leadStatus);
            $leadIds[] = DB::table('leads')->insertGetId([
                'name' => '[Demo] '.$this->pick($people).' '.$n, 'company' => $this->pick($companies), 'email' => 'lead'.$n.'@'.self::DOMAIN, 'phone' => '+2011'.mt_rand(10000000, 99999999), 'country_code' => $this->pick($weights),
                'industry' => $this->pick($industries), 'source' => $this->pick($sources), 'service_id' => $this->pick($services), 'budget' => $this->pick(['5,000–10,000', '10,000–25,000', '25,000+']), 'timeline' => $this->pick(['1 month', '2–3 months']),
                'description' => 'Demo lead for dashboard review.', 'priority' => $this->pick(['low', 'normal', 'normal', 'high', 'urgent']), 'status' => $status, 'lost_reason' => $status === 'lost' ? 'Budget' : null, 'assigned_to' => $admin,
                'last_followup_at' => $created->addDay(), 'next_followup_at' => in_array($status, ['new', 'contacted', 'qualified'], true) ? $now->copy()->addDays(mt_rand(-2, 6)) : null, 'attribution' => json_encode($attr()),
                'created_by' => $admin, 'created_at' => $created, 'updated_at' => $created,
            ]);
        }

        // quotations
        $qStatus = array_merge(array_fill(0, 5, 'approved'), array_fill(0, 3, 'sent'), ['viewed', 'viewed', 'draft', 'rejected', 'expired', 'draft']);
        foreach ($qStatus as $i => $status) {
            $created = $this->when(60);
            $cur = $this->pick(['EGP', 'EGP', 'SAR', 'AED']);
            $items = [];
            $sub = 0;
            foreach ((array) array_rand($services, mt_rand(1, 3)) as $k) {
                $s = $svcRows[$services[$k]];
                $price = round(mt_rand(40, 250) * 100 * ($rate[$cur]), 2);
                $items[] = [$s, $price];
                $sub += $price;
            }
            $tax = round($sub * 0.14, 2);
            $decided = in_array($status, ['approved', 'rejected'], true);
            $qid = DB::table('quotations')->insertGetId([
                'number' => 'DEMO-Q-'.str_pad((string) ($i + 1), 4, '0', STR_PAD_LEFT), 'customer_id' => $this->pick($customerIds), 'customer_name' => '[Demo] Client '.($i + 1), 'customer_email' => 'quote'.($i + 1).'@'.self::DOMAIN,
                'title' => '[Demo] Campaign proposal '.($i + 1), 'currency_code' => $cur, 'status' => $status, 'valid_until' => $created->addDays(30)->toDateString(), 'subtotal' => $sub, 'tax_rate' => 14, 'tax_total' => $tax, 'total' => $sub + $tax,
                'assigned_to' => $admin, 'public_token' => Str::random(40), 'sent_at' => $status === 'draft' ? null : $created, 'decided_at' => $decided ? $created->addDays(3) : null, 'decided_by' => $decided ? 'customer' : null,
                'created_by' => $admin, 'created_at' => $created, 'updated_at' => $created,
            ]);
            foreach ($items as $j => [$s, $price]) {
                DB::table('quotation_items')->insert(['quotation_id' => $qid, 'service_id' => $s->id, 'name_ar' => $s->name_ar, 'name_en' => $s->name_en, 'quantity' => 1, 'unit_price' => $price, 'line_total' => $price, 'sort_order' => $j]);
            }
        }

        // projects, milestones, tasks
        $pStatus = ['planning', 'in_progress', 'in_progress', 'in_progress', 'under_review', 'on_hold', 'completed', 'completed'];
        foreach ($pStatus as $i => $status) {
            $done = $status === 'completed';
            $start = CarbonImmutable::now()->subDays(mt_rand($done ? 30 : 3, $done ? 55 : 28));
            $due = $done ? CarbonImmutable::now()->subDays(mt_rand(1, 25)) : $start->addDays(mt_rand(20, 60));
            $pid = DB::table('projects')->insertGetId([
                'name' => '[Demo] '.$this->pick($companies).' — '.$this->pick(['Brand refresh', 'Ramadan campaign', 'Product shoot', 'Reels series', 'Ads funnel']), 'customer_id' => $this->pick($customerIds), 'type' => $this->pick(['branding', 'production', 'performance']),
                'status' => $status, 'priority' => $this->pick(['normal', 'high', 'urgent', 'low']), 'start_date' => $start->toDateString(), 'due_date' => $due->toDateString(), 'description' => 'Demo project.',
                'progress' => $done ? 100 : ($status === 'planning' ? 0 : mt_rand(25, 85)), 'manager_id' => $admin, 'completed_at' => $done ? $due : null, 'created_by' => $admin, 'created_at' => $start, 'updated_at' => $start,
            ]);
            foreach (['Kick-off', 'First draft', 'Client review', 'Delivery'] as $m => $title) {
                DB::table('milestones')->insert(['project_id' => $pid, 'title' => $title, 'due_date' => $start->addDays(7 + $m * 10)->toDateString(), 'status' => ($done || $m < 1 && $status !== 'planning') ? 'done' : 'pending', 'sort_order' => $m, 'created_at' => $start, 'updated_at' => $start]);
            }
            foreach (range(1, 3) as $t) {
                DB::table('tasks')->insert(['title' => '[Demo] Task '.$t.' for project '.($i + 1), 'status' => ($done || $t === 1 && $status !== 'planning') ? 'done' : 'open', 'priority' => 'normal', 'due_at' => $now->copy()->addDays(mt_rand(-3, 12)), 'assigned_to' => $admin,
                    'taskable_type' => 'project', 'taskable_id' => $pid, 'created_by' => $admin, 'created_at' => $start, 'updated_at' => $start]);
            }
        }
        foreach (array_slice($leadIds, 0, 5) as $k => $lid) {
            DB::table('tasks')->insert(['title' => '[Demo] Follow up lead call', 'status' => 'open', 'priority' => 'high', 'due_at' => $now->copy()->addDays($k), 'assigned_to' => $admin, 'taskable_type' => 'lead', 'taskable_id' => $lid, 'created_by' => $admin, 'created_at' => $now, 'updated_at' => $now]);
        }

        // contact messages + in-app notifications
        foreach (range(1, 8) as $n) {
            $c = $this->when(20);
            DB::table('contact_messages')->insert(['name' => '[Demo] '.$this->pick($people), 'email' => 'msg'.$n.'@'.self::DOMAIN, 'subject' => $this->pick(['Pricing question', 'Partnership', 'Project inquiry', 'Support']), 'message' => 'Demo message for dashboard review.', 'status' => $n <= 5 ? 'new' : 'read', 'created_at' => $c, 'updated_at' => $c]);
        }
        if ($admin) {
            foreach ([['lead.created', 'عميل محتمل جديد', 'New lead'], ['order.created', 'طلب جديد', 'New order'], ['payment.failed', 'فشل دفعة', 'Payment failed'], ['quote.approved', 'تمت الموافقة على عرض سعر', 'Quotation approved']] as $k => [$ev, $ar, $en]) {
                DB::table('notifications')->insert(['id' => (string) Str::uuid(), 'type' => 'App\\Notifications\\PlatformEvent', 'notifiable_type' => User::class, 'notifiable_id' => $admin,
                    'data' => json_encode(['demo' => true, 'event' => $ev, 'data' => [], 'subject_ar' => $ar, 'subject_en' => $en, 'body_ar' => 'بيانات تجريبية', 'body_en' => 'Demo data'], JSON_UNESCAPED_UNICODE), 'read_at' => $k > 2 ? $now : null, 'created_at' => $now->copy()->subMinutes($k * 40), 'updated_at' => $now]);
            }
        }
    }
}
