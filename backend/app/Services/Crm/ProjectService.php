<?php

namespace App\Services\Crm;

use App\Models\Lead;
use App\Models\Project;
use App\Models\Quotation;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProjectService
{
    /** Completion % follows the project's tasks when it has any. */
    public function recalculate(int $projectId): void
    {
        $total = Task::where('taskable_type', 'project')->where('taskable_id', $projectId)->count();
        if ($total === 0) {
            return;
        }
        $done = Task::where('taskable_type', 'project')->where('taskable_id', $projectId)->where('status', 'done')->count();
        Project::withoutEvents(fn () => Project::whereKey($projectId)->update(['progress' => (int) round($done / $total * 100)]));
    }

    public function changeStatus(Project $project, string $to, ?int $userId): Project
    {
        return DB::transaction(function () use ($project, $to, $userId) {
            $from = $project->status;
            $project->forceFill(['status' => $to, 'completed_at' => $to === 'completed' ? now() : null] + ($to === 'completed' ? ['progress' => 100] : []))->save();
            app(CrmService::class)->log('project', $project->id, 'status', null, ['from' => $from, 'to' => $to], $userId);

            return $project;
        });
    }

    public function fromQuotation(Quotation $q, ?int $userId): Project
    {
        if ($q->status !== 'approved') {
            throw ValidationException::withMessages(['status' => ['Only approved quotations can become projects.']]);
        }
        if (Project::where('quotation_id', $q->id)->exists()) {
            throw ValidationException::withMessages(['quotation' => ['A project already exists for this quotation.']]);
        }

        return DB::transaction(function () use ($q, $userId) {
            $project = new Project(['name' => $q->title, 'customer_id' => $q->customer_id, 'quotation_id' => $q->id, 'order_id' => $q->order_id, 'lead_id' => $q->lead_id,
                'scope' => $q->scope, 'description' => $q->notes, 'manager_id' => $q->assigned_to]);
            $project->forceFill(['created_by' => $userId])->save();
            app(CrmService::class)->log('project', $project->id, 'system', "Created from quotation {$q->number}", [], $userId);

            return $project;
        });
    }

    public function fromLead(Lead $lead, ?int $userId): Project
    {
        if (Project::where('lead_id', $lead->id)->exists()) {
            throw ValidationException::withMessages(['lead' => ['A project already exists for this lead.']]);
        }
        $crm = app(CrmService::class);

        return DB::transaction(function () use ($lead, $userId, $crm) {
            $customer = $lead->customer_id ? $lead->customer : $crm->convertToCustomer($lead, $userId);
            $project = new Project(['name' => ($lead->company ?: $lead->name).' — project', 'customer_id' => $customer->id, 'lead_id' => $lead->id,
                'description' => $lead->description, 'manager_id' => $lead->assigned_to]);
            $project->forceFill(['created_by' => $userId])->save();
            $crm->log('project', $project->id, 'system', "Created from lead #{$lead->id}", [], $userId);
            $crm->log('lead', $lead->id, 'system', "Converted to project #{$project->id}", [], $userId);

            return $project;
        });
    }
}
