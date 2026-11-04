<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\HomepageVersion;
use App\Services\Content\HomepageService;
use App\Support\Audit\AuditLogger;
use App\Support\Content\HomepageSections;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class HomepageController extends Controller
{
    public function __construct(private readonly HomepageService $homepage) {}

    public function draft(): JsonResponse
    {
        Gate::authorize('homepage.view');
        $draft = $this->homepage->draft();

        return response()->json(['data' => $this->present($draft), 'published_version' => $this->homepage->published()?->id]);
    }

    public function saveDraft(Request $request): JsonResponse
    {
        Gate::authorize('homepage.edit');
        $data = $request->validate($this->rules());
        $draft = $this->homepage->saveDraft($data['sections'], $request->user()->id);
        AuditLogger::record('homepage.draft_saved', null, [], [], ['version' => $draft->id]);

        return response()->json(['data' => $this->present($draft)]);
    }

    /** Draft rendered exactly as the public site would show it. */
    public function preview(): JsonResponse
    {
        Gate::authorize('homepage.view');

        return response()->json(['data' => $this->homepage->resolve($this->homepage->draft()->sections, preview: true)]);
    }

    public function publish(Request $request): JsonResponse
    {
        Gate::authorize('homepage.publish');
        $version = $this->homepage->publishDraft($request->user()->id);
        AuditLogger::record('homepage.published', null, [], [], ['version' => $version->id]);

        return response()->json(['data' => $this->present($version)]);
    }

    public function history(): JsonResponse
    {
        Gate::authorize('homepage.view');

        return response()->json(['data' => HomepageVersion::where('status', 'published')->orderByDesc('id')->limit(50)
            ->get(['id', 'published_at', 'created_by'])]);
    }

    public function restore(Request $request, string $id): JsonResponse
    {
        Gate::authorize('homepage.edit');
        $version = HomepageVersion::where('status', 'published')->findOrFail($id);
        $draft = $this->homepage->saveDraft($version->sections, $request->user()->id);
        AuditLogger::record('homepage.version_restored_to_draft', null, [], [], ['from' => $version->id]);

        return response()->json(['data' => $this->present($draft)]);
    }

    private function present(HomepageVersion $v): array
    {
        return ['id' => $v->id, 'sections' => $v->sections, 'updated_at' => $v->updated_at];
    }

    /** @return array<string, mixed> */
    private function rules(): array
    {
        return [
            'sections' => ['required', 'array', 'min:1', 'max:30'],
            'sections.*.id' => ['required', 'string', 'max:64', 'distinct'],
            'sections.*.type' => ['required', Rule::in(HomepageSections::TYPES)],
            'sections.*.enabled' => ['required', 'boolean'],
            'sections.*.title_ar' => ['nullable', 'string', 'max:300'],
            'sections.*.title_en' => ['nullable', 'string', 'max:300'],
            'sections.*.subtitle_ar' => ['nullable', 'string', 'max:600'],
            'sections.*.subtitle_en' => ['nullable', 'string', 'max:600'],
            'sections.*.settings' => ['nullable', 'array'],
            'sections.*.settings.ids' => ['sometimes', 'array', 'max:24'],
            'sections.*.settings.ids.*' => ['integer'],
            'sections.*.settings.media_id' => ['nullable', 'integer', 'exists:media,id'],
            'sections.*.settings.video_media_id' => ['nullable', 'integer', 'exists:media,id'],
            'sections.*.settings.buttons' => ['sometimes', 'array', 'max:4'],
            'sections.*.settings.buttons.*.label_ar' => ['required', 'string', 'max:80'],
            'sections.*.settings.buttons.*.label_en' => ['required', 'string', 'max:80'],
            'sections.*.settings.buttons.*.url' => ['required', 'string', 'max:500', 'regex:/^(\/|https?:\/\/|mailto:|tel:)/i'],
            'sections.*.settings.buttons.*.style' => ['sometimes', Rule::in(['primary', 'secondary', 'ghost'])],
            'sections.*.settings.items' => ['sometimes', 'array', 'max:12'],
            'sections.*.settings.items.*.title_ar' => ['nullable', 'string', 'max:190'],
            'sections.*.settings.items.*.title_en' => ['nullable', 'string', 'max:190'],
            'sections.*.settings.items.*.text_ar' => ['nullable', 'string', 'max:600'],
            'sections.*.settings.items.*.text_en' => ['nullable', 'string', 'max:600'],
        ];
    }
}
