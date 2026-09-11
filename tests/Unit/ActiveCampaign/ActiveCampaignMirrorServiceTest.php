<?php

use App\DataTransferObjects\ActiveCampaign\ActiveCampaignContactSnapshot;
use App\Models\ActiveCampaignWebActivity;
use App\Models\Customer;
use App\Models\User;
use App\Services\ActiveCampaign\ActiveCampaignCacheService;
use App\Services\ActiveCampaign\ActiveCampaignMirrorService;
use App\Services\ActiveCampaign\ActiveCampaignReadService;
use App\Services\ActiveCampaign\ActiveCampaignService;
use App\Services\ActiveCampaign\ActiveCampaignWebActivitySyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'services.activecampaign.enabled' => true,
        'services.activecampaign.endpoint' => 'https://ac.test',
        'services.activecampaign.token' => 'test-token',
    ]);

    Cache::flush();
});

function fakeAcMirrorApi($contactDataResponse = null, ?array $activities = null): void
{
    $activities ??= [
        [
            'id' => '100',
            'tstamp' => '2026-08-04T18:00:00-05:00',
            'reference_type' => 'SubscriberEmail',
            'reference_id' => '13',
            'reference_action' => 'open',
            'referenceModelName' => 'contact-email',
            'subscriberid' => '42',
        ],
        [
            'id' => '99',
            'tstamp' => '2026-08-03T10:00:00-05:00',
            'reference_type' => 'SubscriberTag',
            'reference_id' => '7',
            'reference_action' => '',
            'referenceModelName' => 'contact-tag',
            'subscriberid' => '42',
        ],
    ];

    Http::fake([
        'https://ac.test/api/3/contacts/42' => Http::response([
            'contact' => [
                'id' => '42',
                'email' => 'cliente@example.com',
                'firstName' => 'Ana',
                'lastName' => 'Pérez',
                'phone' => '+525512345678',
                'cdate' => '2024-01-15T10:00:00-06:00',
                'udate' => '2026-08-01T12:00:00-05:00',
                'sentcnt' => '10',
                'owner' => '3',
            ],
        ], 200),
        'https://ac.test/api/3/contacts/42/contactData' => $contactDataResponse ?? Http::response([
            'contactDatum' => [
                'geoCity' => 'Monterrey',
                'geoState' => 'Nuevo León',
                'geo_country' => 'Mexico',
                'geoCountry2' => 'MX',
                'geoZip' => '64000',
                'geoLat' => '25.686600',
                'geoLon' => '-100.316100',
                'geoIp4' => '187.190.1.1',
                'geoTz' => 'America/Monterrey',
            ],
        ], 200),
        'https://ac.test/api/3/contacts/42/contactTags' => Http::response([
            'contactTags' => [
                ['id' => '9', 'contact' => '42', 'tag' => '7', 'cdate' => '2024-02-01T00:00:00-06:00'],
            ],
        ], 200),
        'https://ac.test/api/3/contacts/42/contactLists' => Http::response([
            'contactLists' => [
                ['id' => '3', 'contact' => '42', 'list' => '5', 'status' => '1', 'sdate' => '2024-01-15T10:00:00-06:00'],
            ],
        ], 200),
        'https://ac.test/api/3/contacts/42/fieldValues' => Http::response([
            'fieldValues' => [
                ['id' => '11', 'contact' => '42', 'field' => '18', 'value' => 'Pérez'],
                ['id' => '12', 'contact' => '42', 'field' => '20', 'value' => 'Odessa'],
                ['id' => '13', 'contact' => '42', 'field' => '21', 'value' => 'Monterrey'],
            ],
        ], 200),
        'https://ac.test/api/3/contacts/42/contactAutomations' => Http::response([
            'contactAutomations' => [
                [
                    'id' => '6',
                    'contact' => '42',
                    'automation' => '2',
                    'status' => '1',
                    'adddate' => '2024-03-01T08:00:00-06:00',
                    'lastdate' => '2024-03-02T08:00:00-06:00',
                    'completedElements' => 1,
                    'totalElements' => 3,
                    'completeValue' => 33,
                ],
            ],
        ], 200),
        'https://ac.test/api/3/contacts/42/scoreValues' => Http::response([
            'scoreValues' => [
                ['id' => '1', 'score' => '2', 'contact' => '42', 'scoreValue' => '55', 'mdate' => '2026-08-01T12:00:00-05:00'],
            ],
        ], 200),
        'https://ac.test/api/3/users/3' => Http::response([
            'user' => [
                'id' => '3',
                'username' => 'agente.ac',
                'firstName' => 'Luis',
                'lastName' => 'García',
                'email' => 'luis@famedic.test',
            ],
        ], 200),
        'https://ac.test/api/3/scores*' => Http::response([
            'scores' => [['id' => '2', 'name' => 'Lead Score']],
            'meta' => ['total' => 1],
        ], 200),
        'https://ac.test/api/3/activities*' => Http::response([
            'activities' => $activities,
        ], 200),
        'https://ac.test/api/3/tags*' => Http::response([
            'tags' => [['id' => '7', 'tag' => 'RegistroNuevo']],
            'meta' => ['total' => 1],
        ], 200),
        'https://ac.test/api/3/lists*' => Http::response([
            'lists' => [['id' => '5', 'name' => 'Nuevos usuarios']],
            'meta' => ['total' => 1],
        ], 200),
        'https://ac.test/api/3/automations*' => Http::response([
            'automations' => [['id' => '2', 'name' => 'Onboarding']],
            'meta' => ['total' => 1],
        ], 200),
        'https://ac.test/api/3/fields*' => Http::response([
            'fields' => [
                ['id' => '18', 'title' => 'Apellido Paterno', 'perstag' => '%APELLIDO_PATERNO%', 'type' => 'text'],
                ['id' => '20', 'title' => 'Empresa', 'perstag' => '%EMPRESA%', 'type' => 'text'],
                ['id' => '21', 'title' => 'Ciudad', 'perstag' => '%CIUDAD%', 'type' => 'text'],
            ],
            'meta' => ['total' => 3],
        ], 200),
        'https://ac.test/api/3/contacts*' => Http::response([
            'contacts' => [['id' => '42', 'email' => 'cliente@example.com']],
        ], 200),
    ]);
}

