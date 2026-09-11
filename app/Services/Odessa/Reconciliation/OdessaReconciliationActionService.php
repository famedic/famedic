<?php

namespace App\Services\Odessa\Reconciliation;

use App\Actions\Admin\MurguiaMonitorSingleCustomerAction;
use App\Models\Customer;
use App\Models\OdessaAfiliateAccount;
use App\Models\OdessaReconciliationItem;
use App\Models\OdessaReconciliationItemAction;
use App\Models\OdessaReconciliationRun;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

class OdessaReconciliationActionService
{
    public const ACTION_UPDATE_EMAIL = 'update-email';

    public const ACTION_LINK_ODESSA_ACCOUNT = 'link-odessa-account';

    public const ACTION_CREATE_MEMBERSHIP = 'create-membership';

    public const ACTION_RETRY_MURGUIA_SYNC = 'retry-murguia-sync';

    public const ACTION_ACTIVATE_MURGUIA_MEMBERSHIP = 'activate-murguia-membership';

    public const ACTION_DEACTIVATE_MURGUIA_MEMBERSHIP = 'deactivate-murguia-membership';

    public function __construct(
        private readonly MurguiaMonitorSingleCustomerAction $murguiaMonitorSingleCustomerAction,
    ) {}

    public static function permissions(): array
    {
        return [
            self::ACTION_UPDATE_EMAIL => 'odessa-reconciliation.actions.email',
            self::ACTION_LINK_ODESSA_ACCOUNT => 'odessa-reconciliation.actions.odessa',
            self::ACTION_CREATE_MEMBERSHIP => 'odessa-reconciliation.actions.membership',
            self::ACTION_RETRY_MURGUIA_SYNC => 'odessa-reconciliation.actions.murguia',
            self::ACTION_ACTIVATE_MURGUIA_MEMBERSHIP => 'odessa-reconciliation.actions.murguia.activate',
            self::ACTION_DEACTIVATE_MURGUIA_MEMBERSHIP => 'odessa-reconciliation.actions.murguia.deactivate',
        ];
    }

    public function preview(OdessaReconciliationRun $run, OdessaReconciliationItem $item, string $action): array
    {
        $this->assertItemBelongsToRun($run, $item);
        $action = $this->normalizeAction($action);

        $preview = match ($action) {
            self::ACTION_UPDATE_EMAIL => $this->previewUpdateEmail($item),
            self::ACTION_LINK_ODESSA_ACCOUNT => $this->previewLinkOdessaAccount($item),
            self::ACTION_CREATE_MEMBERSHIP => $this->previewCreateMembership($item),
            self::ACTION_RETRY_MURGUIA_SYNC => $this->previewRetryMurguiaSync($item),
            self::ACTION_ACTIVATE_MURGUIA_MEMBERSHIP => $this->previewMurguiaMembershipAction($item, 'activate'),
            self::ACTION_DEACTIVATE_MURGUIA_MEMBERSHIP => $this->previewMurguiaMembershipAction($item, 'deactivate'),
        };

        $preview = array_merge($preview, [
            'action' => $action,
            'action_type' => $this->actionType($action),
            'requires_confirmation' => true,
            'confirmation_text' => 'CONFIRMAR',
        ]);

        return array_merge($preview, [
            'token' => $this->previewToken($preview),
        ]);
    }

