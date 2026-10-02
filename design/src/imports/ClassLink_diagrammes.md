# ClassLink 1.0 : code des diagrammes

Chaque bloc ci-dessous correspond à une figure du cahier des charges.

- **Mermaid** : coller le code sur https://mermaid.live puis exporter en PNG ou SVG.
- **PlantUML** : coller le code sur https://www.plantuml.com/plantuml puis télécharger le PNG ou SVG.
- **draw.io** : Organiser, Insérer, Avancé, puis Mermaid ou PlantUML.

---

## Figure 1 : Diagramme de cas d'utilisation général

Outil : PlantUML

```plantuml
@startuml
left to right direction
skinparam packageStyle rectangle
actor "Étudiant" as S
actor "Enseignant" as T
actor "Super admin" as A
actor "Microsoft (OAuth)" as MS
actor "Fournisseur IA" as AI

rectangle "ClassLink 1.0" {
  usecase "S'authentifier" as UC1
  usecase "Se déconnecter" as UC2
  usecase "Rejoindre une classe" as UC3
  usecase "Consulter les contenus" as UC4
  usecase "Passer un quiz" as UC5
  usecase "Rendre un devoir" as UC6
  usecase "Suivre sa progression" as UC7
  usecase "Trouver un partenaire d'étude" as UC8
  usecase "Créer et gérer une classe" as UC9
  usecase "Traiter les demandes d'adhésion" as UC10
  usecase "Publier contenus et annonces" as UC11
  usecase "Créer un quiz manuellement" as UC12
  usecase "Générer un quiz avec l'IA" as UC13
  usecase "Corriger les devoirs" as UC14
  usecase "Suivre la progression de la classe" as UC15
  usecase "Gérer utilisateurs et rôles" as UC16
  usecase "Configurer l'IA" as UC17
  usecase "Consulter le journal d'audit" as UC18
}

S --> UC1
S --> UC2
S --> UC3
S --> UC4
S --> UC5
S --> UC6
S --> UC7
S --> UC8
T --> UC1
T --> UC2
T --> UC9
T --> UC10
T --> UC11
T --> UC12
T --> UC13
T --> UC14
T --> UC15
A --> UC16
A --> UC17
A --> UC18
UC1 --> MS
UC13 --> AI
UC13 ..> UC12 : <<extend>>
UC3 ..> UC1 : <<include>>
@enduml
```

---

## Figure 2 : Diagramme d'activité : parcours complet d'un étudiant, de la connexion à la déconnexion

Outil : Mermaid

```mermaid
flowchart TD
A(["Début : arrivée sur ClassLink"]) --> B["Page d'accueil"]
B --> C["Clic sur Se connecter"]
C --> D{"Compte Microsoft accessible ?"}
D -- "Oui" --> E["Connexion Microsoft"]
D -- "Non" --> F["Connexion par code email"]
E --> G{"Domaine ofppt-edu.ma et rôle étudiant ?"}
F --> G
G -- "Non" --> H["Accès refusé ou compte en attente"]
H --> Z
G -- "Oui" --> I{"Première connexion ?"}
I -- "Oui" --> J["Choix du nom d'affichage et de la langue"]
I -- "Non" --> K["Tableau de bord"]
J --> K
K --> L{"A déjà une classe ?"}
L -- "Non" --> M["Saisir le code de classe"]
M --> N["Demande en attente"]
N --> O{"Décision de l'enseignant"}
O -- "Rejetée" --> M
O -- "Acceptée" --> P["Classe visible dans Mes classes"]
L -- "Oui" --> P
P --> Q["Consulter annonces et ressources"]
Q --> R["Passer un quiz et voir la correction"]
R --> S["Rendre un devoir"]
S --> T["Consulter sa progression"]
T --> U["Chercher un partenaire d'étude (optionnel)"]
U --> V["Menu profil, Se déconnecter"]
V --> W["Confirmation"]
W --> X["Jeton révoqué et session effacée"]
X --> Z(["Fin : retour à la page d'accueil"])
```

---

## Figure 3 : Diagramme d'activité : parcours complet d'un enseignant

Outil : Mermaid

