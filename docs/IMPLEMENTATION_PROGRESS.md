# Journal de mise en œuvre

Suivi d'exécution du plan de remédiation défini dans `IMPLEMENTATION_AUDIT.md`.

**Portée engagée** : Phase 0 (0.1–0.5) et Phase 1 (1.1–1.5).
**Reference** : commit d'audit `fd7a77e9ec5e991be088cefc9b8828fbe821a7c8`.

Convention : une tâche n'est notée « Terminé » qu'avec le code **et** les tests
correspondants qui passent. Les tests ajoutés ont tous été vérifiés en échec sur
le code d'origine (`git checkout` du correctif), puis en succès.

---

## Phase 0 — bloquants

### 0.1 — Soumission après l'échéance d'un quiz

**Terminé**

RG-12 exige que les réponses envoyées après l'échéance soient **refusées**, et
T-15 autorise les deux comportements : « refusées ou expiration ». Le choix
retenu est « expirée » : la tentative est finalisée `expired=true` **sans**
enregistrer ni corriger les réponses tardives, et la réponse HTTP reste un
`AttemptResultResource` 200 — le contrat de l'API est préservé.

| Fichier | Modification |
|---|---|
| `backend/app/Services/QuizGradingService.php` | `saveAnswers()` refuse d'écrire après l'échéance (`BusinessRuleException` 409, `expired: true`). Garde-fou serveur : protège les appelants futurs, dont un éventuel point de sauvegarde partielle. |
| `backend/app/Http/Controllers/QuizController.php` | `submitAttempt()` n'appelle plus `saveAnswers()` pour une tentative expirée et appelle `submit(forcedExpired: true)`. |

Tests (`backend/tests/Feature/QuizTest.php`) :

- `t15 submission after the deadline is marked expired` — **renforcé** : score `0.0`,
  aucune ligne dans `attempt_answers`.
- `t15 the service refuses to save answers after the deadline` — garde-fou du service.
- `t15 answers recorded before the deadline are still graded` — les réponses
  enregistrées **avant** l'échéance restent corrigées (empêche la sur-correction).
- `t15 another student cannot submit an expired attempt` — le chemin « expirée »
  ne contourne pas le contrôle de propriété (étudiant tiers et enseignant : 403,
  `submitted_at` reste `null`).

Vérifié en échec avant correctif : `score 2.0 au lieu de 0.0`.

### 0.2 — Consentement partenaire (`opt_in`)

**Terminé**

RG-17 : « Le profil partenaire est désactivé par défaut. » `candidates()` filtrait
déjà sur `opt_in`, mais un appel direct à `POST /partner-requests` connaissant
l'identifiant du camarade ne passait pas par ce filtre : **le consentement n'était
pas vérifié sur le chemin d'écriture**.

| Fichier | Modification |
|---|---|
| `backend/app/Services/PartnerMatchingService.php` | `request()` refuse (403) si le destinataire n'a pas de profil ou un profil `opt_in = false`. Aucun profil n'est créé à la place du camarade. |

La fonction **n'existait pas** : aucun test ne couvrait le module partenaires.
Créé : `backend/tests/Feature/PartnerTest.php` (14 tests) — opt-in par défaut,
activation, candidats filtrés, refus d'un profil désactivé, refus d'un profil
inexistant, désactivation après une demande, hors-classe, enseignant, seul le
destinataire répond, doublon 409, et absence d'email dans les réponses.

Vérifié en échec avant correctif : `201 au lieu de 403` sur les 3 scénarios de consentement.

### 0.3 — Planificateur en production

**Terminé**

Le quota IA était réinitialisé par `Schedule::call(...)->dailyAt('00:05')`, mais
aucun `schedule:run` ne s'exécute en production : le quota d'un enseignant était
épuisé définitivement dès le premier jour.

Correctif aligné sur l'architecture de déploiement existante : les deux autres
tâches survivent via une route interne protégée par `X-Digest-Token` appelée par
GitHub Actions. La remise à zéro des quotas emprunte **exactement** le même
chemin, plutôt que d'introduire un service scheduler permanent.

| Fichier | Modification |
|---|---|
| `backend/routes/api.php` | `POST /api/internal/ai-quota-reset` sous middleware `digest.token`. |
| `.github/workflows/ai-quota-reset.yml` | Cron `5 0 * * *` (00:05 UTC), aligné sur la tâche `Schedule::call`. |

`routes/console.php` est **inchangé** : `schedule:run` reste valable en local.