    public function execute(
        OdessaReconciliationRun $run,
        OdessaReconciliationItem $item,
        User $user,
        string $action,
        string $reason,
        string $confirmation,
        string $previewToken,
    ): array {
        $this->assertItemBelongsToRun($run, $item);
        $action = $this->normalizeAction($action);
        $reason = trim($reason);

        if (! (bool) config('famedic.odessa_reconciliation_actions.enabled', false)) {
            return $this->failed($item, $user, $action, $reason, 'ACTIONS_DISABLED', 'Las acciones correctivas ODESSA no están habilitadas en este ambiente.');
        }

        if ($reason === '') {
            return $this->failed($item, $user, $action, $reason, 'REASON_REQUIRED', 'El motivo es obligatorio.');
        }

        if (mb_strtoupper(trim($confirmation)) !== 'CONFIRMAR') {
            return $this->failed($item, $user, $action, $reason, 'CONFIRMATION_REQUIRED', 'Escribe CONFIRMAR para ejecutar la acción.');
        }

        $item = $item->fresh();
        $preview = $this->preview($run->fresh(), $item, $action);

        if (($preview['blocked_reason_code'] ?? null) === 'ALREADY_APPLIED') {
            return $this->recordAlreadyApplied($item, $user, $preview, $reason);
        }

        if (! ($preview['allowed'] ?? false)) {
            return $this->failed($item, $user, $action, $reason, $preview['blocked_reason_code'] ?? 'ACTION_BLOCKED', $preview['blocked_reason'] ?? 'La acción no está permitida.', $preview);
        }

        if (! hash_equals($preview['token'], $previewToken)) {
            return $this->failed($item, $user, $action, $reason, 'RESOURCE_CHANGED', 'El registro cambió desde el preview. Genera un nuevo preview antes de ejecutar.', $preview);
        }

        return match ($action) {
            self::ACTION_UPDATE_EMAIL => $this->executeUpdateEmail($item, $user, $preview, $reason),
            self::ACTION_LINK_ODESSA_ACCOUNT => $this->executeLinkOdessaAccount($item, $user, $preview, $reason),
            self::ACTION_CREATE_MEMBERSHIP => $this->failed($item, $user, $action, $reason, 'NOT_IMPLEMENTED', 'La creación de membresía queda bloqueada en esta subfase.', $preview),
            self::ACTION_RETRY_MURGUIA_SYNC => $this->executeRetryMurguiaSync($item, $user, $preview, $reason),
            self::ACTION_ACTIVATE_MURGUIA_MEMBERSHIP => $this->executeMurguiaMembershipAction($item, $user, $preview, $reason, 'activate'),
            self::ACTION_DEACTIVATE_MURGUIA_MEMBERSHIP => $this->executeMurguiaMembershipAction($item, $user, $preview, $reason, 'deactivate'),
        };
    }

    private function previewUpdateEmail(OdessaReconciliationItem $item): array
    {
        $user = $item->user_id ? User::query()->with('customer')->find($item->user_id) : null;
        $proposed = $this->normalizeEmail($item->email_excel);
        $current = $this->normalizeEmail($user?->email);

        $base = $this->basePreview($item, [
            'target' => ['type' => User::class, 'id' => $user?->id, 'label' => $user?->full_name ?: $user?->email],
            'before' => ['email' => $current, 'email_verified_at' => $user?->email_verified_at?->toDateTimeString()],
            'after' => ['email' => $proposed, 'email_verified_at' => null],
            'warnings' => ['email_verified_at se invalidará siguiendo el flujo existente de cambio de correo.'],
        ]);

        if (! $user) {
            return $this->blocked($base, 'USER_NOT_FOUND', 'No existe user_id para este item.');
        }

        if (! $this->identityConfirmed($item)) {
            return $this->blocked($base, 'IDENTITY_NOT_CONFIRMED', 'Primero confirme la identidad del colaborador.');
        }

        if (! $this->validEmail($proposed)) {
            return $this->blocked($base, 'INVALID_EMAIL', 'El email del Excel no tiene un formato válido.');
        }

        if ($current === $proposed) {
            return $this->blocked($base, 'ALREADY_APPLIED', 'El usuario ya tiene el email propuesto.');
        }

        $occupant = User::query()
            ->with('customer')
            ->whereRaw('LOWER(email) = ?', [$proposed])
            ->where('id', '!=', $user->id)
            ->first();

        if ($occupant) {
            return $this->blocked(array_merge($base, [
                'conflict' => [
                    'user_id' => $occupant->id,
                    'customer_id' => $occupant->customer?->id,
                    'label' => $occupant->full_name ?: $occupant->email,
                ],
            ]), 'EMAIL_ALREADY_IN_USE', 'El email propuesto ya pertenece a otro usuario.');
        }

        return $this->allowed($base);
    }

    private function executeUpdateEmail(OdessaReconciliationItem $item, User $actor, array $preview, string $reason): array
    {
        return DB::transaction(function () use ($item, $actor, $preview, $reason) {
            $user = User::query()->lockForUpdate()->findOrFail($preview['target']['id']);
            $before = ['email' => $this->normalizeEmail($user->email), 'email_verified_at' => $user->email_verified_at?->toDateTimeString()];
            $after = $preview['after'];

            $user->forceFill([
                'email' => $after['email'],
                'email_verified_at' => null,
            ])->save();

            $this->markFlagsResolved($item, ['EMAIL_DIFFERENT']);

            return $this->completed($item->fresh(), $actor, $preview, $reason, $before, $after, [
                'code' => 'EMAIL_UPDATED',
                'message' => 'Email actualizado. email_verified_at fue invalidado.',
            ]);
        });
    }

