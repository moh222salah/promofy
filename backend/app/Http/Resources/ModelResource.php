<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Default resource used by the generic CRUD controllers: the model's visible attributes plus any
 * relations that were eager loaded. Dedicated resources override this where the shape must differ.
 */
class ModelResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return $this->resource->toArray();
    }
}
