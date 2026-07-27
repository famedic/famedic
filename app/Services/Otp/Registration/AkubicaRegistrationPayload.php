<?php

namespace App\Services\Otp\Registration;

/**
 * Versioned registration payload (plaintext only in memory / Crypt blob).
 * Never JsonSerializable with PII; never log via __toString.
 */
final readonly class AkubicaRegistrationPayload
{
    public const VERSION = 1;

    /**
     * @var list<string>
     */
    private const ALLOWED_KEYS = ['v', 'email', 'phone', 'phone_country', 'full_name'];

    public function __construct(
        public NormalizedEmail $email,
        public PhoneIdentity $phone,
        public string $fullName,
        public int $payloadVersion = self::VERSION,
    ) {
        if ($payloadVersion !== self::VERSION) {
            throw new \InvalidArgumentException('Unsupported registration payload version.');
        }

        if (trim($fullName) === '' || mb_strlen($fullName) < 3) {
            throw new \InvalidArgumentException('Invalid registration full name.');
        }
    }

    public static function fromIdentity(RegistrationIdentity $identity, int $version = self::VERSION): self
    {
        return new self(
            email: $identity->email,
            phone: $identity->phone,
            fullName: trim($identity->fullName),
            payloadVersion: $version,
        );
    }

    /**
     * Strict reconstruction after decrypt. Rejects unknown/missing keys.
     *
     * @param  array<string, mixed>  $data
     */
    public static function fromDecryptedArray(array $data): self
    {
        foreach (array_keys($data) as $key) {
            if (! in_array($key, self::ALLOWED_KEYS, true)) {
                throw new \InvalidArgumentException('Unknown registration payload field.');
            }
        }

        foreach (self::ALLOWED_KEYS as $required) {
            if (! array_key_exists($required, $data)) {
                throw new \InvalidArgumentException('Missing registration payload field.');
            }
        }

        $version = (int) $data['v'];
        if ($version !== self::VERSION) {
            throw new \InvalidArgumentException('Unsupported registration payload version.');
        }

        $email = app(EmailNormalizer::class)->normalize((string) $data['email']);
        $phone = app(MexicoPhoneNormalizer::class)->normalize(
            (string) $data['phone'],
            (string) $data['phone_country'],
        );

        // Re-normalization must match stored national/country exactly (no silent rewrite).
        if ($phone->nationalNumber() !== (string) $data['phone']
            || $phone->countryCode() !== strtoupper((string) $data['phone_country'])
            || $email->value() !== (string) $data['email']
        ) {
            throw new \InvalidArgumentException('Registration payload identity mismatch.');
        }

        return new self(
            email: $email,
            phone: $phone,
            fullName: trim((string) $data['full_name']),
            payloadVersion: $version,
        );
    }

    /**
     * Deterministic array for encryption (no extra keys).
     *
     * @return array{v: int, email: string, phone: string, phone_country: string, full_name: string}
     */
    public function toEncryptableArray(): array
    {
        return [
            'v' => $this->payloadVersion,
            'email' => $this->email->value(),
            'phone' => $this->phone->nationalNumber(),
            'phone_country' => $this->phone->countryCode(),
            'full_name' => $this->fullName,
        ];
    }

    public function toRegistrationIdentity(): RegistrationIdentity
    {
        return new RegistrationIdentity(
            email: $this->email,
            phone: $this->phone,
            fullName: $this->fullName,
        );
    }

    public function __toString(): string
    {
        return '[akubica-registration-payload:v'.$this->payloadVersion.']';
    }
}
