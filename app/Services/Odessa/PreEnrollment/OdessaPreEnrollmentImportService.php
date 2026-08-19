<?php

namespace App\Services\Odessa\PreEnrollment;

use App\Models\OdessaPreEnrollment;
use App\Models\OdessaPreEnrollmentAudit;
use App\Models\OdessaPreEnrollmentImportRun;
use App\Models\OdessaPreEnrollmentImportRunAudit;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Throwable;

class OdessaPreEnrollmentImportService
{
    private const CODE_INVALID_RUN = 'invalid_import_run';
    private const CODE_RUN_NOT_PREVIEWED = 'run_not_previewed';
    private const CODE_RUN_EXPIRED = 'run_expired';
    private const CODE_FILE_HASH_MISMATCH = 'file_hash_mismatch';
    private const CODE_COUNTS_MISMATCH = 'counts_mismatch';
    private const CODE_ROW_HASH_MISMATCH = 'row_hash_mismatch';
    private const CODE_READY_ROWS_MISMATCH = 'ready_rows_mismatch';
    private const CODE_IMPORT_PARTIAL_CONFLICT = 'IMPORT_PARTIAL_CONFLICT';
    private const CODE_IMPORT_FAILED = 'import_failed';

    private const AUDIT_PREVIEWED = 'IMPORT_PREVIEWED';
    private const AUDIT_STARTED = 'IMPORT_STARTED';
    private const AUDIT_COMPLETED = 'IMPORT_COMPLETED';
    private const AUDIT_FAILED = 'IMPORT_FAILED';
    private const AUDIT_IDEMPOTENT_REPLAY = 'IMPORT_IDEMPOTENT_REPLAY';
    private const AUDIT_CROSS_RUN_REPLAY = 'IMPORT_CROSS_RUN_REPLAY';

    private const ALLOWED_CODES = [
        self::CODE_INVALID_RUN,
        self::CODE_RUN_NOT_PREVIEWED,
        self::CODE_RUN_EXPIRED,
        self::CODE_FILE_HASH_MISMATCH,
        self::CODE_COUNTS_MISMATCH,
        self::CODE_ROW_HASH_MISMATCH,
        self::CODE_READY_ROWS_MISMATCH,
        self::CODE_IMPORT_PARTIAL_CONFLICT,
        self::CODE_IMPORT_FAILED,
        'started',
        'completed',
        'completed_replay',
        'completed_cross_run_replay',
    ];

    public function __construct(
        private readonly OdessaPreEnrollmentPreviewService $previewService,
    ) {}

    public function confirm(string $runUuid, UploadedFile|string $file, User $actor): array
    {
        $preliminary = $this->preflightRun($runUuid, $actor);
        if (($preliminary['ok'] ?? false) === true) {
            return $preliminary;
        }
        if (($preliminary['continue'] ?? false) !== true) {
            return $this->failureResult((string) $preliminary['code']);
        }

        try {
            $run = $preliminary['run'];
            $rowHmacKey = $this->decryptRowHmacKey($run);
            $analysis = $this->previewService->analyze($file);
            $rowHashes = $this->previewService->rowHashes($analysis, $rowHmacKey);

            return DB::transaction(function () use ($runUuid, $actor, $analysis, $rowHashes) {
                $run = OdessaPreEnrollmentImportRun::query()
                    ->where('uuid', $runUuid)
                    ->lockForUpdate()
                    ->first();

                $this->assertRunCanBeConfirmed($run, $actor);
                $this->assertFileHashMatches($run, $analysis);
                $this->assertManifestMatchesRun($run, $rowHashes);

                if ($this->allManifestRowsAlreadyImported($run)) {
                    return $this->completeRun($run, $actor, created: 0, replay: true, event: self::AUDIT_CROSS_RUN_REPLAY, code: 'completed_cross_run_replay');
                }

                $this->assertCountsMatch($run, $analysis);

                $readyRows = collect($analysis['rows'])
                    ->filter(fn (array $row) => $row['diagnostic_status'] === OdessaPreEnrollmentPreviewService::READY_TO_PRELOAD)
                    ->values();
                $existingRows = $this->existingImportedRows($run, $readyRows);
                if ($existingRows > 0) {
                    throw new OdessaPreEnrollmentImportException(self::CODE_IMPORT_PARTIAL_CONFLICT);
                }
                if ($readyRows->count() !== (int) $run->ready_rows || $readyRows->isEmpty()) {
                    throw new OdessaPreEnrollmentImportException(self::CODE_READY_ROWS_MISMATCH);
                }

                $run->forceFill(['status' => OdessaPreEnrollmentImportRun::STATUS_IMPORTING])->save();
                $this->audit($run, $actor, self::AUDIT_STARTED, 'started');

                foreach ($readyRows as $row) {
                    $this->createPreEnrollment($run, $row, $actor);
                }

                OdessaPreEnrollment::query()
                    ->where('import_run_id', $run->id)
                    ->get()
                    ->each(function (OdessaPreEnrollment $preEnrollment) use ($actor): void {
                        OdessaPreEnrollmentAudit::create([
                            'odessa_pre_enrollment_id' => $preEnrollment->id,
                            'performed_by' => $actor->id,
                            'action_type' => 'IMPORT_PRELOADED',
                            'before_json' => null,
                            'after_json' => [
                                'status' => $preEnrollment->status,
                                'link_status' => $preEnrollment->link_status,
                                'murguia_status' => $preEnrollment->murguia_status,
                                'has_medical_attention_identifier' => false,
                            ],
                            'reason' => 'Importación controlada ODESSA 6C-A.',
                            'performed_at' => now(),
                        ]);
                    });

                return $this->completeRun($run, $actor, created: $readyRows->count(), replay: false, event: self::AUDIT_COMPLETED, code: 'completed');
            });
        } catch (OdessaPreEnrollmentImportException $exception) {
            $this->markFailed($runUuid, $actor, $exception->codeName);

            return $this->failureResult($exception->codeName);
        } catch (QueryException $exception) {
            if ($this->isUniqueConstraintViolation($exception)) {
                return $this->resolveUniqueRace($runUuid, $actor);
            }

            $this->markFailed($runUuid, $actor, self::CODE_IMPORT_FAILED);

            return $this->failureResult(self::CODE_IMPORT_FAILED);
        } catch (Throwable $exception) {
            $this->markFailed($runUuid, $actor, self::CODE_IMPORT_FAILED);

            return $this->failureResult(self::CODE_IMPORT_FAILED);
        }
    }

