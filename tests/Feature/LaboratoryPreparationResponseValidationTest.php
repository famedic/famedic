<?php

use App\Enums\LaboratoryBrand;
use App\Models\AiExecution;
use App\Models\LaboratoryPurchase;
use App\Models\LaboratoryPurchaseItem;
use App\Models\LaboratoryPurchasePreparationSummary;
use App\Models\User;
use App\Services\LaboratoryPreparation\LaboratoryPreparationSummaryService;
use App\Services\OpenAi\OpenAiClient;

function preparationValidationPurchase(): LaboratoryPurchase
{
    $user = User::factory()
        ->withRegularCustomer()
        ->withCompleteProfile()
        ->create(['documentation_accepted_at' => now()])
        ->fresh(['customer']);

    return LaboratoryPurchase::query()->create([
        'customer_id' => $user->customer->id,
        'brand' => LaboratoryBrand::OLAB->value,
        'gda_order_id' => 'gda-validation-'.fake()->unique()->numerify('######'),
        'name' => 'Paciente',
        'paternal_lastname' => 'Validacion',
        'maternal_lastname' => 'Prompt',
        'phone' => '8111111111',
        'phone_country' => 'MX',
        'birth_date' => '1990-01-01',
        'gender' => null,
        'street' => 'Calle',
        'number' => '1',
        'neighborhood' => 'Centro',
        'state' => 'Nuevo Leon',
        'city' => 'Monterrey',
        'zipcode' => '64000',
        'additional_references' => 'Prueba',
        'total_cents' => 100000,
    ]);
}

function preparationValidationItem(LaboratoryPurchase $purchase, array $attributes = []): LaboratoryPurchaseItem
{
    return LaboratoryPurchaseItem::query()->create(array_merge([
        'laboratory_purchase_id' => $purchase->id,
        'name' => 'Estudio',
        'gda_id' => fake()->unique()->numerify('####'),
        'indications' => 'Indicacion base.',
        'feature_list' => [],
        'price_cents' => 50000,
    ], $attributes));
}

function bindPreparationValidationOpenAi(array $content): object
{
    $fake = new class($content) extends OpenAiClient
    {
        public int $calls = 0;

        public function __construct(public array $content) {}

        public function chatCompletionWithMetadata(
            array $messages,
            ?string $model = null,
            ?array $jsonSchema = null,
            ?string $schemaName = null,
            float $temperature = 0,
        ): array {
            $this->calls++;

            return [
                'content' => $this->content,
                'model' => $model ?: 'gpt-4o-mini',
                'usage' => ['prompt_tokens' => 10, 'completion_tokens' => 10, 'total_tokens' => 20],
                'raw' => [],
            ];
        }
    };

    app()->instance(OpenAiClient::class, $fake);

    return $fake;
}

it('activates laboratory preparation prompt version 3 with fidelity instructions', function () {
    $prompt = app(LaboratoryPreparationSummaryService::class)->activePrompt();

    expect($prompt->version)->toBe(3)
        ->and($prompt->system_prompt)->toContain('REGLA FUNDAMENTAL DE FUENTE')
        ->and($prompt->system_prompt)->toContain('feature_list')
        ->and($prompt->system_prompt)->toContain('NO INVENTAR')
        ->and($prompt->system_prompt)->toContain('NO demuestra por sí mismo')
        ->and($prompt->user_prompt)->toContain('{{items_json}}');
});

it('accepts generic summary text when structured preparation content is faithful', function () {
    $purchase = preparationValidationPurchase();
    $item = preparationValidationItem($purchase, ['indications' => 'Ayuno de 8 horas.']);

    bindPreparationValidationOpenAi([
        'summary' => 'Para la preparación de los estudios, es necesario seguir las indicaciones específicas para cada uno.',
        'sections' => [
            [
                'key' => 'ayuno',
                'title' => 'Ayuno',
                'content' => '• Ayuno de 8 horas.',
                'source_item_ids' => [$item->id],
            ],
        ],
        'special_instructions' => [],
        'individual_instructions' => [],
    ]);

    $summary = app(LaboratoryPreparationSummaryService::class)->generate($purchase);

    expect($summary)->toBeInstanceOf(LaboratoryPurchasePreparationSummary::class);
});