    private function previewLinkOdessaAccount(OdessaReconciliationItem $item): array
    {
        $customer = $item->customer_id ? Customer::query()->with('customerable')->find($item->customer_id) : null;
        $account = $item->odessa_id_excel
            ? OdessaAfiliateAccount::withTrashed()->with(['customer', 'odessaAfiliatedCompany'])->where('odessa_identifier', $item->odessa_id_excel)->first()
            : null;

        $base = $this->basePreview($item, [
            'target' => ['type' => OdessaAfiliateAccount::class, 'id' => $account?->id, 'label' => $account?->odessa_identifier],
            'before' => [
                'customer_id' => $customer?->id,
                'customerable_type' => $customer?->customerable_type,
                'customerable_id' => $customer?->customerable_id,
            ],
            'after' => [
                'customerable_type' => OdessaAfiliateAccount::class,
                'customerable_id' => $account?->id,
            ],
        ]);

        if (! $this->identityConfirmed($item)) {
            return $this->blocked($base, 'IDENTITY_NOT_CONFIRMED', 'Primero confirme la identidad del colaborador.');
        }

        if (! $customer) {
            return $this->blocked($base, 'CUSTOMER_NOT_FOUND', 'No existe customer_id para vincular.');
        }

        if (! $account) {
            return $this->blocked($base, 'ODESSA_ACCOUNT_NOT_FOUND', 'No existe una cuenta ODESSA activa para vincular.');
        }

        if ($account->trashed()) {
            return $this->blocked($base, 'ODESSA_ACCOUNT_SOFT_DELETED', 'La cuenta ODESSA existe pero está eliminada; requiere revisión separada.');
        }

        if ((int) $customer->customerable_id === (int) $account->id && $customer->customerable_type === OdessaAfiliateAccount::class) {
            return $this->blocked($base, 'ALREADY_APPLIED', 'El customer ya está vinculado a esta cuenta ODESSA.');
        }

        if ($account->customer && (int) $account->customer->id !== (int) $customer->id) {
            return $this->blocked($base, 'ODESSA_ACCOUNT_CONFLICT', 'La cuenta ODESSA ya está vinculada a otro customer.');
        }

        if ((string) $account->odessa_identifier !== (string) $item->odessa_id_excel) {
            return $this->blocked($base, 'DISCREPANCIA', 'El ID ODESSA no coincide.');
        }

        if ($item->company_excel && (string) $account->odessaAfiliatedCompany?->odessa_identifier !== (string) $item->company_excel) {
            return $this->blocked($base, 'DISCREPANCIA', 'La empresa ODESSA de la cuenta no coincide con el Excel.');
        }

        if ($item->employee_excel && (string) $account->partner_identifier !== (string) $item->employee_excel) {
            return $this->blocked($base, 'DISCREPANCIA', 'El socio/empleado ODESSA de la cuenta no coincide con el Excel.');
        }

        return $this->allowed($base);
    }

    private function executeLinkOdessaAccount(OdessaReconciliationItem $item, User $actor, array $preview, string $reason): array
    {
        return DB::transaction(function () use ($item, $actor, $preview, $reason) {
            $customer = Customer::query()->with('customerable')->lockForUpdate()->findOrFail($item->customer_id);
            $account = OdessaAfiliateAccount::query()->lockForUpdate()->findOrFail($preview['target']['id']);
            $previousCustomerable = $customer->customerable;
            $before = [
                'customer_id' => $customer->id,
                'customerable_type' => $customer->customerable_type,
                'customerable_id' => $customer->customerable_id,
            ];

            $customer->customerable()->associate($account);
            $customer->save();

            if ($previousCustomerable && method_exists($previousCustomerable, 'delete')) {
                $previousCustomerable->delete();
            }

            $after = [
                'customer_id' => $customer->id,
                'customerable_type' => OdessaAfiliateAccount::class,
                'customerable_id' => $account->id,
            ];

            $this->markResolution($item, OdessaReconciliationItem::RESOLUTION_PARTIALLY_RESOLVED);

            return $this->completed($item->fresh(), $actor, $preview, $reason, $before, $after, [
                'code' => 'ODESSA_ACCOUNT_LINKED',
                'message' => 'Customer vinculado a cuenta ODESSA existente.',
            ]);
        });
    }

