<?php

namespace App\Support;

use App\Enums\LaboratoryBrand;
use Illuminate\Http\Request;

class ZohoSalesIq
{
    public static function isEnabled(): bool
    {
        if (! (bool) config('services.zoho.salesiq.enabled')) {
            return false;
        }

        return static::widgetUrl() !== null;
    }

    public static function widgetUrl(): ?string
    {
        $url = config('services.zoho.salesiq.widget_url');

        if (is_string($url) && trim($url) !== '') {
            return trim($url);
        }

        $code = config('services.zoho.salesiq.widget_code');

        if (is_string($code) && trim($code) !== '') {
            return 'https://salesiq.zohopublic.com/widget?wc='.trim($code);
        }

        return null;
    }

    /**
     * Configuración segura para exponer en el frontend (sin secretos).
     *
     * @return array<string, mixed>
     */
    public static function frontendConfig(): array
    {
        return [
            'enabled' => static::isEnabled(),
            'widgetUrl' => static::widgetUrl(),
            'env' => static::environmentLabel(),
            'floatPosition' => 'left',
        ];
    }

    /**
     * Contexto de visitante autorizado para identificación en SalesIQ.
     *
     * @param  array<string, mixed>|null  $laboratoryCarts  Carritos ya cargados (p. ej. desde HandleInertiaRequests).
     * @return array<string, mixed>
     */
    public static function visitorContext(Request $request, ?array $laboratoryCarts = null): array
    {
        $context = [
            'page' => '/'.ltrim($request->path(), '/'),
            'route' => $request->route()?->getName(),
            'env' => static::environmentLabel(),
            'membershipActive' => false,
        ];

        $user = $request->user();

        if (! $user) {
            return $context;
        }

        $customer = $user->customer;
        $name = trim((string) ($user->full_name ?? ''));

        if ($name === '') {
            $name = trim(((string) ($user->name ?? '')).' '.((string) ($user->last_name ?? '')));
        }

        $phone = $user->full_phone ?? $user->phone;

        $context = [
            ...$context,
            'userId' => $user->id,
            'customerId' => $customer?->id,
            'name' => $name !== '' ? $name : null,
            'email' => $user->email,
            'phone' => $phone ? (string) $phone : null,
            'membershipActive' => (bool) ($customer?->medical_attention_subscription_is_active),
            'cart' => static::laboratoryCartSummary($user, $laboratoryCarts),
        ];

        return $context;
    }

    public static function environmentLabel(): string
    {
        $configured = config('services.zoho.salesiq.env');

        if (is_string($configured) && trim($configured) !== '') {
            return trim($configured);
        }

        return (string) app()->environment();
    }

    /**
     * Secreto de webhooks (solo backend). Nunca incluir en frontendConfig().
     */
    public static function webhookSecret(): ?string
    {
        $secret = config('services.zoho.salesiq.webhook_secret');

        if (! is_string($secret) || trim($secret) === '') {
            return null;
        }

        return trim($secret);
    }

    /**
     * @param  array<string, mixed>|null  $laboratoryCarts
     * @return array{itemCount: int, brands: list<string>}
     */
    protected static function laboratoryCartSummary($user, ?array $laboratoryCarts = null): array
    {
        if ($laboratoryCarts !== null) {
            return static::summarizeLaboratoryCarts($laboratoryCarts);
        }

        if (! $user->customer) {
            return [
                'itemCount' => 0,
                'brands' => [],
            ];
        }

        /** @var array<string, mixed> $byBrand */
        $byBrand = collect(LaboratoryBrand::cases())
            ->mapWithKeys(fn (LaboratoryBrand $brand) => [
                $brand->value => $user->customer
                    ->laboratoryCartItems()
                    ->ofBrand($brand)
                    ->get(),
            ]);

        return static::summarizeLaboratoryCarts($byBrand->all());
    }

    /**
     * @param  array<string, mixed>  $laboratoryCarts
     * @return array{itemCount: int, brands: list<string>}
     */
    protected static function summarizeLaboratoryCarts(array $laboratoryCarts): array
    {
        $brandsWithItems = [];
        $itemCount = 0;

        foreach ($laboratoryCarts as $brand => $items) {
            $count = is_countable($items) ? count($items) : 0;

            if ($count > 0) {
                $brandsWithItems[] = (string) $brand;
                $itemCount += $count;
            }
        }

        return [
            'itemCount' => $itemCount,
            'brands' => $brandsWithItems,
        ];
    }
}
