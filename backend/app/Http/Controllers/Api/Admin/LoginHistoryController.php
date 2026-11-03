<?php

namespace App\Http\Controllers\Api\Admin;

use App\Models\LoginHistory;
use App\Support\Crud\CrudController;

class LoginHistoryController extends CrudController
{
    protected string $model = LoginHistory::class;

    protected string $module = 'audit';

    protected array $searchable = ['email', 'ip_address'];

    protected array $filterable = ['status', 'user_id'];

    protected array $sortable = ['id', 'created_at', 'status'];

    protected array $with = ['user:id,name,email'];
}
