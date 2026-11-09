<?php

declare(strict_types=1);

namespace App\Services\Api\V1\Benefits;

use App\Domain\Akubica\Benefits\BenefitActor;
use App\Domain\Akubica\Benefits\BenefitFailure;
use App\Domain\Akubica\Benefits\BenefitStateStore;
use App\Domain\Akubica\Benefits\OpaqueReference;
use App\Exceptions\CouponApplicationException;
use App\Models\Coupon;
use App\Models\CouponUser;
use App\Models\MedicalServiceCart;
use App\Models\User;
use App\Services\Api\V1\MedicalServiceCartService;
use App\Services\CouponApplicationService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

/** Real DB adapter. No raw references/consents/results stored in plaintext.
 * Root transactions serialize new operations via a mutex, then cart/source locks.
 * MySQL uses READ COMMITTED for this transaction; unknown outer transactions fail closed.
 */
final class EloquentBenefitStateStore implements BenefitStateStore
{
    private int $depth = 0;

    private ?object $context = null;

    private ?MedicalServiceCart $locked = null;

    private ?array $financialContext = null;

    public function __construct(public readonly BenefitActor $actor, private ?int $contextId = null) {}

    public function transaction(callable $operation): mixed
    {
        if ($this->depth > 0) {
            return $operation();
        }
        if (DB::transactionLevel() !== 0) {
            throw new BenefitFailure('BENEFIT_SERVICE_UNAVAILABLE');
        }
        if (DB::getDriverName() === 'mysql') {
            DB::statement('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
        } elseif (DB::getDriverName() !== 'sqlite') {
            throw new BenefitFailure('BENEFIT_SERVICE_UNAVAILABLE');
        }

        return DB::transaction(function () use ($operation) {
            if (DB::table('opaque_benefit_mutex')->where('id', 1)->increment('generation') !== 1) {
                throw new BenefitFailure('BENEFIT_SERVICE_UNAVAILABLE');
            }
            $this->depth = 1;
            try {
                $user = User::findOrFail($this->actor->userId);
                $q = MedicalServiceCart::query()->where('customer_id', $user->customer?->id)->where('service_line', 'medical_services');
                if ($this->contextId) {
                    $ctx = DB::table('opaque_benefit_contexts')->where('id', $this->contextId)->where('actor_hash', $this->actor->namespace())->first();
                    if (! $ctx) {
                        throw new BenefitFailure('CART_REFERENCE_NOT_FOUND');
                    }$q->where('id', $ctx->cart_id);
                } else {
                    $q->where('status', 'active');
                }
                $this->locked = $q->lockForUpdate()->first();
                if (! $this->locked) {
                    throw new BenefitFailure('EMPTY_CART');
                }
                $limit = config('akubica_connectors.catalog_limits.assigned_sources', 200);
                if (! is_int($limit) || $limit < 1 || $limit > 200) {
                    throw new BenefitFailure('BENEFIT_SERVICE_UNAVAILABLE');
                }
                $ids = CouponUser::query()->where('user_id', $this->actor->userId)->orderBy('coupon_id')->limit($limit + 1)->pluck('coupon_id');
                if ($ids->count() > $limit) {
                    throw new BenefitFailure('BENEFIT_SERVICE_UNAVAILABLE');
                }
                Coupon::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
                CouponUser::query()->where('user_id', $this->actor->userId)->whereIn('coupon_id', $ids)->orderBy('coupon_id')->lockForUpdate()->get();

                return $operation();
            } finally {
                $this->depth = 0;
                $this->locked = null;
                $this->context = null;
                $this->financialContext = null;
            }
        }, 1);
    }

    private function requireTransaction(): void
    {
        if ($this->depth === 0) {
            throw new \LogicException('Benefit transaction required');
        }
    }

    private function fingerprint(): string
    {
        $c = $this->locked->fresh();
        $items = $c->items()->orderBy('id')->get()->map(fn ($i) => $i->getAttributes())->all();
        $selection = $c->benefits()->orderBy('id')->get()->map(fn ($b) => $b->getAttributes())->all();
        $sourceState = [];
        foreach ($selection as $b) {
            $coupon = Coupon::query()->select(['id', 'opaque_revision', 'remaining_cents', 'is_active', 'approval_status', 'valid_from', 'expires_at', 'min_purchase_cents'])->find($b['coupon_id']);
            $assignment = CouponUser::query()->where('coupon_id', $b['coupon_id'])->where('user_id', $this->actor->userId)->first(['id', 'opaque_revision', 'used_at']);
            $sourceState[] = [$coupon?->getAttributes(), $assignment?->getAttributes()];
        }

        return hash('sha256', json_encode([(int) $c->opaque_revision, $c->status, $items, $selection, $sourceState], JSON_THROW_ON_ERROR));
    }

    public function cartReference(): string
    {
        $this->requireTransaction();
        $this->refreshContext();

        return Crypt::decryptString($this->context->cart_ref_ciphertext);
    }

    public function contextId(): int
    {
        $this->refreshContext();

        return (int) $this->context->id;
    }

    private function refreshContext(): void
    {
        $this->requireTransaction();
        $cart = $this->locked->fresh();
        $fingerprint = $this->fingerprint();
        $ctx = DB::table('opaque_benefit_contexts')->where('cart_id', $cart->id)->where('actor_hash', $this->actor->namespace())->lockForUpdate()->first();
        if (! $ctx) {
            $ref = OpaqueReference::issue('crt');
            $id = DB::table('opaque_benefit_contexts')->insertGetId(['cart_id' => $cart->id, 'user_id' => $this->actor->userId, 'consumer_id' => (int) $this->actor->consumerId, 'channel_id' => (int) $this->actor->channelId, 'delegation_id' => (int) $this->actor->delegationId, 'actor_hash' => $this->actor->namespace(), 'cart_ref_hash' => OpaqueReference::key($ref, 'crt'), 'cart_ref_ciphertext' => Crypt::encryptString($ref), 'version_ciphertext' => Crypt::encryptString(OpaqueReference::issue('ver')), 'fingerprint' => $fingerprint, 'revision' => (int) $cart->opaque_revision, 'created_at' => now(), 'updated_at' => now()]);
            $ctx = DB::table('opaque_benefit_contexts')->find($id);
        } elseif ($ctx->fingerprint !== $fingerprint) {
            DB::table('opaque_benefit_contexts')->where('id', $ctx->id)->update(['fingerprint' => $fingerprint, 'revision' => (int) $cart->opaque_revision, 'version_ciphertext' => Crypt::encryptString(OpaqueReference::issue('ver')), 'updated_at' => now()]);
            $ctx = DB::table('opaque_benefit_contexts')->find($ctx->id);
        }
        $this->context = $ctx;
        $version = Crypt::decryptString($ctx->version_ciphertext);
        $snapshotKey = OpaqueReference::key($version, 'ver');
        if (! DB::table('opaque_benefit_states')->where('bucket', 'snapshots')->where('key_hash', $snapshotKey)->exists()) {
            $snapshot = ['version' => $version, 'revision' => (int) $cart->opaque_revision, 'status' => $cart->status, 'fingerprint' => $fingerprint, 'totals' => ['subtotal_cents' => (int) $cart->subtotal_cents, 'discount_cents' => (int) $cart->discount_cents, 'credit_applied_cents' => (int) $cart->credit_applied_cents, 'total_cents' => (int) $cart->total_cents],
                'items' => $cart->items()->orderBy('id')->get()->map(fn ($i) => array_intersect_key($i->getAttributes(), array_flip(['id', 'item_type', 'quantity', 'unit_price_cents', 'subtotal_cents'])))->all(),
                'selection' => $cart->benefits()->orderBy('id')->get()->map(fn ($b) => array_intersect_key($b->getAttributes(), array_flip(['coupon_id', 'benefit_type', 'applied_cents'])))->all()];
            DB::table('opaque_benefit_states')->insert(['bucket' => 'snapshots', 'key_hash' => $snapshotKey, 'context_id' => $ctx->id, 'ciphertext' => Crypt::encryptString(json_encode($snapshot, JSON_THROW_ON_ERROR)), 'created_at' => now(), 'updated_at' => now()]);
        }

    }

    public function get(string $table, string $key): ?array
    {
        $this->requireTransaction();
        if ($table === 'sources') {
            return $this->source($key);
        }
        $this->refreshContext();
        if ($table === 'carts') {
            if (! hash_equals($this->context->cart_ref_hash, $key)) {
                return null;
            }
            $cart = $this->locked->fresh();
            $application = null;
            $rows = DB::table('opaque_benefit_states')->where('context_id', $this->context->id)->where('bucket', 'applications')->orderByDesc('id')->get();
            foreach ($rows as $row) {
                $value = $this->decode($row);
                if ($value['status'] === 'applied') {
                    $application = $value['reference'];
                    break;
                }
            }
            // A legacy selection is never silently adopted as an opaque, consented application.
            if (! $application && $cart->benefits()->exists()) {
                throw new BenefitFailure('LEGACY_SELECTION_REQUIRES_REVIEW');
            }

            return ['user_id' => $this->actor->userId, 'consumer_id' => $this->actor->consumerId, 'delegation_id' => $this->actor->delegationId, 'service_line' => 'medical_services', 'active' => $cart->status === 'active', 'subtotal_cents' => (int) $cart->items()->sum('subtotal_cents'), 'revision' => (int) $cart->opaque_revision, 'version' => Crypt::decryptString($this->context->version_ciphertext), 'application' => $application];
        }
        $this->bucket($table);
        $row = DB::table('opaque_benefit_states')->where('bucket', $table)->where('key_hash', $key)->where('context_id', $this->context->id)->first();

        return $row ? $this->decode($row) : null;
    }

    public function all(string $table): array
    {
        $this->requireTransaction();
        if ($table !== 'sources') {
            throw new \LogicException('Unsupported catalog');
        }
        $subtotal = (int) $this->locked->items()->sum('subtotal_cents');
        $user = User::findOrFail($this->actor->userId);
        $result = [];
        foreach (app(MedicalBenefitEligibilityReader::class)->eligible($user, $subtotal) as $option) {
            $key = (string) $option->sourceIdForInternalBinding();
            $source = $this->source($key);
            if ($source && $source['active']) {
                $result[$key] = $source;
            }
        }

        return $result;
    }

    private function source(string $key): ?array
    {
        if (! ctype_digit($key) || (string) (int) $key !== $key) {
            return null;
        }
        $coupon = Coupon::query()->whereKey((int) $key)->lockForUpdate()->first();
        if (! $coupon) {
            return null;
        }$assignment = CouponUser::query()->where('coupon_id', $coupon->id)->where('user_id', $this->actor->userId)->lockForUpdate()->first();
        if (! $assignment) {
            return null;
        }
        $valid = true;
        try {
            app(CouponApplicationService::class)->validateMedicalServicePreview(User::findOrFail($this->actor->userId), (int) $coupon->id, (int) $this->locked->items()->sum('subtotal_cents'));
        } catch (CouponApplicationException|ModelNotFoundException) {
            $valid = false;
        }

        return ['user_id' => $this->actor->userId, 'kind' => $coupon->type === \App\Enums\CouponType::Balance ? 'credit' : 'coupon', 'active' => $valid, 'approved' => $coupon->approval_status === \App\Enums\CouponApprovalStatus::Active, 'service_line' => 'medical_services', 'remaining_cents' => (int) $coupon->remaining_cents, 'minimum_cents' => (int) $coupon->min_purchase_cents, 'starts_at' => $coupon->valid_from?->timestamp ?? 0, 'expires_at' => $coupon->expires_at?->timestamp ?? PHP_INT_MAX, 'source_revision' => (int) $coupon->opaque_revision, 'assignment_revision' => (int) $assignment->opaque_revision, 'assignment_identity' => (int) $assignment->id];
    }

    private function bucket(string $table): void
    {
        if (! in_array($table, ['options', 'confirmations', 'applications', 'receipts', 'debits'], true)) {
            throw new \LogicException('Unsupported state bucket');
        }
    }

    private function decode(object $row): array
    {
        return json_decode(Crypt::decryptString($row->ciphertext), true, 512, JSON_THROW_ON_ERROR);
    }

    public function put(string $table, string $key, array $value): void
    {
        $this->requireTransaction();
        $this->refreshContext();
        if ($table === 'sources') {
            $this->writeSource($key, $value);

            return;
        }
        if ($table === 'carts') {
            if (! hash_equals($this->context->cart_ref_hash, $key)) {
                throw new BenefitFailure('CART_REFERENCE_NOT_FOUND');
            }
            $cart = $this->locked->fresh();
            $ref = $value['application'];
            if ($ref) {
                $application = $this->get('applications', OpaqueReference::key($ref, 'app'));
                if (! $application) {
                    throw new BenefitFailure('BENEFIT_APPLICATION_NOT_FOUND');
                }$source = $this->source($application['source_id']);
                if (! $source || ! $source['active']) {
                    throw new BenefitFailure('BENEFIT_NOT_ELIGIBLE');
                }$cart->benefits()->updateOrCreate([], ['coupon_id' => (int) $application['source_id'], 'benefit_type' => $application['kind'], 'applied_cents' => $application['amount']]);
            } else {
                $cart->benefits()->delete();
            }
            app(MedicalServiceCartService::class)->recalculate($cart);
            $cart = $cart->fresh();
            DB::table('opaque_benefit_contexts')->where('id', $this->context->id)->update(['version_ciphertext' => Crypt::encryptString($value['version']), 'fingerprint' => $this->fingerprint(), 'revision' => (int) $cart->opaque_revision, 'updated_at' => now()]);
            $this->context = DB::table('opaque_benefit_contexts')->find($this->context->id);
            if ($ref) {
                $application['reference'] = $ref;
                $application['post_revision'] = (int) $cart->opaque_revision;
                $this->put('applications', OpaqueReference::key($ref, 'app'), $application);
            }

            return;
        }
        $this->bucket($table);
        if ($table === 'applications') {
            $value['reference'] = $value['reference'] ?? null;
        }
        // Workflow keys are hashes; preserve the actual application alias supplied by its cart write.
        $existing = DB::table('opaque_benefit_states')->where('bucket', $table)->where('key_hash', $key)->first();
        if ($existing && (int) $existing->context_id !== (int) $this->context->id) {
            throw new BenefitFailure('BENEFIT_REFERENCE_NOT_FOUND');
        }
        DB::table('opaque_benefit_states')->updateOrInsert(['bucket' => $table, 'key_hash' => $key], ['context_id' => $this->context->id, 'source_id' => isset($value['source_id']) ? (int) $value['source_id'] : null, 'ciphertext' => Crypt::encryptString(json_encode($value, JSON_THROW_ON_ERROR)), 'created_at' => $existing?->created_at ?? now(), 'updated_at' => now()]);
    }

    public function bindApplicationAlias(string $hash, string $reference): void
    {
        $row = $this->get('applications', $hash);
        if (! $row) {
            throw new BenefitFailure('BENEFIT_APPLICATION_NOT_FOUND');
        }$row['reference'] = $reference;
        $this->put('applications', $hash, $row);
    }

    private function writeSource(string $key, array $value): void
    {
        throw new BenefitFailure('FINANCIAL_WRITES_PENDING_APPROVAL');
    }
}
