<?php

namespace App\Support;

use App\Models\AuditLog;
use App\Models\Country;
use App\Models\Currency;
use App\Models\ExchangeRate;
use App\Models\LoginHistory;
use App\Models\Media;
use App\Models\Permission;
use App\Models\ServiceCategory;
use App\Models\Service;
use App\Models\PortfolioItem;
use App\Models\CaseStudy;
use App\Models\ArticleCategory;
use App\Models\Article;
use App\Models\Page;
use App\Models\Testimonial;
use App\Models\TeamMember;
use App\Models\ContactMessage;
use App\Models\ProductCategory;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Customer;
use App\Models\Lead;
use App\Models\Task;
use App\Models\Quotation;
use App\Models\Project;
use App\Models\Role;
use App\Models\User;

/**
 * Stable, readable type names used in polymorphic columns (audit log subjects, media usage…).
 * Every model that appears in a polymorphic relation must be registered here.
 */
final class MorphMap
{
    /** @var array<string, class-string> */
    public const MAP = [
        'user' => User::class,
        'role' => Role::class,
        'permission' => Permission::class,
        'audit_log' => AuditLog::class,
        'login_history' => LoginHistory::class,
        'media' => Media::class,
        'country' => Country::class,
        'currency' => Currency::class,
        'exchange_rate' => ExchangeRate::class,
        'service_category' => ServiceCategory::class,
        'service' => Service::class,
        'portfolio_item' => PortfolioItem::class,
        'case_study' => CaseStudy::class,
        'article_category' => ArticleCategory::class,
        'article' => Article::class,
        'page' => Page::class,
        'testimonial' => Testimonial::class,
        'team_member' => TeamMember::class,
        'contact_message' => ContactMessage::class,
        'product_category' => ProductCategory::class,
        'product' => Product::class,
        'product_variant' => ProductVariant::class,
        'order' => Order::class,
        'payment' => Payment::class,
        'customer' => Customer::class,
        'lead' => Lead::class,
        'task' => Task::class,
        'quotation' => Quotation::class,
        'project' => Project::class,
    ];

    /** @return array<string, class-string> */
    public static function all(): array
    {
        return self::MAP;
    }
}