Tests (`backend/tests/Feature/InternalRouteTest.php`) — les 4 tests de sécurité
existants couvrent désormais les 3 routes ; ajout de : nombre de fournisseurs
réinitialisés, compteur `used_today` remis à zéro et `last_reset_at` mis à jour,
`daily_limit` **inchangé**, un étudiant ne peut pas réinitialiser, et les tâches
`routes/console.php` restent déclarées.

### 0.4 — Message de session expirée

**Terminé**

T-25 : le 401 porte un message explicite et traduit. Le client le **jetait** :
`request()`/`download()` ne lisaient pas le corps de la réponse et levaient
`SessionExpiredError` avec le littéral `session_expired`, que `errorMessage()`
remplaçait ensuite par son `fallback` local. L'utilisateur était déconnecté sans
jamais apprendre pourquoi.

| Fichier | Modification |
|---|---|
| `frontend/src/lib/api.ts` | `readSessionMessage()` extrait le message 401 ; `SessionExpiredError` le transporte ; le handler 401 le reçoit. `errorMessage()` n'écrase plus le message serveur (le `fallback` ne sert que si l'API n'a rien renvoyé). |
| `frontend/src/context/AuthContext.tsx` | `sessionNotice` conserve le message 401 ; effacé à la reconnexion réussie. |
| `frontend/src/screens/PublicScreens.tsx` | L'écran de connexion affiche l'avertissement de session expirée. |

Le message brut `session_expired` ne peut pas fuiter : `SessionExpiredError` a un
message **vide** par défaut et un corps 401 non JSON ne fait pas échouer la
déconnexion.

Tests (`frontend/tests/api.test.ts`) : message traduit conservé (requête et
téléchargement), message transmis au handler 401, repli local si corps vide,
déconnexion maintenue sur corps non JSON. Le test existant
`falls back for an expired session instead of leaking the raw code` a été
**conservé** — son intention (ne jamais afficher le code brut) reste respectée.

Vérifié en échec avant correctif : 4 des 5 nouveaux tests.

### 0.5 — Proxys de confiance et TLS PostgreSQL

**Terminé**

`sslmode` était codé en dur à `prefer` : `render.yaml` fixe `DB_SSLMODE=require`,
qui était ignoré — dégradation silencieuse possible vers une connexion en clair.
Et sans `trustProxies`, `Request::ip()` valait l'adresse interne du proxy pour
**toutes** les requêtes : le journal d'audit ne traçait plus le client réel et les
quotas par IP devenaient globaux.

| Fichier | Modification |
|---|---|
| `backend/config/database.php` | `'sslmode' => env('DB_SSLMODE', 'prefer')`. |
| `backend/bootstrap/app.php` | `$middleware->trustProxies(at: env('TRUSTED_PROXIES', '*'))`. |
| `render.yaml` | `TRUSTED_PROXIES="*"` explicite, avec le motif en commentaire. |

Tests (`backend/tests/Feature/ProductionConfigTest.php`) : `sslmode` lu depuis
l'environnement, repli `prefer` en local, IP client lue dans `X-Forwarded-For`
derrière le proxy, liste de confiance configurable par l'environnement.

Vérifié en échec avant correctif : 3 tests sur 4.

---

## Phase 1 — ecarts Must

### 1.1 — CRUD et réordonnancement des questions
**Terminé**

Les questions n'étaient gérées que par la suppressedirecte d'un quiz : aucune
route pour ajouter, modifier, supprimer ni réordonner. §12.4 les exige.

| Fichier | Modification |
|---|---|
| `backend/app/Policies/QuizPolicy.php` | `manageQuestions()` : propriétaire de la classe, classe non en lecture seule, quiz **brouillon** uniquement. |
| `backend/app/Http/Requests/QuestionRequest.php` | Autorisation via la policy + validation (2 à 10 options, au moins une bonne réponse, unicité des libellés). |
| `backend/app/Http/Controllers/QuestionController.php` | `store`/`update`/`destroy`/`reorder`, renumérotation transactionnelle des positions, remplacement intégral des options. |
| `backend/app/Models/Quiz.php` | `QuizStatus::Draft` utilisé pour le contrôle d'accès (plus de transtypage). |

`reorder` exige une **permutation exacte** des questions du quiz : une liste
partielle ou dupliquée laisserait des questions sans position, donc hors de
l'ordre d'affichage.

Tests : `backend/tests/Feature/QuestionManagementTest.php` (23 tests) — cycle
nominal, options remplacées à la mise à jour, réordonnancement, refus d'une liste
incomplète, quiz publié / autre enseignant / étudiant / classe en lecture seule.

### 1.2 — Mélange des questions côté serveur
**Terminé**

