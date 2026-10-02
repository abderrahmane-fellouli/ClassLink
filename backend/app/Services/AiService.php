<?php

namespace App\Services;

use App\Exceptions\AiUnavailableException;
use App\Models\AiProvider;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * §15.2 / §15.3 / §17.8 — service IA avec bascule automatique.
 *
 * Comportement imposé par la spécification :
 *  - les fournisseurs sont essayés par ordre de priorité croissante ;
 *  - 429, erreur serveur ou délai dépassé -> fournisseur suivant (T-18) ;
 *  - un fournisseur en échec peut être ignoré quelques minutes ;
 *  - si tous échouent -> AiUnavailableException, message clair, et la
 *    création manuelle reste possible (F-IA-06, T-19) ;
 *  - quota quotidien par fournisseur et par enseignant ;
 *  - cache par empreinte de fichier : un même PDF n'est pas retraité et le
 *    quota n'est pas reconsommé (RG-14, T-20).
 */
class AiService
{
    public function __construct(
        private readonly AiPayloadValidator $validator,
    ) {}

    /**
     * @return array{ok: true, data: array<string, mixed>, provider: string, cached: bool}
     * @throws AiUnavailableException
     */
    public function generate(string $text, string $fileHash, string $type = 'quiz'): array
    {
        // RG-14 / T-20 : cache par empreinte. Un même PDF renvoie le
        // résultat précédent sans consommer de quota.
        $cacheKey = "ai:{$type}:{$fileHash}";

        if ($cached = Cache::get($cacheKey)) {
            return ['ok' => true, 'data' => $cached, 'provider' => 'cache', 'cached' => true];
        }

        $providers = AiProvider::where('enabled', true)->orderBy('priority')->get();
        $attempts = [];

        foreach ($providers as $provider) {
            if ($this->isCoolingDown($provider)) {
                $attempts[] = ['provider' => $provider->name, 'reason' => 'ignoré temporairement'];
                continue;
            }

            if (! $provider->hasQuotaLeft()) {
                $attempts[] = ['provider' => $provider->name, 'reason' => 'quota quotidien atteint'];
                continue;
            }

            if (! $provider->secretKey()) {
                $attempts[] = ['provider' => $provider->name, 'reason' => 'clé absente'];
                continue;
            }

            $result = $this->callProvider($provider, $text, $type);

            if ($result['ok']) {
                $provider->increment('used_today');
                Cache::put($cacheKey, $result['data'], (int) config('classlink.ai.cache_days', 30) * 86400);

                return [
                    'ok' => true,
                    'data' => $result['data'],
                    'provider' => $provider->name,
                    'cached' => false,
                ];
            }

            // §15.2 : on ignore ce fournisseur quelques minutes.
            $this->coolDown($provider);
            $attempts[] = ['provider' => $provider->name, 'reason' => $result['reason']];
            Log::warning('Fournisseur IA en échec', [
                'provider' => $provider->name,
                'reason' => $result['reason'],
            ]);
        }

        throw new AiUnavailableException(attempts: $attempts);
    }

