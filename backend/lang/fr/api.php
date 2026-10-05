<?php

/*
|--------------------------------------------------------------------------
| Messages de l'API
|--------------------------------------------------------------------------
|
| Les messages renvoyés par le gestionnaire d'exceptions et par les
| contrôleurs sont traduits : le client peut donc afficher une erreur
| comprehensible dans la langue choisie par l'utilisateur (T-26).
|
*/

return [
    'digest' => [
        'subject' => 'ClassLink — résumé du jour',
        'heading' => 'Vos actualités ClassLink :',
        'types' => [
            'join_requested' => 'Demande d’adhésion', 'membership_accepted' => 'Adhésion acceptée',
            'membership_rejected' => 'Adhésion refusée', 'membership_removed' => 'Adhésion supprimée',
            'quiz_published' => 'Nouveau quiz', 'graded' => 'Devoir noté',
            'partner_request_received' => 'Demande de partenaire', 'partner_request_answered' => 'Réponse du partenaire',
            'ai_job_finished' => 'Actualité de génération IA', 'announcement_published' => 'Annonce',
            'assignment_published' => 'Nouveau devoir',
        ],
    ],
    'otp' => [
        'invalid' => 'Code invalide ou expiré.',
        'expired' => 'Code expiré.',
        'attempts_exhausted' => 'Nombre maximal de tentatives atteint.',
        'account_denied' => 'Ce compte n’est pas autorisé à accéder à ClassLink.',
        'sent' => 'Si cette adresse est autorisée, un code de connexion a été envoyé.',
        'delivery_failed' => 'L’email de connexion n’a pas pu être envoyé. Réessayez plus tard.',
        'email_subject' => 'Votre code de connexion ClassLink',
        'email_body' => "Votre code ClassLink est : :code\n\nIl est valable :minutes minutes. Vous avez droit à 5 essais.\nSi vous n’avez pas demandé ce code, ignorez ce message.",
    ],

    'errors' => [
        'invalid_data' => 'Données invalides.',
        'unauthenticated' => 'Authentification requise.',
        'forbidden' => 'Accès refusé.',
        'not_found' => 'Ressource introuvable.',
        'server_error' => 'Erreur serveur.',
        'too_many_requests' => 'Trop de requêtes. Veuillez réessayer plus tard.',
        'session_expired' => 'Session expirée. Veuillez vous reconnecter.',
    ],

    'files' => [
        'type_not_allowed' => 'Type de fichier non autorisé.',
        'too_large' => 'Fichier trop volumineux (maximum :max Ko).',
        'storage_failed' => 'Le stockage du fichier a échoué.',
        'not_found' => 'Fichier introuvable.',
        'no_file' => 'Cette remise ne contient aucun fichier.',
        'content_mismatch' => 'Contenu du fichier invalide : il ne correspond pas au type annoncé.',
    ],

    'ai' => [
        'unavailable' => 'La génération par IA est indisponible. Vous pouvez créer le quiz manuellement.',
        'pdf_only' => 'Seuls les fichiers PDF sont acceptés en version 1.0.',
        'pdf_not_found' => 'Fichier introuvable pour la génération. Créez le quiz manuellement.',
        'pdf_unreadable' => 'Le texte du PDF n\'a pas pu être extrait (PDF scanné sans couche texte). Créez le quiz manuellement.',
        'pdf_not_configured' => "L'extraction du texte des PDF n'est pas configurée sur ce serveur. Créez le quiz manuellement.",
        'pdf_corrupted' => 'PDF illisible ou corrompu. Créez le quiz manuellement.',
        'too_many_pages' => 'Le PDF compte :pages pages (maximum :max). Découpez le cours ou créez le quiz manuellement.',
        'quota_reached' => 'Quota quotidien atteint (:quota générations).',
        'manual_fallback' => 'La création manuelle reste possible.',
    ],

];
