<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * F-AUTH-02 — demande de code a usage unique.
 *
 * §16 « Énumération de comptes » : la réponse HTTP est IDENTIQUE que
 * l'adresse existe, qu'elle soit du domaine autorisé ou non. C'est
 * OtpService::request() qui ignore silencieusement toute adresse dont le
 * rôle calculé vaut « denied » (RG-01).
 *
 * Il ne faut donc surtout PAS rejeter ici un domaine étranger : un 422
 * distinguerait « domaine non autorisé » de « adresse inconnue » et
 * permettrait d'énumérer le domaine de l'établissement.
 */
class OtpRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // `email:rfc` refuse « a@b@ofppt-edu.ma » et les autres formes
            // malformees, qui ne doivent jamais atteindre RoleDetector.
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
        ];
    }

    public function attributes(): array
    {
        return ['email' => 'adresse e-mail scolaire'];
    }
}
