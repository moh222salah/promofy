<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

#[Fillable(['title', 'alt_ar', 'alt_en', 'category'])]
class Media extends Model
{
    use Auditable;

    protected $table = 'media';

    protected $appends = ['url', 'urls'];

    protected array $auditExclude = ['url', 'urls', 'variants', 'checksum'];

    protected function casts(): array
    {
        return ['variants' => 'array', 'size' => 'integer', 'width' => 'integer', 'height' => 'integer'];
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function getUrlAttribute(): ?string
    {
        return $this->disk === 'public' ? Storage::disk('public')->url($this->path) : null;
    }

    /** @return array<string, string> variant name => public URL */
    public function getUrlsAttribute(): array
    {
        if ($this->disk !== 'public') {
            return [];
        }

        return collect($this->variants ?? [])->map(fn (string $path) => Storage::disk('public')->url($path))->all();
    }

    /** @return list<array{type: string, id: int, collection: string}> */
    public function usages(): array
    {
        return DB::table('mediables')->where('media_id', $this->id)
            ->get(['mediable_type as type', 'mediable_id as id', 'collection'])
            ->map(fn ($r) => (array) $r)->all();
    }

    public function isInUse(): bool
    {
        return DB::table('mediables')->where('media_id', $this->id)->exists();
    }
}
