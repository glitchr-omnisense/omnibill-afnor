<?php

namespace Omnibill\Afnor\Tests;

use Omnibill\Afnor\AfnorGatewayFactory;
use Omnibill\Afnor\Api;
use Omnibill\Exception\InvalidConfigException;
use Omnibill\Exception\InvalidKeyException;
use Omnibill\Exception\InvalidNotificationException;
use Omnibill\Exception\ProviderException;
use Omnibill\Exception\UnreachableException;
use Omnibill\GatewayInterface;
use Omnibill\Model\Direction;
use Omnibill\Model\EReport;
use Omnibill\Model\Event;
use Omnibill\Model\FlowKind;
use Omnibill\Model\FlowState;
use Omnibill\Model\Invoice;
use Omnibill\Model\LifecycleStatus;
use Omnibill\Model\StatusChange;
use Omnibill\Model\Syntax;
use Omnibill\Request\Lookup;
use Omnibill\Request\Notify;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * Answers written from XP Z12-013's OpenAPI (Flow Service and Directory
 * Service 1.3.0, as the FNFE-MPE publishes them with the standard) and,
 * for the lifecycle message, from the DGFiP's Annex 2 (CDAR, Flux 6):
 * no platform was called - no account.
 */
final class AfnorGatewayTest extends TestCase
{
    private const OPTIONS = [
        'flow_url' => 'https://api.flow.pa.example/flow-service',
        'directory_url' => 'https://api.directory.pa.example/directory-service',
        'token_url' => 'https://auth.pa.example/auth-service/acme/token',
        'client_id' => 'acme',
        'client_secret' => 's3cret',
        'organization_id' => '123456789',
        'webhook_key' => 'c2lnbmluZy1rZXktb2YtdGhlLXdlYmhvb2stMzItYnl0ZXM=',
    ];

    /** @var list<array{string, string, array<string, mixed>}> */
    private array $calls = [];

    /** @param array<string, string|MockResponse> $routes "METHOD path" (#n to answer it again) => a fixture or a response */
    private function gateway(array $routes, array $options = []): GatewayInterface
    {
        $http = new MockHttpClient(function (string $method, string $url, array $options) use (&$routes): MockResponse {
            $path = (string) parse_url($url, \PHP_URL_PATH);
            $this->calls[] = [$method, $url, $options];
            if (str_ends_with($path, '/token')) {
                return new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/token.json'));
            }
            parse_str((string) parse_url($url, \PHP_URL_QUERY), $query);
            foreach ($routes as $route => $answer) {
                [$m, $p] = explode(' ', (string) preg_replace('~#\d+$~', '', $route), 2);
                if ($m === $method && str_ends_with($path.(isset($query['docType']) ? '?'.$query['docType'] : ''), $p)) {
                    unset($routes[$route]);
                    if ($answer instanceof MockResponse) {
                        return $answer;
                    }
                    [$file, $status] = explode(':', $answer.':200');

                    return new MockResponse((string) file_get_contents(__DIR__.'/Fixtures/'.$file), ['http_code' => (int) $status]);
                }
            }

            return new MockResponse('{"errorCode":"NOT_FOUND"}', ['http_code' => 404]);
        });

        return (new AfnorGatewayFactory($http))->create($options + self::OPTIONS);
    }

    /** @return list<array{string, string, array<string, mixed>}> the calls but the token's */
    private function calls(): array
    {
        return array_values(array_filter($this->calls, static fn (array $c) => !str_ends_with((string) parse_url($c[1], \PHP_URL_PATH), '/token')));
    }

    private static function invoice(): Invoice
    {
        return new Invoice('%PDF-1.7 Factur-X', 'F-2026-042.pdf', Syntax::FACTURX, 'F-2026-042', profile: 'Extended-CTC-FR', rule: 'B2B');
    }

