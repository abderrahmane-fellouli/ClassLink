<?php

/*
|--------------------------------------------------------------------------
| API messages (English)
|--------------------------------------------------------------------------
|
| Mirror of lang/fr/api.php. The French file is the reference; both must
| stay structurally identical (T-26: no untranslated text).
|
*/

return [
    'digest' => [
        'subject' => 'ClassLink — daily summary',
        'heading' => 'Your ClassLink updates:',
        'types' => [
            'join_requested' => 'Membership request', 'membership_accepted' => 'Membership accepted',
            'membership_rejected' => 'Membership rejected', 'membership_removed' => 'Membership removed',
            'quiz_published' => 'New quiz', 'graded' => 'Assignment graded',
            'partner_request_received' => 'Study partner request', 'partner_request_answered' => 'Study partner response',
            'ai_job_finished' => 'AI generation update', 'announcement_published' => 'Announcement',
            'assignment_published' => 'New assignment',
        ],
    ],
    'otp' => [
        'invalid' => 'Invalid or expired code.',
        'expired' => 'Code expired.',
        'attempts_exhausted' => 'Maximum number of attempts reached.',
        'account_denied' => 'This account is not authorized to access ClassLink.',
        'sent' => 'If this address is allowed, a sign-in code has been sent.',
        'delivery_failed' => 'The sign-in email could not be sent. Please try again later.',
        'email_subject' => 'Your ClassLink sign-in code',
        'email_body' => "Your ClassLink code is: :code\n\nIt is valid for :minutes minutes. You have 5 attempts.\nIf you did not request this code, ignore this message.",
    ],

    'errors' => [
        'invalid_data' => 'Invalid data.',
        'unauthenticated' => 'Authentication required.',
        'forbidden' => 'Access denied.',
        'not_found' => 'Resource not found.',
        'server_error' => 'Server error.',
        'too_many_requests' => 'Too many requests. Please try again later.',
        'session_expired' => 'Session expired. Please sign in again.',
    ],

    'files' => [
        'type_not_allowed' => 'File type not allowed.',
        'too_large' => 'File too large (maximum :max KB).',
        'storage_failed' => 'File storage failed.',
        'not_found' => 'File not found.',
        'no_file' => 'This submission has no file.',
        'content_mismatch' => 'Invalid file content: it does not match the declared type.',
    ],

    'ai' => [
        'unavailable' => 'AI generation is unavailable. You can create the quiz manually.',
        'pdf_only' => 'Only PDF files are accepted in version 1.0.',
        'pdf_not_found' => 'File not found for generation. Create the quiz manually.',
        'pdf_unreadable' => 'The PDF text could not be extracted (scanned PDF without a text layer). Create the quiz manually.',
        'pdf_not_configured' => 'PDF text extraction is not configured on this server. Create the quiz manually.',
        'pdf_corrupted' => 'PDF unreadable or corrupted. Create the quiz manually.',
        'too_many_pages' => 'The PDF has :pages pages (maximum :max). Split the course or create the quiz manually.',
        'quota_reached' => 'Daily quota reached (:quota generations).',
        'manual_fallback' => 'Manual creation is still possible.',
    ],

];
