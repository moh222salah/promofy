<?php

namespace App\Http\Controllers\Api\Admin;

use App\Models\ContactMessage;
use App\Support\Crud\CrudController;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Inbox for the public contact form. Messages are created only through the public endpoint. */
class ContactMessageController extends CrudController
{
    protected string $model = ContactMessage::class;

    protected string $module = 'messages';

    protected array $searchable = ['name', 'email', 'phone', 'subject', 'message'];

    protected array $filterable = ['status', 'assigned_to'];

    protected array $sortable = ['id', 'created_at', 'status', 'name'];

    protected array $with = ['assignee:id,name'];

    protected function rules(?Model $record): array
    {
        return [
            'status' => ['sometimes', Rule::in(['new', 'read', 'replied', 'archived'])],
            'assigned_to' => ['nullable', 'integer', Rule::exists('users', 'id')],
        ];
    }

    protected function persistUpdate(Model $record, array $data, Request $request): void
    {
        $record->forceFill(\Illuminate\Support\Arr::only($data, ['status', 'assigned_to']))->save();
    }

    protected function exportColumns(): array
    {
        return ['ID' => 'id', 'Name' => 'name', 'Email' => 'email', 'Phone' => 'phone', 'Subject' => 'subject', 'Message' => 'message', 'Status' => 'status', 'Received' => 'created_at'];
    }
}