    public function testAnInvoiceIsUploadedAsAFlowWithItsFlowInfo(): void
    {
        $flow = $this->gateway(['POST /v1/flows' => 'flow-post-202.json:202'])->submit(self::invoice(), 'order-42');

        self::assertSame(['8f1d2c3b-4a5e-4f60-9a7b-1c2d3e4f5a6b', FlowState::PENDING, FlowKind::INVOICE, Direction::OUT, 'order-42', 'F-2026-042', null], [$flow->reference, $flow->state, $flow->kind, $flow->direction, $flow->trackingId, $flow->invoiceNumber, $flow->status]);
        [$method, $url, $options] = $this->calls()[0];
        self::assertSame(['POST', 'https://api.flow.pa.example/flow-service/v1/flows'], [$method, $url]);
        $headers = implode("\n", $options['headers']);
        self::assertStringContainsString('Authorization: Bearer eyJhbGciOiJSUzI1NiJ9.example.signature', $headers);
        self::assertStringContainsString('Organization-Id: 123456789', $headers);
        self::assertMatchesRegularExpression('~Content-Type: multipart/form-data; boundary=(omnibill-[0-9a-f]+)~', $headers);
        preg_match('~boundary=(omnibill-[0-9a-f]+)~', $headers, $m);
        $parts = explode('--'.$m[1], (string) $options['body']);
        self::assertStringContainsString("Content-Disposition: form-data; name=\"flowInfo\"\r\nContent-Type: application/json", $parts[1]);
        $info = json_decode(trim(explode("\r\n\r\n", $parts[1], 2)[1]), true);
        self::assertSame(['trackingId' => 'order-42', 'name' => 'F-2026-042.pdf', 'processingRule' => 'B2B', 'flowSyntax' => 'Factur-X', 'flowProfile' => 'Extended-CTC-FR', 'sha256' => hash('sha256', '%PDF-1.7 Factur-X')], $info);
        self::assertStringContainsString("Content-Disposition: form-data; name=\"file\"; filename=\"F-2026-042.pdf\"\r\nContent-Type: application/pdf\r\n\r\n%PDF-1.7 Factur-X\r\n", $parts[2]);
        self::assertSame("--\r\n", $parts[3]);
    }

    public function testTheAcknowledgementIsTheFirstStatusOfAnInvoiceSent(): void
    {
        $gateway = $this->gateway(['POST /v1/flows' => 'flow-post-202.json:202', 'GET 8f1d2c3b-4a5e-4f60-9a7b-1c2d3e4f5a6b?Metadata' => 'flow-metadata-ok.json']);
        $flow = $gateway->fetchStatus($gateway->submit(self::invoice()));

        self::assertSame([FlowState::ACCEPTED, LifecycleStatus::DEPOSITED, '2026-10-08T08:00:42+00:00'], [$flow->state, $flow->status, $flow->updatedAt?->format(\DATE_ATOM)]);
        self::assertSame('200', $flow->history[0]->code);
        self::assertSame(['docType' => 'Metadata'], $this->calls()[1][2]['query']);

        $rejected = $this->gateway(['GET 0b9c8d7e-6f5a-4b3c-8d2e-1f0a9b8c7d6e?Metadata' => 'flow-metadata-error.json'])->fetchStatus(new \Omnibill\Model\Flow('0b9c8d7e-6f5a-4b3c-8d2e-1f0a9b8c7d6e', invoiceNumber: 'F-2026-043'));
        self::assertSame([FlowState::REJECTED, LifecycleStatus::REJECTED], [$rejected->state, $rejected->status]);
        self::assertSame(['InvalidSchema: The file does not validate against the CII schema (F-2026-043.xml)'], $rejected->errors);
    }