```mermaid
flowchart TD
A(["Début"]) --> B["Connexion Microsoft ou code email"]
B --> C["Rôle enseignant détecté"]
C --> D["Tableau de bord enseignant"]
D --> E["Créer une classe"]
E --> F["Copier le code d'invitation"]
F --> G["Partager le code aux étudiants"]
G --> H["Recevoir les demandes"]
H --> I{"Étudiant de la vraie classe ?"}
I -- "Oui" --> J["Accepter"]
I -- "Non" --> K["Rejeter"]
J --> L["Publier annonces et ressources"]
K --> H
L --> M{"Créer un quiz"}
M -- "Manuel" --> N["Éditeur de quiz"]
M -- "IA" --> O["Téléverser un PDF"]
O --> P{"IA disponible ?"}
P -- "Oui" --> Q["Quiz brouillon généré"]
P -- "Non" --> N
Q --> R["Relecture et corrections"]
N --> S["Publier le quiz"]
R --> S
S --> T["Consulter résultats et progression"]
T --> U["Corriger les devoirs"]
U --> V["Se déconnecter"]
V --> W(["Fin"])
```

---

## Figure 4 : Diagramme d'activité : traitement d'une demande d'adhésion

Outil : Mermaid

```mermaid
flowchart TD
A(["Étudiant saisit un code"]) --> B{"Code existant et actif ?"}
B -- "Non" --> C["Erreur : code invalide"]
B -- "Oui" --> D{"Déjà membre ?"}
D -- "Oui" --> E["Message : déjà dans la classe"]
D -- "Non" --> F{"Demande déjà en attente ?"}
F -- "Oui" --> G["Message : demande en cours"]
F -- "Non" --> H{"Rejeté il y a moins de 24 h ?"}
H -- "Oui" --> I["Message : réessayer plus tard"]
H -- "Non" --> J["Créer adhésion pending"]
J --> K["Notifier l'enseignant"]
K --> L{"Décision"}
L -- "Accepter" --> M["Statut accepted et notification"]
L -- "Rejeter" --> N["Statut rejected et notification"]
```

---

## Figure 5 : Plan du site (arborescence des écrans)

Outil : Mermaid

```mermaid
flowchart TD
ROOT["ClassLink"]
ROOT --> PUB["Zone publique"]
ROOT --> STU["Espace étudiant"]
ROOT --> TEA["Espace enseignant"]
ROOT --> ADM["Espace super admin"]
PUB --> P1["Accueil"]
PUB --> P2["Connexion"]
PUB --> P3["Accès refusé"]
PUB --> P4["Confidentialité"]
STU --> S1["Tableau de bord"]
STU --> S2["Mes classes"]
S2 --> S21["Rejoindre une classe"]
S2 --> S22["Page de classe : annonces, ressources, quiz, devoirs, membres"]
STU --> S3["Quiz : liste, passage, résultat"]
STU --> S4["Échéances"]
STU --> S5["Partenaires d'étude"]
STU --> S6["Profil et notifications"]
TEA --> T1["Tableau de bord"]
TEA --> T2["Mes classes"]
T2 --> T21["Créer une classe"]
T2 --> T22["Gestion de classe : aperçu, demandes, membres, annonces, ressources, quiz, devoirs, paramètres"]
TEA --> T3["Progression"]
TEA --> T4["Profil et notifications"]
ADM --> A1["Vue d'ensemble"]
ADM --> A2["Utilisateurs"]
ADM --> A3["Classes"]
ADM --> A4["Paramètres IA"]
ADM --> A5["Journal d'audit"]
```

---

## Figure 6 : Diagramme de classes

Outil : Mermaid