§12.4 impose le mélange des questions et des options. Le mélange existait côté
client uniquement : un appel direct à l'API présentait les questions dans l'ordre
de la base, et l'ordre variait d'un appel à l'autre sans être figé pour une
tentative.

| Fichier | Modification |
|---|---|
| `backend/database/migrations/2026_10_03_000001_add_question_order_to_attempts_table.php` | Colonne `attempts.question_order` (JSON, nullable) — l'ordre servi est figé par tentative, comme l'exige l'audit. |
| `backend/app/Models/Attempt.php` | `question_order` fillable + cast `array`. |
| `backend/app/Services/QuizGradingService.php` | `start()` mélange côté serveur et persiste les identifiants ; `questionsFor()` sert cet ordre, avec repli sur l'ordre naturel si absent. |
| `backend/app/Http/Controllers/QuizController.php` | `startAttempt()` sert l'ordre figé et expose `shuffled`. |

Tests : `backend/tests/Feature/QuizShuffleTest.php` (12 tests) — ordre persistant
d'une tentative à l'autre, absence de fuite entre tentatives, mélange actif,
`question_order` réellement enregistré, repli si la colonne est absente.

Vérifié en échec avant correctif : 2 tests (ordre identique à la base).

### 1.3 — Publication IA bloquée par défaut (fail closed)
**Terminé**

Trois défauts fail-open enchaînés rendaient la porte de relecture décorative :

1. `quizzes.reviewed` et `flashcard_decks.reviewed` avaient pour **valeur par
   défaut `true`** en base : toute création qui n'écrivait pas la colonne naissait
   « relue ».
2. `publish()` **réécrivait `reviewed = true`** : publier certifiait la relecture,
   donc l'acte de publication était l'acte de relecture.
3. Rien n'attestait qu'un enseignant avait réellement ouvert le contenu : le
   drapeau n'était pas vérifiable.

| Fichier | Modification |
|---|---|
| `backend/database/migrations/2026_10_03_000002_make_ai_review_fail_closed.php` | Défaut `false` sur les deux colonnes, ajout de `reviewed_at` (nullable). Migration additive : aucune donnée existante n'est touchée. |
| `backend/app/Models/Quiz.php`, `backend/app/Models/FlashcardDeck.php` | `reviewed_at` fillable/casté, `markReviewed()` idempotent qui **conserve le premier** horodatage. |
| `backend/app/Http/Controllers/QuizController.php`, `FlashcardController.php` | `reviewed = true` retiré de `store()` et de `publish()`. |
| `backend/app/Http/Controllers/QuestionController.php` | Une **modification réelle** du contenu (ajout, édition, suppression, réordonnancement) appelle `markReviewed()` : avoir édité les questions prouve qu'elles ont été relues. |
| `backend/app/Http/Controllers/QuizController.php`, `FlashcardController.php` | `review` / `reviewed` posent l'horodatage via `markReviewed()`. |

Un quiz **manuel** reste publiable sans relecture : `canBePublished()` ne verrouille
que les contenus IA (RG-11).

Défaut préexistant corrigé au passage : `FlashcardController::publish()` déclarait
`: FlashcardDeckResource` mais renvoyait une `JsonResponse` sur sa branche 409. Le
TypeError rendait cette branche **injoignable** — le refus de publier un deck IA
non relu renvoyait 500 au lieu de 409, et n'était couvert par aucun test.

Tests : `backend/tests/Feature/AiReviewGateTest.php` (19 tests) — défauts à la
création, publication ne certifiant pas la relecture (quiz et deck), verrou IA
fermé sans relecture, déverrouillage par `review` puis publication, déverrouillage
par une modification réelle, **échec** de modification ne déverrouillant rien,
horodatage conservé, 403 enseignant tiers / étudiant primant sur 409.

Vérifié en échec avant correctif : 6 tests.

### 1.4 — Transitions de membres en attente uniquement
**Terminé**

RG-06 (Figure 4) : une décision ne porte que sur une demande **en attente**.
`accept()`/`reject()` n'avaient aucun contrôle d'état et étaient réversibles.

La faille concrète : « rejeter » un étudiant déjà accepté **le retirait par le
mauvais chemin** — notification `MEMBERSHIP_REJECTED` et délai de 24 h au lieu de
`MEMBERSHIP_REMOVED`, c'est-à-dire un retrait déguisé en refus de demande. Chaque
décision émettait en outre une seconde notification et une seconde ligne d'audit
pour un état déjà décidé.

