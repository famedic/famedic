<?php

namespace App\Support\Odessa;

class OdessaApiErrorFormatter
{
    public static function summarize(string $message): string
    {
        $decoded = json_decode($message, true);

        if (json_last_error() !== JSON_ERROR_NONE || ! is_array($decoded)) {
            return $message;
        }

        if (isset($decoded['message']) && is_string($decoded['message']) && $decoded['message'] !== '') {
            return $decoded['message'];
        }

        $response = $decoded['response'] ?? null;

        if (! is_array($response)) {
            return $message;
        }

        if (isset($response['message']) && is_string($response['message']) && $response['message'] !== '') {
            return $response['message'];
        }

        if (isset($response['chrMessage']) && is_string($response['chrMessage']) && $response['chrMessage'] !== '') {
            return $response['chrMessage'];
        }

        if (array_is_list($response)) {
            foreach ($response as $envelope) {
                if (! is_array($envelope)) {
                    continue;
                }

                if (isset($envelope['chrMessage']) && is_string($envelope['chrMessage']) && $envelope['chrMessage'] !== '') {
                    return $envelope['chrMessage'];
                }
            }
        }

        if (isset($response['errorCode'])) {
            return sprintf('errorCode %s', (string) $response['errorCode']);
        }

        return $message;
    }
}