test('ActiveCampaignService getContact* lee endpoints oficiales', function () {
    fakeAcMirrorApi();

    $service = app(ActiveCampaignService::class);

    expect($service->getContact(42)['email'])->toBe('cliente@example.com');
    expect($service->getContactTags(42))->toHaveCount(1);
    expect($service->getContactLists(42)[0]['list'])->toBe('5');
    expect($service->getContactFieldValues(42)[0]['value'])->toBe('Pérez');
    expect($service->getContactAutomations(42)[0]['automation'])->toBe('2');
    expect($service->getContactScoreValues(42)[0]['scoreValue'])->toBe('55');
    expect($service->getContactActivities(42)['activities'])->toHaveCount(2);
    expect($service->getContactData(42)['geoCity'])->toBe('Monterrey');
    expect($service->getUser(3)['email'])->toBe('luis@famedic.test');
});

test('ActiveCampaignReadService transforma payload API a DTOs enriquecidos', function () {
    fakeAcMirrorApi();

    $read = app(ActiveCampaignReadService::class);
    $ac = app(ActiveCampaignService::class);

    $snapshot = $read->buildSnapshot(
        contact: $ac->getContact(42),
        contactData: $ac->getContactData(42),
        contactTags: $ac->getContactTags(42),
        contactLists: $ac->getContactLists(42),
        fieldValues: $ac->getContactFieldValues(42),
        contactAutomations: $ac->getContactAutomations(42),
        activities: $ac->getContactActivities(42)['activities'],
        scoreValues: $ac->getContactScoreValues(42),
        customerId: 20,
    );

    expect($snapshot)->toBeInstanceOf(ActiveCampaignContactSnapshot::class);
    expect($snapshot->tags[0]->name)->toBe('RegistroNuevo');
    expect($snapshot->lists[0]->name)->toBe('Nuevos usuarios');
    expect($snapshot->lists[0]->status)->toBe('Activa');
    expect($snapshot->fields)->toHaveCount(3);
    expect($snapshot->relevantFields)->toHaveCount(2);
    expect($snapshot->automations[0]->name)->toBe('Onboarding');
    expect($snapshot->location['city'])->toBe('Monterrey');
    expect($snapshot->lastActivity?->id)->toBe('100');
    expect($snapshot->leadScoreTotal())->toBe(55);
    expect($snapshot->leadScoreSummary()->classification)->toBe('Bueno');
    expect($snapshot->owner?->email)->toBe('luis@famedic.test');
    expect($snapshot->engagement?->emailsSent)->toBe(10);
    expect($snapshot->toArray()['lead_score'])->toBe(55);
    expect($snapshot->toArray()['lead_score_detail']['classification'])->toBe('Bueno');
});

test('ActiveCampaignMirrorService snapshot orquesta lectura y persiste ac_contact_id', function () {
    fakeAcMirrorApi();

    $user = User::factory()->create(['email' => 'cliente@example.com']);
    $customer = Customer::factory()->withRegularAccount()->create(['user_id' => $user->id]);

    $mirror = app(ActiveCampaignMirrorService::class);
    $snapshot = $mirror->snapshot($customer);

    expect($snapshot)->not->toBeNull();
    expect($snapshot->acContactId)->toBe(42);
    expect($snapshot->email)->toBe('cliente@example.com');
    expect($snapshot->fromCache)->toBeFalse();

    $customer->refresh();
    expect((int) $customer->ac_contact_id)->toBe(42);
    expect($customer->ac_last_sync_at)->not->toBeNull();
    expect($customer->ac_location)->toBe([
        'city' => 'Monterrey',
        'state' => 'Nuevo León',
        'country' => 'Mexico',
        'timezone' => 'America/Monterrey',
        'source' => 'activecampaign',
    ]);
    expect($customer->ac_location_cached_at)->not->toBeNull();
    expect($customer->ac_location)->not->toHaveKey('geoIp4');
    expect($customer->ac_location)->not->toHaveKey('geoLat');
    expect($customer->ac_location)->not->toHaveKey('geoLon');

    $cached = $mirror->snapshot($customer);
    expect($cached?->fromCache)->toBeTrue();
    expect($cached?->acContactId)->toBe(42);
});

