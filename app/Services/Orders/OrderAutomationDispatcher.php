<?php

namespace App\Services\Orders;

use App\DTOs\Orders\OrderAutomationContext;
use App\DTOs\Orders\OrderAutomationDispatchResult;
use App\DTOs\Orders\OrderAutomationResult;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Official fan-out layer for completed-order automations.
 *
 * Fulfill / callers notify order completion → OrderAutomationService → this dispatcher.
 * Drivers are registered in config/order_automation.php — add Email/WhatsApp/etc.
 * without changing this class.
 */
class OrderAutomationDispatcher
{
    /** @var list<object> */
    private array $drivers;

    /**
     * @param  list<object|string>|null  $drivers  Driver instances or class names (defaults to config)
     */
    public function __construct(?array $drivers = null)
    {
        $resolved = $drivers ?? config('order_automation.drivers', []);

        $this->drivers = array_values(array_map(
            function (object|string $driver): object {
                return is_string($driver) ? app($driver) : $driver;
            },
            $resolved
        ));
    }

    public function dispatch(OrderAutomationContext $context): OrderAutomationDispatchResult
    {
        $started = hrtime(true);

        Log::info('[Order Automation Dispatcher] dispatch started', [
            'channel' => $context->channel,
            'drivers_registered' => count($this->drivers),
            'driver_names' => array_map(fn (object $d) => class_basename($d), $this->drivers),
            'context' => $context->toArray(),
        ]);

        $driverEntries = [];
        $operations = [];
        $errors = [];
        $successful = 0;
        $failed = 0;

        foreach ($this->drivers as $driver) {
            $driverName = class_basename($driver);

            Log::info('[Driver Execution] starting', [
                'driver' => $driverName,
                'channel' => $context->channel,
            ]);

            try {
                $result = $this->executeDriver($driver, $context);
                $resultArray = $result->toArray();
                $driverSuccess = $this->isDriverSuccess($result);

                $driverEntries[] = [
                    'driver' => $driverName,
                    'success' => $driverSuccess,
                    'status' => $result->status,
                    'message' => $result->message,
                    'result' => $resultArray,
                ];

                foreach ($result->activecampaign['operations'] ?? [] as $operation) {
                    if (is_array($operation)) {
                        $operations[] = array_merge(['driver' => $driverName], $operation);
                    }
                }

                if ($driverSuccess) {
                    $successful++;
                    Log::info('[Driver Success]', [
                        'driver' => $driverName,
                        'channel' => $context->channel,
                        'status' => $result->status,
                        'activecampaign' => $result->activecampaign,
                    ]);
                } else {
                    $failed++;
                    $errorEntry = [
                        'driver' => $driverName,
                        'status' => $result->status,
                        'message' => $result->message,
                        'error' => $result->activecampaign['error'] ?? $result->message,
                        'retryable' => $result->activecampaign['retryable'] ?? null,
                    ];
                    $errors[] = $errorEntry;

                    Log::warning('[Driver Failed]', $errorEntry);
                }
            } catch (Throwable $e) {
                $failed++;
                $errorEntry = [
                    'driver' => $driverName,
                    'status' => 'exception',
                    'message' => $e->getMessage(),
                    'error' => $e->getMessage(),
                    'retryable' => true,
                ];
                $errors[] = $errorEntry;
                $driverEntries[] = [
                    'driver' => $driverName,
                    'success' => false,
                    'status' => 'exception',
                    'message' => $e->getMessage(),
                    'result' => null,
                ];

                Log::error('[Driver Failed] exception', [
                    'driver' => $driverName,
                    'channel' => $context->channel,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $durationMs = (int) round((hrtime(true) - $started) / 1_000_000);

        $dispatchResult = new OrderAutomationDispatchResult(
            drivers: $driverEntries,
            successful: $successful,
            failed: $failed,
            durationMs: $durationMs,
            operations: $operations,
            errors: $errors,
            channel: $context->channel,
            context: $context->toArray(),
            handled: true,
            status: $failed === 0 && $successful > 0
                ? 'completed'
                : ($successful === 0 && $failed === 0 ? 'noop' : 'completed_with_errors'),
            message: sprintf(
                'Dispatched %d driver(s): %d successful, %d failed.',
                count($this->drivers),
                $successful,
                $failed
            ),
        );

        Log::info('[Order Automation Dispatcher] dispatch completed', $dispatchResult->toArray());

        return $dispatchResult;
    }

    private function executeDriver(object $driver, OrderAutomationContext $context): OrderAutomationResult
    {
        return match ($context->channel) {
            OrderAutomationContext::CHANNEL_LABORATORY => $driver->handleLaboratoryOrder($context),
            OrderAutomationContext::CHANNEL_PHARMACY => $driver->handlePharmacyOrder($context),
            OrderAutomationContext::CHANNEL_MEMBERSHIP => $driver->handleMembershipOrder($context),
            default => new OrderAutomationResult(
                handler: 'dispatch',
                status: 'failed',
                handled: false,
                message: "Unsupported order channel: {$context->channel}",
                context: $context->toArray(),
                automationsExecuted: false,
                activecampaign: OrderAutomationResult::emptyActiveCampaignPayload('unsupported_channel'),
            ),
        };
    }

    private function isDriverSuccess(OrderAutomationResult $result): bool
    {
        if (array_key_exists('success', $result->activecampaign) && $result->activecampaign['success'] !== null) {
            return (bool) $result->activecampaign['success'];
        }

        return in_array($result->status, ['synced', 'prepared', 'completed'], true);
    }
}
