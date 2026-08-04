<?php

use App\Support\GDA\GdaWebhookPayloadResolver;

beforeEach(function () {
    $this->resolver = new GdaWebhookPayloadResolver;
});

test('prioriza service request id numerico sobre infogda_orden en laboratorio normal', function () {
    $resolved = $this->resolver->resolve(normalLabPayload());

    expect($resolved['gda_order_id'])->toBe('24642071')
        ->and($resolved['gda_consecutivo'])->toBe(24642071)
        ->and($resolved['infogda_orden'])->toBe('1392')
        ->and($resolved['is_gabinete'])->toBeFalse();
});

test('normaliza payload de laboratorio con id numerico', function () {
    $resolved = $this->resolver->resolve([
        'id' => '412924',
        'requisition' => ['value' => '1868'],
        'GDA_menssage' => ['acuse' => 'uuid-lab-1'],
        'code' => ['coding' => [['code' => '123', 'display' => 'EXAMEN']]],
    ]);

    expect($resolved['gda_order_id'])->toBe('412924')
        ->and($resolved['gda_consecutivo'])->toBe(412924)
        ->and($resolved['gda_external_id'])->toBe('1868')
        ->and($resolved['acuse'])->toBe('uuid-lab-1')
        ->and($resolved['is_gabinete'])->toBeFalse();
});

test('normaliza payload de gabinete con etiqueta alfanumerica', function () {
    $resolved = $this->resolver->resolve(gabinetePayload('GZ0L000414', '414', '1868'));

    expect($resolved['gda_order_id'])->toBe('GZ0L000414')
        ->and($resolved['gda_consecutivo'])->toBe(414)
        ->and($resolved['gda_external_id'])->toBe('1868')
        ->and($resolved['infogda_etiqueta'])->toBe('GZ0L000414')
        ->and($resolved['is_gabinete'])->toBeTrue();
});

test('no asigna etiqueta alfanumerica como gda_consecutivo', function () {
    $resolved = $this->resolver->resolve([
        'id' => 'GZ0L000414',
        'requisition' => ['value' => '1868'],
        'code' => [
            'coding' => [[
                'infogda_muestras' => [[
                    'infogda_etiqueta' => 'GZ0L000414',
                    'infogda_contenedoracronim' => 'GAB',
                ]],
            ]],
        ],
    ]);

    expect($resolved['gda_consecutivo'])->toBeNull()
        ->and($resolved['gda_order_id'])->toBe('GZ0L000414');
});

test('detecta gabinete por contenedor GAB', function () {
    $resolved = $this->resolver->resolve(gabinetePayload('GZ0L000515', '515', '2066'));

    expect($resolved['is_gabinete'])->toBeTrue()
        ->and($resolved['gda_consecutivo'])->toBe(515);
});

test('gabinete hibrido: prioriza etiqueta sobre ServiceRequest.id numerico', function () {
    $resolved = $this->resolver->resolve([
        'id' => '24748093',
        'requisition' => ['value' => '2151', 'convenio' => 17682],
        'GDA_menssage' => ['acuse' => 'uuid-gab-hybrid'],
        'code' => [
            'coding' => [[
                'infogda_orden' => '552',
                'infogda_muestras' => [[
                    'infogda_etiqueta' => 'GZ0L000552',
                    'infogda_contenedoracronim' => 'GAB',
                ]],
            ]],
        ],
    ]);

    expect($resolved['is_gabinete'])->toBeTrue()
        ->and($resolved['gda_order_id'])->toBe('GZ0L000552')
        ->and($resolved['gda_consecutivo'])->toBe(24748093)
        ->and($resolved['service_request_id'])->toBe('24748093')
        ->and($resolved['infogda_etiqueta'])->toBe('GZ0L000552');
});

test('laboratorio normal no usa etiqueta con sufijo como gda_order_id', function () {
    $resolved = $this->resolver->resolve(normalLabPayload());

    expect($resolved['is_gabinete'])->toBeFalse()
        ->and($resolved['gda_order_id'])->toBe('24642071')
        ->and($resolved['infogda_etiqueta'])->toBe('HD0L001392OQ');
});

test('isNumericConsecutivo valida solo digitos', function () {
    expect($this->resolver->isNumericConsecutivo('414'))->toBeTrue()
        ->and($this->resolver->isNumericConsecutivo('GZ0L000414'))->toBeFalse()
        ->and($this->resolver->isNumericConsecutivo(''))->toBeFalse()
        ->and($this->resolver->isNumericConsecutivo(null))->toBeFalse();
});

function normalLabPayload(): array
{
    return [
        'header' => [
            'lineanegocio' => 'Notificaion-Resultados',
            'marca' => 5,
        ],
        'resourceType' => 'ServiceRequest',
        'id' => '24642071',
        'requisition' => [
            'value' => 'HD0L001392',
            'convenio' => 17479,
        ],
        'status' => 'completed',
        'code' => [
            'coding' => [[
                'code' => '510770',
                'display' => 'PERFIL HORMONAL 5',
                'infogda_status' => 'completed',
                'infogda_cexamen' => 510770,
                'infogda_orden' => '1392',
                'infogda_muestras' => [[
                    'infogda_etiqueta' => 'HD0L001392OQ',
                    'infogda_contenedoracronim' => 'TTOG',
                ]],
            ]],
        ],
        'GDA_menssage' => [
            'acuse' => 'QA-LAB-NORMAL-001',
        ],
    ];
}

function gabinetePayload(string $id, string $orden, string $requisitionValue): array
{
    return [
        'id' => $id,
        'requisition' => ['value' => $requisitionValue],
        'GDA_menssage' => ['acuse' => 'test-acuse-'.uniqid()],
        'code' => [
            'coding' => [[
                'infogda_orden' => $orden,
                'infogda_muestras' => [[
                    'infogda_etiqueta' => $id,
                    'infogda_contenedoracronim' => 'GAB',
                ]],
            ]],
        ],
    ];
}
