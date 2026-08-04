<?php

namespace App\Services\Api\V1\Audit;

use App\Support\Api\V1\AkubicaCorrelationId;
use Illuminate\Http\Request;

/**
 * Per-request audit context. Bound to the Request attributes — never static.
 *
 * Does not write events. Hydrated by InitializeApiV1AuditContext middleware
 * (when attached) or lazily via fromRequest() / ensureFromRequest().
 */
final class ApiV1AuditContext
{
    public const REQUEST_ATTRIBUTE = 'akubica.audit_context';

    private ?string $correlationId = null;

    private ?string $routeName = null;

    private ?string $method = null;

    private ?AuditActor $actor = null;

    private ?int $idempotencyRecordId = null;

    private ?string $idempotencyEffect = null;

    private ?string $relatedCorrelationId = null;

    private bool $terminalEventEmitted = false;

    public function correlationId(): ?string
    {
        return $this->correlationId;
    }

    public function setCorrelationId(?string $correlationId): void
    {
        $this->correlationId = is_string($correlationId) && $correlationId !== ''
            ? $correlationId
            : null;
    }

    public function routeName(): ?string
    {
        return $this->routeName;
    }

    public function setRouteName(?string $routeName): void
    {
        $this->routeName = is_string($routeName) && $routeName !== ''
            ? mb_substr($routeName, 0, 128)
            : null;
    }

    public function method(): ?string
    {
        return $this->method;
    }

    public function setMethod(?string $method): void
    {
        $this->method = is_string($method) && $method !== ''
            ? strtoupper(mb_substr($method, 0, 10))
            : null;
    }

    public function actor(): ?AuditActor
    {
        return $this->actor;
    }

    public function setActor(?AuditActor $actor): void
    {
        $this->actor = $actor;
    }

    public function idempotencyRecordId(): ?int
    {
        return $this->idempotencyRecordId;
    }

    public function setIdempotencyRecordId(?int $idempotencyRecordId): void
    {
        $this->idempotencyRecordId = $idempotencyRecordId;
    }

    public function idempotencyEffect(): ?string
    {
        return $this->idempotencyEffect;
    }

    public function setIdempotencyEffect(?string $idempotencyEffect): void
    {
        $this->idempotencyEffect = is_string($idempotencyEffect) && $idempotencyEffect !== ''
            ? mb_substr($idempotencyEffect, 0, 24)
            : null;
    }

    public function relatedCorrelationId(): ?string
    {
        return $this->relatedCorrelationId;
    }

    public function setRelatedCorrelationId(?string $relatedCorrelationId): void
    {
        $this->relatedCorrelationId = is_string($relatedCorrelationId) && $relatedCorrelationId !== ''
            ? mb_substr($relatedCorrelationId, 0, 128)
            : null;
    }

    public function terminalEventEmitted(): bool
    {
        return $this->terminalEventEmitted;
    }

    public function markTerminalEventEmitted(): void
    {
        $this->terminalEventEmitted = true;
    }

    /**
     * Bind (or return existing) context on the request. Safe across tests —
     * state lives only on this Request instance.
     */
    public static function fromRequest(Request $request): self
    {
        $existing = $request->attributes->get(self::REQUEST_ATTRIBUTE);
        if ($existing instanceof self) {
            return $existing;
        }

        $context = new self;
        $context->hydrateFromRequest($request);
        $request->attributes->set(self::REQUEST_ATTRIBUTE, $context);

        return $context;
    }

    /**
     * Current request context when available; null outside HTTP.
     */
    public static function current(): ?self
    {
        $request = request();

        if (! $request instanceof Request) {
            return null;
        }

        $existing = $request->attributes->get(self::REQUEST_ATTRIBUTE);

        return $existing instanceof self ? $existing : null;
    }

    /**
     * Return current context or create one from the active request.
     */
    public static function currentOrCreate(): self
    {
        $current = self::current();
        if ($current !== null) {
            return $current;
        }

        $request = request();
        if ($request instanceof Request) {
            return self::fromRequest($request);
        }

        return new self;
    }

    public function hydrateFromRequest(Request $request): void
    {
        if ($this->correlationId === null) {
            $bound = $request->attributes->get(AkubicaCorrelationId::REQUEST_ATTRIBUTE);
            if (is_string($bound) && $bound !== '') {
                $this->setCorrelationId($bound);
            } else {
                $this->setCorrelationId(AkubicaCorrelationId::fromRequest($request));
            }
        }

        if ($this->method === null) {
            $this->setMethod($request->getMethod());
        }

        if ($this->routeName === null) {
            $name = $request->route()?->getName();
            $this->setRouteName(is_string($name) ? $name : null);
        }
    }
}