    public function testWhatArrivesIsReceivedFromTheCursorInvoicesAndStatuses(): void
    {
        $inbox = $this->gateway([
            'POST /v1/flows/search' => 'flow-search.json',
            'GET c1a2b3c4-d5e6-4f70-8a9b-0c1d2e3f4a5b?Original' => 'supplier-invoice.xml',
            'GET d2b3c4d5-e6f7-4a80-9b0c-1d2e3f4a5b6c?Original' => 'cdar-212.xml',
        ])->receive('previous-cursor', 25);

        self::assertCount(2, $inbox->items);
        self::assertTrue($inbox->more);
        self::assertSame('eyJjcmVhdGVkX2F0IjoiMjAyNi0xMC0wOFQwOTowMDowMFoiLCJpZCI6MTIzNH0=', $inbox->cursor);
        self::assertSame(['limit' => 25, 'cursor' => 'previous-cursor', 'where' => ['flowDirection' => ['In']]], json_decode((string) $this->calls()[0][2]['body'], true));

        $invoice = $inbox->invoices()[0];
        self::assertSame(['FA-77.xml', Syntax::CII, 'B2B', 'CIUS'], [$invoice->filename, $invoice->syntax, $invoice->rule, $invoice->profile]);
        self::assertStringContainsString('<ram:ID>FA-77</ram:ID>', $invoice->content);

        $status = $inbox->statuses()[0];
        self::assertSame([LifecycleStatus::PAID, 'F-2026-042', '212', '2026-10-08T09:00:00+00:00'], [$status->status, $status->invoiceNumber, $status->code, $status->at?->format(\DATE_ATOM)]);
        self::assertSame([FlowKind::LIFECYCLE, Direction::IN, LifecycleStatus::PAID], [$inbox->items[1]->flow->kind, $inbox->items[1]->flow->direction, $inbox->items[1]->flow->status]);
    }

    public function testAStatusIsSentAsTheApplicationsLifecycleMessage(): void
    {
        $gateway = $this->gateway(['POST /v1/flows' => 'flow-post-202.json:202']);
        $cdar = (string) file_get_contents(__DIR__.'/Fixtures/cdar-212.xml');
        $flow = $gateway->setStatus(new StatusChange(LifecycleStatus::PAID, 'F-2026-042', amount: '1250.00', file: $cdar));

        self::assertSame([FlowKind::LIFECYCLE, LifecycleStatus::PAID], [$flow->kind, $flow->status]);
        self::assertStringContainsString('"flowSyntax":"CDAR"', (string) $this->calls()[0][2]['body']);
        self::assertStringContainsString('filename="CDV-212-F-2026-042.xml"', (string) $this->calls()[0][2]['body']);
        self::assertStringNotContainsString('processingRule', explode('file', (string) $this->calls()[0][2]['body'])[0]);

        $this->expectException(InvalidConfigException::class);
        $gateway->setStatus(new StatusChange(LifecycleStatus::REFUSED, 'F-1'));
    }

    public function testTheDirectorySaysWhereACompanyReceives(): void
    {
        $lines = $this->gateway(['POST /v1/directory-line/search' => 'directory-line-search.json'])->lookup('702 042 755');

        self::assertSame('https://api.directory.pa.example/directory-service/v1/directory-line/search', $this->calls()[0][1]);
        self::assertSame(['filters' => ['siren' => ['op' => 'strict', 'value' => '702042755']], 'include' => ['siren', 'siret', 'routingCode']], json_decode((string) $this->calls()[0][2]['body'], true));
        self::assertSame(['702042755', null, '702042755', 'MAISON ERABLE', 'WK', true], [$lines[0]->siren, $lines[0]->siret, $lines[0]->electronicAddress, $lines[0]->name, $lines[0]->platform, $lines[0]->isEnabled()]);
        self::assertSame(['70204275500240', 'ACHATS', false], [$lines[1]->siret, $lines[1]->routingCode, $lines[1]->isEnabled()]);
        self::assertSame([], $this->gateway(['POST /v1/directory-line/search' => new MockResponse('', ['http_code' => 204])])->lookup('70204275500240'));
        self::assertSame('siret', array_key_first(json_decode((string) $this->calls()[1][2]['body'], true)['filters']));

        $this->expectException(InvalidConfigException::class);
        $this->gateway([])->lookup('not-a-siren');
    }

    public function testAnEReportingFileIsAFlowToo(): void
    {
        $flow = $this->gateway(['POST /v1/flows' => 'flow-post-202.json:202'])->report(new EReport('<Report/>', 'TR-2026-10.xml', rule: 'B2C'));

        self::assertSame(FlowKind::REPORT, $flow->kind);
        self::assertStringContainsString('"processingRule":"B2C","flowSyntax":"FRR"', (string) $this->calls()[0][2]['body']);
    }

