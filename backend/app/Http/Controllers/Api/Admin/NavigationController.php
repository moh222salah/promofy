<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Menu;
use App\Support\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class NavigationController extends Controller
{
    public static function tree(string $location, bool $visibleOnly = false): array
    {
        $menu = Menu::firstOrCreate(['location' => $location], ['name' => ucfirst($location)]);
        $items = $menu->items()->get()->when($visibleOnly, fn ($c) => $c->where('is_visible', true));
        $byParent = $items->groupBy('parent_id');
        $build = function ($parentId) use (&$build, $byParent) {
            return ($byParent[$parentId] ?? collect())->map(fn ($i) => [
                'id' => $i->id, 'label_ar' => $i->label_ar, 'label_en' => $i->label_en, 'url' => $i->url,
                'open_in_new_tab' => $i->open_in_new_tab, 'is_visible' => $i->is_visible,
                'children' => $build($i->id),
            ])->values()->all();
        };

        return $build(null);
    }

    public function index(): JsonResponse
    {
        Gate::authorize('navigation.view');

        return response()->json(['data' => ['header' => self::tree('header'), 'footer' => self::tree('footer')]]);
    }

    public function update(Request $request, string $location): JsonResponse
    {
        Gate::authorize('navigation.edit');
        abort_unless(in_array($location, ['header', 'footer'], true), 404);

        $item = [
            'label_ar' => ['required', 'string', 'max:120'],
            'label_en' => ['required', 'string', 'max:120'],
            'url' => ['required', 'string', 'max:500', 'regex:/^(\/|#|https?:\/\/|mailto:|tel:)/i'],
            'open_in_new_tab' => ['sometimes', 'boolean'],
            'is_visible' => ['sometimes', 'boolean'],
        ];
        $rules = ['items' => ['present', 'array', 'max:30']];
        foreach ($item as $k => $r) {
            $rules["items.*.{$k}"] = $r;
            $rules["items.*.children.*.{$k}"] = $r;
        }
        $rules['items.*.children'] = ['sometimes', 'array', 'max:15'];
        $data = $request->validate($rules);

        DB::transaction(function () use ($location, $data) {
            $menu = Menu::firstOrCreate(['location' => $location], ['name' => ucfirst($location)]);
            $menu->items()->delete();
            foreach (array_values($data['items']) as $i => $row) {
                $parent = $menu->items()->create($this->row($row, $i));
                foreach (array_values($row['children'] ?? []) as $j => $child) {
                    $menu->items()->create($this->row($child, $j) + ['parent_id' => $parent->id]);
                }
            }
        });
        AuditLogger::record('navigation.updated', null, [], [], ['location' => $location, 'count' => count($data['items'])]);

        return response()->json(['data' => self::tree($location)]);
    }

    private function row(array $r, int $order): array
    {
        return [
            'label_ar' => $r['label_ar'], 'label_en' => $r['label_en'], 'url' => $r['url'],
            'open_in_new_tab' => $r['open_in_new_tab'] ?? false, 'is_visible' => $r['is_visible'] ?? true, 'sort_order' => $order,
        ];
    }
}