    private function previewCreateMembership(OdessaReconciliationItem $item): array
    {
        $base = $this->basePreview($item, [
            'target' => ['type' => Customer::class, 'id' => $item->customer_id],
            'before' => [
                'medical_attention_identifier' => $item->medical_attention_identifier,
                'subscription_status' => $item->subscription_status,
                'subscription_id' => $item->subscription_id,
            ],
            'after' => [],
            'warnings' => ['No se inventan vigencias ni números de membresía desde conciliación.'],
        ]);

        return $this->blocked($base, 'INSUFFICIENT_MEMBERSHIP_DATA', 'No hay información suficiente para crear o renovar membresía de forma segura en esta subfase.');
    }

    private function previewRetryMurguiaSync(OdessaReconciliationItem $item): array
    {
        $customer = $item->customer_id ? Customer::query()->with(['user', 'murguiaSyncLogs' => fn ($query) => $query->latest()->limit(1)])->find($item->customer_id) : null;
        $lastLog = $customer?->murguiaSyncLogs?->first();
        $base = $this->basePreview($item, [
            'target' => ['type' => Customer::class, 'id' => $customer?->id, 'label' => $customer?->user?->email],
            'before' => [
                'email' => $customer?->user?->email,
                'medical_attention_identifier' => $customer?->medical_attention_identifier,
                'last_sync_status' => $lastLog?->status,
                'last_sync_action' => $lastLog?->action,
                'last_sync_message' => $lastLog?->message,
            ],
            'after' => ['murguia_action' => 'activate'],
        ]);

        if (! $customer) {
            return $this->blocked($base, 'CUSTOMER_NOT_FOUND', 'No existe customer válido para sincronizar.');
        }

        if (! $this->identityConfirmed($item)) {
            return $this->blocked($base, 'IDENTITY_NOT_CONFIRMED', 'Primero confirme la identidad del colaborador.');
        }

        if (! $customer->medical_attention_identifier) {
            return $this->blocked($base, 'MISSING_MEDICAL_ATTENTION_IDENTIFIER', 'El customer no tiene noCredito para Murguía.');
        }

        return $this->allowed($base);
    }

    private function executeRetryMurguiaSync(OdessaReconciliationItem $item, User $actor, array $preview, string $reason): array
    {
        $actionRow = OdessaReconciliationItemAction::create([
            'item_id' => $item->id,
            'run_id' => $item->run_id,
            'performed_by' => $actor->id,
            'action_type' => OdessaReconciliationItemAction::TYPE_RETRY_MURGUIA_SYNC,
            'status' => OdessaReconciliationItemAction::STATUS_PENDING,
            'target_type' => Customer::class,
            'target_id' => $item->customer_id,
            'before_json' => $preview['before'],
            'after_json' => $preview['after'],
            'request_json' => ['reason' => $reason],
            'reason' => $reason,
            'performed_at' => now(),
        ]);

        $result = ($this->murguiaMonitorSingleCustomerAction)((int) $item->customer_id, 'activate', $actor->id);
        $ok = (bool) ($result['ok'] ?? false);

        $actionRow->update([
            'status' => $ok ? OdessaReconciliationItemAction::STATUS_COMPLETED : OdessaReconciliationItemAction::STATUS_FAILED,
            'result_json' => $result,
            'error_message' => $ok ? null : ($result['message'] ?? 'Murguía rechazó o falló la sincronización.'),
        ]);

        if ($ok) {
            $this->markFlagsResolved($item, ['FAMEDIC_NO_MURGUIA']);
        }

        Log::info('ODESSA_RECONCILIATION_ACTION', [
            'item_id' => $item->id,
            'run_id' => $item->run_id,
            'action_type' => OdessaReconciliationItemAction::TYPE_RETRY_MURGUIA_SYNC,
            'status' => $actionRow->status,
            'customer_id' => $item->customer_id,
        ]);

        return [
            'ok' => $ok,
            'code' => $ok ? 'MURGUIA_SYNC_RETRIED' : 'MURGUIA_SYNC_FAILED',
            'message' => $result['message'] ?? ($ok ? 'Sincronización enviada.' : 'Sincronización fallida.'),
            'action_id' => $actionRow->id,
        ];
    }

