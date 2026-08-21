<?php

namespace App\Console\Commands;

use App\Services\Monitoring\MultibrandCartReconciler;
use Illuminate\Console\Command;

class ReconcileMultibrandCartsCommand extends Command
{
    protected $signature = 'carts:reconcile-multibrand
                            {--cart= : Limitar a un cart_id}
                            {--customer= : Limitar a un customer_id}
                            {--limit= : Limitar cantidad de carts activos analizados}
                            {--apply : Aplicar cambios; por default es dry-run}';

    protected $description = 'Detecta y reconcilia de forma segura carritos activos de laboratorio con multiples marcas';

    public function handle(MultibrandCartReconciler $reconciler): int
    {
        $cartId = $this->optionInt('cart');
        $customerId = $this->optionInt('customer');
        $limit = $this->optionInt('limit');
        $apply = (bool) $this->option('apply');

        $this->info($apply ? 'RECONCILIACION MULTIBRAND - APPLY' : 'RECONCILIACION MULTIBRAND - DRY RUN');
        $this->line('Scope: activos, tipo lab, con mas de una brand real en cart_items.');
        $this->newLine();

        $carts = $reconciler->candidates($cartId, $customerId, $limit);
        $summary = [
            'analizados' => $carts->count(),
            'reconciliados' => 0,
            'sin_cambios' => 0,
            'conflictos' => 0,
            'errores' => 0,
        ];

        if ($carts->isEmpty()) {
            $this->comment('No se detectaron carts activos multibrand para el filtro indicado.');
        }

        foreach ($carts as $cart) {
            $result = $apply ? $reconciler->apply($cart) : array_merge($reconciler->plan($cart), ['result' => 'dry_run']);
            $this->printPlan($result);

            match ($result['result'] ?? 'dry_run') {
                'reconciled' => $summary['reconciliados']++,
                'no_changes' => $summary['sin_cambios']++,
                'skipped_conflict' => $summary['conflictos']++,
                'error' => $summary['errores']++,
                default => null,
            };
        }

        $completed = $reconciler->completedMultibrand($cartId, $customerId);
        $completedForDisplay = $limit !== null && $limit > 0 ? $completed->take($limit)->values() : $completed;
        $this->newLine();
        $this->warn('Completed multibrand detectados (no modificados): '.$completed->count());
        if ($completedForDisplay->isNotEmpty()) {
            $this->table(
                ['cart_id', 'customer_id', 'total', 'brands'],
                $completedForDisplay->map(fn ($cart) => [
                    $cart->id,
                    $cart->user?->customer?->id,
                    (float) $cart->total,
                    collect($cart->labBrands())->pluck('value')->implode(', '),
                ])->all(),
            );
        }

        $this->newLine();
        $this->info('Resumen');
        $this->line('Analizados: '.$summary['analizados']);
        $this->line('Reconciliados: '.$summary['reconciliados']);
        $this->line('Sin cambios: '.$summary['sin_cambios']);
        $this->line('Conflictos: '.$summary['conflictos']);
        $this->line('Errores: '.$summary['errores']);
        $this->line('Completed multibrand no modificados: '.$completed->count());

        if (! $apply) {
            $this->comment('Dry run: no se modifico la base de datos. Usa --apply para ejecutar.');
        }

        return $summary['errores'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    private function optionInt(string $name): ?int
    {
        $value = $this->option($name);

        return $value === null || $value === '' ? null : max(1, (int) $value);
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function printPlan(array $plan): void
    {
        $this->line(str_repeat('-', 72));
        $this->line('cart_id: '.$plan['cart_id']);
        $this->line('customer_id: '.($plan['customer_id'] ?? 'sin customer'));
        $this->line('status: '.$plan['status']);
        $this->line('total actual: '.$plan['total']);
        $this->line('brands detectadas: '.implode(', ', $plan['brands'] ?? []));
        $this->line('brand que conservaria ID: '.($plan['brand_to_keep'] ?? 'N/A'));
        $this->line('resultado esperado: '.($plan['expected_result'] ?? 'N/A'));
        $this->line('resultado: '.($plan['result'] ?? 'dry_run'));

        $this->printBrandSummary('items por brand (cart_items)', $plan['items_by_brand'] ?? []);
        $this->printBrandSummary('items por brand (laboratory_cart_items activos)', $plan['source_items_by_brand'] ?? []);
        $this->printRelations($plan['explicit_relations'] ?? []);

        if (! empty($plan['target_carts'])) {
            $this->line('carts adicionales a crear/reutilizar:');
            foreach ($plan['target_carts'] as $brand => $targetCartId) {
                $this->line('  '.$brand.' => '.($targetCartId ?: 'crear nuevo'));
            }
        }

        if (! empty($plan['risks'])) {
            $this->warn('riesgos/conflictos: '.implode('; ', $plan['risks']));
        }

        if (! empty($plan['after_carts'])) {
            $this->table(['cart_id', 'brands', 'total'], collect($plan['after_carts'])->map(fn ($row) => [
                $row['cart_id'],
                implode(', ', $row['brands'] ?? []),
                $row['total'],
            ])->all());
        }

        if (! empty($plan['error'])) {
            $this->error('error: '.$plan['error']);
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $summary
     */
    private function printBrandSummary(string $title, array $summary): void
    {
        $this->line($title.':');
        foreach ($summary as $brand => $data) {
            $this->line(sprintf(
                '  %s: items=%s subtotal=%s product_ids=%s',
                $brand,
                $data['items'] ?? 0,
                $data['subtotal'] ?? 0,
                implode(',', $data['product_ids'] ?? []),
            ));
        }
    }

    /**
     * @param  array<string, list<mixed>>  $relations
     */
    private function printRelations(array $relations): void
    {
        $this->line('relaciones explicitas:');
        foreach (['payment_attempts', 'purchases', 'appointments', 'cart_events'] as $key) {
            $rows = $relations[$key] ?? [];
            $this->line('  '.$key.': '.count($rows));
            foreach ($rows as $row) {
                $this->line('    '.json_encode($row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            }
        }
    }
}