```mermaid
classDiagram
class User {
  +int id
  +string email
  +string displayName
  +string role
  +string locale
  +bool isActive
  +login()
  +logout()
}
class Classroom {
  +int id
  +string name
  +string subject
  +string groupLabel
  +string schoolYear
  +string joinCode
  +bool joinEnabled
  +string status
  +regenerateCode()
  +archive()
}
class Membership {
  +int id
  +string status
  +datetime requestedAt
  +datetime decidedAt
  +accept()
  +reject()
  +remove()
}
class Material {
  +int id
  +string title
  +string chapter
  +string type
  +string path
}
class Announcement {
  +int id
  +string title
  +text body
  +bool pinned
}
class Quiz {
  +int id
  +string title
  +string status
  +string source
  +int timeLimit
  +int maxAttempts
  +publish()
}
class Question {
  +int id
  +text statement
  +string type
  +text explanation
  +int position
}
class Option {
  +int id
  +string label
  +bool isCorrect
}
class Attempt {
  +int id
  +int attemptNo
  +float score
  +datetime submittedAt
  +submit()
}
class Assignment {
  +int id
  +string title
  +datetime dueAt
}
class Submission {
  +int id
  +string filePath
  +float grade
  +text feedback
  +bool isLate
}
class Notification {
  +int id
  +string type
  +json payload
  +datetime readAt
}
class PartnerProfile {
  +int id
  +bool optIn
  +json skills
  +string availability
}
class AiJob {
  +int id
  +string fileHash
  +string status
  +string provider
}
User "1" --> "0..*" Classroom : enseigne
User "1" --> "0..*" Membership : demande
Classroom "1" --> "0..*" Membership : contient
Classroom "1" --> "0..*" Material : possède
Classroom "1" --> "0..*" Announcement : diffuse
Classroom "1" --> "0..*" Quiz : propose
Classroom "1" --> "0..*" Assignment : donne
Quiz "1" --> "1..*" Question : compose
Question "1" --> "2..*" Option : offre
User "1" --> "0..*" Attempt : réalise
Quiz "1" --> "0..*" Attempt : reçoit
Assignment "1" --> "0..*" Submission : reçoit
User "1" --> "0..*" Submission : dépose
User "1" --> "0..*" Notification : reçoit
User "1" --> "0..1" PartnerProfile : possède
User "1" --> "0..*" AiJob : lance
AiJob "0..1" --> "0..1" Quiz : produit
```

---

## Figure 7 : Modèle entité-association (base de données)

Outil : Mermaid

```mermaid
erDiagram
USERS ||--o{ CLASSROOMS : "enseigne"
USERS ||--o{ MEMBERSHIPS : "demande"
CLASSROOMS ||--o{ MEMBERSHIPS : "contient"
CLASSROOMS ||--o{ MATERIALS : "possede"
CLASSROOMS ||--o{ ANNOUNCEMENTS : "diffuse"
CLASSROOMS ||--o{ QUIZZES : "propose"
CLASSROOMS ||--o{ ASSIGNMENTS : "donne"
CLASSROOMS ||--o{ FLASHCARD_DECKS : "regroupe"
QUIZZES ||--|{ QUESTIONS : "compose"
QUESTIONS ||--|{ OPTIONS : "offre"
QUIZZES ||--o{ ATTEMPTS : "recoit"
USERS ||--o{ ATTEMPTS : "realise"
ATTEMPTS ||--o{ ATTEMPT_ANSWERS : "contient"
QUESTIONS ||--o{ ATTEMPT_ANSWERS : "concerne"
FLASHCARD_DECKS ||--o{ FLASHCARDS : "contient"
ASSIGNMENTS ||--o{ SUBMISSIONS : "recoit"
USERS ||--o{ SUBMISSIONS : "depose"
USERS ||--o{ NOTIFICATIONS : "recoit"
USERS ||--o| PARTNER_PROFILES : "possede"
USERS ||--o{ PARTNER_REQUESTS : "envoie"
USERS ||--o{ AI_JOBS : "lance"
USERS ||--o{ AUDIT_LOGS : "genere"
USERS ||--o{ OTP_CODES : "recoit"

USERS {
  bigint id PK
  string email UK
  string display_name
  string role "student, teacher, admin, pending"
  boolean role_locked
  string locale "fr, en"
  boolean is_active
  timestamp last_login_at
}
CLASSROOMS {
  bigint id PK
  bigint teacher_id FK
  string name
  string subject
  string group_label
  string school_year
  string join_code UK
  boolean join_enabled
  string status "active, archived"
}
MEMBERSHIPS {
  bigint id PK
  bigint classroom_id FK
  bigint student_id FK
  string status "pending, accepted, rejected, removed"
  timestamp requested_at
  timestamp decided_at
  bigint decided_by FK
}
MATERIALS {
  bigint id PK
  bigint classroom_id FK
  string title
  string chapter
  string type "file, link"
  string path_or_url
  bigint uploaded_by FK
}
ANNOUNCEMENTS {
  bigint id PK
  bigint classroom_id FK
  bigint author_id FK
  string title
  text body
  boolean pinned
}
QUIZZES {
  bigint id PK
  bigint classroom_id FK
  bigint created_by FK
  string title
  string status "draft, published, archived"
  string source "manual, ai"
  int time_limit_min
  int max_attempts
  boolean shuffle
  boolean show_answers
}
QUESTIONS {
  bigint id PK
  bigint quiz_id FK
  text statement
  string type "single, multiple, true_false"
  text explanation
  int position
}
OPTIONS {
  bigint id PK
  bigint question_id FK
  string label
  boolean is_correct
}
ATTEMPTS {
  bigint id PK
  bigint quiz_id FK
  bigint student_id FK
  int attempt_no
  float score
  timestamp started_at
  timestamp submitted_at
}
ATTEMPT_ANSWERS {
  bigint id PK
  bigint attempt_id FK
  bigint question_id FK
  json selected_option_ids
  boolean is_correct
}
FLASHCARD_DECKS {
  bigint id PK
  bigint classroom_id FK
  string title
  string source "manual, ai"
  string status "draft, published"
}
FLASHCARDS {
  bigint id PK
  bigint deck_id FK
  text front
  text back
}
ASSIGNMENTS {
  bigint id PK
  bigint classroom_id FK
  string title
  text instructions
  timestamp due_at
}
SUBMISSIONS {
  bigint id PK
  bigint assignment_id FK
  bigint student_id FK
  string file_path
  boolean is_late
  float grade
  text feedback
}
NOTIFICATIONS {
  bigint id PK
  bigint user_id FK
  string type
  json payload
  timestamp read_at
}
PARTNER_PROFILES {
  bigint id PK
  bigint user_id FK
  boolean opt_in
  json skills
  string availability
}
PARTNER_REQUESTS {
  bigint id PK
  bigint from_user_id FK
  bigint to_user_id FK
  bigint classroom_id FK
  string status "pending, accepted, declined"
}
AI_JOBS {
  bigint id PK
  bigint teacher_id FK
  bigint classroom_id FK
  string file_hash
  string status "queued, processing, done, failed"
  string provider
  bigint quiz_id FK
  text error
}
AI_PROVIDERS {
  bigint id PK
  string name UK
  int priority
  boolean enabled
  int daily_limit
  int used_today
}
AUDIT_LOGS {
  bigint id PK
  bigint user_id FK
  string action
  json context
  string ip
  timestamp created_at
}
OTP_CODES {
  bigint id PK
  bigint user_id FK
  string code_hash
  int attempts
  timestamp expires_at
}
```

