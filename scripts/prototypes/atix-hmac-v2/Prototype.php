<?php
/** LOCAL EXPERIMENT ONLY. No Composer registration, routes, secrets or PAT issuance. */
declare(strict_types=1);
namespace Famedic\LocalPrototype\AtixV2;

final class Denied extends \RuntimeException
{
    public function __construct(public readonly string $reason) { parent::__construct($reason); }
}

final readonly class ProofRequest
{
    public function __construct(
        public string $audience, public string $keyId, public string $method,
        public string $path, public string $query, public string $timestamp,
        public string $nonce, public string $channel, public ?string $pat,
        public ?string $idempotencyKey, public string $body,
    ) {}

    public function canonical(): string
    {
        foreach ([$this->audience, $this->keyId, $this->channel] as $value) {
            if (!preg_match('/\A[A-Za-z0-9][A-Za-z0-9._:-]{0,127}\z/D', $value)) {
                throw new Denied('V2_MALFORMED');
            }
        }
        if (!preg_match('/\A(?:GET|POST|PUT|PATCH|DELETE|HEAD|OPTIONS)\z/D', strtoupper($this->method))
            || !preg_match('/\A(?:0|[1-9][0-9]{0,9})\z/D', $this->timestamp)
            || !preg_match('/\A[A-Za-z0-9._-]{8,128}\z/D', $this->nonce)) {
            throw new Denied('V2_MALFORMED');
        }
        if ($this->path === '' || strlen($this->path) > 2048 || $this->path[0] !== '/'
            || preg_match('/[^\x21-\x7e]|[?#\\\\]/', $this->path)
            || str_contains($this->path, '//') || preg_match('/%(?![0-9A-F]{2})/', $this->path)
            || preg_match('/%(?:2F|5C|25)/i', $this->path)) {
            throw new Denied('V2_AMBIGUOUS_TARGET');
        }
        $decoded = rawurldecode($this->path);
        if (preg_match('/[\x00-\x20\x7f]/', $decoded)
            || preg_match('#(?:^|/)\.{1,2}(?:/|$)#', $decoded)) {
            throw new Denied('V2_AMBIGUOUS_TARGET');
        }
        if (strlen($this->query) > 4096 || preg_match('/[^\x21-\x7e]|#/', $this->query)
            || preg_match('/%(?![0-9A-Fa-f]{2})/', $this->query)) {
            throw new Denied('V2_AMBIGUOUS_QUERY');
        }
        $seen = [];
        foreach ($this->query === '' ? [] : explode('&', $this->query) as $part) {
            $name = urldecode(explode('=', $part, 2)[0]);
            if (!preg_match('/\A[A-Za-z_][A-Za-z0-9_-]*\z/D', $name)
                || $name === '_method' || isset($seen[$name])) {
                throw new Denied('V2_AMBIGUOUS_QUERY');
            }
            $seen[$name] = true;
        }
        if ($this->pat !== null && !preg_match('/\A[A-Za-z0-9|_-]{1,256}\z/D', $this->pat)) {
            throw new Denied('V2_MALFORMED_PAT');
        }
        if ($this->idempotencyKey !== null
            && !preg_match('/\A[A-Za-z0-9._-]{8,128}\z/D', $this->idempotencyKey)) {
            throw new Denied('V2_MALFORMED_IDEMPOTENCY');
        }
        if (strlen($this->body) > 1048576) { throw new Denied('V2_BODY_TOO_LARGE'); }
        return implode("\n", [
            'ATIX-API-V2', $this->audience, $this->keyId, strtoupper($this->method),
            $this->path, hash('sha256', $this->query), $this->timestamp, $this->nonce,
            $this->channel, $this->pat === null ? '-' : hash('sha256', $this->pat),
            $this->idempotencyKey === null ? '-' : hash('sha256', $this->idempotencyKey),
            hash('sha256', $this->body),
        ]);
    }
}

interface Nonces
{
    /** Atomic claim; false means duplicate. Storage failure MUST throw. */
    public function claim(string $namespace, string $nonceHash, int $now, int $expiresAt): bool;
}

