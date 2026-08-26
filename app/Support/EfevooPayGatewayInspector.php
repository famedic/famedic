<?php

namespace App\Support;

use App\Services\EfevooPay\MockEfevooPayGateway;
use App\Services\EfevooPayService;
use Illuminate\Support\Facades\URL;

class EfevooPayGatewayInspector
{
    private const REQUIRED_CREDENTIAL_KEYS = [
        'api_url',
        'api_user',
        'api_key',
        'clave',
        'vector',
        'cliente',
        'totp_secret',
        'fiid_comercio',
    ];

    /**
     * @return array<string, mixed>
     */
    public static function inspect(): array
    {
        $mode = EfevooPayGatewayMode::current();
        $gatewayClass = self::resolveGatewayClassName();
        $apiUrl = (string) config('efevoopay.api_url', '');
        $hostname = self::extractHostname($apiUrl);
        $verification = PaymentAuthenticationEfevooPayAmounts::validateConfiguredAmounts();

        return [
            'app_env' => app()->environment(),
            'gateway_mode' => $mode,
            'uses_mock' => EfevooPayGatewayMode::usesMock(),
            'uses_http_gateway' => EfevooPayGatewayMode::usesHttpGateway(),
            'gateway_class' => $gatewayClass,
            'efevoo_environment' => (string) config('efevoopay.environment'),
            'api_hostname' => $hostname,
            'verify_ssl' => (bool) config('efevoopay.verify_ssl', false),
            'force_simulation' => filter_var(config('efevoopay.force_simulation', false), FILTER_VALIDATE_BOOLEAN),
            'credentials' => self::credentialPresence(),
            'database' => [
                'connection' => (string) config('database.default'),
                'host' => (string) config('database.connections.'.config('database.default').'.host'),
                'database' => (string) config('database.connections.'.config('database.default').'.database'),
            ],
            'cache_store' => (string) config('cache.default'),
            'queue_connection' => (string) config('queue.default'),
            'local_real_tests' => [
                'enabled' => EfevooPayLocalRealTestMode::enabled(),
                'active_for_current_user' => EfevooPayLocalRealTestMode::activeFor(),
                'blocks_external_integrations' => EfevooPayLocalRealTestMode::blocksExternalIntegrations(),
                'allowed_user_configured' => self::allowedTestUserConfigured(),
            ],
            'limits' => [
                'getlink_amount_mxn' => PaymentAuthenticationEfevooPayAmounts::threeDsVerificationAmount(),
                'tokenize_amount_mxn' => PaymentAuthenticationEfevooPayAmounts::tokenizationVerificationAmount(),
                'max_verification_total_mxn' => PaymentAuthenticationEfevooPayAmounts::centsToDecimal(
                    EfevooPayLocalRealTestMode::maxCardVerificationTotalCents()
                ),
                'max_payment_mxn' => PaymentAuthenticationEfevooPayAmounts::centsToDecimal(
                    EfevooPayLocalRealTestMode::maxPaymentAmountCents()
                ),
                'verification_amounts_valid' => $verification['allowed'],
                'verification_amounts_reason' => $verification['reason'],
            ],
            'https' => [
                'app_url' => URL::to('/'),
                'session_secure_cookie' => config('session.secure'),
                'session_domain' => config('session.domain'),
                'trusted_proxies_configured' => filled(config('app.trusted_proxies')),
            ],
            'harness_available' => EfevooPayGatewayMode::usesMock() && app()->environment(['local', 'testing']),
        ];
    }

    public static function resolveGatewayClassName(): string
    {
        if (EfevooPayGatewayMode::usesMock()) {
            return MockEfevooPayGateway::class;
        }

        return EfevooPayService::class;
    }

    /**
     * @return array<string, bool>
     */
    public static function credentialPresence(): array
    {
        $presence = [];

        foreach (self::REQUIRED_CREDENTIAL_KEYS as $key) {
            $presence[$key] = filled(config('efevoopay.'.$key));
        }

        $presence['all_required_present'] = ! in_array(false, $presence, true);

        return $presence;
    }

    private static function allowedTestUserConfigured(): bool
    {
        $allowedUserId = (int) config('efevoopay.local_real_tests.allowed_user_id', 0);
        $allowedEmail = trim((string) config('efevoopay.local_real_tests.allowed_user_email', ''));

        return $allowedUserId > 0 || $allowedEmail !== '';
    }

    private static function extractHostname(string $apiUrl): ?string
    {
        if ($apiUrl === '') {
            return null;
        }

        $parts = parse_url($apiUrl);

        return is_array($parts) ? ($parts['host'] ?? null) : null;
    }
}
