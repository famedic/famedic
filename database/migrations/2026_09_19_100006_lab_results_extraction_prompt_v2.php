<?php

use App\Services\LaboratoryResults\Extraction\LaboratoryResultVisionPromptDefinition;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('ai_prompts')) {
            return;
        }

        $exists = DB::table('ai_prompts')
            ->where('key', LaboratoryResultVisionPromptDefinition::KEY)
            ->where('version', LaboratoryResultVisionPromptDefinition::VERSION_V2)
            ->exists();

        if ($exists) {
            return;
        }

        DB::table('ai_prompts')
            ->where('key', LaboratoryResultVisionPromptDefinition::KEY)
            ->where('version', LaboratoryResultVisionPromptDefinition::VERSION_V1)
            ->update([
                'status' => 'archived',
                'updated_at' => now(),
            ]);

        $record = LaboratoryResultVisionPromptDefinition::record(LaboratoryResultVisionPromptDefinition::VERSION_V2);

        DB::table('ai_prompts')->insert([
            'key' => $record['key'],
            'domain' => $record['domain'],
            'version' => $record['version'],
            'status' => $record['status'],
            'model' => $record['model'],
            'system_prompt' => $record['system_prompt'],
            'user_prompt' => $record['user_prompt'],
            'response_schema' => json_encode($record['response_schema'], JSON_UNESCAPED_UNICODE),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        if (! DB::getSchemaBuilder()->hasTable('ai_prompts')) {
            return;
        }

        DB::table('ai_prompts')
            ->where('key', LaboratoryResultVisionPromptDefinition::KEY)
            ->where('version', LaboratoryResultVisionPromptDefinition::VERSION_V2)
            ->delete();

        DB::table('ai_prompts')
            ->where('key', LaboratoryResultVisionPromptDefinition::KEY)
            ->where('version', LaboratoryResultVisionPromptDefinition::VERSION_V1)
            ->update([
                'status' => 'active',
                'updated_at' => now(),
            ]);
    }
};
