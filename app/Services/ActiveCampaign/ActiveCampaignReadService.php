<?php

namespace App\Services\ActiveCampaign;

use App\DataTransferObjects\ActiveCampaign\ActiveCampaignContactSnapshot;
use App\DataTransferObjects\ActiveCampaign\ContactActivityData;
use App\DataTransferObjects\ActiveCampaign\ContactAutomationData;
use App\DataTransferObjects\ActiveCampaign\ContactEngagementData;
use App\DataTransferObjects\ActiveCampaign\ContactFieldData;
use App\DataTransferObjects\ActiveCampaign\ContactLeadScoreSummary;
use App\DataTransferObjects\ActiveCampaign\ContactListData;
use App\DataTransferObjects\ActiveCampaign\ContactOwnerData;
use App\DataTransferObjects\ActiveCampaign\ContactScoreData;
use App\DataTransferObjects\ActiveCampaign\ContactTagData;
use Illuminate\Support\Carbon;

/**
 * Transforma respuestas crudas de la API ActiveCampaign en DTOs tipados.
 * No realiza HTTP de contacto: consume arrays ya obtenidos por ActiveCampaignService
 * (salvo catálogos cacheados: tags, fields, lists, automations, scores, users).
 */
class ActiveCampaignReadService
{
    /**
     * Títulos / perstags visibles en el espejo (case-insensitive, parcial).
     *
     * @var list<string>
     */
    public const RELEVANT_FIELD_KEYWORDS = [
        'empresa',
        'socio',
        'ciudad',
        'rfc',
        'fecha primer laboratorio',
        'primer laboratorio',
        'fecha última compra',
        'fecha ultima compra',
        'última compra',
        'ultima compra',
        'membresía',
        'membresia',
        'fuente',
        'canal',
        'referido',
    ];

    /**
     * Prefijos / patrones a ocultar (campos internos FM / cupones / sync).
     *
     * @var list<string>
     */
    public const INTERNAL_FIELD_PATTERNS = [
        'fm_',
        'fm-',
        '%fm_',
        'crédito',
        'credito',
        'promo',
        'coupon',
        'cupon',
        'saldo',
        'idempotency',
        'sync',
    ];

    public function __construct(
        protected ActiveCampaignService $activeCampaign,
        protected ActiveCampaignCacheService $cache,
    ) {}

