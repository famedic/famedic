<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

class UseForwardedRootUrl
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! config('app.url_from_request')) {
            return $next($request);
        }

        $root = $this->rootUrl($request);

        URL::forceRootUrl($root);
        URL::forceScheme(parse_url($root, PHP_URL_SCHEME) ?: 'https');
        config(['app.url' => $root]);

        return $next($request);
    }

    public function rootUrl(Request $request): string
    {
        $scheme = $this->scheme($request);
        $host = $request->getHost();

        return rtrim($scheme.'://'.$host.$request->getBasePath(), '/');
    }

    public function scheme(Request $request): string
    {
        $forwarded = strtolower((string) $request->headers->get('X-Forwarded-Proto', ''));
        if (str_contains($forwarded, 'https')) {
            return 'https';
        }

        $visitor = (string) $request->headers->get('CF-Visitor', '');
        if (str_contains($visitor, 'https')) {
            return 'https';
        }

        $host = $request->getHost();
        if (str_ends_with($host, '.trycloudflare.com')) {
            return 'https';
        }

        return $request->getScheme();
    }
}
