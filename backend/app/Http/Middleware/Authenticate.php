<?php

namespace App\Http\Middleware;

use Illuminate\Auth\Middleware\Authenticate as BaseAuthenticate;
use Illuminate\Http\Request;

/**
 * Authenticate — scope-aware authorization middleware for the UU Mission API.
 *
 * This is an API-only backend. Unauthenticated requests must always receive a
 * JSON 401 response; they must never be redirected to a login page (which does
 * not exist as a named route and would not be meaningful for an API client).
 *
 * Laravel's default Authenticate middleware calls route('login') when the
 * request does not expect JSON, which throws RouteNotFoundException because no
 * route is named "login". This middleware overrides redirectTo() to always
 * return null, causing Laravel to throw AuthenticationException instead, which
 * is handled by the exception renderer in bootstrap/app.php to return a JSON
 * 401 response.
 */
class Authenticate extends BaseAuthenticate
{
    /**
     * Get the path the user should be redirected to when they are not authenticated.
     *
     * @return null Always returns null so that Laravel throws AuthenticationException
     *              instead of attempting a redirect to a non-existent login route.
     */
    protected function redirectTo(Request $request): ?string
    {
        return null;
    }
}
