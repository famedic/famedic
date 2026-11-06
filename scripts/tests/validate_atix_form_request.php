<?php
// Synthetic-only harness: Composer autoload, isolated container and validator.
// No Laravel app bootstrap, database, HMAC, OTP, routes or outbound requests.
require dirname(__DIR__, 2).'/vendor/autoload.php';

try {
    $container = new Illuminate\Container\Container;
    Illuminate\Container\Container::setInstance($container);
    $container->instance('config', new Illuminate\Config\Repository([
        'atix' => ['sandbox' => ['allowed_channels' => ['whatsapp'], 'allowed_audiences' => ['leo']]],
    ]));
    $translator = new Illuminate\Translation\Translator(new Illuminate\Translation\ArrayLoader, 'en');
    $factory = new Illuminate\Validation\Factory($translator, $container);
    $inputs = json_decode(stream_get_contents(STDIN), true, flags: JSON_THROW_ON_ERROR);
    $results = [];
    foreach ($inputs as $phone) {
        $payload = ['phone' => $phone, 'conversation_id' => 'fixture-conversation',
            'channel_event_id' => 'fixture-event', 'channel' => 'whatsapp',
            'audience' => 'leo', 'personalization' => ['allowed' => false]];
        $incoming = Illuminate\Http\Request::create('/api/v1/integrations/atix/phone-lookups', 'POST', [], [], [],
            ['CONTENT_TYPE' => 'application/json'], json_encode($payload, JSON_THROW_ON_ERROR));
        (new Illuminate\Foundation\Http\Middleware\TrimStrings)->handle($incoming, static fn ($request) => $request);
        (new Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull)->handle($incoming, static fn ($request) => $request);
        $form = Illuminate\Foundation\Http\FormRequest::createFrom($incoming,
            new App\Http\Requests\Api\V1\Integrations\AtixPhoneLookupRequest);
        $form->setContainer($container);
        (new ReflectionMethod($form, 'prepareForValidation'))->invoke($form);
        $validator = $factory->make($form->validationData(), $form->rules());
        $results[] = $validator->passes();
    }
    echo json_encode($results, JSON_THROW_ON_ERROR);
} catch (Throwable $exception) {
    fwrite(STDERR, "FAIL: isolated FormRequest harness; values omitted\n");
    exit(1);
}