it('accepts fixture A consolidating compatible duplicate instructions', function () {
    $purchase = preparationValidationPurchase();
    $first = preparationValidationItem($purchase, [
        'name' => 'Urocultivo',
        'indications' => 'Desechar el primer chorro y recolectar el chorro medio.',
    ]);
    $second = preparationValidationItem($purchase, [
        'name' => 'Examen general de orina',
        'indications' => 'Desechar el primer chorro y recolectar el chorro medio.',
    ]);

    $fixture = [
        'summary' => 'Dos estudios requieren recolectar orina del chorro medio después de desechar el primero.',
        'sections' => [
            [
                'key' => 'orina',
                'title' => 'Recolección de orina',
                'content' => "• Desecha el primer chorro de orina.\n• Recolecta el chorro medio.",
                'source_item_ids' => [$first->id, $second->id],
            ],
        ],
        'special_instructions' => [],
        'individual_instructions' => [],
    ];

    bindPreparationValidationOpenAi($fixture);

    $summary = app(LaboratoryPreparationSummaryService::class)->generate($purchase);

    expect($summary)->toBeInstanceOf(LaboratoryPurchasePreparationSummary::class)
        ->and($summary->summary_json['sections'][0]['source_item_ids'])->toBe([$first->id, $second->id])
        ->and($summary->summary_json['sections'][0]['content'])->toContain('chorro medio');
});

it('rejects fixture A when compatible instructions are repeated without consolidation', function () {
    $purchase = preparationValidationPurchase();
    $first = preparationValidationItem($purchase, [
        'indications' => 'Desechar el primer chorro y recolectar el chorro medio.',
    ]);
    $second = preparationValidationItem($purchase, [
        'indications' => 'Desechar el primer chorro y recolectar el chorro medio.',
    ]);

    bindPreparationValidationOpenAi([
        'summary' => 'Recolecta orina del chorro medio en ambos estudios.',
        'sections' => [
            [
                'key' => 'orina',
                'title' => 'Indicaciones para estudios de orina',
                'content' => "Desechar el primer chorro y recolectar el chorro medio.\n\nDesechar el primer chorro y recolectar el chorro medio.",
                'source_item_ids' => [$first->id, $second->id],
            ],
        ],
        'special_instructions' => [],
        'individual_instructions' => [],
    ]);

    $summary = app(LaboratoryPreparationSummaryService::class)->generate($purchase);

    expect($summary)->toBeNull()
        ->and(AiExecution::query()->where('status', AiExecution::STATUS_FAILED)->count())->toBe(1);
});

it('accepts fixture B keeping incompatible urine instructions separated', function () {
    $purchase = preparationValidationPurchase();
    $first = preparationValidationItem($purchase, [
        'name' => 'Urocultivo',
        'indications' => 'Recolectar primera orina de la mañana.',
    ]);
    $second = preparationValidationItem($purchase, [
        'name' => 'Creatinina en orina',
        'indications' => 'Recolectar después de 4 horas de la última micción.',
    ]);

    $fixture = [
        'summary' => 'Hay dos formas distintas de recolectar orina según el estudio.',
        'sections' => [
            [
                'key' => 'orina-manana',
                'title' => 'Primera orina de la mañana',
                'content' => '• Recolecta la primera orina de la mañana.',
                'source_item_ids' => [$first->id],
            ],
            [
                'key' => 'orina-intervalo',
                'title' => 'Recolección con intervalo',
                'content' => '• Recolecta después de 4 horas de la última micción.',
                'source_item_ids' => [$second->id],
            ],
        ],
        'special_instructions' => [],
        'individual_instructions' => [],
    ];

    bindPreparationValidationOpenAi($fixture);

    $summary = app(LaboratoryPreparationSummaryService::class)->generate($purchase);

    expect($summary)->toBeInstanceOf(LaboratoryPurchasePreparationSummary::class)
        ->and($summary->summary_json['sections'])->toHaveCount(2);
});

it('rejects fixture B when incompatible instructions are concatenated verbatim', function () {
    $purchase = preparationValidationPurchase();
    $first = preparationValidationItem($purchase, [
        'indications' => 'Recolectar primera orina de la mañana.',
    ]);
    $second = preparationValidationItem($purchase, [
        'indications' => 'Recolectar después de 4 horas de la última micción.',
    ]);

    bindPreparationValidationOpenAi([
        'summary' => 'Sigue las indicaciones de orina de tus estudios.',
        'sections' => [
            [
                'key' => 'orina',
                'title' => 'Indicaciones para estudios de orina',
                'content' => "Recolectar primera orina de la mañana.\n\nRecolectar después de 4 horas de la última micción.",
                'source_item_ids' => [$first->id, $second->id],
            ],
        ],
        'special_instructions' => [],
        'individual_instructions' => [],
    ]);

    $summary = app(LaboratoryPreparationSummaryService::class)->generate($purchase);

    expect($summary)->toBeNull()
        ->and(AiExecution::query()->where('status', AiExecution::STATUS_FAILED)->count())->toBe(1);
});

