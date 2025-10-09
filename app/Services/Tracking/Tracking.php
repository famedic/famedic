<?php

namespace App\Services\Tracking;

use App\Services\Tracking\Base;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use FacebookAds\Api;
use FacebookAds\Logger\CurlLogger;
use FacebookAds\Object\ServerSide\ActionSource;
use FacebookAds\Object\ServerSide\Content;
use FacebookAds\Object\ServerSide\CustomData;
use FacebookAds\Object\ServerSide\Event as FbEvent;
use FacebookAds\Object\ServerSide\EventRequest;
use FacebookAds\Object\ServerSide\UserData;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;

class Tracking
{
    public static function __callStatic($method, $args)
    {
        return app(self::class)->$method(...$args);
    }

    public function queueEvent(Base $event): void
    {
        session()->push('trackingEvents', $event);
    }

    public function propagateEvents(?array $events = null): void
    {
        $events ??= [];

        $pixelId = config('services.facebook.pixel_id');
        $token   = config('services.facebook.capi_token');

        if (!$pixelId || !$token || empty($events)) {
            return;
        }

        $fbEvents = collect($events)->map(function (Base $event) {
            return $this->buildFbEvent(
                $event->name,
                $event->id,
                $event->currency,
                $event->value,
                $event->contents,
                $event->contentType,
                $event->contentIds,
                $event->numItems,
                $event->searchString,
                $event->customProperties
            );
        })->all();

        $chunks = array_chunk($fbEvents, config('services.facebook.batch_size_limit', 10));
        $api = Api::init(null, null, $token);
        $api->setLogger(new CurlLogger());

        foreach ($chunks as $batch) {
            $eventRequest = (new EventRequest($pixelId))->setEvents($batch);
            if (filled(config('services.facebook.test_event_code'))) {
                $eventRequest->setTestEventCode(config('services.facebook.test_event_code'));
            }
            $this->executeRequest($eventRequest);
        }

        // Google Analytics future code goes here
    }

    protected function buildFbEvent(
        string $name,
        string $id,
        ?string $currency = null,
        float|int|string|null $value = null,
        ?array $contents = null,
        ?string $contentType = null,
        ?array $contentIds = null,
        ?int $numItems = null,
        ?string $searchString = null,
        ?array $customProperties = null
    ): FbEvent {
        $fbUserData = new UserData();
        $this->populateUserData($fbUserData);

        $fbCustomData = new CustomData();
        $this->populateCustomData(
            $fbCustomData,
            $currency,
            $value,
            $contents,
            $contentType,
            $contentIds,
            $numItems,
            $searchString,
            $customProperties
        );

        return (new FbEvent())
            ->setEventName($name)
            ->setEventTime(now()->timestamp)
            ->setEventId($id)
            ->setUserData($fbUserData)
            ->setCustomData($fbCustomData)
            ->setEventSourceUrl(request()->fullUrl())
            ->setActionSource(ActionSource::WEBSITE);
    }

    protected function populateUserData(UserData $userData): void
    {
        $userData->setClientIpAddress(request()->ip())
            ->setClientUserAgent(request()->userAgent());

        $user = Auth::user();
        if ($user) {
            if (filled($user->email)) {
                $clean = Str::of($user->email)->trim()->lower()->toString();
                $userData->setEmails([hash('sha256', $clean)]);
            }
            if (filled($user->name)) {
                $clean = Str::of($user->name)->trim()->lower()->toString();
                $userData->setFirstNames([hash('sha256', $clean)]);
            }
            $lastName = trim(($user->paternal_lastname ?? '') . ' ' . ($user->maternal_lastname ?? ''));
            if (filled($lastName)) {
                $clean = Str::of($lastName)->trim()->lower()->toString();
                $userData->setLastNames([hash('sha256', $clean)]);
            }
        }
    }

    protected function populateCustomData(
        CustomData $customData,
        ?string $currency = null,
        float|int|string|null $value = null,
        ?array $contents = null,
        ?string $contentType = null,
        ?array $contentIds = null,
        ?int $numItems = null,
        ?string $searchString = null,
        ?array $customProperties = null
    ): void {
        when(filled($contents) && is_array($contents), function () use ($contents, $customData) {
            $this->populateContents($customData, $contents);
        });

        when(filled($currency) && preg_match('/^[A-Z]{3}$/', strtoupper($currency)), function () use ($currency, $customData) {
            $customData->setCurrency(strtoupper($currency));
        });

        when(filled($value) && is_numeric($value) && floatval($value) >= 0, function () use ($value, $customData) {
            $customData->setValue(floatval($value));
        });

        when(filled($contentType), function () use ($contentType, $customData) {
            $customData->setContentType($contentType);
        });

        when(filled($contentIds) && is_array($contentIds), function () use ($contentIds, $customData) {
            // Convert all IDs to strings for consistency
            $customData->setContentIds(array_map('strval', $contentIds));
        });

        when(filled($numItems) && is_int($numItems) && $numItems > 0, function () use ($numItems, $customData) {
            $customData->setNumItems($numItems);
        });

        when(filled($searchString), function () use ($searchString, $customData) {
            $customData->setSearchString($searchString);
        });

        when(filled($customProperties) && is_array($customProperties), function () use ($customProperties, $customData) {
            $customData->setCustomProperties($customProperties);
        });
    }

    protected function populateContents(CustomData $customData, array $contents): void
    {
        $mappedContents = Collection::make($contents)
            ->map(function ($item) {
                $content = new Content();
                $content->setProductId($item['id']);
                $content->setQuantity($item['quantity']);
                return $content;
            })
            ->all();

        if ($mappedContents !== []) {
            $customData->setContents($mappedContents);
        }
    }

    private function executeRequest(EventRequest $request): void
    {
        try {
            $request->execute();
        } catch (\Throwable $e) {
            Log::warning('Facebook CAPI request failed', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);
        }
    }
}
