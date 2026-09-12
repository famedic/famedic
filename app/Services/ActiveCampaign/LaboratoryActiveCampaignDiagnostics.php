<?php

namespace App\Services\ActiveCampaign;

use App\Models\ActiveCampaignDispatch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

class LaboratoryActiveCampaignDiagnostics
{
    public const STATUS_OK = 'OK';
    public const STATUS_MISSING_CONFIG = 'MISSING_CONFIG';
    public const STATUS_FIELD_NOT_FOUND = 'FIELD_NOT_FOUND';
    public const STATUS_TITLE_MISMATCH = 'TITLE_MISMATCH';
    public const STATUS_TYPE_WARNING = 'TYPE_WARNING';
    public const STATUS_API_ERROR = 'API_ERROR';

    public const EXIT_OK = 0;
    public const EXIT_CONFIG_ERROR = 1;
    public const EXIT_API_ERROR = 2;

    public function __construct(
        private ActiveCampaignService $activeCampaign,
    ) {}

    /**
     * @return array<string, array{env:string,config:string,title:string,types:list<string>}>
     */
    public static function fieldDefinitions(): array
    {
        return [
            'url_finalizar_compra' => [
                'env' => 'ACTIVECAMPAIGN_FIELD_LAB_URL_FINALIZAR_COMPRA',
                'config' => 'services.activecampaign.fields.lab.url_finalizar_compra',
                'title' => 'URL Finalizar Compra',
                'types' => ['text'],
            ],
            'paciente_lab' => [
                'env' => 'ACTIVECAMPAIGN_FIELD_LAB_PACIENTE',
                'config' => 'services.activecampaign.fields.lab.paciente_lab',
                'title' => 'Paciente Laboratorio',
                'types' => ['text'],
            ],
            'sucursal_lab' => [
                'env' => 'ACTIVECAMPAIGN_FIELD_LAB_SUCURSAL',
                'config' => 'services.activecampaign.fields.lab.sucursal_lab',
                'title' => 'Sucursal Laboratorio',
                'types' => ['text'],
            ],
            'google_maps_lab' => [
                'env' => 'ACTIVECAMPAIGN_FIELD_LAB_GOOGLE_MAPS',
                'config' => 'services.activecampaign.fields.lab.google_maps_lab',
                'title' => 'Google Maps Laboratorio',
                'types' => ['text'],
            ],
            'direccion_lab' => [
                'env' => 'ACTIVECAMPAIGN_FIELD_LAB_DIRECCION',
                'config' => 'services.activecampaign.fields.lab.direccion_lab',
                'title' => 'Direccion Laboratorio',
                'types' => ['textarea', 'text'],
            ],
            'fecha_cita_lab' => [
                'env' => 'ACTIVECAMPAIGN_FIELD_LAB_FECHA_CITA',
                'config' => 'services.activecampaign.fields.lab.fecha_cita_lab',
                'title' => 'Fecha Cita Laboratorio',
                'types' => ['date'],
            ],
            'horario_cita_lab' => [
                'env' => 'ACTIVECAMPAIGN_FIELD_LAB_HORARIO_CITA',
                'config' => 'services.activecampaign.fields.lab.horario_cita_lab',
                'title' => 'Horario Cita Laboratorio',
                'types' => ['text'],
            ],
            'folio_famedic' => [
                'env' => 'ACTIVECAMPAIGN_FIELD_LAB_FOLIO_FAMEDIC',
                'config' => 'services.activecampaign.fields.lab.folio_famedic',
                'title' => 'Folio FAMEDIC',
                'types' => ['text'],
            ],
            'gda_consecutivo' => [
                'env' => 'ACTIVECAMPAIGN_FIELD_LAB_GDA_CONSECUTIVO',
                'config' => 'services.activecampaign.fields.lab.gda_consecutivo',
                'title' => 'Consecutivo GDA',
                'types' => ['text'],
            ],
            'mapa_sucursales_labs' => [
                'env' => 'ACTIVECAMPAIGN_FIELD_LAB_MAPA_SUCURSALES',
                'config' => 'services.activecampaign.fields.lab.mapa_sucursales_labs',
                'title' => 'Mapa Sucursales Laboratorio',
                'types' => ['text'],
            ],
            'toma_de_muestra_lab' => [
                'env' => 'ACTIVECAMPAIGN_FIELD_LAB_TOMA_MUESTRA',
                'config' => 'services.activecampaign.fields.lab.toma_de_muestra_lab',
                'title' => 'Toma de Muestra',
                'types' => ['text'],
            ],
            'resultados_lab' => [
                'env' => 'ACTIVECAMPAIGN_FIELD_LAB_RESULTADOS',
                'config' => 'services.activecampaign.fields.lab.resultados_lab',
                'title' => 'Resultados Disponibles',
                'types' => ['text'],
            ],
        ];
    }

