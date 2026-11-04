<?php

namespace App\Http\Controllers\Api\Admin;

use App\Models\CrmActivity;
use App\Models\Lead;
use App\Models\LeadAttachment;
use App\Services\Crm\CrmService;
use App\Support\Crud\CrudController;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class LeadController extends CrudController
{
    protected string $model = Lead::class;

    protected string $module = 'leads';

    protected array $searchable = ['name', 'company', 'email', 'phone', 'description'];

    protected array $filterable = ['status', 'source', 'priority', 'assigned_to', 'country_code', 'service_id'];

    protected array $sortable = ['id', 'name', 'status', 'priority', 'created_at', 'last_followup_at', 'next_followup_at'];

    protected array $with = ['assignee:id,name', 'service:id,name_ar,name_en', 'customer:id,name'];

    protected ?string $assignedColumn = 'assigned_to';

    protected function rules(?Model $record): array
    {
        return [
            'name' => ['required', 'string', 'max:190'],
            'company' => ['nullable', 'string', 'max:190'],
            'email' => ['nullable', 'email:rfc', 'max:190', 'required_without:phone'],
            'phone' => ['nullable', 'string', 'max:32', 'regex:/^\+?[0-9 ()\-]{6,32}$/', 'required_without:email'],
            'country_code' => ['nullable', 'string', 'size:2', 'exists:countries,code'],
            'industry' => ['nullable', 'string', 'max:120'],
            'source' => ['sometimes', Rule::in(Lead::SOURCES)],
            'service_id' => ['nullable', 'integer', 'exists:services,id'],
            'budget' => ['nullable', 'string', 'max:64'],
            'timeline' => ['nullable', 'string', 'max:64'],
            'contact_method' => ['nullable', Rule::in(['email', 'phone', 'whatsapp'])],
            'description' => ['nullable', 'string', 'max:5000'],
            'priority' => ['sometimes', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'assigned_to' => ['nullable', 'integer', Rule::exists('users', 'id')->where('type', 'staff')],
        ];
    }

    protected function validated(Request $request, ?Model $record): array
    {
        $data = parent::validated($request, $record);
        if (array_key_exists('assigned_to', $data) && ($record === null || (int) $data['assigned_to'] !== (int) $record->assigned_to)) {
            if (! Gate::allows('leads.assign')) {
                if ($record !== null || $data['assigned_to'] !== $request->user()->id) {
                    abort(403, 'You are not allowed to assign leads.');
                }
            }
        }

        return $data;
    }

    protected function persistNew(array $data, Request $request): Model
    {
        // agents without the assign right own what they create
        if (! array_key_exists('assigned_to', $data) && ! Gate::allows('leads.assign')) {
            $data['assigned_to'] = $request->user()->id;
        }
        $lead = parent::persistNew($data, $request);
        $lead->forceFill(['created_by' => $request->user()->id, 'source' => $data['source'] ?? 'direct'])->save();
        app(CrmService::class)->log('lead', $lead->id, 'system', 'Lead created manually');

        return $lead;
    }

    protected function persistUpdate(Model $record, array $data, Request $request): void
    {
        $oldAssignee = $record->assigned_to;
        parent::persistUpdate($record, $data, $request);
        if ($record->assigned_to !== $oldAssignee) {
            app(CrmService::class)->log('lead', $record->id, 'assignment', null, ['from' => $oldAssignee, 'to' => $record->assigned_to]);
        }
    }

    protected function exportColumns(): array
    {
        return ['ID' => 'id', 'Name' => 'name', 'Company' => 'company', 'Email' => 'email', 'Phone' => 'phone', 'Country' => 'country_code', 'Source' => 'source',
            'Service' => 'service.name_en', 'Budget' => 'budget', 'Priority' => 'priority', 'Status' => 'status', 'Assigned to' => 'assignee.name', 'Created' => 'created_at'];
    }

    // ---------------------------------------------------------------- pipeline
    public function status(Request $request, CrmService $crm, string $id): JsonResponse
    {
        $lead = $this->findRecord($id, $this->scopeFor('edit'));
        $data = $request->validate(['status' => ['required', Rule::in(Lead::STATUSES)], 'lost_reason' => ['nullable', 'string', 'max:255']]);

        return $this->respond($this->reload($crm->changeStatus($lead, $data['status'], $data['lost_reason'] ?? null, $request->user()->id)));
    }

    /** Kanban data: per pipeline column the total and the most recent cards. */
    public function board(Request $request): JsonResponse
    {
        $scope = $this->scopeFor('view');
        $columns = [];
        foreach (Lead::STATUSES as $status) {
            $q = $this->baseQuery($scope)->where('status', $status);
            $this->applySearch($q, (string) $request->query('search', ''));
            $this->applyFilters($q, (array) $request->query('filter', []));
            $columns[] = [
                'status' => $status, 'total' => (clone $q)->count(),
                'items' => $q->with(['assignee:id,name', 'service:id,name_ar,name_en'])->orderByDesc('id')->limit(50)
                    ->get(['id', 'name', 'company', 'priority', 'source', 'assigned_to', 'service_id', 'budget', 'next_followup_at', 'created_at']),
            ];
        }

        return response()->json(['data' => $columns]);
    }

    public function followUp(Request $request, CrmService $crm, string $id): JsonResponse
    {
        $lead = $this->findRecord($id, $this->scopeFor('edit'));
        $data = $request->validate(['next_followup_at' => ['required', 'date', 'after:now'], 'note' => ['nullable', 'string', 'max:1000'], 'create_task' => ['sometimes', 'boolean']]);
        DB::transaction(function () use ($lead, $data, $crm, $request) {
            $lead->forceFill(['next_followup_at' => $data['next_followup_at']])->save();
            $crm->log('lead', $lead->id, 'followup', $data['note'] ?? null, ['at' => $data['next_followup_at']], $request->user()->id);
            if ($data['create_task'] ?? true) {
                (new \App\Models\Task(['title' => 'Follow up: '.$lead->name, 'description' => $data['note'] ?? null, 'due_at' => $data['next_followup_at'], 'assigned_to' => $lead->assigned_to ?? $request->user()->id,
                    'taskable_type' => 'lead', 'taskable_id' => $lead->id]))->forceFill(['created_by' => $request->user()->id])->save();
            }
        });

        return $this->respond($this->reload($lead));
    }

    // ---------------------------------------------------------------- timeline
    public function activities(string $id): JsonResponse
    {
        $lead = $this->findRecord($id, $this->scopeFor('view'), true);

        return response()->json(['data' => CrmActivity::with('user:id,name')->where('subject_type', 'lead')->where('subject_id', $lead->id)->orderByDesc('id')->limit(200)->get()]);
    }

    /** Logs a contact (note, call, email, WhatsApp, meeting) and stamps the last follow-up date. */
    public function addActivity(Request $request, CrmService $crm, string $id): JsonResponse
    {
        $lead = $this->findRecord($id, $this->scopeFor('edit'));
        $data = $request->validate(['type' => ['required', Rule::in(['note', 'call', 'email', 'whatsapp', 'meeting'])], 'body' => ['required', 'string', 'max:3000']]);
        $activity = DB::transaction(function () use ($lead, $data, $crm, $request) {
            if ($data['type'] !== 'note') {
                $lead->forceFill(['last_followup_at' => now()])->save();
            }

            return $crm->log('lead', $lead->id, $data['type'], $data['body'], [], $request->user()->id);
        });

        return response()->json(['data' => $activity->load('user:id,name')], 201);
    }

    public function convert(Request $request, CrmService $crm, string $id): JsonResponse
    {
        $lead = $this->findRecord($id, $this->scopeFor('edit'));
        Gate::authorize('customers.create');
        $customer = $crm->convertToCustomer($lead, $request->user()->id);

        return response()->json(['data' => $customer], 201);
    }

    // ---------------------------------------------------------------- attachments (private)
    public function attachment(string $id, string $file)
    {
        $lead = $this->findRecord($id, $this->scopeFor('view'), true);
        $att = $lead->attachments()->findOrFail($file);

        return Storage::disk($att->disk)->download($att->path, $att->original_name);
    }

    public function attachments(string $id): JsonResponse
    {
        $lead = $this->findRecord($id, $this->scopeFor('view'), true);

        return response()->json(['data' => $lead->attachments]);
    }

    // ---------------------------------------------------------------- analytics
    public function analytics(Request $request): JsonResponse
    {
        $scope = $this->scopeFor('view');
        $base = fn () => $this->baseQuery($scope)->when($request->query('from'), fn ($q, $v) => $q->where('created_at', '>=', \Carbon\Carbon::parse($v)->startOfDay()))
            ->when($request->query('to'), fn ($q, $v) => $q->where('created_at', '<=', \Carbon\Carbon::parse($v)->endOfDay()));
        $total = $base()->count();
        $won = $base()->where('status', 'won')->count();
        $bySource = $base()->select('source', DB::raw('count(*) as total'), DB::raw("sum(case when status = 'won' then 1 else 0 end) as won"))->groupBy('source')->orderByDesc('total')->get();

        return response()->json(['data' => [
            'total' => $total, 'won' => $won, 'lost' => $base()->where('status', 'lost')->count(),
            'conversion_rate' => $total > 0 ? round($won / $total * 100, 1) : 0.0,
            'by_status' => $base()->select('status', DB::raw('count(*) as total'))->groupBy('status')->pluck('total', 'status'),
            'by_source' => $bySource,
        ]]);
    }
}
