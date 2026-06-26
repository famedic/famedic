<?php

namespace App\Services\Membership;

use App\Enums\MedicalSubscriptionType;
use App\Models\Customer;
use App\Models\FamilyAccount;
use App\Models\MedicalAttentionSubscription;
use App\Models\Transaction;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class MembershipDashboardService
{
    public function build(Customer $customer): array
    {
        $context = $this->buildContext($customer);

        return [
            'status' => $this->buildStatus(
                $customer,
                $context['currentSubscription'],
                $context['isActive'],
                $context['remainingDays'],
                $context['renewUrl'],
            ),
            'access' => $this->buildAccess($customer, $context['isActive']),
            'progress' => $context['progress'],
            'benefits' => $this->buildBenefits(),
            'plan' => $this->buildPlan($context['currentSubscription'], $context['isActive']),
            'payment' => $context['latestPayment']
                ? $this->formatPayment($context['latestPayment'])
                : null,
            'coverage' => $this->buildCoverage($customer, $context['isActive']),
            'renewal' => $this->buildRenewal(
                $context['isActive'],
                $context['remainingDays'],
                $context['renewUrl'],
            ),
            'holder' => $this->buildHolder($customer, $context['isActive']),
            'faq' => $this->buildFaq(),
            'capabilities' => $this->buildCapabilities(
                $customer,
                $context['isActive'],
                $context['latestPayment'],
            ),
        ];
    }

    public function buildTabData(Customer $customer, string $tab): array
    {
        $context = $this->buildContext($customer);

        return match ($tab) {
            'plan' => [
                'plan' => $this->buildPlan($context['currentSubscription'], $context['isActive']),
                'benefits' => $this->buildBenefits(),
            ],
            'pagos' => [
                'payments' => $this->buildPayments($context['subscriptions']),
                'capabilities' => $this->buildCapabilities(
                    $customer,
                    $context['isActive'],
                    $context['latestPayment'],
                ),
            ],
            'cobertura' => [
                'coverage' => $this->buildCoverage($customer, $context['isActive']),
                'capabilities' => $this->buildCapabilities(
                    $customer,
                    $context['isActive'],
                    $context['latestPayment'],
                ),
            ],
            'uso' => [
                'usage' => $this->buildUsage(),
            ],
            'historial' => [
                'timeline' => $this->buildTimeline($context['subscriptions'], $customer),
            ],
            'documentos' => [
                'documents' => $this->buildDocuments(
                    $context['latestPayment'],
                    $this->buildCapabilities(
                        $customer,
                        $context['isActive'],
                        $context['latestPayment'],
                    ),
                ),
            ],
            default => [],
        };
    }

    protected function buildContext(Customer $customer): array
    {
        $subscriptions = $customer->medicalAttentionSubscriptions()
            ->with(['transactions'])
            ->orderByDesc('end_date')
            ->get();

        $currentSubscription = $this->resolveCurrentSubscription($subscriptions, $customer);
        $isActive = $customer->medical_attention_subscription_is_active;
        $renewUrl = route('medical-attention.checkout');
        $remainingDays = $this->remainingDays($customer, $currentSubscription);
        $progress = $this->buildProgress($currentSubscription, $remainingDays, $isActive);
        $latestPayment = $this->resolveLatestPayment($currentSubscription, $subscriptions);

        return compact(
            'subscriptions',
            'currentSubscription',
            'isActive',
            'renewUrl',
            'remainingDays',
            'progress',
            'latestPayment',
        );
    }

    protected function resolveCurrentSubscription(Collection $subscriptions, Customer $customer): ?MedicalAttentionSubscription
    {
        $active = $subscriptions->first(fn (MedicalAttentionSubscription $sub) => $sub->is_active);

        if ($active) {
            return $active;
        }

        if ($customer->medical_attention_subscription_expires_at) {
            return $subscriptions->first();
        }

        return $subscriptions->first();
    }

    protected function remainingDays(Customer $customer, ?MedicalAttentionSubscription $subscription): int
    {
        $expiresAt = $subscription?->end_date ?? $customer->medical_attention_subscription_expires_at;

        if (! $expiresAt) {
            return 0;
        }

        $end = Carbon::parse($expiresAt)->endOfDay();

        return max(0, (int) now()->diffInDays($end, false));
    }

    protected function buildStatus(
        Customer $customer,
        ?MedicalAttentionSubscription $subscription,
        bool $isActive,
        int $remainingDays,
        string $renewUrl,
    ): array {
        $status = 'none';
        $statusLabel = 'Sin membresía';

        if ($isActive) {
            $status = 'active';
            $statusLabel = 'Activa';
        } elseif ($subscription || $customer->medical_attention_subscription_expires_at) {
            $status = 'expired';
            $statusLabel = 'Expirada';
        }

        $startDate = $subscription?->start_date ?? $customer->created_at;
        $endDate = $subscription?->end_date ?? $customer->medical_attention_subscription_expires_at;

        return [
            'title' => $this->membershipTitle($subscription),
            'status' => $status,
            'statusLabel' => $statusLabel,
            'startDate' => $this->formatDashboardDate($startDate),
            'endDate' => $this->formatDashboardDate($endDate),
            'remainingDays' => $remainingDays,
            'canRenew' => ! $isActive,
            'renewUrl' => $renewUrl,
        ];
    }

    protected function buildAccess(Customer $customer, bool $isActive): ?array
    {
        if (! $isActive || blank($customer->medical_attention_identifier)) {
            return null;
        }

        $isPremium = (bool) $customer->has_odessa_afiliate_account;

        $phoneNumber = $isPremium ? '5541697768' : '5594540058';
        $formattedPhone = $isPremium ? '55 4169 7768' : '55 9454 0058';

        return [
            'identifier' => $customer->medical_attention_identifier,
            'phoneNumber' => $phoneNumber,
            'formattedPhone' => $formattedPhone,
            'telHref' => 'tel:+52'.$phoneNumber,
            'lineLabel' => $isPremium
                ? 'Línea de atención Premium'
                : 'Línea de atención Básica',
            'lineHint' => $isPremium
                ? 'Línea exclusiva para miembros Premium'
                : 'Línea de atención general',
            'isPremium' => $isPremium,
            'instruction' => 'Marca o presiona el número para iniciar una conversación con un doctor y obtener la atención médica que necesitas.',
        ];
    }

    protected function buildProgress(
        ?MedicalAttentionSubscription $subscription,
        int $remainingDays,
        bool $isActive,
    ): ?array {
        if (! $subscription?->start_date || ! $subscription?->end_date) {
            return null;
        }

        $start = Carbon::parse($subscription->start_date)->startOfDay();
        $end = Carbon::parse($subscription->end_date)->endOfDay();
        $totalDays = max(1, (int) $start->diffInDays($end) + 1);
        $usedDays = $isActive
            ? min($totalDays, max(0, (int) $start->diffInDays(now()->startOfDay()) + 1))
            : $totalDays;
        $computedRemaining = $isActive ? min($remainingDays, max(0, $totalDays - $usedDays)) : 0;
        $percentageUsed = round(($usedDays / $totalDays) * 100, 1);

        return [
            'totalDays' => $totalDays,
            'remainingDays' => $computedRemaining,
            'usedDays' => $usedDays,
            'percentageUsed' => $percentageUsed,
        ];
    }

    protected function buildBenefits(): array
    {
        return [
            [
                'icon' => 'heart',
                'title' => 'Atención médica ilimitada',
                'description' => 'Consultas generales sin límite durante tu vigencia.',
            ],
            [
                'icon' => 'clock',
                'title' => 'Consultas 24/7',
                'description' => 'Médicos disponibles en cualquier momento del día.',
            ],
            [
                'icon' => 'brain',
                'title' => 'Psicología',
                'description' => 'Asistencia telefónica psicológica para ti y tu familia.',
            ],
            [
                'icon' => 'nutrition',
                'title' => 'Nutrición',
                'description' => 'Orientación nutricional profesional por teléfono.',
            ],
            [
                'icon' => 'family',
                'title' => 'Atención para toda la familia',
                'description' => 'Cobertura para titular, cónyuge e hijos.',
            ],
            [
                'icon' => 'video',
                'title' => 'Telemedicina',
                'description' => 'Videoconsultas y chat con especialistas generales.',
            ],
        ];
    }

    protected function buildPlan(?MedicalAttentionSubscription $subscription, bool $isActive): ?array
    {
        if (! $subscription) {
            return null;
        }

        $transaction = $subscription->transactions->first();
        $paymentStatus = $this->resolvePaymentStatusLabel($subscription, $transaction);

        return [
            'name' => $this->planName($subscription),
            'price' => $subscription->formatted_price,
            'purchaseDate' => $this->formatDashboardDate($subscription->created_at),
            'renewalDate' => $this->formatDashboardDate($subscription->end_date),
            'startDate' => $this->formatDashboardDate($subscription->start_date),
            'endDate' => $this->formatDashboardDate($subscription->end_date),
            'paymentType' => $this->paymentTypeLabel($subscription),
            'status' => $paymentStatus,
            'type' => $subscription->type->value,
            'isActive' => $isActive,
            'provider' => $transaction
                ? $this->paymentProviderLabel($transaction)
                : 'Famedic',
        ];
    }

    protected function resolveLatestPayment(
        ?MedicalAttentionSubscription $currentSubscription,
        Collection $subscriptions,
    ): ?Transaction {
        if ($currentSubscription) {
            $transaction = $currentSubscription->transactions
                ->sortByDesc('created_at')
                ->first();

            if ($transaction) {
                return $transaction;
            }
        }

        return $subscriptions
            ->flatMap(fn (MedicalAttentionSubscription $sub) => $sub->transactions)
            ->sortByDesc('created_at')
            ->first();
    }

    protected function formatPayment(Transaction $transaction): array
    {
        $details = $transaction->details ?? [];
        $createdAt = localizedDate($transaction->created_at);
        $cardBrand = $details['card_brand'] ?? null;
        $cardLastFour = $details['card_last_four'] ?? null;
        $method = $cardBrand && $cardLastFour
            ? ucfirst($cardBrand).' ****'.$cardLastFour
            : $this->paymentMethodLabel($transaction->payment_method);

        return [
            'transactionNumber' => $this->formatTransactionNumber($transaction),
            'provider' => $this->paymentProviderLabel($transaction),
            'method' => $method,
            'authorization' => $details['authorization_code']
                ?? $details['auth_code']
                ?? $transaction->gateway_response['authorization_code'] ?? null,
            'reference' => $transaction->reference_id
                ?? $transaction->provider_transaction_id
                ?? $transaction->gateway_transaction_id,
            'date' => $createdAt->isoFormat('D MMM Y'),
            'time' => $createdAt->isoFormat('h:mm A'),
            'amount' => $transaction->formatted_amount,
            'status' => $transaction->isSuccessfulPayment() ? 'Pago exitoso' : 'Pendiente',
            'statusKey' => $transaction->isSuccessfulPayment() ? 'success' : 'pending',
            'transactionId' => $transaction->id,
        ];
    }

    protected function buildHistory(Collection $subscriptions): array
    {
        $entries = $subscriptions->flatMap(function (MedicalAttentionSubscription $subscription) {
            $transactions = $subscription->transactions->sortByDesc('created_at');

            if ($transactions->isEmpty()) {
                return [[
                    'id' => 'subscription-'.$subscription->id,
                    'sortAt' => $subscription->created_at,
                    'date' => $this->formatDashboardDate($subscription->created_at),
                    'concept' => $this->historyConcept($subscription),
                    'amount' => $subscription->formatted_price,
                    'status' => $subscription->price_cents > 0 ? 'Registrado' : 'Gratuito',
                    'statusKey' => $subscription->price_cents > 0 ? 'paid' : 'free',
                    'subscriptionId' => $subscription->id,
                    'transactionId' => null,
                ]];
            }

            return $transactions->map(fn (Transaction $transaction) => [
                'id' => 'transaction-'.$transaction->id,
                'sortAt' => $transaction->created_at,
                'date' => $this->formatDashboardDate($transaction->created_at),
                'concept' => $this->historyConcept($subscription),
                'amount' => $transaction->formatted_amount,
                'status' => $transaction->isSuccessfulPayment() ? 'Pagado' : 'Pendiente',
                'statusKey' => $transaction->isSuccessfulPayment() ? 'paid' : 'pending',
                'subscriptionId' => $subscription->id,
                'transactionId' => $transaction->id,
            ]);
        });

        return $entries
            ->sortByDesc(fn (array $entry) => $entry['sortAt'])
            ->map(fn (array $entry) => collect($entry)->except('sortAt')->all())
            ->values()
            ->all();
    }

    protected function buildHolder(Customer $customer, bool $isActive): array
    {
        $user = $customer->user;
        $isTitular = $customer->customerable_type !== FamilyAccount::class;

        return [
            'id' => 'holder-'.$customer->id,
            'name' => trim(collect([
                $user?->name,
                $user?->paternal_lastname,
                $user?->maternal_lastname,
            ])->filter()->join(' ')),
            'email' => $user?->email,
            'phone' => $user?->phone,
            'formattedPhone' => $user?->phone
                ? $this->formatPhone((string) $user->phone)
                : null,
            'birthDate' => $user?->birth_date
                ? $this->formatDashboardDate($user->birth_date)
                : null,
            'userType' => $isTitular ? 'Titular' : 'Beneficiario',
            'status' => $isActive ? 'Activo' : 'Inactivo',
            'statusKey' => $isActive ? 'active' : 'inactive',
            'avatarUrl' => $user?->profile_photo_url,
            'initials' => $this->initials($user?->name),
            'editUrl' => route('user.edit'),
        ];
    }

    protected function buildCoverage(Customer $customer, bool $isActive): array
    {
        $user = $customer->user;
        $beneficiaries = [
            [
                'id' => 'holder-'.$customer->id,
                'familyAccountId' => null,
                'name' => trim(collect([
                    $user?->name,
                    $user?->paternal_lastname,
                    $user?->maternal_lastname,
                ])->filter()->join(' ')),
                'kinship' => 'Titular',
                'age' => $user?->birth_date ? (int) Carbon::parse($user->birth_date)->age : null,
                'status' => $isActive ? 'Activo' : 'Inactivo',
                'statusKey' => $isActive ? 'active' : 'inactive',
                'avatarUrl' => $user?->profile_photo_url,
                'initials' => $this->initials($user?->name),
                'isHolder' => true,
                'editUrl' => route('user.edit'),
            ],
        ];

        $familyAccounts = $customer->familyAccounts()->with('customer.user')->get();

        foreach ($familyAccounts as $familyAccount) {
            $beneficiaries[] = [
                'id' => 'family-'.$familyAccount->id,
                'familyAccountId' => $familyAccount->id,
                'name' => $familyAccount->full_name,
                'kinship' => $familyAccount->formatted_kinship,
                'age' => $familyAccount->birth_date ? (int) Carbon::parse($familyAccount->birth_date)->age : null,
                'status' => $isActive ? 'Activo' : 'Inactivo',
                'statusKey' => $isActive ? 'active' : 'inactive',
                'avatarUrl' => $familyAccount->profile_photo_url,
                'initials' => $this->initials($familyAccount->name),
                'isHolder' => false,
                'editUrl' => route('family.edit', $familyAccount),
            ];
        }

        return $beneficiaries;
    }

    protected function buildPayments(Collection $subscriptions): array
    {
        return $subscriptions
            ->flatMap(function (MedicalAttentionSubscription $subscription) {
                $transactions = $subscription->transactions->sortByDesc('created_at');

                if ($transactions->isEmpty()) {
                    return [[
                        'id' => 'subscription-'.$subscription->id,
                        'sortAt' => $subscription->created_at,
                        'date' => $this->formatDashboardDate($subscription->created_at),
                        'concept' => $this->historyConcept($subscription),
                        'amount' => $subscription->formatted_price,
                        'method' => $this->paymentTypeLabel($subscription),
                        'provider' => 'Famedic',
                        'status' => $subscription->price_cents > 0 ? 'Registrado' : 'Gratuito',
                        'statusKey' => $subscription->price_cents > 0 ? 'paid' : 'free',
                        'invoiceAvailable' => false,
                        'transactionId' => null,
                    ]];
                }

                return $transactions->map(fn (Transaction $transaction) => [
                    'id' => 'transaction-'.$transaction->id,
                    'sortAt' => $transaction->created_at,
                    'date' => $this->formatDashboardDate($transaction->created_at),
                    'concept' => $this->historyConcept($subscription),
                    'amount' => $transaction->formatted_amount,
                    'method' => $this->formatPayment($transaction)['method'],
                    'provider' => $this->paymentProviderLabel($transaction),
                    'status' => $transaction->isSuccessfulPayment() ? 'Pagado' : 'Pendiente',
                    'statusKey' => $transaction->isSuccessfulPayment() ? 'paid' : 'pending',
                    'invoiceAvailable' => false,
                    'transactionId' => $transaction->id,
                ]);
            })
            ->sortByDesc(fn (array $entry) => $entry['sortAt'])
            ->map(fn (array $entry) => collect($entry)->except('sortAt')->all())
            ->values()
            ->all();
    }

    protected function buildTimeline(Collection $subscriptions, Customer $customer): array
    {
        $events = collect();

        foreach ($subscriptions as $subscription) {
            $events->push([
                'id' => 'subscription-start-'.$subscription->id,
                'sortAt' => $subscription->start_date ?? $subscription->created_at,
                'date' => $this->formatDashboardDate($subscription->start_date ?? $subscription->created_at),
                'title' => $this->historyConcept($subscription),
                'description' => 'Vigencia hasta '.$this->formatDashboardDate($subscription->end_date),
                'type' => 'purchase',
                'typeLabel' => 'Compra',
                'amount' => $subscription->formatted_price,
            ]);

            foreach ($subscription->transactions->sortByDesc('created_at') as $transaction) {
                $events->push([
                    'id' => 'transaction-'.$transaction->id,
                    'sortAt' => $transaction->created_at,
                    'date' => $this->formatDashboardDate($transaction->created_at),
                    'title' => 'Pago registrado',
                    'description' => $this->historyConcept($subscription),
                    'type' => 'payment',
                    'typeLabel' => 'Pago',
                    'amount' => $transaction->formatted_amount,
                ]);
            }
        }

        foreach ($customer->familyAccounts as $familyAccount) {
            $events->push([
                'id' => 'family-added-'.$familyAccount->id,
                'sortAt' => $familyAccount->created_at,
                'date' => $this->formatDashboardDate($familyAccount->created_at),
                'title' => 'Beneficiario agregado',
                'description' => $familyAccount->full_name.' · '.$familyAccount->formatted_kinship,
                'type' => 'beneficiary',
                'typeLabel' => 'Alta',
                'amount' => null,
            ]);
        }

        return $events
            ->sortByDesc(fn (array $event) => $event['sortAt'])
            ->map(fn (array $event) => collect($event)->except('sortAt')->all())
            ->values()
            ->all();
    }

    protected function buildDocuments(?Transaction $latestPayment, array $capabilities): array
    {
        $canDownloadReceipt = $capabilities['canDownloadReceipt'] ?? false;

        return [
            [
                'id' => 'receipt',
                'type' => 'receipt',
                'label' => 'Comprobante de pago',
                'description' => 'Recibo de tu última transacción.',
                'available' => $canDownloadReceipt,
                'downloadUrl' => $capabilities['receiptDownloadUrl'] ?? null,
            ],
            [
                'id' => 'contract',
                'type' => 'contract',
                'label' => 'Contrato de membresía',
                'description' => 'Términos y condiciones de tu plan.',
                'available' => false,
                'downloadUrl' => null,
            ],
            [
                'id' => 'invoice',
                'type' => 'invoice',
                'label' => 'Factura',
                'description' => 'Comprobante fiscal de tu compra.',
                'available' => false,
                'downloadUrl' => null,
            ],
            [
                'id' => 'terms',
                'type' => 'terms',
                'label' => 'Términos y condiciones',
                'description' => 'Políticas de uso de la membresía.',
                'available' => false,
                'downloadUrl' => null,
            ],
        ];
    }

    protected function buildRenewal(bool $isActive, int $remainingDays, string $renewUrl): array
    {
        return [
            'showBanner' => $isActive && $remainingDays > 0 && $remainingDays <= 30,
            'daysRemaining' => $remainingDays,
            'renewUrl' => $renewUrl,
        ];
    }

    protected function buildUsage(): array
    {
        return [
            'available' => false,
            'consultations' => null,
            'psychology' => null,
            'nutrition' => null,
            'videoCalls' => null,
            'lastUsedAt' => null,
            'lastUsedLabel' => null,
        ];
    }

    protected function buildFaq(): array
    {
        return [
            [
                'question' => '¿Cómo uso mi membresía?',
                'answer' => 'Ingresa a Atención médica desde Famedic y solicita una consulta por videollamada o chat. También puedes usar las asistencias telefónicas de psicología, nutrición y orientación legal.',
            ],
            [
                'question' => '¿Qué incluye?',
                'answer' => 'Telemedicina ilimitada 24/7, asistencias telefónicas psicológicas, nutricionales y legales, y cobertura para titular, cónyuge e hijos durante la vigencia de tu plan.',
            ],
            [
                'question' => '¿Cómo renovar?',
                'answer' => 'Cuando tu membresía esté por vencer o haya expirado, usa el botón Renovar membresía para completar el pago anual y extender tu vigencia por un año más.',
            ],
            [
                'question' => '¿Cómo cancelar?',
                'answer' => 'Tu membresía es de pago único anual y no se renueva automáticamente. Si necesitas ayuda con tu cuenta, contacta a soporte antes de tu fecha de vencimiento.',
            ],
            [
                'question' => '¿Cómo contactar soporte?',
                'answer' => 'Escríbenos desde la sección de ayuda de Famedic o al correo de soporte indicado en tu comprobante. Estamos disponibles para resolver dudas sobre cobertura y uso.',
            ],
        ];
    }

    protected function buildCapabilities(
        Customer $customer,
        bool $isActive,
        ?Transaction $latestPayment,
    ): array {
        $isTitular = $customer->customerable_type !== FamilyAccount::class;

        return [
            'canRenew' => true,
            'canAddBeneficiary' => $isActive && $isTitular,
            'canDownloadReceipt' => $latestPayment !== null && $latestPayment->isSuccessfulPayment(),
            'canCancel' => false,
            'canChangePlan' => false,
            'canAutoRenew' => false,
            'canInvoice' => false,
            'addBeneficiaryUrl' => $isActive && $isTitular ? route('family.create') : null,
            'receiptDownloadUrl' => null,
        ];
    }

    protected function membershipTitle(?MedicalAttentionSubscription $subscription): string
    {
        if (! $subscription) {
            return 'Membresía Médica Anual';
        }

        return match ($subscription->type) {
            MedicalSubscriptionType::TRIAL => 'Membresía Médica — Prueba',
            MedicalSubscriptionType::INSTITUTIONAL => 'Membresía Médica Institucional',
            MedicalSubscriptionType::FAMILY_MEMBER => 'Membresía Médica Familiar',
            default => 'Membresía Médica Anual',
        };
    }

    protected function planName(MedicalAttentionSubscription $subscription): string
    {
        return match ($subscription->type) {
            MedicalSubscriptionType::TRIAL => 'Membresía de prueba',
            MedicalSubscriptionType::INSTITUTIONAL => 'Membresía institucional',
            MedicalSubscriptionType::FAMILY_MEMBER => 'Membresía familiar',
            default => 'Membresía Anual',
        };
    }

    protected function paymentTypeLabel(MedicalAttentionSubscription $subscription): string
    {
        return match ($subscription->type) {
            MedicalSubscriptionType::TRIAL, MedicalSubscriptionType::FAMILY_MEMBER => 'Sin costo',
            MedicalSubscriptionType::INSTITUTIONAL => 'Institucional',
            default => 'Pago único',
        };
    }

    protected function resolvePaymentStatusLabel(
        MedicalAttentionSubscription $subscription,
        ?Transaction $transaction,
    ): string {
        if ($transaction) {
            return $transaction->isSuccessfulPayment() ? 'Pagado' : 'Pendiente';
        }

        return match ($subscription->type) {
            MedicalSubscriptionType::TRIAL, MedicalSubscriptionType::FAMILY_MEMBER => 'Sin costo',
            default => $subscription->price_cents > 0 ? 'Registrado' : 'Sin costo',
        };
    }

    protected function historyConcept(MedicalAttentionSubscription $subscription): string
    {
        return match ($subscription->type) {
            MedicalSubscriptionType::TRIAL => 'Activación de prueba gratuita',
            MedicalSubscriptionType::INSTITUTIONAL => 'Membresía institucional',
            MedicalSubscriptionType::FAMILY_MEMBER => 'Alta de beneficiario familiar',
            default => 'Compra membresía anual',
        };
    }

    protected function formatDashboardDate($date): ?string
    {
        if (! $date) {
            return null;
        }

        return localizedDate($date)->isoFormat('D MMM Y');
    }

    protected function formatTransactionNumber(Transaction $transaction): string
    {
        return sprintf(
            'FM-%s-%06d',
            $transaction->created_at?->format('Y') ?? now()->format('Y'),
            $transaction->id,
        );
    }

    protected function paymentProviderLabel(Transaction $transaction): string
    {
        return match ($transaction->payment_method ?? $transaction->gateway) {
            'efevoopay' => 'EfevooPay',
            'stripe' => 'Stripe',
            'paypal' => 'PayPal',
            'odessa' => 'Odessa',
            default => ucfirst((string) ($transaction->payment_provider ?? $transaction->gateway ?? 'Famedic')),
        };
    }

    protected function paymentMethodLabel(?string $method): string
    {
        return match ($method) {
            'efevoopay' => 'Tarjeta',
            'stripe' => 'Tarjeta',
            'paypal' => 'PayPal',
            'odessa' => 'Odessa',
            default => 'No especificado',
        };
    }

    protected function initials(?string $name): string
    {
        if (! $name) {
            return '?';
        }

        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $first = mb_substr($parts[0] ?? '?', 0, 1);
        $second = isset($parts[1]) ? mb_substr($parts[1], 0, 1) : '';

        return mb_strtoupper($first.$second);
    }

    protected function formatPhone(string $phone): string
    {
        $digits = preg_replace('/\D+/', '', $phone) ?? $phone;

        if (strlen($digits) === 10) {
            return substr($digits, 0, 2).' '
                .substr($digits, 2, 4).' '
                .substr($digits, 6);
        }

        return $phone;
    }
}
