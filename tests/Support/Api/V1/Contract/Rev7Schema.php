<?php

namespace Tests\Support\Api\V1\Contract;

/**
 * Minimal, strict validator for the rev7 OpenAPI component schemas used by Leo responses
 * (docs/Akubica/v1.3.0/akubica-openapi-v1.3.0-rc2.json, contract 2b6672e6). It supports exactly the keywords those
 * schemas use (type, required, properties, additionalProperties:false, items, $ref, const, enum, minimum,
 * minLength, pattern, format:date-time) and fails on any keyword it does not understand, so it can never pass silently.
 */
final class Rev7Schema
{
    private const KNOWN = ['type', 'required', 'properties', 'additionalProperties', 'items', '$ref', 'const', 'enum',
        'minimum', 'minLength', 'pattern', 'format', 'description', 'examples', 'example', 'anyOf', 'oneOf', 'maximum'];

    private static ?array $schemas = null;

    /** @return list<string> validation errors (empty when the value conforms) */
    public static function errors(mixed $value, string $component): array
    {
        $errors = [];
        self::check($value, self::component($component), '$', $errors);

        return $errors;
    }

    private static function component(string $name): array
    {
        if (self::$schemas === null) {
            $doc = json_decode(file_get_contents(dirname(__DIR__, 5).'/docs/Akubica/v1.3.0/akubica-openapi-v1.3.0-rc2.json'), true, 512, JSON_THROW_ON_ERROR);
            self::$schemas = $doc['components']['schemas'];
        }

        return self::$schemas[$name] ?? throw new \LogicException("Unknown rev7 schema {$name}");
    }

    private static function check(mixed $v, array $s, string $path, array &$errors): void
    {
        foreach (array_keys($s) as $keyword) {
            // x-* vendor annotations (e.g. x-implementation-status) carry no validation semantics.
            if (! in_array($keyword, self::KNOWN, true) && ! str_starts_with((string) $keyword, 'x-')) {
                throw new \LogicException("Unsupported schema keyword {$keyword} at {$path}");
            }
        }
        if (isset($s['$ref'])) {
            self::check($v, self::component(substr($s['$ref'], strlen('#/components/schemas/'))), $path, $errors);

            return;
        }
        if (isset($s['anyOf'])) {
            // anyOf: at least one branch must validate with no errors.
            foreach ($s['anyOf'] as $branch) {
                $branchErrors = [];
                self::check($v, $branch, $path, $branchErrors);
                if ($branchErrors === []) {
                    return;
                }
            }
            $errors[] = "{$path}: anyOf";

            return;
        }
        if (isset($s['oneOf'])) {
            // oneOf (A3, LeoInvoiceRequestStatus): exactly one branch must validate with no errors.
            $valid = 0;
            foreach ($s['oneOf'] as $branch) {
                $branchErrors = [];
                self::check($v, $branch, $path, $branchErrors);
                $valid += $branchErrors === [] ? 1 : 0;
            }
            if ($valid !== 1) {
                $errors[] = "{$path}: oneOf";
            }

            return;
        }
        if (isset($s['type']) && ! self::typeOk($v, (array) $s['type'])) {
            $errors[] = "{$path}: tipo ".get_debug_type($v).' no es '.implode('|', (array) $s['type']);

            return;
        }
        if (array_key_exists('const', $s) && $v !== $s['const']) {
            $errors[] = "{$path}: const";
        }
        if (isset($s['enum']) && ! in_array($v, $s['enum'], true)) {
            $errors[] = "{$path}: enum";
        }
        if (isset($s['minimum']) && is_int($v) && $v < $s['minimum']) {
            $errors[] = "{$path}: minimum";
        }
        if (isset($s['maximum']) && is_int($v) && $v > $s['maximum']) {
            $errors[] = "{$path}: maximum";
        }
        if (isset($s['minLength']) && is_string($v) && mb_strlen($v) < $s['minLength']) {
            $errors[] = "{$path}: minLength";
        }
        if (isset($s['pattern']) && is_string($v) && preg_match('/'.str_replace('/', '\/', $s['pattern']).'/u', $v) !== 1) {
            $errors[] = "{$path}: pattern";
        }
        if (($s['format'] ?? null) === 'date-time' && is_string($v) && \DateTimeImmutable::createFromFormat(\DateTimeInterface::ATOM, $v) === false) {
            $errors[] = "{$path}: date-time";
        }
        if (($s['format'] ?? null) === 'uuid' && is_string($v) && preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i', $v) !== 1) {
            $errors[] = "{$path}: uuid";
        }
        if (($s['format'] ?? null) === 'uri' && is_string($v) && filter_var($v, FILTER_VALIDATE_URL) === false) {
            $errors[] = "{$path}: uri";
        }
        if (is_array($v) && array_is_list($v) && isset($s['items'])) {
            foreach ($v as $i => $item) {
                self::check($item, $s['items'], "{$path}[{$i}]", $errors);
            }
        }
        if (is_array($v) && (! array_is_list($v) || $v === []) && isset($s['properties'])) {
            foreach ($s['required'] ?? [] as $key) {
                if (! array_key_exists($key, $v)) {
                    $errors[] = "{$path}.{$key}: requerido";
                }
            }
            foreach ($v as $key => $item) {
                if (! isset($s['properties'][$key])) {
                    if (($s['additionalProperties'] ?? true) === false) {
                        $errors[] = "{$path}.{$key}: campo no permitido";
                    }

                    continue;
                }
                self::check($item, $s['properties'][$key], "{$path}.{$key}", $errors);
            }
        }
    }

    private static function typeOk(mixed $v, array $types): bool
    {
        foreach ($types as $t) {
            $ok = match ($t) {
                'object' => is_array($v) && (! array_is_list($v) || $v === []),
                'array' => is_array($v) && array_is_list($v),
                'string' => is_string($v),
                'integer' => is_int($v),
                'boolean' => is_bool($v),
                'null' => $v === null,
                default => throw new \LogicException("Unsupported type {$t}"),
            };
            if ($ok) {
                return true;
            }
        }

        return false;
    }
}
