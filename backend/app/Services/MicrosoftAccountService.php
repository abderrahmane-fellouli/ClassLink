<?php

namespace App\Services;

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\User;
use App\Support\RoleDetector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as ProviderUser;

/** Called only with a profile obtained by the authenticated Azure/Graph provider. */
class MicrosoftAccountService
{
    public function resolve(ProviderUser $profile): ?User
    {
        if (! is_string($profile->getEmail()) || ! is_string($profile->getId())) {
            return null;
        }
        $email = RoleDetector::normalizeOfpptAddress($profile->getEmail());
        $objectId = strtolower($profile->getId());
        $tenantId = strtolower((string) config('services.azure.tenant'));
        if ($tenantId === 'organizations') {
            // /organization uses the same server-obtained token as Graph /me.
            // Never persist the token or trust client/raw JWT tid claims.
            $tenantId = $email !== null && Str::isUuid($objectId)
                ? (new MicrosoftOrganizationResolver)->resolve($profile) : null;
            $rawTenant = null;
        } else {
            $rawTenant = $profile->getRaw()['tid'] ?? null;
        }
        if ($email === null || ! Str::isUuid($objectId) || ! is_string($tenantId) || ! Str::isUuid($tenantId)
            || ($rawTenant !== null && (! is_string($rawTenant) || strtolower($rawTenant) !== $tenantId))) {
            return null;
        }

        return DB::transaction(function () use ($profile, $email, $objectId, $tenantId) {
            $user = User::where('microsoft_tenant_id', $tenantId)
                ->where('microsoft_object_id', $objectId)->lockForUpdate()->first();
            $matches = User::whereRaw('LOWER(email) = ?', [$email])->lockForUpdate()->limit(2)->get();
            if ($matches->count() > 1) {
                return null; // Ambiguous legacy case variants cannot be merged.
            }
            $contact = $matches->first();
            if ($user && $contact && $contact->id !== $user->id) {
                return null; // UPN rename collides with another account: no takeover.
            }
            if (! $user && $contact) {
                if ($contact->microsoft_object_id !== null || $contact->microsoft_tenant_id !== null) {
                    return null; // This email is already linked to a different identity.
                }
                $user = $contact; // One-time backward-compatible legacy linking.
            }
            if ($user && ! $user->is_active) {
                return null;
            }

            $detected = RoleDetector::fromEmail($email);
            if (! $user) {
                $user = new User([
                    'email' => $email,
                    'display_name' => is_string($profile->getName()) && trim($profile->getName()) !== ''
                        ? Str::limit(trim($profile->getName()), 80, '') : ($detected === 'student' ? 'Student' : 'OFPPT account'),
                    'role' => $detected,
                    'role_locked' => false,
                    'locale' => app()->getLocale(),
                    'is_active' => true,
                ]);
            }
            $previousRole = $user->role;
            $role = RoleDetector::resolveFor($email, (bool) $user->role_locked, $user->role);
            $user->forceFill([
                'email' => $email,
                'microsoft_tenant_id' => $tenantId,
                'microsoft_object_id' => $objectId,
                'microsoft_verified_at' => now(),
                'role_candidate' => $detected === Role::Student->value ? 'student' : 'teacher',
                'role' => $role,
            ])->save();
            if ($previousRole !== $role) {
                $user->tokens()->delete();
            }
            AuditLog::record($user, 'auth.microsoft_verified', ['candidate' => $user->role_candidate, 'status' => $user->role]);

            return $user;
        }, 3);
    }
}
