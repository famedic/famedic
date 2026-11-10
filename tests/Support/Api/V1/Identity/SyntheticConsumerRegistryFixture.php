<?php

namespace Tests\Support\Api\V1\Identity;

use App\Models\ApiConsumer;
use App\Models\ApiConsumerChannel;
use App\Models\ApiConsumerDelegation;
use App\Models\User;
use App\Services\Api\V1\Auth\ConsumerTokenBindingWriter;
use App\Services\Api\V1\Auth\PaymentLinkAccessPolicy;
use Illuminate\Support\Facades\Gate;

final class SyntheticConsumerRegistryFixture
{
    private static array $transport = [];

    private static array $actors = [];

    public static function issue(User $user, string $kind = 'native', ?array $caps = null): array
    {
        $safeSqlite = config('database.default') === 'sqlite'
            && config('database.connections.sqlite.database') === ':memory:';
        $safeLegacyMysql = config('benefits.synthetic_mysql') === true
            && config('database.connections.mysql.database') === 'benefits_synthetic_testing'
            && config('database.connections.mysql.unix_socket') === '/phase-socket/mysql.sock';
        $safeRc2Mysql = config('database.default') === 'mysql'
            && config('database.connections.mysql.database') === 'famedic_rc2_local_20261110_testing'
            && config('database.connections.mysql.host') === 'rc2-local-mysql-20261110';

        if (! app()->environment('testing') || ! ($safeSqlite || $safeLegacyMysql || $safeRc2Mysql)) {
            throw new \LogicException('Unsafe fixture environment');
        }
        $defaultCaps=$caps===null;
        $caps ??= [PaymentLinkAccessPolicy::CAPABILITY, 'cart:read', 'memberships:read', 'cart:membership:write', 'checkout:prepare', 'legacy-benefits:read', 'legacy-benefits:write'];
        if($defaultCaps && $kind==='native')$caps=array_merge($caps,['cart:write','payments:draft']);
        $issuer = User::factory()->create();
        Gate::define('manageApiConsumerRegistry', fn (User $operator) => $operator->id === $issuer->id);
        $consumer = ApiConsumer::forceCreate(['kind' => $kind, 'capabilities' => $caps, 'enabled' => true, 'expires_at' => now()->addHour()]);
        $channel = ApiConsumerChannel::forceCreate(['consumer_id' => $consumer->id, 'enabled' => true, 'expires_at' => now()->addHour()]);
        $issued = app(\App\Services\Api\V1\Auth\ConsumerMachineCredentialIssuer::class)->issue($issuer, $channel, now()->addMinutes(40));
        $machine = $issued['token'];
        $credential = $issued['credential'];
        $delegation = ApiConsumerDelegation::forceCreate(['consumer_id' => $consumer->id, 'channel_id' => $channel->id, 'user_id' => $user->id, 'customer_id' => $user->customer->id, 'capabilities' => $caps, 'enabled' => true, 'expires_at' => now()->addHour()]);
        $token = $user->createToken('synthetic-delegated-user', $caps, now()->addHour());
        // Trusted synthetic enrollment authority, never a production Gate grant.
        $binding = app(ConsumerTokenBindingWriter::class)->bind($issuer, $token->accessToken, $delegation, $caps, now()->addMinutes(30));
        self::$transport[hash('sha256', $token->plainTextToken)] = $machine->plainTextToken;
        $context = app(\App\Contracts\Api\V1\Auth\ConsumerBindingRepository::class)->findByTokenId((int) $token->accessToken->id);
        self::$actors[$user->id] = \App\Services\Api\V1\Auth\ConsumerRequestScope::actorKey((int) $user->customer->id, $context);

        return compact('user', 'consumer', 'channel', 'credential', 'delegation', 'binding', 'token', 'machine', 'issuer');
    }

    public static function headers(string $userToken): array
    {
        $machine = self::$transport[hash('sha256', $userToken)] ?? null;

        return $machine === null ? [] : ['X-Synthetic-Machine-Credential' => $machine];
    }


    public static function associateIssuedToken(string $raw,array $actor):void{
        if(!app()->environment('testing'))throw new \LogicException('Unsafe fixture');
        $pat=\Laravel\Sanctum\PersonalAccessToken::findToken($raw);
        $binding=$pat?\App\Models\ApiConsumerTokenBinding::where('personal_access_token_id',$pat->id)->sole():null;
        if(!$pat || !$binding || (int)$pat->tokenable_id!==(int)$actor['user']->id || (int)$binding->consumer_id!==(int)$actor['consumer']->id || (int)$binding->channel_id!==(int)$actor['channel']->id || (int)$binding->delegation_id!==(int)$actor['delegation']->id)throw new \LogicException('Issued fixture credential mismatch');
        self::$transport[hash('sha256',$raw)]=$actor['machine']->plainTextToken;
    }

    public static function actorKey(User $user): string
    {
        return self::$actors[$user->id] ?? throw new \LogicException('Native fixture identity not issued');
    }
}
