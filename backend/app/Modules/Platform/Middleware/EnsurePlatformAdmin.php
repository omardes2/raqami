<?php

namespace App\Modules\Platform\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Guards the Super Admin portal. Platform admins are a SEPARATE identity and
 * guard from tenant users — a normal tenant user can never pass this.
 */
class EnsurePlatformAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = Auth::guard('platform')->user();

        if ($admin === null || ! $admin->isActive()) {
            abort(403, 'Platform administrator access required.');
        }

        // Make the platform admin the resolved user for this request.
        $request->setUserResolver(fn () => $admin);

        return $next($request);
    }
}