    /**
     * Un appel à un fournisseur, avec une nouvelle tentative si le JSON est
     * invalide (§15.3).
     *
     * @return array{ok: true, data: array<string, mixed>}|array{ok: false, reason: string}
     */
    private function callProvider(AiProvider $provider, string $text, string $type): array
    {
        $retries = (int) config('classlink.ai.max_retries_on_invalid_json', 1);
        $lastReason = 'réponse invalide';

        for ($attempt = 0; $attempt <= $retries; $attempt++) {
            try {
                $response = Http::withToken((string) $provider->secretKey())
                    ->timeout((int) config('classlink.ai.request_timeout', 60))
                    ->acceptJson()
                    ->post(rtrim((string) $provider->endpoint(), '/').'/chat/completions', [
                        'model' => $provider->model(),
                        'temperature' => 0.4,
                        'response_format' => ['type' => 'json_object'],
                        'messages' => [
                            [
                                'role' => 'system',
                                'content' => $this->systemPrompt($type),
                            ],
                            [
                                'role' => 'user',
                                'content' => $this->buildPrompt($text, $type),
                            ],
                        ],
                    ]);
            } catch (\Throwable $e) {
                // Délai dépassé ou erreur réseau -> fournisseur suivant.
                return ['ok' => false, 'reason' => 'délai dépassé ou erreur réseau'];
            }

            if ($response->status() === 429) {
                return ['ok' => false, 'reason' => 'limite de requêtes atteinte (429)'];
            }

            if ($response->serverError()) {
                return ['ok' => false, 'reason' => 'erreur serveur '.$response->status()];
            }

            if (! $response->successful()) {
                return ['ok' => false, 'reason' => 'statut HTTP '.$response->status()];
            }

            $content = $response->json('choices.0.message.content');
            $decoded = is_string($content) ? json_decode($content, true) : $content;

            $validation = $this->validator->validate($decoded, $type);

            if ($validation['ok'] && $this->validator->hasAnswerableQuestions($validation['data'])) {
                return ['ok' => true, 'data' => $validation['data']];
            }

            $lastReason = 'JSON invalide : '.implode(' / ', $validation['errors'] ?? ['structure inattendue']);
        }

        return ['ok' => false, 'reason' => $lastReason];
    }

    // -------------------------------------------------------------------------
    // Prompts — §15.3 « Confidentialité : seul le texte du cours est envoyé,
    // jamais de données sur les utilisateurs ».
    // -------------------------------------------------------------------------

    private function systemPrompt(string $type): string
    {
        $limit = (int) config('classlink.ai.max_questions', 30);

        if ($type === 'flashcard') {
            return "Tu es un assistant pédagogique. Tu produis des flashcards à partir d'un cours. "
                ."Réponds uniquement par un JSON valide, sans texte autour, au format exact : "
                .'{"title":"...","cards":[{"front":"...","back":"..."}]}. '
                ."Maximum {$limit} cartes. Français uniquement.";
        }

        return "Tu es un assistant pédagogique. Tu produis un quiz à partir d'un cours. "
            ."Réponds uniquement par un JSON valide, sans texte autour, au format exact : "
            .'{"title":"...","questions":[{"statement":"...","type":"single|multiple|true_false",'
            .'"explanation":"...","options":[{"label":"...","is_correct":true}]}]}. '
            ."Chaque question a au moins 2 options et exactement une bonne réponse pour single "
            ."et true_false. Maximum {$limit} questions. Français uniquement.";
    }

    private function buildPrompt(string $text, string $type): string
    {
        $maxChars = 20000; // Garde-fou : jamais le cours entier en mémoire vive.

        $trimmed = mb_substr($text, 0, $maxChars);

        return "Voici le texte du cours. ".($type === 'flashcard'
            ? 'Produis des flashcards de révision.'
            : 'Produis un quiz de révision.')."\n\n---\n{$trimmed}\n---";
    }

    // -------------------------------------------------------------------------
    // Refroidissement des fournisseurs (§15.2)
    // -------------------------------------------------------------------------

    private function isCoolingDown(AiProvider $provider): bool
    {
        return Cache::get($this->cooldownKey($provider)) !== null;
    }

    private function coolDown(AiProvider $provider): void
    {
        Cache::put(
            $this->cooldownKey($provider),
            true,
            (int) config('classlink.ai.cooldown_minutes', 5) * 60
        );
    }

    private function cooldownKey(AiProvider $provider): string
    {
        return "ai:cooldown:{$provider->name}";
    }

    /**
     * Remise à zéro quotidienne des compteurs `used_today`.
     * Appelé par la tâche planifiée quotidienne.
     */
    public function resetDailyQuotas(): int
    {
        return AiProvider::query()->update([
            'used_today' => 0,
            'last_reset_at' => Carbon::now(),
        ]);
    }
}
