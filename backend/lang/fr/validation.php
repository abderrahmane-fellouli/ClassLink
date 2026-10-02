<?php

/*
|--------------------------------------------------------------------------
| Fichiers de langue
|--------------------------------------------------------------------------
|
| Version 1.0 : le français et l'anglais uniquement (§1.5 — l'arabe est
| exclu de la v1.0).
|
| Laravel ne fournit que l'anglais : sans ce fichier, une validation en
| français renvoie la clé brute (`validation.required`), ce qui viole
| T-26 « aucun texte non traduit ».
|
*/

return [

    /*
    |--------------------------------------------------------------------------
    | Messages de validation
    |--------------------------------------------------------------------------
    */

    'accepted' => 'Le champ :attribute doit être accepté.',
    'accepted_if' => 'Le champ :attribute doit être accepté lorsque :other est :value.',
    'active_url' => 'Le champ :attribute doit être une URL valide.',
    'after' => 'Le champ :attribute doit être une date postérieure à :date.',
    'after_or_equal' => 'Le champ :attribute doit être une date postérieure ou égale à :date.',
    'alpha' => 'Le champ :attribute ne doit contenir que des lettres.',
    'alpha_dash' => 'Le champ :attribute ne doit contenir que des lettres, des chiffres, des tirets et des soulignés.',
    'alpha_num' => 'Le champ :attribute ne doit contenir que des lettres et des chiffres.',
    'array' => 'Le champ :attribute doit être un tableau.',
    'ascii' => 'Le champ :attribute ne doit contenir que des caractères ASCII.',
    'before' => 'Le champ :attribute doit être une date antérieure à :date.',
    'before_or_equal' => 'Le champ :attribute doit être une date antérieure ou égale à :date.',
    'between' => [
        'array' => 'Le champ :attribute doit contenir entre :min et :max éléments.',
        'file' => 'Le champ :attribute doit peser entre :min et :max kilo-octets.',
        'numeric' => 'Le champ :attribute doit être compris entre :min et :max.',
        'string' => 'Le champ :attribute doit contenir entre :min et :max caractères.',
    ],
    'boolean' => 'Le champ :attribute doit être vrai ou faux.',
    'can' => 'Le champ :attribute contient une valeur non autorisée.',
    'confirmed' => 'La confirmation du champ :attribute ne correspond pas.',
    'contains' => 'Le champ :attribute ne contient pas de valeur autorisée.',
    'current_password' => 'Le mot de passe est incorrect.',
    'date' => 'Le champ :attribute doit être une date valide.',
    'date_equals' => 'Le champ :attribute doit être une date égale à :date.',
    'date_format' => 'Le champ :attribute ne correspond pas au format :format.',
    'decimal' => 'Le champ :attribute doit contenir :decimal décimales.',
    'declined' => 'Le champ :attribute doit être refusé.',
    'declined_if' => 'Le champ :attribute doit être refusé lorsque :other est :value.',
    'different' => 'Les champs :attribute et :other doivent être différents.',
    'digits' => 'Le champ :attribute doit contenir :digits chiffres.',
    'digits_between' => 'Le champ :attribute doit contenir entre :min et :max chiffres.',
    'dimensions' => 'Le champ :attribute a des dimensions d’image invalides.',
    'distinct' => 'Le champ :attribute a une valeur en double.',
    'doesnt_end_with' => 'Le champ :attribute ne doit pas se terminer par :values.',
    'doesnt_start_with' => 'Le champ :attribute ne doit pas commencer par :values.',
    'email' => 'Le champ :attribute doit être une adresse e-mail valide.',
    'ends_with' => 'Le champ :attribute doit se terminer par :values.',
    'enum' => 'La valeur sélectionnée pour :attribute est invalide.',
    'exists' => 'La valeur sélectionnée pour :attribute est invalide.',
    'extensions' => 'Le champ :attribute doit avoir l’une des extensions suivantes :values.',
    'file' => 'Le champ :attribute doit être un fichier.',
    'filled' => 'Le champ :attribute ne peut pas être vide.',
    'gt' => [
        'array' => 'Le champ :attribute doit contenir plus de :value éléments.',
        'file' => 'Le champ :attribute doit peser plus de :value kilo-octets.',
        'numeric' => 'Le champ :attribute doit être supérieur à :value.',
        'string' => 'Le champ :attribute doit contenir plus de :value caractères.',
    ],
    'gte' => [
        'array' => 'Le champ :attribute doit contenir au moins :value éléments.',
        'file' => 'Le champ :attribute doit peser au moins :value kilo-octets.',
        'numeric' => 'Le champ :attribute doit être supérieur ou égal à :value.',
        'string' => 'Le champ :attribute doit contenir au moins :value caractères.',
    ],
    'hex_color' => 'Le champ :attribute doit être une couleur hexadécimale valide.',
    'image' => 'Le champ :attribute doit être une image.',
    'in' => 'La valeur sélectionnée pour :attribute est invalide.',
    'in_array' => 'Le champ :attribute n’existe pas dans :other.',
    'integer' => 'Le champ :attribute doit être un entier.',
    'ip' => 'Le champ :attribute doit être une adresse IP valide.',
    'ipv4' => 'Le champ :attribute doit être une adresse IPv4 valide.',
    'ipv6' => 'Le champ :attribute doit être une adresse IPv6 valide.',
    'json' => 'Le champ :attribute doit être une chaîne JSON valide.',
    'list' => 'Le champ :attribute doit être une liste.',
    'lowercase' => 'Le champ :attribute doit être en minuscules.',
    'lt' => [
        'array' => 'Le champ :attribute doit contenir moins de :value éléments.',
        'file' => 'Le champ :attribute doit peser moins de :value kilo-octets.',
        'numeric' => 'Le champ :attribute doit être inférieur à :value.',
        'string' => 'Le champ :attribute doit contenir moins de :value caractères.',
    ],
    'lte' => [
        'array' => 'Le champ :attribute ne doit pas contenir plus de :value éléments.',
        'file' => 'Le champ :attribute doit peser au plus :value kilo-octets.',
        'numeric' => 'Le champ :attribute doit être inférieur ou égal à :value.',
        'string' => 'Le champ :attribute doit contenir au plus :value caractères.',
    ],
    'mac_address' => 'Le champ :attribute doit être une adresse MAC valide.',
    'max' => [
        'array' => 'Le champ :attribute ne doit pas contenir plus de :max éléments.',
        'file' => 'Le champ :attribute ne doit pas peser plus de :max kilo-octets.',
        'numeric' => 'Le champ :attribute ne doit pas être supérieur à :max.',
        'string' => 'Le champ :attribute ne doit pas contenir plus de :max caractères.',
    ],
    'max_digits' => 'Le champ :attribute ne doit pas contenir plus de :max chiffres.',
    'mimes' => 'Le champ :attribute doit être un fichier de type :values.',
    'mimetypes' => 'Le champ :attribute doit être un fichier de type :values.',
    'min' => [
        'array' => 'Le champ :attribute doit contenir au moins :min éléments.',
        'file' => 'Le champ :attribute doit peser au moins :min kilo-octets.',
        'numeric' => 'Le champ :attribute doit être au moins :min.',
        'string' => 'Le champ :attribute doit contenir au moins :min caractères.',
    ],
    'min_digits' => 'Le champ :attribute doit contenir au moins :min chiffres.',
    'missing' => 'Le champ :attribute est absent.',
    'multiple_of' => 'Le champ :attribute doit être un multiple de :value.',
    'not_in' => 'La valeur sélectionnée pour :attribute est invalide.',
    'not_regex' => 'Le format du champ :attribute est invalide.',
    'numeric' => 'Le champ :attribute doit être un nombre.',
    'password' => [
        'letters' => 'Le champ :attribute doit contenir au moins une lettre.',
        'mixed' => 'Le champ :attribute doit contenir au moins une majuscule et une minuscule.',
        'numbers' => 'Le champ :attribute doit contenir au moins un chiffre.',
        'symbols' => 'Le champ :attribute doit contenir au moins un symbole.',
        'uncompromised' => 'Le champ :attribute est compromis et doit être changé.',
    ],
    'present' => 'Le champ :attribute doit être présent.',
    'prohibited' => 'Le champ :attribute est interdit.',
    'prohibited_if' => 'Le champ :attribute est interdit lorsque :other est :value.',
    'prohibited_unless' => 'Le champ :attribute est interdit sauf si :other vaut :values.',
    'prohibits' => 'Le champ :attribute interdit :other.',
    'range' => 'Le champ :attribute doit être compris entre :min et :max.',
    'regex' => 'Le format du champ :attribute est invalide.',
    'required' => 'Le champ :attribute est obligatoire.',
    'required_array_keys' => 'Le champ :attribute doit contenir des entrées pour :values.',
    'required_if' => 'Le champ :attribute est obligatoire lorsque :other est :value.',
    'required_if_accepted' => 'Le champ :attribute est obligatoire lorsque :other est accepté.',
    'required_if_declined' => 'Le champ :attribute est obligatoire lorsque :other est refusé.',
    'required_unless' => 'Le champ :attribute est obligatoire sauf si :other vaut :values.',
    'required_with' => 'Le champ :attribute est obligatoire quand :values est présent.',
    'required_with_all' => 'Le champ :attribute est obligatoire quand :values est présent.',
    'required_without' => 'Le champ :attribute est obligatoire quand :values n’est pas présent.',
    'required_without_all' => 'Le champ :attribute est obligatoire quand :values n’est pas présent.',
    'same' => 'Les champs :attribute et :other doivent être identiques.',
    'size' => [
        'array' => 'Le champ :attribute doit contenir :size éléments.',
        'file' => 'Le champ :attribute doit peser :size kilo-octets.',
        'numeric' => 'Le champ :attribute doit valoir :size.',
        'string' => 'Le champ :attribute doit contenir :size caractères.',
    ],
    'starts_with' => 'Le champ :attribute doit commencer par :values.',
    'string' => 'Le champ :attribute doit être une chaîne de caractères.',
    'timezone' => 'Le champ :attribute doit être un fuseau horaire valide.',
    'unique' => 'Cette valeur de :attribute est déjà utilisée.',
    'uploaded' => 'Le fichier :attribute n’a pas pu être téléversé.',
    'uppercase' => 'Le champ :attribute doit être en majuscules.',
    'url' => 'Le champ :attribute doit être une URL valide.',
    'ulid' => 'Le champ :attribute doit être un ULID valide.',
    'uuid' => 'Le champ :attribute doit être un UUID valide.',

    /*
    |--------------------------------------------------------------------------
    | Messages personnalisés
    |--------------------------------------------------------------------------
    */

    'custom' => [
        'file' => [
            'mimes' => 'Seuls les fichiers PDF sont acceptés.',
            'max' => 'Fichier trop volumineux.',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Noms d'attributs
    |--------------------------------------------------------------------------
    |
    | Les noms de champs techniques sont traduits pour que l'enseignant ne
    | lise jamais « le champ file est obligatoire ».
    |
    */

    'attributes' => [
        'address' => 'adresse',
        'ai_provider' => 'fournisseur IA',
        'announcement' => 'annonce',
        'assignment' => 'devoir',
        'chapter' => 'chapitre',
        'classroom' => 'classe',
        'code' => 'code',
        'comment' => 'commentaire',
        'content' => 'contenu',
        'deadline' => 'date limite',
        'display_name' => 'nom affiché',
        'due_at' => 'date de remise',
        'email' => 'adresse e-mail',
        'explanation' => 'explication',
        'feedback' => 'commentaire',
        'file' => 'fichier',
        'flashcard' => 'flashcard',
        'grade' => 'note',
        'instructions' => 'consignes',
        'is_correct' => 'bonne réponse',
        'label' => 'option',
        'locale' => 'langue',
        'material' => 'ressource',
        'max_attempts' => 'nombre maximal de tentatives',
        'max_score' => 'note maximale',
        'name' => 'nom',
        'option' => 'option',
        'options' => 'options',
        'partner' => 'partenaire',
        'password' => 'mot de passe',
        'path' => 'chemin',
        'question' => 'question',
        'quiz' => 'quiz',
        'reason' => 'motif',
        'request' => 'demande',
        'role' => 'rôle',
        'score' => 'score',
        'shuffle' => 'mélange',
        'statement' => 'énoncé',
        'status' => 'statut',
        'student' => 'étudiant',
        'submission' => 'remise',
        'teacher' => 'enseignant',
        'time_limit_min' => 'durée limite',
        'title' => 'titre',
        'type' => 'type',
        'url' => 'adresse',
        'user' => 'utilisateur',
    ],

];