it('accepts fixture C keeping a unique instruction in individual_instructions', function () {
    $purchase = preparationValidationPurchase();
    $shared = preparationValidationItem($purchase, [
        'name' => 'Biometria hematica',
        'indications' => 'Ayuno de 8 horas.',
    ]);
    $unique = preparationValidationItem($purchase, [
        'name' => 'Perfil hormonal',
        'indications' => 'Evitar ejercicio intenso 24 horas antes.',
    ]);

    $fixture = [
        'summary' => 'Un estudio requiere ayuno y otro tiene una preparación especial.',
        'sections' => [
            [
                'key' => 'ayuno',
                'title' => 'Ayuno',
                'content' => '• Ayuno de 8 horas.',
                'source_item_ids' => [$shared->id],
            ],
        ],
        'special_instructions' => [],
        'individual_instructions' => [
            [
                'study_name' => 'Perfil hormonal',
                'content' => 'Evitar ejercicio intenso 24 horas antes.',
                'source_item_id' => $unique->id,
            ],
        ],
    ];

    bindPreparationValidationOpenAi($fixture);

    $summary = app(LaboratoryPreparationSummaryService::class)->generate($purchase);

    expect($summary)->toBeInstanceOf(LaboratoryPurchasePreparationSummary::class)
        ->and($summary->summary_json['individual_instructions'][0]['source_item_id'])->toBe($unique->id);
});

it('accepts fixture D preserving quantities and time ranges in synthesized content', function () {
    $purchase = preparationValidationPurchase();
    $item = preparationValidationItem($purchase, [
        'indications' => 'Ayuno de 8 - 14 horas. Llenar por lo menos las tres cuartas partes del recipiente.',
    ]);

    $fixture = [
        'summary' => 'Este estudio requiere ayuno y llenar el recipiente en un nivel específico.',
        'sections' => [
            [
                'key' => 'ayuno',
                'title' => 'Ayuno',
                'content' => '• Ayuno de 8 - 14 horas.',
                'source_item_ids' => [$item->id],
            ],
            [
                'key' => 'recipiente',
                'title' => 'Recipiente',
                'content' => '• Llenar por lo menos las tres cuartas partes del recipiente.',
                'source_item_ids' => [$item->id],
            ],
        ],
        'special_instructions' => [],
        'individual_instructions' => [],
    ];

    bindPreparationValidationOpenAi($fixture);

    $summary = app(LaboratoryPreparationSummaryService::class)->generate($purchase);

    expect($summary)->toBeInstanceOf(LaboratoryPurchasePreparationSummary::class)
        ->and($summary->summary_json['sections'][0]['content'])->toContain('8 - 14 horas')
        ->and($summary->summary_json['sections'][1]['content'])->toContain('tres cuartas partes');
});

it('accepts fixture E when studies have no indications and sections remain empty', function () {
    $purchase = preparationValidationPurchase();
    preparationValidationItem($purchase, ['indications' => null, 'name' => 'Estudio sin prep']);

    bindPreparationValidationOpenAi([
        'summary' => 'No hay indicaciones de preparación registradas para estos estudios.',
        'sections' => [],
        'special_instructions' => [],
        'individual_instructions' => [],
    ]);

    $summary = app(LaboratoryPreparationSummaryService::class)->generate($purchase);

    expect($summary)->toBeInstanceOf(LaboratoryPurchasePreparationSummary::class)
        ->and($summary->summary_json['sections'])->toBe([]);
});

it('accepts fixture F with structured urine preparation bullets', function () {
    $purchase = preparationValidationPurchase();
    $first = preparationValidationItem($purchase, [
        'name' => 'EGO',
        'indications' => 'Desechar el primer chorro. Recolectar chorro medio. Llenar hasta tres cuartas partes.',
    ]);
    $second = preparationValidationItem($purchase, [
        'name' => 'Urocultivo',
        'indications' => 'Desechar el primer chorro. Recolectar chorro medio.',
    ]);
    $third = preparationValidationItem($purchase, [
        'name' => 'Microalbuminuria',
        'indications' => 'Desechar el primer chorro. Recolectar chorro medio. Entregar en menos de 2 horas.',
    ]);

    $fixture = [
        'summary' => 'Varios estudios requieren recolectar orina del chorro medio; uno también pide entregar la muestra en un plazo específico.',
        'sections' => [
            [
                'key' => 'orina-comun',
                'title' => 'Recolección de orina',
                'content' => "• Desecha el primer chorro.\n• Recolecta el chorro medio.",
                'source_item_ids' => [$first->id, $second->id],
            ],
        ],
        'special_instructions' => [
            [
                'content' => '• Llenar hasta tres cuartas partes del recipiente.',
                'source_item_ids' => [$first->id],
            ],
            [
                'content' => '• Entregar la muestra en menos de 2 horas.',
                'source_item_ids' => [$third->id],
            ],
        ],
        'individual_instructions' => [],
    ];

    bindPreparationValidationOpenAi($fixture);

    $summary = app(LaboratoryPreparationSummaryService::class)->generate($purchase);

    expect($summary)->toBeInstanceOf(LaboratoryPurchasePreparationSummary::class)
        ->and($summary->summary_json['sections'][0]['content'])->toContain('•')
        ->and($summary->summary_json['special_instructions'])->toHaveCount(2);
});

