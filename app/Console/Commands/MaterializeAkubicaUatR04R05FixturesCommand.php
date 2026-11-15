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
                            {--confirm= : Debe ser exactamente akubica-uat-v1 para apply/reset}
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
        if ($apply && $reset) {
            return $this->out('error', ['error_code' => 'UAT_INVALID_OPTION_COMBINATION']);
        }
        if (($apply || $reset) && (string) $this->option('confirm') !== AkubicaUatFixtureContract::NAMESPACE) {
            return $this->out('error', ['error_code' => 'UAT_CONFIRMATION_MISMATCH']);
        }

        $manifest = AkubicaUatFixtureManifest::query()->where('namespace', AkubicaUatFixtureContract::NAMESPACE)->first();
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

        $pre = $this->snapshot($customers);
        $unknown = $this->option('check-unknown-phone') ? $this->unknownPhoneCheck() : null;

        if (! $apply && ! $reset) {
            return $this->out('ok', [
                'action' => 'dry-run',
                'customers' => $customers,
                'pre' => $pre,
                'would_create' => [
                    'family_accounts' => collect($pre)->filter(fn ($s) => $s['family_marked'] === 0)->keys()->values(),
                    'laboratory_appointments' => collect($pre)->filter(fn ($s) => $s['appointments_marked'] === 0)->keys()->values(),
                ],
                'unknown_phone' => $unknown,
            ]);
        }

        $changes = [];
        DB::transaction(function () use ($apply, $customers, $storeId, &$changes): void {
            foreach ($customers as $role => $customerId) {
                if ($apply) {
                    $family = FamilyAccount::query()->withTrashed()->firstOrCreate(
                        ['customer_id' => $customerId, 'name' => self::FAMILY_NAME, 'paternal_lastname' => self::FAMILY_LASTNAME, 'maternal_lastname' => self::FAMILY_LASTNAME_2],
                        ['kinship' => Kinship::CHILD, 'birth_date' => '2015-01-01'],
                    );
                    if ($family->trashed()) {
                        $family->restore();
                    }
                    $changes[] = ['role' => $role, 'table' => 'family_accounts', 'id' => $family->id, 'op' => $family->wasRecentlyCreated ? 'created' : 'kept'];

                    $appointment = LaboratoryAppointment::query()->withTrashed()->firstOrCreate(
                        ['customer_id' => $customerId, 'notes' => self::MARKER],
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
                        ->where('name', self::FAMILY_NAME)->where('maternal_lastname', self::FAMILY_LASTNAME_2);
                    $a = LaboratoryAppointment::query()->withTrashed()->where('customer_id', $customerId)->where('notes', self::MARKER);
                    $changes[] = ['role' => $role, 'table' => 'family_accounts', 'ids' => $f->pluck('id')->all(), 'op' => 'deleted'];
                    $changes[] = ['role' => $role, 'table' => 'laboratory_appointments', 'ids' => $a->pluck('id')->all(), 'op' => 'deleted'];
                    $f->forceDelete();
                    $a->forceDelete();
                }
            }
        });

        $post = $this->snapshot($customers);
        Log::info('akubica_uat_r04r05_fixtures', ['action' => $apply ? 'apply' : 'reset', 'changes' => $changes]);

        return $this->out('ok', ['action' => $apply ? 'apply' : 'reset', 'pre' => $pre, 'changes' => $changes, 'post' => $post, 'unknown_phone' => $unknown]);
    }

    /** @param array<string,int> $customers */
    private function snapshot(array $customers): array
    {
        $out = [];
        foreach ($customers as $role => $customerId) {
            $out[$role] = [
                'customer_id' => (int) $customerId,
                'family_total' => FamilyAccount::query()->where('customer_id', $customerId)->count(),
                'family_marked' => FamilyAccount::query()->where('customer_id', $customerId)->where('name', self::FAMILY_NAME)->where('maternal_lastname', self::FAMILY_LASTNAME_2)->count(),
                'appointments_total' => LaboratoryAppointment::query()->where('customer_id', $customerId)->count(),
                'appointments_marked' => LaboratoryAppointment::query()->where('customer_id', $customerId)->where('notes', self::MARKER)->count(),
            ];
        }

        return $out;
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