    private function previewMurguiaMembershipAction(OdessaReconciliationItem $item, string $murguiaAction): array
    {
        $customer = $item->customer_id ? Customer::query()->with(['user', 'murguiaSyncLogs' => fn ($query) => $query->latest()->limit(1)])->find($item->customer_id) : null;
        $lastLog = $customer?->murguiaSyncLogs?->first();
        $expectedSourceAction = $murguiaAction === 'activate'
            ? OdessaCollaboratorExcelParser::ACTION_ALTA
            : OdessaCollaboratorExcelParser::ACTION_BAJA;

        $base = $this->basePreview($item, [
            'target' => ['type' => Customer::class, 'id' => $customer?->id, 'label' => $customer?->user?->email],
            'before' => [
                'source_action' => $item->source_action,
                'source_action_status' => $item->source_action_status,
                'email' => $customer?->user?->email,
                'medical_attention_identifier' => $customer?->medical_attention_identifier,
                'subscription_status' => $item->subscription_status,
                'murguia_status' => $item->murguia_status,
                'last_sync_status' => $lastLog?->status,
                'last_sync_action' => $lastLog?->action,
                'last_sync_message' => $lastLog?->message,
            ],
            'after' => ['murguia_action' => $murguiaAction],
            'warnings' => $murguiaAction === 'activate'
                ? ['Reutiliza MurguiaMonitorSingleCustomerAction: puede crear licencia institucional ODESSA y cerrar suscripciones no institucionales activas antes de sincronizar Murguía. No crea User, Customer ni cuenta ODESSA.']
                : ['Reutiliza MurguiaMonitorSingleCustomerAction: envía estatus inactivo a Murguía. La suscripción local no se modifica.'],
        ]);

        if (($item->source_action ?? 'NONE') !== $expectedSourceAction) {
            return $this->blocked($base, 'SOURCE_ACTION_MISMATCH', "Esta acción sólo aplica cuando ODESSA marcó {$expectedSourceAction}.");
        }

        if (! $customer) {
            return $this->blocked($base, 'CUSTOMER_NOT_FOUND', 'No existe customer válido para operar Murguía.');
        }

        if (! $this->identityConfirmed($item)) {
            return $this->blocked($base, 'IDENTITY_NOT_CONFIRMED', 'Primero confirme la identidad del colaborador.');
        }

        if (! $customer->medical_attention_identifier) {
            return $this->blocked($base, 'MISSING_MEDICAL_ATTENTION_IDENTIFIER', 'El customer no tiene noCredito para Murguía.');
        }

        if ($this->hasDuplicateRisk($item)) {
            return $this->blocked($base, 'POSSIBLE_DUPLICATE', 'El registro tiene señales de duplicado y requiere revisión antes de operar Murguía.');
        }

        if ($murguiaAction === 'activate' && $item->source_action_status === OdessaReconciliationItem::ACTION_STATUS_ALREADY_ACTIVE) {
            return $this->blocked($base, 'ALREADY_APPLIED', 'La membresía ya aparece activa en FAMEDIC y presente en el reporte Murguía.');
        }

        if ($murguiaAction === 'deactivate' && $item->source_action_status === OdessaReconciliationItem::ACTION_STATUS_ALREADY_INACTIVE) {
            return $this->blocked($base, 'ALREADY_APPLIED', 'El colaborador ya no aparece activo en el reporte Murguía de referencia.');
        }

        return $this->allowed($base);
    }