    private function preflightRun(string $runUuid, User $actor): array
    {
        $run = OdessaPreEnrollmentImportRun::query()
            ->where('uuid', $runUuid)
            ->first();

        if (! $run || (int) $run->previewed_by !== (int) $actor->id) {
            return ['code' => self::CODE_INVALID_RUN];
        }

        if ($run->status === OdessaPreEnrollmentImportRun::STATUS_COMPLETED) {
            return $this->successResult($run, created: 0, replay: true);
        }

        if ($run->status !== OdessaPreEnrollmentImportRun::STATUS_PREVIEWED) {
            return ['code' => self::CODE_RUN_NOT_PREVIEWED];
        }

        if ($run->expires_at && $run->expires_at->isPast()) {
            $this->expireRun($run, $actor);

            return ['code' => self::CODE_RUN_EXPIRED];
        }

        return ['continue' => true, 'run' => $run];
    }

    private function assertRunCanBeConfirmed(?OdessaPreEnrollmentImportRun $run, User $actor): void
    {
        if (! $run || (int) $run->previewed_by !== (int) $actor->id) {
            throw new OdessaPreEnrollmentImportException(self::CODE_INVALID_RUN);
        }
        if ($run->status !== OdessaPreEnrollmentImportRun::STATUS_PREVIEWED) {
            throw new OdessaPreEnrollmentImportException(self::CODE_RUN_NOT_PREVIEWED);
        }
        if ($run->expires_at && $run->expires_at->isPast()) {
            throw new OdessaPreEnrollmentImportException(self::CODE_RUN_EXPIRED);
        }
    }

    private function assertFileHashMatches(OdessaPreEnrollmentImportRun $run, array $analysis): void
    {
        if (! hash_equals((string) $run->source_file_hash, (string) $analysis['source_file_hash'])) {
            throw new OdessaPreEnrollmentImportException(self::CODE_FILE_HASH_MISMATCH);
        }
    }

    private function assertCountsMatch(OdessaPreEnrollmentImportRun $run, array $analysis): void
    {
        foreach ($this->previewService->counts($analysis) as $key => $value) {
            if ((int) $run->{$key} !== (int) $value) {
                throw new OdessaPreEnrollmentImportException(self::CODE_COUNTS_MISMATCH);
            }
        }
    }

    private function assertManifestMatchesRun(OdessaPreEnrollmentImportRun $run, array $rowHashes): void
    {
        $expected = $run->rows()
            ->orderBy('source_row')
            ->pluck('source_row_hash', 'source_row')
            ->map(fn ($hash) => (string) $hash)
            ->all();
        $actual = collect($rowHashes)
            ->sortKeys()
            ->map(fn ($hash) => (string) $hash)
            ->all();

        if ($expected !== $actual) {
            throw new OdessaPreEnrollmentImportException(self::CODE_ROW_HASH_MISMATCH);
        }
    }

