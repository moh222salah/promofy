<?php

namespace App\Http\Controllers\Api\Admin;

use App\Models\CrmActivity;
use App\Models\Lead;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Quotation;
use App\Models\Task;
use App\Services\Crm\CrmService;
use App\Services\Crm\ProjectService;
use App\Support\Crud\CrudController;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProjectController extends CrudController
{
    protected string $model = Project::class;

    protected string $module = 'projects';

    protected array $searchable = ['name', 'type', 'description'];

    protected array $filterable = ['status', 'priority', 'customer_id', 'manager_id', 'type'];

    protected array $sortable = ['id', 'name', 'status', 'priority', 'start_date', 'due_date', 'progress', 'created_at'];

    protected array $with = ['customer:id,name,company', 'manager:id,name', 'members:id,name', 'milestones', 'media'];

    protected ?string $assignedColumn = 'manager_id';

    protected string $dateColumn = 'due_date';

    /** "assigned" scope = projects I manage or am a team member of. */
    protected function baseQuery(string $scope): Builder
    {
        $query = ($this->model)::query();
        if ($scope === 'assigned') {
            $me = auth()->id();
            $query->where(fn ($q) => $q->where('projects.manager_id', $me)->orWhereExists(fn ($s) => $s->selectRaw('1')->from('project_members')
                ->whereColumn('project_members.project_id', 'projects.id')->where('project_members.user_id', $me)));
        }

        return $query;
    }

    protected function rules(?Model $record): array
    {
        return [
            'name' => ['required', 'string', 'max:190'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'order_id' => ['nullable', 'integer', 'exists:orders,id'],
            'quotation_id' => ['nullable', 'integer', 'exists:quotations,id'],
            'type' => ['nullable', 'string', 'max:32'],
            'priority' => ['sometimes', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'start_date' => ['nullable', 'date'],
            'due_date' => ['nullable', 'date', 'after_or_equal:start_date'],
            'description' => ['nullable', 'string', 'max:5000'],
            'scope' => ['nullable', 'string', 'max:50000'],
            'progress' => ['sometimes', 'integer', 'between:0,100'],
            'manager_id' => ['nullable', 'integer', Rule::exists('users', 'id')->where('type', 'staff')],
            'members' => ['sometimes', 'array', 'max:50'],
            'members.*.user_id' => ['required', 'integer', 'distinct', Rule::exists('users', 'id')->where('type', 'staff')],
            'members.*.role' => ['nullable', 'string', 'max:48'],
            'milestones' => ['sometimes', 'array', 'max:50'],
            'milestones.*.id' => ['sometimes', 'integer'],
            'milestones.*.title' => ['required', 'string', 'max:190'],
            'milestones.*.description' => ['nullable', 'string', 'max:1000'],
            'milestones.*.due_date' => ['nullable', 'date'],
            'milestones.*.status' => ['sometimes', Rule::in(['pending', 'done'])],
            'media' => ['sometimes', 'array'], 'media.*' => ['array', 'max:50'], 'media.*.*' => ['integer', 'exists:media,id'],
        ];
    }

    protected function validated(Request $request, ?Model $record): array
    {
        $data = parent::validated($request, $record);
        foreach (array_keys($data['media'] ?? []) as $collection) {
            if ($collection !== 'files') {
                throw ValidationException::withMessages(['media' => ['Unknown media collection.']]);
            }
        }
        if (array_key_exists('manager_id', $data) && ! Gate::allows('projects.edit') && (int) $data['manager_id'] !== (int) $request->user()->id) {
            abort(403, 'You cannot reassign this project.');
        }
        // manual progress only while the project has no tasks (otherwise it follows task completion)
        if ($record && array_key_exists('progress', $data) && Task::where('taskable_type', 'project')->where('taskable_id', $record->id)->exists()) {
            unset($data['progress']);
        }

        return $data;
    }

    protected function persistNew(array $data, Request $request): Model
    {
        $data['manager_id'] ??= $request->user()->id;
        $project = parent::persistNew($data, $request);
        $project->forceFill(['created_by' => $request->user()->id, 'progress' => $data['progress'] ?? 0])->save();

        return $project;
    }

    protected function afterSave(Model $record, array $data, Request $request, bool $created): void
    {
        if (array_key_exists('members', $data)) {
            $record->members()->sync(collect($data['members'])->mapWithKeys(fn ($m) => [$m['user_id'] => ['role' => $m['role'] ?? null]])->all());
        }
        if (array_key_exists('milestones', $data)) {
            $keep = [];
            foreach (array_values($data['milestones']) as $i => $row) {
                $m = isset($row['id']) ? $record->milestones()->find($row['id']) : null;
                $m ??= new Milestone(['project_id' => $record->id]);
                $status = $row['status'] ?? $m->status ?? 'pending';
                $m->forceFill(['title' => $row['title'], 'description' => $row['description'] ?? null, 'due_date' => $row['due_date'] ?? null, 'status' => $status,
                    'completed_at' => $status === 'done' ? ($m->completed_at ?? now()) : null, 'sort_order' => $i, 'project_id' => $record->id])->save();
                $keep[] = $m->id;
            }
            $record->milestones()->whereNotIn('id', $keep)->delete();
        }
        if (isset($data['media']['files'])) {
            $record->syncMedia('files', array_map('intval', $data['media']['files']));
        }
    }

    protected function respond(Model $record, int $status = 200): JsonResponse
    {
        $record->loadMissing($this->with);
        $data = $record->toArray();
        if ($record->relationLoaded('media')) {
            $data['media'] = (object) ['files' => $record->media->map(fn ($m) => Arr::except($m->toArray(), ['pivot']))->values()];
        }

        return response()->json(['data' => $data], $status);
    }

    protected function exportColumns(): array
    {
        return ['ID' => 'id', 'Name' => 'name', 'Customer' => 'customer.name', 'Type' => 'type', 'Status' => 'status', 'Priority' => 'priority',
            'Start' => 'start_date', 'Due' => 'due_date', 'Progress %' => 'progress', 'Manager' => 'manager.name'];
    }

    // ---------------------------------------------------------------- workflow & views
    public function status(Request $request, ProjectService $projects, string $id): JsonResponse
    {
        $project = $this->findRecord($id, $this->scopeFor('edit'));
        $data = $request->validate(['status' => ['required', Rule::in(Project::STATUSES)]]);

        return $this->respond($projects->changeStatus($project, $data['status'], $request->user()->id));
    }

    /** Kanban columns by status. */
    public function board(Request $request): JsonResponse
    {
        $scope = $this->scopeFor('view');
        $cols = [];
        foreach (Project::STATUSES as $status) {
            $q = $this->baseQuery($scope)->where('status', $status);
            $this->applySearch($q, (string) $request->query('search', ''));
            $this->applyFilters($q, (array) $request->query('filter', []));
            $cols[] = ['status' => $status, 'total' => (clone $q)->count(),
                'items' => $q->with(['customer:id,name', 'manager:id,name'])->orderByRaw('due_date is null, due_date')->limit(50)->get(['id', 'name', 'customer_id', 'manager_id', 'priority', 'due_date', 'progress'])];
        }

        return response()->json(['data' => $cols]);
    }

    /** Calendar / timeline feed: project spans, milestones and task deadlines inside a range. */
    public function calendar(Request $request): JsonResponse
    {
        $scope = $this->scopeFor('view');
        $r = $request->validate(['from' => ['required', 'date'], 'to' => ['required', 'date', 'after_or_equal:from']]);
        $from = \Carbon\Carbon::parse($r['from'])->startOfDay();
        $to = \Carbon\Carbon::parse($r['to'])->endOfDay();

        $projects = $this->baseQuery($scope)->whereNotIn('status', ['cancelled'])
            ->where(fn ($q) => $q->whereBetween('due_date', [$from, $to])->orWhereBetween('start_date', [$from, $to])
                ->orWhere(fn ($w) => $w->where('start_date', '<', $from)->where('due_date', '>', $to)))
            ->get(['id', 'name', 'status', 'priority', 'start_date', 'due_date', 'progress']);
        $ids = $this->baseQuery($scope)->pluck('projects.id');
        $milestones = Milestone::whereIn('project_id', $ids)->whereBetween('due_date', [$from, $to])->get(['id', 'project_id', 'title', 'due_date', 'status']);
        $tasks = Task::where('taskable_type', 'project')->whereIn('taskable_id', $ids)->whereBetween('due_at', [$from, $to])->get(['id', 'taskable_id as project_id', 'title', 'due_at', 'status']);

        return response()->json(['data' => compact('projects', 'milestones', 'tasks')]);
    }

    public function timeline(string $id): JsonResponse
    {
        $project = $this->findRecord($id, $this->scopeFor('view'), true);

        return response()->json(['data' => CrmActivity::with('user:id,name')->where('subject_type', 'project')->where('subject_id', $project->id)->orderByDesc('id')->limit(200)->get()]);
    }

    /** Internal notes (never visible to customers). */
    public function addNote(Request $request, CrmService $crm, string $id): JsonResponse
    {
        $project = $this->findRecord($id, $this->scopeFor('edit'));
        $data = $request->validate(['body' => ['required', 'string', 'max:3000']]);

        return response()->json(['data' => $crm->log('project', $project->id, 'note', $data['body'], [], $request->user()->id)->load('user:id,name')], 201);
    }

    public function tasks(string $id): JsonResponse
    {
        $project = $this->findRecord($id, $this->scopeFor('view'), true);

        return response()->json(['data' => Task::with('assignee:id,name')->where('taskable_type', 'project')->where('taskable_id', $project->id)->orderBy('status')->orderBy('due_at')->get()]);
    }

    // ---------------------------------------------------------------- conversions
    public function fromQuotation(Request $request, ProjectService $projects, string $quotation): JsonResponse
    {
        $this->authorizeAction('create');
        Gate::authorize('quotations.view');
        $project = $projects->fromQuotation(Quotation::findOrFail($quotation), $request->user()->id);

        return $this->respond($project, 201);
    }

    public function fromLead(Request $request, ProjectService $projects, string $lead): JsonResponse
    {
        $this->authorizeAction('create');
        Gate::authorize('customers.create');
        $project = $projects->fromLead(Lead::findOrFail($lead), $request->user()->id);

        return $this->respond($project, 201);
    }
}
