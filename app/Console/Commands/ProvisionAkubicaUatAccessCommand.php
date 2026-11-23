<?php

namespace App\Console\Commands;

use App\Data\Api\V1\Uat\AkubicaUatFixtureContract;
use App\Services\Api\V1\Uat\AkubicaUatAccessProvisioner;
use Illuminate\Console\Command;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Encryption\MissingAppKeyException;
use App\Models\User;

final class ProvisionAkubicaUatAccessCommand extends Command
{
    protected $signature = 'akubica:uat-access
        {--namespace= : Namespace dedicado permitido}
        {--dry-run : Plan sin escrituras ni credenciales}
        {--apply : Crear consumers, delegaciones y bindings}
        {--reset : Revocar y limpiar sólo el estado del namespace}
        {--confirm= : Confirmación exacta del namespace}';

    public function handle(AkubicaUatAccessProvisioner $provisioner): int
    {
        if (! app()->environment(['testing', 'staging'])) return $this->failCode('UAT_ENVIRONMENT_NOT_ALLOWED');
        $namespace = (string) ($this->option('namespace') ?: AkubicaUatFixtureContract::DEDICATED_NAMESPACE);
        if ($namespace !== AkubicaUatFixtureContract::DEDICATED_NAMESPACE) return $this->failCode('UAT_ACCESS_NAMESPACE_NOT_ALLOWED');
        if ($this->option('apply') && $this->option('reset') || ! $this->option('dry-run') && ! $this->option('apply') && ! $this->option('reset')) return $this->failCode('UAT_MODE_REQUIRED');
        if (($this->option('apply') || $this->option('reset')) && (string) $this->option('confirm') !== $namespace) return $this->failCode('UAT_CONFIRMATION_MISMATCH');
        try {
            $operatorId = (int) config('akubica_uat.operator_id', 0);
            $operator = $operatorId > 0 ? User::query()->find($operatorId) : null;
            if ($this->option('dry-run')) return $this->json(['status' => 'ok', 'action' => 'dry-run'] + $provisioner->dryRun($namespace, $operator));
            if ($operator === null) return $this->failCode('UAT_OPERATOR_NOT_CONFIGURED');
            $result = $this->option('apply') ? $provisioner->apply($namespace, $operator) : $provisioner->reset($namespace, $operator);
            return $this->json($result + ['action' => $this->option('apply') ? 'apply' : 'reset']);
        } catch (\Throwable $exception) {
            $errorCode = match (true) {
                $exception instanceof AuthorizationException => 'UAT_OPERATOR_UNAUTHORIZED',
                $exception instanceof MissingAppKeyException => 'UAT_CREDENTIAL_CUSTODY_UNAVAILABLE',
                $exception instanceof \DomainException => $exception->getMessage(),
                default => 'UAT_ACCESS_FAILED',
            };
            $this->json(['status' => 'error', 'error_code' => $errorCode, 'exception_type' => $exception::class]);
            return self::FAILURE;
        }
    }

    private function json(array $payload): int { $this->line(json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)); return self::SUCCESS; }
    private function failCode(string $code): int { $this->json(['status' => 'error', 'error_code' => $code]); return self::FAILURE; }
}
