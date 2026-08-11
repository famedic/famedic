<?php

namespace App\Console\Commands;

use App\Data\Api\V1\Uat\AkubicaUatFixtureContract;
use App\Exceptions\AkubicaUatFixtureException;
use App\Services\Api\V1\Uat\AkubicaUatFixtureMaterializer;
use App\Services\Api\V1\Uat\AkubicaUatFixturePlanner;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class MaterializeAkubicaUatFixturesCommand extends Command
{
    protected $signature = 'akubica:uat-fixtures
                            {--namespace= : Namespace permitido}
                            {--dry-run : Construye el plan sin escribir}
                            {--apply : Materializa fixtures sinteticos}
                            {--reset : Elimina exclusivamente fixtures sinteticos}
                            {--confirm= : Confirmacion exacta del namespace para apply/reset}';

    protected $description = 'Materializa fixtures sinteticos de UAT Akubica en modo seguro y sanitizado.';

    public function handle(): int
    {
        if (! app()->environment((array) config('akubica_uat.allowed_environments', ['testing', 'staging']))) {
            $this->line($this->jsonPayload('error', ['error_code' => 'UAT_ENVIRONMENT_NOT_ALLOWED']));

            return self::FAILURE;
        }

        $namespace = (string) ($this->option('namespace') ?: AkubicaUatFixtureContract::NAMESPACE);
        if ($namespace !== AkubicaUatFixtureContract::NAMESPACE) {
            $this->line($this->jsonPayload('error', ['error_code' => 'UAT_NAMESPACE_NOT_ALLOWED']));

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run') || ! $this->option('apply') && ! $this->option('reset');
        $apply = (bool) $this->option('apply');
        $reset = (bool) $this->option('reset');

        if ($apply && $reset) {
            $this->line($this->jsonPayload('error', ['error_code' => 'UAT_INVALID_OPTION_COMBINATION']));

            return self::FAILURE;
        }

        if (($apply || $reset) && (string) $this->option('confirm') !== $namespace) {
            $this->line($this->jsonPayload('error', ['error_code' => 'UAT_CONFIRMATION_MISMATCH']));

            return self::FAILURE;
        }

        $planner = new AkubicaUatFixturePlanner();

        if ($dryRun) {
            try {
                $plan = $planner->buildPlan('dry-run', false);
            } catch (AkubicaUatFixtureException $exception) {
                $this->line($this->jsonPayload('error', ['error_code' => $exception->errorCode]));

                return self::FAILURE;
            }

            $this->line($this->jsonPayload('ok', $plan->toSanitizedArray()));

            return self::SUCCESS;
        }

        if (! $dryRun) {
            $lock = Cache::lock('akubica-uat-fixtures:'.$namespace, 30);

            if (! $lock->get()) {
                $this->line($this->jsonPayload('error', ['error_code' => 'UAT_LOCK_UNAVAILABLE']));

                return self::FAILURE;
            }
        }

        try {
            $plan = $planner->buildPlan($reset ? 'reset' : 'apply', true);

            /** @var AkubicaUatFixtureMaterializer $materializer */
            $materializer = app(AkubicaUatFixtureMaterializer::class);

            if ($apply) {
                $materializer->assertNoCollisions($plan);
            }

            $result = $apply
                ? $materializer->apply($plan)
                : $materializer->reset($plan);

            $this->line($this->jsonPayload('ok', $result->toSanitizedArray()));

            return self::SUCCESS;
        } catch (AkubicaUatFixtureException $exception) {
            $this->line($this->jsonPayload('error', [
                'namespace' => $namespace,
                'action' => $apply ? 'apply' : 'reset',
                'error_code' => $exception->errorCode,
            ]));

            return self::FAILURE;
        } catch (Throwable $throwable) {
            $errorReference = (string) Str::uuid();
            Log::error('akubica_uat_fixture_unexpected_error', [
                'error_reference' => $errorReference,
                'exception_class' => $throwable::class,
                'phase' => $apply ? 'apply' : 'reset',
                'namespace' => AkubicaUatFixtureContract::NAMESPACE,
            ]);

            $this->line($this->jsonPayload('error', [
                'namespace' => $namespace,
                'action' => $apply ? 'apply' : 'reset',
                'error_code' => 'UAT_FIXTURE_UNEXPECTED_ERROR',
                'error_reference' => $errorReference,
            ]));

            return self::FAILURE;
        } finally {
            if (isset($lock)) {
                $lock->release();
            }
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function jsonPayload(string $status, array $payload): string
    {
        return json_encode(array_merge(['status' => $status], $payload), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
}
