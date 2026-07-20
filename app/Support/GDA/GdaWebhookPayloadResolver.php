<?php

namespace App\Support\GDA;

class GdaWebhookPayloadResolver
{
    /**
     * Normaliza identificadores GDA del payload de webhook.
     *
     * @return array{
     *     service_request_id: string|null,
     *     requisition_value: string|null,
     *     acuse: string|null,
     *     infogda_orden: string|null,
     *     infogda_etiqueta: string|null,
     *     contenedor_acronim: string|null,
     *     is_gabinete: bool,
     *     gda_order_id: string|null,
     *     gda_consecutivo: int|null,
     *     gda_external_id: string|null,
     * }
     */
    public function resolve(array $payload): array
    {
        $serviceRequestId = $this->stringOrNull(data_get($payload, 'id'));
        $requisitionValue = $this->stringOrNull(data_get($payload, 'requisition.value'));
        $acuse = $this->stringOrNull(data_get($payload, 'GDA_menssage.acuse'));

        $coding = data_get($payload, 'code.coding.0', []);
        $coding = is_array($coding) ? $coding : [];

        $infogdaOrden = $this->stringOrNull(data_get($coding, 'infogda_orden'));

        $firstSample = data_get($coding, 'infogda_muestras.0', []);
        $firstSample = is_array($firstSample) ? $firstSample : [];

        $infogdaEtiqueta = $this->stringOrNull(data_get($firstSample, 'infogda_etiqueta'));
        $contenedorAcronim = $this->stringOrNull(data_get($firstSample, 'infogda_contenedoracronim'));

        $isGabinete = $this->detectGabinete($serviceRequestId, $contenedorAcronim);

        $gdaOrderId = $this->resolveGdaOrderId($serviceRequestId, $infogdaEtiqueta, $isGabinete);
        $gdaConsecutivo = $this->resolveGdaConsecutivo($serviceRequestId, $infogdaOrden, $isGabinete);

        return [
            'service_request_id' => $serviceRequestId,
            'requisition_value' => $requisitionValue,
            'acuse' => $acuse,
            'infogda_orden' => $infogdaOrden,
            'infogda_etiqueta' => $infogdaEtiqueta,
            'contenedor_acronim' => $contenedorAcronim,
            'is_gabinete' => $isGabinete,
            'gda_order_id' => $gdaOrderId,
            'gda_consecutivo' => $gdaConsecutivo,
            'gda_external_id' => $requisitionValue,
        ];
    }

    public function isNumericConsecutivo(?string $value): bool
    {
        if ($value === null || $value === '') {
            return false;
        }

        return (bool) preg_match('/^\d+$/', $value);
    }

    protected function resolveGdaOrderId(?string $serviceRequestId, ?string $infogdaEtiqueta, bool $isGabinete = false): ?string
    {
        // Gabinete: la etiqueta (GZ0L…) es el folio consultable. GDA a veces manda
        // ServiceRequest.id numérico junto con la etiqueta; no debemos guardar el numérico.
        if ($isGabinete && $infogdaEtiqueta !== null && $infogdaEtiqueta !== '') {
            return $infogdaEtiqueta;
        }

        if ($serviceRequestId !== null && $serviceRequestId !== '') {
            return $serviceRequestId;
        }

        if ($infogdaEtiqueta !== null && $infogdaEtiqueta !== '') {
            return $infogdaEtiqueta;
        }

        return null;
    }

    protected function resolveGdaConsecutivo(?string $serviceRequestId, ?string $infogdaOrden, bool $isGabinete = false): ?int
    {
        if ($isGabinete) {
            // Si GDA manda ServiceRequest.id numérico (híbrido), conservarlo como
            // consecutivo para agrupar con notificaciones históricas ya guardadas.
            if ($this->isNumericConsecutivo($serviceRequestId)) {
                return (int) $serviceRequestId;
            }

            if ($this->isNumericConsecutivo($infogdaOrden)) {
                return (int) $infogdaOrden;
            }

            return null;
        }

        // Compatibilidad laboratorio normal: si ServiceRequest.id es numérico, es el consecutivo histórico.
        if ($this->isNumericConsecutivo($serviceRequestId)) {
            return (int) $serviceRequestId;
        }

        if ($this->isNumericConsecutivo($infogdaOrden)) {
            return (int) $infogdaOrden;
        }

        return null;
    }

    protected function detectGabinete(?string $serviceRequestId, ?string $contenedorAcronim): bool
    {
        if ($contenedorAcronim !== null && strtoupper($contenedorAcronim) === 'GAB') {
            return true;
        }

        if ($serviceRequestId !== null && preg_match('/^[A-Za-z]/', $serviceRequestId)) {
            return true;
        }

        return false;
    }

    protected function stringOrNull(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }

    /**
     * Campos GDA para actualizar entidades solo cuando están vacíos.
     *
     * @return array<string, mixed>
     */
    public function emptyGdaFieldUpdates(array $resolved, ?string $currentOrderId, int|string|null $currentConsecutivo): array
    {
        $updates = [];

        if (empty($currentOrderId) && ! empty($resolved['gda_order_id'])) {
            $updates['gda_order_id'] = $resolved['gda_order_id'];
        }

        if (
            ($currentConsecutivo === null || $currentConsecutivo === '')
            && isset($resolved['gda_consecutivo'])
            && $resolved['gda_consecutivo'] !== null
        ) {
            $updates['gda_consecutivo'] = $resolved['gda_consecutivo'];
        }

        return $updates;
    }

    /**
     * Identificador de orden para gate/logs: etiqueta o folio normalizado.
     */
    public function gateOrderId(array $resolved, array $data): string
    {
        return (string) ($resolved['gda_order_id'] ?? $data['id'] ?? '');
    }
}