---

## Figure 8 : Diagramme d'états : adhésion à une classe

Outil : Mermaid

```mermaid
stateDiagram-v2
[*] --> pending: L'étudiant envoie une demande
pending --> accepted: L'enseignant accepte
pending --> rejected: L'enseignant rejette
rejected --> pending: Nouvelle demande après 24 h
accepted --> removed: L'enseignant retire l'étudiant
removed --> pending: Nouvelle demande
accepted --> [*]: Classe archivée
```

---

## Figure 9 : Diagramme d'états : quiz et tâche IA

Outil : Mermaid

```mermaid
stateDiagram-v2
state "Quiz" as Q {
  [*] --> draft
  draft --> published: Relecture puis publication
  published --> draft: Dépublication (si aucune tentative)
  published --> archived: Archivage
  archived --> [*]
}
state "Tâche IA" as J {
  [*] --> queued
  queued --> processing
  processing --> done: Quiz brouillon créé
  processing --> failed: Tous les fournisseurs ont échoué
  failed --> queued: Nouvel essai
  done --> [*]
}
```

---

## Figure 10 : Diagramme de séquence : connexion Microsoft et détection du rôle

Outil : Mermaid

```mermaid
sequenceDiagram
autonumber
actor U as Utilisateur
participant F as Frontend React
participant B as API Laravel
participant M as Microsoft Entra ID
participant D as Base de données
U->>F: Clique sur Se connecter avec Microsoft
F->>B: GET /api/auth/microsoft/redirect
B-->>U: Redirection vers la page Microsoft
U->>M: Saisit ses identifiants scolaires
M-->>B: Callback avec code d'autorisation
B->>M: Échange le code contre le profil
M-->>B: Email et nom
B->>B: Vérifie le domaine ofppt-edu.ma
alt Domaine refusé
  B-->>F: Redirection vers /access-denied
  F-->>U: Message accès refusé
else Domaine valide
  B->>B: Détecte le rôle avec RoleDetector
  B->>D: Crée ou retrouve l'utilisateur
  B->>D: Écrit auth.login dans audit_logs
  B-->>F: Redirection avec jeton Sanctum (8 h)
  F->>F: Stocke le jeton en sessionStorage
  F->>B: GET /api/me
  B-->>F: Profil et rôle
  F-->>U: Affiche le tableau de bord du rôle
end
```

