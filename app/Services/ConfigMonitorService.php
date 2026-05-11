<?php

namespace App\Services;

use App\Models\Setting;
use App\Models\SettingGroup;
use Dotenv\Dotenv;
use Illuminate\Support\Facades\Config;

class ConfigMonitorService
{
    /** @var array<string, string>|null */
    private static ?array $dotEnvCache = null;

    /**
     * @return array<string, string>
     */
    public function getDotEnvReferenceMap(): array
    {
        if (self::$dotEnvCache !== null) {
            return self::$dotEnvCache;
        }

        $path = base_path('.env');
        if (! is_readable($path)) {
            return self::$dotEnvCache = [];
        }

        try {
            $parsed = Dotenv::parse((string) file_get_contents($path));

            return self::$dotEnvCache = is_array($parsed) ? $parsed : [];
        } catch (\Throwable) {
            return self::$dotEnvCache = [];
        }
    }

    public function clearDotEnvCache(): void
    {
        self::$dotEnvCache = null;
    }

    /**
     * @return array{
     *   configuration_cached: bool,
     *   dotenv_file_loaded: bool,
     *   summary: array<string, int>,
     *   groups: array<int, array<string, mixed>>,
     *   env_file_coverage: array{ count: int, rows: array<int, array<string, mixed>> }
     * }
     */
    public function buildReport(): array
    {
        $configurationCached = app()->configurationIsCached();
        $dotenvMap = $this->getDotEnvReferenceMap();

        $summary = [
            'ok' => 0,
            'warning' => 0,
            'critical' => 0,
            'mismatch' => 0,
            'cache_issue' => 0,
            'sin_mapeo' => 0,
        ];

        $groups = SettingGroup::query()
            ->orderBy('sort_order')
            ->orderBy('name')
            ->with('settings')
            ->get()
            ->map(function (SettingGroup $group) use ($dotenvMap, $configurationCached, &$summary) {
                $rows = $group->settings->map(function (Setting $setting) use ($dotenvMap, $configurationCached, &$summary) {
                    $row = $this->evaluateRow($setting, $dotenvMap, $configurationCached);
                    $summary[$row['status']] = ($summary[$row['status']] ?? 0) + 1;

                    return $row;
                })->values()->all();

                return [
                    'id' => $group->id,
                    'name' => $group->name,
                    'slug' => $group->slug,
                    'rows' => $rows,
                ];
            })->values()->all();

        $registeredKeys = Setting::query()->pluck('env_key')->all();
        $registeredSet = array_fill_keys($registeredKeys, true);
        $aliases = config('config_monitor.env_to_config', []);

        $coverageRows = [];
        foreach (array_keys($dotenvMap) as $envKey) {
            if (isset($registeredSet[$envKey])) {
                continue;
            }
            $configPath = $aliases[$envKey] ?? null;
            $sensitive = $this->inferSensitiveFromEnvKey($envKey);

            if (is_string($configPath) && $configPath !== '') {
                $synthetic = new Setting([
                    'env_key' => $envKey,
                    'config_key' => $configPath,
                    'is_sensitive' => $sensitive,
                    'is_required' => false,
                    'label' => null,
                    'description' => null,
                ]);
                $row = $this->evaluateRow($synthetic, $dotenvMap, $configurationCached);
                $row['id'] = 'auto:'.$envKey;
                $row['source'] = 'env_alias';
                $summary[$row['status']] = ($summary[$row['status']] ?? 0) + 1;
                $coverageRows[] = $row;
            } else {
                $row = $this->evaluateEnvOnlyRow($envKey, $dotenvMap);
                $summary['sin_mapeo'] = ($summary['sin_mapeo'] ?? 0) + 1;
                $coverageRows[] = $row;
            }
        }

        usort($coverageRows, static fn (array $a, array $b): int => strcmp($a['env_key'], $b['env_key']));

        return [
            'configuration_cached' => $configurationCached,
            'dotenv_file_loaded' => $dotenvMap !== [],
            'summary' => $summary,
            'groups' => $groups,
            'env_file_coverage' => [
                'count' => count($coverageRows),
                'rows' => $coverageRows,
            ],
        ];
    }

