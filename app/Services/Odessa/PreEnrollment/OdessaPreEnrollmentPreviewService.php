<?php

namespace App\Services\Odessa\PreEnrollment;

use App\Models\OdessaAfiliateAccount;
use App\Models\OdessaPreEnrollment;
use App\Models\OdessaPreEnrollmentImportRun;
use App\Models\OdessaPreEnrollmentImportRunAudit;
use App\Models\OdessaPreEnrollmentImportRunRow;
use App\Models\User;
use App\Services\Odessa\Reconciliation\OdessaCollaboratorMatcher;
use App\Services\Odessa\Reconciliation\OdessaReconciliationDbIndex;
use App\Services\Odessa\Reconciliation\OdessaReconciliationMatchTypes;
use App\Services\Odessa\Reconciliation\OdessaReconciliationNormalizer;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Normalizer;

class OdessaPreEnrollmentPreviewService
{
    public const READY_TO_PRELOAD = 'READY_TO_PRELOAD';
    public const ALREADY_PRELOADED = 'ALREADY_PRELOADED';
    public const EXISTING_FAMEDIC_USER = 'EXISTING_FAMEDIC_USER';
    public const EXISTING_ODESSA_ACCOUNT = 'EXISTING_ODESSA_ACCOUNT';
    public const OTHER_EMAIL = 'OTHER_EMAIL';
    public const POSSIBLE_DUPLICATE = 'POSSIBLE_DUPLICATE';
    public const IDENTITY_CONFLICT = 'IDENTITY_CONFLICT';
    public const BLOCKED = 'BLOCKED';

    public function __construct(
        private readonly OdessaPreEnrollmentExcelParser $parser,
    ) {}

    public function preview(UploadedFile|string $file, ?User $actor = null): array
    {
        $analysis = $this->analyze($file);
        $run = $this->createImportRun($analysis, $actor);

        return $this->publicPreview($analysis, $run);
    }

    public function analyze(UploadedFile|string $file): array
    {
        $path = $file instanceof UploadedFile ? $file->getRealPath() : $file;
        if (! $path || ! is_file($path)) {
            throw new \InvalidArgumentException('No existe el archivo a analizar.');
        }

        $sourceRows = $this->parser->parse($path);
        if ($sourceRows === []) {
            throw new \InvalidArgumentException('El archivo no contiene filas reconocibles para preafiliación ODESSA.');
        }

        $preIndex = $this->preEnrollmentIndex();
        $famedicMatcher = new OdessaCollaboratorMatcher(OdessaReconciliationDbIndex::build());
        $uploadDuplicates = $this->uploadDuplicates($sourceRows);

        $rows = array_map(function (OdessaPreEnrollmentSourceRow $row) use ($preIndex, $famedicMatcher, $uploadDuplicates) {
            $diagnosis = $this->diagnose($row, $preIndex, $famedicMatcher, $uploadDuplicates);
            $diagnosis['_source'] = $row;

            return $diagnosis;
        }, $sourceRows);

        return [
            'meta' => [
                'file_received' => true,
                'sheet' => (string) config('famedic.odessa_pre_enrollments.import_expected_sheet', 'Sin Registro'),
                'row_count' => count($rows),
                'status' => 'analyzed',
                'generated_at' => now()->toDateTimeString(),
            ],
            'summary' => collect($rows)->countBy('diagnostic_status')->all(),
            'source_actions' => collect($rows)->countBy('source_action')->all(),
            'rows' => $rows,
            'source_file_hash' => hash_file('sha256', $path),
        ];
    }

    public function publicPreview(array $analysis, ?OdessaPreEnrollmentImportRun $run = null): array
    {
        $meta = $analysis['meta'];
        if ($run) {
            $meta['run_uuid'] = $run->uuid;
            $meta['expires_at'] = $run->expires_at?->toDateTimeString();
            $meta['ready_rows'] = $run->ready_rows;
            $meta['excluded_rows'] = $run->excluded_rows;
            $meta['importable'] = $run->ready_rows > 0;
        }

        return [
            'meta' => $meta,
            'summary' => $analysis['summary'],
            'source_actions' => $analysis['source_actions'],
            'rows' => array_map(fn (array $row) => $this->publicRow($row), $analysis['rows']),
        ];
    }

