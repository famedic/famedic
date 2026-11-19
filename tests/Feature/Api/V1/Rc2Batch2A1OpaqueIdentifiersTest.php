<?php

// RC2 Batch 2 · A1 (rev7 L08 LeoFamilyMember.id, L13b LeoOpaqueTaxProfileId): LeoOpaqueIdentifiers service.
// Stability, isolation by customer and by resource type, first-read concurrency over the real unique constraint,
// and rejection of references whose subject was deleted or transferred (ownership is revalidated on resolve).
// LOCAL ONLY (MySQL harness). This is not STAGING_PASS and certifies nothing.
use App\Domain\Akubica\Benefits\OpaqueReference;
use App\Enums\Kinship;
use App\Models\FamilyAccount;
use App\Models\TaxProfile;
use App\Models\User;
use App\Services\Api\V1\Leo\LeoOpaqueIdentifiers;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

function oiCustomer(): int
{
    return User::factory()->withRegularCustomer()->create()->customer->id;
}

function oiFamily(int $customerId): FamilyAccount
{
    return FamilyAccount::query()->create(['customer_id' => $customerId, 'name' => 'Ana', 'paternal_lastname' => 'Sintetica', 'maternal_lastname' => 'Prueba', 'kinship' => Kinship::CHILD]);
}

function oiTax(int $customerId): TaxProfile
{
    $profile = (new TaxProfile)->forceFill(['customer_id' => $customerId, 'name' => 'Persona Sintetica', 'razon_social' => 'Persona Sintetica', 'rfc' => 'SIPA800101Q12', 'zipcode' => '01000', 'tax_regime' => '605', 'cfdi_use' => 'D01', 'domicilio_fiscal' => 'Calle Sintetica 1', 'fiscal_certificate' => 'certificates/a1-sintetica.pdf', 'is_default' => false]);
    $profile->save();

    return $profile;
}

function oiRows(int $customerId, string $type, int $subjectId): int
{
    return DB::table('api_opaque_identifiers')->where(['customer_id' => $customerId, 'subject_type' => $type, 'subject_id' => $subjectId])->count();
}

test('a reference is stable across repeated reads, random, prefixed by type and stored only as hash plus ciphertext', function () {
    $ids = app(LeoOpaqueIdentifiers::class);
    $customer = oiCustomer();
    $member = oiFamily($customer);

    $first = $ids->forSubject($customer, LeoOpaqueIdentifiers::FAMILY_MEMBER, $member->id);
    $again = $ids->forSubject($customer, LeoOpaqueIdentifiers::FAMILY_MEMBER, $member->id);

    expect($again)->toBe($first)
        ->and(OpaqueReference::valid($first, 'fam'))->toBeTrue()
        ->and(oiRows($customer, LeoOpaqueIdentifiers::FAMILY_MEMBER, $member->id))->toBe(1);
    $row = DB::table('api_opaque_identifiers')->where('customer_id', $customer)->first();
    expect($row->ref_hash)->toBe(hash('sha256', $first))
        ->and($row->ref_ciphertext)->not->toContain($first)
        ->and(Crypt::decryptString($row->ref_ciphertext))->toBe($first)
        ->and($ids->resolve($customer, LeoOpaqueIdentifiers::FAMILY_MEMBER, $first))->toBe($member->id);
});

test('references are isolated by customer and by resource type', function () {
    $ids = app(LeoOpaqueIdentifiers::class);
    $a = oiCustomer();
    $b = oiCustomer();
    $member = oiFamily($a);
    $tax = oiTax($a);

    $fam = $ids->forSubject($a, LeoOpaqueIdentifiers::FAMILY_MEMBER, $member->id);
    $taxRef = $ids->forSubject($a, LeoOpaqueIdentifiers::TAX_PROFILE, $tax->id);

    expect($fam)->toStartWith('fam_')->and($taxRef)->toStartWith('tax_')->and($fam)->not->toBe($taxRef)
        ->and($ids->resolve($b, LeoOpaqueIdentifiers::FAMILY_MEMBER, $fam))->toBeNull()
        ->and($ids->resolve($b, LeoOpaqueIdentifiers::TAX_PROFILE, $taxRef))->toBeNull()
        ->and($ids->resolve($a, LeoOpaqueIdentifiers::TAX_PROFILE, $fam))->toBeNull()
        ->and($ids->resolve($a, LeoOpaqueIdentifiers::FAMILY_MEMBER, $taxRef))->toBeNull()
        ->and($ids->resolve($a, LeoOpaqueIdentifiers::FAMILY_MEMBER, (string) $member->id))->toBeNull()
        ->and($ids->resolve($a, LeoOpaqueIdentifiers::FAMILY_MEMBER, 'fam_'.base64_encode((string) $member->id)))->toBeNull();

    // The same numeric subject id under another type gets a different reference.
    expect($ids->forSubject($a, LeoOpaqueIdentifiers::TAX_PROFILE, $member->id))->not->toBe($fam);
    expect(fn () => $ids->forSubject($a, 'order', 1))->toThrow(\InvalidArgumentException::class);
});