    /**
     * @return array<string, array{config:string,label:string,expected_name:string|null}>
     */
    public static function tagDefinitions(): array
    {
        return [
            'cart.abandoned' => [
                'config' => 'services.activecampaign.tags.cart.abandoned',
                'label' => 'Tag carrito abandonado',
                'expected_name' => 'Carrito abandonado',
            ],
            'cart.appointment_pending' => [
                'config' => 'services.activecampaign.tags.cart.appointment_pending',
                'label' => 'Tag cita pendiente',
                'expected_name' => 'Cita pendiente',
            ],
            'laboratory_purchase_completed' => [
                'config' => 'services.activecampaign.tag_laboratory_purchase_completed',
                'label' => 'Tag laboratorio compra completada',
                'expected_name' => null,
            ],
            'lab_sample_collected' => [
                'config' => 'services.activecampaign.tag_lab_sample_collected',
                'label' => 'Tag toma de muestra',
                'expected_name' => null,
            ],
            'lab_results_available' => [
                'config' => 'services.activecampaign.tag_lab_results_available',
                'label' => 'Tag resultados disponibles',
                'expected_name' => null,
            ],
        ];
    }

    /**
     * @return array{ok:int,warnings:int,errors:int,api_error:bool,http_status:int|null,error:string|null,fields:list<array<string,mixed>>,exit_code:int}
     */
    public function verifyFields(): array
    {
        $result = $this->activeCampaign->getCustomFieldsResult();

        if (! $result->success) {
            return $this->apiErrorResult('fields', $result->httpStatus, $result->error, count(self::fieldDefinitions()));
        }

        $fields = is_array($result->response) ? ($result->response['fields'] ?? []) : [];
        $fieldsById = collect($fields)->keyBy(fn (array $field) => (string) ($field['id'] ?? ''));
        $fieldsByTitle = collect($fields)->keyBy(fn (array $field) => $this->normalizeTitle((string) ($field['title'] ?? '')));
        $rows = [];

        foreach (self::fieldDefinitions() as $key => $definition) {
            $configuredId = config($definition['config']);
            $configuredId = $configuredId === null || trim((string) $configuredId) === ''
                ? null
                : (string) $configuredId;

            $field = $configuredId ? $fieldsById->get($configuredId) : null;
            $expectedTitleField = $fieldsByTitle->get($this->normalizeTitle($definition['title']));
            $status = self::STATUS_OK;

            if ($configuredId === null) {
                $status = self::STATUS_MISSING_CONFIG;
                $field = is_array($expectedTitleField) ? $expectedTitleField : $field;
            } elseif (! is_array($field)) {
                $status = self::STATUS_FIELD_NOT_FOUND;
                $field = is_array($expectedTitleField) ? $expectedTitleField : $field;
            } elseif (! $this->titlesMatch((string) ($field['title'] ?? ''), $definition['title'])) {
                $status = self::STATUS_TITLE_MISMATCH;
            } elseif (! in_array((string) ($field['type'] ?? ''), $definition['types'], true)) {
                $status = self::STATUS_TYPE_WARNING;
            }

            $rows[] = [
                'key' => $key,
                'env' => $definition['env'],
                'config' => $definition['config'],
                'configured_id' => $configuredId,
                'expected_title' => $definition['title'],
                'expected_types' => $definition['types'],
                'ac_field_id' => $field['id'] ?? null,
                'ac_title' => $field['title'] ?? null,
                'type' => $field['type'] ?? null,
                'personalization_tag' => $field['perstag'] ?? null,
                'status' => $status,
            ];
        }

        return $this->summarize('fields', $rows);
    }

