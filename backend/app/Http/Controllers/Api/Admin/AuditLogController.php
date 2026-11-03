<?php

namespace App\Http\Controllers\Api\Admin;

use App\Models\AuditLog;
use App\Support\Crud\CrudController;

class AuditLogController extends CrudController
{
    protected string $model = AuditLog::class;

    protected string $module = 'audit';

    protected array $searchable = ['event', 'ip_address'];

    protected array $filterable = ['user_id', 'event', 'auditable_type', 'auditable_id'];

    protected array $sortable = ['id', 'created_at', 'event'];

    protected array $with = ['user:id,name,email'];

    protected function exportColumns(): array
    {
        return [
            'ID' => 'id', 'When' => 'created_at', 'User' => 'user.email', 'Event' => 'event',
            'Type' => 'auditable_type', 'Subject ID' => 'auditable_id', 'IP' => 'ip_address',
            'Old' => 'old_values', 'New' => 'new_values',
        ];
    }
}
