<?php

namespace App\Services\Api\V1\Uat;

use App\Data\Api\V1\Uat\AkubicaUatFixtureContract;
use App\Data\Api\V1\Uat\AkubicaUatFixturePlan;
use App\Exceptions\AkubicaUatFixtureException;
use Carbon\CarbonImmutable;

class AkubicaUatFixturePlanner
{
    /**
     * @return array<string, string>
     */
    public function configuredStatus(): array
    {
        $identities = config('akubica_uat.identities', []);

        return collect(['primary', 'foreign', 'disposable'])
            ->mapWithKeys(function (string $key) use ($identities): array {
                $identity = (array) ($identities[$key] ?? []);

                return [
                    $key => filled($identity['email'] ?? null)
                        && filled($identity['phone'] ?? null)
                        && filled($identity['country'] ?? null)
                            ? 'configured'
                            : 'not_configured',
                ];
            })
            ->all();
    }

    public function buildPlan(string $action, bool $requireConfigured): AkubicaUatFixturePlan
    {
        $configured = $this->configuredStatus();
        $identities = $requireConfigured ? $this->requiredIdentities() : $this->optionalIdentities(validateCompleteSet: false);
        $storageDefinitions = $this->storageDefinitions(AkubicaUatFixtureContract::NAMESPACE);

        return new AkubicaUatFixturePlan(
            namespace: AkubicaUatFixtureContract::NAMESPACE,
            fixtureVersion: AkubicaUatFixtureContract::FIXTURE_VERSION,
            action: $action,
            configured: $configured,
            counts: [
                'apply' => [
                    'users' => 2,
                    'customers' => 2,
                    'contacts' => 2,
                    'addresses' => 2,
                    'tax_profiles' => 2,
                    'orders' => 5,
                    'purchase_items' => 5,
                    'cart_items' => 2,
                    'checkout_drafts' => 1,
                    'coupons' => 3,
                    'coupon_assignments' => 2,
                    'tests' => 2,
                    'stores' => 1,
                    'categories' => 1,
                ],
                'reset' => [
                    'manifest_rows' => 1,
                ],
            ],
            identityHashes: [
                'primary' => isset($identities['primary']) ? $this->identityHash($identities['primary']['email'], $identities['primary']['phone']) : 'not_configured',
                'foreign' => isset($identities['foreign']) ? $this->identityHash($identities['foreign']['email'], $identities['foreign']['phone']) : 'not_configured',
                'disposable' => isset($identities['disposable']) ? $this->identityHash($identities['disposable']['email'], $identities['disposable']['phone']) : 'not_configured',
            ],
            storageHashes: collect($storageDefinitions)->mapWithKeys(
                fn (array $definition, string $path): array => [$path => hash('sha256', $definition['content'])]
            )->all(),
            storagePaths: array_keys($storageDefinitions),
            idempotencyActorHashes: [
                'primary' => isset($identities['primary']) ? $this->idempotencyActorHash('primary', $identities['primary']['email'], $identities['primary']['phone']) : 'not_configured',
                'foreign' => isset($identities['foreign']) ? $this->idempotencyActorHash('foreign', $identities['foreign']['email'], $identities['foreign']['phone']) : 'not_configured',
                'disposable' => isset($identities['disposable']) ? $this->idempotencyActorHash('disposable', $identities['disposable']['email'], $identities['disposable']['phone']) : 'not_configured',
            ],
            expiresAt: CarbonImmutable::now()->addDays((int) config('akubica_uat.ttl_days', 14)),
        );
    }

    /**
     * @return array<string, array{email: string, phone: string, country: string}>
     */
    public function requiredIdentities(): array
    {
        return $this->optionalIdentities(validateCompleteSet: true);
    }

