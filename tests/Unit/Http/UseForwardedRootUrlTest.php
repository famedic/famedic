<?php

use App\Http\Middleware\UseForwardedRootUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

afterEach(function () {
    URL::forceRootUrl(null);
    URL::forceScheme(null);
});

test('does not override generated urls when disabled', function () {
    config(['app.url' => 'http://127.0.0.1:8080', 'app.url_from_request' => false]);
    URL::forceRootUrl('http://127.0.0.1:8080');

    $request = Request::create('https://random-name.trycloudflare.com/login', 'GET');
    $middleware = new UseForwardedRootUrl;

    $middleware->handle($request, function () {
        expect(url('/'))->toStartWith('http://127.0.0.1:8080');

        return response('ok');
    });
});

test('uses the request host and scheme when enabled', function () {
    config(['app.url' => 'http://127.0.0.1:8080', 'app.url_from_request' => true]);
    URL::forceRootUrl('http://127.0.0.1:8080');

    $request = Request::create('https://random-name.trycloudflare.com/login', 'GET');
    $middleware = new UseForwardedRootUrl;

    $middleware->handle($request, function () {
        expect(url('/login'))->toBe('https://random-name.trycloudflare.com/login')
            ->and(config('app.url'))->toBe('https://random-name.trycloudflare.com');

        return response('ok');
    });
});

test('forces https for trycloudflare even when the incoming request is http', function () {
    config(['app.url' => 'http://127.0.0.1:8080', 'app.url_from_request' => true]);
    URL::forceRootUrl('http://127.0.0.1:8080');

    $request = Request::create('http://random-name.trycloudflare.com/login', 'GET');
    $request->headers->set('X-Forwarded-Proto', 'https');
    $middleware = new UseForwardedRootUrl;

    $middleware->handle($request, function () {
        expect(url('/login'))->toBe('https://random-name.trycloudflare.com/login');

        return response('ok');
    });
});