    private function executeMurguiaMembershipAction(OdessaReconciliationItem $item, User $actor, array $preview, string $reason, string $murguiaAction): array
    {
        $actionType = $murguiaAction === 'activate'
            ? OdessaReconciliationItemAction::TYPE_ACTIVATE_MURGUIA_MEMBERSHIP
            : OdessaReconciliationItemAction::TYPE_DEACTIVATE_MURGUIA_MEMBERSHIP;

        $actionRow = OdessaReconciliationItemAction::create([
            'item_id' => $item->id,
            'run_id' => $item->run_id,
            'performed_by' => $actor->id,
            'action_type' => $actionType,
            'status' => OdessaReconciliationItemAction::STATUS_PENDING,
            'target_type' => Customer::class,
            'target_id' => $item->customer_id,
            'before_json' => $preview['before'],
            'after_json' => $preview['after'],
            'request_json' => ['reason' => $reason],
            'reason' => $reason,
            'performed_at' => now(),
        ]);

        $result = ($this->murguiaMonitorSingleCustomerAction)((int) $item->customer_id, $murguiaAction, $actor->id);
        $ok = (bool) ($result['ok'] ?? false);

        $actionRow->update([
            'status' => $ok ? OdessaReconciliationItemAction::STATUS_COMPLETED : OdessaReconciliationItemAction::STATUS_FAILED,
            'result_json' => $result,
            'error_message' => $ok ? null : ($result['message'] ?? 'Murguía rechazó o falló la operación.'),
        ]);

        $item->update([
            'source_action_status' => $ok
                ? ($murguiaAction === 'activate' ? OdessaReconciliationItem::ACTION_STATUS_ACTIVATED : OdessaReconciliationItem::ACTION_STATUS_DEACTIVATED)
                : OdessaReconciliationItem::ACTION_STATUS_FAILED,
            'resolution_status' => $ok ? OdessaReconciliationItem::RESOLUTION_RESOLVED : $item->resolution_status,
        ]);

        Log::info('ODESSA_RECONCILIATION_ACTION', [
            'item_id' => $item->id,
            'run_id' => $item->run_id,
            'action_type' => $actionType,
            'status' => $actionRow->status,
            'customer_id' => $item->customer_id,
        ]);

        return [
            'ok' => $ok,
            'code' => $ok
                ? ($murguiaAction === 'activate' ? 'MURGUIA_MEMBERSHIP_ACTIVATED' : 'MURGUIA_MEMBERSHIP_DEACTIVATED')
                : 'MURGUIA_MEMBERSHIP_ACTION_FAILED',
            'message' => $result['message'] ?? ($ok ? 'Operación enviada a Murguía.' : 'Operación Murguía fallida.'),
            'action_id' => $actionRow->id,
        ];
    }

    private function basePreview(OdessaReconciliationItem $item, array $data): array
    {
        return array_merge([
            'allowed' => false,
            'blocked_reason' => null,
            'blocked_reason_code' => null,
            'target' => [],
            'before' => [],
            'after' => [],
            'evidence' => [
                'match_type' => $item->match_type,
                'identity_status' => $item->identity_status,
                'odessa_id' => $item->odessa_id_excel,
                'company' => $item->company_excel,
                'employee' => $item->employee_excel,
                'evidence' => $item->evidence_json ?? [],
            ],
            'warnings' => [],
        ], $data);
    }

    private function allowed(array $preview): array
    {
        return array_merge($preview, ['allowed' => true, 'blocked_reason' => null, 'blocked_reason_code' => null]);
    }

    private function blocked(array $preview, string $code, string $reason): array
    {
        return array_merge($preview, ['allowed' => false, 'blocked_reason_code' => $code, 'blocked_reason' => $reason]);
    }

    private function completed(OdessaReconciliationItem $item, User $actor, array $preview, string $reason, array $before, array $after, array $result): array
    {
        $row = OdessaReconciliationItemAction::create([
            'item_id' => $item->id,
            'run_id' => $item->run_id,
            'performed_by' => $actor->id,
            'action_type' => $preview['action_type'],
            'status' => OdessaReconciliationItemAction::STATUS_COMPLETED,
            'target_type' => $preview['target']['type'] ?? null,
            'target_id' => $preview['target']['id'] ?? null,
            'before_json' => $before,
            'after_json' => $after,
            'request_json' => ['reason' => $reason],
            'result_json' => $result,
            'reason' => $reason,
            'performed_at' => now(),
        ]);

        Log::info('ODESSA_RECONCILIATION_ACTION', [
            'item_id' => $item->id,
            'run_id' => $item->run_id,
            'action_type' => $preview['action_type'],
            'status' => OdessaReconciliationItemAction::STATUS_COMPLETED,
            'target_id' => $preview['target']['id'] ?? null,
        ]);

        return [
            'ok' => true,
            'code' => $result['code'],
            'message' => $result['message'],
            'action_id' => $row->id,
        ];
    }

