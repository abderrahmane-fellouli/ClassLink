<?php

namespace Tests\Feature;

use App\Support\MailDeliveryDiagnostics;
use Illuminate\Mail\MailManager;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Mailer\Exception\TransportException;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;
use Symfony\Component\Mime\Exception\RfcComplianceException;
use Tests\TestCase;
use Throwable;

class MailDeliveryDiagnosticsTest extends TestCase
{
    public static function failures(): array
    {
        return [
            'authentication' => [new TransportException('Failed to authenticate on SMTP server with username "private-user".', 535), 'authentication_failed'],
            'timeout' => [new TransportException('Connection timed out private-data'), 'connection_timeout'],
            'dns' => [new TransportException('php_network_getaddresses: getaddrinfo failed private-data'), 'dns_failed'],
            'tls' => [new TransportException('Unable to connect with STARTTLS private-data'), 'tls_failed'],
            'certificate' => [new TransportException('certificate verify failed private-data'), 'tls_failed'],
            'refused' => [new TransportException('Connection refused private-data'), 'connection_refused'],
            'connection' => [new TransportException('Connection could not be established private-data'), 'connection_failed'],
            'server rejection' => [new TransportException('Expected response code 250 but got code 550 private-data', 550), 'smtp_transport_failed'],
            'invalid sender' => [new RfcComplianceException('Email "private-data" is invalid'), 'invalid_address'],
            'unexpected' => [new \RuntimeException('private-data'), 'unexpected_mail_failure'],
            'type error' => [new \TypeError('private-data'), 'unexpected_mail_failure'],
        ];
    }

    #[DataProvider('failures')]
    public function test_failure_messages_are_normalized_without_untrusted_text(Throwable $exception, string $category): void
    {
        $context = MailDeliveryDiagnostics::context($exception);
        $this->assertSame($exception::class, $context['exception_class']);
        $this->assertSame($category, $context['error_category']);
        $this->assertStringNotContainsString('private', json_encode($context));
        $this->assertNotSame($exception->getMessage(), $context['sanitized_message']);
    }

    public function test_unknown_configuration_values_and_nested_exception_messages_are_not_logged(): void
    {
        config()->set('mail.default', 'private-data');
        config()->set('mail.mailers.smtp', array_fill_keys(['host', 'port', 'encryption', 'scheme', 'username', 'password', 'url'], 'private-data'));
        config()->set('mail.from.address', 'private-data');
        $context = MailDeliveryDiagnostics::context(new \RuntimeException('private-data', 123456, new \RuntimeException('private-data')));
        $this->assertSame('other', $context['mailer']);
        $this->assertSame('other', $context['smtp_port']);
        $this->assertSame('other', $context['smtp_scheme']);
        $this->assertNull($context['smtp_response_code']);
        $this->assertFalse($context['from_address_valid']);
        $this->assertStringNotContainsString('private-data', json_encode($context));
        $this->assertSame(\RuntimeException::class, $context['previous_exception_class']);
    }

    public function test_brevo_starttls_configuration_builds_laravels_smtp_transport_without_network_io(): void
    {
        config()->set('mail.mailers.smtp', [
            'transport' => 'smtp', 'url' => null,
            'host' => 'smtp-relay.brevo.com', 'port' => 587,
            'encryption' => 'tls', 'scheme' => null, 'require_tls' => true,
            'username' => 'synthetic-user', 'password' => 'synthetic-password',
            'timeout' => 30, 'local_domain' => null,
        ]);
        $transport = (new MailManager($this->app))->mailer('smtp')->getSymfonyTransport();
        $this->assertInstanceOf(EsmtpTransport::class, $transport);
        $this->assertTrue($transport->isTlsRequired());
        $this->assertSame('smtp-relay.brevo.com', $transport->getStream()->getHost());
        $this->assertSame(587, $transport->getStream()->getPort());
    }
}
