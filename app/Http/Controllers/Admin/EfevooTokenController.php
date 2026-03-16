<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\EfevooToken;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Inertia\Inertia;

class EfevooTokenController extends Controller
{
    public function index(Request $request)
    {
        $filters = collect($request->only([
            'search',
            'environment',
            'status',
            'customer_id',
        ]))->filter()->all();

        $query = EfevooToken::with(['customer.user'])
            ->when($filters['search'] ?? null, function (Builder $query, string $search) {
                $query->where(function (Builder $query) use ($search) {
                    $query->where('alias', 'like', '%' . $search . '%')
                        ->orWhere('card_last_four', 'like', '%' . $search . '%')
                        ->orWhere('card_holder', 'like', '%' . $search . '%')
                        ->orWhere('card_brand', 'like', '%' . $search . '%')
                        ->orWhere('client_token', 'like', '%' . $search . '%')
                        ->orWhereHas('customer.user', function (Builder $query) use ($search) {
                            $query->where('name', 'like', '%' . $search . '%')
                                ->orWhere('paternal_lastname', 'like', '%' . $search . '%')
                                ->orWhere('maternal_lastname', 'like', '%' . $search . '%')
                                ->orWhere('email', 'like', '%' . $search . '%');
                        });
                });
            })
            ->when($filters['environment'] ?? null, function (Builder $query, string $environment) {
                if (in_array($environment, ['test', 'production'], true)) {
                    $query->where('environment', $environment);
                }
            })
            ->when($filters['status'] ?? null, function (Builder $query, string $status) {
                if ($status === 'active') {
                    $query->where('is_active', true)
                        ->where(function (Builder $query) {
                            $query->whereNull('expires_at')
                                ->orWhere('expires_at', '>', now());
                        });
                } elseif ($status === 'inactive') {
                    $query->where(function (Builder $query) {
                        $query->where('is_active', false)
                            ->orWhere(function (Builder $query) {
                                $query->whereNotNull('expires_at')
                                    ->where('expires_at', '<=', now());
                            });
                    });
                }
            })
            ->when($filters['customer_id'] ?? null, function (Builder $query, $customerId) {
                $query->where('customer_id', $customerId);
            })
            ->orderByDesc('created_at');

        $tokens = $query->paginate(25)->withQueryString();

        $tokens->getCollection()->transform(function (EfevooToken $token) {
            $token->formatted_environment = $token->environment === 'production' ? 'Producción' : 'Pruebas';
            $token->is_expired = $token->isExpired();

            return $token;
        });

        return Inertia::render('Admin/EfevooTokens', [
            'tokens' => $tokens,
            'filters' => $filters,
        ]);
    }

    public function show(EfevooToken $efevooToken)
    {
        $efevooToken->load(['customer.user', 'transactions' => function ($query) {
            $query->latest()->limit(20);
        }]);

        $efevooToken->formatted_environment = $efevooToken->environment === 'production' ? 'Producción' : 'Pruebas';
        $efevooToken->is_expired = $efevooToken->isExpired();

        return Inertia::render('Admin/EfevooToken', [
            'token' => $efevooToken,
        ]);
    }
}

