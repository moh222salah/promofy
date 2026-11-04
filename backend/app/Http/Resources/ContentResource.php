<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** Model attributes plus attached media collapsed into a {collection: [media…]} map. */
class ContentResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        $data = $this->resource->toArray();

        if ($this->resource->relationLoaded('media')) {
            $map = [];
            foreach ($this->resource->media as $media) {
                $item = $media->toArray();
                unset($item['pivot']);
                $map[$media->pivot->collection][] = $item;
            }
            $data['media'] = (object) $map;
        }

        return $data;
    }
}
