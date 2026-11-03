<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $supported = config('promofy.locales', ['ar', 'en']);
        $candidate = $request->header('X-Locale') ?: $request->query('lang') ?: $request->user()?->locale;

        $locale = in_array($candidate, $supported, true)
            ? $candidate
            : ($request->getPreferredLanguage($supported) ?: config('app.locale'));

        app()->setLocale($locale);

        return $next($request);
    }
}
