<?php

namespace App\Console\Commands;

use App\Services\ActiveCampaign\LaboratoryActiveCampaignDiagnostics;
use Illuminate\Console\Command;

class VerifyActiveCampaignLabFieldsCommand extends Command
{
    protected $signature = 'activecampaign:verify-lab-fields
                            {--json : Emitir JSON consumible por scripts/CI}
                            {--tags : Incluir verificacion read-only de tags de laboratorio/carrito}';

    protected $description = 'Verifica custom fields y tags de laboratorio en ActiveCampaign contra config local (solo lectura).';

    public function handle(LaboratoryActiveCampaignDiagnostics $diagnostics): int
    {
        $fields = $diagnostics->verifyFields();
        $tags = $this->option('tags') ? $diagnostics->verifyTags() : null;
        $exitCode = max($fields['exit_code'], $tags['exit_code'] ?? LaboratoryActiveCampaignDiagnostics::EXIT_OK);

        if ($this->option('json')) {
            $this->line(json_encode(array_filter([
                'ok' => ($fields['ok'] ?? 0) + ($tags['ok'] ?? 0),
                'warnings' => ($fields['warnings'] ?? 0) + ($tags['warnings'] ?? 0),
                'errors' => ($fields['errors'] ?? 0) + ($tags['errors'] ?? 0),
                'api_error' => ($fields['api_error'] ?? false) || ($tags['api_error'] ?? false),
                'http_status' => $fields['http_status'] ?? $tags['http_status'] ?? null,
                'error' => $fields['error'] ?? $tags['error'] ?? null,
                'fields' => $fields['fields'] ?? [],
                'tags' => $tags['tags'] ?? null,
            ], static fn ($value) => $value !== null), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return $exitCode;
        }

        if ($fields['api_error']) {
            $this->error($fields['error'] ?? 'ActiveCampaign fields API_ERROR');

            return $exitCode;
        }

        $this->info('ActiveCampaign lab custom fields');
        $this->table(
            ['KEY', 'ENV/CONFIG ID', 'AC FIELD ID', 'AC FIELD TITLE', 'TYPE', 'PERSONALIZATION TAG', 'STATUS'],
            collect($fields['fields'])->map(fn (array $row): array => [
                $row['key'],
                $row['configured_id'] ?? 'null',
                $row['ac_field_id'] ?? '-',
                $row['ac_title'] ?? '-',
                $row['type'] ?? '-',
                $row['personalization_tag'] ?? '-',
                $row['status'],
            ])->all()
        );

        $this->line(sprintf(
            'Summary fields: OK=%d warnings=%d errors=%d',
            $fields['ok'],
            $fields['warnings'],
            $fields['errors'],
        ));

        if ($tags !== null) {
            if ($tags['api_error']) {
                $this->newLine();
                $this->error($tags['error'] ?? 'ActiveCampaign tags API_ERROR');

                return $exitCode;
            }

            $this->newLine();
            $this->info('ActiveCampaign lab/cart tags');
            $this->table(
                ['CONFIG KEY', 'CONFIG VALUE', 'TAG ID', 'TAG NAME', 'STATUS'],
                collect($tags['tags'])->map(fn (array $row): array => [
                    $row['config'],
                    $row['configured'] ?? 'null',
                    $row['tag_id'] ?? '-',
                    $row['tag_name'] ?? '-',
                    $row['status'],
                ])->all()
            );

            $this->line(sprintf(
                'Summary tags: OK=%d warnings=%d errors=%d',
                $tags['ok'],
                $tags['warnings'],
                $tags['errors'],
            ));
        }

        return $exitCode;
    }
}
