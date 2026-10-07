<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\PersonalAccessToken;

/** Re-check the current principal and serialize institutional writes with revocation. */
class InstitutionalWriteTransaction
{
    public function handle(Request $request, Closure $next)
    {
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        return DB::transaction(function () use ($request, $next) {
            $previous = $request->user();
            abort_unless($previous, 401);
            $current = User::whereKey($previous->id)->lockForUpdate()->first();
            abort_unless($current && $current->canAccessApp(), 403);
            $token = $previous->currentAccessToken();
            if ($token instanceof PersonalAccessToken) {
                abort_unless(PersonalAccessToken::whereKey($token->id)->where('tokenable_id', $current->id)->exists(), 401);
                abort_if($token->expires_at && $token->expires_at->isPast(), 401);
                $current->withAccessToken($token);
            }
            $request->setUserResolver(fn () => $current);
            Auth::guard('sanctum')->setUser($current);

            return $next($request);
        }, 3);
    }
}
