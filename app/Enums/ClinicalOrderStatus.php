<?php

namespace App\Enums;

enum ClinicalOrderStatus: string
{
    case Draft = 'draft';
    case Interpreted = 'interpreted';
    case Validated = 'validated';
    case QuotePrepared = 'quote_prepared';
    case CartPrepared = 'cart_prepared';
    case CheckoutStarted = 'checkout_started';
    case Completed = 'completed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Borrador',
            self::Interpreted => 'Interpretada',
            self::Validated => 'Validada',
            self::QuotePrepared => 'Cotización preparada',
            self::CartPrepared => 'Carrito preparado',
            self::CheckoutStarted => 'Checkout iniciado',
            self::Completed => 'Completada',
            self::Cancelled => 'Cancelada',
        };
    }

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Interpreted, self::Validated, self::Cancelled],
            self::Interpreted => [self::Validated, self::Cancelled],
            self::Validated => [self::QuotePrepared, self::CartPrepared, self::Cancelled],
            self::QuotePrepared => [self::CartPrepared, self::QuotePrepared, self::Cancelled],
            self::CartPrepared => [self::CheckoutStarted, self::QuotePrepared, self::CartPrepared, self::Cancelled],
            self::CheckoutStarted => [self::Completed, self::Cancelled],
            self::Completed, self::Cancelled => [],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }
}