    private function failed(OdessaReconciliationItem $item, User $actor, string $action, string $reason, string $code, string $message, ?array $preview = null): array
    {
        $row = OdessaReconciliationItemAction::create([
            'item_id' => $item->id,
            'run_id' => $item->run_id,
            'performed_by' => $actor->id,
            'action_type' => $this->actionType($action),
            'status' => OdessaReconciliationItemAction::STATUS_FAILED,
            'target_type' => $preview['target']['type'] ?? null,
            'target_id' => $preview['target']['id'] ?? null,
            'before_json' => $preview['before'] ?? null,
            'after_json' => $preview['after'] ?? null,
            'request_json' => ['reason' => $reason],
            'result_json' => ['code' => $code, 'message' => $message],
            'reason' => $reason !== '' ? $reason : null,
            'error_message' => $message,
            'performed_at' => now(),
        ]);

        return ['ok' => false, 'code' => $code, 'message' => $message, 'action_id' => $row->id];
    }

    private function recordAlreadyApplied(OdessaReconciliationItem $item, User $actor, array $preview, string $reason): array
    {
        return $this->completed($item, $actor, $preview, $reason, $preview['before'], $preview['after'], [
            'code' => 'ALREADY_APPLIED',
            'message' => $preview['blocked_reason'] ?? 'La acción ya estaba aplicada.',
        ]);
    }

    private function previewToken(array $preview): string
    {
        $payload = json_encode([
            'action_type' => $preview['action_type'] ?? null,
            'allowed' => $preview['allowed'] ?? false,
            'target' => $preview['target'] ?? [],
            'before' => $preview['before'] ?? [],
            'after' => $preview['after'] ?? [],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return hash_hmac('sha256', $payload, (string) config('app.key'));
    }

    private function normalizeAction(string $action): string
    {
        $action = Str::of($action)->lower()->replace('_', '-')->toString();

        if (! array_key_exists($action, self::permissions())) {
            abort(404);
        }

        return $action;
    }

    private function actionType(string $action): string
    {
        return match ($this->normalizeAction($action)) {
            self::ACTION_UPDATE_EMAIL => OdessaReconciliationItemAction::TYPE_UPDATE_EMAIL,
            self::ACTION_LINK_ODESSA_ACCOUNT => OdessaReconciliationItemAction::TYPE_LINK_ODESSA_ACCOUNT,
            self::ACTION_CREATE_MEMBERSHIP => OdessaReconciliationItemAction::TYPE_CREATE_MEMBERSHIP,
            self::ACTION_RETRY_MURGUIA_SYNC => OdessaReconciliationItemAction::TYPE_RETRY_MURGUIA_SYNC,
            self::ACTION_ACTIVATE_MURGUIA_MEMBERSHIP => OdessaReconciliationItemAction::TYPE_ACTIVATE_MURGUIA_MEMBERSHIP,
            self::ACTION_DEACTIVATE_MURGUIA_MEMBERSHIP => OdessaReconciliationItemAction::TYPE_DEACTIVATE_MURGUIA_MEMBERSHIP,
        };
    }

    private function identityConfirmed(OdessaReconciliationItem $item): bool
    {
        return $item->identity_status === 'CONFIRMED';
    }

    private function hasDuplicateRisk(OdessaReconciliationItem $item): bool
    {
        return array_intersect($item->data_quality_flags_json ?? [], [
            'POSSIBLE_DUPLICATE_PERSON',
            'POSSIBLE_EXISTING_USER',
            'DUPLICATE_ODESSA_ID',
            'DUPLICATE_COMPANY_PARTNER',
            'DUPLICATE_MEMBERSHIP_IDENTIFIER',
        ]) !== [];
    }

    private function normalizeEmail(?string $email): ?string
    {
        $email = mb_strtolower(trim((string) $email));

        return $email !== '' ? $email : null;
    }

    private function validEmail(?string $email): bool
    {
        return Validator::make(['email' => $email], ['email' => ['required', 'email']])->passes();
    }

    private function markFlagsResolved(OdessaReconciliationItem $item, array $flags): void
    {
        $resolved = array_values(array_unique(array_merge($item->resolved_flags_json ?? [], $flags)));
        $item->update([
            'resolved_flags_json' => $resolved,
            'resolution_status' => OdessaReconciliationItem::RESOLUTION_RESOLVED,
        ]);
    }

    private function markResolution(OdessaReconciliationItem $item, string $status): void
    {
        $item->update(['resolution_status' => $status]);
    }

    private function assertItemBelongsToRun(OdessaReconciliationRun $run, OdessaReconciliationItem $item): void
    {
        if ((int) $item->run_id !== (int) $run->id) {
            abort(404);
        }
    }
}
