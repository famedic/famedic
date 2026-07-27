<?php

use App\Services\Otp\MysqlContentionClassifier;
use Illuminate\Database\QueryException;
use Illuminate\Database\UniqueConstraintViolationException;

test('mysql contention classifier prefers driver codes over message text', function () {
    $classifier = new MysqlContentionClassifier;

    $e = new QueryException('mysql', 'x', [], new Exception('unrelated wording'));
    $e->errorInfo = ['40001', 1213, 'unrelated wording'];

    expect($classifier->classify($e)['kind'])->toBe(MysqlContentionClassifier::KIND_DEADLOCK)
        ->and($classifier->isRetryableDeadlock($e))->toBeTrue();

    $timeout = new QueryException('mysql', 'x', [], new Exception('zzz'));
    $timeout->errorInfo = ['HY000', 1205, 'zzz'];
    expect($classifier->classify($timeout)['kind'])->toBe(MysqlContentionClassifier::KIND_LOCK_WAIT_TIMEOUT);

    $unique = new UniqueConstraintViolationException('mysql', 'insert', [], new Exception('Duplicate entry for key users_phone_country_phone_unique'));
    $unique->errorInfo = ['23000', 1062, 'Duplicate entry for key users_phone_country_phone_unique'];

    $classified = $classifier->classify($unique);
    expect($classified['kind'])->toBe(MysqlContentionClassifier::KIND_DUPLICATE_KEY)
        ->and($classified['duplicate_users_phone'])->toBeTrue();
});
