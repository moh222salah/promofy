<?php

namespace App\Http\Controllers\Api\Admin;

use App\Models\Task;
use App\Support\Crud\CrudController;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class TaskController extends CrudController
{
    protected string $model = Task::class;

    protected string $module = 'tasks';

    protected array $searchable = ['title', 'description'];

    protected array $filterable = ['status', 'priority', 'assigned_to', 'taskable_type', 'taskable_id'];

    protected array $sortable = ['id', 'due_at', 'priority', 'status', 'created_at'];

    protected string $defaultSort = 'due_at';

    protected array $with = ['assignee:id,name'];

    protected ?string $assignedColumn = 'assigned_to';

    protected string $dateColumn = 'due_at';

    protected function rules(?Model $record): array
    {
        return [
            'title' => ['required', 'string', 'max:190'],
            'description' => ['nullable', 'string', 'max:3000'],
            'due_at' => ['nullable', 'date'],
            'priority' => ['sometimes', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'assigned_to' => ['nullable', 'integer', Rule::exists('users', 'id')->where('type', 'staff')],
            'taskable_type' => ['nullable', Rule::in(['lead', 'customer', 'project'])],
            'taskable_id' => ['nullable', 'integer', 'required_with:taskable_type'],
        ];
    }

    protected function persistNew(array $data, Request $request): Model
    {
        $data['assigned_to'] ??= $request->user()->id;
        $task = parent::persistNew($data, $request);
        $task->forceFill(['created_by' => $request->user()->id])->save();

        return $task;
    }

    public function complete(string $id): JsonResponse
    {
        $task = $this->findRecord($id, $this->scopeFor('edit'));
        $task->forceFill(['status' => 'done', 'completed_at' => now()])->save();

        return $this->respond($task);
    }

    public function reopen(string $id): JsonResponse
    {
        $task = $this->findRecord($id, $this->scopeFor('edit'));
        $task->forceFill(['status' => 'open', 'completed_at' => null])->save();

        return $this->respond($task);
    }
}
