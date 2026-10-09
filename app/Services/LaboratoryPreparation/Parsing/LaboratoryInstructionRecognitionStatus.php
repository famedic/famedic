<?php

namespace App\Services\LaboratoryPreparation\Parsing;

final class LaboratoryInstructionRecognitionStatus
{
    public const RECOGNIZED = 'recognized';

    public const PARTIAL = 'partial';

    public const AMBIGUOUS = 'ambiguous';

    public const UNRECOGNIZED = 'unrecognized';

    public const INCOMPLETE = 'incomplete';
}