---

## Figure 11 : Diagramme de séquence : connexion de secours par code email

Outil : Mermaid

```mermaid
sequenceDiagram
autonumber
actor U as Utilisateur
participant F as Frontend React
participant B as API Laravel
participant E as Brevo (email)
U->>F: Saisit son email scolaire
F->>B: POST /api/auth/otp/request
B->>B: Valide le domaine et limite le débit
B->>E: Envoie un code à 6 chiffres
E-->>U: Email avec le code (valable 10 min)
B-->>F: 202 Code envoyé
U->>F: Saisit le code
F->>B: POST /api/auth/otp/verify
alt Code valide
  B-->>F: Jeton Sanctum
  F-->>U: Tableau de bord
else Code faux ou expiré
  B-->>F: 422 Code invalide
  F-->>U: Message d'erreur (5 essais max)
end
```

---

## Figure 12 : Diagramme de séquence : demande d'adhésion à une classe

Outil : Mermaid

```mermaid
sequenceDiagram
autonumber
actor E as Étudiant
actor P as Enseignant
participant F as Frontend React
participant B as API Laravel
participant D as Base de données
E->>F: Saisit le code de la classe
F->>B: POST /api/join-requests
B->>D: Cherche la classe par code
alt Code inconnu ou désactivé
  B-->>F: 404 Code invalide
  F-->>E: Message d'erreur
else Demande déjà en attente ou délai après rejet
  B-->>F: 409 ou 429
  F-->>E: Message explicatif
else Demande valide
  B->>D: Crée l'adhésion avec le statut pending
  B->>D: Crée la notification pour l'enseignant
  B-->>F: 201 Demande envoyée
  F-->>E: Statut En attente
end
P->>F: Ouvre la liste des demandes
F->>B: GET /api/classes/{id}/join-requests
B-->>F: Liste des demandes pending
P->>F: Clique sur Accepter
F->>B: POST /api/join-requests/{id}/accept
B->>D: Statut accepted et decided_by
B->>D: Notification pour l'étudiant
B-->>F: 200 OK
F-->>P: Étudiant ajouté aux membres
```

---

## Figure 13 : Diagramme de séquence : génération d'un quiz par l'IA avec bascule de fournisseur

Outil : Mermaid

```mermaid
sequenceDiagram
autonumber
actor P as Enseignant
participant F as Frontend React
participant B as API Laravel
participant S as AiService
participant A1 as Fournisseur IA 1
participant A2 as Fournisseur IA 2
participant D as Base de données
P->>F: Téléverse un PDF de cours
F->>B: POST /api/classes/{id}/ai/generate
B->>B: Vérifie rôle, quota, taille et type du fichier
B->>D: Cherche le hash du fichier (cache)
alt Résultat en cache
  B-->>F: 200 Quiz brouillon existant
else Pas de cache
  B->>D: Crée ai_jobs (queued)
  B-->>F: 202 job_id
  B->>S: Lance la génération en arrière-plan
  S->>A1: Requête (extrait du cours)
  alt Limite atteinte ou erreur
    A1-->>S: 429 ou 5xx
    S->>A2: Même requête
    A2-->>S: JSON de questions
  else Succès
    A1-->>S: JSON de questions
  end
  S->>S: Valide le JSON, retente une fois si invalide
  S->>D: Crée le quiz en brouillon et ai_jobs (done)
end
F->>B: GET /api/ai/jobs/{id} (interrogation régulière)
B-->>F: Statut done et quiz_id
F-->>P: Ouvre l'éditeur de relecture
P->>F: Corrige puis publie
F->>B: POST /api/quizzes/{id}/publish
```

---

## Figure 14 : Diagramme de séquence : passage d'un quiz par un étudiant

Outil : Mermaid