    public function counts(array $analysis): array
    {
        $summary = $analysis['summary'];
        $ready = (int) ($summary[self::READY_TO_PRELOAD] ?? 0);
        $existing = (int) ($summary[self::EXISTING_FAMEDIC_USER] ?? 0) + (int) ($summary[self::EXISTING_ODESSA_ACCOUNT] ?? 0);
        $other = (int) ($summary[self::OTHER_EMAIL] ?? 0);
        $possible = (int) ($summary[self::POSSIBLE_DUPLICATE] ?? 0) + (int) ($summary[self::IDENTITY_CONFLICT] ?? 0);
        $blocked = (int) ($summary[self::BLOCKED] ?? 0);
        $total = count($analysis['rows']);

        return [
            'total_rows' => $total,
            'ready_rows' => $ready,
            'excluded_rows' => max(0, $total - $ready),
            'existing_user_rows' => $existing,
            'other_email_rows' => $other,
            'possible_duplicate_rows' => $possible,
            'blocked_rows' => $blocked,
        ];
    }

    public function rowHashes(array $analysis, string $key): array
    {
        return collect($analysis['rows'])
            ->mapWithKeys(fn (array $row) => [(int) $row['source_row'] => $this->sourceRowHmac($row['_source'], $key)])
            ->all();
    }

    private function diagnose(
        OdessaPreEnrollmentSourceRow $row,
        array $preIndex,
        OdessaCollaboratorMatcher $famedicMatcher,
        array $uploadDuplicates,
    ): array {
        $flags = [];
        $notes = [];
        $existingPreEnrollmentId = null;
        $diagnostic = self::READY_TO_PRELOAD;
        $match = null;
        $identityUser = null;

        if (! $row->companyExternalIdentifier || ! $row->employeeIdentifier || ! $row->firstName || ! $row->paternalLastName || ! $row->birthDate) {
            $diagnostic = self::BLOCKED;
            $flags[] = 'REQUIRED_FIELDS_MISSING';
            $notes[] = 'Faltan campos mínimos para preafiliación segura.';
        }

        foreach ($this->duplicateSignals($row, $uploadDuplicates) as $flag) {
            $flags[] = $flag;
            $diagnostic = $diagnostic === self::BLOCKED ? $diagnostic : self::POSSIBLE_DUPLICATE;
        }

        $companyEmployeeKey = $this->companyEmployeeKey($row);
        if ($companyEmployeeKey && isset($preIndex['company_employee'][$companyEmployeeKey])) {
            $diagnostic = self::ALREADY_PRELOADED;
            $existingPreEnrollmentId = $preIndex['company_employee'][$companyEmployeeKey]['id'];
            $flags[] = 'DUPLICATE_COMPANY_PARTNER';
        }

        if ($row->odessaIdentifier && isset($preIndex['odessa'][$row->odessaIdentifier])) {
            $diagnostic = self::ALREADY_PRELOADED;
            $existingPreEnrollmentId = $preIndex['odessa'][$row->odessaIdentifier]['id'];
            $flags[] = 'DUPLICATE_ODESSA_ID';
        }

        if ($row->sourceEmail && isset($preIndex['email'][$row->sourceEmail])) {
            $flags[] = 'POSSIBLE_EXISTING_PRE_ENROLLMENT_EMAIL';
            $diagnostic = $diagnostic === self::READY_TO_PRELOAD ? self::POSSIBLE_DUPLICATE : $diagnostic;
        }

        if ($row->identityKey() && isset($preIndex['identity'][$row->identityKey()])) {
            $flags[] = 'POSSIBLE_DUPLICATE_PERSON';
            $diagnostic = $diagnostic === self::READY_TO_PRELOAD ? self::POSSIBLE_DUPLICATE : $diagnostic;
        }

        $match = $famedicMatcher->match($row->toCollaboratorSourceRow());
        if ($match->matched) {
            $matchedEmail = OdessaReconciliationNormalizer::email($match->matched->email ?? null);
            $matchedType = $match->matched->customerable_type ?? null;

            if ($matchedType === OdessaAfiliateAccount::class) {
                $diagnostic = self::EXISTING_ODESSA_ACCOUNT;
                $flags[] = 'EXISTING_ODESSA_ACCOUNT';
            } elseif ($match->matchType === OdessaReconciliationMatchTypes::CONFIRMED_EMAIL) {
                $diagnostic = self::EXISTING_FAMEDIC_USER;
                $flags[] = 'POSSIBLE_EXISTING_USER';
            } elseif ($matchedEmail && $row->sourceEmail && $matchedEmail !== $row->sourceEmail) {
                $diagnostic = self::OTHER_EMAIL;
                $flags[] = 'POSSIBLE_EXISTING_USER';
                $flags[] = 'EMAIL_DIFFERENT';
            } elseif ($match->matchType === OdessaReconciliationMatchTypes::PROBABLE_IDENTITY) {
                $diagnostic = self::IDENTITY_CONFLICT;
                $flags[] = 'POSSIBLE_DUPLICATE_PERSON';
            } elseif ($match->matchType === OdessaReconciliationMatchTypes::AMBIGUOUS) {
                $diagnostic = self::POSSIBLE_DUPLICATE;
                $flags[] = 'POSSIBLE_DUPLICATE_PERSON';
            }
        }

        if (! $match->matched && $row->birthDate && $row->looseIdentityKey()) {
            $identityUser = $this->findUserByIdentity($row);
            if ($identityUser) {
                $matchedEmail = OdessaReconciliationNormalizer::email($identityUser->email);
                if ($matchedEmail && $row->sourceEmail && $matchedEmail !== $row->sourceEmail) {
                    $diagnostic = self::OTHER_EMAIL;
                    $flags[] = 'POSSIBLE_EXISTING_USER';
                    $flags[] = 'EMAIL_DIFFERENT';
                    $notes[] = 'Nombre + apellidos + fecha de nacimiento coinciden con un User bajo otro correo.';
                } elseif ($diagnostic === self::READY_TO_PRELOAD) {
                    $diagnostic = self::EXISTING_FAMEDIC_USER;
                    $flags[] = 'POSSIBLE_EXISTING_USER';
                }
            }
        }

        return [
            'source_row' => $row->sourceRow,
            'source_action' => $row->sourceAction,
            'diagnostic_status' => $diagnostic,
            'ready_to_preload' => $diagnostic === self::READY_TO_PRELOAD,
            'already_preloaded' => $existingPreEnrollmentId !== null,
            'existing_account' => (bool) ($match?->matched || $identityUser),
            'existing_email_present' => (bool) ($match?->matched?->email ?? $identityUser?->email ?? null),
            'identity_conflict' => $diagnostic === self::IDENTITY_CONFLICT,
            'possible_duplicate' => in_array($diagnostic, [self::POSSIBLE_DUPLICATE, self::OTHER_EMAIL], true),
            'existing_odessa_account' => $diagnostic === self::EXISTING_ODESSA_ACCOUNT,
            'match_type' => $match?->matchType,
            'data_quality_flags' => array_values(array_unique($flags)),
            'notes' => $this->sanitizedNotes(array_merge($notes, $match?->reviewNotes ?? [])),
        ];
    }

