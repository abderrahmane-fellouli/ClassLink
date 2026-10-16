<?php

// Temporary, disabled by default. Remove this file with the review endpoint.
return [
    'secret' => env('TEMP_REVIEW_SECRET'),
    // Required absolute UTC cutoff, e.g. YYYY-MM-DDTHH:MM:SSZ.
    'until' => env('TEMP_REVIEW_UNTIL'),
];
