<?php

namespace App\Console\Commands;

use App\Support\PaymentAuthenticationTokenizedAttemptReconciler;
use Illuminate\Console\Command;

class ReconcileTokenizedAttemptsCommand extends Command
{
    protected $signature = 'efevoo:reconcile-tokenized-attempts
                            {--attempt= : ID exacto del intento (obligatorio)}
                            {--target-origin= : Origen destino: mock|test|live (obligatorio)}
                            {--dry-run : Ejecutar en modo simulación (por defecto)}
                            {--apply : Aplicar tras validaciones completas}';

    protected $description = 'Reconcilia intentos TokenCard exitosos cuyo método local quedó incompatible con el ambiente (dry-run por defecto)';

    public function handle(PaymentAuthenticationTokenizedAttemptReconciler $reconciler): int
    {
        $attemptOption = $this->option('attempt');
        $targetOrigin = strtolower(trim((string) ($this->option('target-origin') ?? '')));
        $apply = (bool) $this->option('apply');
        $dryRunFlag = (bool) $this->option('dry-run');

        if ($apply && $dryRunFlag) {
            $this->error('No puedes usar --dry-run y --apply juntos.');

            return self::FAILURE;
        }

        if ($attemptOption === null || trim((string) $attemptOption) === '') {
            $this->error('Debes indicar --attempt=ID (no se permite operación masiva).');

            return self::FAILURE;
        }

        if (! is_numeric($attemptOption) || (int) $attemptOption <= 0) {
            $this->error('--attempt debe ser un ID numérico positivo.');

            return self::FAILURE;
        }

        if ($targetOrigin === '') {
            $this->error('Debes indicar --target-origin=mock|test|live.');

            return self::FAILURE;
        }

        if (! in_array($targetOrigin, ['mock', 'test', 'live'], true)) {
            $this->error('--target-origin debe ser mock, test o live.');

            return self::FAILURE;
        }

        $attemptId = (int) $attemptOption;
        $result = $reconciler->reconcile($attemptId, $targetOrigin, $apply);

        $this->printResult($result);

        $candidates = isset($result['attempt_id']) ? 1 : 0;
        $safeToApply = (! ($result['blocked'] ?? true) && ($result['proposed_action'] ?? 'blocked') !== 'blocked') ? 1 : 0;
        $blocked = ($result['blocked'] ?? true) ? 1 : 0;
        $changesApplied = (int) ($result['changes_applied'] ?? 0);

        $this->newLine();
        $this->line('candidates: '.$candidates);
        $this->line('safe_to_apply: '.$safeToApply);
        $this->line('blocked: '.$blocked);
        $this->line('changes_applied: '.$changesApplied);

        if ($this->output->isVerbose() && isset($result['block_reason'])) {
            $this->comment('block_reason: '.$result['block_reason']);
        }

        return ($apply && ($result['blocked'] ?? true) && $changesApplied === 0)
            ? self::FAILURE
            : self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function printResult(array $result): void
    {
        $fields = [
            'mode',
            'attempt_id',
            'support_reference',
            'customer_id',
            'session_id',
            'provider_order_id',
            'last4',
            'efevoo_token_id',
            'attempt_origin',
            'current_token_origin',
            'target_origin',
            'get_status_approved',
            'token_card_call_count',
            'tokenization_succeeded',
            'token_usuario_present',
            'visible_before',
            'ownership_conflicts',
            'reference_conflicts',
            'proposed_action',
            'provider_calls',
        ];

        foreach ($fields as $field) {
            if (! array_key_exists($field, $result)) {
                continue;
            }

            $value = $result[$field];

            if (is_bool($value)) {
                $value = $value ? 'true' : 'false';
            }

            $this->line($field.': '.$value);
        }
    }
}