    private function createPreEnrollment(OdessaPreEnrollmentImportRun $run, array $row, User $actor): void
    {
        /** @var OdessaPreEnrollmentSourceRow $source */
        $source = $row['_source'];

        OdessaPreEnrollment::create([
            'import_run_id' => $run->id,
            'source_run_id' => $run->id,
            'source_file_hash' => $run->source_file_hash,
            'source_sheet' => $source->sourceSheet,
            'source_row' => $source->sourceRow,
            'source_action' => $source->sourceAction,
            'company_external_identifier' => $source->companyExternalIdentifier,
            'employee_identifier' => $source->employeeIdentifier,
            'odessa_identifier' => $source->odessaIdentifier,
            'first_name' => $source->firstName,
            'paternal_last_name' => $source->paternalLastName,
            'maternal_last_name' => $source->maternalLastName,
            'birth_date' => $source->birthDate?->toDateString(),
            'source_email' => $source->sourceEmail,
            'medical_attention_identifier' => null,
            'membership_type' => 'institutional',
            'murguia_status' => OdessaPreEnrollment::MURGUIA_NOT_REQUESTED,
            'link_status' => OdessaPreEnrollment::LINK_PENDING_ACCOUNT,
            'status' => OdessaPreEnrollment::STATUS_READY,
            'data_quality_flags' => null,
            'source_snapshot_json' => null,
            'metadata_json' => null,
            'created_by' => $actor->id,
            'imported_at' => now(),
            'imported_by' => $actor->id,
        ]);
    }

    private function existingImportedRows(OdessaPreEnrollmentImportRun $run, $readyRows): int
    {
        return OdessaPreEnrollment::query()
            ->where('source_file_hash', $run->source_file_hash)
            ->where('source_sheet', $run->source_sheet)
            ->whereIn('source_row', $readyRows->pluck('source_row')->map(fn ($row) => (int) $row)->all())
            ->count();
    }

    private function allManifestRowsAlreadyImported(OdessaPreEnrollmentImportRun $run): bool
    {
        $sourceRows = $run->rows()->pluck('source_row')->map(fn ($row) => (int) $row)->all();
        if ($sourceRows === []) {
            return false;
        }

        return OdessaPreEnrollment::query()
            ->where('source_file_hash', $run->source_file_hash)
            ->where('source_sheet', $run->source_sheet)
            ->whereIn('source_row', $sourceRows)
            ->count() === count($sourceRows);
    }

    private function completeRun(
        OdessaPreEnrollmentImportRun $run,
        User $actor,
        int $created,
        bool $replay,
        string $event,
        string $code,
    ): array {
        $run->rows()->delete();
        $run->forceFill([
            'status' => OdessaPreEnrollmentImportRun::STATUS_COMPLETED,
            'imported_by' => $actor->id,
            'imported_at' => now(),
            'failure_code' => null,
            'row_hmac_key_encrypted' => null,
        ])->save();
        $this->audit($run, $actor, $event, $code);

        return $this->successResult($run->fresh(), created: $created, replay: $replay);
    }

    private function markFailed(string $runUuid, User $actor, string $code): void
    {
        DB::transaction(function () use ($runUuid, $actor, $code) {
            $run = OdessaPreEnrollmentImportRun::query()
                ->where('uuid', $runUuid)
                ->lockForUpdate()
                ->first();

            if (! $run || (int) $run->previewed_by !== (int) $actor->id || $run->status === OdessaPreEnrollmentImportRun::STATUS_COMPLETED) {
                return;
            }

            $status = $code === self::CODE_RUN_EXPIRED
                ? OdessaPreEnrollmentImportRun::STATUS_EXPIRED
                : OdessaPreEnrollmentImportRun::STATUS_FAILED;

            $run->rows()->delete();
            $run->forceFill([
                'status' => $status,
                'failure_code' => $this->sanitizeCode($code),
                'row_hmac_key_encrypted' => null,
            ])->save();

            $this->audit($run, $actor, self::AUDIT_FAILED, $code);
        });
    }

