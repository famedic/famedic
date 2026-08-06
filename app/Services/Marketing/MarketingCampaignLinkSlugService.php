<?php

namespace App\Services\Marketing;

use App\Models\MarketingCampaignLink;
use App\Models\MarketingCampaignLinkAlias;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class MarketingCampaignLinkSlugService
{
    /**
     * Slugs principales (incl. soft-deleted) y aliases históricos quedan reservados.
     * Hard delete no se expone por policy/action.
     *
     * Riesgo residual concurrente: la comprobación cruza dos tablas sin un lock
     * compartido; Create/Update envuelven validación+escritura en transacción y
     * los unique indexes actúan como última línea de defensa.
     */
    public function normalize(string $slug): string
    {
        return Str::slug(Str::lower(trim($slug)));
    }

    public function assertAvailable(string $slug, ?MarketingCampaignLink $except = null): string
    {
        $normalized = $this->normalize($slug);

        if ($normalized === '') {
            throw ValidationException::withMessages([
                'slug' => 'El slug debe contener al menos una letra o un número.',
            ]);
        }

        if (mb_strlen($normalized) > 180) {
            throw ValidationException::withMessages([
                'slug' => 'El slug no puede exceder 180 caracteres.',
            ]);
        }

        $linkCollision = MarketingCampaignLink::query()
            ->withTrashed()
            ->where('slug', $normalized)
            ->when($except, fn ($query) => $query->whereKeyNot($except->getKey()))
            ->exists();

        $aliasCollision = MarketingCampaignLinkAlias::query()
            ->where('slug', $normalized)
            ->exists();

        if ($linkCollision || $aliasCollision) {
            throw ValidationException::withMessages([
                'slug' => 'El slug ya está reservado por un enlace o alias histórico.',
            ]);
        }

        return $normalized;
    }

    public function changeSlug(MarketingCampaignLink $link, string $slug): MarketingCampaignLink
    {
        $normalized = $this->normalize($slug);

        if ($normalized === $link->slug) {
            return $link;
        }

        return DB::transaction(function () use ($link, $normalized) {
            $lockedLink = MarketingCampaignLink::query()
                ->withTrashed()
                ->lockForUpdate()
                ->findOrFail($link->getKey());

            if ($normalized === $lockedLink->slug) {
                return $lockedLink;
            }

            $availableSlug = $this->assertAvailable($normalized, $lockedLink);
            $previousSlug = $lockedLink->slug;

            MarketingCampaignLinkAlias::query()->firstOrCreate([
                'marketing_campaign_link_id' => $lockedLink->id,
                'slug' => $previousSlug,
            ]);

            $lockedLink->update(['slug' => $availableSlug]);

            return $lockedLink->refresh();
        });
    }
}
