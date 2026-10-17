<?php

return [
    // Independent of APP_ENV. Explicit target/manifest/inventory acknowledgements
    // and a reviewed manager manifest are ALSO required by the internal command.
    'apply_enabled' => (bool) env('OFPPT_COMPATIBILITY_APPLY_ENABLED', false),
];
