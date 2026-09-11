<?php

use App\Models\Documentation;
use App\Models\User;
use App\Support\FamedicPublicContactConfig;

beforeEach(function () {
    Documentation::query()->delete();
    Documentation::unguarded(function () {
        Documentation::create([
            'privacy_policy' => 'Política de privacidad de prueba.',
            'terms_of_service' => 'Términos de servicio de prueba.',
        ]);
    });
});

function supportPortalUser(array $attributes = []): User
{
    return User::factory()
        ->withCompleteProfile()
        ->withRegularCustomer()
        ->create(array_merge([
            'documentation_accepted_at' => now(),
        ], $attributes))
        ->fresh(['customer', 'administrator']);
}

test('guest cannot access user support page', function () {
    $this->get(route('user.support'))
        ->assertRedirect(route('login'));
});

test('authenticated customer can access user support page', function () {
    $user = supportPortalUser();

    $this->actingAs($user)
        ->get(route('user.support'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->component('User/Support')
            ->has('customerService')
            ->has('alternativeChannel')
            ->has('email')
            ->has('supportHours')
            ->has('concierge')
            ->has('appointmentConfirmation')
            ->has('social'));
});

test('support page exposes normalized whatsapp urls and encoded messages', function () {
    $user = supportPortalUser();

    $this->actingAs($user)
        ->get(route('user.support'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('customerService.whatsappUrl', FamedicPublicContactConfig::whatsappUrl(
                '528128601893',
                'Hola, necesito ayuda con mi cuenta o una compra en Famedic.'
            ))
            ->where('alternativeChannel.whatsappUrl', FamedicPublicContactConfig::whatsappUrl(
                '523349605998',
                'Hola, quiero recibir información y ayuda sobre los servicios de Famedic.'
            ))
            ->where('alternativeChannel.title', 'Canal alternativo de atención')
            ->where('alternativeChannel.badge', 'Segundo canal')
            ->where('alternativeChannel.buttonLabel', 'Abrir WhatsApp alternativo')
            ->where('email.mailtoUrl', 'mailto:contacto@famedic.com.mx?subject='.rawurlencode('Solicitud de soporte Famedic'))
            ->where('concierge.phoneTel', '5566515232')
            ->where('concierge.telUrl', 'tel:5566515232')
            ->where('supportHours.timezone', 'America/Monterrey')
            ->where('supportHours.lines.0', 'Lunes a viernes: 8:30 AM a 6:00 PM')
            ->where('concierge.scheduleLines.0', 'Lunes a viernes: 7:00 AM a 8:00 PM'));
});

test('user navigation includes support before administration for admins', function () {
    $user = User::factory()
        ->withCompleteProfile()
        ->withRegularCustomer()
        ->withAdministrator()
        ->create([
            'documentation_accepted_at' => now(),
        ])
        ->fresh(['customer', 'administrator']);

    $this->actingAs($user)
        ->get(route('user.support'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('userNavigation', 9)
            ->where('userNavigation.7.label', 'Soporte')
            ->where('userNavigation.8.label', 'Administración'));
});

test('user navigation marks support as current on support page', function () {
    $user = supportPortalUser();

    $this->actingAs($user)
        ->get(route('user.support'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('userNavigation', fn ($items) => collect($items)->contains(
                fn ($item) => $item['label'] === 'Soporte'
                    && $item['current'] === true
                    && $item['url'] === route('user.support')
            )));
});

test('non admin users do not see administration in navigation', function () {
    $user = supportPortalUser();

    $this->actingAs($user)
        ->get(route('user.support'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('userNavigation', fn ($items) => collect($items)->every(
                fn ($item) => $item['label'] !== 'Administración'
            )));
});

test('social profiles are exposed on support page', function () {
    $user = supportPortalUser();

    $this->actingAs($user)
        ->get(route('user.support'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('social.profiles', 3)
            ->where('social.profiles.0.url', 'https://www.instagram.com/famedicmx/')
            ->where('social.profiles.1.url', 'https://www.facebook.com/famedicmx/')
            ->where('social.profiles.2.url', 'https://mx.linkedin.com/company/famedicmx'));
});

test('famedic concierge config is shared globally for checkout reuse', function () {
    $user = supportPortalUser();
    $appointmentMessage = 'Hola, quiero confirmar mi cita de laboratorio. ¿Me pueden ayudar a elegir fecha, horario y sucursal?';

    $this->actingAs($user)
        ->get(route('user.support'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('famedicConcierge')
            ->where('famedicConcierge.phoneDisplay', '(55) 6651 5232')
            ->where('famedicConcierge.phoneTel', '5566515232')
            ->where('famedicConcierge.appointmentWhatsApp.display', '(55) 4057 2139')
            ->where('famedicConcierge.appointmentWhatsApp.e164', '525540572139')
            ->where('famedicConcierge.appointmentWhatsApp.url', FamedicPublicContactConfig::whatsappUrl(
                '525540572139',
                $appointmentMessage,
            ))
            ->where('famedicConcierge.scheduleLines.0', 'Lunes a viernes: 7:00 AM a 8:00 PM'));
});

test('support page contact props do not expose sensitive user or cart data', function () {
    $user = supportPortalUser();

    $this->actingAs($user)
        ->get(route('user.support'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->missing('customerService.patient')
            ->missing('concierge.cart')
            ->where('customerService', fn ($channel) => collect($channel)->keys()->every(
                fn ($key) => ! in_array($key, ['password', 'token', 'ssn', 'medicalRecord'], true)
            )));
});

test('disabled alternative channel is omitted from support page props', function () {
    config()->set('famedic.support.alternative_channel.whatsapp_e164', '');

    $user = supportPortalUser();

    $this->actingAs($user)
        ->get(route('user.support'))
        ->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('alternativeChannel', null)
            ->has('customerService'));
});
