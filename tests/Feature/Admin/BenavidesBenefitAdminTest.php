<?php

use App\Models\Administrator;
use App\Models\BenavidesCode;
use App\Models\BenavidesCodeImport;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

beforeEach(function () {
    Storage::fake('local');
});

function benavidesAdmin(bool $withPermission = true): User
{
    $user = User::factory()
        ->withCompleteProfile()
        ->create(['documentation_accepted_at' => now()]);

    $administrator = Administrator::factory()->for($user)->create();

    if ($withPermission) {
        $administrator->givePermissionTo(Permission::firstOrCreate([
            'name' => 'benavides-benefit.manage',
            'guard_name' => 'web',
        ]));
    }

    return $user->fresh('administrator');
}

function benavidesAdminWithRole(string $roleName = 'Administrador'): User
{
    $user = User::factory()
        ->withCompleteProfile()
        ->create(['documentation_accepted_at' => now()]);

    $administrator = Administrator::factory()->for($user)->create();
    $administrator->assignRole($roleName);

    return $user->fresh('administrator');
}

function benavidesAdminSession(): array
{
    return ['auth.password_confirmed_at' => time()];
}

function benavidesCsvUpload(string $contents, string $filename = 'benavides.csv'): UploadedFile
{
    $path = storage_path('framework/testing/'.$filename);
    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0777, true);
    }
    file_put_contents($path, $contents);

    return new UploadedFile($path, $filename, 'text/csv', null, true);
}

function benavidesPreview(User $admin, string $contents): int
{
    test()->actingAs($admin)
        ->withSession(benavidesAdminSession())
        ->post(route('admin.benavides-benefit.import.preview'), [
            'source_file' => benavidesCsvUpload($contents),
        ])
        ->assertRedirect(route('admin.benavides-benefit.import'));

    return BenavidesCodeImport::query()->latest('id')->value('id');
}

it('blocks customers from the Benavides admin monitor', function () {
    $this->actingAs(medicalAttentionUser())
        ->withSession(benavidesAdminSession())
        ->get(route('admin.benavides-benefit.index'))
        ->assertNotFound();
});

it('allows authorized administrators to access the Benavides admin monitor', function () {
    $this->actingAs(benavidesAdmin())
        ->withSession(benavidesAdminSession())
        ->get(route('admin.benavides-benefit.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/BenavidesBenefit/Index')
            ->has('metrics')
            ->has('codes'));
});

it('assigns the Benavides permission to admin roles', function () {
    $permission = Permission::query()
        ->where('name', 'benavides-benefit.manage')
        ->where('guard_name', 'web')
        ->first();

    expect($permission)->not->toBeNull()
        ->and(Role::where('name', 'Administrador')->first()->hasPermissionTo('benavides-benefit.manage'))->toBeTrue()
        ->and(Role::where('name', 'superadmin')->first()->hasPermissionTo('benavides-benefit.manage'))->toBeTrue();
});

