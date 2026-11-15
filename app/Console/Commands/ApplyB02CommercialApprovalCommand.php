<?php

namespace App\Console\Commands;

use App\Models\LaboratoryPackageCommercialApproval;
use App\Models\LaboratoryTestPackage;
use App\Models\LaboratoryTestPackageDescriptor;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * RC2 B02: registra la aprobación comercial de TODA la composición de un paquete.
 *
 * - Dry-run por defecto (no escribe). --apply exige --confirm=B02-COMMERCIAL-APPROVAL.
 * - Todo o nada: el archivo debe cubrir exactamente todos los estudios y descriptores
 *   del paquete; si falta uno, sobra uno o alguno no está pendiente, no se escribe nada.
 * - Cada elemento requiere una referencia aprobada con formato válido.
 * - Transaccional: filas bloqueadas (lockForUpdate), revalidación dentro de la transacción,
 *   actualización y registro de auditoría append-only en la misma transacción.
 * - Auditable: aprobador, referencia, fecha de aprobación, operador, correlation_id,
 *   hash del archivo de entrada y estado antes/después.
 */
final class ApplyB02CommercialApprovalCommand extends Command
{
    public const CONFIRMATION = 'B02-COMMERCIAL-APPROVAL';

    /** Referencia aprobada: PREFIJO-IDENTIFICADOR, p. ej. COM-B02-2026-001 o COM-B02-2026-001/125. */
    public const REFERENCE_PATTERN = '/^[A-Z]{2,10}-[A-Z0-9][A-Z0-9._\/-]{2,100}$/';

    protected $signature = 'akubica:b02-commercial-approval
        {--file= : Ruta al JSON de aprobación firmado por FAMEDIC Comercial}
        {--operator= : Persona que ejecuta el comando (queda en auditoría)}
        {--apply : Escribe (por defecto solo dry-run)}
        {--confirm= : Frase exacta requerida por --apply}';

    protected $description = 'RC2 B02: aprobación comercial transaccional y auditable de la composición de un paquete (dry-run por defecto).';

