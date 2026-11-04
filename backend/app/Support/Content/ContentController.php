<?php

namespace App\Support\Content;

use App\Http\Resources\ContentResource;
use App\Models\Media;
use App\Support\Crud\CrudController;
use App\Support\Html\HtmlSanitizer;
use App\Support\Slug;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Base for publishable bilingual content (services, portfolio, case studies, articles, pages…).
 * Adds on top of the generic CRUD kit: slug generation, HTML sanitising, media attachment,
 * publish-permission enforcement and "scheduled publishing" (future published_at).
 */
abstract class ContentController extends CrudController
{
    protected ?string $statusColumn = 'status';

    protected string $resourceClass = ContentResource::class;

    /** Rich-text columns that must pass through the HTML sanitiser. */
    protected array $htmlFields = [];

    /** slug column => source column used when no slug is supplied, e.g. ['slug_en' => 'name_en']. */
    protected array $slugSources = [];

    /** Allowed media collections for this module ("main_image", "gallery"…). */
    protected array $mediaCollections = [];

    /** Rules shared by every publishable record. */
    protected function publishingRules(): array
    {
        return [
            'seo_title_ar' => ['nullable', 'string', 'max:255'],
            'seo_title_en' => ['nullable', 'string', 'max:255'],
            'seo_description_ar' => ['nullable', 'string', 'max:400'],
            'seo_description_en' => ['nullable', 'string', 'max:400'],
            'seo_noindex' => ['sometimes', 'boolean'],
            'is_featured' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'status' => ['sometimes', Rule::in(['draft', 'published', 'archived'])],
            'published_at' => ['nullable', 'date'],
            'media' => ['sometimes', 'array'],
            'media.*' => ['array', 'max:50'],
            'media.*.*' => ['integer', 'exists:media,id'],
        ];
    }

    protected function slugRules(string $table, ?Model $record): array
    {
        $unique = fn (string $col) => Rule::unique($table, $col)->ignore($record?->getKey());

        return [
            'slug_ar' => ['nullable', 'string', 'max:190', $unique('slug_ar')],
            'slug_en' => ['nullable', 'string', 'max:190', $unique('slug_en')],
        ];
    }

    protected function validated(Request $request, ?Model $record): array
    {
        $data = parent::validated($request, $record);

        // Moving content to published / scheduled / archived needs the publish permission.
        $touchesPublishing = isset($data['status']) && $data['status'] !== 'draft'
            || array_key_exists('published_at', $data)
            || (isset($data['status']) && $record && $record->getAttribute('status') !== $data['status']);
        if ($touchesPublishing && ! Gate::allows("{$this->module}.publish")) {
            abort(403, __('crud.publish_forbidden'));
        }

        foreach (array_keys($data['media'] ?? []) as $collection) {
            if (! in_array($collection, $this->mediaCollections, true)) {
                throw ValidationException::withMessages(['media' => ["Unknown media collection: {$collection}"]]);
            }
        }

        foreach ($this->htmlFields as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = HtmlSanitizer::clean($data[$field]);
            }
        }

        return $data;
    }

    protected function persistNew(array $data, Request $request): Model
    {
        $data = $this->withSlugs($data, null);
        $data = $this->withDefaults($data, null);

        return parent::persistNew($data, $request);
    }

    protected function persistUpdate(Model $record, array $data, Request $request): void
    {
        $data = $this->withSlugs($data, $record);
        if (isset($data['status']) && $data['status'] === 'published' && ! array_key_exists('published_at', $data) && $record->published_at === null) {
            $data['published_at'] = now();
        }

        parent::persistUpdate($record, $data, $request);
    }

    protected function afterSave(Model $record, array $data, Request $request, bool $created): void
    {
        if (isset($data['media']) && method_exists($record, 'syncMedia')) {
            foreach ($data['media'] as $collection => $ids) {
                $record->syncMedia($collection, array_map('intval', $ids));
            }
        }
    }

    protected function onStatusChange(Model $record, string $status): void
    {
        if ($status === 'published' && $record->published_at === null) {
            $record->published_at = now();
        }
    }

    protected function prepareDuplicate(Model $copy, Model $original): void
    {
        foreach (array_keys($this->slugSources) as $slugColumn) {
            $copy->{$slugColumn} = Slug::unique($this->model, $slugColumn, $original->{$slugColumn}.'-copy');
        }
        if ($copy->isFillable('status') || array_key_exists('status', $copy->getAttributes())) {
            $copy->status = 'draft';
            $copy->published_at = null;
        }
        foreach (['title_en', 'name_en'] as $label) {
            if (array_key_exists($label, $copy->getAttributes())) {
                $copy->{$label} = $original->{$label}.' (copy)';
            }
        }
        foreach (['title_ar', 'name_ar'] as $label) {
            if (array_key_exists($label, $copy->getAttributes())) {
                $copy->{$label} = $original->{$label}.' (نسخة)';
            }
        }
    }

    protected function afterDuplicate(Model $copy, Model $original): void
    {
        if (method_exists($original, 'media')) {
            foreach ($original->media()->get() as $media) {
                $copy->media()->attach($media->id, ['collection' => $media->pivot->collection, 'sort_order' => $media->pivot->sort_order]);
            }
        }
    }

    /** @param  array<string, mixed>  $data */
    protected function withSlugs(array $data, ?Model $record): array
    {
        foreach ($this->slugSources as $slugColumn => $sourceColumn) {
            $isArabic = str_ends_with($slugColumn, '_ar');
            $supplied = $data[$slugColumn] ?? null;

            if ($supplied !== null && $supplied !== '') {
                $data[$slugColumn] = Slug::unique($this->model, $slugColumn, Slug::make($supplied), $record?->getKey());
            } elseif ($record === null || ($record->{$slugColumn} === null || $record->{$slugColumn} === '')) {
                $source = (string) ($data[$sourceColumn] ?? $record?->{$sourceColumn} ?? '');
                $base = $isArabic ? Slug::make($source) : Slug::ascii($source);
                if ($base === '') {
                    $base = Slug::make($source);
                }
                $data[$slugColumn] = Slug::unique($this->model, $slugColumn, $base, $record?->getKey());
            } else {
                unset($data[$slugColumn]);
            }
        }

        return $data;
    }

    /** @param  array<string, mixed>  $data */
    protected function withDefaults(array $data, ?Model $record): array
    {
        if (($data['status'] ?? 'draft') === 'published' && empty($data['published_at'])) {
            $data['published_at'] = now();
        }

        return $data;
    }
}
