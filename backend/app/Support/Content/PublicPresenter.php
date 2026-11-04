<?php

namespace App\Support\Content;

use Illuminate\Database\Eloquent\Model;

/** Shapes records for the public API: drops admin-only data and enforces consent / verification flags. */
final class PublicPresenter
{
    private const HIDDEN = ['deleted_at', 'status', 'created_at', 'author_id', 'consent_given', 'is_system', 'assigned_to', 'ip_address', 'user_agent'];

    /** @return array<string, mixed> */
    public static function present(Model $model, string $kind): array
    {
        $data = $model->toArray();

        foreach (self::HIDDEN as $key) {
            unset($data[$key]);
        }

        // Client names appear only where the client approved it; results only once verified.
        if (array_key_exists('client_approved', $data)) {
            if (! $data['client_approved']) {
                $data['client_name'] = null;
            }
            unset($data['client_approved']);
        }
        if (array_key_exists('results_verified', $data)) {
            if (! $data['results_verified']) {
                $data['results'] = [];
            }
            unset($data['results_verified']);
        }

        if ($model->relationLoaded('media')) {
            $map = [];
            foreach ($model->media as $m) {
                $map[$m->pivot->collection][] = self::media($m);
            }
            $data['media'] = (object) $map;
        }

        $data['kind'] = $kind;

        return $data;
    }

    /** Only display fields of a media item are public (never storage paths or uploader ids). */
    public static function media(\App\Models\Media $m): array
    {
        return $m->only(['id', 'kind', 'mime', 'url', 'urls', 'width', 'height', 'title', 'alt_ar', 'alt_en']);
    }
}
