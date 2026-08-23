<?php

namespace App\Support;

use App\Enums\PaymentAuthenticationRecoveryContextType;
use App\Models\Customer;
use App\Models\PaymentAuthenticationRecoveryContext;
use Illuminate\Http\RedirectResponse;

class PaymentAuthenticationRecoveryReturnBuilder
{
    public function __construct(
        private PaymentAuthenticationRecoveryContextGuard $guard
    ) {}

    /**
     * @return array{route_name: string, params: array<string, mixed>, href: string}
     */
    public function action(Customer $customer, PaymentAuthenticationRecoveryContext $context): array
    {
        $type = $this->type($context);

        if (! $type || ! $this->guard->isUsableForReturn($customer, $context)) {
            return $this->fallbackAction($type);
        }

        $routeName = $context->return_route_name ?: $type->returnRouteName();

        if (! PaymentAuthenticationRecoveryReturnRouteAllowlist::isAllowed($routeName)) {
            return $this->fallbackAction($type);
        }

        $params = $this->routeParams($context);

        return [
            'route_name' => $routeName,
            'params' => $params,
            'href' => route($routeName, $params, false),
        ];
    }

    public function href(Customer $customer, PaymentAuthenticationRecoveryContext $context): string
    {
        return $this->action($customer, $context)['href'];
    }

    public function redirect(Customer $customer, PaymentAuthenticationRecoveryContext $context, ?string $successMessage = null): RedirectResponse
    {
        $action = $this->action($customer, $context);
        $redirect = redirect()->to($action['href']);

        if ($successMessage) {
            $redirect->with('success', $successMessage);
        }

        if (! $this->guard->isUsableForReturn($customer, $context)) {
            $redirect->with('warning', 'Tu sesion de pago ya no es valida. Vuelve a iniciar el checkout.');
        }

        return $redirect;
    }

    /**
     * @return array<string, mixed>
     */
    public function routeParams(PaymentAuthenticationRecoveryContext $context): array
    {
        $type = $this->type($context);
        $data = $context->allowlistedContextData();

        return match ($type) {
            PaymentAuthenticationRecoveryContextType::LaboratoryCheckout => array_filter([
                'laboratory_brand' => $data['laboratory_brand'] ?? null,
                'step' => $data['step'] ?? 'payment',
                'contact' => $data['contact_id'] ?? null,
                'address' => $data['address_id'] ?? null,
                'coupon_id' => $data['coupon_id'] ?? null,
            ], fn ($value) => $value !== null && $value !== ''),
            PaymentAuthenticationRecoveryContextType::MedicalAttentionCheckout => array_filter([
                'step' => $data['step'] ?? null,
            ], fn ($value) => $value !== null && $value !== ''),
            PaymentAuthenticationRecoveryContextType::OnlinePharmacyCheckout => array_filter([
                'contact' => $data['contact_id'] ?? null,
                'address' => $data['address_id'] ?? null,
                'step' => $data['step'] ?? null,
            ], fn ($value) => $value !== null && $value !== ''),
            default => [],
        };
    }

    /**
     * @return array{route_name: string, params: array<string, mixed>, href: string}
     */
    private function fallbackAction(?PaymentAuthenticationRecoveryContextType $type): array
    {
        $routeName = $type?->fallbackRouteName() ?? 'payment-methods.index';

        if (! \Illuminate\Support\Facades\Route::has($routeName)) {
            $routeName = 'payment-methods.index';
        }

        return [
            'route_name' => $routeName,
            'params' => [],
            'href' => route($routeName, [], false),
        ];
    }

    private function type(PaymentAuthenticationRecoveryContext $context): ?PaymentAuthenticationRecoveryContextType
    {
        return $context->context_type instanceof PaymentAuthenticationRecoveryContextType
            ? $context->context_type
            : PaymentAuthenticationRecoveryContextType::tryFrom((string) $context->context_type);
    }
}
