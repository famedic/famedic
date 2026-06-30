<?php

namespace App\Console\Commands;

use App\Services\ActiveCampaign\ActiveCampaignService;
use Illuminate\Console\Command;

class ListActiveCampaignFamedicFieldsCommand extends Command
{
    protected $signature = 'activecampaign:list-famedic-fields
                            {--prefix=FM_ : Filtrar por título o perstag con este prefijo}
                            {--all : Mostrar todos los custom fields sin filtrar}
                            {--dotenv : Imprimir bloque listo para copiar al .env}';

    protected $description = 'Lista custom fields de ActiveCampaign (Famedic) con IDs reales para configurar .env (solo lectura)';

    /** @var list<string> */
    private const EXPECTED_FM_TITLES = [
        'FM_USER_ID',
        'FM_CUSTOMER_ID',
        'FM_CREDITO_ESTADO',
        'FM_CREDITO_MONTO',
        'FM_CREDITO_RESTANTE',
        'FM_CREDITO_EXPIRA_AT',
        'FM_CREDITO_COMPRA_MINIMA',
        'FM_CREDITO_CAMPANIA',
        'FM_CREDITO_TIPO',
        'FM_CREDITO_ULTIMO_USO_AT',
        'FM_SALDO_TOTAL',
        'FM_SALDO_APLICABLE',
        'FM_SALDO_CONDICIONADO',
        'FM_PROMO_ULTIMO_CODIGO',
        'FM_PROMO_ESTADO',
        'FM_ULTIMA_COMPRA_LAB_AT',
    ];

    public function handle(ActiveCampaignService $service): int
    {
        try {
            $fields = $service->getCustomFields();
        } catch (\Throwable $e) {
            $this->error('No se pudo inicializar el cliente de ActiveCampaign.');
            $this->line('Verifica ACTIVE_CAMPAIGN_API_ENDPOINT y ACTIVE_CAMPAIGN_API_TOKEN en .env.');
            $this->line('Detalle: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($fields === []) {
            $this->warn('No se obtuvieron campos (respuesta vacía o error de API). Revisa credenciales y conectividad.');

            return self::FAILURE;
        }

        $showAll = (bool) $this->option('all');
        $prefix = (string) $this->option('prefix');
        $filtered = $showAll ? $fields : $this->filterFields($fields, $prefix);

        usort(
            $filtered,
            fn (array $a, array $b): int => strcasecmp((string) ($a['title'] ?? ''), (string) ($b['title'] ?? ''))
        );

        $this->info(sprintf(
            'Custom fields: %d mostrados / %d totales en ActiveCampaign%s',
            count($filtered),
            count($fields),
            $showAll ? ' (sin filtro)' : sprintf(' (prefijo %s)', $prefix)
        ));

        $this->newLine();

        $rows = array_map(fn (array $field): array => $this->mapFieldRow($field), $filtered);

        $this->table(
            ['ID', 'Title', 'Type', 'Perstag', 'Group ID', 'Variable .env'],
            $rows
        );

        if (! $showAll) {
            $this->reportMissingExpectedFields($filtered);
        }

        $this->printCompactSummary($filtered);

        if ($this->option('dotenv')) {
            $this->printEnvBlock($filtered);
        }

        return self::SUCCESS;
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     * @return list<array<string, mixed>>
     */
    private function filterFields(array $fields, string $prefix): array
    {
        $prefixUpper = strtoupper(trim($prefix));

        return array_values(array_filter($fields, function (array $field) use ($prefixUpper): bool {
            $title = strtoupper(trim((string) ($field['title'] ?? '')));
            $perstag = $this->normalizePersTag((string) ($field['perstag'] ?? ''));

            if ($prefixUpper === '') {
                return true;
            }

            return str_starts_with($title, $prefixUpper)
                || str_starts_with($perstag, $prefixUpper)
                || ($prefixUpper === 'FM_' && str_starts_with($perstag, 'FM'));
        }));
    }

    /**
     * @param  array<string, mixed>  $field
     * @return list<int|string>
     */
    private function mapFieldRow(array $field): array
    {
        $id = (string) ($field['id'] ?? '');
        $title = (string) ($field['title'] ?? '');
        $type = (string) ($field['type'] ?? '');
        $perstag = (string) ($field['perstag'] ?? '');
        $groupId = $field['relid'] ?? $field['groupid'] ?? $field['group'] ?? '—';
        $envKey = $this->suggestEnvKey($field);

        return [$id, $title, $type, $perstag, (string) $groupId, "{$envKey}={$id}"];
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     */
    private function reportMissingExpectedFields(array $fields): void
    {
        $foundTitles = array_map(
            fn (array $field): string => strtoupper(trim((string) ($field['title'] ?? ''))),
            $fields
        );

        $missing = array_values(array_diff(self::EXPECTED_FM_TITLES, $foundTitles));

        if ($missing === []) {
            return;
        }

        $this->newLine();
        $this->warn('Campos Famedic esperados no encontrados con el filtro actual:');

        foreach ($missing as $title) {
            $this->line("  - {$title}");
        }
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     */
    private function printCompactSummary(array $fields): void
    {
        if ($fields === []) {
            return;
        }

        $this->newLine();
        $this->info('Resumen compacto:');

        foreach ($fields as $field) {
            $slug = $this->fieldSlug($field);
            $id = (string) ($field['id'] ?? '');
            $envKey = $this->suggestEnvKey($field);

            $this->line("{$slug} | id: {$id} | env: {$envKey}={$id}");
        }
    }

    /**
     * @param  list<array<string, mixed>>  $fields
     */
    private function printEnvBlock(array $fields): void
    {
        if ($fields === []) {
            return;
        }

        $this->newLine();
        $this->info('Bloque sugerido para .env:');

        foreach ($fields as $field) {
            $envKey = $this->suggestEnvKey($field);
            $id = (string) ($field['id'] ?? '');

            $this->line("{$envKey}={$id}");
        }
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private function suggestEnvKey(array $field): string
    {
        return 'ACTIVECAMPAIGN_FIELD_'.$this->fieldSlug($field);
    }

    /**
     * @param  array<string, mixed>  $field
     */
    private function fieldSlug(array $field): string
    {
        $perstag = $this->normalizePersTag((string) ($field['perstag'] ?? ''));
        $title = strtoupper(preg_replace('/\s+/', '_', trim((string) ($field['title'] ?? ''))) ?? '');

        if ($perstag !== '' && (str_starts_with($perstag, 'FM_') || str_starts_with($perstag, 'FM'))) {
            return $perstag;
        }

        return $title;
    }

    private function normalizePersTag(string $perstag): string
    {
        return strtoupper(str_replace('%', '', trim($perstag)));
    }
}
