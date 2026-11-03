<?php

namespace App\Models\Concerns;

use App\Support\Audit\AuditLogger;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Records created / updated / deleted / restored events of a model in the audit trail.
 *
 * Hidden attributes (passwords, tokens…) and anything listed in $auditExclude are never logged.
 */
trait Auditable
{
    protected static function bootAuditable(): void
    {
        static::created(function (Model $model) {
            AuditLogger::record(static::auditEventName($model, 'created'), $model, [], $model->auditCreatedValues());
        });

        static::updated(function (Model $model) {
            $changed = array_diff(array_keys($model->getChanges()), $model->auditExcludedAttributes());
            if ($changed === []) {
                return;
            }

            $old = [];
            $new = [];
            foreach ($changed as $key) {
                $old[$key] = $model->getOriginal($key);
                $new[$key] = $model->getAttribute($key);
            }

            AuditLogger::record(static::auditEventName($model, 'updated'), $model, $old, $new);
        });

        static::deleted(function (Model $model) {
            AuditLogger::record(static::auditEventName($model, 'deleted'), $model, $model->auditCreatedValues());
        });

        if (in_array(SoftDeletes::class, class_uses_recursive(static::class), true)) {
            static::restored(function (Model $model) {
                AuditLogger::record(static::auditEventName($model, 'restored'), $model);
            });
        }
    }

    protected static function auditEventName(Model $model, string $action): string
    {
        return $model->getMorphClass().'.'.$action;
    }

    /** @return list<string> */
    public function auditExcludedAttributes(): array
    {
        return array_values(array_unique(array_merge(
            $this->getHidden(),
            $this->auditExclude ?? [],
            ['updated_at', 'created_at', 'deleted_at'],
        )));
    }

    /** @return array<string, mixed> */
    protected function auditCreatedValues(): array
    {
        return array_diff_key($this->attributesToArray(), array_flip($this->auditExcludedAttributes()));
    }
}
