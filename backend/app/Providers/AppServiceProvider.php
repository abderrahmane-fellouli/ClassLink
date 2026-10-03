<?php

namespace App\Providers;

use App\Contracts\PdfTextExtractor;
use App\Models\Announcement;
use App\Models\Assignment;
use App\Models\Attempt;
use App\Models\AiJob;
use App\Models\Classroom;
use App\Models\FlashcardDeck;
use App\Models\Material;
use App\Models\Membership;
use App\Models\PartnerRequest;
use App\Models\Quiz;
use App\Models\Submission;
use App\Models\User;
use App\Policies\AiJobPolicy;
use App\Policies\AnnouncementPolicy;
use App\Policies\AssignmentPolicy;
use App\Policies\AttemptPolicy;
use App\Policies\ClassroomPolicy;
use App\Policies\FlashcardDeckPolicy;
use App\Policies\MaterialPolicy;
use App\Policies\MembershipPolicy;
use App\Policies\PartnerRequestPolicy;
use App\Policies\QuizPolicy;
use App\Policies\SubmissionPolicy;
use App\Policies\UserPolicy;
use App\Services\SmalotPdfTextExtractor;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

/**
 * §17.6 : enregistrement explicite des policies.
 * §16 : « Policies Laravel à chaque route : rôle, propriété, adhésion
 * acceptée. »
 */
class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // §15.3 : le moteur d'extraction PDF est un composant optionnel.
        // Le contrat permet de le remplacer (test, autre moteur) sans toucher
        // au code appelant.
        $this->app->singleton(PdfTextExtractor::class, SmalotPdfTextExtractor::class);
    }

    public function boot(): void
    {
        /*
         | Format de reponse uniforme.
         |
         | Sans cela, Laravel enveloppe `{ "data": ... }` les ressources
         | renvoyees directement par un controleur, mais PAS celles
         | passees a `response()->json()`. Le meme objet revenait donc
         | suivant l'endpoint, ce qui casse le client.
         |
         | Convention retenue : un objet unique est renvoye nu, une
         | collection l'est explicitement sous `data` (voir les controleurs).
         */
        JsonResource::withoutWrapping();

        // §17.6 : branchement du fournisseur Socialite "azure".
        Event::listen(function (\SocialiteProviders\Manager\SocialiteWasCalled $event) {
            $event->extendSocialite('azure', \SocialiteProviders\Azure\Provider::class);
        });

        $this->registerPolicies();
    }

    private function registerPolicies(): void
    {
        $map = [
            \App\Models\AppNotification::class => \App\Policies\AppNotificationPolicy::class,
            User::class => UserPolicy::class,
            Classroom::class => ClassroomPolicy::class,
            Membership::class => MembershipPolicy::class,
            Material::class => MaterialPolicy::class,
            Announcement::class => AnnouncementPolicy::class,
            Quiz::class => QuizPolicy::class,
            Attempt::class => AttemptPolicy::class,
            Assignment::class => AssignmentPolicy::class,
            Submission::class => SubmissionPolicy::class,
            FlashcardDeck::class => FlashcardDeckPolicy::class,
            PartnerRequest::class => PartnerRequestPolicy::class,
            AiJob::class => AiJobPolicy::class,
        ];

        foreach ($map as $model => $policy) {
            Gate::policy($model, $policy);
        }
    }
}
