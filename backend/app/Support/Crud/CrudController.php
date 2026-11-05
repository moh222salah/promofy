<?php

namespace App\Support\Crud;

use App\Http\Controllers\Controller;
use App\Http\Resources\ModelResource;
use App\Support\Audit\AuditLogger;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Generic, permission-enforcing admin CRUD controller.
 *
 * A module controller only declares its model, permission module, searchable / filterable /
 * sortable columns and validation rules; list, show, create, update, delete, duplicate,
 * publish / unpublish / archive, reorder, bulk actions, CSV export and select options come for free.
 * Every action is authorised on the backend through the "<module>.<action>" permission keys.
 */
abstract class CrudController extends Controller
{
    /** @var class-string<Model> */
    protected string $model;

    /** Permission module, e.g. "services" → services.view, services.create… */
    protected string $module;

    /** @var list<string> columns matched by ?search= */
    protected array $searchable = [];

    /** @var list<string>|array<string, string> filterable columns for ?filter[name]=a,b (name => column) */
    protected array $filterable = [];

    /** @var list<string> */
    protected array $sortable = ['id', 'created_at'];

    protected string $defaultSort = '-id';

    protected string $dateColumn = 'created_at';

    /** @var list<string> */
    protected array $with = [];

    /** @var list<string> */
    protected array $withCount = [];

    /** Column holding the responsible user; enables the "*_assigned" permission scoping. */
    protected ?string $assignedColumn = null;

    /** Column driving publish / unpublish / archive. */
    protected ?string $statusColumn = null;

    protected string $publishedValue = 'published';

    protected string $unpublishedValue = 'draft';

    protected string $archivedValue = 'archived';

    /** Column holding the manual sort position (enables /reorder). */
    protected ?string $orderColumn = null;

    /** @var list<string> */
    protected array $replicateExcept = [];

    protected int $maxPerPage = 100;

    /** @var class-string<\Illuminate\Http\Resources\Json\JsonResource> */
    protected string $resourceClass = ModelResource::class;

    // ---------------------------------------------------------------------------------------------
    // Hooks for module controllers
    // ---------------------------------------------------------------------------------------------

    /**
     * @return array<string, mixed> validation rules (arrays, not pipe strings); updates are always partial
     */
    protected function rules(?Model $record): array
    {
        return [];
    }

    /** @return array<string, string> */
    protected function messages(): array
    {
        return [];
    }

    /** @return array<string, string> */
    protected function attributes(): array
    {
        return [];
    }

    /** @param  array<string, mixed>  $data */
    protected function afterSave(Model $record, array $data, Request $request, bool $created): void {}

    /** @param  array<string, mixed>  $data */
    protected function beforeUpdate(Model $record, array $data, Request $request): void {}

    protected function beforeDelete(Model $record, Request $request): void {}

    protected function scopeIndex(Builder $query, Request $request): void {}

    protected function prepareDuplicate(Model $copy, Model $original): void {}

    protected function afterDuplicate(Model $copy, Model $original): void {}

    protected function onStatusChange(Model $record, string $status): void {}

    /** @return array<string, array<int, mixed>> rules for fields allowed in bulk "update" */
    protected function bulkRules(): array
    {
        return [];
    }

    /** @return array<string, string|callable> CSV header => attribute name or callable(Model): mixed */
    protected function exportColumns(): array
    {
        return [];
    }

    /** @return array{value: mixed, label_ar: ?string, label_en: ?string} */
    protected function option(Model $record): array
    {
        return [
            'value' => $record->getKey(),
            'label_ar' => $record->name_ar ?? $record->title_ar ?? $record->name ?? $record->title ?? (string) $record->getKey(),
            'label_en' => $record->name_en ?? $record->title_en ?? $record->name ?? $record->title ?? (string) $record->getKey(),
        ];
    }

    // ---------------------------------------------------------------------------------------------
    // Standard actions
    // ---------------------------------------------------------------------------------------------

    public function index(Request $request)
    {
        $scope = $this->scopeFor('view');
        $perPage = min(max($request->integer('per_page', 20), 1), $this->maxPerPage);

        return ($this->resourceClass)::collection(
            $this->indexQuery($request, $scope)->paginate($perPage)->withQueryString()
        );
    }

    public function show(string $id)
    {
        $scope = $this->scopeFor('view');

        return $this->respond($this->findRecord($id, $scope, true));
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorizeAction('create');
        $data = $this->validated($request, null);

        $record = DB::transaction(function () use ($data, $request) {
            $record = $this->persistNew($data, $request);
            $this->afterSave($record, $data, $request, true);

            return $record;
        });

        return $this->respond($this->reload($record), 201);
    }