    /**
     * @param  array<string, mixed>  $contact
     * @param  array<string, mixed>|null  $contactData
     * @param  list<array<string, mixed>>  $contactTags
     * @param  list<array<string, mixed>>  $contactLists
     * @param  list<array<string, mixed>>  $fieldValues
     * @param  list<array<string, mixed>>  $contactAutomations
     * @param  list<array<string, mixed>>  $activities
     * @param  list<array<string, mixed>>  $scoreValues
     * @param  array<string, mixed>|null  $ownerUser  Usuario AC ya resuelto (opcional)
     */
    public function buildSnapshot(
        array $contact,
        ?array $contactData,
        array $contactTags,
        array $contactLists,
        array $fieldValues,
        array $contactAutomations,
        array $activities,
        array $scoreValues,
        ?int $customerId = null,
        ?array $ownerUser = null,
    ): ActiveCampaignContactSnapshot {
        $mappedActivities = $this->mapActivities($activities);
        $lastActivity = $mappedActivities[0] ?? null;
        $mappedFields = $this->mapFields($fieldValues);
        $mappedScores = $this->mapScores($scoreValues);
        $leadScore = ContactLeadScoreSummary::fromScores($mappedScores);

        return new ActiveCampaignContactSnapshot(
            acContactId: (int) ($contact['id'] ?? 0),
            customerId: $customerId,
            email: $this->nullableString($contact['email'] ?? null),
            firstName: $this->nullableString($contact['firstName'] ?? null),
            lastName: $this->nullableString($contact['lastName'] ?? null),
            phone: $this->nullableString($contact['phone'] ?? null),
            createdAt: $this->nullableString($contact['cdate'] ?? null),
            updatedAt: $this->nullableString($contact['udate'] ?? null),
            location: $this->mapLocation($contactData),
            tags: $this->mapTags($contactTags),
            lists: $this->mapLists($contactLists),
            fields: $mappedFields,
            automations: $this->mapAutomations($contactAutomations),
            activities: $mappedActivities,
            scores: $mappedScores,
            lastActivity: $lastActivity,
            mirroredAt: Carbon::now(),
            fromCache: false,
            relevantFields: $this->filterRelevantFields($mappedFields),
            owner: $this->mapOwner($contact, $ownerUser),
            engagement: $this->mapEngagement($contact, $mappedActivities),
            leadScore: $leadScore,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $contactTags
     * @return list<ContactTagData>
     */
    public function mapTags(array $contactTags): array
    {
        $catalog = $this->tagCatalogById();

        $mapped = [];
        foreach ($contactTags as $row) {
            $tagId = (int) ($row['tag'] ?? 0);
            if ($tagId <= 0) {
                continue;
            }

            $mapped[] = new ContactTagData(
                contactTagId: (int) ($row['id'] ?? 0),
                tagId: $tagId,
                name: $this->nullableString($catalog[$tagId]['tag'] ?? $catalog[$tagId]['name'] ?? null),
                cdate: $this->nullableString($row['cdate'] ?? null),
            );
        }

        return $mapped;
    }

    /**
     * @param  list<array<string, mixed>>  $contactLists
     * @return list<ContactListData>
     */
    public function mapLists(array $contactLists): array
    {
        $catalog = $this->listCatalogById();

        $mapped = [];
        foreach ($contactLists as $row) {
            $listId = (int) ($row['list'] ?? 0);
            if ($listId <= 0) {
                continue;
            }

            $mapped[] = new ContactListData(
                contactListId: (int) ($row['id'] ?? 0),
                listId: $listId,
                name: $this->nullableString($catalog[$listId]['name'] ?? null),
                status: $this->listStatusLabel($row['status'] ?? null),
                sdate: $this->nullableString($row['sdate'] ?? null),
                udate: $this->nullableString($row['udate'] ?? null),
            );
        }

        return $mapped;
    }

    /**
     * @param  list<array<string, mixed>>  $fieldValues
     * @return list<ContactFieldData>
     */
    public function mapFields(array $fieldValues): array
    {
        $catalog = $this->fieldCatalogById();

        $mapped = [];
        foreach ($fieldValues as $row) {
            $fieldId = (int) ($row['field'] ?? 0);
            if ($fieldId <= 0) {
                continue;
            }

            $meta = $catalog[$fieldId] ?? [];

            $mapped[] = new ContactFieldData(
                fieldValueId: (int) ($row['id'] ?? 0),
                fieldId: $fieldId,
                title: $this->nullableString($meta['title'] ?? null),
                perstag: $this->nullableString($meta['perstag'] ?? null),
                type: $this->nullableString($meta['type'] ?? null),
                value: isset($row['value']) ? (is_scalar($row['value']) ? (string) $row['value'] : null) : null,
                cdate: $this->nullableString($row['cdate'] ?? null),
                udate: $this->nullableString($row['udate'] ?? null),
            );
        }

        return $mapped;
    }

    /**
     * @param  list<ContactFieldData>  $fields
     * @return list<ContactFieldData>
     */
    public function filterRelevantFields(array $fields): array
    {
        $relevant = [];
        foreach ($fields as $field) {
            if ($this->isInternalField($field)) {
                continue;
            }
            if (! $this->isRelevantField($field)) {
                continue;
            }
            $relevant[] = $field;
        }

        usort($relevant, static fn (ContactFieldData $a, ContactFieldData $b) => strcasecmp(
            (string) ($a->title ?? ''),
            (string) ($b->title ?? '')
        ));

        return $relevant;
    }

    /**
     * @param  list<array<string, mixed>>  $contactAutomations
     * @return list<ContactAutomationData>
     */
    public function mapAutomations(array $contactAutomations): array
    {
        $catalog = $this->automationCatalogById();

        $mapped = [];
        foreach ($contactAutomations as $row) {
            $automationId = (int) ($row['automation'] ?? $row['seriesid'] ?? 0);
            if ($automationId <= 0) {
                continue;
            }

            $mapped[] = new ContactAutomationData(
                contactAutomationId: (int) ($row['id'] ?? 0),
                automationId: $automationId,
                name: $this->nullableString($catalog[$automationId]['name'] ?? null),
                status: $this->nullableString(isset($row['status']) ? (string) $row['status'] : null),
                addDate: $this->nullableString($row['adddate'] ?? null),
                lastDate: $this->nullableString($row['lastdate'] ?? null),
                completedElements: isset($row['completedElements']) ? (int) $row['completedElements'] : null,
                totalElements: isset($row['totalElements']) ? (int) $row['totalElements'] : null,
                completeValue: isset($row['completeValue']) ? (int) $row['completeValue'] : null,
            );
        }

        return $mapped;
    }

    /**
     * @param  list<array<string, mixed>>  $activities
     * @return list<ContactActivityData>
     */
    public function mapActivities(array $activities): array
    {
        $mapped = [];
        foreach ($activities as $row) {
            $mapped[] = new ContactActivityData(
                id: isset($row['id']) ? (string) $row['id'] : null,
                type: $this->nullableString($row['referenceModelName'] ?? $row['reference_type'] ?? null),
                tstamp: $this->nullableString($row['tstamp'] ?? null),
                referenceType: $this->nullableString($row['reference_type'] ?? null),
                referenceId: isset($row['reference_id']) ? (string) $row['reference_id'] : null,
                referenceAction: $this->nullableString($row['reference_action'] ?? null),
                referenceModelName: $this->nullableString($row['referenceModelName'] ?? null),
                meta: [
                    'subscriberid' => $row['subscriberid'] ?? null,
                    'userid' => $row['userid'] ?? null,
                    'jsonData' => $row['jsonData'] ?? null,
                ],
            );
        }

        usort($mapped, static function (ContactActivityData $a, ContactActivityData $b): int {
            return strcmp((string) $b->tstamp, (string) $a->tstamp);
        });

        return $mapped;
    }

    /**
     * @param  list<array<string, mixed>>  $scoreValues
     * @return list<ContactScoreData>
     */
    public function mapScores(array $scoreValues): array
    {
        $catalog = $this->scoreCatalogById();

        $mapped = [];
        foreach ($scoreValues as $row) {
            $scoreId = (int) ($row['score'] ?? 0);
            $mapped[] = new ContactScoreData(
                scoreValueId: (int) ($row['id'] ?? 0),
                scoreId: $scoreId,
                name: $this->nullableString($catalog[$scoreId]['name'] ?? $catalog[$scoreId]['descript'] ?? null),
                scoreValue: (int) ($row['scoreValue'] ?? 0),
                cdate: $this->nullableString($row['cdate'] ?? null),
                mdate: $this->nullableString($row['mdate'] ?? null),
            );
        }

        return $mapped;
    }

    /**
     * @param  array<string, mixed>  $contact
     * @param  array<string, mixed>|null  $ownerUser
     */
    public function mapOwner(array $contact, ?array $ownerUser = null): ?ContactOwnerData
    {
        $userId = (int) (
            $contact['owner']
            ?? $contact['userid']
            ?? $contact['created_by']
            ?? 0
        );

        if ($ownerUser === null && $userId > 0) {
            try {
                $ownerUser = $this->userById($userId);
            } catch (\Throwable) {
                $ownerUser = null;
            }
        }

        if (! is_array($ownerUser) || $ownerUser === []) {
            return null;
        }

        $id = (int) ($ownerUser['id'] ?? $userId);
        if ($id <= 0) {
            return null;
        }

        $fullName = trim(($ownerUser['firstName'] ?? '').' '.($ownerUser['lastName'] ?? ''));
        $name = $this->nullableString($ownerUser['username'] ?? null)
            ?? $this->nullableString($fullName !== '' ? $fullName : null)
            ?? $this->nullableString($ownerUser['email'] ?? null);

        return new ContactOwnerData(
            id: $id,
            name: $name,
            email: $this->nullableString($ownerUser['email'] ?? null),
        );
    }

    /**
     * Engagement derivado de contact.sentcnt + activities.
     * Nunca lanza: campos ausentes → "No disponible".
     *
     * @param  array<string, mixed>  $contact
     * @param  list<ContactActivityData>  $activities
     */
    public function mapEngagement(array $contact, array $activities): ContactEngagementData
    {
        try {
            $emailsSent = array_key_exists('sentcnt', $contact)
                ? (int) $contact['sentcnt']
                : ContactEngagementData::UNAVAILABLE;

            $lastOpen = ContactEngagementData::UNAVAILABLE;
            $lastClick = ContactEngagementData::UNAVAILABLE;
            $lastCampaign = ContactEngagementData::UNAVAILABLE;
            $openCount = 0;
            $clickCount = 0;

            foreach ($activities as $activity) {
                $type = mb_strtolower((string) ($activity->referenceModelName ?? $activity->type ?? ''));
                $action = mb_strtolower((string) ($activity->referenceAction ?? ''));
                $isEmail = str_contains($type, 'email') || str_contains($type, 'campaign');

                if ($isEmail && ($action === 'open' || str_contains($type, 'open'))) {
                    $openCount++;
                    if ($lastOpen === ContactEngagementData::UNAVAILABLE && $activity->tstamp) {
                        $lastOpen = $activity->tstamp;
                    }
                }

                if ($isEmail && ($action === 'click' || str_contains($type, 'click'))) {
                    $clickCount++;
                    if ($lastClick === ContactEngagementData::UNAVAILABLE && $activity->tstamp) {
                        $lastClick = $activity->tstamp;
                    }
                }

                if (
                    $lastCampaign === ContactEngagementData::UNAVAILABLE
                    && (str_contains($type, 'campaign') || str_contains($type, 'email'))
                    && $activity->referenceId
                ) {
                    $lastCampaign = 'Campaña / email #'.$activity->referenceId;
                }
            }

            $openRate = ContactEngagementData::UNAVAILABLE;
            $clickRate = ContactEngagementData::UNAVAILABLE;
            if (is_int($emailsSent) && $emailsSent > 0) {
                $openRate = (int) round(($openCount / $emailsSent) * 100);
                $clickRate = (int) round(($clickCount / $emailsSent) * 100);
            } elseif (is_int($emailsSent) && $emailsSent === 0) {
                $openRate = 0;
                $clickRate = 0;
            }

            return new ContactEngagementData(
                emailsSent: $emailsSent,
                lastOpen: $lastOpen,
                lastClick: $lastClick,
                openRate: $openRate,
                clickRate: $clickRate,
                lastCampaign: $lastCampaign,
            );
        } catch (\Throwable) {
            return ContactEngagementData::unavailable();
        }
    }

    /**
     * @param  array<string, mixed>|null  $contactData
     * @return array{
     *     city: string|null,
     *     state: string|null,
     *     country: string|null,
     *     country2: string|null,
     *     zip: string|null,
     *     lat: string|null,
     *     lon: string|null,
     *     ip: string|null,
     *     tz: string|null
     * }|null
     */
    public function mapLocation(?array $contactData): ?array
    {
        if ($contactData === null || $contactData === []) {
            return null;
        }

        $location = [
            'city' => $this->nullableString($contactData['geoCity'] ?? null),
            'state' => $this->nullableString($contactData['geoState'] ?? null),
            'country' => $this->nullableString($contactData['geo_country'] ?? null),
            'country2' => $this->nullableString($contactData['geoCountry2'] ?? null),
            'zip' => $this->nullableString($contactData['geoZip'] ?? null),
            'lat' => $this->nullableString($contactData['geoLat'] ?? null),
            'lon' => $this->nullableString($contactData['geoLon'] ?? null),
            'ip' => $this->nullableString(
                isset($contactData['geoIp4']) && (string) $contactData['geoIp4'] !== '0'
                    ? (string) $contactData['geoIp4']
                    : null
            ),
            'tz' => $this->nullableString($contactData['geoTz'] ?? null),
        ];

        $hasValue = false;
        foreach ($location as $value) {
            if ($value !== null && $value !== '' && $value !== '0' && $value !== '0.000000') {
                $hasValue = true;
                break;
            }
        }

        return $hasValue ? $location : null;
    }

    protected function isRelevantField(ContactFieldData $field): bool
    {
        $haystack = mb_strtolower(trim(
            ($field->title ?? '').' '.($field->perstag ?? '')
        ));

        if ($haystack === '') {
            return false;
        }

        foreach (self::RELEVANT_FIELD_KEYWORDS as $keyword) {
            if (str_contains($haystack, mb_strtolower($keyword))) {
                return true;
            }
        }

        return false;
    }

    protected function isInternalField(ContactFieldData $field): bool
    {
        $haystack = mb_strtolower(trim(
            ($field->title ?? '').' '.($field->perstag ?? '')
        ));

        foreach (self::INTERNAL_FIELD_PATTERNS as $pattern) {
            if (str_contains($haystack, mb_strtolower($pattern))) {
                return true;
            }
        }

        return false;
    }

    protected function listStatusLabel(mixed $status): ?string
    {
        return match ((string) $status) {
            '0' => 'Sin confirmar',
            '1' => 'Activa',
            '2' => 'Dado de baja',
            '3' => 'Rebotado',
            default => $this->nullableString(isset($status) ? (string) $status : null),
        };
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function tagCatalogById(): array
    {
        return $this->cache->remember($this->cache->catalogKey('tags'), function () {
            $byId = [];
            foreach ($this->activeCampaign->getTags() as $tag) {
                $id = (int) ($tag['id'] ?? 0);
                if ($id > 0) {
                    $byId[$id] = $tag;
                }
            }

            return $byId;
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function fieldCatalogById(): array
    {
        return $this->cache->remember($this->cache->catalogKey('fields'), function () {
            $byId = [];
            foreach ($this->activeCampaign->getCustomFields() as $field) {
                $id = (int) ($field['id'] ?? 0);
                if ($id > 0) {
                    $byId[$id] = $field;
                }
            }

            return $byId;
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function listCatalogById(): array
    {
        return $this->cache->remember($this->cache->catalogKey('lists'), function () {
            $byId = [];
            foreach ($this->activeCampaign->getLists() as $list) {
                $id = (int) ($list['id'] ?? 0);
                if ($id > 0) {
                    $byId[$id] = $list;
                }
            }

            return $byId;
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function automationCatalogById(): array
    {
        return $this->cache->remember($this->cache->catalogKey('automations'), function () {
            $byId = [];
            foreach ($this->activeCampaign->getAutomations() as $automation) {
                $id = (int) ($automation['id'] ?? 0);
                if ($id > 0) {
                    $byId[$id] = $automation;
                }
            }

            return $byId;
        });
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function scoreCatalogById(): array
    {
        return $this->cache->remember($this->cache->catalogKey('scores'), function () {
            $byId = [];
            foreach ($this->activeCampaign->getScores() as $score) {
                $id = (int) ($score['id'] ?? 0);
                if ($id > 0) {
                    $byId[$id] = $score;
                }
            }

            return $byId;
        });
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function userById(int $userId): ?array
    {
        return $this->cache->remember($this->cache->catalogKey("user:{$userId}"), function () use ($userId) {
            return $this->activeCampaign->getUser($userId);
        });
    }

    protected function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = trim((string) $value);

        return $string === '' ? null : $string;
    }
}
