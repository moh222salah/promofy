<?php

namespace App\Models\Concerns;

use App\Models\Media;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Support\Facades\DB;

/**
 * Attaches media items to a record per collection ("main_image", "gallery"…) and keeps the
 * usage references in `mediables` so the media library can refuse to delete files still in use.
 */
trait HasMedia
{
    protected static function bootHasMedia(): void
    {
        static::deleted(function ($model) {
            if (! method_exists($model, 'isForceDeleting') || $model->isForceDeleting()) {
                DB::table('mediables')->where('mediable_type', $model->getMorphClass())->where('mediable_id', $model->getKey())->delete();
            }
        });
    }

    public function media(): MorphToMany
    {
        return $this->morphToMany(Media::class, 'mediable')->withPivot(['collection', 'sort_order'])->orderBy('mediables.sort_order');
    }

    /** @param  list<int>  $mediaIds ordered */
    public function syncMedia(string $collection, array $mediaIds): void
    {
        DB::table('mediables')
            ->where('mediable_type', $this->getMorphClass())->where('mediable_id', $this->getKey())
            ->where('collection', $collection)->delete();

        $rows = [];
        foreach (array_values(array_unique($mediaIds)) as $position => $mediaId) {
            $rows[] = [
                'media_id' => $mediaId, 'mediable_type' => $this->getMorphClass(), 'mediable_id' => $this->getKey(),
                'collection' => $collection, 'sort_order' => $position,
            ];
        }
        if ($rows) {
            DB::table('mediables')->insert($rows);
        }
        $this->unsetRelation('media');
    }

    /** @return \Illuminate\Support\Collection<int, Media> */
    public function mediaIn(string $collection)
    {
        return $this->media->filter(fn (Media $m) => $m->pivot->collection === $collection)->values();
    }
}
