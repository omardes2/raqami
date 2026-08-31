<?php

namespace App\Modules\Localization\Middleware;

use App\Modules\Localization\Services\LocaleService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the request locale (authenticated user's preference, else an
 * explicit header/param, else fallback) and applies it to the app so all
 * translated strings and formatting respond correctly. RTL/LTR direction is
 * derived from the locale, not hard-coded.
 */
class SetLocale
{
    public function __construct(private readonly LocaleService $locales) {}

    public function handle(Request $request, Closure $next): Response
    {
        $candidate = $request->user()?->locale
            ?? $request->header('X-Locale')
            ?? $request->query('locale')
            ?? config('app.locale');

        if (is_string($candidate) && $this->locales->isSupported($candidate)) {
            app()->setLocale($candidate);
        }

        return $next($request);
    }
}
