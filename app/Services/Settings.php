<?php

namespace App\Services;

use App\Models\Admin;
use App\Models\Setting;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Editable platform settings. Defaults and validation rules come from
 * config/settings.php; overrides are stored as JSON in the `settings` table.
 */
class Settings
{
    private const CACHE_KEY = 'platform.settings.v1';

    private ?array $values = null;

    /** @return array<string, array> group => definition */
    public function schema(): array
    {
        return config('settings', []);
    }

    /** @return array<string, array> key => field definition */
    public function fields(): array
    {
        $fields = [];
        foreach ($this->schema() as $group => $definition) {
            foreach ($definition['fields'] as $key => $field) {
                $fields[$key] = $field + ['group' => $group];
            }
        }

        return $fields;
    }

    public function all(): array
    {
        if ($this->values !== null) {
            return $this->values;
        }

        $defaults = array_map(fn (array $field) => $field['default'] ?? null, $this->fields());

        try {
            $overrides = Cache::rememberForever(self::CACHE_KEY, function () {
                return Setting::query()->pluck('value', 'key')
                    ->map(fn ($value) => json_decode((string) $value, true))
                    ->all();
            });
        } catch (Throwable $e) {
            // Database not migrated yet (installation) – run on defaults.
            Log::debug('Settings unavailable, using defaults: '.$e->getMessage());
            $overrides = [];
        }

        return $this->values = array_merge($defaults, array_intersect_key($overrides, $defaults));
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->all();

        return array_key_exists($key, $all) && $all[$key] !== null ? $all[$key] : $default;
    }

    public function bool(string $key): bool
    {
        return filter_var($this->get($key, false), FILTER_VALIDATE_BOOLEAN);
    }

    public function int(string $key, int $default = 0): int
    {
        return (int) $this->get($key, $default);
    }

    public function string(string $key, string $default = ''): string
    {
        return (string) $this->get($key, $default);
    }

    public function money(string $key): BigDecimal
    {
        return Money::of((string) $this->get($key, '0'));
    }

    public function array(string $key): array
    {
        $value = $this->get($key, []);

        return is_array($value) ? $value : [];
    }

    /**
     * Persist new values. Unknown keys are ignored. Returns the keys that changed
     * together with their old and new values (for the audit trail).
     */
    public function update(array $values, ?Admin $admin = null): array
    {
        $fields = $this->fields();
        $current = $this->all();
        $changes = [];

        foreach ($values as $key => $value) {
            if (! array_key_exists($key, $fields)) {
                continue;
            }

            if ($current[$key] === $value) {
                continue;
            }

            Setting::query()->updateOrCreate(
                ['key' => $key],
                ['value' => json_encode($value, JSON_UNESCAPED_UNICODE), 'updated_by' => $admin?->id]
            );

            $changes[$key] = ['from' => $current[$key], 'to' => $value];
        }

        $this->flush();

        return $changes;
    }

    public function flush(): void
    {
        Cache::forget(self::CACHE_KEY);
        $this->values = null;
    }

    public function timezone(): string
    {
        $tz = $this->string('app.timezone', 'UTC');

        return in_array($tz, timezone_identifiers_list(), true) ? $tz : 'UTC';
    }

    /** Start of the current business day, expressed in UTC for database queries. */
    public function startOfToday(): CarbonImmutable
    {
        return CarbonImmutable::now($this->timezone())->startOfDay()->utc();
    }

    public function todayKey(): string
    {
        return CarbonImmutable::now($this->timezone())->format('Y-m-d');
    }

    public function formatDate(?\DateTimeInterface $date): string
    {
        if ($date === null) {
            return '—';
        }

        return CarbonImmutable::instance($date)->setTimezone($this->timezone())->format($this->string('app.date_format', 'Y-m-d H:i'));
    }
}
