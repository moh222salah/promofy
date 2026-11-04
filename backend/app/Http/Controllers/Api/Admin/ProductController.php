<?php

namespace App\Http\Controllers\Api\Admin;

use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductFile;
use App\Models\ProductVariant;
use App\Services\Store\InventoryService;
use App\Support\Audit\AuditLogger;
use App\Support\Content\ContentController;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ProductController extends ContentController
{
    protected string $model = Product::class;

    protected string $module = 'products';

    protected array $searchable = ['name_ar', 'name_en', 'sku', 'slug_en'];

    protected array $filterable = ['status', 'product_category_id', 'type', 'is_featured', 'track_stock'];

    protected array $sortable = ['id', 'name_en', 'sku', 'price', 'stock_quantity', 'sort_order', 'created_at', 'status'];

    protected string $defaultSort = '-id';

    protected array $with = ['media', 'category', 'variants', 'files'];

    protected array $htmlFields = ['description_ar', 'description_en'];

    protected array $slugSources = ['slug_ar' => 'name_ar', 'slug_en' => 'name_en'];

    protected array $mediaCollections = ['main_image', 'gallery'];

    protected function rules(?Model $record): array
    {
        $id = $record?->getKey();

        return $this->slugRules('products', $record) + $this->publishingRules() + [
            'product_category_id' => ['nullable', 'integer', 'exists:product_categories,id'],
            'type' => ['required', Rule::in(['digital_download', 'template', 'creative_package', 'fixed_package', 'subscription', 'custom'])],
            'sku' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('products', 'sku')->ignore($id)],
            'name_ar' => ['required', 'string', 'max:190'],
            'name_en' => ['required', 'string', 'max:190'],
            'short_description_ar' => ['nullable', 'string', 'max:600'],
            'short_description_en' => ['nullable', 'string', 'max:600'],
            'description_ar' => ['nullable', 'string', 'max:100000'],
            'description_en' => ['nullable', 'string', 'max:100000'],
            'price' => ['required', 'numeric', 'min:0', 'max:99999999'],
            'discount_price' => ['nullable', 'numeric', 'min:0', 'lt:price'],
            'currency_code' => ['required', 'string', 'size:3', 'exists:currencies,code'],
            'options' => ['nullable', 'array', 'max:10'],
            'options.*.name_ar' => ['required', 'string', 'max:100'],
            'options.*.name_en' => ['required', 'string', 'max:100'],
            'options.*.values' => ['required', 'array', 'min:1', 'max:30'],
            'options.*.values.*' => ['string', 'max:100'],
            'has_variants' => ['sometimes', 'boolean'],
            'track_stock' => ['sometimes', 'boolean'],
            'stock_quantity' => ['sometimes', 'integer', 'min:0', 'max:10000000'],
            'low_stock_threshold' => ['sometimes', 'integer', 'min:0'],
            'allow_backorder' => ['sometimes', 'boolean'],
            'allow_online_payment' => ['sometimes', 'boolean'],
            'allow_whatsapp_order' => ['sometimes', 'boolean'],
            'subscription_interval' => ['nullable', Rule::in(['monthly', 'yearly']), 'required_if:type,subscription'],
            'video_url' => ['nullable', 'url', 'max:500'],
            'variants' => ['sometimes', 'array', 'max:100'],
            'variants.*.id' => ['sometimes', 'integer'],
            'variants.*.sku' => ['required', 'string', 'max:64', 'regex:/^[A-Za-z0-9._-]+$/', 'distinct'],
            'variants.*.name_ar' => ['required', 'string', 'max:190'],
            'variants.*.name_en' => ['required', 'string', 'max:190'],
            'variants.*.option_values' => ['nullable', 'array'],
            'variants.*.price' => ['nullable', 'numeric', 'min:0'],
            'variants.*.discount_price' => ['nullable', 'numeric', 'min:0'],
            'variants.*.stock_quantity' => ['sometimes', 'integer', 'min:0'],
            'variants.*.is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function validated(Request $request, ?Model $record): array
    {
        $data = parent::validated($request, $record);
        if ($record !== null) {
            unset($data['stock_quantity']); // stock changes only through the inventory endpoint (keeps history)
        }
        foreach ($data['variants'] ?? [] as $i => $v) {
            $dup = ProductVariant::where('sku', $v['sku'])->when($v['id'] ?? null, fn ($q, $vid) => $q->whereKeyNot($vid))->exists()
                || Product::where('sku', $v['sku'])->exists();
            if ($dup) {
                throw ValidationException::withMessages(["variants.{$i}.sku" => ['This SKU is already in use.']]);
            }
        }

        return $data;
    }

    protected function afterSave(Model $record, array $data, Request $request, bool $created): void
    {
        parent::afterSave($record, $data, $request, $created);
        $inventory = app(InventoryService::class);

        if ($created && $record->track_stock && ! $record->has_variants && $record->stock_quantity > 0) {
            InventoryMovement::create(['product_id' => $record->id, 'change' => $record->stock_quantity, 'quantity_after' => $record->stock_quantity, 'reason' => 'initial', 'user_id' => $request->user()->id]);
        }

        if (array_key_exists('variants', $data)) {
            $keep = [];
            foreach (array_values($data['variants']) as $i => $row) {
                $variant = isset($row['id']) ? $record->variants()->find($row['id']) : null;
                $isNew = $variant === null;
                $variant ??= new ProductVariant;
                $stock = $isNew ? (int) ($row['stock_quantity'] ?? 0) : null;
                $variant->fill(collect($row)->except(['id', 'stock_quantity'])->all());
                $variant->product_id = $record->id;
                $variant->sort_order = $i;
                if ($isNew) {
                    $variant->stock_quantity = 0;
                }
                $variant->save();
                if ($isNew && $stock > 0) {
                    $inventory->change($record, $variant, $stock, 'initial', null, null, null, $request->user()->id);
                }
                $keep[] = $variant->id;
            }
            $record->variants()->whereNotIn('id', $keep)->delete();
            if ($keep !== [] && ! $record->has_variants) {
                $record->forceFill(['has_variants' => true])->save();
            }
        }
    }

    protected function afterDuplicate(Model $copy, Model $original): void
    {
        parent::afterDuplicate($copy, $original);
        $copy->forceFill(['sku' => Str::limit($original->sku, 50, '').'-'.Str::lower(Str::random(5)), 'stock_quantity' => 0])->save();
        foreach ($original->variants as $i => $v) {
            $new = $v->replicate(['stock_quantity']);
            $new->product_id = $copy->id;
            $new->sku = Str::limit($v->sku, 50, '').'-'.Str::lower(Str::random(5));
            $new->stock_quantity = 0;
            $new->save();
        }
    }

    protected function bulkRules(): array
    {
        return ['product_category_id' => ['nullable', 'integer', 'exists:product_categories,id'], 'is_featured' => ['boolean'], 'allow_online_payment' => ['boolean'], 'allow_whatsapp_order' => ['boolean']];
    }

    protected function exportColumns(): array
    {
        return ['SKU' => 'sku', 'Type' => 'type', 'Name (EN)' => 'name_en', 'Name (AR)' => 'name_ar', 'Category' => 'category.name_en',
            'Price' => 'price', 'Discount price' => 'discount_price', 'Currency' => 'currency_code', 'Stock' => 'stock_quantity', 'Status' => 'status'];
    }

    // ------------------------------------------------------------ inventory
    public function adjustStock(Request $request, InventoryService $inventory, string $id): JsonResponse
    {
        $this->authorizeAction('edit');
        $product = $this->findRecord($id, 'all');
        $data = $request->validate([
            'change' => ['required', 'integer', 'not_in:0', 'between:-1000000,1000000'],
            'reason' => ['required', Rule::in(['adjustment', 'restock'])],
            'note' => ['nullable', 'string', 'max:255'],
            'variant_id' => ['nullable', 'integer'],
        ]);
        abort_unless($product->track_stock, 422, 'Stock tracking is disabled for this product.');
        $variant = isset($data['variant_id']) ? $product->variants()->findOrFail($data['variant_id']) : null;
        abort_if($product->has_variants && ! $variant, 422, 'Choose a variant to adjust.');

        $after = DB::transaction(fn () => $inventory->change($product, $variant, $data['change'], $data['reason'], null, null, $data['note'] ?? null, $request->user()->id));
        AuditLogger::record('product.stock_adjusted', $product, [], ['change' => $data['change'], 'after' => $after]);

        return response()->json(['data' => ['quantity' => $after, 'low' => $inventory->isLow($product->refresh())]]);
    }

    public function movements(Request $request, string $id): JsonResponse
    {
        $this->authorizeAction('view');
        $product = $this->findRecord($id, 'all', true);

        return response()->json(InventoryMovement::where('product_id', $product->id)->with('user:id,name')->latest('id')->paginate(30)->withQueryString());
    }

    public function lowStock(): JsonResponse
    {
        $this->authorizeAction('view');
        $rows = Product::where('track_stock', true)->where('has_variants', false)->whereColumn('stock_quantity', '<=', 'low_stock_threshold')
            ->orderBy('stock_quantity')->limit(100)->get(['id', 'sku', 'name_ar', 'name_en', 'stock_quantity', 'low_stock_threshold']);

        return response()->json(['data' => $rows]);
    }

    // ------------------------------------------------------------ downloadable files (private storage)
    public function uploadFile(Request $request, string $id): JsonResponse
    {
        $this->authorizeAction('edit');
        $product = $this->findRecord($id, 'all');
        $request->validate(['file' => ['required', 'file', 'max:'.config('promofy.uploads.max_document_kb') * 5], 'label' => ['nullable', 'string', 'max:190']]);
        $file = $request->file('file');

        $allowed = array_merge(config('promofy.uploads.document_mimes'), config('promofy.uploads.image_mimes'), config('promofy.uploads.video_mimes'));
        $mime = $file->getMimeType();
        abort_unless(isset($allowed[$mime]), 422, 'This file type is not allowed.');

        $path = $file->storeAs("product-files/{$product->id}", Str::uuid().'.'.$allowed[$mime], 'local');
        $record = ProductFile::create([
            'product_id' => $product->id, 'disk' => 'local', 'path' => $path, 'original_name' => mb_substr($file->getClientOriginalName(), 0, 190),
            'mime' => $mime, 'size' => $file->getSize(), 'label' => $request->input('label'),
        ]);
        AuditLogger::record('product.file_added', $product, [], ['file' => $record->original_name]);

        return response()->json(['data' => $record], 201);
    }

    public function deleteFile(string $id, string $file): JsonResponse
    {
        $this->authorizeAction('edit');
        $product = $this->findRecord($id, 'all');
        $record = $product->files()->findOrFail($file);
        Storage::disk($record->disk)->delete($record->path);
        $record->delete();
        AuditLogger::record('product.file_removed', $product, [], ['file' => $record->original_name]);

        return response()->json(['data' => ['deleted' => true]]);
    }

    protected function option(Model $record): array
    {
        return ['value' => $record->id, 'label_ar' => $record->name_ar, 'label_en' => $record->name_en, 'sku' => $record->sku];
    }
}