    private function preEnrollmentIndex(): array
    {
        $index = [
            'company_employee' => [],
            'odessa' => [],
            'email' => [],
            'identity' => [],
        ];

        OdessaPreEnrollment::query()
            ->where('status', '!=', OdessaPreEnrollment::STATUS_ARCHIVED)
            ->get()
            ->each(function (OdessaPreEnrollment $preEnrollment) use (&$index) {
                if ($preEnrollment->active_company_employee_key) {
                    $index['company_employee'][$preEnrollment->active_company_employee_key] = ['id' => $preEnrollment->id];
                }
                if ($preEnrollment->active_odessa_identifier) {
                    $index['odessa'][$preEnrollment->active_odessa_identifier] = ['id' => $preEnrollment->id];
                }
                if ($preEnrollment->source_email) {
                    $index['email'][OdessaReconciliationNormalizer::email($preEnrollment->source_email)] = ['id' => $preEnrollment->id];
                }
                $identity = OdessaReconciliationNormalizer::identityKey(
                    $preEnrollment->first_name,
                    $preEnrollment->paternal_last_name,
                    $preEnrollment->maternal_last_name,
                    $preEnrollment->birth_date?->toDateString(),
                );
                if ($identity !== '|||') {
                    $index['identity'][$identity] = ['id' => $preEnrollment->id];
                }
            });

        return $index;
    }

    /** @param list<OdessaPreEnrollmentSourceRow> $rows */
    private function uploadDuplicates(array $rows): array
    {
        $groups = [
            'company_employee' => [],
            'odessa' => [],
            'email' => [],
            'identity' => [],
        ];

        foreach ($rows as $row) {
            if ($key = $this->companyEmployeeKey($row)) {
                $groups['company_employee'][$key][] = $row->sourceRow;
            }
            if ($row->odessaIdentifier) {
                $groups['odessa'][$row->odessaIdentifier][] = $row->sourceRow;
            }
            if ($row->sourceEmail) {
                $groups['email'][$row->sourceEmail][] = $row->sourceRow;
            }
            if ($row->identityKey()) {
                $groups['identity'][$row->identityKey()][] = $row->sourceRow;
            }
        }

        return $groups;
    }

    private function duplicateSignals(OdessaPreEnrollmentSourceRow $row, array $duplicates): array
    {
        $flags = [];
        if (($key = $this->companyEmployeeKey($row)) && count($duplicates['company_employee'][$key] ?? []) > 1) {
            $flags[] = 'DUPLICATE_COMPANY_PARTNER';
        }
        if ($row->odessaIdentifier && count($duplicates['odessa'][$row->odessaIdentifier] ?? []) > 1) {
            $flags[] = 'DUPLICATE_ODESSA_ID';
        }
        if ($row->sourceEmail && count($duplicates['email'][$row->sourceEmail] ?? []) > 1) {
            $flags[] = 'POSSIBLE_EXISTING_USER';
        }
        if ($row->identityKey() && count($duplicates['identity'][$row->identityKey()] ?? []) > 1) {
            $flags[] = 'POSSIBLE_DUPLICATE_PERSON';
        }

        return $flags;
    }

