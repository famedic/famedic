<?php

namespace App\Services\Laboratory;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * BLQ-A1-01: classifies the original laboratory_tests.id of an order product.
 *
 * 1. A persisted laboratory_purchase_items.laboratory_test_id is the source of truth (YA_PERSISTIDO) and is the ONLY
 *    way an id is returned.
 * 2. AJ-BLQ-A1-01-A (IT-0035): rows without a persisted id are never resolved from dates plus catalog matches. The
 *    schema does not enforce updated_at (no ON UPDATE, no catalog audit), the repository already writes
 *    laboratory_tests through the query builder without touching updated_at, and catalog history before 2025-10-09
 *    is not traceable, so "catalog row unchanged since the purchase" cannot be demonstrated. Such a row is
 *    AMBIGUO / TIMESTAMPS_NO_CONCLUYENTES (BLOCKED). The remaining reasons are kept only to measure the history:
 *      - SIN_FUENTE: no gda_id, no order brand, or no catalog row (soft-deleted rows included) for brand + gda_id;
 *      - AMBIGUO: several candidates, missing timestamps, candidate created/modified after the purchase, deleted
 *        before it, different name, or (all of those passing) TIMESTAMPS_NO_CONCLUYENTES.
 *    No rule emits RESOLUBLE: it is reserved for an independent, verifiable source that does not exist today.
 */
final class LaboratoryPurchaseItemTestResolver
{
    public const YA_PERSISTIDO = 'YA_PERSISTIDO';
    /** Reserved (AJ-BLQ-A1-01-A): no current rule emits it; only an independent verifiable source could. */
    public const RESOLUBLE = 'RESOLUBLE';
    public const AMBIGUO = 'AMBIGUO';
    public const SIN_FUENTE = 'SIN_FUENTE';

    /**
     * Candidate laboratory_tests rows (soft-deleted included), grouped by "brand|gda_id".
     *
     * @param  iterable<array{brand: ?string, gda_id: ?string}>  $keys
     * @return Collection<string, Collection<int, object>>
     */
    public function candidates(iterable $keys): Collection
    {
        $byBrand = [];
        foreach ($keys as $key) {
            if (($key['brand'] ?? null) !== null && (string) ($key['gda_id'] ?? '') !== '') {
                $byBrand[(string) $key['brand']][] = (string) $key['gda_id'];
            }
        }

        $rows = collect();
        foreach ($byBrand as $brand => $gdaIds) {
            $rows = $rows->concat(DB::table('laboratory_tests')
                ->select(['id', 'brand', 'gda_id', 'name', 'created_at', 'updated_at', 'deleted_at'])
                ->where('brand', $brand)
                ->whereIn('gda_id', array_values(array_unique($gdaIds)))
                ->get());
        }

        return $rows->groupBy(fn (object $row) => $row->brand.'|'.$row->gda_id);
    }

    /**
     * @param  object  $item  laboratory_purchase_items row/model (laboratory_test_id, gda_id, name, created_at)
     * @param  Collection<string, Collection<int, object>>  $candidates
     * @return array{class: string, reason: string, laboratory_test_id: ?int}
     */
    public function classify(object $item, ?string $brand, Collection $candidates): array
    {
        if ($item->laboratory_test_id !== null) {
            return ['class' => self::YA_PERSISTIDO, 'reason' => 'ID_PERSISTIDO', 'laboratory_test_id' => (int) $item->laboratory_test_id];
        }
        if ((string) ($item->gda_id ?? '') === '') {
            return $this->none(self::SIN_FUENTE, 'SIN_GDA_ID');
        }
        if ($brand === null || $brand === '') {
            return $this->none(self::SIN_FUENTE, 'SIN_MARCA_DEL_PEDIDO');
        }

        $matches = $candidates->get($brand.'|'.$item->gda_id, collect());
        if ($matches->isEmpty()) {
            return $this->none(self::SIN_FUENTE, 'SIN_CANDIDATO_EN_CATALOGO');
        }
        if ($matches->count() > 1) {
            return $this->none(self::AMBIGUO, 'MULTIPLES_CANDIDATOS');
        }

        $test = $matches->first();
        if ($item->created_at === null || $test->created_at === null || $test->updated_at === null) {
            return $this->none(self::AMBIGUO, 'SIN_MARCAS_DE_TIEMPO');
        }

        $bought = Carbon::parse($item->created_at);
        if (Carbon::parse($test->created_at)->gt($bought)) {
            return $this->none(self::AMBIGUO, 'CATALOGO_CREADO_DESPUES_DEL_PEDIDO');
        }
        if (Carbon::parse($test->updated_at)->gt($bought)) {
            return $this->none(self::AMBIGUO, 'CATALOGO_MODIFICADO_DESPUES_DEL_PEDIDO');
        }
        if ($test->deleted_at !== null && Carbon::parse($test->deleted_at)->lte($bought)) {
            return $this->none(self::AMBIGUO, 'ELIMINADO_ANTES_DEL_PEDIDO');
        }
        if (trim((string) $item->name) !== trim((string) $test->name)) {
            return $this->none(self::AMBIGUO, 'NOMBRE_DISTINTO');
        }

        // AJ-BLQ-A1-01-A: matching dates and name are not proof of the historical study; no id is derived.
        return $this->none(self::AMBIGUO, 'TIMESTAMPS_NO_CONCLUYENTES');
    }

    /** @return array{class: string, reason: string, laboratory_test_id: null} */
    private function none(string $class, string $reason): array
    {
        return ['class' => $class, 'reason' => $reason, 'laboratory_test_id' => null];
    }
}
