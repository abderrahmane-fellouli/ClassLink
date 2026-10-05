<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Laravel\Socialite\Contracts\User as ProviderUser;

/** Tenant identity from authenticated Microsoft Graph, not an unvalidated JWT. */
class MicrosoftOrganizationResolver
{
    public function resolve(ProviderUser $profile): ?string
    {
        if (! isset($profile->token) || ! is_string($profile->token) || $profile->token === '') {
            return null;
        }
        try {
            // User.Read permits id and verifiedDomains; no Directory.Read.All required.
            $response = Http::withToken($profile->token)->acceptJson()->timeout(15)
                ->withOptions(['allow_redirects' => false])
                ->get('https://graph.microsoft.com/v1.0/organization', ['$select' => 'id,verifiedDomains']);
            if (! $response->successful()) {
                return null;
            }
            $organizations = $response->json('value');
            if (! is_array($organizations) || count($organizations) !== 1) {
                return null;
            }
            $organization = $organizations[0];
            $tenant = $organization['id'] ?? null;
            if (! is_string($tenant) || ! Str::isUuid($tenant)) {
                return null;
            }
            foreach ($organization['verifiedDomains'] ?? [] as $domain) {
                if (is_array($domain) && is_string($domain['name'] ?? null)
                    && strtolower($domain['name']) === 'ofppt-edu.ma') {
                    return strtolower($tenant);
                }
            }
        } catch (\Throwable $e) {
            // Do not report HTTP exceptions or responses: they may contain tokens.
            return null;
        }

        return null;
    }
}