    private function expireRun(OdessaPreEnrollmentImportRun $run, User $actor): void
    {
        DB::transaction(function () use ($run, $actor) {
            $locked = OdessaPreEnrollmentImportRun::query()
                ->whereKey($run->id)
                ->lockForUpdate()
                ->first();

            if (! $locked || $locked->status !== OdessaPreEnrollmentImportRun::STATUS_PREVIEWED) {
                return;
            }

            $locked->rows()->delete();
            $locked->forceFill([
                'status' => OdessaPreEnrollmentImportRun::STATUS_EXPIRED,
                'failure_code' => self::CODE_RUN_EXPIRED,
                'source_file_hash' => null,
                'row_hmac_key_encrypted' => null,
            ])->save();

            $this->audit($locked, $actor, self::AUDIT_FAILED, self::CODE_RUN_EXPIRED);
        });
    }

    private function resolveUniqueRace(string $runUuid, User $actor): array
    {
        return DB::transaction(function () use ($runUuid, $actor) {
            $run = OdessaPreEnrollmentImportRun::query()
                ->where('uuid', $runUuid)
                ->lockForUpdate()
                ->first();

            if (! $run || (int) $run->previewed_by !== (int) $actor->id) {
                return $this->failureResult(self::CODE_INVALID_RUN);
            }

            $readyRows = $run->rows()->where('ready_to_preload', true)->get();
            $existingRows = OdessaPreEnrollment::query()
                ->where('source_file_hash', $run->source_file_hash)
                ->where('source_sheet', $run->source_sheet)
                ->whereIn('source_row', $readyRows->pluck('source_row')->all())
                ->count();

            if ($readyRows->isNotEmpty() && $existingRows === $readyRows->count()) {
                return $this->completeRun($run, $actor, created: 0, replay: true, event: self::AUDIT_CROSS_RUN_REPLAY, code: 'completed_cross_run_replay');
            }

            $this->markFailed($runUuid, $actor, self::CODE_IMPORT_PARTIAL_CONFLICT);

            return $this->failureResult(self::CODE_IMPORT_PARTIAL_CONFLICT);
        });
    }

    private function audit(OdessaPreEnrollmentImportRun $run, User $actor, string $event, string $code): void
    {
        OdessaPreEnrollmentImportRunAudit::create([
            'import_run_id' => $run->id,
            'performed_by' => $actor->id,
            'event' => $this->sanitizeEvent($event),
            'counts_json' => [
                'total_rows' => (int) $run->total_rows,
                'ready_rows' => (int) $run->ready_rows,
                'excluded_rows' => (int) $run->excluded_rows,
                'existing_user_rows' => (int) $run->existing_user_rows,
                'other_email_rows' => (int) $run->other_email_rows,
                'possible_duplicate_rows' => (int) $run->possible_duplicate_rows,
                'blocked_rows' => (int) $run->blocked_rows,
            ],
            'result_code' => $this->sanitizeCode($code),
            'performed_at' => now(),
        ]);
    }

    private function successResult(OdessaPreEnrollmentImportRun $run, int $created, bool $replay): array
    {
        return [
            'ok' => true,
            'status' => $run->status,
            'created' => $created,
            'omitted' => (int) $run->excluded_rows + max(0, (int) $run->ready_rows - $created),
            'replay' => $replay,
            'message' => $replay
                ? 'La importación ya había sido completada.'
                : 'Importación completada correctamente.',
        ];
    }

    private function failureResult(string $code): array
    {
        return [
            'ok' => false,
            'status' => 'FAILED',
            'code' => $this->sanitizeCode($code),
            'message' => 'No se pudo confirmar la importación. Repite el análisis con el archivo vigente.',
        ];
    }

    private function decryptRowHmacKey(OdessaPreEnrollmentImportRun $run): string
    {
        $encrypted = (string) $run->row_hmac_key_encrypted;
        if ($encrypted === '') {
            throw new OdessaPreEnrollmentImportException(self::CODE_INVALID_RUN);
        }

        return base64_decode(Crypt::decryptString($encrypted), true) ?: throw new OdessaPreEnrollmentImportException(self::CODE_INVALID_RUN);
    }

    private function sanitizeCode(string $code): string
    {
        return in_array($code, self::ALLOWED_CODES, true) ? $code : self::CODE_IMPORT_FAILED;
    }

    private function sanitizeEvent(string $event): string
    {
        return in_array($event, [
            self::AUDIT_PREVIEWED,
            self::AUDIT_STARTED,
            self::AUDIT_COMPLETED,
            self::AUDIT_FAILED,
            self::AUDIT_IDEMPOTENT_REPLAY,
            self::AUDIT_CROSS_RUN_REPLAY,
        ], true) ? $event : self::AUDIT_FAILED;
    }

    private function isUniqueConstraintViolation(QueryException $exception): bool
    {
        return in_array((string) ($exception->errorInfo[0] ?? ''), ['23000', '23505'], true);
    }
}
