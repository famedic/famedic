<?php

namespace Tests\Support\Postman;

/**
 * Espejo de scripts/extract-rc1-phase-a-state.py para pruebas en Docker (sin python3).
 */
final class NewmanResponseBodyDecoder
{
    /**
     * @param  array<string, mixed>|mixed  $response
     * @return array<string, mixed>|null
     */
    public static function decodeResponseJson(mixed $response): ?array
    {
        if (! is_array($response)) {
            return null;
        }

        $body = $response['body'] ?? null;
        if (is_array($body)) {
            return $body;
        }
        if (is_string($body) && trim($body) !== '') {
            $parsed = json_decode($body, true);

            return is_array($parsed) ? $parsed : null;
        }

        $stream = $response['stream'] ?? null;
        if (is_string($stream) && trim($stream) !== '') {
            $parsed = json_decode($stream, true);

            return is_array($parsed) ? $parsed : null;
        }

        if (is_array($stream)) {
            $data = $stream['data'] ?? null;
            if (($stream['type'] ?? null) === 'Buffer' && is_array($data)) {
                $bytes = array_map(static fn ($b) => chr((int) $b & 0xFF), $data);
                $text = implode('', $bytes);
                $parsed = json_decode($text, true);

                return is_array($parsed) ? $parsed : null;
            }
            if (is_array($data)) {
                return $data;
            }
            if (is_string($data) && trim($data) !== '') {
                $parsed = json_decode($data, true);

                return is_array($parsed) ? $parsed : null;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $rawReport
     * @return array<string, string>
     */
    public static function extractPhaseAState(array $rawReport): array
    {
        $executions = $rawReport['run']['executions'] ?? [];
        $state = [];

        foreach ($executions as $run) {
            if (! is_array($run)) {
                continue;
            }
            $name = (string) ($run['item']['name'] ?? '');
            $bodyRaw = $run['request']['body']['raw'] ?? '';
            if (is_string($bodyRaw) && str_contains($bodyRaw, 'conversation_id') && ! str_contains($bodyRaw, 'challenge_id')) {
                $payload = json_decode($bodyRaw, true);
                if (is_array($payload)) {
                    if (! empty($payload['conversation_id']) && is_string($payload['conversation_id'])) {
                        $state['conversation_id'] = trim($payload['conversation_id']);
                    }
                    if (! empty($payload['channel_event_id']) && is_string($payload['channel_event_id'])) {
                        $state['channel_event_id'] = trim($payload['channel_event_id']);
                    }
                }
            }

            $nameLower = strtolower($name);
            if (str_contains($nameLower, 'request-code') || str_contains($nameLower, 'authloginrequestcode')) {
                $response = is_array($run['response'] ?? null) ? $run['response'] : [];
                if (($response['code'] ?? null) === 202) {
                    $body = self::decodeResponseJson($response);
                    $challengeId = $body['data']['challenge_id'] ?? null;
                    if (is_string($challengeId) && trim($challengeId) !== '') {
                        $state['challenge_id'] = trim($challengeId);
                    }
                }
            }
        }

        return $state;
    }
}
