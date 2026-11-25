<?php

namespace App\Console\Commands;

use App\Data\Api\V1\Uat\AkubicaUatFixtureContract;
use App\Enums\Kinship;
use App\Enums\LaboratoryBrand;
use App\Models\Api\V1\AkubicaUatFixtureManifest;
use App\Models\FamilyAccount;
use App\Models\LaboratoryAppointment;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * RC2 — fixtures sintéticos complementarios para R04 (familiares) y R05 (citas)
 * y verificación de solo lectura del teléfono desconocido para A02/A09.
 *
 * Reutiliza el manifiesto existente `akubica-uat-v1` (akubica:uat-fixtures):
 * no crea usuarios ni clientes; solo cuelga registros marcados de los clientes
 * sintéticos `primary` y `foreign` ya materializados.
 *
 * - Por defecto DRY-RUN (sin escrituras).
 * - --apply / --reset exigen --confirm=akubica-uat-v1.
 * - Prohibido fuera de testing/staging (guardia de entorno, igual que akubica:uat-fixtures).
 * - Idempotente: los registros se identifican por marcadores fijos; reaplicar no duplica.
 * - Rollback: --reset elimina (forceDelete) EXCLUSIVAMENTE registros con marcador.
 * - Sin datos personales reales: nombres y notas son literales sintéticos; sin teléfono.
 */
class MaterializeAkubicaUatR04R05FixturesCommand extends Command
{
    public const MARKER = 'akubica-uat-v1:rc2-r04r05';
    public const FAMILY_NAME = 'RC2 UAT FAMILIAR';
    public const FAMILY_LASTNAME = 'SINTETICO';
    public const FAMILY_LASTNAME_2 = 'AKUBICA-UAT-V1';
    public const PATIENT_NAME = 'RC2 UAT PACIENTE';

    protected $signature = 'akubica:uat-fixtures-r04r05
                            {--dry-run : Plan y verificaciones sin escribir (por defecto)}
                            {--apply : Crea familiares y citas sintéticos (idempotente)}
                            {--reset : Elimina solo registros con marcador RC2}
                            {--family-only : Prepara o restablece únicamente familiares; nunca crea citas}
                            {--confirm= : Debe ser exactamente akubica-uat-v1 para apply/reset}
                            {--namespace= : Namespace dedicado permitido; por defecto akubica-uat-v1}
                            {--check-unknown-phone : Verifica (lectura) que QA_UNKNOWN_PHONE no exista; lee el número del entorno}';

    protected $description = 'Fixtures sintéticos RC2 R04/R05 sobre el manifiesto akubica-uat-v1 (QA únicamente).';

