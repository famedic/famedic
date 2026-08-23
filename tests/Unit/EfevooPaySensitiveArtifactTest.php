<?php

test('efevoopay sensitive debug artifacts are not present in the working tree', function () {
    expect(base_path('curl_last.log'))->not->toBeFile();
    expect(base_path('app/Console/Commands/DebugEfevooResponse.php'))->not->toBeFile();
    expect(base_path('app/Console/Commands/TestExactClone.php'))->not->toBeFile();
    expect(base_path('app/Http/Controllers/TestEfevooController.php'))->not->toBeFile();
    expect(base_path('app/Http/Controllers/TestEfevooFinalController.php'))->not->toBeFile();
    expect(base_path('resources/views/test-efevoo.blade.php'))->not->toBeFile();
});

test('gitignore blocks known efevoopay debug artifact names', function () {
    $gitignore = file_get_contents(base_path('.gitignore'));

    expect($gitignore)
        ->toContain('/curl_last.log')
        ->toContain('/efevoopay-debug*.log')
        ->toContain('/efevoopay-response*.json')
        ->toContain('/efevoopay-curl*.txt');
});
