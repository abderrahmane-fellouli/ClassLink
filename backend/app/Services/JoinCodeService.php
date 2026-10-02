<?php

namespace App\Services;

use App\Models\Classroom;
use Illuminate\Support\Str;

/**
 * F-CLS-02 — génération automatique d'un code d'invitation unique.
 * §17.7 : le code est validé sur `size:8`, donc exactement 8 caractères.
 */
class JoinCodeService
{
    /**
     * Alphabet sans caractères ambigus (0/O, 1/I/L) : le code est lu à voix
     * haute et recopié depuis un écran de téléphone.
     */
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public function generate(): string
    {
        $length = (int) config('classlink.membership.join_code_length', 8);

        do {
            $code = '';
            $max = strlen(self::ALPHABET) - 1;
            for ($i = 0; $i < $length; $i++) {
                $code .= self::ALPHABET[random_int(0, $max)];
            }
        } while (Classroom::where('join_code', $code)->exists());

        return $code;
    }

    /** F-CLS-03 : nouveau code d'invitation. */
    public function regenerate(Classroom $classroom): string
    {
        $code = $this->generate();
        $classroom->update(['join_code' => $code]);

        return $code;
    }

    /** Normalisation saisie par l'étudiant (§17.7 : strtoupper). */
    public static function normalize(string $code): string
    {
        return Str::upper(trim($code));
    }
}