    /**
     * @return array{ok:int,warnings:int,errors:int,api_error:bool,http_status:int|null,error:string|null,tags:list<array<string,mixed>>,exit_code:int}
     */
    public function verifyTags(): array
    {
        $result = $this->activeCampaign->getTagsResult();

        if (! $result->success) {
            $apiError = $this->apiErrorResult('tags', $result->httpStatus, $result->error, count(self::tagDefinitions()));

            return array_merge($apiError, ['tags' => [], 'fields' => null]);
        }

        $tags = is_array($result->response) ? ($result->response['tags'] ?? []) : [];
        $tagsById = collect($tags)->keyBy(fn (array $tag) => (string) ($tag['id'] ?? ''));
        $tagsByName = collect($tags)->keyBy(fn (array $tag) => $this->normalizeTitle((string) ($tag['tag'] ?? $tag['name'] ?? '')));
        $rows = [];

        foreach (self::tagDefinitions() as $key => $definition) {
            $configured = config($definition['config']);
            $configured = $configured === null || trim((string) $configured) === '' ? null : trim((string) $configured);
            $tag = null;
            $status = self::STATUS_OK;

            if ($configured === null) {
                $status = self::STATUS_MISSING_CONFIG;
            } elseif (ctype_digit($configured)) {
                $tag = $tagsById->get($configured);
                if (! is_array($tag)) {
                    $status = 'TAG_NOT_FOUND';
                }
            } else {
                $tag = $tagsByName->get($this->normalizeTitle($configured));
                if (! is_array($tag)) {
                    $status = 'TAG_NOT_FOUND';
                }
            }

            if ($status === self::STATUS_OK && is_array($tag) && $definition['expected_name'] !== null) {
                $actualName = (string) ($tag['tag'] ?? $tag['name'] ?? '');
                if (! $this->titlesMatch($actualName, $definition['expected_name'])) {
                    $status = 'NAME_MISMATCH';
                }
            }

            $rows[] = [
                'key' => $key,
                'config' => $definition['config'],
                'label' => $definition['label'],
                'configured' => $configured,
                'tag_id' => $tag['id'] ?? null,
                'tag_name' => $tag['tag'] ?? $tag['name'] ?? null,
                'status' => $status,
            ];
        }

        $summary = $this->summarize('tags', $rows);

        return [
            'ok' => $summary['ok'],
            'warnings' => $summary['warnings'],
            'errors' => $summary['errors'],
            'api_error' => $summary['api_error'],
            'http_status' => $summary['http_status'],
            'error' => $summary['error'],
            'tags' => $summary['tags'],
            'exit_code' => $summary['exit_code'],
        ];
    }

