<?php

namespace Tests\Support\Http;

final class OfflineHttpFactory extends \Illuminate\Http\Client\Factory
{
    protected function newPendingRequest()
    {
        return parent::newPendingRequest()->setHandler(static function () {
            throw new \LogicException('Unstubbed network forbidden in isolated tests');
        });
    }
}