```mermaid
sequenceDiagram
autonumber
actor E as Étudiant
participant F as Frontend React
participant B as API Laravel
participant D as Base de données
E->>F: Clique sur Commencer le quiz
F->>B: POST /api/quizzes/{id}/attempts
B->>D: Vérifie membre accepté, quiz publié, tentatives restantes
B->>D: Crée la tentative (started_at)
B-->>F: Questions sans les bonnes réponses
loop Pour chaque question
  E->>F: Choisit une réponse
  F->>F: Enregistre localement la réponse
end
E->>F: Termine ou le minuteur expire
F->>B: POST /api/attempts/{id}/submit
B->>B: Corrige côté serveur et calcule le score
B->>D: Enregistre les réponses et le score
B-->>F: Score et correction (selon les réglages)
F-->>E: Affiche le résultat
```

---

## Figure 15 : Diagramme de séquence : déconnexion et expiration de session

Outil : Mermaid

```mermaid
sequenceDiagram
autonumber
actor U as Utilisateur
participant F as Frontend React
participant B as API Laravel
participant D as Base de données
U->>F: Clique sur Se déconnecter
F->>U: Demande de confirmation
U->>F: Confirme
F->>B: POST /api/auth/logout (Bearer token)
B->>D: Supprime le jeton d'accès courant
B->>D: Écrit auth.logout dans audit_logs
B-->>F: 204 No Content
F->>F: Efface sessionStorage et cache des données
F-->>U: Redirige vers la page d'accueil
Note over U,B: Cas de l'expiration automatique
U->>F: Action après 8 heures
F->>B: Requête avec un jeton expiré
B-->>F: 401 Unauthorized
F->>F: Efface la session locale
F-->>U: Page de connexion avec le message Session expirée
```

---

## Figure 16 : Architecture technique globale

Outil : Mermaid

```mermaid
flowchart LR
U["Navigateur : étudiant, enseignant, super admin"]
subgraph Vercel
  FE["Frontend React (Vite)"]
end
subgraph Render
  API["API Laravel 11 (Docker)"]
end
DB[("Base de données MySQL ou PostgreSQL")]
ST[("Stockage de fichiers S3-compatible")]
MS["Microsoft Entra ID (OAuth 2.0)"]
BR["Brevo (emails)"]
AI["Fournisseurs IA (API compatible OpenAI)"]
GH["GitHub (code et CI)"]
U -->|"HTTPS"| FE
FE -->|"HTTPS et JSON (Bearer)"| API
API --> DB
API --> ST
API -->|"OAuth"| MS
API -->|"SMTP ou API"| BR
API -->|"HTTPS"| AI
GH -->|"Déploiement automatique"| FE
GH -->|"Déploiement automatique"| API
```

---

## Figure 17 : Planning prévisionnel (diagramme de Gantt)

Outil : Mermaid

```mermaid
gantt
dateFormat YYYY-MM-DD
title Planning ClassLink 1.0
section Sprint 0 Cadrage
Cahier des charges et Jira :s0a, 2026-10-05, 4d
Maquettes Figma (écrans clés) :s0b, 2026-10-05, 7d
Dépôt GitHub et environnements :s0c, 2026-10-07, 3d
section Sprint 1 Authentification
Laravel, Sanctum, Microsoft OAuth :s1a, 2026-10-12, 8d
Détection du rôle et code email :s1b, after s1a, 4d
Layouts React, i18n, profil :s1c, 2026-10-12, 10d
section Sprint 2 Classes et adhésions
Classes et codes d'invitation :s2a, 2026-10-26, 5d
Demandes d'adhésion et notifications :s2b, after s2a, 7d
Membres et rôles admin :s2c, after s2b, 2d
section Sprint 3 Contenus et quiz
Ressources et annonces :s3a, 2026-11-09, 5d
Quiz manuels et passage :s3b, after s3a, 7d
Résultats et correction :s3c, after s3b, 2d
section Sprint 4 Fonctions Should
Génération IA et bascule :s4a, 2026-11-23, 6d
Devoirs et progression :s4b, after s4a, 5d
Partenaires d'étude :s4c, after s4b, 3d
section Sprint 5 Finalisation
Tests et corrections :s5a, 2026-12-07, 4d
Déploiement et démo :s5b, after s5a, 3d
```

