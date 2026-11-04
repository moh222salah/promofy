<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;

/** status = published and published_at in the past (a future date means "scheduled"). */
trait Publishable
{
    public function scopePublished(Builder $query): Builder
    {
        $table = $this->getTable();

        return $query->where("{$table}.status", 'published')
            ->where(fn (Builder $q) => $q->whereNull("{$table}.published_at")->orWhere("{$table}.published_at", '<=', now()));
    }

    public function isLive(): bool
    {
        return $this->status === 'published' && ($this->published_at === null || $this->published_at->isPast());
    }
}
