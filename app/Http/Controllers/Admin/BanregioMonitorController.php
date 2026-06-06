<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PaymentAttempt;
use App\Models\PaymentMethod;
use App\Models\PaymentTransaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;

class BanregioMonitorController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeBanregio();

        $provider = config('heybanco.provider_key');
        $tab = $request->input('tab', 'tokens');
        if (! in_array($tab, ['tokens', 'transactions', 'attempts'], true)) {
            $tab = 'tokens';
        }

        $filters = collect($request->only([
            'search',
            'status',
            'flow',
        ]))->filter()->all();

        $summary = [
            'tokens_total' => PaymentMethod::forProvider($provider)->count(),
            'tokens_active' => PaymentMethod::forProvider($provider)->where('status', 'active')->count(),
            'transactions_total' => PaymentTransaction::where('provider', $provider)->count(),
            'attempts_total' => PaymentAttempt::where('gateway', $provider)->count(),
            'environment' => config('heybanco.env'),
            'mode' => config('heybanco.mode'),
            'enabled' => (bool) config('heybanco.enabled'),
            'adq_url' => config('heybanco.adq_url'),
        ];

        $tokens = null;
        $transactions = null;
        $attempts = null;

        if ($tab === 'transactions') {
            $transactions = $this->transactionsQuery($provider, $filters)
                ->paginate(25)
                ->withQueryString();
        } elseif ($tab === 'attempts') {
            $attempts = $this->attemptsQuery($provider, $filters)
                ->paginate(25)
                ->withQueryString();
        } else {
            $tokens = $this->tokensQuery($provider, $filters)
                ->paginate(25)
                ->withQueryString();

            $tokens->getCollection()->transform(function (PaymentMethod $method) {
                $method->customer = $method->user?->customer;
                $method->is_expired = $method->isExpired();

                return $method;
            });
        }

        return Inertia::render('Admin/BanregioMonitor', [
            'tab' => $tab,
            'filters' => $filters,
            'summary' => $summary,
            'tokens' => $tokens,
            'transactions' => $transactions,
            'attempts' => $attempts,
            'flowOptions' => $this->flowOptions($provider),
            'statusOptions' => $this->statusOptions($tab, $provider),
        ]);
    }

    private function authorizeBanregio(): void
    {
        request()->user()->administrator->hasPermissionTo('banregio.manage') || abort(403);
    }

    /**
     * @param  array<string, string>  $filters
     */
    private function tokensQuery(string $provider, array $filters): Builder
    {
        return PaymentMethod::query()
            ->forProvider($provider)
            ->with(['user.customer'])
            ->when($filters['search'] ?? null, function (Builder $query, string $search) {
                $query->where(function (Builder $query) use ($search) {
                    $query->where('alias', 'like', '%' . $search . '%')
                        ->orWhere('last4', 'like', '%' . $search . '%')
                        ->orWhere('card_holder', 'like', '%' . $search . '%')
                        ->orWhere('brand', 'like', '%' . $search . '%')
                        ->orWhere('media_id', 'like', '%' . $search . '%')
                        ->orWhereHas('user', function (Builder $query) use ($search) {
                            $query->where('name', 'like', '%' . $search . '%')
                                ->orWhere('paternal_lastname', 'like', '%' . $search . '%')
                                ->orWhere('maternal_lastname', 'like', '%' . $search . '%')
                                ->orWhere('email', 'like', '%' . $search . '%');
                        });
                });
            })
            ->when($filters['status'] ?? null, function (Builder $query, string $status) {
                if ($status === 'active') {
                    $query->where('status', 'active');
                } elseif ($status === 'inactive') {
                    $query->where('status', '!=', 'active');
                }
            })
            ->orderByDesc('created_at');
    }

    /**
     * @param  array<string, string>  $filters
     */
    private function transactionsQuery(string $provider, array $filters): Builder
    {
        return PaymentTransaction::query()
            ->where('provider', $provider)
            ->with(['user.customer', 'paymentMethod'])
            ->when($filters['search'] ?? null, function (Builder $query, string $search) {
                $query->where(function (Builder $query) use ($search) {
                    $query->where('reference', 'like', '%' . $search . '%')
                        ->orWhere('folio', 'like', '%' . $search . '%')
                        ->orWhere('auth_code', 'like', '%' . $search . '%')
                        ->orWhere('bnrg_texto', 'like', '%' . $search . '%')
                        ->orWhereHas('user', function (Builder $query) use ($search) {
                            $query->where('email', 'like', '%' . $search . '%')
                                ->orWhere('name', 'like', '%' . $search . '%');
                        });
                });
            })
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->when($filters['flow'] ?? null, fn (Builder $query, string $flow) => $query->where('flow', $flow))
            ->orderByDesc('created_at');
    }

    /**
     * @param  array<string, string>  $filters
     */
    private function attemptsQuery(string $provider, array $filters): Builder
    {
        return PaymentAttempt::query()
            ->where('gateway', $provider)
            ->with(['customer.user'])
            ->when($filters['search'] ?? null, function (Builder $query, string $search) {
                $query->where(function (Builder $query) use ($search) {
                    $query->where('reference', 'like', '%' . $search . '%')
                        ->orWhere('processor_transaction_id', 'like', '%' . $search . '%')
                        ->orWhere('processor_code', 'like', '%' . $search . '%')
                        ->orWhere('processor_message', 'like', '%' . $search . '%');
                })->orWhereHas('customer.user', function (Builder $query) use ($search) {
                    $query->where('email', 'like', '%' . $search . '%')
                        ->orWhere('name', 'like', '%' . $search . '%');
                });
            })
            ->when($filters['status'] ?? null, fn (Builder $query, string $status) => $query->where('status', $status))
            ->orderByDesc('processed_at')
            ->orderByDesc('created_at');
    }

    /**
     * @return list<string>
     */
    private function flowOptions(string $provider): array
    {
        return PaymentTransaction::query()
            ->where('provider', $provider)
            ->whereNotNull('flow')
            ->distinct()
            ->orderBy('flow')
            ->pluck('flow')
            ->all();
    }

    /**
     * @return list<string>
     */
    private function statusOptions(string $tab, string $provider): array
    {
        if ($tab === 'attempts') {
            return PaymentAttempt::query()
                ->where('gateway', $provider)
                ->whereNotNull('status')
                ->distinct()
                ->orderBy('status')
                ->pluck('status')
                ->all();
        }

        if ($tab === 'transactions') {
            return PaymentTransaction::query()
                ->where('provider', $provider)
                ->whereNotNull('status')
                ->distinct()
                ->orderBy('status')
                ->pluck('status')
                ->all();
        }

        return ['active', 'inactive'];
    }
}
