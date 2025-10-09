<?php

namespace App\Services\Tracking;

class CompleteRegistration extends Base
{
    public static function track(): void
    {
        $event = new self();

        $event->queue();
    }

    public function __construct()
    {
        $this->name = 'CompleteRegistration';

        $this->id = $this->generateEventId(
            hashData: [],
            appendTimestampToIdHash: true
        );
    }
}
