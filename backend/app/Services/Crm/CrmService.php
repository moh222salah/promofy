<?php

namespace App\Services\Crm;

use App\Models\CrmActivity;
use App\Models\Customer;
use App\Models\Lead;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CrmService
{
    public function log(string $subjectType, int $subjectId, string $type, ?string $body = null, array $meta = [], ?int $userId = null): CrmActivity
    {
        return CrmActivity::create(['subject_type' => $subjectType, 'subject_id' => $subjectId, 'type' => $type, 'body' => $body, 'meta' => $meta ?: null, 'user_id' => $userId ?? auth()->id()]);
    }

    /** Maps a UTM source / referrer hint onto one of the CRM lead sources. */
    public static function sourceFrom(array $attribution): string
    {
        $hint = strtolower(($attribution['utm_source'] ?? '').' '.($attribution['referrer'] ?? ''));
        foreach (['instagram' => ['instagram', 'ig'], 'facebook' => ['facebook', 'fb', 'meta'], 'google' => ['google'], 'whatsapp' => ['whatsapp', 'wa.me']] as $source => $needles) {
            foreach ($needles as $needle) {
                if (preg_match('/(^|[^a-z])'.preg_quote($needle, '/').'([^a-z]|$)/', $hint)) {
                    return $source;
                }
            }
        }

        return 'website';
    }

    public function changeStatus(Lead $lead, string $to, ?string $lostReason, ?int $userId): Lead
    {
        if ($to === 'lost' && trim((string) $lostReason) === '') {
            throw ValidationException::withMessages(['lost_reason' => ['A reason is required when marking a lead as lost.']]);
        }

        return DB::transaction(function () use ($lead, $to, $lostReason, $userId) {
            $from = $lead->status;
            $lead->forceFill(['status' => $to, 'lost_reason' => $to === 'lost' ? $lostReason : null])->save();
            $this->log('lead', $lead->id, 'status', null, ['from' => $from, 'to' => $to, 'lost_reason' => $lostReason], $userId);

            return $lead;
        });
    }

    /** Creates (or reuses by e-mail) a customer from a lead and links them. */
    public function convertToCustomer(Lead $lead, ?int $userId): Customer
    {
        return DB::transaction(function () use ($lead, $userId) {
            $customer = $lead->email ? Customer::where('email', $lead->email)->first() : null;
            $created = false;
            if (! $customer) {
                $customer = new Customer($lead->only(['name', 'company', 'email', 'phone', 'country_code', 'industry', 'source']));
                $customer->forceFill(['created_by' => $userId, 'attribution' => $lead->attribution])->save();
                $created = true;
            }
            $lead->forceFill(['customer_id' => $customer->id])->save();
            $this->log('lead', $lead->id, 'system', $created ? 'Converted to a new customer' : 'Linked to an existing customer', ['customer_id' => $customer->id], $userId);
            $this->log('customer', $customer->id, 'system', "Created from lead #{$lead->id}", ['lead_id' => $lead->id], $userId);

            return $customer;
        });
    }
}