| Fichier | Modification |
|---|---|
| `backend/app/Services/MembershipService.php` | `assertPending()` appelé par `accept()` et `reject()` : état non pending → `BusinessRuleException` 409 avec le statut courant en contexte. Le retrait reste le seul moyen de rompre une adhésion (`remove()`). |

L'ordre des gardes est délibéré : `assertOwner()` passe **avant**
`assertPending()`, donc 403 prime sur 409 — un enseignant tiers n'apprend ni
l'existence de la demande ni son état.

Tests ajoutés à `backend/tests/Feature/MembershipTest.php` (9 tests) — double
acceptation, rejet d'une adhésion acceptée, double rejet, retrait non ré-acceptable,
statut reported dans le 409, `remove()` toujours possible, 403 prioritaire,
étudiant ne peut pas se décider, `accept-all` limité au pending.

### 1.5 — Notifications d'annonce et de devoir
**Terminé**

F-CON-04 et F-DEV-01 notifient la classe à la publication. `POST /quizzes/{id}/publish`
notifiait déjà (`QUIZ_PUBLISHED`), mais ni la création d'une annonce ni celle d'un
devoir ne notifiait : un devoir créé n'était visible que si l'élève consultait la
liste au hasard.

| Fichier | Modification |
|---|---|
| `backend/app/Services/NotificationService.php` | Constantes `ANNOUNCEMENT_PUBLISHED` et `ASSIGNMENT_PUBLISHED`. |
| `backend/app/Http/Controllers/ContentController.php` | `storeAnnouncement()` notifie via `notifyMany()`. |
| `backend/app/Http/Controllers/AssignmentController.php` | `store()` notifie via `notifyMany()`, avec `due_at`. |

Destinataires = `Classroom::members()`, c'est-à-dire les membres **acceptés**
seulement (RG-06) : un pending, un rejected, un removed ou un élève d'une autre
classe ne reçoit rien. L'enseignant auteur n'est pas notifié de son propre contenu.
Le `payload` ne contient ni `author_id` ni le corps du texte.

Défaut préexistant corrigé au passage : `describeNotification()` (frontend)
comparait à des types à points (`quiz.published`, `announcement.created`) alors que
le backend n'émet que des constantes à underscores (`quiz_published`,
`announcement_published`). **Aucune** comparaison n'aboutissait : toute
notification s'affichait comme « Nouvelle notification ». Les clés de `payload`
étaient également toutes lues à vide (`payload.name`/`payload.class` au lieu de
`student_name`/`classroom_name`). Corrigé dans `frontend/src/components/AppShell.tsx`
et `frontend/src/i18n/{fr,en}.ts` — sans ce correctif, les notifications de la
Phase 1.5 seraient arrivées mais se seraient affichées comme génériques.

Tests : `backend/tests/Feature/ContentNotificationTest.php` (11 tests) et
`frontend/tests/notifications.test.ts` (8 tests).

Vérifié en échec avant correctif : 5 tests backend, tous les tests frontend de rendu.

### Hors périmètre — correction d'une incohérence de documentation

`docs/DEPLOYMENT.md` affirmait que `CLASSLINK_API_URL` incluait le préfixe `/api`,
alors que les trois workflows GitHub Actions ajoutent eux-mêmes `/api/...`. Un
secret contenant `/api` produisait `/api/api/internal/...` et **toutes** les tâches
planifiées échouaient en 404. La documentation a été alignée sur le comportement
réel des workflows (URL de base **sans** `/api`) ; les chemins `/internal/...`
cités dans le même document ont été complétés en `/api/internal/...`. Le workflow
`ai-quota-reset.yml` documente désormais explicitement la convention.

---

## Verification

| Phase | Backend | Frontend | Typecheck | Build |
|---|---|---|---|---|
| Reference (avant travaux) | 285 tests / 0 échec | 31/31 | OK | OK |
| Après Phase 0 | 311 tests / 826 assertions / 0 échec | 36/36 | OK | OK |
| Après Phase 1 | **385 tests / 1085 assertions / 0 échec** | **44/44** | **OK** | **OK** |

Les marqueurs `deprecated` visibles dans la sortie de `php artisan test` sont des
avertissements PHP 8.5 émis par `vendor/` (`PDO::MYSQL_ATTR_SSL_CA`,
`ReflectionMethod::setAccessible`) : préexistants, sans lien avec les
modifications.

## Suite

Phase 2 et au-delà : **non engagées**. Les écarts Must qui y sont classés restent
ouverts et sont reportés explicitement dans le rapport final — notamment la
confirmation de déconnexion, l'interface d'édition de questions, les libellés
codés en dur dans l'interface, la navigation mobile et les états d'interface
(absence de données / chargement / erreur).