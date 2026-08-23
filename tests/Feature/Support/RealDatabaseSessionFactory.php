<?php

namespace Tests\Feature\Support;

use App\Support\LaravelDatabaseSessionPayloadCodec;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Session\DatabaseSessionHandler;
use Illuminate\Session\EncryptedStore;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RealDatabaseSessionFactory
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public static function create(array $attributes, ?Authenticatable $user = null, bool $withFlash = true): string
    {
        $store = self::makeStore(Str::random(40));
        $store->start();

        if ($user) {
            $store->put(Auth::getName(), $user->getAuthIdentifier());
        }

        $store->put('_token', Str::random(40));
        $store->put('normal_key', 'keep-me');

        if ($withFlash) {
            $store->put('_flash', ['new' => ['status'], 'old' => []]);
            $store->put('status', 'flash-ok');
        }

        foreach ($attributes as $key => $value) {
            $store->put($key, $value);
        }

        $store->save();

        return $store->getId();
    }

    public static function load(string $sessionId): Store
    {
        $store = self::makeStore($sessionId);
        $store->start();

        return $store;
    }

    public static function rawRow(string $sessionId): ?object
    {
        return DB::table(config('session.table', 'sessions'))->where('id', $sessionId)->first();
    }

    /**
     * @return array{encoding: string, encrypted: bool, serialized: bool}
     */
    public static function describeStoredPayload(string $sessionId): array
    {
        $row = self::rawRow($sessionId);

        return [
            'encoding' => 'base64',
            'encrypted' => (bool) config('session.encrypt', false),
            'serialized' => is_array(app(LaravelDatabaseSessionPayloadCodec::class)->decode((string) $row->payload)),
        ];
    }

    private static function makeStore(string $sessionId): Store
    {
        $handler = new DatabaseSessionHandler(
            DB::connection(config('session.connection')),
            (string) config('session.table', 'sessions'),
            (int) config('session.lifetime', 120),
            app()
        );

        $cookieName = (string) config('session.cookie', 'laravel_session');

        if (config('session.encrypt')) {
            return new EncryptedStore($cookieName, $handler, app('encrypter'), $sessionId);
        }

        return new Store($cookieName, $handler, $sessionId);
    }
}