it('accepts fixture G keeping different fasting ranges in separate sections', function () {
    $purchase = preparationValidationPurchase();
    $eight = preparationValidationItem($purchase, [
        'name' => 'Química sanguínea',
        'indications' => 'Ayuno de 8 horas.',
    ]);
    $twelve = preparationValidationItem($purchase, [
        'name' => 'Perfil lipídico',
        'indications' => 'Ayuno de 12 horas.',
    ]);

    $fixture = [
        'summary' => 'Tus estudios requieren ayunos distintos; respeta el tiempo indicado para cada uno.',
        'sections' => [
            [
                'key' => 'ayuno-8',
                'title' => 'Ayuno de 8 horas',
                'content' => '• Ayuno de 8 horas.',
                'source_item_ids' => [$eight->id],
            ],
            [
                'key' => 'ayuno-12',
                'title' => 'Ayuno de 12 horas',
                'content' => '• Ayuno de 12 horas.',
                'source_item_ids' => [$twelve->id],
            ],
        ],
        'special_instructions' => [],
        'individual_instructions' => [],
    ];

    bindPreparationValidationOpenAi($fixture);

    $summary = app(LaboratoryPreparationSummaryService::class)->generate($purchase);

    expect($summary)->toBeInstanceOf(LaboratoryPurchasePreparationSummary::class)
        ->and($summary->summary_json['sections'][0]['content'])->toContain('8 horas')
        ->and($summary->summary_json['sections'][1]['content'])->toContain('12 horas');
});

it('rejects responses that omit source item coverage for indicated studies', function () {
    $purchase = preparationValidationPurchase();
    $first = preparationValidationItem($purchase, ['indications' => 'Ayuno de 8 horas.']);
    preparationValidationItem($purchase, ['indications' => 'Llevar recipiente esteril.']);

    bindPreparationValidationOpenAi([
        'summary' => 'Un estudio requiere ayuno.',
        'sections' => [
            [
                'key' => 'ayuno',
                'title' => 'Ayuno',
                'content' => 'Ayuno de 8 horas.',
                'source_item_ids' => [$first->id],
            ],
        ],
        'special_instructions' => [],
        'individual_instructions' => [],
    ]);

    $summary = app(LaboratoryPreparationSummaryService::class)->generate($purchase);

    expect($summary)->toBeNull()
        ->and(AiExecution::query()->where('status', AiExecution::STATUS_FAILED)->count())->toBe(1);
});

it('rejects tetra marcador responses that preserve source_item_id but drop critical instructions', function () {
    $purchase = preparationValidationPurchase();
    $tetra = preparationValidationItem($purchase, [
        'name' => 'Tetra Marcador',
        'indications' => "- Presentarse con ayuno de 8 -14 horas.\n- Se deberá realizar entre las semanas 14 a 22 de gestación por ultrasonido.\n- Copia de interpretación estudio de ultrasonido realizado dentro de las semanas permitidas.",
    ]);

    bindPreparationValidationOpenAi([
        'summary' => 'Este estudio requiere ayuno antes de acudir.',
        'sections' => [
            [
                'key' => 'ayuno',
                'title' => 'Ayuno',
                'content' => '• Ayuno de 8 - 14 horas.',
                'source_item_ids' => [$tetra->id],
            ],
        ],
        'special_instructions' => [],
        'individual_instructions' => [],
    ]);

    $summary = app(LaboratoryPreparationSummaryService::class)->generate($purchase);

    expect($summary)->toBeNull()
        ->and(AiExecution::query()->where('status', AiExecution::STATUS_FAILED)->count())->toBe(1);
});

