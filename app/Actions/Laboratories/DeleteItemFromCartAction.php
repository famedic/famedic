<?php

namespace App\Actions\Laboratories;

use App\Models\LaboratoryCartItem;

class DeleteItemFromCartAction
{
    public function __invoke(LaboratoryCartItem $laboratoryCartItem)
    {
        $laboratoryCartItem->delete();
    }
}
