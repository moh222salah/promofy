<?php

namespace App\Services\Notifications;

/** Platform events that notify staff. "permission" decides who receives them by default. */
final class EventCatalog
{
    /** @return array<string, array{permission: string, subject_ar: string, subject_en: string, body_ar: string, body_en: string}> */
    public static function all(): array
    {
        return [
            'lead.created' => ['permission' => 'leads.view', 'subject_ar' => 'عميل محتمل جديد', 'subject_en' => 'New lead',
                'body_ar' => 'وصل عميل محتمل جديد: :name (:source).', 'body_en' => 'A new lead arrived: :name (:source).'],
            'order.placed' => ['permission' => 'orders.view', 'subject_ar' => 'طلب جديد', 'subject_en' => 'New order',
                'body_ar' => 'طلب جديد :number بقيمة :total :currency.', 'body_en' => 'New order :number for :total :currency.'],
            'payment.confirmed' => ['permission' => 'payments.view', 'subject_ar' => 'تأكيد دفع', 'subject_en' => 'Payment confirmed',
                'body_ar' => 'تم تأكيد دفع الطلب :number.', 'body_en' => 'Payment confirmed for order :number.'],
            'payment.failed' => ['permission' => 'payments.view', 'subject_ar' => 'فشل دفع', 'subject_en' => 'Payment failed',
                'body_ar' => 'فشلت عملية دفع الطلب :number.', 'body_en' => 'A payment failed for order :number.'],
            'quotation.requested' => ['permission' => 'quotations.view', 'subject_ar' => 'طلب عرض سعر جديد', 'subject_en' => 'New quote request',
                'body_ar' => 'طلب مشروع جديد من :name.', 'body_en' => 'A new project request from :name.'],
            'quotation.approved' => ['permission' => 'quotations.view', 'subject_ar' => 'تمت الموافقة على عرض سعر', 'subject_en' => 'Quotation approved',
                'body_ar' => 'تمت الموافقة على عرض السعر :number.', 'body_en' => 'Quotation :number was approved.'],
            'quotation.rejected' => ['permission' => 'quotations.view', 'subject_ar' => 'تم رفض عرض سعر', 'subject_en' => 'Quotation rejected',
                'body_ar' => 'تم رفض عرض السعر :number.', 'body_en' => 'Quotation :number was rejected.'],
            'project.assigned' => ['permission' => 'projects.view_assigned', 'subject_ar' => 'تم إسناد مشروع إليك', 'subject_en' => 'Project assigned',
                'body_ar' => 'تم إسناد المشروع :name إليك.', 'body_en' => 'You were assigned to project :name.'],
            'stock.low' => ['permission' => 'products.view', 'subject_ar' => 'مخزون منخفض', 'subject_en' => 'Low stock',
                'body_ar' => 'المنتج :name وصل إلى :quantity وحدة.', 'body_en' => 'Product :name is down to :quantity units.'],
            'message.received' => ['permission' => 'messages.view', 'subject_ar' => 'رسالة تواصل جديدة', 'subject_en' => 'New contact message',
                'body_ar' => 'رسالة جديدة من :name.', 'body_en' => 'New message from :name.'],
        ];
    }

    public static function exists(string $event): bool
    {
        return isset(self::all()[$event]);
    }
}
