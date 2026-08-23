<?php

namespace App\Http\Controllers\Api;

use App\Contracts\EfevooPayGateway;
use App\Http\Controllers\Controller;
use App\Http\Requests\EfevooPay\ProcessPaymentRequest;
use App\Http\Requests\EfevooPay\RefundRequest;
use App\Http\Requests\EfevooPay\SearchTransactionsRequest;
use App\Http\Requests\EfevooPay\TokenizeCardRequest;
use App\Models\EfevooToken;
use App\Models\EfevooTransaction;
use App\Models\Transaction;
use App\Models\User;
use App\Support\EfevooPayLogSanitizer;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class EfevooPayController extends Controller
{
    public function __construct(
        protected EfevooPayGateway $efevooPayService
    ) {}

    public function healthCheck(): JsonResponse
    {
        try {
            $this->authorizeEfevooBackoffice(request()->user(), [
                'payment-attempts.manage',
                'efevoo-tokens.manage',
            ]);

            $result = $this->efevooPayService->healthCheck();

            return response()->json([
                'success' => $result['status'] === 'online',
                'data' => $result,
                'timestamp' => now()->toISOString(),
            ]);
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('EfevooPay Health Check Error', EfevooPayLogSanitizer::exception($e));

            return response()->json([
                'success' => false,
                'message' => 'Error al verificar estado de EfevooPay',
            ], 500);
        }
    }

    public function tokenizeCard(TokenizeCardRequest $request): JsonResponse
    {
        try {
            $user = $request->user();
            $customer = $this->authenticatedCustomer($user);
            $data = $request->validated();

            Log::warning('EfevooPay Tokenization Attempt', [
                'user_id' => $user->id,
                'customer_id' => $customer->id,
                'amount' => $data['amount'],
                'card_last_four' => substr($data['card_number'], -4),
            ]);

            $result = $this->efevooPayService->tokenizeCard($data, $customer->id);

            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'message' => 'Tarjeta tokenizada exitosamente',
                    'token_id' => $result['token_id'],
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => $result['message'],
                'errors' => $result['errors'] ?? [],
                'code' => $result['codigo'] ?? null,
            ], 400);
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('EfevooPay Tokenization Error', [
                'user_id' => $request->user()?->id,
                ...EfevooPayLogSanitizer::exception($e),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al tokenizar tarjeta',
            ], 500);
        }
    }

    public function processPayment(ProcessPaymentRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();
            $user = $request->user();
            $customer = $this->authenticatedCustomer($user);

            if (isset($data['token_id'])) {
                $token = EfevooToken::query()
                    ->where('customer_id', $customer->id)
                    ->active()
                    ->find($data['token_id']);

                if (! $token) {
                    return response()->json([
                        'success' => false,
                        'message' => 'Token de tarjeta no encontrado o no pertenece al usuario',
                    ], 404);
                }
            }

            $result = $this->efevooPayService->processPayment($data, $customer->id, $data['token_id'] ?? null);

            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'message' => 'Pago procesado exitosamente',
                    'transaction_id' => $result['transaction_id'] ?? null,
                    'reference' => $result['reference'] ?? null,
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => $result['message'],
                'code' => $result['codigo'] ?? null,
                'transaction_id' => $result['transaction_id'] ?? null,
            ], 400);
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('EfevooPay Payment Error', [
                'user_id' => $request->user()?->id,
                ...EfevooPayLogSanitizer::exception($e),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al procesar pago',
            ], 500);
        }
    }

    public function refund(RefundRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();
            $user = $request->user();
            $this->authorizeEfevooBackoffice($user, [
                'payment-attempts.manage',
                'laboratory-purchases.manage',
            ]);

            $transaction = EfevooTransaction::query()
                ->where('transaction_type', EfevooTransaction::TYPE_PAYMENT)
                ->find($data['transaction_id']);

            if (! $transaction) {
                return response()->json([
                    'success' => false,
                    'message' => 'Transaccion no encontrada',
                ], 404);
            }

            if (! $transaction->canBeRefunded()) {
                return response()->json([
                    'success' => false,
                    'message' => 'La transaccion no es reembolsable',
                ], 422);
            }

            if (isset($data['amount']) && (float) $data['amount'] > (float) $transaction->amount) {
                return response()->json([
                    'success' => false,
                    'message' => 'El monto de reembolso excede el monto de la transaccion',
                ], 422);
            }

            $result = $this->efevooPayService->refundTransaction($data['transaction_id']);

            if ($result['success']) {
                return response()->json([
                    'success' => true,
                    'message' => 'Reembolso procesado exitosamente',
                    'refund_id' => $result['refund_id'] ?? null,
                    'original_transaction_id' => $result['original_transaction_id'] ?? null,
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => $result['message'],
                'code' => $result['codigo'] ?? null,
            ], 400);
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('EfevooPay Refund Error', [
                'user_id' => $request->user()?->id,
                'transaction_id' => $request->input('transaction_id'),
                ...EfevooPayLogSanitizer::exception($e),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al procesar reembolso',
            ], 500);
        }
    }

    public function searchTransactions(SearchTransactionsRequest $request): JsonResponse
    {
        try {
            $data = $request->validated();
            $this->authorizeEfevooBackoffice($request->user(), [
                'payment-attempts.manage',
                'laboratory-purchases.manage',
            ]);
            $this->localEfevooTransactionOrFail((string) $data['transaction_id']);

            $result = $this->efevooPayService->searchTransactions([
                'transaction_id' => $data['transaction_id'],
            ]);

            if ($result['success']) {
                $transactions = collect($result['data']['data'] ?? [])
                    ->map(fn ($transaction) => EfevooPayLogSanitizer::providerResult(['data' => $transaction]))
                    ->values();

                return response()->json([
                    'success' => true,
                    'count' => $transactions->count(),
                    'transactions' => $transactions,
                ]);
            }

            return response()->json([
                'success' => false,
                'message' => $result['message'],
                'code' => $result['codigo'] ?? null,
            ], 400);
        } catch (ModelNotFoundException) {
            return response()->json([
                'success' => false,
                'message' => 'Transaccion local no encontrada',
            ], 404);
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('EfevooPay Search Transactions Error', [
                'user_id' => $request->user()?->id,
                'transaction_id' => $request->input('transaction_id'),
                ...EfevooPayLogSanitizer::exception($e),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al buscar transacciones',
            ], 500);
        }
    }

    public function getUserTokens(): JsonResponse
    {
        try {
            $user = request()->user();
            $customer = $this->authenticatedCustomer($user);

            $tokens = EfevooToken::query()
                ->where('customer_id', $customer->id)
                ->active()
                ->with(['transactions' => function ($query) {
                    $query->latest()->take(5);
                }])
                ->get()
                ->map(function (EfevooToken $token) {
                    return [
                        'id' => $token->id,
                        'card_last_four' => $token->card_last_four,
                        'card_brand' => $token->card_brand,
                        'card_expiration' => $token->card_expiration,
                        'is_active' => $token->is_active,
                        'expires_at' => $token->expires_at,
                        'created_at' => $token->created_at,
                        'recent_transactions' => $token->transactions->map(function (EfevooTransaction $transaction) {
                            return [
                                'id' => $transaction->id,
                                'amount' => $transaction->amount,
                                'status' => $transaction->status,
                                'reference' => $transaction->reference,
                                'created_at' => $transaction->created_at,
                            ];
                        }),
                    ];
                });

            return response()->json([
                'success' => true,
                'count' => $tokens->count(),
                'tokens' => $tokens,
            ]);
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('EfevooPay Get User Tokens Error', [
                'user_id' => request()->user()?->id,
                ...EfevooPayLogSanitizer::exception($e),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al obtener tokens',
            ], 500);
        }
    }

    public function deleteToken($tokenId): JsonResponse
    {
        try {
            $user = request()->user();
            $customer = $this->authenticatedCustomer($user);

            $token = EfevooToken::query()
                ->where('customer_id', $customer->id)
                ->find($tokenId);

            if (! $token) {
                return response()->json([
                    'success' => false,
                    'message' => 'Token no encontrado',
                ], 404);
            }

            $token->update(['is_active' => false]);

            return response()->json([
                'success' => true,
                'message' => 'Token desactivado exitosamente',
            ]);
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (\Exception $e) {
            Log::error('EfevooPay Delete Token Error', [
                'user_id' => request()->user()?->id,
                'token_id' => $tokenId,
                ...EfevooPayLogSanitizer::exception($e),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Error al eliminar token',
            ], 500);
        }
    }

    private function authenticatedCustomer(?User $user)
    {
        $customer = $user?->customer;

        abort_unless($customer, 403, 'La cuenta autenticada no tiene cliente asociado');

        return $customer;
    }

    private function authorizeEfevooBackoffice(?User $user, array $permissions): void
    {
        abort_unless($user?->administrator?->hasAnyPermission($permissions), 403);
    }

    private function localEfevooTransactionOrFail(string $providerTransactionId): Transaction
    {
        return Transaction::query()
            ->where(function ($query) {
                $query->where('payment_method', 'efevoopay')
                    ->orWhere('gateway', 'efevoopay')
                    ->orWhere('payment_provider', 'efevoopay');
            })
            ->where(function ($query) use ($providerTransactionId) {
                $query->where('gateway_transaction_id', $providerTransactionId)
                    ->orWhere('provider_transaction_id', $providerTransactionId)
                    ->orWhere('reference_id', $providerTransactionId);
            })
            ->firstOrFail();
    }
}
