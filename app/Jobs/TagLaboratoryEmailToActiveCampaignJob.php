<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\ActiveCampaign\ActiveCampaignService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class TagLaboratoryEmailToActiveCampaignJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public string $email;
    /**
     * Compatibilidad hacia atrás:
     * - Jobs antiguos guardaban "tagName" (string).
     * - Jobs nuevos guardan "tagId" (int).
     */
    public ?int $tagId = null;
    public ?string $tagName = null;

    /**
     * Reintentos y backoff para tolerancia a fallos.
     */
    public int $tries = 5;
    public int $backoff = 60;

    public function __construct(string $email, int|string $tagNameOrId)
    {
        $this->email = $email;
        $this->tagId = is_numeric($tagNameOrId) ? (int) $tagNameOrId : null;
        $this->tagName = is_numeric($tagNameOrId) ? null : (string) $tagNameOrId;
    }

    public function handle(ActiveCampaignService $activeCampaignService): void
    {
        try {
            $effectiveTagId = $this->tagId;

            if (! $effectiveTagId && $this->tagName) {
                $effectiveTagId = match ($this->tagName) {
                    'Lab Toma de muestra', 'Lab Toma De muestra' => (int) config('services.activecampaign.tag_lab_sample_collected', 32),
                    'Lab Resultado Disponible' => (int) config('services.activecampaign.tag_lab_results_available', 33),
                    default => null,
                };
            }

            // Fallback final: evita romper si llega un job mal serializado
            $effectiveTagId = $effectiveTagId ?: 0;

            Log::info('AC Job: TagLaboratoryEmailToActiveCampaignJob iniciado', [
                'email' => $this->email,
                'tag_id_intentado' => $effectiveTagId,
                'tag_name' => $this->tagName,
            ]);

            if ($effectiveTagId <= 0) {
                Log::warning('AC Job: tagId inválido, omitiendo etiquetado', [
                    'email' => $this->email,
                    'tag_id' => $effectiveTagId,
                    'tag_name' => $this->tagName,
                ]);
                return;
            }

            $user = User::query()->where('email', $this->email)->first();

            if (! $user) {
                Log::warning('AC Job: no hay user para email; omitiendo sync/tag', [
                    'email' => $this->email,
                ]);
                return;
            }

            // contact/sync en AC crea o actualiza y devuelve el contactId.
            $contactId = $activeCampaignService->syncContact([
                'email' => $user->email,
                'first_name' => $user->name ?? '',
                'paternal_lastname' => $user->paternal_lastname ?? '',
                'maternal_lastname' => $user->maternal_lastname ?? '',
                'phone' => $user->phone ?? '',
                'gender' => $user->formatted_gender ?? ($user->gender?->value ?? ''),
                'birth_date' => $user->birth_date?->format('Y-m-d') ?? '',
                'phone_country' => $user->phone_country ?? '',
                'state' => $user->state ?? '',
            ]);

            if (! $contactId) {
                Log::warning('AC Job: no se pudo crear/sincronizar contacto en AC', [
                    'email' => $this->email,
                ]);
                return;
            }

            // Siempre intentar RegistroNuevo (id=3). Usar fallback por si la config no está cargada.
            $registroNuevoTagRaw = config('services.activecampaign.tag_registro_nuevo', 3);
            $registroNuevoTagId = is_numeric($registroNuevoTagRaw)
                ? (int) $registroNuevoTagRaw
                : 3;

            if ($registroNuevoTagId <= 0) {
                $registroNuevoTagId = 3;
            }
            Log::info('AC Job: aplicando RegistroNuevo', [
                'email' => $this->email,
                'contact_id' => $contactId,
                'registro_nuevo_tag_id' => $registroNuevoTagId,
            ]);
            if ($registroNuevoTagId > 0) {
                $activeCampaignService->addTagToContact($contactId, $registroNuevoTagId);
            } else {
                Log::warning('AC Job: registroNuevoTagId inválido, omitiendo', [
                    'email' => $this->email,
                    'registro_nuevo_tag_id' => $registroNuevoTagId,
                ]);
            }

            $activeCampaignService->addTagToContact($contactId, $effectiveTagId);

            Log::info('AC Job: TagLaboratoryEmailToActiveCampaignJob completado', [
                'email' => $this->email,
                'tag_id' => $effectiveTagId,
                'contact_id' => $contactId,
            ]);
        } catch (\Throwable $e) {
            Log::error('AC Job: error etiquetando en ActiveCampaign', [
                'email' => $this->email,
                'tag_id' => $this->tagId,
                'error' => $e->getMessage(),
            ]);

            // Lanzar para que la cola reintente (no rompe flujo del request/email)
            throw $e;
        }
    }
}

