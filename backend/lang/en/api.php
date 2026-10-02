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
