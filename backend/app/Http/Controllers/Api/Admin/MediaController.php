<?php

namespace App\Http\Controllers\Api\Admin;

use App\Models\Media;
use App\Services\Media\MediaService;
use App\Support\Audit\AuditLogger;
use App\Support\Crud\CrudController;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class MediaController extends CrudController
{
    protected string $model = Media::class;

    protected string $module = 'media';

    protected array $searchable = ['original_name', 'title', 'alt_ar', 'alt_en'];

    protected array $filterable = ['kind', 'category'];

    protected array $sortable = ['id', 'created_at', 'size', 'original_name'];

    protected function rules(?Model $record): array
    {
        return [
            'title' => ['nullable', 'string', 'max:255'],
            'alt_ar' => ['nullable', 'string', 'max:255'],
            'alt_en' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:64'],
        ];
    }

    protected function option(Model $record): array
    {
        return ['value' => $record->id, 'label_ar' => $record->title ?? $record->original_name, 'label_en' => $record->title ?? $record->original_name, 'url' => $record->url, 'kind' => $record->kind];
    }

    protected function beforeDelete(Model $record, Request $request): void
    {
        abort_if($record->isInUse(), 409, 'This file is still used by other content. Remove those references first.');
    }

    /** Files are deleted from storage together with the row. */
    public function destroy(Request $request, string $id)
    {
        $this->authorizeAction('delete');
        $media = $this->findRecord($id, 'all');
        $this->beforeDelete($media, $request);
        app(MediaService::class)->delete($media);

        return response()->noContent();
    }

    public function upload(Request $request, MediaService $service): JsonResponse
    {
        $this->authorizeAction('create');
        $request->validate([
            'files' => ['required_without:file', 'array', 'max:20'],
            'files.*' => ['file'],
            'file' => ['required_without:files', 'file'],
            'title' => ['nullable', 'string', 'max:255'],
            'alt_ar' => ['nullable', 'string', 'max:255'],
            'alt_en' => ['nullable', 'string', 'max:255'],
            'category' => ['nullable', 'string', 'max:64'],
        ]);

        $files = $request->hasFile('files') ? $request->file('files') : [$request->file('file')];
        $items = DB::transaction(fn () => collect($files)->map(
            fn ($file) => $service->store($file, $request->user()->id, $request->only(['title', 'alt_ar', 'alt_en', 'category']))
        ));

        AuditLogger::record('media.uploaded', null, [], [], ['ids' => $items->pluck('id')->all()]);

        return response()->json(['data' => $items->values()], 201);
    }

    public function replace(Request $request, MediaService $service, string $id): JsonResponse
    {
        $this->authorizeAction('edit');
        $request->validate(['file' => ['required', 'file']]);
        $media = $service->replace($this->findRecord($id, 'all'), $request->file('file'));
        AuditLogger::record('media.replaced', $media);

        return $this->respond($media->refresh());
    }

    public function usages(string $id): JsonResponse
    {
        $this->authorizeAction('view');

        return response()->json(['data' => $this->findRecord($id, 'all')->usages()]);
    }
}
