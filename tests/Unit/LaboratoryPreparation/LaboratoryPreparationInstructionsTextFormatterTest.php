<?php

use App\Services\LaboratoryPreparation\LaboratoryPreparationInstructionsTextFormatter;
use App\Services\LaboratoryPreparation\LaboratoryPreparationPresenter;

it('formats consolidated ai sections for ActiveCampaign', function () {
    $text = app(LaboratoryPreparationInstructionsTextFormatter::class)->format([
        'ai_status' => LaboratoryPreparationPresenter::STATUS_AI_READY,
        'summary' => [
            'sections' => [
                ['title' => 'Preparación general', 'content' => '• Ayuno de 8 horas.'],
            ],
            'special_instructions' => [],
            'individual_instructions' => [],
        ],
        'studies' => [],
    ]);

    expect($text)->toBe("Preparación general\n• Ayuno de 8 horas.");
});

it('formats per study instructions when ai summary is not ready', function () {
    $text = app(LaboratoryPreparationInstructionsTextFormatter::class)->format([
        'ai_status' => LaboratoryPreparationPresenter::STATUS_FALLBACK,
        'summary' => [
            'sections' => [],
            'special_instructions' => [],
            'individual_instructions' => [],
        ],
        'studies' => [
            ['name' => 'COLESTEROL VLDL', 'instructions' => 'Ayuno de 10 a 12 horas'],
        ],
    ]);

    expect($text)->toBe("COLESTEROL VLDL\nAyuno de 10 a 12 horas");
});