    /**
     * @return array<string, array{email: string, phone: string, country: string}>
     */
    private function optionalIdentities(bool $validateCompleteSet): array
    {
        $identities = (array) config('akubica_uat.identities', []);
        $normalized = [];

        foreach (['primary', 'foreign', 'disposable'] as $role) {
            $identity = (array) ($identities[$role] ?? []);
            $email = mb_strtolower(trim((string) ($identity['email'] ?? '')));
            $phone = preg_replace('/\D+/', '', (string) ($identity['phone'] ?? '')) ?? '';
            $country = strtoupper(trim((string) ($identity['country'] ?? '')));

            if ($email === '' || $phone === '' || $country === '') {
                if ($validateCompleteSet) {
                    throw new AkubicaUatFixtureException('UAT_CONFIG_REQUIRED');
                }

                continue;
            }

            if ($country !== 'MX' || strlen($phone) !== 10) {
                throw new AkubicaUatFixtureException('UAT_CONFIG_INVALID');
            }

            $normalized[$role] = compact('email', 'phone', 'country');
        }

        if ($validateCompleteSet && count($normalized) !== 3) {
            throw new AkubicaUatFixtureException('UAT_CONFIG_REQUIRED');
        }

        $pairs = collect($normalized)->flatMap(fn (array $identity): array => [
            $identity['email'],
            $identity['country'].'|'.$identity['phone'],
        ])->all();

        if (count($pairs) !== count(array_unique($pairs))) {
            throw new AkubicaUatFixtureException('UAT_CONFIG_COLLISION');
        }

        return $normalized;
    }

    /**
     * @return array<string, array{content: string, mime: string}>
     */
    public function storageDefinitions(string $namespace): array
    {
        return [
            AkubicaUatFixtureContract::storagePath('results/result-ready.pdf') => [
                'content' => "%PDF-1.4\n% Synthetic [UAT] results ready {$namespace}\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Count 1/Kids[3 0 R]>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 300 144]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n",
                'mime' => 'application/pdf',
            ],
            AkubicaUatFixtureContract::storagePath('results/foreign-order.pdf') => [
                'content' => "%PDF-1.4\n% Synthetic [UAT] foreign results {$namespace}\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Count 1/Kids[3 0 R]>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 300 144]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n",
                'mime' => 'application/pdf',
            ],
            AkubicaUatFixtureContract::storagePath('invoices/invoice-ready.pdf') => [
                'content' => "%PDF-1.4\n% Synthetic [UAT] invoice ready {$namespace}\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Count 1/Kids[3 0 R]>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 300 144]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n",
                'mime' => 'application/pdf',
            ],
            AkubicaUatFixtureContract::storagePath('invoices/invoice-ready.xml') => [
                'content' => '<invoice synthetic="true" namespace="'.$namespace.'"><status>completed</status></invoice>',
                'mime' => 'application/xml',
            ],
            AkubicaUatFixtureContract::storagePath('invoices/foreign-order.pdf') => [
                'content' => "%PDF-1.4\n% Synthetic [UAT] foreign invoice {$namespace}\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Count 1/Kids[3 0 R]>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 300 144]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n",
                'mime' => 'application/pdf',
            ],
            AkubicaUatFixtureContract::storagePath('invoices/foreign-order.xml') => [
                'content' => '<invoice synthetic="true" namespace="'.$namespace.'"><status>foreign</status></invoice>',
                'mime' => 'application/xml',
            ],
            AkubicaUatFixtureContract::storagePath('tax/fiscal-certificate.pdf') => [
                'content' => "%PDF-1.4\n% Synthetic [UAT] fiscal certificate {$namespace}\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj\n2 0 obj<</Type/Pages/Count 1/Kids[3 0 R]>>endobj\n3 0 obj<</Type/Page/Parent 2 0 R/MediaBox[0 0 300 144]>>endobj\ntrailer<</Root 1 0 R>>\n%%EOF\n",
                'mime' => 'application/pdf',
            ],
        ];
    }

    public function identityHash(string $email, string $phone): string
    {
        return hash_hmac('sha256', $email.'|'.$phone, (string) config('app.key'));
    }

    public function idempotencyActorHash(string $role, string $email, string $phone): string
    {
        return hash_hmac('sha256', $role.'|'.$email.'|'.$phone, 'akubica-uat-idempotency');
    }
}
