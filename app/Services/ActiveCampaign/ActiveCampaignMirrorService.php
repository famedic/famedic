<?php

namespace App\Services\ActiveCampaign;

use App\DataTransferObjects\ActiveCampaign\ActiveCampaignContactSnapshot;
use App\Models\Customer;
use Illuminate\Support\Facades\Log;

/**
 * Orquesta lecturas ActiveCampaign para producir un snapshot reutilizable.
 *
 * Uso:
 *   $snapshot = app(ActiveCampaignMirrorService::class)->snapshot($customer);
 */
class ActiveCampaignMirrorService
{
    public function __construct(
        protected ActiveCampaignService $activeCampaign,
        protected ActiveCampaignReadService $read,
        protected ActiveCampaignCacheService $cache,
        protected ActiveCampaignLocationMapper $locationMapper,
    ) {}

    /**
     * Snapshot completo del contacto AC asociado al Customer Famedic.
     * Cualquier módulo (Customer 360, Hub, Journey, Drawer) puede consumirlo
     * sin conocer la API de ActiveCampaign.
     */
    public function snapshot(Customer $customer, bool $forceRefresh = false): ?ActiveCampaignContactSnapshot
    {
        if (! $this->activeCampaign->enabled()) {
            return null;
        }

        if (! $forceRefresh) {
            $cached = $this->cache->getSnapshot((int) $customer->id);
            if ($cached instanceof ActiveCampaignContactSnapshot) {
                return $cached->withFromCache(true);
            }
        }

        $acContactId = $this->resolveAcContactId($customer);
        if ($acContactId === null) {
            Log::debug('AC Mirror: contacto no encontrado en ActiveCampaign', [
                'customer_id' => $customer->id,
            ]);

            return null;
        }

        $contactData = null;
        $snapshot = $this->fetchSnapshot($acContactId, (int) $customer->id, $contactData);
        if ($snapshot === null) {
            return null;
        }

        $this->persistMirrorPointers($customer, $acContactId, $contactData);
        $this->cache->putSnapshot((int) $customer->id, $snapshot);

        return $snapshot;
    }

    /**
     * Snapshot directo por ID de contacto ActiveCampaign (sin Customer).
     */
    public function snapshotByAcContactId(int $acContactId, ?int $customerId = null, bool $forceRefresh = false): ?ActiveCampaignContactSnapshot
    {
        if (! $this->activeCampaign->enabled() || $acContactId <= 0) {
            return null;
        }

        if ($customerId !== null && ! $forceRefresh) {
            $cached = $this->cache->getSnapshot($customerId);
            if ($cached instanceof ActiveCampaignContactSnapshot && $cached->acContactId === $acContactId) {
                return $cached->withFromCache(true);
            }
        }

        $contactData = null;
        $snapshot = $this->fetchSnapshot($acContactId, $customerId, $contactData);
        if ($snapshot === null) {
            return null;
        }

        if ($customerId !== null) {
            $customer = Customer::query()->find($customerId);
            if ($customer) {
                $this->persistMirrorPointers($customer, $acContactId, $contactData);
            }

            $this->cache->putSnapshot($customerId, $snapshot);
        }

        return $snapshot;
    }

    public function forget(Customer $customer): void
    {
        $this->cache->forgetSnapshot((int) $customer->id);

        $acContactId = (int) ($customer->ac_contact_id ?? 0);
        if ($acContactId > 0) {
            $this->cache->forgetContact($acContactId);
        }
    }

    /**
     * @param  array<string, mixed>|null  $contactData
     */
    protected function fetchSnapshot(int $acContactId, ?int $customerId, ?array &$contactData = null): ?ActiveCampaignContactSnapshot
    {
        // getContact primero: AC genera activities al recuperar el contacto.
        $contact = $this->activeCampaign->getContact($acContactId);
        if ($contact === null) {
            return null;
        }

        $contactData = $this->activeCampaign->getContactData($acContactId);
        $tags = $this->activeCampaign->getContactTags($acContactId);
        $lists = $this->activeCampaign->getContactLists($acContactId);
        $fields = $this->activeCampaign->getContactFieldValues($acContactId);
        $automations = $this->activeCampaign->getContactAutomations($acContactId);
        $scores = $this->activeCampaign->getContactScoreValues($acContactId);
        $activitiesPayload = $this->activeCampaign->getContactActivities($acContactId);

        $ownerUser = null;
        $ownerId = (int) ($contact['owner'] ?? $contact['userid'] ?? $contact['created_by'] ?? 0);
        if ($ownerId > 0) {
            try {
                $ownerUser = $this->activeCampaign->getUser($ownerId);
            } catch (\Throwable) {
                $ownerUser = null;
            }
        }

        return $this->read->buildSnapshot(
            contact: $contact,
            contactData: $contactData,
            contactTags: $tags,
            contactLists: $lists,
            fieldValues: $fields,
            contactAutomations: $automations,
            activities: $activitiesPayload['activities'] ?? [],
            scoreValues: $scores,
            customerId: $customerId,
            ownerUser: $ownerUser,
        );
    }

    /**
     * Invalidar caché del espejo (llamar desde webhooks inbound cuando existan).
     */
    public function invalidate(Customer $customer): void
    {
        $this->forget($customer);
    }

    protected function resolveAcContactId(Customer $customer): ?int
    {
        $stored = (int) ($customer->ac_contact_id ?? 0);
        if ($stored > 0) {
            return $stored;
        }

        $email = $this->resolveEmail($customer);
        if ($email === null) {
            return null;
        }

        $result = $this->activeCampaign->getContactIdByEmailPublic($email);

        if (! $result->success || ! $result->contactId) {
            return null;
        }

        return $result->contactId > 0 ? $result->contactId : null;
    }

    protected function resolveEmail(Customer $customer): ?string
    {
        $customer->loadMissing('user');

        $email = trim((string) ($customer->user?->email ?? ''));

        return $email !== '' ? $email : null;
    }

    /**
     * @param  array<string, mixed>|null  $contactData
     */
    protected function persistMirrorPointers(Customer $customer, int $acContactId, ?array $contactData = null): void
    {
        $dirty = false;

        if ((int) ($customer->ac_contact_id ?? 0) !== $acContactId) {
            $customer->ac_contact_id = $acContactId;
            $dirty = true;
        }

        $customer->ac_last_sync_at = now();
        $dirty = true;

        if ($contactData !== null) {
            $location = $this->locationMapper->fromContactData($contactData);
            $customer->ac_location = $location;
            $customer->ac_location_cached_at = $location !== null ? now() : null;
            $dirty = true;
        }

        if ($dirty) {
            $customer->save();
        }
    }
}
