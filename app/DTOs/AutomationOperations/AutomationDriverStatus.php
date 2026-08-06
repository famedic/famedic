<?php

namespace App\DTOs\AutomationOperations;

readonly class AutomationDriverStatus
{
    /**
     * @param  array<string, mixed>  $stats
     */
    public function __construct(
        public string $key,
        public string $name,
        public string $layer,
        public string $status,
        public ?string $version,
        public string $description,
        public ?string $class,
        public ?string $lastExecutionAt,
        public ?float $avgDurationMs,
        public int $errors,
        public int $retryables,
        public int $executions,
        public array $stats = [],
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'name' => $this->name,
            'layer' => $this->layer,
            'status' => $this->status,
            'version' => $this->version,
            'description' => $this->description,
            'class' => $this->class,
            'last_execution_at' => $this->lastExecutionAt,
            'avg_duration_ms' => $this->avgDurationMs,
            'errors' => $this->errors,
            'retryables' => $this->retryables,
            'executions' => $this->executions,
            'stats' => $this->stats,
        ];
    }
}
