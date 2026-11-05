<?php

namespace App\Http\Controllers\Api\Public;

use App\Http\Controllers\Controller;
use App\Models\Lead;
use App\Models\LeadAttachment;
use App\Services\Crm\CrmService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/** "Start a Project" brief: validated, spam-protected, files stored privately, lands in the CRM as a lead. */
class ProjectRequestController extends Controller
{
    public function store(Request $request, CrmService $crm): JsonResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:190'],
            'company' => ['nullable', 'string', 'max:190'],
            'email' => ['required', 'email:rfc', 'max:190'],
            'phone' => ['required', 'string', 'max:32', 'regex:/^\+?[0-9 ()\-]{6,32}$/'],
            'country' => ['required', 'string', 'size:2', Rule::exists('countries', 'code')->where('is_active', true)],
            'industry' => ['nullable', 'string', 'max:120'],
            'service_id' => ['nullable', 'integer', Rule::exists('services', 'id')->where('status', 'published')],
            'description' => ['required', 'string', 'min:10', 'max:5000'],
            'budget' => ['nullable', 'string', 'max:64'],
            'timeline' => ['nullable', 'string', 'max:64'],
            'contact_method' => ['nullable', Rule::in(['email', 'phone', 'whatsapp'])],
            'consent' => ['accepted'],
            'attribution' => ['nullable', 'array'], 'attribution.*' => ['nullable', 'string', 'max:255'],
            'attachments' => ['nullable', 'array', 'max:5'],
            'attachments.*' => ['file', 'max:10240'],
            'website' => ['nullable', 'string'], // honeypot
        ]);
        if (! empty($data['website'])) {
            return response()->json(['data' => ['received' => true]], 201);
        }

        $allowed = array_merge(config('promofy.uploads.document_mimes'), config('promofy.uploads.image_mimes'));
        foreach ($request->file('attachments', []) as $i => $file) {
            if (! isset($allowed[$file->getMimeType()])) {
                throw \Illuminate\Validation\ValidationException::withMessages(["attachments.{$i}" => ['This file type is not allowed.']]);
            }
        }

        $attribution = array_slice($data['attribution'] ?? [], 0, 12);
        $lead = DB::transaction(function () use ($data, $attribution, $request, $crm, $allowed) {
            $lead = new Lead(Arr::only($data, ['name', 'company', 'email', 'phone', 'industry', 'service_id', 'budget', 'timeline', 'contact_method', 'description']));
            $lead->forceFill(['country_code' => strtoupper($data['country']), 'source' => CrmService::sourceFrom($attribution), 'status' => 'new', 'attribution' => $attribution])->save();
            foreach ($request->file('attachments', []) as $file) {
                $mime = $file->getMimeType();
                $path = $file->storeAs("lead-files/{$lead->id}", Str::uuid().'.'.$allowed[$mime], 'local');
                LeadAttachment::create(['lead_id' => $lead->id, 'disk' => 'local', 'path' => $path, 'original_name' => mb_substr($file->getClientOriginalName(), 0, 190), 'mime' => $mime, 'size' => $file->getSize()]);
            }
            $crm->log('lead', $lead->id, 'system', 'Project brief submitted from the website', ['source' => $lead->source], null);

            return $lead;
        });

        \App\Services\Notifications\NotificationService::send('lead.created', ['name' => $lead->name, 'source' => $lead->source]);
        \App\Services\Notifications\NotificationService::send('quotation.requested', ['name' => $lead->name]);

        return response()->json(['data' => ['received' => true, 'reference' => 'LEAD-'.str_pad((string) $lead->id, 6, '0', STR_PAD_LEFT)]], 201);
    }
}