    public function testAWebhooksSignatureIsChecked(): void
    {
        $body = (string) file_get_contents(__DIR__.'/Fixtures/flow-metadata-ok.json');
        $time = (string) time();
        $key = (string) base64_decode(self::OPTIONS['webhook_key']);
        $signature = base64_encode(hash_hmac('sha256', hash('sha256', $body.'@'.$time, true), $key, true));
        $gateway = $this->gateway([]);

        $notification = $gateway->notify($body, ['afnor-signature' => [$signature], 'Afnor-Signature-Timestamp' => $time]);
        self::assertSame([Event::FLOW_UPDATED, '8f1d2c3b-4a5e-4f60-9a7b-1c2d3e4f5a6b', LifecycleStatus::DEPOSITED], [$notification->event, $notification->flow?->reference, $notification->flow?->status]);
        $hex = base64_encode(hash_hmac('sha256', hash('sha256', $body.'@'.$time), $key, true));
        self::assertSame(Event::FLOW_UPDATED, $gateway->notify($body, ['Afnor-Signature' => $hex, 'Afnor-Signature-Timestamp' => $time])->event, 'over the fingerprint\'s hexadecimal form too');

        foreach ([['Afnor-Signature' => $signature, 'Afnor-Signature-Timestamp' => (string) (time() - 3600)], ['Afnor-Signature' => base64_encode('forged'), 'Afnor-Signature-Timestamp' => $time], ['Afnor-Signature-Timestamp' => $time]] as $headers) {
            try {
                $gateway->notify($body, $headers);
                self::fail();
            } catch (InvalidNotificationException) {
                self::addToAssertionCount(1);
            }
        }
        self::assertFalse($this->gateway([], ['webhook_key' => null])->supports(Notify::class), 'no key, no webhook');
        self::assertFalse($this->gateway([], ['directory_url' => null])->supports(Lookup::class), 'no directory, no lookup');
    }

    public function testErrorsAreThePlatformsAndCredentialsAreRenewedOnce(): void
    {
        try {
            $this->gateway(['POST /v1/flows' => 'error-422.json:422'])->submit(self::invoice());
            self::fail();
        } catch (ProviderException $e) {
            self::assertSame(['[afnor] HTTP 422: UNPROCESSABLE_FLOW The flowInfo does not match the file - sha256 mismatch', 'UNPROCESSABLE_FLOW'], [$e->getMessage(), $e->providerCode]);
        }

        $this->calls = [];
        $flow = $this->gateway(['POST /v1/flows' => new MockResponse('{"errorCode":"EXPIRED_TOKEN"}', ['http_code' => 401]), 'POST /v1/flows#2' => 'flow-post-202.json:202'])->submit(self::invoice());
        self::assertSame('8f1d2c3b-4a5e-4f60-9a7b-1c2d3e4f5a6b', $flow->reference);
        self::assertCount(2, array_filter($this->calls, static fn ($c) => str_ends_with($c[1], '/token')), 'a new token after a 401');

        try {
            $this->gateway(['POST /v1/flows' => 'error-422.json:403'])->submit(self::invoice());
            self::fail();
        } catch (InvalidKeyException) {
            self::addToAssertionCount(1);
        }
        $this->expectException(UnreachableException::class);
        $this->gateway(['POST /v1/flows' => new MockResponse('', ['http_code' => 503])])->submit(self::invoice());
    }

    public function testALifecycleMessageWithoutAStatusOfTheReformIsNotOne(): void
    {
        self::assertNull(Api::lifecycle('<not-xml'));
        self::assertNull(Api::lifecycle(str_replace('>212<', '>999<', (string) file_get_contents(__DIR__.'/Fixtures/cdar-212.xml'))));
        self::assertSame(LifecycleStatus::PAID, Api::lifecycle(str_replace('>212<', '>fr:212<', (string) file_get_contents(__DIR__.'/Fixtures/cdar-212.xml')))?->status);
        self::assertTrue($this->gateway([])->capabilities()->approved);
        self::assertTrue($this->gateway([])->capabilities()->carries(Syntax::CDAR));
    }
}