    public function update(Request $request, string $id): JsonResponse
    {
        $scope = $this->scopeFor('edit');
        $record = $this->findRecord($id, $scope);
        $data = $this->validated($request, $record);

        DB::transaction(function () use ($record, $data, $request) {
            $this->beforeUpdate($record, $data, $request);
            $this->persistUpdate($record, $data, $request);
            $this->afterSave($record, $data, $request, false);
        });

        return $this->respond($this->reload($record));
    }

    public function destroy(Request $request, string $id)
    {
        $this->authorizeAction('delete');
        $record = $this->findRecord($id, 'all');

        DB::transaction(function () use ($record, $request) {
            $this->beforeDelete($record, $request);
            $record->delete();
        });

        return response()->noContent();
    }

    public function duplicate(string $id): JsonResponse
    {
        $this->authorizeAction('create');
        $original = $this->findRecord($id, 'all');

        $copy = DB::transaction(function () use ($original) {
            $copy = $original->replicate($this->replicateExcept);
            $this->prepareDuplicate($copy, $original);
            $copy->save();
            $this->afterDuplicate($copy, $original);

            return $copy;
        });

        return $this->respond($this->reload($copy), 201);
    }

    public function publish(string $id): JsonResponse
    {
        return $this->changeStatus($id, $this->publishedValue, 'published');
    }

    public function unpublish(string $id): JsonResponse
    {
        return $this->changeStatus($id, $this->unpublishedValue, 'unpublished');
    }

    public function archive(string $id): JsonResponse
    {
        return $this->changeStatus($id, $this->archivedValue, 'archived');
    }