it('accepts tetra marcador responses that preserve all critical instruction elements', function () {
    $purchase = preparationValidationPurchase();
    $tetra = preparationValidationItem($purchase, [
        'name' => 'Tetra Marcador',
        'indications' => "- Presentarse con ayuno de 8 -14 horas.\n- Se deberá realizar entre las semanas 14 a 22 de gestación por ultrasonido.\n- Copia de interpretación estudio de ultrasonido realizado dentro de las semanas permitidas.",
    ]);

    bindPreparationValidationOpenAi([
        'summary' => 'Requiere ayuno, realizar el estudio entre las semanas indicadas y llevar copia del ultrasonido.',
        'sections' => [
            [
                'key' => 'ayuno',
                'title' => 'Ayuno',
                'content' => '• Presentarse con ayuno de 8 - 14 horas.',
                'source_item_ids' => [$tetra->id],
            ],
            [
                'key' => 'gestacion',
                'title' => 'Semanas de gestación',
                'content' => '• Debe realizarse entre las semanas 14 a 22 de gestación por ultrasonido.',
                'source_item_ids' => [$tetra->id],
            ],
            [
                'key' => 'documentacion',
                'title' => 'Documentación',
                'content' => '• Llevar copia de interpretación del ultrasonido realizado dentro de las semanas permitidas.',
                'source_item_ids' => [$tetra->id],
            ],
        ],
        'special_instructions' => [],
        'individual_instructions' => [],
    ]);

    $summary = app(LaboratoryPreparationSummaryService::class)->generate($purchase);

    expect($summary)->toBeInstanceOf(LaboratoryPurchasePreparationSummary::class)
        ->and($summary->summary_json['sections'])->toHaveCount(3);
});

it('rejects responses that source items with null indications', function () {
    $purchase = preparationValidationPurchase();
    $withoutIndications = preparationValidationItem($purchase, [
        'name' => 'Paquete orina',
        'indications' => null,
        'feature_list' => ['EXAMEN GENERAL DE ORINA'],
    ]);

    bindPreparationValidationOpenAi([
        'summary' => 'Un paquete incluye examen de orina.',
        'sections' => [
            [
                'key' => 'orina',
                'title' => 'Examen General de Orina',
                'content' => 'Seguir las instrucciones específicas de recolección.',
                'source_item_ids' => [$withoutIndications->id],
            ],
        ],
        'special_instructions' => [],
        'individual_instructions' => [],
    ]);

    $summary = app(LaboratoryPreparationSummaryService::class)->generate($purchase);

    expect($summary)->toBeNull()
        ->and(AiExecution::query()->where('status', AiExecution::STATUS_FAILED)->count())->toBe(1);
});

it('accepts a feature that appears only as study_name for an indicated item', function () {
    $purchase = preparationValidationPurchase();
    $withIndications = preparationValidationItem($purchase, [
        'name' => 'Quimica sanguinea',
        'indications' => 'Ayuno de 8 horas.',
    ]);
    preparationValidationItem($purchase, [
        'name' => 'Paquete metabolico',
        'indications' => null,
        'feature_list' => ['EXAMEN GENERAL DE ORINA'],
    ]);

    bindPreparationValidationOpenAi([
        'summary' => 'Se requiere ayuno de 8 horas.',
        'sections' => [
            [
                'key' => 'ayuno',
                'title' => 'Ayuno',
                'content' => 'Ayuno de 8 horas.',
                'source_item_ids' => [$withIndications->id],
            ],
        ],
        'special_instructions' => [],
        'individual_instructions' => [
            [
                'study_name' => 'EXAMEN GENERAL DE ORINA',
                'content' => 'Ayuno de 8 horas.',
                'source_item_id' => $withIndications->id,
            ],
        ],
    ]);

    $summary = app(LaboratoryPreparationSummaryService::class)->generate($purchase);

    expect($summary)->toBeInstanceOf(LaboratoryPurchasePreparationSummary::class);
});

it('accepts a feature mentioned in instructions when backed by another item indications', function () {
    $purchase = preparationValidationPurchase();
    $withoutIndications = preparationValidationItem($purchase, [
        'name' => 'Paquete orina',
        'indications' => null,
        'feature_list' => ['EXAMEN GENERAL DE ORINA'],
    ]);
    $withIndications = preparationValidationItem($purchase, [
        'name' => 'Examen general de orina',
        'indications' => 'Para EXAMEN GENERAL DE ORINA, recolectar chorro medio.',
    ]);

    bindPreparationValidationOpenAi([
        'summary' => 'El EXAMEN GENERAL DE ORINA requiere recolectar chorro medio.',
        'sections' => [
            [
                'key' => 'orina',
                'title' => 'Recoleccion',
                'content' => 'Para EXAMEN GENERAL DE ORINA, recolectar chorro medio.',
                'source_item_ids' => [$withIndications->id],
            ],
        ],
        'special_instructions' => [],
        'individual_instructions' => [],
    ]);

    $summary = app(LaboratoryPreparationSummaryService::class)->generate($purchase);

    $sourcedIds = collect($summary->summary_json['sections'])
        ->flatMap(fn (array $section) => $section['source_item_ids'])
        ->map(fn ($id) => (int) $id)
        ->all();

    expect($summary)->toBeInstanceOf(LaboratoryPurchasePreparationSummary::class)
        ->and($sourcedIds)->toBe([$withIndications->id])
        ->and($sourcedIds)->not->toContain($withoutIndications->id);
});