it('allows administrators through the Administrador role permission', function () {
    $this->actingAs(benavidesAdminWithRole())
        ->withSession(benavidesAdminSession())
        ->get(route('admin.benavides-benefit.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('Admin/BenavidesBenefit/Index'));
});

it('shows the Benavides benefit item in the admin pharmacy sidebar when permitted', function () {
    $this->actingAs(benavidesAdminWithRole())
        ->withSession(benavidesAdminSession())
        ->get(route('admin.benavides-benefit.index'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->where('adminNavigation.1.label', 'OPERACIÓN')
            ->where('adminNavigation.1.items.1.label', 'Farmacia')
            ->where('adminNavigation.1.items.1.items.2.label', 'Beneficio Benavides')
            ->where('adminNavigation.1.items.1.items.2.url', route('admin.benavides-benefit.index'))
            ->where('adminNavigation.1.items.1.items.2.current', true));
});

it('hides the Benavides benefit sidebar item from admins without permission', function () {
    $this->actingAs(benavidesAdmin(false))
        ->withSession(benavidesAdminSession())
        ->get(route('admin.admin'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->missing('adminNavigation.1.items.1.items.2'));
});

it('blocks administrators without permission from importing', function () {
    $this->actingAs(benavidesAdmin(false))
        ->withSession(benavidesAdminSession())
        ->post(route('admin.benavides-benefit.import.preview'), [
            'source_file' => benavidesCsvUpload("code\n0001\n"),
        ])
        ->assertForbidden();
});

it('enforces the Benavides permission on every admin route', function () {
    $adminWithoutPermission = benavidesAdmin(false);
    $authorizedAdmin = benavidesAdmin();
    $import = BenavidesCodeImport::query()->create([
        'original_filename' => 'codes.csv',
        'status' => BenavidesCodeImport::STATUS_PREVIEWED,
        'uploaded_by_user_id' => $authorizedAdmin->id,
    ]);

    $this->actingAs($adminWithoutPermission)
        ->withSession(benavidesAdminSession())
        ->get(route('admin.benavides-benefit.index'))
        ->assertForbidden();

    $this->actingAs($adminWithoutPermission)
        ->withSession(benavidesAdminSession())
        ->get(route('admin.benavides-benefit.import'))
        ->assertForbidden();

    $this->actingAs($adminWithoutPermission)
        ->withSession(benavidesAdminSession())
        ->post(route('admin.benavides-benefit.import.preview'), [
            'source_file' => benavidesCsvUpload("code\n0001\n"),
        ])
        ->assertForbidden();

    $this->actingAs($adminWithoutPermission)
        ->withSession(benavidesAdminSession())
        ->post(route('admin.benavides-benefit.import.confirm'), ['import_id' => $import->id])
        ->assertForbidden();
});

it('allows authorized administrators to open every Benavides admin route', function () {
    $authorizedAdmin = benavidesAdminWithRole();
    $import = BenavidesCodeImport::query()->create([
        'original_filename' => 'codes.csv',
        'status' => BenavidesCodeImport::STATUS_PREVIEWED,
        'uploaded_by_user_id' => $authorizedAdmin->id,
    ]);

    $this->actingAs($authorizedAdmin)
        ->withSession(benavidesAdminSession())
        ->get(route('admin.benavides-benefit.index'))
        ->assertOk();

    $this->actingAs($authorizedAdmin)
        ->withSession(benavidesAdminSession())
        ->get(route('admin.benavides-benefit.import'))
        ->assertOk();

    $this->actingAs($authorizedAdmin)
        ->withSession(benavidesAdminSession())
        ->post(route('admin.benavides-benefit.import.preview'), [
            'source_file' => benavidesCsvUpload("code\n0001\n"),
        ])
        ->assertRedirect(route('admin.benavides-benefit.import'));

    $this->actingAs($authorizedAdmin)
        ->withSession(benavidesAdminSession())
        ->post(route('admin.benavides-benefit.import.confirm'), ['import_id' => $import->id])
        ->assertRedirect(route('admin.benavides-benefit.import'));
});

it('generates preview for a valid file and preserves leading zeroes', function () {
    $admin = benavidesAdmin();

    $importId = benavidesPreview($admin, "code\n000012345678\n000012345679\n");

    $run = BenavidesCodeImport::query()->first();

    expect($run->total_rows)->toBe(2)
        ->and($run->valid_rows)->toBe(2)
        ->and($run->summary_json['importable_rows'])->toBe(2)
        ->and($run->summary_json['examples']['duplicate_file'])->toBe([]);

    $this->actingAs($admin)
        ->withSession(benavidesAdminSession())
        ->post(route('admin.benavides-benefit.import.confirm'), ['import_id' => $importId]);

    expect(BenavidesCode::query()->where('code', '000012345678')->exists())->toBeTrue();
});

it('detects empty rows and duplicate rows inside the file', function () {
    $admin = benavidesAdmin();

    benavidesPreview($admin, "code\n000001\n\n000001\n000002\n");

    $run = BenavidesCodeImport::query()->first();

    expect($run->empty_rows)->toBe(1)
        ->and($run->duplicate_file_rows)->toBe(1)
        ->and($run->summary_json['importable_rows'])->toBe(2);
});

it('detects duplicate database rows and assigned conflicts', function () {
    $admin = benavidesAdmin();
    $assignedUser = medicalAttentionUser();
    BenavidesCode::factory()->create(['code' => '000010']);
    BenavidesCode::factory()->assignedTo($assignedUser)->create(['code' => '000011']);

    benavidesPreview($admin, "code\n000010\n000011\n000012\n");

    $run = BenavidesCodeImport::query()->first();

    expect($run->duplicate_database_rows)->toBe(2)
        ->and($run->assigned_conflict_rows)->toBe(1)
        ->and($run->summary_json['importable_rows'])->toBe(1);
});

it('imports new codes, associates batch and registers confirming user', function () {
    $admin = benavidesAdmin();
    $importId = benavidesPreview($admin, "code\n000020\n000021\n");

    $this->actingAs($admin)
        ->withSession(benavidesAdminSession())
        ->post(route('admin.benavides-benefit.import.confirm'), ['import_id' => $importId])
        ->assertRedirect(route('admin.benavides-benefit.import'));

    $run = BenavidesCodeImport::query()->find($importId);

    expect(BenavidesCode::query()->whereIn('code', ['000020', '000021'])->count())->toBe(2)
        ->and(BenavidesCode::query()->where('code', '000020')->value('import_batch_id'))->toBe($run->id)
        ->and($run->fresh()->confirmed_by_user_id)->toBe($admin->id)
        ->and($run->fresh()->imported_rows)->toBe(2);
});

it('does not duplicate existing codes or modify assigned codes during confirmation', function () {
    $admin = benavidesAdmin();
    $assignedUser = medicalAttentionUser();
    BenavidesCode::factory()->create(['code' => '000030']);
    $assigned = BenavidesCode::factory()->assignedTo($assignedUser)->create(['code' => '000031']);
    $importId = benavidesPreview($admin, "code\n000030\n000031\n000032\n");

    $this->actingAs($admin)
        ->withSession(benavidesAdminSession())
        ->post(route('admin.benavides-benefit.import.confirm'), ['import_id' => $importId])
        ->assertRedirect(route('admin.benavides-benefit.import'));

    expect(BenavidesCode::query()->where('code', '000030')->count())->toBe(1)
        ->and(BenavidesCode::query()->where('code', '000031')->count())->toBe(1)
        ->and($assigned->fresh()->user_id)->toBe($assignedUser->id)
        ->and(BenavidesCode::query()->where('code', '000032')->exists())->toBeTrue();
});

it('does not duplicate codes when confirmation is retried', function () {
    $admin = benavidesAdmin();
    $importId = benavidesPreview($admin, "code\n000040\n000041\n");

    $this->actingAs($admin)->withSession(benavidesAdminSession())
        ->post(route('admin.benavides-benefit.import.confirm'), ['import_id' => $importId]);
    $this->actingAs($admin)->withSession(benavidesAdminSession())
        ->post(route('admin.benavides-benefit.import.confirm'), ['import_id' => $importId]);

    expect(BenavidesCode::query()->whereIn('code', ['000040', '000041'])->count())->toBe(2);
});

it('rejects invalid files without importing', function () {
    $admin = benavidesAdmin();

    $this->actingAs($admin)
        ->withSession(benavidesAdminSession())
        ->post(route('admin.benavides-benefit.import.preview'), [
            'source_file' => UploadedFile::fake()->create('codes.txt', 1, 'text/plain'),
        ])
        ->assertSessionHasErrors('source_file');

    expect(BenavidesCode::query()->count())->toBe(0);
});

it('accepts CSV headers with BOM and supported aliases', function (string $header) {
    $admin = benavidesAdmin();

    benavidesPreview($admin, "\xEF\xBB\xBF{$header}\n000060\n");

    $run = BenavidesCodeImport::query()->first();

    expect($run->valid_rows)->toBe(1)
        ->and($run->summary_json['importable_rows'])->toBe(1);
})->with(['code', 'codigo', 'código', 'folio']);

it('rejects ambiguous multicolumn files without a recognized code header', function () {
    $admin = benavidesAdmin();

    $this->actingAs($admin)
        ->withSession(benavidesAdminSession())
        ->post(route('admin.benavides-benefit.import.preview'), [
            'source_file' => benavidesCsvUpload("nombre,valor\nFarmacia,000070\n"),
        ])
        ->assertSessionHasErrors('source_file');

    expect(BenavidesCodeImport::query()->count())->toBe(0)
        ->and(BenavidesCode::query()->count())->toBe(0);
});

it('shows correct monitor metrics and supports search and status filters', function () {
    $admin = benavidesAdmin();
    $assignedUser = medicalAttentionUser();
    BenavidesCode::factory()->create(['code' => '000050']);
    BenavidesCode::factory()->assignedTo($assignedUser)->create(['code' => '000051']);

    $this->actingAs($admin)
        ->withSession(benavidesAdminSession())
        ->get(route('admin.benavides-benefit.index'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('metrics.total', 2)
            ->where('metrics.available', 1)
            ->where('metrics.assigned', 1));

    $this->actingAs($admin)
        ->withSession(benavidesAdminSession())
        ->get(route('admin.benavides-benefit.index', ['search' => '000050']))
        ->assertInertia(fn (Assert $page) => $page->where('codes.data.0.code', '000050'));

    $this->actingAs($admin)
        ->withSession(benavidesAdminSession())
        ->get(route('admin.benavides-benefit.index', ['search' => $assignedUser->email]))
        ->assertInertia(fn (Assert $page) => $page->where('codes.data.0.code', '000051'));

    $this->actingAs($admin)
        ->withSession(benavidesAdminSession())
        ->get(route('admin.benavides-benefit.index', ['status' => 'available']))
        ->assertInertia(fn (Assert $page) => $page->where('codes.data.0.status', 'available'));

    $this->actingAs($admin)
        ->withSession(benavidesAdminSession())
        ->get(route('admin.benavides-benefit.index', ['status' => 'assigned']))
        ->assertInertia(fn (Assert $page) => $page->where('codes.data.0.status', 'assigned'));
});