    public function reorder(Request $request): JsonResponse
    {
        $this->authorizeAction('edit');
        abort_if($this->orderColumn === null, 404);

        $ids = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer', 'distinct'],
        ])['ids'];

        DB::transaction(function () use ($ids) {
            foreach (array_values($ids) as $position => $id) {
                ($this->model)::query()->whereKey($id)->update([$this->orderColumn => $position + 1]);
            }
        });

        AuditLogger::record($this->morph().'.reordered', null, [], [], ['ids' => array_values($ids)]);

        return response()->json(['data' => ['reordered' => count($ids)]]);
    }

    public function bulk(Request $request): JsonResponse
    {
        $payload = $request->validate([
            'action' => ['required', Rule::in(['publish', 'unpublish', 'archive', 'delete', 'update'])],
            'ids' => ['required', 'array', 'min:1', 'max:200'],
            'ids.*' => ['integer', 'distinct'],
            'data' => ['sometimes', 'array'],
            'confirm' => ['sometimes', 'boolean'],
        ]);
        $action = $payload['action'];

        $permission = match ($action) {
            'delete' => 'delete',
            'update' => 'edit',
            default => 'publish',
        };
        $scope = $this->scopeFor($permission);

        if ($action === 'delete' && ! ($payload['confirm'] ?? false)) {
            throw ValidationException::withMessages(['confirm' => [__('crud.bulk_delete_confirm')]]);
        }

        $changes = [];
        if ($action === 'update') {
            $rules = $this->bulkRules();
            if ($rules === []) {
                throw ValidationException::withMessages(['action' => [__('crud.bulk_update_unsupported')]]);
            }
            $changes = Validator::make($payload['data'] ?? [], $this->partialize($rules))->validate();
            if ($changes === []) {
                throw ValidationException::withMessages(['data' => [__('crud.bulk_update_empty')]]);
            }
        }
        if (in_array($action, ['publish', 'unpublish', 'archive'], true)) {
            abort_if($this->statusColumn === null, 404);
        }

        $affected = 0;
        DB::transaction(function () use ($payload, $action, $scope, $changes, $request, &$affected) {
            $records = $this->baseQuery($scope)->whereKey($payload['ids'])->get();
            foreach ($records as $record) {
                match ($action) {
                    'delete' => $this->deleteRecord($record, $request),
                    'update' => $this->bulkUpdateRecord($record, $changes, $request),
                    default => $this->setStatus($record, match ($action) {
                        'publish' => $this->publishedValue,
                        'unpublish' => $this->unpublishedValue,
                        default => $this->archivedValue,
                    }),
                };
                $affected++;
            }
        });

        AuditLogger::record($this->morph().'.bulk_'.$action, null, [], $changes, ['ids' => $payload['ids'], 'affected' => $affected]);

        return response()->json(['data' => ['affected' => $affected]]);
    }

    public function export(Request $request): StreamedResponse
    {
        $this->authorizeAction('export');
        $columns = $this->exportColumns();
        abort_if($columns === [], 404);

        $query = $this->indexQuery($request, 'all');
        $filename = $this->module.'-'.now()->format('Ymd-His').'.csv';

        AuditLogger::record($this->morph().'.exported', null, [], [], ['filters' => $request->query()]);

        return response()->streamDownload(function () use ($query, $columns) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM so Excel renders Arabic correctly
            fputcsv($out, array_keys($columns));
            foreach ($query->cursor() as $record) {
                $row = [];
                foreach ($columns as $source) {
                    $value = is_callable($source) ? $source($record) : data_get($record, $source);
                    $row[] = $this->csvCell($value);
                }
                fputcsv($out, $row);
            }
            fclose($out);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    public function options(Request $request): JsonResponse
    {
        $scope = $this->scopeFor('view');
        $query = $this->baseQuery($scope);
        $this->applySearch($query, (string) $request->query('q', ''));
        $this->scopeIndex($query, $request);

        $items = $query->orderBy($query->getModel()->getKeyName(), 'desc')->limit(50)->get()
            ->map(fn (Model $record) => $this->option($record))->values();

        return response()->json(['data' => $items]);
    }

    // ---------------------------------------------------------------------------------------------
    // Authorisation helpers
    // ---------------------------------------------------------------------------------------------

    protected function can(string $action): bool
    {
        return Gate::allows("{$this->module}.{$action}");
    }

    protected function authorizeAction(string $action): void
    {
        Gate::authorize("{$this->module}.{$action}");
    }

    /**
     * "all" when the user holds the full permission, "assigned" when only "<action>_assigned"
     * is held (records limited to the user), otherwise a 403.
     */
    protected function scopeFor(string $action): string
    {
        if ($this->can($action)) {
            return 'all';
        }
        if ($this->assignedColumn !== null && $this->can("{$action}_assigned")) {
            return 'assigned';
        }

        Gate::authorize("{$this->module}.{$action}");

        return 'all'; // unreachable: authorize() throws
    }

    protected function baseQuery(string $scope): Builder
    {
        $query = ($this->model)::query();
        if ($scope === 'assigned' && $this->assignedColumn !== null) {
            $query->where($query->getModel()->getTable().'.'.$this->assignedColumn, auth()->id());
        }

        return $query;
    }

    // ---------------------------------------------------------------------------------------------
    // Query building
    // ---------------------------------------------------------------------------------------------

    protected function indexQuery(Request $request, string $scope): Builder
    {
        $query = $this->baseQuery($scope)->with($this->with)->withCount($this->withCount);

        if ($this->usesSoftDeletes() && $this->can('delete')) {
            match ($request->query('trashed')) {
                'with' => $query->withTrashed(),
                'only' => $query->onlyTrashed(),
                default => null,
            };
        }

        $this->applySearch($query, (string) $request->query('search', ''));
        $this->applyFilters($query, (array) $request->query('filter', []));
        $this->applyDateRange($query, $request);
        $this->scopeIndex($query, $request);
        $this->applySort($query, (string) $request->query('sort', ''));

        return $query;
    }

    protected function applySearch(Builder $query, string $term): void
    {
        $term = trim($term);
        if ($term === '' || $this->searchable === []) {
            return;
        }

        $like = '%'.mb_strtolower(str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term)).'%';
        $grammar = $query->getQuery()->getGrammar();

        $query->where(function (Builder $q) use ($like, $grammar, $query) {
            foreach ($this->searchable as $column) {
                $qualified = str_contains($column, '.') ? $column : $query->getModel()->getTable().'.'.$column;
                $q->orWhereRaw('lower('.$grammar->wrap($qualified).") like ? escape '!'", [$like]);
            }
        });
    }

    /** @param  array<string, mixed>  $filters */
    protected function applyFilters(Builder $query, array $filters): void
    {
        foreach ($this->filterable as $name => $column) {
            if (is_int($name)) {
                $name = $column;
            }
            $raw = $filters[$name] ?? null;
            if ($raw === null || $raw === '' || $raw === []) {
                continue;
            }

            $values = is_array($raw) ? $raw : explode(',', (string) $raw);
            $values = array_map(fn ($v) => match (trim((string) $v)) {
                'true' => 1,
                'false' => 0,
                default => trim((string) $v),
            }, $values);

            $qualified = $query->getModel()->getTable().'.'.$column;
            $includeNull = in_array('null', $values, true);
            $values = array_values(array_filter($values, fn ($v) => $v !== 'null' && $v !== ''));

            $query->where(function (Builder $q) use ($qualified, $values, $includeNull) {
                if ($values !== []) {
                    $q->whereIn($qualified, $values);
                }
                if ($includeNull) {
                    $q->orWhereNull($qualified);
                }
            });
        }
    }

    protected function applyDateRange(Builder $query, Request $request): void
    {
        $column = $query->getModel()->getTable().'.'.$this->dateColumn;
        foreach (['from' => '>=', 'to' => '<='] as $key => $operator) {
            $value = $request->query($key);
            if (! is_string($value) || $value === '') {
                continue;
            }
            try {
                $date = Carbon::parse($value);
            } catch (\Throwable) {
                continue;
            }
            $query->where($column, $operator, $key === 'from' ? $date->startOfDay() : $date->endOfDay());
        }
    }

    protected function applySort(Builder $query, string $sort): void
    {
        $table = $query->getModel()->getTable();
        $applied = false;

        foreach (array_filter(array_map('trim', explode(',', $sort !== '' ? $sort : $this->defaultSort))) as $part) {
            $direction = str_starts_with($part, '-') ? 'desc' : 'asc';
            $column = ltrim($part, '-+');
            if (in_array($column, $this->sortable, true)) {
                $query->orderBy("{$table}.{$column}", $direction);
                $applied = true;
            }
        }

        if (! $applied) {
            $default = $this->defaultSort;
            $query->orderBy($table.'.'.ltrim($default, '-+'), str_starts_with($default, '-') ? 'desc' : 'asc');
        }
        $query->orderBy($table.'.'.$query->getModel()->getKeyName(), 'desc');
    }

    protected function findRecord(string|int $id, string $scope, bool $withTrashed = false): Model
    {
        $query = $this->baseQuery($scope);
        if ($withTrashed && $this->usesSoftDeletes() && $this->can('delete')) {
            $query->withTrashed();
        }

        return $query->with($this->with)->withCount($this->withCount)->findOrFail($id);
    }

    protected function reload(Model $record): Model
    {
        return $this->baseQuery('all')->with($this->with)->withCount($this->withCount)->findOrFail($record->getKey());
    }

    // ---------------------------------------------------------------------------------------------
    // Persistence
    // ---------------------------------------------------------------------------------------------

    /** @param  array<string, mixed>  $data */
    protected function persistNew(array $data, Request $request): Model
    {
        $record = new $this->model;
        $record->fill(Arr::only($data, $record->getFillable()));
        $record->save();

        return $record;
    }

    /** @param  array<string, mixed>  $data */
    protected function persistUpdate(Model $record, array $data, Request $request): void
    {
        $record->fill(Arr::only($data, $record->getFillable()));
        $record->save();
    }

    /** @return array<string, mixed> */
    protected function validated(Request $request, ?Model $record): array
    {
        $rules = $this->rules($record);
        if ($record !== null) {
            $rules = $this->partialize($rules);
        }

        return Validator::make($request->all(), $rules, $this->messages(), $this->attributes())->validate();
    }

    /**
     * Updates accept partial payloads: every rule set gets "sometimes" so omitted fields are untouched
     * while supplied fields are still fully validated.
     *
     * @param  array<string, mixed>  $rules
     * @return array<string, mixed>
     */
    protected function partialize(array $rules): array
    {
        $out = [];
        foreach ($rules as $field => $rule) {
            $list = is_string($rule) ? explode('|', $rule) : $rule;
            if (! in_array('sometimes', $list, true)) {
                array_unshift($list, 'sometimes');
            }
            $out[$field] = $list;
        }

        return $out;
    }

    protected function changeStatus(string $id, string $status, string $verb): JsonResponse
    {
        $this->authorizeAction('publish');
        abort_if($this->statusColumn === null, 404);

        $record = $this->findRecord($id, 'all');
        DB::transaction(function () use ($record, $status, $verb) {
            $this->setStatus($record, $status);
            AuditLogger::record($this->morph().'.'.$verb, $record, [], [$this->statusColumn => $status]);
        });

        return $this->respond($this->reload($record));
    }

    /** Business rules a record must satisfy before it can go live; applies to publish, bulk publish and any other status change. */
    protected function assertPublishable(Model $record): void {}

    protected function setStatus(Model $record, string $status): void
    {
        if ($status === $this->publishedValue) {
            $this->assertPublishable($record);
        }
        $record->forceFill([$this->statusColumn => $status]);
        $this->onStatusChange($record, $status);
        $record->save();
    }

    /** @param  array<string, mixed>  $changes */
    protected function bulkUpdateRecord(Model $record, array $changes, Request $request): void
    {
        $this->beforeUpdate($record, $changes, $request);
        $record->forceFill(Arr::only($changes, array_keys($this->bulkRules())))->save();
    }

    protected function deleteRecord(Model $record, Request $request): void
    {
        $this->beforeDelete($record, $request);
        $record->delete();
    }

    // ---------------------------------------------------------------------------------------------
    // Small utilities
    // ---------------------------------------------------------------------------------------------

    protected function respond(Model $record, int $status = 200): JsonResponse
    {
        return (new $this->resourceClass($record))->response()->setStatusCode($status);
    }

    protected function usesSoftDeletes(): bool
    {
        return in_array(SoftDeletes::class, class_uses_recursive($this->model), true);
    }

    protected function morph(): string
    {
        return (new $this->model)->getMorphClass();
    }

    /** Neutralise spreadsheet formula injection in exported cells. */
    protected function csvCell(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if (is_array($value) || is_object($value)) {
            $value = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        $value = (string) ($value ?? '');

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