it('rejects a preparation instruction based only on feature_list content', function () {
    $purchase = preparationValidationPurchase();
    $withIndications = preparationValidationItem($purchase, [
        'name' => 'Quimica sanguinea',
        'indications' => 'Ayuno de 8 horas.',
    ]);
    $withoutIndications = preparationValidationItem($purchase, [
        'name' => 'Paquete orina',
        'indications' => null,
        'feature_list' => ['EXAMEN GENERAL DE ORINA'],
    ]);

    bindPreparationValidationOpenAi([
        'summary' => 'Se requiere ayuno de 8 horas.',
        'sections' => [
            [
                'key' => 'ayuno',
                'title' => 'Ayuno',
                'content' => 'Ayuno de 8 horas.',
                'source_item_ids' => [$withIndications->id],
            ],
            [
                'key' => 'orina',
                'title' => 'Recoleccion',
                'content' => 'EXAMEN GENERAL DE ORINA: recolectar muestra en recipiente esteril.',
                'source_item_ids' => [$withIndications->id],
            ],
        ],
        'special_instructions' => [],
        'individual_instructions' => [],
    ]);

    $summary = app(LaboratoryPreparationSummaryService::class)->generate($purchase);
    $execution = AiExecution::query()->latest('id')->first();

    expect($summary)->toBeNull()
        ->and($execution->status)->toBe(AiExecution::STATUS_FAILED)
        ->and($execution->error)->toContain("Item [{$withoutIndications->id}]")
        ->and($execution->error)->toContain('feature_hash')
        ->and($execution->error)->toContain('sections.1.content');
});

it('rejects responses that invent preparation instructions from feature_list without indications', function () {
    $purchase = preparationValidationPurchase();
    preparationValidationItem($purchase, [
        'name' => 'Paquete orina',
        'indications' => null,
        'feature_list' => ['EXAMEN GENERAL DE ORINA'],
    ]);

    bindPreparationValidationOpenAi([
        'summary' => 'Para el examen general de orina, seguir las instrucciones específicas que se proporcionen al momento de la recolección.',
        'sections' => [],
        'special_instructions' => [],
        'individual_instructions' => [],
    ]);

    $summary = app(LaboratoryPreparationSummaryService::class)->generate($purchase);

    expect($summary)->toBeNull()
        ->and(AiExecution::query()->where('status', AiExecution::STATUS_FAILED)->count())->toBe(1);
});

it('rejects responses that lose numeric ranges from the original indications', function () {
    $purchase = preparationValidationPurchase();
    $item = preparationValidationItem($purchase, [
        'indications' => 'Ayuno de 8 - 14 horas.',
    ]);

    bindPreparationValidationOpenAi([
        'summary' => 'Se requiere ayuno antes del estudio.',
        'sections' => [
            [
                'key' => 'ayuno',
                'title' => 'Ayuno',
                'content' => '• Ayuno prolongado antes del estudio.',
                'source_item_ids' => [$item->id],
            ],
        ],
        'special_instructions' => [],
        'individual_instructions' => [],
    ]);

    $summary = app(LaboratoryPreparationSummaryService::class)->generate($purchase);

    expect($summary)->toBeNull()
        ->and(AiExecution::query()->where('status', AiExecution::STATUS_FAILED)->count())->toBe(1);
});

it('rejects responses that lose critical units from the original indications', function () {
    $purchase = preparationValidationPurchase();
    $item = preparationValidationItem($purchase, [
        'indications' => 'Ayuno de 8 - 14 horas.',
    ]);

    bindPreparationValidationOpenAi([
        'summary' => 'Se requiere ayuno entre 8 y 14.',
        'sections' => [
            [
                'key' => 'ayuno',
                'title' => 'Ayuno',
                'content' => '• Ayuno de 8 - 14.',
                'source_item_ids' => [$item->id],
            ],
        ],
        'special_instructions' => [],
        'individual_instructions' => [],
    ]);

    $summary = app(LaboratoryPreparationSummaryService::class)->generate($purchase);

    expect($summary)->toBeNull()
        ->and(AiExecution::query()->where('status', AiExecution::STATUS_FAILED)->count())->toBe(1);
});

