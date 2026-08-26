<?php

use App\Http\Requests\EfevooPay\SearchTransactionsRequest;
use Illuminate\Support\Facades\Validator;

it('does not claim order id reconciliation support for search transactions', function () {
    $rules = (new SearchTransactionsRequest)->rules();

    expect($rules)->toHaveKey('transaction_id')
        ->and($rules)->not->toHaveKey('order_id')
        ->and($rules)->not->toHaveKey('merchant_reference');

    $validator = Validator::make(['order_id' => '31144'], $rules);

    expect($validator->passes())->toBeFalse();
});