    private function companyEmployeeKey(OdessaPreEnrollmentSourceRow $row): ?string
    {
        if (! $row->companyExternalIdentifier || ! $row->employeeIdentifier) {
            return null;
        }

        return "{$row->companyExternalIdentifier}|{$row->employeeIdentifier}";
    }

    private function findUserByIdentity(OdessaPreEnrollmentSourceRow $row): ?User
    {
        $birthDate = $row->birthDate?->toDateString();
        if (! $birthDate) {
            return null;
        }

        return User::query()
            ->with('customer')
            ->whereDate('birth_date', $birthDate)
            ->get()
            ->first(function (User $user) use ($row) {
                return OdessaReconciliationNormalizer::comparableName($user->name, $user->paternal_lastname, $user->maternal_lastname)
                    === OdessaReconciliationNormalizer::comparableName($row->firstName, $row->paternalLastName, $row->maternalLastName);
            });
    }

    private function sanitizedNotes(array $notes): array
    {
        $allowed = [
            'Faltan campos mínimos para preafiliación segura.',
            'Nombre + apellidos + fecha de nacimiento coinciden con un User bajo otro correo.',
        ];

        return array_values(array_unique(array_filter(
            $notes,
            fn (string $note) => in_array($note, $allowed, true)
        )));
    }

    private function createImportRun(array $analysis, ?User $actor): OdessaPreEnrollmentImportRun
    {
        return DB::transaction(function () use ($analysis, $actor) {
            $counts = $this->counts($analysis);
            $rowHmacKey = random_bytes(32);
            $run = OdessaPreEnrollmentImportRun::create(array_merge($counts, [
                'source_file_hash' => $analysis['source_file_hash'],
                'source_sheet' => $analysis['meta']['sheet'],
                'status' => OdessaPreEnrollmentImportRun::STATUS_PREVIEWED,
                'previewed_by' => $actor?->id,
                'previewed_at' => now(),
                'expires_at' => now()->addMinutes(30),
                'row_hmac_key_encrypted' => Crypt::encryptString(base64_encode($rowHmacKey)),
            ]));

            $rowHashes = $this->rowHashes($analysis, $rowHmacKey);
            foreach ($analysis['rows'] as $row) {
                OdessaPreEnrollmentImportRunRow::create([
                    'import_run_id' => $run->id,
                    'source_row' => (int) $row['source_row'],
                    'diagnostic_status' => (string) $row['diagnostic_status'],
                    'ready_to_preload' => (bool) $row['ready_to_preload'],
                    'source_row_hash' => (string) $rowHashes[(int) $row['source_row']],
                ]);
            }

            OdessaPreEnrollmentImportRunAudit::create([
                'import_run_id' => $run->id,
                'performed_by' => $actor?->id,
                'event' => 'IMPORT_PREVIEWED',
                'counts_json' => $counts,
                'result_code' => 'previewed',
                'performed_at' => now(),
            ]);

            return $run;
        });
    }

    private function publicRow(array $row): array
    {
        unset($row['_source'], $row['_source_row_hash']);

        return $row;
    }

    public function sourceRowHmac(OdessaPreEnrollmentSourceRow $row, string $key): string
    {
        return hash_hmac('sha256', $this->canonicalRow($row), $key);
    }

    private function canonicalRow(OdessaPreEnrollmentSourceRow $row): string
    {
        return json_encode([
            'source_sheet' => $this->canonicalText($row->sourceSheet),
            'source_row' => (string) $row->sourceRow,
            'company_external_identifier' => $this->canonicalText($row->companyExternalIdentifier),
            'employee_identifier' => $this->canonicalText($row->employeeIdentifier),
            'first_name' => $this->canonicalText($row->firstName),
            'paternal_last_name' => $this->canonicalText($row->paternalLastName),
            'maternal_last_name' => $this->canonicalText($row->maternalLastName),
            'birth_date' => $row->birthDate?->toDateString() ?? '__NULL__',
            'source_email' => $this->canonicalText($row->sourceEmail),
            'odessa_identifier' => $this->canonicalText($row->odessaIdentifier),
            'source_action' => $this->canonicalText($row->sourceAction),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function canonicalText(?string $value): string
    {
        if ($value === null) {
            return '__NULL__';
        }

        $value = trim($value);
        if (class_exists(Normalizer::class)) {
            $value = Normalizer::normalize($value, Normalizer::FORM_C) ?: $value;
        }

        return mb_strtolower($value);
    }
}