    /**
     * Claves ENV que parecen secretos (cuando no vienen de metadatos).
     */
    public function inferSensitiveFromEnvKey(string $key): bool
    {
        foreach (config('config_monitor.sensitive_patterns', []) as $pattern) {
            if (@preg_match($pattern, $key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, string>  $dotenvMap
     * @return array<string, mixed>
     */
    private function evaluateEnvOnlyRow(string $envKey, array $dotenvMap): array
    {
        $sensitive = $this->inferSensitiveFromEnvKey($envKey);
        $envFromFile = $dotenvMap[$envKey] ?? null;

        return [
            'id' => 'env:'.$envKey,
            'label' => $envKey,
            'description' => null,
            'env_key' => $envKey,
            'config_key' => '—',
            'is_sensitive' => $sensitive,
            'is_required' => false,
            'config_display' => null,
            'env_display' => $this->displayEnvReference($envFromFile, $sensitive),
            'config_missing' => true,
            'status' => 'sin_mapeo',
            'notes' => [
                'Presente en .env pero sin mapeo a config(). Añade la clave en Metadatos o en config/config_monitor.php (env_to_config).',
            ],
            'source' => 'env_only',
        ];
    }

    /**
     * @param  array<string, string>  $dotenvMap
     * @return array<string, mixed>
     */
    public function evaluateRow(Setting $setting, array $dotenvMap, bool $configurationCached): array
    {
        $envKey = $setting->env_key;
        $configKey = $setting->config_key;

        $rawConfig = Config::has($configKey) ? Config::get($configKey) : null;
        $configMissing = ! Config::has($configKey);

        $envFromFile = $dotenvMap[$envKey] ?? null;
        $envKeyPresentInFile = array_key_exists($envKey, $dotenvMap);

        $configDisplay = $this->displayValue($rawConfig, (bool) $setting->is_sensitive);
        $envDisplay = $this->displayEnvReference($envFromFile, (bool) $setting->is_sensitive);

        $configComparable = $this->stringifyForCompare($rawConfig);
        $envComparable = $this->normalizeEnvComparable($envFromFile, $rawConfig);

        $bothComparable = $envKeyPresentInFile
            && ! $configMissing
            && ! $this->isEffectivelyEmpty($rawConfig)
            && $envComparable !== null
            && $configComparable !== null;

        $differs = $bothComparable && $configComparable !== $envComparable;

        $status = 'ok';
        $notes = [];

        if ($configMissing) {
            $status = $setting->is_required ? 'critical' : 'warning';
            $notes[] = 'La clave config() no está definida.';
        } elseif ($this->isEffectivelyEmpty($rawConfig)) {
            if ($setting->is_required) {
                $status = 'critical';
                $notes[] = 'Valor vacío en config().';
            } else {
                $status = 'warning';
                $notes[] = 'Valor vacío en config().';
            }
        }

        if ($envKeyPresentInFile && $this->isEffectivelyEmptyString($envFromFile) && $setting->is_required) {
            if ($status === 'ok') {
                $status = 'critical';
            }
            $notes[] = 'Variable vacía en el archivo .env de referencia.';
        }

        if (! $envKeyPresentInFile && $setting->is_required) {
            if ($status === 'ok' || $status === 'warning') {
                $status = 'critical';
            }
            $notes[] = 'No aparece en el archivo .env leído (puede existir solo en el entorno del servidor).';
        }

        if ($differs && $status !== 'critical') {
            if ($configurationCached) {
                $status = 'cache_issue';
                $notes[] = 'Diferencia entre config() y .env; con configuración en caché, ejecuta php artisan config:clear (o config:cache) tras cambiar .env.';
            } else {
                $status = 'mismatch';
                $notes[] = 'config() y referencia .env difieren.';
            }
        }

        $notes = array_values(array_unique($notes));

        return [
            'id' => $setting->id,
            'label' => $setting->label ?? $envKey,
            'description' => $setting->description,
            'env_key' => $envKey,
            'config_key' => $configKey,
            'is_sensitive' => (bool) $setting->is_sensitive,
            'is_required' => (bool) $setting->is_required,
            'config_display' => $configDisplay,
            'env_display' => $envDisplay,
            'config_missing' => $configMissing,
            'status' => $status,
            'notes' => $notes,
        ];
    }

    private function displayValue(mixed $value, bool $sensitive): ?string
    {
        if ($value === null) {
            return null;
        }
        if ($sensitive) {
            return '••••••••';
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) $value;
    }

    private function displayEnvReference(?string $value, bool $sensitive): ?string
    {
        if ($value === null) {
            return null;
        }
        if ($sensitive) {
            return '••••••••';
        }

        return $value;
    }

    private function stringifyForCompare(mixed $configValue): ?string
    {
        if ($configValue === null) {
            return null;
        }
        if (is_array($configValue)) {
            $flat = array_map(static fn ($v) => is_scalar($v) ? (string) $v : json_encode($v), $configValue);

            return implode(',', $flat);
        }
        if (is_bool($configValue)) {
            return $configValue ? '1' : '0';
        }

        return trim((string) $configValue);
    }

    private function normalizeEnvComparable(?string $envValue, mixed $configValue): ?string
    {
        if ($envValue === null) {
            return null;
        }
        $trimmed = trim($envValue);
        if ($trimmed === '') {
            return '';
        }

        if (is_array($configValue)) {
            $parts = array_map('trim', explode(',', $trimmed));

            return implode(',', $parts);
        }

        return $trimmed;
    }

    private function isEffectivelyEmpty(mixed $value): bool
    {
        if ($value === null) {
            return true;
        }
        if (is_string($value) && trim($value) === '') {
            return true;
        }
        if (is_array($value) && $value === []) {
            return true;
        }

        return false;
    }

    private function isEffectivelyEmptyString(?string $value): bool
    {
        return $value === null || trim($value) === '';
    }
}