test('ActiveCampaignMirrorService persists valid Site Tracking logs from existing activities payload', function () {
    fakeAcMirrorApi(null, [
        [
            'id' => 'web-activity',
            'tstamp' => '2026-08-21T13:41:00-05:00',
            'reference_type' => 'TrackingLog',
            'reference_id' => 'tracking-log-1',
            'reference_action' => '',
            'subscriberid' => '42',
            'jsonData' => [
                'url' => 'https://famedic.com.mx/laboratory/olab/checkout?brand=olab#step',
                'title' => 'Checkout OLAB',
            ],
        ],
        [
            'id' => 'generic-log',
            'tstamp' => '2026-08-21T13:42:00-05:00',
            'reference_type' => 'Log',
            'reference_id' => 'mail-log',
            'subscriberid' => '42',
        ],
    ]);

    $user = User::factory()->create(['email' => 'cliente@example.com']);
    $customer = Customer::factory()->withRegularAccount()->create(['user_id' => $user->id]);

    app(ActiveCampaignMirrorService::class)->snapshot($customer);
    app(ActiveCampaignMirrorService::class)->forget($customer);
    app(ActiveCampaignMirrorService::class)->snapshot($customer->refresh(), forceRefresh: true);

    expect(ActiveCampaignWebActivity::query()->count())->toBe(1);

    $activity = ActiveCampaignWebActivity::query()->first();
    expect($activity->path)->toBe('/laboratory/olab/checkout')
        ->and($activity->title)->toBe('Checkout OLAB')
        ->and($activity->raw_reference_type)->toBe('TrackingLog')
        ->and($activity->raw_reference_id)->toBe('tracking-log-1');
});

test('ActiveCampaignMirrorService web activity sync error does not break snapshot', function () {
    fakeAcMirrorApi();

    $this->app->instance(
        ActiveCampaignWebActivitySyncService::class,
        new class extends ActiveCampaignWebActivitySyncService
        {
            public function __construct() {}

            public function syncForCustomer(Customer $customer, int $acContactId, array $activities): void
            {
                throw new RuntimeException('db unavailable');
            }
        },
    );

    $user = User::factory()->create(['email' => 'cliente@example.com']);
    $customer = Customer::factory()->withRegularAccount()->create(['user_id' => $user->id]);

    $snapshot = app(ActiveCampaignMirrorService::class)->snapshot($customer);

    expect($snapshot)->not->toBeNull()
        ->and($snapshot->acContactId)->toBe(42)
        ->and(ActiveCampaignWebActivity::query()->count())->toBe(0);
});

test('ActiveCampaignMirrorService does not invent location when contactData has no geo values', function () {
    fakeAcMirrorApi(Http::response([
        'contactDatum' => [
            'geoCity' => '',
            'geoState' => '',
            'geo_country' => '',
            'geoCountry2' => '',
            'geoTz' => '',
        ],
    ], 200));

    $user = User::factory()->create(['email' => 'cliente@example.com']);
    $customer = Customer::factory()->withRegularAccount()->create(['user_id' => $user->id]);

    $snapshot = app(ActiveCampaignMirrorService::class)->snapshot($customer);

    expect($snapshot)->not->toBeNull();
    expect($customer->refresh()->ac_location)->toBeNull();
    expect($customer->ac_location_cached_at)->toBeNull();
});

test('ActiveCampaignMirrorService contactData error does not break snapshot or write unsafe location', function () {
    fakeAcMirrorApi(Http::response(['message' => 'temporary error'], 500));

    $user = User::factory()->create(['email' => 'cliente@example.com']);
    $customer = Customer::factory()->withRegularAccount()->create([
        'user_id' => $user->id,
        'ac_location' => [
            'city' => 'Monterrey',
            'state' => 'Nuevo Leon',
            'country' => 'Mexico',
            'timezone' => 'America/Monterrey',
            'source' => 'activecampaign',
        ],
        'ac_location_cached_at' => now()->subDay(),
    ]);

    $snapshot = app(ActiveCampaignMirrorService::class)->snapshot($customer);

    expect($snapshot)->not->toBeNull();
    expect($customer->refresh()->ac_location['city'])->toBe('Monterrey');
    expect($customer->ac_last_sync_at)->not->toBeNull();
});

test('ActiveCampaignCacheService usa TTL de 5 minutos', function () {
    expect(ActiveCampaignCacheService::TTL_SECONDS)->toBe(300);
});
