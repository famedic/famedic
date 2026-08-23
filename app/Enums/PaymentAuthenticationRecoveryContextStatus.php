<?php

namespace App\Enums;

enum PaymentAuthenticationRecoveryContextStatus: string
{
    case Open = 'open';
    case AuthenticationInProgress = 'authentication_in_progress';
    case CardVerified = 'card_verified';
    case RecoveryAvailable = 'recovery_available';
    case PaymentInProgress = 'payment_in_progress';
    case Recovered = 'recovered';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    /**
     * Statuses that may be reused on form refresh or a second tab.
     *
     * @return list<string>
     */
    public static function reusableValues(): array
    {
        return [
            self::Open->value,
            self::AuthenticationInProgress->value,
            self::RecoveryAvailable->value,
            self::CardVerified->value,
        ];
    }

    /**
     * Statuses that can accept a new authentication attempt.
     *
     * @return list<string>
     */
    public static function attachableValues(): array
    {
        return [
            self::Open->value,
            self::RecoveryAvailable->value,
            self::CardVerified->value,
        ];
    }

    /**
     * @return list<string>
     */
    public static function blockedRecoveryValues(): array
    {
        return [
            self::AuthenticationInProgress->value,
            self::PaymentInProgress->value,
        ];
    }

    /**
     * @return list<string>
     */
    public static function terminalValues(): array
    {
        return [
            self::Recovered->value,
            self::Cancelled->value,
            self::Expired->value,
        ];
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next->value, self::transitions()[$this->value] ?? [], true);
    }

    /**
     * @return array<string, list<string>>
     */
    public static function transitions(): array
    {
        return [
            self::Open->value => [
                self::AuthenticationInProgress->value,
                self::Cancelled->value,
                self::Expired->value,
            ],
            self::AuthenticationInProgress->value => [
                self::RecoveryAvailable->value,
                self::CardVerified->value,
                self::Cancelled->value,
                self::Expired->value,
            ],
            self::RecoveryAvailable->value => [
                self::AuthenticationInProgress->value,
                self::PaymentInProgress->value,
                self::CardVerified->value,
                self::Cancelled->value,
                self::Expired->value,
            ],
            self::CardVerified->value => [
                self::AuthenticationInProgress->value,
                self::PaymentInProgress->value,
                self::RecoveryAvailable->value,
                self::Recovered->value,
                self::Cancelled->value,
                self::Expired->value,
            ],
            self::PaymentInProgress->value => [
                self::Recovered->value,
                self::RecoveryAvailable->value,
                self::Cancelled->value,
                self::Expired->value,
            ],
        ];
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $status) => $status->value, self::cases());
    }
}
