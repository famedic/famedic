<?php

use App\Services\ActiveCampaign\ActiveCampaignService;
use Illuminate\Support\Facades\Artisan;

function fakeActiveCampaignTags(array $tags): void
{
    $service = Mockery::mock(ActiveCampaignService::class);
    $service->shouldReceive('getTags')
        ->once()
        ->andReturn($tags);

    app()->instance(ActiveCampaignService::class, $service);
}

it('finds an exact tag match', function () {
    fakeActiveCampaignTags([
        ['id' => '34', 'tag' => 'Cita Pendiente'],
    ]);

    $exit = Artisan::call('activecampaign:find-tag', ['name' => 'Cita Pendiente']);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('ID: 34')
        ->and($output)->toContain('Tag: Cita Pendiente');
});

it('finds tags case-insensitively', function () {
    fakeActiveCampaignTags([
        ['id' => '34', 'tag' => 'Cita Pendiente'],
    ]);

    $exit = Artisan::call('activecampaign:find-tag', ['name' => 'cita pendiente']);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('ID: 34')
        ->and($output)->toContain('Tag: Cita Pendiente');
});

it('trims the search term before matching', function () {
    fakeActiveCampaignTags([
        ['id' => '34', 'tag' => 'Cita Pendiente'],
    ]);

    $exit = Artisan::call('activecampaign:find-tag', ['name' => '  Cita Pendiente  ']);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('ID: 34')
        ->and($output)->toContain('Tag: Cita Pendiente');
});

it('shows multiple partial matches when exact match is absent', function () {
    fakeActiveCampaignTags([
        ['id' => '34', 'tag' => 'Cita Pendiente'],
        ['id' => '40', 'tag' => 'Cita Confirmada'],
        ['id' => '50', 'tag' => 'Compra Laboratorio'],
    ]);

    $exit = Artisan::call('activecampaign:find-tag', ['name' => 'cita']);
    $output = Artisan::output();

    expect($exit)->toBe(0)
        ->and($output)->toContain('Coincidencias:')
        ->and($output)->toContain('34  Cita Pendiente')
        ->and($output)->toContain('40  Cita Confirmada')
        ->and($output)->not->toContain('50  Compra Laboratorio');
});

it('returns not found when no tag matches', function () {
    fakeActiveCampaignTags([
        ['id' => '50', 'tag' => 'Compra Laboratorio'],
    ]);

    $exit = Artisan::call('activecampaign:find-tag', ['name' => 'Cita Pendiente']);
    $output = Artisan::output();

    expect($exit)->toBe(1)
        ->and($output)->toContain('No se encontraron tags para: Cita Pendiente');
});

it('returns api error without exposing secrets when the service fails', function () {
    config(['services.activecampaign.token' => 'super-secret-token']);

    $service = Mockery::mock(ActiveCampaignService::class);
    $service->shouldReceive('getTags')
        ->once()
        ->andThrow(new RuntimeException('AC unavailable'));

    app()->instance(ActiveCampaignService::class, $service);

    $exit = Artisan::call('activecampaign:find-tag', ['name' => 'Cita Pendiente']);
    $output = Artisan::output();

    expect($exit)->toBe(2)
        ->and($output)->toContain('No se pudieron consultar tags de ActiveCampaign.')
        ->and($output)->not->toContain('super-secret-token')
        ->and($output)->not->toContain('AC unavailable');
});
