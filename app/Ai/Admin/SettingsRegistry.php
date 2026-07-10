<?php

namespace App\Ai\Admin;

use App\Settings\AiSettings;
use App\Settings\TelegramSettings;
use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use Spatie\LaravelSettings\Settings;

/**
 * The single source of truth for which settings the admin assistant may see
 * and propose changes to: every spatie settings group of the application,
 * introspected by reflection so a newly added property is automatically
 * covered without touching the assistant. Secret-looking keys (tokens, API
 * keys, passwords) are masked on read and their values are never echoed back
 * in proposal summaries.
 */
class SettingsRegistry
{
    /**
     * Every settings group the assistant works with, keyed by group name.
     *
     * @return array<string, class-string<Settings>>
     */
    public static function groups(): array
    {
        return [
            AiSettings::group() => AiSettings::class,
            TelegramSettings::group() => TelegramSettings::class,
        ];
    }

    /**
     * The editable public properties of one group with their declared types.
     *
     * @return array<string, string> key => type name (bool|int|float|string|array)
     */
    public function keysFor(string $group): array
    {
        $class = static::groups()[$group] ?? null;

        if ($class === null) {
            return [];
        }

        $keys = [];

        foreach ((new ReflectionClass($class))->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
            if ($property->isStatic()) {
                continue;
            }

            $type = $property->getType();
            $keys[$property->getName()] = $type instanceof ReflectionNamedType ? $type->getName() : 'string';
        }

        return $keys;
    }

    /**
     * The current values of one group, secrets masked.
     *
     * @return array<string, mixed>
     */
    public function currentValues(string $group): array
    {
        $class = static::groups()[$group] ?? null;

        if ($class === null) {
            return [];
        }

        $settings = app($class);
        $values = [];

        foreach (array_keys($this->keysFor($group)) as $key) {
            $value = $settings->{$key};
            $values[$key] = $this->isSecretKey($key) ? $this->mask($value) : $value;
        }

        return $values;
    }

    /**
     * The raw (unmasked) current value of one key.
     */
    public function currentValue(string $group, string $key): mixed
    {
        $class = static::groups()[$group] ?? null;

        if ($class === null || ! array_key_exists($key, $this->keysFor($group))) {
            return null;
        }

        return app($class)->{$key};
    }

    /**
     * Whether the key holds a credential-like value that must never be shown
     * in full (bot tokens, API keys, …).
     */
    public function isSecretKey(string $key): bool
    {
        return preg_match('/token|secret|password|api_key|credential/i', $key) === 1;
    }

    /**
     * Mask a secret value, keeping only the last 4 characters visible.
     */
    public function mask(mixed $value): string
    {
        $text = is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_UNICODE);

        if ($text === false || $text === '') {
            return '••••';
        }

        return mb_strlen($text) <= 4 ? '••••' : '••••'.mb_substr($text, -4);
    }

    /**
     * Cast a proposed value (arriving from the model as a string) to the
     * key's declared type. Returns null when the value cannot represent the
     * type — the caller turns that into a validation refusal.
     *
     * @return array{value: mixed}|null wrapped so a legitimate false/0 survives
     */
    public function castValue(string $group, string $key, string $raw): ?array
    {
        $type = $this->keysFor($group)[$key] ?? null;

        if ($type === null) {
            return null;
        }

        $raw = trim($raw);

        return match ($type) {
            'bool' => match (mb_strtolower($raw)) {
                'true', '1', 'on', 'yes' => ['value' => true],
                'false', '0', 'off', 'no' => ['value' => false],
                default => null,
            },
            'int' => preg_match('/^-?\d+$/', $raw) === 1 ? ['value' => (int) $raw] : null,
            'float' => is_numeric($raw) ? ['value' => (float) $raw] : null,
            'string' => ['value' => $raw],
            'array' => $this->castArray($raw),
            default => null,
        };
    }

    /**
     * Apply one value to its settings class through spatie's saver.
     */
    public function apply(string $group, string $key, mixed $value): void
    {
        $class = static::groups()[$group];

        $settings = app($class);
        $settings->{$key} = $value;
        $settings->save();
    }

    /**
     * @return array{value: array<array-key, mixed>}|null
     */
    private function castArray(string $raw): ?array
    {
        $decoded = json_decode($raw, true);

        return is_array($decoded) ? ['value' => $decoded] : null;
    }
}
