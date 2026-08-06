<?php

namespace App\Services\Orders;

use App\DTOs\Orders\OrderAutomationContext;
use App\DTOs\Orders\OrderAutomationDispatchResult;
use App\Jobs\Automation\AutomationExecutionJob;
use App\Models\AutomationRun;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Official fan-out layer for completed-order automations.
 *
 * Phase 4: enqueues one AutomationExecutionJob per registered driver.
 * Drivers are NOT executed inline — workers run them asynchronously.
 * Business logic remains inside drivers; this class only schedules work.
 */
class OrderAutomationDispatcher
{
    /** @var list<class-string> */
    private array $driverClasses;

    /**
     * @param  list<object|string>|null  $drivers  Driver instances or class names (defaults to config)
     */
    public function __construct(?array $drivers = null)
    {
        $resolved = $drivers ?? config('order_automation.drivers', []);

        $this->driverClasses = array_values(array_map(
            function (object|string $driver): string {
                return is_string($driver) ? $driver : $driver::class;
            },
            $resolved
        ));
    }

    public function dispatch(OrderAutomationContext $context): OrderAutomationDispatchResult
    {
        $started = hrtime(true);
        $handler = $this->handlerForChannel($context->channel);
        $payload = $context->toArray();

        Log::info('[Order Automation Dispatcher] enqueue started', [
            'channel' => $context->channel,
            'handler' => $handler,
            'drivers_registered' => count($this->driverClasses),
            'driver_names' => array_map(fn (string $c) => class_basename($c), $this->driverClasses),
            'context' => $payload,
        ]);

        $driverEntries = [];
        $errors = [];
        $queued = 0;
        $failed = 0;

        foreach ($this->driverClasses as $driverClass) {
            $driverName = class_basename($driverClass);
            $uuid = (string) Str::uuid();

            try {
                $run = AutomationRun::query()->create([
                    'automation_uuid' => $uuid,
                    'driver' => $driverName,
                    'driver_class' => $driverClass,
                    'handler' => $handler,
                    'entity_type' => $payload['order_type'] ?? null,
                    'entity_id' => $payload['order_id'] ?? null,
                    'channel' => $context->channel,
                    'attempt' => 1,
                    'status' => AutomationRun::STATUS_PENDING,
                    'payload' => $payload,
                ]);

                AutomationExecutionJob::dispatchForUuid($run->automation_uuid);
                $queued++;

                $driverEntries[] = [
                    'driver' => $driverName,
                    'success' => true,
                    'status' => 'queued',
                    'message' => 'Enqueued AutomationExecutionJob',
                    'automation_uuid' => $uuid,
                    'result' => [
                        'automation_uuid' => $uuid,
                        'status' => AutomationRun::STATUS_PENDING,
                    ],
                ];

                Log::info('[Order Automation Dispatcher] job queued', [
                    'automation_uuid' => $uuid,
                    'driver' => $driverName,
                    'channel' => $context->channel,
                ]);
            } catch (\Throwable $e) {
                $failed++;
                $errors[] = [
                    'driver' => $driverName,
                    'status' => 'enqueue_failed',
                    'message' => $e->getMessage(),
                    'error' => $e->getMessage(),
                    'retryable' => true,
                ];
                $driverEntries[] = [
                    'driver' => $driverName,
                    'success' => false,
                    'status' => 'enqueue_failed',
                    'message' => $e->getMessage(),
                    'result' => null,
                ];

                Log::error('[Order Automation Dispatcher] enqueue failed', [
                    'driver' => $driverName,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $durationMs = (int) round((hrtime(true) - $started) / 1_000_000);

        $dispatchResult = new OrderAutomationDispatchResult(
            drivers: $driverEntries,
            successful: $queued,
            failed: $failed,
            durationMs: $durationMs,
            operations: [],
            errors: $errors,
            channel: $context->channel,
            context: $payload,
            handled: true,
            status: $failed === 0 && $queued > 0
                ? 'queued'
                : ($queued === 0 && $failed === 0 ? 'noop' : 'queued_with_errors'),
            message: sprintf(
                'Queued %d driver job(s): %d enqueued, %d failed to enqueue.',
                count($this->driverClasses),
                $queued,
                $failed
            ),
        );

        Log::info('[Order Automation Dispatcher] enqueue completed', $dispatchResult->toArray());

        return $dispatchResult;
    }

    private function handlerForChannel(string $channel): string
    {
        return match ($channel) {
            OrderAutomationContext::CHANNEL_LABORATORY => 'handleLaboratoryOrder',
            OrderAutomationContext::CHANNEL_PHARMACY => 'handlePharmacyOrder',
            OrderAutomationContext::CHANNEL_MEMBERSHIP => 'handleMembershipOrder',
            default => 'handleLaboratoryOrder',
        };
    }
}
