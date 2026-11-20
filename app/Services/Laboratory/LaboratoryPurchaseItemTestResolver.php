<?php

namespace App\Services\Laboratory;

use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * BLQ-A1-01: resolves the original laboratory_tests.id of an order product.
 *
 * 1. A persisted laboratory_purchase_items.laboratory_test_id is the source of truth (YA_PERSISTIDO).
 * 2. For rows without it (created before the column existed) a candidate is accepted ONLY when the match is
 *    demonstrably unequivocal (RESOLUBLE). The current gda_id alone is not proof: gda_ids are reassigned in place
 *    (e.g. migration 2026_05_16_120000) and a freed gda_id can later belong to a different study. Criterion:
 *      - exactly one laboratory_tests row (soft-deleted rows included) with the order brand and the item gda_id;
 *      - item.created_at, test.created_at and test.updated_at are all present;
 *      - test.created_at <= item.created_at and test.updated_at <= item.created_at, i.e. the catalog row has not
 *        been modified at all (no gda_id change, no soft delete, no edit) since the product was bought, so the
 *        gda_id it carries now is the one it carried then;
 *      - the item name (copied from the catalog at purchase time) equals the catalog name.
 *    Anything that cannot be demonstrated is AMBIGUO; no candidate at all is SIN_FUENTE. Neither yields an id.
 */
final class LaboratoryPurchaseItemTestResolver
{
    public const YA_PERSISTIDO = 'YA_PERSISTIDO';
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

        return ['class' => self::RESOLUBLE, 'reason' => 'CATALOGO_SIN_CAMBIOS_DESDE_EL_PEDIDO', 'laboratory_test_id' => (int) $test->id];
    }

    /** @return array{class: string, reason: string, laboratory_test_id: null} */
    private function none(string $class, string $reason): array
    {
        return ['class' => $class, 'reason' => $reason, 'laboratory_test_id' => null];
    }
}
