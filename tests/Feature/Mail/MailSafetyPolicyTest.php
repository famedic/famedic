<?php

use App\Listeners\ApplyMailSafetyPolicy;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\Mime\Email;

beforeEach(function () {
    config([
        'mail.safe_mode.enabled' => true,
        'mail.safe_mode.allowed_recipients' => ['qa@famedic.com.mx', 'dev@famedic.com.mx'],
        'mail.safe_mode.allowed_domains' => ['famedic.com.mx'],
        'mail.safe_mode.block_disallowed' => true,
        'mail.safe_mode.log_blocked' => true,
        'mail.default' => 'array',
    ]);
});

function makeMessageSendingEvent(
    array $to = [],
    array $cc = [],
    array $bcc = [],
    string $subject = 'Test subject',
): MessageSending {
    $message = (new Email())
        ->subject($subject)
        ->text('Test body');

    foreach ($to as $email) {
        $message->addTo($email);
    }

    foreach ($cc as $email) {
        $message->addCc($email);
    }

    foreach ($bcc as $email) {
        $message->addBcc($email);
    }

    return new MessageSending($message);
}

function applyMailSafetyPolicy(MessageSending $event): bool
{
    return app(ApplyMailSafetyPolicy::class)->handle($event);
}

test('en production no bloquea correos aunque el destinatario no esté en lista blanca', function () {
    $this->app['env'] = 'production';

    $result = applyMailSafetyPolicy(makeMessageSendingEvent(['paciente@gmail.com']));

    expect($result)->toBeTrue();
});

test('en staging permite email exacto definido en MAIL_ALLOWED_RECIPIENTS', function () {
    $this->app['env'] = 'staging';

    $result = applyMailSafetyPolicy(makeMessageSendingEvent(['qa@famedic.com.mx']));

    expect($result)->toBeTrue();
});

test('en staging permite email exacto con mayúsculas en MAIL_ALLOWED_RECIPIENTS', function () {
    $this->app['env'] = 'staging';

    $result = applyMailSafetyPolicy(makeMessageSendingEvent(['QA@FAMEDIC.COM.MX']));

    expect($result)->toBeTrue();
});

test('en staging permite dominio definido en MAIL_ALLOWED_DOMAINS', function () {
    $this->app['env'] = 'staging';

    $result = applyMailSafetyPolicy(makeMessageSendingEvent(['admin@famedic.com.mx']));

    expect($result)->toBeTrue();
});

test('en staging bloquea destinatario externo no permitido', function () {
    $this->app['env'] = 'staging';
    Log::spy();

    $result = applyMailSafetyPolicy(makeMessageSendingEvent(['paciente@gmail.com']));

    expect($result)->toBeFalse();

    Log::shouldHaveReceived('warning')
        ->once()
        ->with(
            ApplyMailSafetyPolicy::POLICY_NAME.': correo bloqueado por destinatario no permitido',
            \Mockery::on(fn (array $context) => $context['environment'] === 'staging'
                && $context['subject'] === 'Test subject'
                && $context['recipients'] === ['paciente@gmail.com']
                && $context['blocked_recipients'] === ['paciente@gmail.com']
                && $context['policy'] === ApplyMailSafetyPolicy::POLICY_NAME)
        );
});

test('en staging bloquea todo el correo si cc tiene destinatario no permitido', function () {
    $this->app['env'] = 'staging';

    $result = applyMailSafetyPolicy(makeMessageSendingEvent(
        to: ['qa@famedic.com.mx'],
        cc: ['paciente@gmail.com'],
    ));

    expect($result)->toBeFalse();
});

test('en staging bloquea todo el correo si bcc tiene destinatario no permitido', function () {
    $this->app['env'] = 'staging';

    $result = applyMailSafetyPolicy(makeMessageSendingEvent(
        to: ['qa@famedic.com.mx'],
        bcc: ['paciente@gmail.com'],
    ));

    expect($result)->toBeFalse();
});

test('si MAIL_SAFE_MODE es false no bloquea en ambientes no productivos', function () {
    $this->app['env'] = 'staging';
    config(['mail.safe_mode.enabled' => false]);

    $result = applyMailSafetyPolicy(makeMessageSendingEvent(['paciente@gmail.com']));

    expect($result)->toBeTrue();
});

test('registra log cuando se bloquea un correo si MAIL_LOG_BLOCKED es true', function () {
    $this->app['env'] = 'staging';
    config(['mail.safe_mode.log_blocked' => true]);
    Log::spy();

    applyMailSafetyPolicy(makeMessageSendingEvent(['paciente@gmail.com'], subject: 'Asunto bloqueado'));

    Log::shouldHaveReceived('warning')
        ->once()
        ->with(
            ApplyMailSafetyPolicy::POLICY_NAME.': correo bloqueado por destinatario no permitido',
            \Mockery::on(fn (array $context) => $context['subject'] === 'Asunto bloqueado'
                && $context['blocked_recipients'] === ['paciente@gmail.com'])
        );
});

test('no registra log cuando MAIL_LOG_BLOCKED es false', function () {
    $this->app['env'] = 'staging';
    config(['mail.safe_mode.log_blocked' => false]);
    Log::spy();

    applyMailSafetyPolicy(makeMessageSendingEvent(['paciente@gmail.com']));

    Log::shouldNotHaveReceived('warning');
});

test('integración: el listener registrado bloquea el envío real con transport array', function () {
    $this->app['env'] = 'staging';

    $sent = Mail::raw('Cuerpo de prueba', function ($message) {
        $message->to('paciente@gmail.com')->subject('Correo bloqueado');
    });

    expect($sent)->toBeNull();
});

test('integración: el listener registrado permite el envío a destinatario permitido', function () {
    $this->app['env'] = 'staging';

    $sent = Mail::raw('Cuerpo de prueba', function ($message) {
        $message->to('qa@famedic.com.mx')->subject('Correo permitido');
    });

    expect($sent)->not->toBeNull();
});
