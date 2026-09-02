<?php

use App\Support\FamedicPublicContactConfig;

test('whatsapp url normalizes e164 and encodes message', function () {
    $url = FamedicPublicContactConfig::whatsappUrl(
        '528128601893',
        'Hola, necesito ayuda con mi cuenta o una compra en Famedic.'
    );

    expect($url)->toBe(
        'https://wa.me/528128601893?text='.rawurlencode('Hola, necesito ayuda con mi cuenta o una compra en Famedic.')
    );
});

test('whatsapp url returns null for empty e164', function () {
    expect(FamedicPublicContactConfig::whatsappUrl(''))->toBeNull();
});

test('mailto url includes subject when provided', function () {
    expect(FamedicPublicContactConfig::mailtoUrl('contacto@famedic.com.mx', 'Solicitud de soporte Famedic'))
        ->toBe('mailto:contacto@famedic.com.mx?subject='.rawurlencode('Solicitud de soporte Famedic'));
});

test('tel url strips non digits', function () {
    expect(FamedicPublicContactConfig::telUrl('5566515232'))->toBe('tel:5566515232');
});

test('concierge frontend config mirrors checkout source', function () {
    $concierge = FamedicPublicContactConfig::conciergeForFrontend();
    $appointmentMessage = 'Hola, quiero confirmar mi cita de laboratorio. ¿Me pueden ayudar a elegir fecha, horario y sucursal?';

    expect($concierge['phoneTel'])->toBe('5566515232')
        ->and($concierge['phoneDisplay'])->toBe('(55) 6651 5232')
        ->and($concierge['appointmentWhatsApp']['display'])->toBe('(55) 4057 2139')
        ->and($concierge['appointmentWhatsApp']['e164'])->toBe('525540572139')
        ->and($concierge['appointmentWhatsApp']['url'])->toBe(
            'https://wa.me/525540572139?text='.rawurlencode($appointmentMessage)
        )
        ->and($concierge['scheduleLines'][0])->toBe('Lunes a viernes: 7:00 AM a 8:00 PM');
});
