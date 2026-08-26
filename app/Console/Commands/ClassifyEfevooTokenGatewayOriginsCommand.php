<?php

namespace App\Console\Commands;

use App\Models\EfevooToken;
use App\Support\EfevooTokenGatewayOriginPolicy;
use Illuminate\Console\Command;

class ClassifyEfevooTokenGatewayOriginsCommand extends Command
{
    protected $signature = 'efevoo:tokens:classify-gateway-origin
                            {--customer-id= : Limitar a un customer_id}
                            {--token-id= : Clasificar un token específico}
                            {--origin= : Origen a persistir: mock, test o live (requerido con --apply)}
                            {--apply : Persistir metadata.gateway_origin (sin esto: solo dry-run)}
                            {--include-ambiguous-test : Permitir --apply en legacy test sin mock (clasificación explícita)}';

    protected $description = 'Audita y opcionalmente persiste gateway_origin en tokens legacy (dry-run por defecto; no imprime secretos)';

    public function handle(): int
    {
        $apply = (bool) $this->option('apply');
        $origin = strtolower(trim((string) ($this->option('origin') ?? '')));
        $tokenId = $this->option('token-id');
        $customerId = $this->option('customer-id');

        if ($apply && ! in_array($origin, EfevooTokenGatewayOriginPolicy::allowedOrigins(), true)) {
            $this->error('Con --apply debes indicar --origin=mock|test|live');

            return self::FAILURE;
        }

        $query = EfevooToken::query()->withTrashed();

        if ($tokenId) {
            $query->whereKey($tokenId);
        }

        if ($customerId) {
            $query->where('customer_id', $customerId);
        }

        if (! $apply) {
            $query->where(function ($q) {
                $q->whereNull('metadata->gateway_origin')
                    ->orWhere('metadata->gateway_origin', '');
            });
        } elseif ($tokenId) {
            // apply to explicit token even if already classified (re-stamp)
        } else {
            $query->where(function ($q) {
                $q->whereNull('metadata->gateway_origin')
                    ->orWhere('metadata->gateway_origin', '');
            });
        }

        $tokens = $query->orderBy('id')->get();

        if ($tokens->isEmpty()) {
            $this->info('No hay tokens que coincidan con el criterio.');

            return self::SUCCESS;
        }

        $this->line($apply ? 'Modo: APPLY (persistirá gateway_origin)' : 'Modo: DRY-RUN (solo lectura)');
        $this->newLine();

        $rows = [];

        foreach ($tokens as $token) {
            $suggested = EfevooTokenGatewayOriginPolicy::suggestedPersistedOrigin($token);
            $ambiguous = EfevooTokenGatewayOriginPolicy::isAmbiguousLegacy($token);
            $targetOrigin = $apply ? $origin : $suggested;
            $canApply = $apply
                && ($tokenId || ! $ambiguous || (bool) $this->option('include-ambiguous-test'));

            $rows[] = [
                'id' => $token->id,
                'customer_id' => $token->customer_id,
                'last4' => $token->card_last_four,
                'environment' => $token->environment,
                'persisted' => EfevooTokenGatewayOriginPolicy::hasPersistedOrigin($token) ? 'yes' : 'no',
                'resolved' => EfevooTokenGatewayOriginPolicy::resolvedOrigin($token),
                'suggested' => $suggested ?? 'manual',
                'ambiguous' => $ambiguous ? 'yes' : 'no',
                'mock' => $apply ? ($canApply ? 'will_apply' : 'blocked') : '-',
                'live' => EfevooTokenGatewayOriginPolicy::isVisibleInGateway($token, 'live') ? 'yes' : 'no',
                'test_gw' => EfevooTokenGatewayOriginPolicy::isVisibleInGateway($token, 'test') ? 'yes' : 'no',
            ];

            if ($canApply && $targetOrigin) {
                $metadata = is_array($token->metadata) ? $token->metadata : [];
                $metadata['gateway_origin'] = $targetOrigin;
                $metadata['gateway_origin_classified_at'] = now()->toIso8601String();
                $metadata['gateway_origin_classified_by'] = 'efevoo:tokens:classify-gateway-origin';
                $token->forceFill(['metadata' => $metadata])->save();
            }
        }

        $this->table(
            ['id', 'customer_id', 'last4', 'environment', 'persisted', 'resolved', 'suggested', 'ambiguous', 'apply', 'visible_live', 'visible_test_gw'],
            array_map(fn (array $row) => [
                $row['id'],
                $row['customer_id'],
                $row['last4'],
                $row['environment'],
                $row['persisted'],
                $row['resolved'],
                $row['suggested'],
                $row['ambiguous'],
                $row['mock'],
                $row['live'],
                $row['test_gw'],
            ], $rows)
        );

        if ($apply && ! $tokenId && ! $this->option('include-ambiguous-test')) {
            $blocked = collect($rows)->where('ambiguous', 'yes')->count();
            if ($blocked > 0) {
                $this->warn("{$blocked} token(s) legacy test ambiguos no se modificaron. Usa --token-id=ID --origin=... --apply o --include-ambiguous-test.");
            }
        }

        return self::SUCCESS;
    }
}