test('resolution never replaces ownership: a reference issued for a subject of another customer does not resolve', function () {
    $ids = app(LeoOpaqueIdentifiers::class);
    $a = oiCustomer();
    $b = oiCustomer();
    $memberOfA = oiFamily($a);

    // Even if a caller mistakenly issued a reference to customer B for A's member, resolving it for B is rejected,
    // because resolve() revalidates that the subject still belongs to the customer.
    $misissued = $ids->forSubject($b, LeoOpaqueIdentifiers::FAMILY_MEMBER, $memberOfA->id);
    expect($ids->resolve($b, LeoOpaqueIdentifiers::FAMILY_MEMBER, $misissued))->toBeNull()
        ->and($ids->resolve($a, LeoOpaqueIdentifiers::FAMILY_MEMBER, $misissued))->toBeNull();
});

test('first-read concurrency: a competing first emission between lookup and insert yields one row and the same reference, without error', function () {
    $ids = app(LeoOpaqueIdentifiers::class);
    $customer = oiCustomer();
    $member = oiFamily($customer);
    $competitor = OpaqueReference::issue('fam');
    $raced = false;

    // Interleave a concurrent emitter exactly after this request's first lookup and before its insert, so the
    // service's insert hits the real unique (customer_id, subject_type, subject_id) index.
    DB::listen(function (QueryExecuted $query) use (&$raced, $customer, $member, $competitor) {
        if ($raced || ! str_starts_with(strtolower(ltrim($query->sql)), 'select') || ! str_contains($query->sql, 'api_opaque_identifiers')) {
            return;
        }
        $raced = true;
        DB::table('api_opaque_identifiers')->insert([
            'customer_id' => $customer, 'subject_type' => LeoOpaqueIdentifiers::FAMILY_MEMBER, 'subject_id' => $member->id,
            'ref_hash' => hash('sha256', $competitor), 'ref_ciphertext' => Crypt::encryptString($competitor),
            'created_at' => now(), 'updated_at' => now(),
        ]);
    });

    $reference = $ids->forSubject($customer, LeoOpaqueIdentifiers::FAMILY_MEMBER, $member->id);

    expect($raced)->toBeTrue()
        ->and($reference)->toBe($competitor)
        ->and(oiRows($customer, LeoOpaqueIdentifiers::FAMILY_MEMBER, $member->id))->toBe(1)
        ->and($ids->forSubject($customer, LeoOpaqueIdentifiers::FAMILY_MEMBER, $member->id))->toBe($competitor)
        ->and($ids->resolve($customer, LeoOpaqueIdentifiers::FAMILY_MEMBER, $competitor))->toBe($member->id);
});

test('the unique constraints are real: a duplicate subject row or a duplicate reference hash is rejected by the database', function () {
    $ids = app(LeoOpaqueIdentifiers::class);
    $customer = oiCustomer();
    $member = oiFamily($customer);
    $reference = $ids->forSubject($customer, LeoOpaqueIdentifiers::FAMILY_MEMBER, $member->id);
    $base = ['customer_id' => $customer, 'subject_type' => LeoOpaqueIdentifiers::FAMILY_MEMBER, 'ref_ciphertext' => Crypt::encryptString('x'), 'created_at' => now(), 'updated_at' => now()];

    expect(fn () => DB::table('api_opaque_identifiers')->insert($base + ['subject_id' => $member->id, 'ref_hash' => hash('sha256', OpaqueReference::issue('fam'))]))
        ->toThrow(QueryException::class)
        ->and(fn () => DB::table('api_opaque_identifiers')->insert($base + ['subject_id' => $member->id + 1000, 'ref_hash' => hash('sha256', $reference)]))
        ->toThrow(QueryException::class);
});

test('a reference whose subject was deleted or transferred to another customer is rejected', function () {
    $ids = app(LeoOpaqueIdentifiers::class);
    $a = oiCustomer();
    $b = oiCustomer();
    $deleted = oiFamily($a);
    $transferred = oiFamily($a);
    $tax = oiTax($a);
    $refDeleted = $ids->forSubject($a, LeoOpaqueIdentifiers::FAMILY_MEMBER, $deleted->id);
    $refTransferred = $ids->forSubject($a, LeoOpaqueIdentifiers::FAMILY_MEMBER, $transferred->id);
    $refTax = $ids->forSubject($a, LeoOpaqueIdentifiers::TAX_PROFILE, $tax->id);
    expect($ids->resolve($a, LeoOpaqueIdentifiers::FAMILY_MEMBER, $refDeleted))->toBe($deleted->id);

    $deleted->delete();
    DB::table('family_accounts')->where('id', $transferred->id)->update(['customer_id' => $b]);
    $tax->delete();

    expect($ids->resolve($a, LeoOpaqueIdentifiers::FAMILY_MEMBER, $refDeleted))->toBeNull()
        ->and($ids->resolve($a, LeoOpaqueIdentifiers::FAMILY_MEMBER, $refTransferred))->toBeNull()
        ->and($ids->resolve($b, LeoOpaqueIdentifiers::FAMILY_MEMBER, $refTransferred))->toBeNull()
        ->and($ids->resolve($a, LeoOpaqueIdentifiers::TAX_PROFILE, $refTax))->toBeNull();

    // The new owner gets its own, different reference; the old one never moves with the subject.
    $refForB = $ids->forSubject($b, LeoOpaqueIdentifiers::FAMILY_MEMBER, $transferred->id);
    expect($refForB)->not->toBe($refTransferred)
        ->and($ids->resolve($b, LeoOpaqueIdentifiers::FAMILY_MEMBER, $refForB))->toBe($transferred->id);
});