it('accepts safe textual variations when critical information is preserved', function () {
    $purchase = preparationValidationPurchase();
    $item = preparationValidationItem($purchase, [
        'indications' => 'Se deberá realizar entre las semanas 14 a 22 de gestación por ultrasonido.',
    ]);

    bindPreparationValidationOpenAi([
        'summary' => 'Debes realizar el estudio en las semanas de gestación indicadas.',
        'sections' => [
            [
                'key' => 'gestacion',
                'title' => 'Gestación',
                'content' => '• Debe realizarse entre las semanas 14 a 22 de gestación por ultrasonido.',
                'source_item_ids' => [$item->id],
            ],
        ],
        'special_instructions' => [],
        'individual_instructions' => [],
    ]);

    $summary = app(LaboratoryPreparationSummaryService::class)->generate($purchase);

    expect($summary)->toBeInstanceOf(LaboratoryPurchasePreparationSummary::class);
});

it('accepts valid mixed purchases synthesizing indicated items and omitting null indications', function () {
    $purchase = preparationValidationPurchase();
    $withIndications = preparationValidationItem($purchase, [
        'name' => 'Química sanguínea',
        'indications' => 'Ayuno de 8 horas.',
    ]);
    $withoutIndications = preparationValidationItem($purchase, [
        'name' => 'Paquete orina',
        'indications' => null,
        'feature_list' => ['EXAMEN GENERAL DE ORINA'],
    ]);
    $multiClause = preparationValidationItem($purchase, [
        'name' => 'Tetra Marcador',
        'indications' => "- Presentarse con ayuno de 8 -14 horas.\n- Copia de interpretación estudio de ultrasonido.",
    ]);

    bindPreparationValidationOpenAi([
        'summary' => 'Hay ayuno de 8 horas y también se requiere copia del ultrasonido.',
        'sections' => [
            [
                'key' => 'ayuno-8',
                'title' => 'Ayuno de 8 horas',
                'content' => '• Ayuno de 8 horas.',
                'source_item_ids' => [$withIndications->id],
            ],
            [
                'key' => 'tetra',
                'title' => 'Preparación especial',
                'content' => "• Presentarse con ayuno de 8 - 14 horas.\n• Llevar copia de interpretación del ultrasonido.",
                'source_item_ids' => [$multiClause->id],
            ],
        ],
        'special_instructions' => [],
        'individual_instructions' => [],
    ]);

    $summary = app(LaboratoryPreparationSummaryService::class)->generate($purchase);

    $sourcedIds = collect($summary->summary_json['sections'])
        ->flatMap(fn (array $section) => $section['source_item_ids'])
        ->map(fn ($id) => (int) $id)
        ->all();

    expect($summary)->toBeInstanceOf(LaboratoryPurchasePreparationSummary::class)
        ->and($sourcedIds)->toContain($withIndications->id)
        ->and($sourcedIds)->toContain($multiClause->id)
        ->and($sourcedIds)->not->toContain($withoutIndications->id);
});

it('stores the validated OpenAI response payload when fidelity validation fails', function () {
    $purchase = preparationValidationPurchase();
    $item = preparationValidationItem($purchase, [
        'indications' => 'Se deberá realizar entre las semanas 14 a 22 de gestación por ultrasonido.',
    ]);

    $rejectedPayload = [
        'summary' => 'Resumen incompleto.',
        'sections' => [
            [
                'key' => 'gestacion',
                'title' => 'Gestación',
                'content' => '• Realizar el estudio por ultrasonido.',
                'source_item_ids' => [$item->id],
            ],
        ],
        'special_instructions' => [],
        'individual_instructions' => [],
    ];

    bindPreparationValidationOpenAi($rejectedPayload);

    $summary = app(LaboratoryPreparationSummaryService::class)->generate($purchase);

    $execution = AiExecution::query()->latest('id')->first();

    expect($summary)->toBeNull()
        ->and($execution)->not->toBeNull()
        ->and($execution->status)->toBe(AiExecution::STATUS_FAILED)
        ->and($execution->error)->toContain("[{$item->id}]")
        ->and($execution->error)->toContain('Missing critical elements:')
        ->and($execution->response_payload)->toBe($rejectedPayload)
        ->and(LaboratoryPurchasePreparationSummary::query()->where('laboratory_purchase_id', $purchase->id)->count())->toBe(0);
});

it('keeps response_payload null when OpenAI fails before returning structured content', function () {
    $purchase = preparationValidationPurchase();
    preparationValidationItem($purchase, ['indications' => 'Ayuno de 8 horas.']);

    $fake = new class extends OpenAiClient
    {
        public function chatCompletionWithMetadata(
            array $messages,
            ?string $model = null,
            ?array $jsonSchema = null,
            ?string $schemaName = null,
            float $temperature = 0,
        ): array {
            throw new \RuntimeException('Proveedor no disponible');
        }
    };

    app()->instance(OpenAiClient::class, $fake);

    $summary = app(LaboratoryPreparationSummaryService::class)->generate($purchase);

    $execution = AiExecution::query()->latest('id')->first();

    expect($summary)->toBeNull()
        ->and($execution)->not->toBeNull()
        ->and($execution->status)->toBe(AiExecution::STATUS_FAILED)
        ->and($execution->response_payload)->toBeNull();
});