    public function handle(): int
    {
        if (! app()->environment((array) config('akubica_uat.allowed_environments', ['testing', 'staging']))
            || app()->environment('production')) {
            return $this->out('error', ['error_code' => 'UAT_ENVIRONMENT_NOT_ALLOWED']);
        }

        $apply = (bool) $this->option('apply');
        $reset = (bool) $this->option('reset');
        $familyOnly = (bool) $this->option('family-only');
        if ($apply && $reset) {
            return $this->out('error', ['error_code' => 'UAT_INVALID_OPTION_COMBINATION']);
        }
        $namespace = (string) ($this->option('namespace') ?: AkubicaUatFixtureContract::NAMESPACE);
        if (! AkubicaUatFixtureContract::isAllowedNamespace($namespace)) {
            return $this->out('error', ['error_code' => 'UAT_NAMESPACE_NOT_ALLOWED']);
        }
        if (($apply || $reset) && (string) $this->option('confirm') !== $namespace) {
            return $this->out('error', ['error_code' => 'UAT_CONFIRMATION_MISMATCH']);
        }

        $manifest = AkubicaUatFixtureManifest::query()->where('namespace', $namespace)->latest('id')->first();
        if (! $manifest || $manifest->status !== AkubicaUatFixtureContract::STATUS_ACTIVE) {
            return $this->out('error', ['error_code' => 'UAT_FIXTURES_NOT_ACTIVE']);
        }
        $ids = (array) Arr::get((array) ($manifest->ids ?? $manifest->metadata ?? []), 'ids', []);
        $customers = array_filter([
            'primary' => Arr::get($ids, 'customers.primary'),
            'foreign' => Arr::get($ids, 'customers.foreign'),
        ]);
        $storeId = Arr::first((array) Arr::get($ids, 'store', [])) ?: null;
        if (count($customers) !== 2) {
            return $this->out('error', ['error_code' => 'UAT_FIXTURE_CUSTOMERS_MISSING']);
        }

        $marker = $this->marker($namespace);
        $familyName = $this->familyName($namespace);
        $pre = $this->snapshot($customers, $familyName, $marker);
        $unknown = $this->option('check-unknown-phone') ? $this->unknownPhoneCheck() : null;

        if (! $apply && ! $reset) {
            return $this->out('ok', [
                'action' => 'dry-run',
                'customers' => $customers,
                'pre' => $pre,
                'would_create' => [
                    'family_accounts' => collect($pre)->filter(fn ($s) => $s['family_marked'] === 0)->keys()->values(),
                    'laboratory_appointments' => $familyOnly ? [] : collect($pre)->filter(fn ($s) => $s['appointments_marked'] === 0)->keys()->values(),
                ],
                'unknown_phone' => $unknown,
            ]);
        }

        $changes = [];
        DB::transaction(function () use ($apply, $reset, $familyOnly, $customers, $storeId, $marker, $familyName, &$changes): void {
            foreach ($customers as $role => $customerId) {
                if ($apply) {
                    $family = FamilyAccount::query()->withTrashed()->firstOrCreate(
                        ['customer_id' => $customerId, 'name' => $familyName, 'paternal_lastname' => self::FAMILY_LASTNAME, 'maternal_lastname' => self::FAMILY_LASTNAME_2],
                        ['kinship' => Kinship::CHILD, 'birth_date' => '2015-01-01'],
                    );
                    if ($family->trashed()) {
                        $family->restore();
                    }
                    $changes[] = ['role' => $role, 'table' => 'family_accounts', 'id' => $family->id, 'op' => $family->wasRecentlyCreated ? 'created' : 'kept'];

                    if ($familyOnly) {
                        continue;
                    }
                    $appointment = LaboratoryAppointment::query()->withTrashed()->firstOrCreate(
                        ['customer_id' => $customerId, 'notes' => $marker],
                        [
                            'brand' => LaboratoryBrand::OLAB->value,
                            'laboratory_store_id' => $storeId,
                            'patient_name' => self::PATIENT_NAME,
                            'patient_paternal_lastname' => self::FAMILY_LASTNAME,
                            'patient_maternal_lastname' => self::FAMILY_LASTNAME_2,
                            'appointment_date' => now()->addDays(7)->setTime(9, 0),
                            'confirmed_at' => now(),
                        ],
                    );
                    if ($appointment->trashed()) {
                        $appointment->restore();
                    }
                    $changes[] = ['role' => $role, 'table' => 'laboratory_appointments', 'id' => $appointment->id, 'op' => $appointment->wasRecentlyCreated ? 'created' : 'kept'];
                } else {
                    $f = FamilyAccount::query()->withTrashed()->where('customer_id', $customerId)
                        ->where('name', $familyName)->where('maternal_lastname', self::FAMILY_LASTNAME_2);
                    $a = LaboratoryAppointment::query()->withTrashed()->where('customer_id', $customerId)->where('notes', $marker);
                    $changes[] = ['role' => $role, 'table' => 'family_accounts', 'ids' => $f->pluck('id')->all(), 'op' => 'deleted'];
                    // Use the already ownership-scoped IDs for deterministic hard cleanup.
                    // This avoids model-event/soft-delete ambiguity in the family-only path.
                    $familyIds = $f->pluck('id')->all();
                    if ($familyIds !== []) {
                        DB::table('family_accounts')->whereIn('id', $familyIds)->delete();
                    }
                    if (! $familyOnly) {
                        $changes[] = ['role' => $role, 'table' => 'laboratory_appointments', 'ids' => $a->pluck('id')->all(), 'op' => 'deleted'];
                        $a->forceDelete();
                    }
                }
            }
        });

        // Reconcile the family-only reset after the transaction as a final
        // ownership-scoped guard; appointment rows are intentionally excluded.
        if ($reset && $familyOnly) {
            foreach ($customers as $customerId) {
                DB::table('family_accounts')
                    ->where('customer_id', $customerId)
                    ->where('name', $familyName)
                    ->where('maternal_lastname', self::FAMILY_LASTNAME_2)
                    ->delete();
            }
        }

        $post = $this->snapshot($customers, $familyName, $marker);
        Log::info('akubica_uat_r04r05_fixtures', ['action' => $apply ? 'apply' : 'reset', 'family_only' => $familyOnly, 'changes' => $changes]);

        return $this->out('ok', ['action' => $apply ? 'apply' : 'reset', 'namespace' => $namespace, 'family_only' => $familyOnly, 'pre' => $pre, 'changes' => $changes, 'post' => $post, 'unknown_phone' => $unknown]);
    }

    /** @param array<string,int> $customers */
    private function snapshot(array $customers, string $familyName, string $marker): array
    {
        $out = [];
        foreach ($customers as $role => $customerId) {
            $out[$role] = [
                'customer_id' => (int) $customerId,
                'family_total' => FamilyAccount::query()->where('customer_id', $customerId)->count(),
                'family_marked' => FamilyAccount::query()->where('customer_id', $customerId)->where('name', $familyName)->where('maternal_lastname', self::FAMILY_LASTNAME_2)->count(),
                'appointments_total' => LaboratoryAppointment::query()->where('customer_id', $customerId)->count(),
                'appointments_marked' => LaboratoryAppointment::query()->where('customer_id', $customerId)->where('notes', $marker)->count(),
            ];
        }

        return $out;
    }

    private function marker(string $namespace): string
    {
        return $namespace.':rc2-r04r05';
    }

    private function familyName(string $namespace): string
    {
        return $namespace === AkubicaUatFixtureContract::NAMESPACE
            ? self::FAMILY_NAME
            : self::FAMILY_NAME.' '.$namespace;
    }

    /** Solo lectura; nunca imprime el número. */
    private function unknownPhoneCheck(): array
    {
        $raw = (string) env('QA_UNKNOWN_PHONE', '');
        $digits = preg_replace('/\D+/', '', $raw) ?? '';
        if (strlen($digits) < 10) {
            return ['checked' => false, 'reason' => 'QA_UNKNOWN_PHONE ausente o inválido'];
        }
        $national = substr($digits, -10);
        // TODO: confirmar el formato persistido por RawPhoneNumberCast; se consultan ambas formas.
        $exists = User::query()->whereIn('phone', [$national, '+52'.$national, '52'.$national])->exists();

        return ['checked' => true, 'phone_hash' => hash('sha256', $national), 'exists' => $exists, 'apto_para_A02_A09' => ! $exists];
    }

    private function out(string $status, array $payload): int
    {
        $this->line(json_encode(array_merge(['status' => $status], $payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        return $status === 'ok' ? self::SUCCESS : self::FAILURE;
    }
}
