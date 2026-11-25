<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

/**
 * While a team member is editing someone else's page, block anything that would
 * change the owner's account (password, email, deletion) or alter the team list.
 *
 * Navigation requests are redirected to the dashboard (a hard 403 there would
 * also break post-edit redirects that land on the previous page); attempts to
 * post such a change are refused outright.
 */
class TeamGuard
{
    public function handle(Request $request, Closure $next)
    {
        if (!$request->session()->has('team_acting_as')) {
            return $next($request);
        }

        $path = trim($request->path(), '/');

        $blocked = [
            'studio/profile',            // email / password / account deletion
            'studio/page',               // page handle (URL), display name, avatar
            'studio/delete-user',
            'studio/team/add',
            'studio/team/remove',
            'admin',
        ];

        foreach ($blocked as $prefix) {
            if ($path === $prefix || str_starts_with($path, $prefix . '/')) {
                if ($request->isMethod('post') || $request->isMethod('put') || $request->isMethod('delete')) {
                    abort(403, 'Not available while editing another page.');
                }

                return redirect('/dashboard')
                    ->with('team_error', 'That is available only from your own account.');
            }
        }

        return $next($request);
    }
}
