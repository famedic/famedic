<?php

namespace App\Http\Controllers\Admin;

use App\Enums\PaymentAuthenticationAttemptStatus;
use App\Enums\PaymentAuthenticationRecoveryContextStatus;
use App\Enums\PaymentAuthenticationRecoveryContextType;
use App\Exports\PaymentAuthenticationAttemptsExport;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\PaymentAuthenticationAttempts\IndexPaymentAuthenticationAttemptRequest;
use App\Models\PaymentAuthenticationAttempt;
use App\Services\PaymentAuthenticationAttempts\PaymentAuthenticationAttemptDateRange;
use App\Services\PaymentAuthenticationAttempts\PaymentAuthenticationAttemptMetrics;
use App\Services\PaymentAuthenticationAttempts\PaymentAuthenticationAttemptQuery;
use App\Services\PaymentAuthenticationAttempts\PaymentAuthenticationEfevooPayOperationAnalyzer;
use App\Services\PaymentAuthenticationAttempts\PaymentAuthenticationRecoveryAnalyzer;
use App\Services\PaymentAuthenticationAttempts\PaymentAuthenticationRecoveryMetrics;
use App\Support\EfevooPay3dsResultClassifier;
use App\Support\PaymentAuthenticationAttemptAdminResource;
use Inertia\Inertia;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PaymentAuthenticationAttemptController extends Controller
{
    public function index(
        IndexPaymentAuthenticationAttemptRequest $request,
        PaymentAuthenticationAttemptQuery $query,
        PaymentAuthenticationAttemptMetrics $metrics,
        PaymentAuthenticationRecoveryMetrics $recoveryMetrics,
        PaymentAuthenticationRecoveryAnalyzer $recoveryAnalyzer,
        PaymentAuthenticationEfevooPayOperationAnalyzer $operationAnalyzer,
    ) {
        $filters = $this->filters($request);
        $range = PaymentAuthenticationAttemptDateRange::fromFilters($filters);
        $filters = array_merge($filters, $range->toArray());

        $attempts = $query->paginate($filters, $range);
        $attemptIds = $attempts->getCollection()->pluck('id')->all();
        $recoveryAttemptIds = $attempts->getCollection()
            ->filter(fn (PaymentAuthenticationAttempt $attempt) => $attempt->recovery_context_id !== null)
            ->pluck('id')
            ->all();
        $intentionFlags = $recoveryAttemptIds !== []
            ? $recoveryAnalyzer->batchIntentionFlags($recoveryAttemptIds)
            : [];
        $duplicateFlags = $operationAnalyzer->batchPossibleDuplicateFlags($attempts->getCollection());

        $attempts->setCollection(
            $attempts->getCollection()->map(function (PaymentAuthenticationAttempt $attempt) use ($recoveryAnalyzer, $intentionFlags, $duplicateFlags) {
                $flags = $intentionFlags[$attempt->id] ?? PaymentAuthenticationRecoveryAnalyzer::emptyIntentionFlags();
                $recovery = $recoveryAnalyzer->summarizeForAttempt($attempt, $flags);

                return array_merge(
                    PaymentAuthenticationAttemptAdminResource::listItem($attempt, $recovery),
                    [
                        'possible_duplicate_verification_operation' => $duplicateFlags[$attempt->id] ?? false,
                    ]
                );
            })
        );

        return Inertia::render('Admin/PaymentAuthenticationAttempts', [
            'attempts' => $attempts,
            'filters' => $filters,
            'metrics' => $metrics->summarize($filters, $range),
            'recoveryMetrics' => $recoveryMetrics->summarize($filters, $range),
            'options' => $this->options(),
        ]);
    }

    public function show(
        PaymentAuthenticationAttempt $paymentAuthenticationAttempt,
        PaymentAuthenticationAttemptQuery $query,
        PaymentAuthenticationRecoveryAnalyzer $recoveryAnalyzer,
        PaymentAuthenticationEfevooPayOperationAnalyzer $operationAnalyzer,
    ) {
        request()->user()->administrator->hasPermissionTo('payment-attempts.manage') || abort(403);

        $paymentAuthenticationAttempt->load(['recoveryContext.rootAuthenticationAttempt', 'recoveryContext.recoveryTransaction', 'recoveryContext.recoveredTransaction', 'customer.user']);
        $events = $paymentAuthenticationAttempt->events()->get();
        $chain = $query->chainFor($paymentAuthenticationAttempt);

        $recoveryDetail = null;
        if ($paymentAuthenticationAttempt->recoveryContext) {
            $recoveryDetail = $recoveryAnalyzer->detailRecoveryCard(
                $paymentAuthenticationAttempt,
                $paymentAuthenticationAttempt->recoveryContext,
                $events,
                $paymentAuthenticationAttempt->recoveryContext->authenticationAttempts()->count(),
            );
        }

        return Inertia::render('Admin/PaymentAuthenticationAttempt', [
            'attempt' => PaymentAuthenticationAttemptAdminResource::detail(
                $paymentAuthenticationAttempt,
                $events,
                $chain,
                $recoveryDetail,
                $operationAnalyzer->analyze($paymentAuthenticationAttempt->fresh(), $paymentAuthenticationAttempt->efevoo3dsSession)
            ),
        ]);
    }

    public function export(
        IndexPaymentAuthenticationAttemptRequest $request,
        PaymentAuthenticationAttemptQuery $query,
        PaymentAuthenticationRecoveryAnalyzer $recoveryAnalyzer,
    ): BinaryFileResponse {
        $filters = $this->filters($request);
        $range = PaymentAuthenticationAttemptDateRange::fromFilters($filters);

        $rows = $query->exportRows($filters, $range);
        $intentionFlags = $recoveryAnalyzer->batchIntentionFlags($rows->pluck('id')->all());

        $exportRows = $rows
            ->map(function (PaymentAuthenticationAttempt $attempt) use ($recoveryAnalyzer, $intentionFlags) {
                $flags = $intentionFlags[$attempt->id] ?? PaymentAuthenticationRecoveryAnalyzer::emptyIntentionFlags();
                $recovery = $recoveryAnalyzer->summarizeForAttempt($attempt, $flags);

                return PaymentAuthenticationAttemptAdminResource::exportRow($attempt, $recovery);
            })
            ->values();

        return Excel::download(
            new PaymentAuthenticationAttemptsExport($exportRows),
            'intentos-3ds-'.$range->startDate.'_'.$range->endDate.'.xlsx'
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function filters(IndexPaymentAuthenticationAttemptRequest $request): array
    {
        return collect($request->validated())->filter(function ($value) {
            return $value !== null && $value !== '';
        })->all();
    }

    /**
     * @return array<string, list<array{value: string, label: string}>>
     */
    private function options(): array
    {
        return [
            'periods' => [
                ['value' => '1d', 'label' => 'Último día'],
                ['value' => '7d', 'label' => 'Últimos 7 días'],
                ['value' => '30d', 'label' => 'Últimos 30 días'],
                ['value' => 'custom', 'label' => 'Personalizado'],
            ],
            'statuses' => collect(PaymentAuthenticationAttemptStatus::cases())
                ->map(fn (PaymentAuthenticationAttemptStatus $status) => [
                    'value' => $status->value,
                    'label' => $status->value,
                ])->values()->all(),
            'categories' => collect([
                EfevooPay3dsResultClassifier::CATEGORY_AUTHENTICATION_FAILED,
                EfevooPay3dsResultClassifier::CATEGORY_CANCELLED,
                EfevooPay3dsResultClassifier::CATEGORY_CHALLENGE_EXPIRED,
                EfevooPay3dsResultClassifier::CATEGORY_PROVIDER_TIMEOUT,
                EfevooPay3dsResultClassifier::CATEGORY_PROVIDER_ERROR,
                EfevooPay3dsResultClassifier::CATEGORY_NETWORK_ERROR,
                EfevooPay3dsResultClassifier::CATEGORY_TOKENIZATION_FAILED,
                EfevooPay3dsResultClassifier::CATEGORY_DUPLICATE_REQUEST,
                EfevooPay3dsResultClassifier::CATEGORY_UNKNOWN,
            ])->map(fn (string $value) => ['value' => $value, 'label' => $value])->all(),
            'origins' => collect([
                EfevooPay3dsResultClassifier::ORIGIN_UNKNOWN,
                EfevooPay3dsResultClassifier::ORIGIN_NETWORK,
                EfevooPay3dsResultClassifier::ORIGIN_EFEVOOPAY,
                EfevooPay3dsResultClassifier::ORIGIN_ACS,
                EfevooPay3dsResultClassifier::ORIGIN_FAMEDIC,
                EfevooPay3dsResultClassifier::ORIGIN_USER,
                EfevooPay3dsResultClassifier::ORIGIN_ISSUER,
            ])->map(fn (string $value) => ['value' => $value, 'label' => $value])->all(),
            'certainties' => collect([
                EfevooPay3dsResultClassifier::CERTAINTY_UNKNOWN,
                EfevooPay3dsResultClassifier::CERTAINTY_PROBABLE,
                EfevooPay3dsResultClassifier::CERTAINTY_CONFIRMED,
            ])->map(fn (string $value) => ['value' => $value, 'label' => $value])->all(),
            'providers' => [
                ['value' => PaymentAuthenticationAttempt::PROVIDER_EFEVOOPAY, 'label' => 'EfevooPay'],
            ],
            'recovery_context_types' => collect(PaymentAuthenticationRecoveryContextType::cases())
                ->map(fn (PaymentAuthenticationRecoveryContextType $type) => [
                    'value' => $type->value,
                    'label' => $type->value,
                ])->values()->all(),
            'recovery_context_statuses' => collect(PaymentAuthenticationRecoveryContextStatus::cases())
                ->map(fn (PaymentAuthenticationRecoveryContextStatus $status) => [
                    'value' => $status->value,
                    'label' => $status->value,
                ])->values()->all(),
            'recovery_methods' => [
                ['value' => 'paypal', 'label' => 'PayPal'],
            ],
            'efevoopay_operation_filters' => [
                ['value' => 'multiple_get_link', 'label' => 'Más de un GetLink'],
                ['value' => 'multiple_token_card', 'label' => 'Más de un TokenCard'],
                ['value' => 'tokenization_confirmation_pending', 'label' => 'Tokenización en confirmación'],
                ['value' => 'possible_duplicate_operation', 'label' => 'Posible doble operación'],
                ['value' => 'excessive_get_status', 'label' => 'GetStatus excesivo'],
            ],
        ];
    }
}
