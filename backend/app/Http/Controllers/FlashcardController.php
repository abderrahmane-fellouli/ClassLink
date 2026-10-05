<?php

namespace App\Http\Controllers;

use App\Http\Resources\FlashcardDeckResource;
use App\Models\Classroom;
use App\Models\Flashcard;
use App\Models\FlashcardDeck;
use App\Models\FlashcardReview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * §12.5 — F-QUI-08 (Should) : révision par flashcards.
 * Non listé dans le §12 du cahier des charges : routes ajoutées sous la
 * classe correspondante, cohérentes avec la matrice d'autorisation.
 */
class FlashcardController extends Controller
{
    public function index(Request $request, Classroom $classroom): JsonResponse
    {
        $this->authorize('view', $classroom);

        $isManager = $classroom->isOwnedBy($request->user());

        $decks = $classroom->flashcardDecks()
            ->when(! $isManager, fn ($q) => $q->where('status', 'published'))
            ->withCount('cards')
            ->orderByDesc('created_at')
            ->get();

        return response()->json(['data' => FlashcardDeckResource::collection($decks)]);
    }

    /**
     * F-QUI-08 : consultation d'un deck publie avec ses cartes.
     * La politique `FlashcardDeckPolicy::view` interdit tout deck en brouillon
     * a un etudiant, donc aucune carte n'est exposee par erreur.
     *
     * Les cartes portent l'etat de revision du demandeur (`known`) : la reprise
     * d'une session ne depend plus du poste du client.
     */
    public function show(Request $request, FlashcardDeck $deck): FlashcardDeckResource
    {
        $this->authorize('view', $deck);

        $deck->load('cards');

        $known = FlashcardReview::query()
            ->where('user_id', $request->user()->id)
            ->whereIn('flashcard_id', $deck->cards->modelKeys())
            ->pluck('known', 'flashcard_id')
            ->all();

        return new FlashcardDeckResource($deck->setAttribute('knownByViewer', $known));
    }

    /**
     * F-QUI-08 : memorisation d'une carte par l'etudiant.
     *
     * POST /flashcard-decks/{deck}/cards/{card}/review  { "known": true|false }
     *
     * L'etat est enregistre par carte (table `flashcard_reviews`) : il survit au
     * rechargement et est propre a l'etudiant. Meme politique que la lecture du
     * deck : deck publie + adhesion acceptee, donc aucune carte d'une autre
     * classe n'est modifiable.
     */
    public function review(Request $request, FlashcardDeck $deck, Flashcard $card): JsonResponse
    {
        $this->authorize('review', $deck);

        // La carte doit bien appartenir au deck de l'URL : sinon on pourrait
        // ecrire un avis sur une carte d'un autre deck (donc d'une autre classe).
        abort_unless($card->deck_id === $deck->id, 404);

        $data = $request->validate([
            'known' => ['required', 'boolean'],
        ], [], ['known' => 'revision']);

        $review = FlashcardReview::updateOrCreate(
            [
                'user_id' => $request->user()->id,
                'flashcard_id' => $card->id,
            ],
            ['known' => (bool) $data['known']],
        );

        return response()->json([
            'data' => [
                'card_id' => $card->id,
                'known' => $review->known,
            ],
        ]);
    }

    public function store(Request $request, Classroom $classroom): JsonResponse
    {
        $this->authorize('modify', $classroom);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'cards' => ['required', 'array', 'min:1', 'max:100'],
            'cards.*.front' => ['required', 'string', 'max:2000'],
            'cards.*.back' => ['required', 'string', 'max:2000'],
        ]);

        $deck = FlashcardDeck::create([
            'classroom_id' => $classroom->id,
            'title' => $data['title'],
            'source' => 'manual',
            'status' => 'draft',
            // F-IA-03 : fail closed, pas de `reviewed => true` a la creation.
        ]);

        foreach ($data['cards'] as $i => $card) {
            $deck->cards()->create(['front' => $card['front'], 'back' => $card['back'], 'position' => $i]);
        }

        return response()->json(new FlashcardDeckResource($deck->load('cards')), 201);
    }

    /**
     * Modification du titre d'un deck. F-QUI-08.
     *
     * `update` dans `FlashcardDeckPolicy` impose déjà propriétaire + classe non
     * archivée : un deck publié n'est donc pas modifiable par un élève.
     *
     * F-IA-03 : une vraie édition du contenu vaut relecture — même sémantique
     * que `QuestionController::update`.
     */
    public function update(Request $request, FlashcardDeck $deck): FlashcardDeckResource
    {
        $this->authorize('update', $deck);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:255'],
        ]);

        $deck->fill(['title' => $data['title']]);
        if ($deck->isDirty('title')) {
            $deck->save();
            $deck->markReviewed();
        }

        return new FlashcardDeckResource($deck->fresh('cards'));
    }

    /**
     * Modification du recto/verso d'une carte. F-QUI-08.
     */
    public function updateCard(Request $request, FlashcardDeck $deck, Flashcard $card): JsonResponse
    {
        $this->authorize('update', $deck);

        // La carte doit appartenir au deck de l'URL : sinon on pourrait
        // modifier une carte d'un autre deck (donc d'une autre classe).
        abort_unless($card->deck_id === $deck->id, 404);

        $data = $request->validate([
            'front' => ['required', 'string', 'max:2000'],
            'back' => ['required', 'string', 'max:2000'],
        ]);

        $card->fill($data);
        if ($card->isDirty(['front', 'back'])) {
            $card->save();
            $deck->markReviewed();
        }

        return response()->json([
            'data' => [
                'id' => $card->id,
                'front' => $card->front,
                'back' => $card->back,
                'position' => $card->position,
                'deck_reviewed' => (bool) $deck->reviewed,
            ],
        ]);
    }

    /** Suppression d'une carte. F-QUI-08. */
    public function destroyCard(FlashcardDeck $deck, Flashcard $card): Response
    {
        $this->authorize('update', $deck);

        abort_unless($card->deck_id === $deck->id, 404);

        $card->delete();
        $deck->markReviewed();

        return response()->noContent();
    }

    /** RG-11 appliqué aux decks IA : publication après relecture. */
    public function publish(FlashcardDeck $deck): JsonResponse|FlashcardDeckResource
    {
        $this->authorize('update', $deck);

        if ($deck->source === 'ai' && (! $deck->reviewed || $deck->reviewed_at === null)) {
            return response()->json([
                'message' => 'Relisez ce deck généré par l\'IA avant de le publier.',
                'requires_review' => true,
            ], 409);
        }

        // F-IA-03 : publier ne pose pas `reviewed` (publier ne vaut pas
        // relecture). Le verrou IA doit avoir été levé avant.
        $deck->update(['status' => 'published']);

        return new FlashcardDeckResource($deck->fresh('cards'));
    }

    /** Marque le deck comme relu — déverrouille la publication (F-IA-03). */
    public function markReviewed(FlashcardDeck $deck): FlashcardDeckResource
    {
        $this->authorize('update', $deck);

        $deck->markReviewed();

        return new FlashcardDeckResource($deck->fresh('cards'));
    }

    public function destroy(FlashcardDeck $deck): Response
    {
        $this->authorize('delete', $deck);

        $deck->delete();

        return response()->noContent();
    }
}
