<?php

namespace App\Support;

use Symfony\Component\Mailer\Exception\TransportExceptionInterface;
use Symfony\Component\Mime\Exception\RfcComplianceException;
use Throwable;

final class MailDeliveryDiagnostics
{
    /**
     * Never pass exception objects, raw messages, SMTP transcripts or mail
     * payloads to the logger. Provider-controlled text may contain secrets;
     * normalize it to fixed messages rather than relying on regex redaction.
     * Configuration fields are booleans or allowlisted labels, not values.
     */
    public static function context(Throwable $exception): array
    {
        [$category, $message] = self::failure($exception);
        $smtp = (array) config('mail.mailers.smtp', []);
        $mailer = config('mail.default');
        $scheme = $smtp['scheme'] ?? null;
        $code = $exception->getCode();

        return [
            'exception_class' => $exception::class,
            'previous_exception_class' => $exception->getPrevious() ? $exception->getPrevious()::class : null,
            'error_category' => $category,
            'sanitized_message' => $message,
            'smtp_response_code' => $exception instanceof TransportExceptionInterface && $code >= 400 && $code <= 599 ? $code : null,
            'mailer' => in_array($mailer, ['smtp', 'log', 'array', 'sendmail', 'ses', 'postmark', 'failover', 'roundrobin'], true) ? $mailer : 'other',
            // MAIL_URL may override these discrete settings in MailManager.
            'mail_url_configured' => ! empty($smtp['url']),
            'smtp_host_configured' => ! empty($smtp['host']),
            'smtp_host_is_brevo' => ($smtp['host'] ?? null) === 'smtp-relay.brevo.com',
            'smtp_port' => in_array((string) ($smtp['port'] ?? ''), ['25', '465', '587', '2525'], true) ? (int) $smtp['port'] : 'other',
            'smtp_scheme' => $scheme === null ? 'automatic' : (in_array($scheme, ['smtp', 'smtps'], true) ? $scheme : 'other'),
            'smtp_encryption' => in_array($smtp['encryption'] ?? null, ['tls', 'ssl'], true) ? $smtp['encryption'] : 'other',
            'smtp_require_tls' => (bool) ($smtp['require_tls'] ?? false),
            'smtp_username_configured' => ! empty($smtp['username']),
            'smtp_password_configured' => ! empty($smtp['password']),
            'from_address_valid' => filter_var(config('mail.from.address'), FILTER_VALIDATE_EMAIL) !== false,
            'config_cached' => app()->configurationIsCached(),
        ];
    }

    private static function failure(Throwable $exception): array
    {
        $message = strtolower($exception->getMessage());

        if ($exception instanceof RfcComplianceException) {
            return ['invalid_address', 'Mail address or header is invalid.'];
        }

        if ($exception instanceof TransportExceptionInterface) {
            return match (true) {
                str_contains($message, 'authenticate'), str_contains($message, 'authentication') => ['authentication_failed', 'SMTP authentication failed.'],
                str_contains($message, 'timed out'), str_contains($message, 'timeout') => ['connection_timeout', 'SMTP connection or response timed out.'],
                str_contains($message, 'getaddrinfo'), str_contains($message, 'php_network_getaddresses') => ['dns_failed', 'SMTP hostname resolution failed.'],
                str_contains($message, 'certificate'), str_contains($message, 'crypto'), str_contains($message, 'starttls'), str_contains($message, 'tls required') => ['tls_failed', 'SMTP TLS negotiation or certificate validation failed.'],
                str_contains($message, 'connection refused') => ['connection_refused', 'SMTP connection was refused.'],
                str_contains($message, 'connection could not be established') => ['connection_failed', 'SMTP connection could not be established.'],
                default => ['smtp_transport_failed', 'SMTP transport failed or the server rejected a command; inspect the response code.'],
            };
        }

        return ['unexpected_mail_failure', 'Mail delivery failed; unrecognized exception text omitted for safety.'];
    }
}