final class SqliteNonces implements Nonces
{
    private \PDO $db;
    public function __construct(string $path, bool $readOnlyFault = false)
    {
        // Only the runner's fresh temporary directory, never a configured app DB.
        $runtime = getenv('FAMEDIC_V2_EPHEMERAL');
        $directory = realpath(dirname($path));
        if (!$runtime || realpath($runtime) !== $directory || basename($path) !== 'nonces.sqlite'
            || is_link($path) || (file_exists($path) && !is_file($path))) {
            throw new Denied('V2_UNSAFE_LOCAL_STORAGE');
        }
        $this->db = new \PDO('sqlite:'.$path, null, null, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        $this->db->exec('PRAGMA busy_timeout=5000');
        $this->db->exec('CREATE TABLE IF NOT EXISTS prototype_nonces (namespace TEXT NOT NULL, nonce_hash TEXT NOT NULL, expires_at INTEGER NOT NULL, PRIMARY KEY(namespace, nonce_hash))');
        if ($readOnlyFault) { $this->db->exec('PRAGMA query_only=ON'); }
    }
    public function claim(string $namespace, string $nonceHash, int $now, int $expiresAt): bool
    {
        $transaction = false;
        try {
            $this->db->exec('BEGIN IMMEDIATE'); $transaction = true;
            $delete = $this->db->prepare('DELETE FROM prototype_nonces WHERE namespace=? AND nonce_hash=? AND expires_at < ?');
            $delete->execute([$namespace, $nonceHash, $now]);
            $insert = $this->db->prepare('INSERT INTO prototype_nonces VALUES (?, ?, ?) ON CONFLICT(namespace,nonce_hash) DO NOTHING');
            $insert->execute([$namespace, $nonceHash, $expiresAt]);
            $claimed = $insert->rowCount() === 1;
            $this->db->exec('COMMIT'); $transaction = false;
            return $claimed;
        } catch (\Throwable $e) {
            if ($transaction) { try { $this->db->exec('ROLLBACK'); } catch (\Throwable) {} }
            throw $e;
        }
    }
}

final readonly class IntegrationProof
{
    /** Does NOT authenticate a patient, construct an app consumer or grant a capability. */
    public function __construct(
        public string $integration, public string $kind, public string $channel,
        public string $audience, public string $patDigest, public string $requestDigest,
        public int $nonceExpiresAt,
    ) {}
}

final class Verifier
{
    public function __construct(private readonly array $registry, private readonly Nonces $nonces,
        private readonly int $tolerance = 300, private readonly int $margin = 5)
    {
        if ($tolerance < 1 || $tolerance > 900 || $margin < 1 || $margin > 60) {
            throw new Denied('V2_INVALID_CONFIG');
        }
        $secrets = [];
        foreach ($registry as $key => $credential) {
            if (!is_string($key) || !is_string($credential['secret'] ?? null)
                || strlen($credential['secret']) < 32) { throw new Denied('V2_INVALID_CONFIG'); }
            $digest = hash('sha256', $credential['secret']);
            if (isset($secrets[$digest])) { throw new Denied('V2_SHARED_SECRET_FORBIDDEN'); }
            $secrets[$digest] = true;
        }
    }
    public function verify(ProofRequest $request, string $signature, string $targetAudience,
        string $profile, int $now, bool $requirePat): IntegrationProof
    {
        $canonical = $request->canonical();
        $key = $this->registry[$request->keyId] ?? null;
        if ($profile !== 'delegated-v2' || $key === null || ($key['profile'] ?? '') !== $profile
            || !($key['active'] ?? false)) { throw new Denied('V2_CREDENTIAL_REJECTED'); }
        if ($request->audience !== $targetAudience || ($key['audience'] ?? '') !== $targetAudience
            || ($key['channel'] ?? '') !== $request->channel) { throw new Denied('V2_CONTEXT_REJECTED'); }
        if (!preg_match('/\A[a-f0-9]{64}\z/D', $signature)
            || !hash_equals(hash_hmac('sha256', $canonical, $key['secret']), $signature)) {
            throw new Denied('V2_SIGNATURE_INVALID');
        }
        if ($now < 0 || abs($now - (int)$request->timestamp) > $this->tolerance) {
            throw new Denied('V2_TIMESTAMP_EXPIRED');
        }
        if ($requirePat && $request->pat === null) { throw new Denied('V2_PAT_REQUIRED'); }
        $expiry = (int)$request->timestamp + $this->tolerance + $this->margin;
        $namespace = hash('sha256', implode("\0", [$profile, $targetAudience, $request->keyId]));
        try {
            $claimed = $this->nonces->claim($namespace, hash('sha256', $request->nonce), $now, $expiry);
        } catch (\Throwable) { throw new Denied('V2_STORAGE_UNAVAILABLE'); }
        if (!$claimed) { throw new Denied('V2_NONCE_REPLAY'); }
        return new IntegrationProof($key['integration'], $key['kind'], $key['channel'],
            $targetAudience, $request->pat === null ? '' : hash('sha256', $request->pat), hash('sha256', $canonical), $expiry);
    }
}

final class BindingCheck
{
    /** Synthetic fixture predicate only; NOT Sanctum authentication or a persistent binding adapter. */
    public static function matches(IntegrationProof $proof, ?array $binding, int $user, int $now): bool
    {
        return $binding !== null && ($binding['active'] ?? false)
            && ($binding['expires_at'] ?? 0) > $now && ($binding['user_id'] ?? 0) === $user
            && $proof->patDigest !== '' && ($binding['pat_digest'] ?? '') === $proof->patDigest
            && ($binding['integration'] ?? '') === $proof->integration
            && ($binding['channel'] ?? '') === $proof->channel
            && ($binding['audience'] ?? '') === $proof->audience;
    }
}
