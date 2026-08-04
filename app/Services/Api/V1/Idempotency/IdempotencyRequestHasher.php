<?php

namespace App\Services\Api\V1\Idempotency;

use Illuminate\Http\Request;

/**
 * Stable request fingerprint for idempotency scope comparison.
 *
 * Does not include Authorization, X-Correlation-ID, or Idempotency-Key.
 * Does not persist the canonical payload.
 */
final class IdempotencyRequestHasher
{
    public function hash(Request $request): string
    {
        $route = $request->route();
        $pathPattern = $this->normalizedPath($request);
        $routeParams = [];

        if ($route !== null) {
            /** @var array<string, mixed> $params */
            $params = $route->parameters();
            ksort($params);
            foreach ($params as $name => $value) {
                if (is_object($value) && method_exists($value, 'getKey')) {
                    $routeParams[$name] = $value->getKey();
                } elseif (is_scalar($value) || $value === null) {
                    $routeParams[$name] = $value;
                } else {
                    $routeParams[$name] = (string) $value;
                }
            }
        }

        $payload = $this->canonicalJson($request->all());

        $material = json_encode([
            'api' => 'v1',
            'method' => strtoupper($request->getMethod()),
            'path' => $pathPattern,
            'route_params' => $routeParams,
            'payload' => $payload,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return hash('sha256', is_string($material) ? $material : '');
    }

    public function normalizedPath(Request $request): string
    {
        $route = $request->route();
        if ($route !== null) {
            $uri = ltrim((string) $route->uri(), '/');

            // Route::uri() may already include the framework "api" prefix depending
            // on how the route group was registered — never double it.
            if (str_starts_with($uri, 'api/')) {
                return $uri;
            }

            return 'api/'.$uri;
        }

        $path = ltrim($request->path(), '/');
        if (str_starts_with($path, 'api/')) {
            return $path;
        }

        return 'api/'.$path;
    }

    /**
     * @param  array<mixed>  $data
     * @return array<mixed>|list<mixed>
     */
    public function canonicalJson(array $data): array
    {
        return $this->canonicalize($data);
    }

    /**
     * @param  mixed  $value
     * @return mixed
     */
    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }

        if ($value === []) {
            return [];
        }

        $isList = array_is_list($value);

        if ($isList) {
            $out = [];
            foreach ($value as $item) {
                $out[] = $this->canonicalize($item);
            }

            return $out;
        }

        ksort($value);
        $out = [];
        foreach ($value as $key => $item) {
            $out[(string) $key] = $this->canonicalize($item);
        }

        return $out;
    }
}
