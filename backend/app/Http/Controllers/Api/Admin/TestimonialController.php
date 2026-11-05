<?php

namespace App\Http\Controllers\Api\Admin;

use App\Models\Testimonial;
use App\Support\Content\ContentController;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TestimonialController extends ContentController
{
    protected string $model = Testimonial::class;

    protected string $module = 'testimonials';

    protected array $searchable = ['author_name', 'company', 'quote_en', 'quote_ar'];

    protected array $filterable = ['status', 'is_featured', 'consent_given'];

    protected array $sortable = ['id', 'author_name', 'sort_order', 'created_at', 'status'];

    protected string $defaultSort = 'sort_order';

    protected ?string $orderColumn = 'sort_order';

    protected array $with = ['media'];

    protected array $mediaCollections = ['avatar'];

    protected function rules(?Model $record): array
    {
        return [
            'author_name' => ['required', 'string', 'max:190'],
            'author_role_ar' => ['nullable', 'string', 'max:190'],
            'author_role_en' => ['nullable', 'string', 'max:190'],
            'company' => ['nullable', 'string', 'max:190'],
            'quote_ar' => ['nullable', 'string', 'max:2000'],
            'quote_en' => ['nullable', 'string', 'max:2000'],
            'rating' => ['nullable', 'integer', 'between:1,5'],
            'consent_given' => ['sometimes', 'boolean'],
            'is_featured' => ['sometimes', 'boolean'],
            'sort_order' => ['sometimes', 'integer', 'min:0'],
            'status' => ['sometimes', 'in:draft,published,archived'],
            'published_at' => ['nullable', 'date'],
            'media' => ['sometimes', 'array'],
            'media.*' => ['array', 'max:5'],
            'media.*.*' => ['integer', 'exists:media,id'],
        ];
    }

    /** A testimonial is only publishable with the author's recorded consent and a quote. */
    protected function validated(Request $request, ?Model $record): array
    {
        $data = parent::validated($request, $record);
        $status = $data['status'] ?? $record?->status;
        $consent = $data['consent_given'] ?? $record?->consent_given ?? false;
        $quote = ($data['quote_en'] ?? $record?->quote_en) . ($data['quote_ar'] ?? $record?->quote_ar);
        if ($status === 'published' && (! $consent || trim($quote) === '')) {
            throw ValidationException::withMessages(['consent_given' => ['A testimonial needs the author\'s recorded consent and a quote before it can be published.']]);
        }

        return $data;
    }

    /** The publish / bulk-publish actions must honour the same consent rule as create and update. */
    protected function assertPublishable(Model $record): void
    {
        if (! $record->consent_given || trim($record->quote_en.$record->quote_ar) === '') {
            throw ValidationException::withMessages(['consent_given' => ['A testimonial needs the author\'s recorded consent and a quote before it can be published.']]);
        }
    }

    protected function option(Model $record): array
    {
        return ['value' => $record->id, 'label_ar' => $record->author_name, 'label_en' => $record->author_name];
    }
}
