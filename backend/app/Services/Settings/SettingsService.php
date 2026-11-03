<?php

namespace App\Services\Settings;

use App\Models\Setting;
use App\Support\Audit\AuditLogger;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class SettingsService
{
    public const MASK = '********';

    private const CACHE_KEY = 'settings.all';

    /** @return array<string, mixed> the whole schema */
    public function schema(): array
    {
        return config('settings');
    }

    /** @return array<string, mixed> single stored-or-default value */
    public function get(string $group, string $key, mixed $default = null): mixed
    {
        $all = $this->all();
        if (array_key_exists("{$group}.{$key}", $all)) {
            return $all["{$group}.{$key}"];
        }

        return $this->schema()[$group]['keys'][$key]['default'] ?? $default;
    }

    /**
     * @return array<string, mixed> key => value (defaults filled in) for one group
     */
    public function group(string $group, bool $publicOnly = false, bool $maskSecrets = true): array
    {
        $out = [];
        foreach ($this->schema()[$group]['keys'] ?? [] as $key => $def) {
            if ($publicOnly && empty($def['public'])) {
                continue;
            }
            $value = $this->get($group, $key);
            if (($def['type'] ?? '') === 'secret' && $maskSecrets) {
                $value = $value ? self::MASK : null;
            }
            $out[$key] = $value;
        }

        return $out;
    }

    /** @return array<string, array<string, mixed>> every public setting, grouped */
    public function publicSettings(): array
    {
        $out = [];
        foreach (array_keys($this->schema()) as $group) {
            $values = $this->group($group, true);
            if ($values) {
                $out[$group] = $values;
            }
        }

        return $out;
    }

    /**
     * Validate against the schema and persist. Unknown keys are rejected; a masked secret is ignored.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function update(string $group, array $values, ?int $userId = null): array
    {
        $keys = $this->schema()[$group]['keys'] ?? abort(404);
        $rules = [];
        foreach ($keys as $key => $def) {
            $rules[$key] = $this->rulesFor($def);
        }
        $unknown = array_diff(array_keys($values), array_keys($keys));
        abort_if($unknown !== [], 422, 'Unknown setting keys: '.implode(', ', $unknown));

        $data = Validator::make($values, array_intersect_key($rules, $values))->validate();

        $changed = [];
        foreach ($data as $key => $value) {
            $isSecret = ($keys[$key]['type'] ?? '') === 'secret';
            if ($isSecret && $value === self::MASK) {
                continue;
            }
            $stored = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $row = Setting::query()->firstOrNew(['group' => $group, 'key' => $key]);
            $before = $row->exists ? $this->decode($row) : ($keys[$key]['default'] ?? null);
            $row->forceFill([
                'value' => $isSecret ? Crypt::encryptString($stored) : $stored,
                'is_secret' => $isSecret,
                'updated_by' => $userId,
            ])->save();
            $changed[$key] = $isSecret ? ['changed' => true] : ['from' => $before, 'to' => $value];
        }

        Cache::forget(self::CACHE_KEY);
        if ($changed) {
            AuditLogger::record('settings.updated', null, [], [], ['group' => $group, 'changes' => $changed]);
        }

        return $this->group($group);
    }

    /** @return array<string, mixed> "group.key" => decoded value */
    private function all(): array
    {
        return Cache::rememberForever(self::CACHE_KEY, function () {
            $out = [];
            foreach (Setting::all() as $row) {
                $out["{$row->group}.{$row->key}"] = $this->decode($row);
            }

            return $out;
        });
    }

    private function decode(Setting $row): mixed
    {
        $raw = $row->is_secret && $row->value ? Crypt::decryptString($row->value) : $row->value;

        return $raw === null ? null : json_decode($raw, true);
    }

    /** @param  array<string, mixed>  $def */
    private function rulesFor(array $def): array
    {
        return match ($def['type']) {
            'bool' => ['nullable', 'boolean'],
            'int' => ['nullable', 'integer'],
            'decimal' => ['nullable', 'numeric', 'min:0', 'max:100'],
            'url' => ['nullable', 'url', 'max:500'],
            'email' => ['nullable', 'email', 'max:255'],
            'color' => ['nullable', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'bilingual' => ['nullable', 'array'],
            'json' => ['nullable', 'array'],
            'text' => ['nullable', 'string', 'max:5000'],
            default => ['nullable', 'string', 'max:500'],
        };
    }
}
