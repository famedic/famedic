<?php

namespace App\Services\Odessa\PreEnrollment;

use App\Models\OdessaAfiliateAccount;
use App\Models\OdessaPreEnrollment;
use App\Models\User;
use App\Services\Odessa\Reconciliation\OdessaCollaboratorMatcher;
use App\Services\Odessa\Reconciliation\OdessaReconciliationDbIndex;
use App\Services\Odessa\Reconciliation\OdessaReconciliationMatchTypes;
use App\Services\Odessa\Reconciliation\OdessaReconciliationNormalizer;
use Illuminate\Http\UploadedFile;

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

    public function preview(UploadedFile|string $file): array
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

        $rows = array_map(
            fn (OdessaPreEnrollmentSourceRow $row) => $this->diagnose($row, $preIndex, $famedicMatcher, $uploadDuplicates),
            $sourceRows,
        );

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
        ];
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
}