it('accepts tirosina newborn preparation with safe paraphrasing of non critical wording', function () {
    $purchase = preparationValidationPurchase();
    $tirosina = preparationValidationItem($purchase, [
        'name' => 'Tirosina',
        'indications' => "• El recién nacido deberá haber tenido al menos 2 ingestas de alimento previo a la toma del estudio.\n• El estudio deberá realizarse preferentemente entre el tercer y quinto día de vida, o antes de los 30 días si no es posible por alguna circunstancia.",
    ]);

    bindPreparationValidationOpenAi([
        'summary' => 'Para la preparación de los estudios, es necesario seguir las indicaciones específicas para cada uno.',
        'sections' => [
            [
                'key' => 'ingestas',
                'title' => 'Ingestas previas',
                'content' => '• El recién nacido deberá haber tenido al menos 2 ingestas de alimento previo a la toma del estudio de Tirosina.',
                'source_item_ids' => [$tirosina->id],
            ],
            [
                'key' => 'ventana',
                'title' => 'Ventana de toma',
                'content' => '• El estudio de Tirosina deberá realizarse preferentemente entre el tercer y quinto día de vida, o antes de los 30 días si no es posible en ese rango.',
                'source_item_ids' => [$tirosina->id],
            ],
        ],
        'special_instructions' => [],
        'individual_instructions' => [],
    ]);

    $summary = app(LaboratoryPreparationSummaryService::class)->generate($purchase);

    expect($summary)->toBeInstanceOf(LaboratoryPurchasePreparationSummary::class);
});

it('accepts tirosina newborn preparation when ordinals are expressed as numeric days', function () {
    $purchase = preparationValidationPurchase();
    $tirosina = preparationValidationItem($purchase, [
        'name' => 'Tirosina',
        'indications' => 'El estudio deberá realizarse preferentemente entre el tercer y quinto día de vida, o antes de los 30 días si no es posible por alguna circunstancia.',
    ]);

    bindPreparationValidationOpenAi([
        'summary' => 'Resumen breve.',
        'sections' => [
            [
                'key' => 'ventana',
                'title' => 'Ventana de toma',
                'content' => '• Realizar el estudio preferentemente entre los días 3 y 5 de vida, o antes de los 30 días si no es posible en ese rango.',
                'source_item_ids' => [$tirosina->id],
            ],
        ],
        'special_instructions' => [],
        'individual_instructions' => [],
    ]);

    $summary = app(LaboratoryPreparationSummaryService::class)->generate($purchase);

    expect($summary)->toBeInstanceOf(LaboratoryPurchasePreparationSummary::class);
});

it('rejects responses that drop a concrete conditional requirement', function () {
    $purchase = preparationValidationPurchase();
    $item = preparationValidationItem($purchase, [
        'indications' => 'El estudio deberá realizarse entre el tercer y quinto día de vida, o antes de los 30 días si no es posible.',
    ]);

    bindPreparationValidationOpenAi([
        'summary' => 'Resumen breve.',
        'sections' => [
            [
                'key' => 'ventana',
                'title' => 'Ventana de toma',
                'content' => '• Realizar el estudio entre el tercer y quinto día de vida.',
                'source_item_ids' => [$item->id],
            ],
        ],
        'special_instructions' => [],
        'individual_instructions' => [],
    ]);

    $summary = app(LaboratoryPreparationSummaryService::class)->generate($purchase);

    expect($summary)->toBeNull()
        ->and(AiExecution::query()->where('status', AiExecution::STATUS_FAILED)->count())->toBe(1);
});

it('stores response_payload on successful preparation generation', function () {
    $purchase = preparationValidationPurchase();
    $item = preparationValidationItem($purchase, ['indications' => 'Ayuno de 8 horas.']);

    $acceptedPayload = [
        'summary' => 'Se requiere ayuno de 8 horas.',
        'sections' => [
            [
                'key' => 'ayuno',
                'title' => 'Ayuno',
                'content' => '• Ayuno de 8 horas.',
                'source_item_ids' => [$item->id],
            ],
        ],
        'special_instructions' => [],
        'individual_instructions' => [],
    ];

    bindPreparationValidationOpenAi($acceptedPayload);

    $summary = app(LaboratoryPreparationSummaryService::class)->generate($purchase);

    $execution = AiExecution::query()->latest('id')->first();

    expect($summary)->toBeInstanceOf(LaboratoryPurchasePreparationSummary::class)
        ->and($execution->status)->toBe(AiExecution::STATUS_SUCCEEDED)
        ->and($execution->response_payload)->toBe($acceptedPayload);
});
