<?php

namespace App\Enums;

enum LaboratoryResultAiExplanationStatus: string
{
    case Pending = 'pending';
    case Generating = 'generating';
    case Ready = 'ready';
    case Failed = 'failed';
    case Invalid = 'invalid';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Generating],
            self::Generating => [self::Ready, self::Failed, self::Invalid],
            self::Ready => [],
            self::Failed => [self::Pending],
            self::Invalid => [self::Pending],
        };
    }

    public function canTransitionTo(self $next): bool
    {
        return in_array($next, $this->allowedTransitions(), true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Ready, self::Invalid], true);
    }

    public function isPatientVisible(): bool
    {
        return $this === self::Ready;
    }
}
