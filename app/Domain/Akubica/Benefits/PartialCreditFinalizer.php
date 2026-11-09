<?php

declare(strict_types=1);

namespace App\Domain\Akubica\Benefits;

/** Confirmed-debit arithmetic and permanent receipt, shared with the gated Laravel bridge. */
final class PartialCreditFinalizer
{
    public function __construct(private BenefitStateStore $store, private BenefitsAuthorizer $auth, private MedicalPurchaseVerifier $purchases) {}

    public function finalize(BenefitActor $actor, string $purchaseRef, int $now): array
    {
        $this->auth->authorize($actor, 'benefits:finalize', $now);
        $verified = $this->purchases->verifiedDebit($actor, $purchaseRef);
        if (! $verified || $verified->purchaseReference !== $purchaseRef || $verified->userId !== $actor->userId
            || $verified->amountCents <= 0 || $verified->sourceKey === '') {
            throw new BenefitFailure('PURCHASE_NOT_VERIFIED');
        }
        $fingerprint = hash('sha256', json_encode([$verified->userId, $verified->sourceKey, $verified->amountCents], JSON_THROW_ON_ERROR));
        // Purchase is the permanent idempotency identity, independent of PAT/consumer rotation.
        $receiptKey = hash('sha256', $purchaseRef);

        return $this->store->transaction(function () use ($actor, $verified, $fingerprint, $receiptKey, $now) {
            $prior = $this->store->get('debits', $receiptKey);
            if ($prior) {
                if ($prior['fingerprint'] !== $fingerprint) {
                    throw new BenefitFailure('PURCHASE_DEBIT_CONFLICT');
                }

                return $prior['result'] + ['replayed' => true];
            }
            $source = $this->store->get('sources', $verified->sourceKey);
            if (! $source || $source['user_id'] !== $actor->userId || $source['kind'] !== 'credit'
                || $source['service_line'] !== 'medical_services' || ! $source['active'] || ! $source['approved']
                || $source['starts_at'] > $now || $source['expires_at'] < $now) {
                throw new BenefitFailure('CREDIT_NOT_ELIGIBLE');
            }
            $remaining = PartialCredit::remainingAfterConfirmedDebit($source['remaining_cents'], $verified->amountCents);
            $source['remaining_cents'] = $remaining;
            $this->store->put('sources', $verified->sourceKey, $source);
            $result = ['debited_cents' => $verified->amountCents, 'remaining_cents' => $remaining];
            $this->store->put('debits', $receiptKey, ['fingerprint' => $fingerprint, 'result' => $result]);

            return $result + ['replayed' => false];
        });
    }
}
