<?php

namespace App\Console\Commands;

use App\Services\ActiveCampaign\ActiveCampaignService;
use Illuminate\Console\Command;

class FindActiveCampaignTagCommand extends Command
{
    protected $signature = 'activecampaign:find-tag {name : Nombre o fragmento del tag a buscar}';

    protected $description = 'Busca tags de ActiveCampaign por nombre usando la integracion existente (solo lectura).';

    public function handle(ActiveCampaignService $activeCampaign): int
    {
        $query = trim((string) $this->argument('name'));

        try {
            $tags = $activeCampaign->getTags();
        } catch (\Throwable $e) {
            $this->error('No se pudieron consultar tags de ActiveCampaign.');
            $this->line('Verifica endpoint, token, permisos y conectividad.');

            return 2;
        }

        $normalizedQuery = $this->normalize($query);
        $exact = null;
        $partial = [];

        foreach ($tags as $tag) {
            $name = $this->tagName($tag);

            if ($name === '') {
                continue;
            }

            $normalizedName = $this->normalize($name);

            if ($normalizedName === $normalizedQuery) {
                $exact = $tag;
                break;
            }

            if ($normalizedQuery !== '' && str_contains($normalizedName, $normalizedQuery)) {
                $partial[] = $tag;
            }
        }

        if ($exact !== null) {
            $this->line('ID: '.$this->tagId($exact));
            $this->line('Tag: '.$this->tagName($exact));

            return 0;
        }

        if ($partial !== []) {
            $this->line('Coincidencias:');

            foreach ($partial as $tag) {
                $this->line($this->tagId($tag).'  '.$this->tagName($tag));
            }

            return 0;
        }

        $this->line('No se encontraron tags para: '.$query);

        return 1;
    }

    /**
     * @param  array<string, mixed>  $tag
     */
    private function tagName(array $tag): string
    {
        return trim((string) ($tag['tag'] ?? $tag['name'] ?? ''));
    }

    /**
     * @param  array<string, mixed>  $tag
     */
    private function tagId(array $tag): string
    {
        return (string) ($tag['id'] ?? '-');
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/', ' ', $value) ?? ''));
    }
}
