<?php

namespace App\Services\Store;

use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Single place where stock changes, always with a movement row, inside the caller's transaction. */
class InventoryService
{
    public function change(Product $product, ?ProductVariant $variant, int $delta, string $reason, ?string $refType = null, ?int $refId = null, ?string $note = null, ?int $userId = null): int
    {
        $target = $variant
            ? ProductVariant::query()->lockForUpdate()->findOrFail($variant->id)
            : Product::query()->lockForUpdate()->findOrFail($product->id);

        $after = $target->stock_quantity + $delta;
        if ($after < 0 && ! $product->allow_backorder && $delta < 0) {
            throw ValidationException::withMessages(['stock' => ["Insufficient stock for {$product->name_en}."]]);
        }

        $before = $target->stock_quantity;
        $target->forceFill(['stock_quantity' => $after])->save();
        if ($delta < 0 && $product->track_stock && $before > $product->low_stock_threshold && $after <= $product->low_stock_threshold) {
            DB::afterCommit(fn () => \App\Services\Notifications\NotificationService::send('stock.low', ['name' => $product->name_en, 'quantity' => $after]));
        }
        InventoryMovement::create([
            'product_id' => $product->id, 'product_variant_id' => $variant?->id, 'change' => $delta, 'quantity_after' => $after,
            'reason' => $reason, 'reference_type' => $refType, 'reference_id' => $refId, 'note' => $note, 'user_id' => $userId,
        ]);

        return $after;
    }

    public function isLow(Product $product): bool
    {
        return $product->track_stock && ! $product->has_variants && $product->stock_quantity <= $product->low_stock_threshold;
    }
}
