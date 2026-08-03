<?php

namespace Tests\Unit\TaxProfiles;

use App\Exceptions\TaxProfiles\ConstanciaExtractionException;
use App\Services\TaxProfiles\IndividualTaxpayerValidator;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class IndividualTaxpayerValidatorTest extends TestCase
{
    private IndividualTaxpayerValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new IndividualTaxpayerValidator;
    }

    #[Test]
    public function acepta_rfc_fisico_valido_de_13_caracteres(): void
    {
        $result = $this->validator->validate([
            'rfc' => 'MEBE931209BI2',
            'tipo_persona' => 'fisica',
            'taxpayer_type' => 'individual',
            'tax_regime' => '605',
        ]);

        $this->assertSame('accept', $result['decision']);
        $this->assertSame('fisica', $result['tipo_persona']);
        $this->assertSame('individual', $result['taxpayer_type']);
    }

    #[Test]
    public function rechaza_rfc_moral_de_12_caracteres(): void
    {
        $result = $this->validator->validate([
            'rfc' => 'ABC010101XX0',
            'tipo_persona' => 'fisica',
            'taxpayer_type' => 'individual',
        ]);

        $this->assertSame('reject_legal_entity', $result['decision']);
        $this->assertSame('legal_entity', $result['taxpayer_type']);
    }

    #[Test]
    public function rechaza_etiqueta_explicita_persona_moral(): void
    {
        $result = $this->validator->validate(
            [
                'rfc' => 'MEBE931209BI2',
                'tipo_persona' => 'fisica',
                'taxpayer_type' => 'individual',
            ],
            ['explicit_persona_moral' => true]
        );

        $this->assertSame('reject_legal_entity', $result['decision']);
    }

    #[Test]
    public function openai_dice_fisica_pero_rfc_de_12_es_rechazado(): void
    {
        $result = $this->validator->validate([
            'rfc' => 'EME010101AB1',
            'tipo_persona' => 'fisica',
            'taxpayer_type' => 'individual',
        ]);

        $this->assertSame('reject_legal_entity', $result['decision']);
    }

    #[Test]
    public function tipo_persona_moral_es_rechazado(): void
    {
        $result = $this->validator->validate([
            'rfc' => 'MEBE931209BI2',
            'tipo_persona' => 'moral',
            'taxpayer_type' => 'individual',
        ]);

        $this->assertSame('reject_legal_entity', $result['decision']);
    }

    #[Test]
    public function legal_entity_sin_senal_dura_es_inconsistente(): void
    {
        $result = $this->validator->validate([
            'rfc' => 'MEBE931209BI2',
            'tipo_persona' => 'fisica',
            'taxpayer_type' => 'legal_entity',
        ]);

        $this->assertSame('inconsistent', $result['decision']);
    }

    #[Test]
    public function regimen_moral_con_rfc_fisico_solo_advierte(): void
    {
        $result = $this->validator->validate([
            'rfc' => 'MEBE931209BI2',
            'tipo_persona' => 'fisica',
            'taxpayer_type' => 'individual',
            'tax_regime' => '601',
        ]);

        $this->assertSame('accept', $result['decision']);
        $this->assertNotEmpty($result['warnings']);
    }

    #[Test]
    public function rfc_faltante_es_incompleto(): void
    {
        $result = $this->validator->validate([
            'rfc' => null,
            'tipo_persona' => null,
        ]);

        $this->assertSame('incomplete', $result['decision']);
    }

    #[Test]
    public function detecta_persona_moral_en_texto(): void
    {
        $this->assertTrue($this->validator->detectExplicitPersonaMoral(
            'Tipo de persona: Persona Moral'
        ));
        $this->assertFalse($this->validator->detectExplicitPersonaMoral(
            'Tipo de persona: Persona Física'
        ));
    }

    #[Test]
    public function assert_persistence_rechaza_moral_y_acepta_fisica(): void
    {
        $this->validator->assertIndividualForPersistence('MEBE931209BI2', 'fisica');

        $this->expectException(ConstanciaExtractionException::class);
        $this->validator->assertIndividualForPersistence('ABC010101XX0', null);
    }

    #[Test]
    public function assert_persistence_rechaza_desconocido(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->validator->assertIndividualForPersistence('MEBE931209BI2', 'desconocido');
    }

    #[Test]
    public function curp_valida_no_es_obligatoria_para_aceptar(): void
    {
        $result = $this->validator->validate([
            'rfc' => 'MEBE931209BI2',
            'tipo_persona' => 'fisica',
            'curp' => null,
        ]);

        $this->assertSame('accept', $result['decision']);
    }
}
