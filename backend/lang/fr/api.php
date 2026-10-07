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
    'school' => [
        'archived' => 'Ce groupe ou cette année est archivé.',
        'verified_teacher' => 'Sélectionnez un formateur actif validé par l’administration.',
        'primary_group' => 'Ce stagiaire possède déjà un groupe principal pour cette année. Utilisez le transfert.',
        'delegate_limit' => 'Deux délégués actifs au maximum par groupe.',
        'missing_assignments' => 'Attribuez au moins un module à un formateur avant activation.',
        'empty_roster' => 'Validez au moins un stagiaire avant de créer une évaluation.',
        'version_conflict' => 'Ces notes ont changé. Rechargez avant de recommencer.',
        'roster_changed' => 'La liste des stagiaires a changé. Réconciliez la liste avant publication.',
        'correction_required' => 'Créez un brouillon de correction avant de modifier des notes publiées.',
        'invalid_import' => 'Le fichier comporte des erreurs. Aucun changement n’a été enregistré.',
        'incomplete_grades' => 'Chaque stagiaire doit avoir une note ou un statut explicite (absent, exempté, rattrapage).',
        'formulas_forbidden' => 'Les formules et macros ne sont pas acceptées dans les fichiers de notes.',
        'audience_changed' => 'Les destinataires ont changé. Prévisualisez à nouveau avant l’envoi.',
        'request_exists' => 'Une demande existe déjà pour ce module. Contactez l’administration pour son suivi.',
        'conflict' => 'Cette action est en conflit avec l’état actuel. Rechargez et vérifiez les informations.',
        'expired' => 'Cette prévisualisation a expiré. Recommencez la prévisualisation.',
        'year_frozen' => 'Une année close ne garde que son libellé modifiable.',
        'template_instructions' => 'Ne changez pas les identifiants/contexte. Maximum : :max. Statuts : graded, ungraded, absent, exempt, makeup. Décimales : virgule ou point. Import = brouillon ; publication séparée. Ne collez aucune formule.',
    ],
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
            'teaching_assignment_changed' => 'Mise à jour d’attribution pédagogique',
            'assignment_request_received' => 'Nouvelle demande d’attribution de module',
            'delegate_changed' => 'Mise à jour des délégués',
            'official_grade_published' => 'Notes publiées',
            'school_message_received' => 'Message de l’école',
            'resource_published' => 'Nouvelle ressource de la classe',
            'deadline_changed' => 'Date limite modifiée',
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
        'storage_capacity_reached' => 'Espace de stockage atteint (:limit). Les envois de fichiers sont temporairement suspendus ; espacez un envoi existant ou réessayez plus tard.',
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