    public function handle(): int
    {
        $correlationId = 'b02-approval-'.Str::uuid()->toString();

        try {
            $apply = (bool) $this->option('apply');
            if ($apply && (string) $this->option('confirm') !== self::CONFIRMATION) {
                throw new RuntimeException('B02_CONFIRMATION_REQUIRED');
            }

            $operator = trim((string) $this->option('operator'));
            if (mb_strlen($operator) < 3) {
                throw new RuntimeException('B02_OPERATOR_REQUIRED');
            }

            [$input, $inputSha] = $this->readInput((string) $this->option('file'));
            $request = $this->validateInput($input);

            $package = LaboratoryTestPackage::query()
                ->where('package_test_id', $request['package_test_id'])
                ->first();
            if ($package === null) {
                throw new RuntimeException('B02_PACKAGE_NOT_FOUND');
            }

            $before = $this->snapshot($package->id);
            $plan = $this->plan($before, $request['items']);

            if ($plan['noop']) {
                $this->emit('noop', $correlationId, $request, $before, $before, $apply);

                return self::SUCCESS;
            }

            if (! $apply) {
                $this->emit('dry_run', $correlationId, $request, $before, $plan['after'], false);

                return self::SUCCESS;
            }

            $after = DB::transaction(function () use ($package, $request, $operator, $correlationId, $inputSha) {
                $lockedBefore = $this->snapshot($package->id, lock: true);
                $plan = $this->plan($lockedBefore, $request['items']);
                if ($plan['noop']) {
                    throw new RuntimeException('B02_CONCURRENT_CHANGE');
                }

                foreach ($request['items'] as $item) {
                    if ($item['type'] === 'study') {
                        $updated = DB::table('laboratory_test_package_components')
                            ->where('package_id', $package->id)
                            ->where('component_test_id', $item['id'])
                            ->where('validation_status', LaboratoryTestPackageDescriptor::STATUS_PENDING)
                            ->update([
                                'validation_status' => LaboratoryTestPackageDescriptor::STATUS_VALIDATED,
                                'validation_reference' => $item['reference'],
                                'updated_at' => now(),
                            ]);
                    } else {
                        $updated = DB::table('laboratory_test_package_descriptors')
                            ->where('package_id', $package->id)
                            ->where('id', $item['id'])
                            ->where('validation_status', LaboratoryTestPackageDescriptor::STATUS_PENDING)
                            ->update([
                                'validation_status' => LaboratoryTestPackageDescriptor::STATUS_VALIDATED,
                                'validation_reference' => $item['reference'],
                                'updated_at' => now(),
                            ]);
                    }

                    if ($updated !== 1) {
                        throw new RuntimeException('B02_ROW_UPDATE_MISMATCH');
                    }
                }

                $after = $this->snapshot($package->id);
                if ($after !== $plan['after']) {
                    throw new RuntimeException('B02_POSTCONDITION_FAILED');
                }

                LaboratoryPackageCommercialApproval::query()->create([
                    'public_id' => Str::uuid()->toString(),
                    'package_id' => $package->id,
                    'package_test_id' => $request['package_test_id'],
                    'approver' => $request['approver'],
                    'approval_reference' => $request['reference'],
                    'approved_at' => $request['approved_at'],
                    'operator' => $operator,
                    'correlation_id' => $correlationId,
                    'input_sha256' => $inputSha,
                    'before_state' => $lockedBefore,
                    'after_state' => $after,
                ]);

                return $after;
            });

            $this->emit('applied', $correlationId, $request, $before, $after, true);

            return self::SUCCESS;
        } catch (Throwable $e) {
            $code = $e instanceof RuntimeException && str_starts_with($e->getMessage(), 'B02_')
                ? $e->getMessage()
                : 'B02_UNEXPECTED_ERROR';
            Log::warning('akubica_b02_commercial_approval_rejected', [
                'correlation_id' => $correlationId,
                'error_code' => $code,
            ]);
            $this->line(json_encode([
                'result' => 'rejected',
                'error_code' => $code,
                'correlation_id' => $correlationId,
                'writes' => 0,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

            return self::FAILURE;
        }
    }

    /** @return array{0: array<string, mixed>, 1: string} */
    private function readInput(string $path): array
    {
        if ($path === '' || ! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('B02_INPUT_FILE_REQUIRED');
        }
        $raw = (string) file_get_contents($path);
        $data = json_decode($raw, true);
        if (! is_array($data)) {
            throw new RuntimeException('B02_INPUT_INVALID_JSON');
        }

        return [$data, hash('sha256', $raw)];
    }

    /** @return array{package_test_id:int, approver:string, reference:string, approved_at:CarbonImmutable, items:list<array{type:string,id:int,reference:string}>} */
    private function validateInput(array $input): array
    {
        $packageTestId = filter_var($input['package_test_id'] ?? null, FILTER_VALIDATE_INT);
        $approver = trim((string) ($input['approver'] ?? ''));
        $reference = (string) ($input['reference'] ?? '');
        $approvedAtRaw = (string) ($input['approved_at'] ?? '');
        $items = $input['items'] ?? null;

        if ($packageTestId === false || $packageTestId <= 0) {
            throw new RuntimeException('B02_INPUT_PACKAGE_REQUIRED');
        }
        if (mb_strlen($approver) < 3) {
            throw new RuntimeException('B02_APPROVER_REQUIRED');
        }
        if (! preg_match(self::REFERENCE_PATTERN, $reference)) {
            throw new RuntimeException('B02_REFERENCE_INVALID');
        }
        try {
            $approvedAt = CarbonImmutable::parse($approvedAtRaw);
        } catch (Throwable) {
            throw new RuntimeException('B02_APPROVED_AT_INVALID');
        }
        if ($approvedAtRaw === '' || $approvedAt->isFuture()) {
            throw new RuntimeException('B02_APPROVED_AT_INVALID');
        }
        if (! is_array($items) || $items === []) {
            throw new RuntimeException('B02_ITEMS_REQUIRED');
        }

        $normalized = [];
        foreach ($items as $item) {
            $type = $item['type'] ?? null;
            $id = filter_var($type === 'study' ? ($item['component_test_id'] ?? null) : ($item['descriptor_id'] ?? null), FILTER_VALIDATE_INT);
            $itemReference = (string) ($item['reference'] ?? '');
            if (! in_array($type, ['study', 'descriptor'], true) || $id === false) {
                throw new RuntimeException('B02_ITEM_INVALID');
            }
            if (! preg_match(self::REFERENCE_PATTERN, $itemReference)) {
                throw new RuntimeException('B02_REFERENCE_INVALID');
            }
            $key = $type.':'.$id;
            if (isset($normalized[$key])) {
                throw new RuntimeException('B02_ITEM_DUPLICATED');
            }
            $normalized[$key] = ['type' => $type, 'id' => $id, 'reference' => $itemReference];
        }
        ksort($normalized);

        return [
            'package_test_id' => $packageTestId,
            'approver' => $approver,
            'reference' => $reference,
            'approved_at' => $approvedAt,
            'items' => array_values($normalized),
        ];
    }

    /** @return array<string, array{status:string, reference:?string}> clave "study:ID" / "descriptor:ID" */
    private function snapshot(int $packageId, bool $lock = false): array
    {
        $components = DB::table('laboratory_test_package_components')
            ->where('package_id', $packageId)
            ->orderBy('component_test_id');
        $descriptors = DB::table('laboratory_test_package_descriptors')
            ->where('package_id', $packageId)
            ->orderBy('id');
        if ($lock) {
            $components->lockForUpdate();
            $descriptors->lockForUpdate();
        }

        $state = [];
        foreach ($components->get(['component_test_id', 'validation_status', 'validation_reference']) as $row) {
            $state['study:'.$row->component_test_id] = ['status' => $row->validation_status, 'reference' => $row->validation_reference];
        }
        foreach ($descriptors->get(['id', 'validation_status', 'validation_reference']) as $row) {
            $state['descriptor:'.$row->id] = ['status' => $row->validation_status, 'reference' => $row->validation_reference];
        }
        ksort($state);

        return $state;
    }

    /** @return array{noop: bool, after: array<string, array{status:string, reference:?string}>} */
    private function plan(array $before, array $items): array
    {
        if ($before === []) {
            throw new RuntimeException('B02_PACKAGE_WITHOUT_COMPOSITION');
        }
        $requested = [];
        foreach ($items as $item) {
            $requested[$item['type'].':'.$item['id']] = $item['reference'];
        }
        ksort($requested);
        if (array_keys($requested) !== array_keys($before)) {
            throw new RuntimeException('B02_ITEMS_MUST_COVER_FULL_COMPOSITION');
        }

        $after = [];
        $alreadyApproved = true;
        foreach ($before as $key => $row) {
            $after[$key] = ['status' => LaboratoryTestPackageDescriptor::STATUS_VALIDATED, 'reference' => $requested[$key]];
            if ($row !== $after[$key]) {
                $alreadyApproved = false;
            }
        }
        if ($alreadyApproved) {
            return ['noop' => true, 'after' => $before];
        }
        foreach ($before as $row) {
            if ($row['status'] !== LaboratoryTestPackageDescriptor::STATUS_PENDING) {
                throw new RuntimeException('B02_ROW_NOT_PENDING');
            }
        }

        return ['noop' => false, 'after' => $after];
    }

    private function emit(string $result, string $correlationId, array $request, array $before, array $after, bool $applied): void
    {
        $payload = [
            'result' => $result,
            'correlation_id' => $correlationId,
            'package_test_id' => $request['package_test_id'],
            'approval_reference' => $request['reference'],
            'approved_at' => $request['approved_at']->toIso8601String(),
            'writes' => $applied && $result === 'applied' ? count($after) : 0,
            'before' => $before,
            'after' => $after,
        ];
        if ($result === 'applied') {
            Log::info('akubica_b02_commercial_approval_applied', [
                'correlation_id' => $correlationId,
                'package_test_id' => $request['package_test_id'],
                'approval_reference' => $request['reference'],
                'rows' => count($after),
            ]);
        }
        $this->line(json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }
}
