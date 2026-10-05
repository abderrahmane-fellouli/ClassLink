<?php

// Secret-safe local diagnostics. Reads credentials only from Laravel config.
require dirname(__DIR__).'/vendor/autoload.php';
$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if (! $app->environment('local')) {
    exit("BLOCKED: local environment required.\n");
}

$missing = [];
foreach (['MAIL_USERNAME' => 'mail.mailers.smtp.username', 'MAIL_PASSWORD' => 'mail.mailers.smtp.password'] as $variable => $key) {
    $value = config($key);
    if (! is_string($value) || $value === '' || preg_match('/^<[^>]+>$/', $value)) {
        $missing[] = $variable;
    }
}
if ($missing) {
    exit('BLOCKED: privately fill '.implode(', ', $missing)." in backend/.env. No connection or email attempted.\n");
}
if (config('mail.default') !== 'smtp' || config('mail.mailers.smtp.url')
    || config('mail.mailers.smtp.host') !== 'smtp-relay.brevo.com'
    || (int) config('mail.mailers.smtp.port') !== 587
    || ! config('mail.mailers.smtp.require_tls')
    || config('mail.from.address') !== 'noreply@classlink.space') {
    exit("BLOCKED: expected Brevo SMTP/TLS/sender settings or MAIL_URL override need review. Values suppressed.\n");
}

$send = ($argv[1] ?? '') === '--send';
$recipient = $argv[2] ?? '';
if ($send && ! filter_var($recipient, FILTER_VALIDATE_EMAIL)) {
    exit("BLOCKED: supply an explicit recipient after --send. No email attempted.\n");
}

try {
    $mailer = Illuminate\Support\Facades\Mail::mailer('smtp');
    $transport = $mailer->getSymfonyTransport();
    $transport->start();
    echo "SMTP connection/authentication: READY (TLS required).\n";
    if ($send) {
        // One synchronous message, no queue, no automatic retry and no OTP/PII.
        $mailer->raw('This is the single requested local ClassLink Brevo SMTP test. No sign-in code or credentials are included.',
            function ($message) use ($recipient) {
                $message->to($recipient)->subject('ClassLink — local Brevo SMTP test');
            });
        echo "One test email accepted by SMTP. Inbox delivery requires recipient/Brevo confirmation.\n";
    } else {
        echo "Connection check only. No email sent.\n";
    }
    $transport->stop();
} catch (Throwable $e) {
    // SMTP exceptions/transcripts may expose credentials or message recipients.
    echo "BLOCKED: SMTP authentication or submission failed; details suppressed. Do not blindly retry a send.\n";
    exit(1);
}
