<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\TokenService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Temporary operator-authorized review access; remove after the review. */
final class TemporaryReviewLoginController extends Controller
{
    private const ACCOUNT = '2007031400094@ofppt-edu.ma';

    public function __invoke(Request $request, TokenService $tokens): Response
    {
        $secret = config('temporary-review.secret');
        $cutoff = config('temporary-review.until');
        abort_unless(app()->environment('production') && $request->isSecure(), 404);
        abort_unless(is_string($secret) && strlen($secret) >= 64, 404);
        abort_unless(hash_equals($secret, (string) $request->bearerToken()), 404);
        // No email, redirect, role, token lifetime or secret query/body parameters.
        abort_unless($request->all() === [], 404);
        abort_unless(is_string($cutoff) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/D', $cutoff), 404);
        try {
            $until = Carbon::parse($cutoff, 'UTC');
        } catch (\Throwable) {
            abort(404);
        }
        abort_unless($until->isFuture(), 404);
        // The immutable login audit remains a one-use marker even after logout/pruning.
        $purpose = 'temporary-review:'.hash('sha256', $secret);

        try {
            return DB::transaction(function () use ($tokens, $until, $purpose) {
                $matches = User::whereRaw('LOWER(email) = ?', [self::ACCOUNT])->lockForUpdate()->limit(2)->get();
                abort_unless($matches->count() === 1 && $matches->first()->canAccessApp() && $until->isFuture(), 404);
                $user = $matches->first();
                abort_if(AuditLog::where('action', 'auth.login')
                    ->where('context->method', $purpose)->exists(), 410);

                $plain = $tokens->issue($user, $purpose);
                $token = $user->tokens()->findOrFail(explode('|', $plain, 2)[0]);
                $token->forceFill(['name' => $purpose, 'expires_at' => $token->created_at->copy()->addMinutes(15)->min($until)])->save();

                return response('', 302, [
                    'Location' => 'https://classlink.space/auth/microsoft/callback#token='.rawurlencode($plain),
                    'Cache-Control' => 'no-store, private',
                    'Pragma' => 'no-cache',
                    'Referrer-Policy' => 'no-referrer',
                    'X-Robots-Tag' => 'noindex, nofollow',
                    'X-Review-Token-Id' => (string) $token->id,
                    'X-Review-Expires-At' => $token->expires_at->utc()->toIso8601String(),
                ]);
            });
        } catch (HttpExceptionInterface $e) {
            throw $e;
        } catch (\Throwable $e) {
            // Never hand an exception containing in-scope credentials to the generic logger.
            Log::error('Temporary review token issuance failed', ['exception_class' => $e::class]);

            return response()->json(['message' => __('api.errors.server_error')], 503)
                ->header('Cache-Control', 'no-store, private');
        }
    }
}