    /**
     * @return Builder<ActiveCampaignDispatch>
     */
    public function dispatchQuery(array $filters = []): Builder
    {
        return ActiveCampaignDispatch::query()
            ->when($filters['id'] ?? null, fn (Builder $query, mixed $id) => $query->whereKey((int) $id))
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['event_type'] ?? null, fn (Builder $query, string $eventType) => $query->where('event_type', $eventType))
            ->when($filters['operation'] ?? null, fn (Builder $query, string $operation) => $query->where('payload->operation', $operation));
    }

    /**
     * @return array<string, mixed>
     */
    public function dispatchRow(ActiveCampaignDispatch $dispatch, bool $detail = false): array
    {
        $payload = $dispatch->payload ?? [];
        $customFields = is_array($payload['custom_fields'] ?? null) ? $payload['custom_fields'] : [];

        $row = [
            'id' => $dispatch->id,
            'status' => $dispatch->status,
            'operation' => $payload['operation'] ?? null,
            'event_type' => $dispatch->event_type,
            'attempts' => $dispatch->attempts,
            'customer_id' => $dispatch->customer_id,
            'purchase_id' => $payload['laboratory_purchase_id'] ?? ($dispatch->entity_type === 'laboratory_purchase' ? $dispatch->entity_id : null),
            'cart_id' => $payload['cart_id'] ?? ($dispatch->entity_type === 'cart' ? $dispatch->entity_id : null),
            'created_at' => $dispatch->created_at?->toDateTimeString(),
            'last_error' => $dispatch->last_error,
            'field_keys' => implode(',', array_keys($customFields)),
        ];

        if (! $detail) {
            return $row;
        }

        return array_merge($row, [
            'idempotency_key' => $dispatch->idempotency_key,
            'entity_type' => $dispatch->entity_type,
            'entity_id' => $dispatch->entity_id,
            'related_entity_type' => $dispatch->related_entity_type,
            'related_entity_id' => $dispatch->related_entity_id,
            'email' => $this->redactEmail($dispatch->email),
            'updated_at' => $dispatch->updated_at?->toDateTimeString(),
            'synced_at' => $dispatch->synced_at?->toDateTimeString(),
            'payload' => $this->safePayload($payload),
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function safePayload(array $payload): array
    {
        $safe = [];

        foreach ($payload as $key => $value) {
            if ($key === 'contact') {
                $safe[$key] = '[REDACTED]';
                continue;
            }

            if ($key === 'custom_fields' && is_array($value)) {
                $safe[$key] = array_map(fn (mixed $fieldValue, string $fieldKey) => $this->redactPayloadValue($fieldKey, $fieldValue), $value, array_keys($value));
                continue;
            }

            $safe[$key] = $this->redactPayloadValue((string) $key, $value);
        }

        return $safe;
    }

    private function redactPayloadValue(string $key, mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }

        if ($key === 'url_finalizar_compra' || str_contains($value, '/laboratory/checkout/resume/')) {
            return preg_replace('#(/laboratory/checkout/resume/)[^/?\s]+#', '$1[REDACTED]', $value);
        }

        if (in_array($key, ['direccion_lab', 'address'], true)) {
            return '[REDACTED]';
        }

        if ($key === 'paciente_lab') {
            return $this->redactName($value);
        }

        return $value;
    }

    /**
     * @param  list<array<string,mixed>>  $rows
     * @return array{ok:int,warnings:int,errors:int,api_error:bool,http_status:int|null,error:string|null,fields?:list<array<string,mixed>>,tags?:list<array<string,mixed>>,exit_code:int}
     */
    private function summarize(string $key, array $rows): array
    {
        $warnings = collect($rows)->where('status', self::STATUS_TYPE_WARNING)->count();
        $errors = collect($rows)
            ->reject(fn (array $row) => in_array($row['status'], [self::STATUS_OK, self::STATUS_TYPE_WARNING], true))
            ->count();

        return [
            'ok' => collect($rows)->where('status', self::STATUS_OK)->count(),
            'warnings' => $warnings,
            'errors' => $errors,
            'api_error' => false,
            'http_status' => null,
            'error' => null,
            $key => $rows,
            'exit_code' => ($errors > 0 || $warnings > 0) ? self::EXIT_CONFIG_ERROR : self::EXIT_OK,
        ];
    }

    /**
     * @return array{ok:int,warnings:int,errors:int,api_error:bool,http_status:int|null,error:string|null,fields:list<array<string,mixed>>,exit_code:int}
     */
    private function apiErrorResult(string $resource, ?int $httpStatus, ?string $error, int $errorCount): array
    {
        return [
            'ok' => 0,
            'warnings' => 0,
            'errors' => $errorCount,
            'api_error' => true,
            'http_status' => $httpStatus,
            'error' => $this->safeApiErrorMessage($resource, $httpStatus, $error),
            'fields' => [],
            'exit_code' => self::EXIT_API_ERROR,
        ];
    }

    private function safeApiErrorMessage(string $resource, ?int $httpStatus, ?string $error): string
    {
        return match (true) {
            in_array($httpStatus, [401, 403], true) => "ActiveCampaign {$resource}: credenciales o permisos invalidos ({$httpStatus}).",
            $httpStatus === 404 => "ActiveCampaign {$resource}: endpoint o recurso no encontrado (404).",
            $httpStatus === 429 => "ActiveCampaign {$resource}: rate limit (429).",
            $httpStatus !== null && $httpStatus >= 500 => "ActiveCampaign {$resource}: error temporal del API ({$httpStatus}).",
            default => 'ActiveCampaign '.$resource.': '.($error ?: 'API_ERROR'),
        };
    }

    private function titlesMatch(string $actual, string $expected): bool
    {
        return $this->normalizeTitle($actual) === $this->normalizeTitle($expected);
    }

    private function normalizeTitle(string $title): string
    {
        return Str::of($title)
            ->ascii()
            ->lower()
            ->replaceMatches('/[^a-z0-9]+/', ' ')
            ->squish()
            ->toString();
    }

    private function redactEmail(?string $email): ?string
    {
        if (! $email || ! str_contains($email, '@')) {
            return $email;
        }

        [$local, $domain] = explode('@', $email, 2);

        return Str::substr($local, 0, 1).'***@'.$domain;
    }

    private function redactName(string $name): string
    {
        return collect(explode(' ', trim($name)))
            ->filter()
            ->map(fn (string $part) => Str::substr($part, 0, 1).'***')
            ->implode(' ');
    }
}
