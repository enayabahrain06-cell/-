<?php

namespace App\Http\Middleware;

use App\Enums\Locale;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Locale resolution order: authenticated user's saved locale → Accept-Language → "ar".
 * Also stores the display timezone offset for Resources.
 */
class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = Locale::fromHeader($request->header('Accept-Language'));

        if ($user = $request->user('sanctum')) {
            $locale = $user->locale instanceof Locale ? $user->locale : Locale::tryFrom((string) $user->locale) ?? $locale;
        }

        app()->setLocale($locale->value);

        return $next($request);
    }
}
