<?php

namespace App\Enums;

enum PaymentAuthenticationAttemptStatus: string
{
    case Created = 'created';
    case Initiating = 'initiating';
    case ChallengeRequired = 'challenge_required';
    case Pending = 'pending';
    case Authenticated = 'authenticated';
    case Tokenizing = 'tokenizing';
    case Completed = 'completed';
    case Declined = 'declined';
    case Cancelled = 'cancelled';
    case Expired = 'expired';
    case TechnicalError = 'technical_error';
    case Unknown = 'unknown';
    case ProviderConfirmationPending = 'provider_confirmation_pending';
    case TokenizationConfirmationPending = 'tokenization_confirmation_pending';

    public static function activeValues(): array
    {
        return [
            self::Created->value,
            self::Initiating->value,
            self::ChallengeRequired->value,
            self::Pending->value,
            self::Authenticated->value,
            self::Tokenizing->value,
            self::Unknown->value,
            self::ProviderConfirmationPending->value,
            self::TokenizationConfirmationPending->value,
        ];
    }

    public static function terminalValues(): array
    {
        return [
            self::Completed->value,
            self::Declined->value,
            self::Cancelled->value,
            self::Expired->value,
            self::TechnicalError->value,
        ];
    }

    public static function recoverableTerminalValues(): array
    {
        return [
            self::Declined->value,
            self::Cancelled->value,
            self::Expired->value,
            self::TechnicalError->value,
        ];
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next->value, self::transitions()[$this->value] ?? [], true);
    }

    public static function transitions(): array
    {
        return [
            self::Created->value => [
                self::Initiating->value,
                self::Cancelled->value,
                self::Expired->value,
                self::TechnicalError->value,
            ],
            self::Initiating->value => [
                self::ChallengeRequired->value,
                self::Declined->value,
                self::Expired->value,
                self::TechnicalError->value,
                self::Unknown->value,
                self::ProviderConfirmationPending->value,
            ],
            self::ChallengeRequired->value => [
                self::Pending->value,
                self::Authenticated->value,
                self::Tokenizing->value,
                self::Completed->value,
                self::Declined->value,
                self::Cancelled->value,
                self::Expired->value,
                self::TechnicalError->value,
                self::ProviderConfirmationPending->value,
            ],
            self::Pending->value => [
                self::Authenticated->value,
                self::Tokenizing->value,
                self::Completed->value,
                self::Declined->value,
                self::Cancelled->value,
                self::Expired->value,
                self::TechnicalError->value,
                self::ProviderConfirmationPending->value,
            ],
            self::Authenticated->value => [
                self::Tokenizing->value,
                self::Completed->value,
                self::Expired->value,
                self::TechnicalError->value,
                self::TokenizationConfirmationPending->value,
            ],
            self::Tokenizing->value => [
                self::Completed->value,
                self::Expired->value,
                self::TechnicalError->value,
                self::TokenizationConfirmationPending->value,
            ],
            self::Unknown->value => [
                self::ProviderConfirmationPending->value,
                self::Expired->value,
                self::TechnicalError->value,
            ],
            self::ProviderConfirmationPending->value => [
                self::Expired->value,
                self::TechnicalError->value,
            ],
            self::TokenizationConfirmationPending->value => [
                self::Completed->value,
                self::Expired->value,
                self::TechnicalError->value,
            ],
        ];
    }
}
