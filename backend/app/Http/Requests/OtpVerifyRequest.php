<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** F-AUTH-02 : code de 6 chiffres, 5 essais. */
class OtpVerifyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $digits = (int) config('classlink.otp.digits', 6);

        return [
            // `email:rfc` et non `email` : la regle par defaut de Laravel 11
            // laisse passer un CR/LF (CVE-2026-48019). La regle RFC impose le
            // meme filtre que `OtpRequest`, donc une adresse refusee ici l'est
            // aussi a la demande du code.
            'email' => ['required', 'string', 'email:rfc', 'max:255'],
            'code' => ['required', 'string', 'regex:/^\d{'.$digits.'}$/'],
        ];
    }

    public function attributes(): array
    {
        return ['email' => 'adresse e-mail scolaire', 'code' => 'code'];
    }

    public function messages(): array
    {
        return [
            'code.regex' => 'Le code doit contenir '.config('classlink.otp.digits', 6).' chiffres.',
        ];
    }
}
