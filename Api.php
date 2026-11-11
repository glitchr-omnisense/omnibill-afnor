<?php

namespace Omnibill\Afnor;

use Omnibill\Exception\InvalidKeyException;
use Omnibill\Exception\InvalidNotificationException;
use Omnibill\Exception\ProviderException;
use Omnibill\Http\Answer;
use Omnibill\Http\ClientCredentials;
use Omnibill\Model\Addressing;
use Omnibill\Model\Direction;
use Omnibill\Model\Flow;
use Omnibill\Model\FlowKind;
use Omnibill\Model\FlowState;
use Omnibill\Model\LifecycleStatus;
use Omnibill\Model\StatusChange;
use Omnibill\Model\Syntax;
use Omnisense\Contracts\Policy\Backoff;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * A platform's XP Z12-013 API, as its Flow Service and Directory Service
 * OpenAPI 1.3.0 describe it (published by the FNFE-MPE with the standard,
 * 2026-06-30): a bearer token by OAuth 2.0 client credentials, the
 * Organization-Id header for a delegated company, a Request-Id for the
 * logs.
 */
final class Api
{
    public const PROVIDER = 'afnor';

    /** @param (\Closure(): int)|null $clock */
    public function __construct(
        private readonly HttpClientInterface $http,
        private readonly ClientCredentials $credentials,
        public readonly string $flowUrl,
        public readonly ?string $directoryUrl = null,
        public readonly ?string $organizationId = null,
        #[\SensitiveParameter] private readonly ?string $webhookKey = null,
        public readonly int $webhookTolerance = 300,
        public readonly ?string $profile = null,
        public readonly ?string $rule = null,
        private readonly ?\Closure $clock = null,
        private readonly ?Backoff $backoff = null,
    ) {
    }

    /**
     * The flowTypes of the Flow Service's search (OpenAPI 1.3.0, FlowType)
     * each kind of flow received is: an invoice (a supplier's, or a
     * self-billed one), a lifecycle message about one. The other kinds have
     * none to filter on.
     */
    public const FLOW_TYPES = [
        'invoice' => ['SupplierInvoice', 'CustomerInvoice'],
        'lifecycle' => ['CustomerInvoiceLC', 'SupplierInvoiceLC'],
    ];

    /**
     * A call to the platform, the token renewed once when it is refused.
     *
     * @param array<string, mixed> $options
     * @param bool|null            $write   whether it writes (Answer::send()): null by its method, false for a search sent as a POST
     */
    public function call(string $method, string $url, array $options = [], ?bool $write = null): Answer
    {
        for ($attempt = 0; ; ++$attempt) {
            $headers = ['Authorization' => 'Bearer '.$this->credentials->token(), 'Request-Id' => self::uuid()] + ($this->organizationId ? ['Organization-Id' => $this->organizationId] : []);
            $answer = Answer::send($this->http, self::PROVIDER, $method, $url, ['headers' => $headers + Data::map($options['headers'] ?? [])] + $options, $this->backoff, $write);
            if (401 === $answer->status && 0 === $attempt) {
                $this->credentials->reset();
                continue;
            }
            if (\in_array($answer->status, [401, 403], true)) {
                throw new InvalidKeyException(self::PROVIDER, \sprintf('The platform refused the request (HTTP %d): %s', $answer->status, self::error($answer)), (string) $answer->status);
            }
            if ($answer->status >= 400) {
                $error = json_decode($answer->body, true);
                throw new ProviderException(self::PROVIDER, \sprintf('HTTP %d: %s', $answer->status, self::error($answer)), \is_array($error) ? Data::of($error)->textOrNull('errorCode') : (string) $answer->status);
            }

            return $answer;
        }
    }

    /**
     * Uploads a file as a flow: POST /v1/flows, multipart - flowInfo (JSON)
     * and file.
     *
     * @return array<string, mixed> the FullFlowInfo answered
     */
    public function submit(string $content, string $name, Syntax $syntax, ?string $trackingId, ?string $profile, ?string $rule): array
    {
        $info = array_filter([
            'trackingId' => $trackingId,
            'name' => $name,
            'processingRule' => $rule ?? (Syntax::CDAR === $syntax ? null : $this->rule),
            'flowSyntax' => $syntax->value,
            'flowProfile' => $profile ?? (\in_array($syntax, [Syntax::CDAR, Syntax::FRR], true) ? null : $this->profile),
            'sha256' => hash('sha256', $content),
        ], static fn ($v) => null !== $v && '' !== $v);
        $boundary = 'omnibill-'.bin2hex(random_bytes(12));
        $body = "--$boundary\r\nContent-Disposition: form-data; name=\"flowInfo\"\r\nContent-Type: application/json\r\n\r\n".json_encode($info, \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE)."\r\n"
            ."--$boundary\r\nContent-Disposition: form-data; name=\"file\"; filename=\"".addcslashes($name, "\"\\")."\"\r\nContent-Type: ".$syntax->mimeType()."\r\n\r\n".$content."\r\n"
            ."--$boundary--\r\n";

        return $this->call('POST', $this->flowUrl.'/v1/flows', ['headers' => ['Content-Type' => 'multipart/form-data; boundary='.$boundary], 'body' => $body])->json();
    }

    /** @return array<string, mixed> the Flow, its metadata */
    public function metadata(string $flowId): array
    {
        return $this->call('GET', $this->flowUrl.'/v1/flows/'.rawurlencode($flowId), ['query' => ['docType' => 'Metadata']])->json();
    }

    /** The file of a flow, as its emitter gave it (docType Original). */
    public function download(string $flowId, string $docType = 'Original'): string
    {
        return $this->call('GET', $this->flowUrl.'/v1/flows/'.rawurlencode($flowId), ['query' => ['docType' => $docType]])->body;
    }

    /**
     * POST /v1/flows/search: the flows received, from the cursor - of the
     * flowTypes given, when some are.
     *
     * @param list<string> $flowTypes
     *
     * @return array{results: list<array<string, mixed>>, nextCursor: ?string}
     */
    public function search(?string $cursor, int $limit, array $flowTypes = []): array
    {
        $where = ['flowDirection' => ['In']] + ($flowTypes ? ['flowType' => $flowTypes] : []);
        $data = $this->call('POST', $this->flowUrl.'/v1/flows/search', ['json' => array_filter(['limit' => max(1, min(100, $limit)), 'cursor' => $cursor, 'where' => $where], static fn ($v) => null !== $v)], false)->json();

        $next = $data['nextCursor'] ?? null;

        return ['results' => array_map(Data::map(...), array_values((array) ($data['results'] ?? []))), 'nextCursor' => null !== $next && '' !== $next ? Data::cast($next) : null];
    }

    /**
     * POST /v1/flows/search on a trackingId (a criterion since OpenAPI
     * 1.0.2), in one direction: the flows the platform has with it.
     *
     * @return list<array<string, mixed>>
     */
    public function searchByTrackingId(string $trackingId, string $direction = 'Out'): array
    {
        $data = $this->call('POST', $this->flowUrl.'/v1/flows/search', ['json' => ['limit' => 100, 'where' => ['trackingId' => $trackingId, 'flowDirection' => [$direction]]]], false)->json();

        return array_values(array_filter(array_map(Data::map(...), array_values((array) ($data['results'] ?? []))), static fn (array $flow) => Data::cast($flow['trackingId'] ?? '') === $trackingId));
    }

    /**
     * POST /v1/directory-line/search on a SIREN or a SIRET.
     *
     * @return list<Addressing>
     */
    public function lookup(string $identifier): array
    {
        $identifier = preg_replace('~\s+~', '', $identifier);
        $field = 14 === \strlen((string) $identifier) ? 'siret' : 'siren';
        $answer = $this->call('POST', rtrim((string) $this->directoryUrl, '/').'/v1/directory-line/search', ['json' => [
            'filters' => [$field => ['op' => 'strict', 'value' => $identifier]],
            'include' => ['siren', 'siret', 'routingCode'],
        ]], false);
        if (204 === $answer->status || '' === trim($answer->body)) {
            return [];
        }

        $lines = [];
        foreach ((array) ($answer->json()['results'] ?? []) as $line) {
            $read = Data::of($line);
            $lines[] = new Addressing(
                siren: $read->textOrNull('siren') ?? $read->text('legalUnit.siren'),
                siret: $read->textOrNull('siret') ?? $read->textOrNull('facility.siret'),
                routingCode: $read->textOrNull('routingIdentifier') ?? $read->textOrNull('routingCode.routingIdentifier'),
                electronicAddress: $read->textOrNull('addressingIdentifier'),
                name: $read->textOrNull('legalUnit.businessName'),
                platform: $read->textOrNull('platformType'),
                status: $read->textOrNull('directoryLineStatus'),
                data: Data::map($line),
            );
        }

        return $lines;
    }

    /**
     * A webhook's call, its Afnor-Signature checked: the HMAC-SHA-256, under
     * the webhook's signing key, of the SHA-256 of "<body>@<timestamp>", in
     * base64; Afnor-Signature-Timestamp in epoch seconds.
     *
     * @return array<string, mixed> the Flow it carries
     */
    public function verifyWebhook(string $body, ?string $signature, ?string $timestamp): array
    {
        if (null === $this->webhookKey) {
            throw new InvalidNotificationException(self::PROVIDER, 'No webhook signing key is configured.');
        }
        if (null === $signature || null === $timestamp || !ctype_digit($timestamp)) {
            throw new InvalidNotificationException(self::PROVIDER, 'The Afnor-Signature or Afnor-Signature-Timestamp header is missing.');
        }
        $now = $this->clock ? ($this->clock)() : time();
        if ($this->webhookTolerance > 0 && abs($now - (int) $timestamp) > $this->webhookTolerance) {
            throw new InvalidNotificationException(self::PROVIDER, 'The callback is too old.');
        }
        $key = base64_decode($this->webhookKey, true);
        $key = false === $key ? $this->webhookKey : $key;
        $given = base64_decode($signature, true);
        $valid = false;
        // The standard says "a HMAC signature is computed over the fingerprint": over its bytes, or its hexadecimal form.
        foreach ([hash('sha256', $body.'@'.$timestamp, true), hash('sha256', $body.'@'.$timestamp)] as $fingerprint) {
            $valid = $valid || (false !== $given && hash_equals(hash_hmac('sha256', $fingerprint, $key, true), $given));
        }
        if (!$valid) {
            throw new InvalidNotificationException(self::PROVIDER, 'The signature does not hold.');
        }
        $flow = json_decode($body, true);

        return \is_array($flow) ? Data::map($flow) : throw new InvalidNotificationException(self::PROVIDER, 'The callback is not a JSON flow.');
    }

    /**
     * A Flow of the standard's, as the family's: its acknowledgement as a
     * FlowState and, for an invoice sent, as its first lifecycle status -
     * Ok: deposited (200), Error: rejected (213).
     *
     * @param array<string, mixed> $flow
     */
    public static function flow(array $flow, ?string $invoiceNumber = null): Flow
    {
        $ack = (array) ($flow['acknowledgement'] ?? []);
        $state = match ($ack['status'] ?? 'Pending') {
            'Ok' => FlowState::ACCEPTED,
            'Error' => FlowState::REJECTED,
            default => FlowState::PENDING,
        };
        $read = Data::of($flow);
        $syntax = $read->text('flowSyntax');
        $type = $read->text('flowType');
        $kind = match (true) {
            'CDAR' === $syntax || str_ends_with($type, 'LC') => FlowKind::LIFECYCLE,
            'FRR' === $syntax || str_contains($type, 'Report') => FlowKind::REPORT,
            str_contains($type, 'Invoice') || \in_array($syntax, ['CII', 'UBL', 'Factur-X'], true) => FlowKind::INVOICE,
            default => FlowKind::OTHER,
        };
        $direction = 'In' === ($flow['flowDirection'] ?? 'Out') ? Direction::IN : Direction::OUT;
        $status = FlowKind::INVOICE === $kind && Direction::OUT === $direction ? match ($state) {
            FlowState::ACCEPTED => LifecycleStatus::DEPOSITED,
            FlowState::REJECTED => LifecycleStatus::REJECTED,
            default => null,
        } : null;
        $errors = array_map(static fn (array $d) => trim(\sprintf('%s: %s%s', Data::cast($d['reasonCode'] ?? '?'), Data::cast($d['reasonMessage'] ?? ''), isset($d['item']) && '' !== $d['item'] ? ' ('.Data::cast($d['item']).')' : '')), array_values(array_filter((array) ($ack['details'] ?? []), 'is_array')));
        $updatedAt = self::date($flow['updatedAt'] ?? null);

        return new Flow(
            reference: Data::cast($flow['flowId'] ?? throw new ProviderException(self::PROVIDER, 'A flow without a flowId.')),
            kind: $kind,
            direction: $direction,
            state: $state,
            trackingId: $read->textOrNull('trackingId'),
            invoiceNumber: $invoiceNumber,
            submittedAt: self::date($flow['submittedAt'] ?? null),
            updatedAt: $updatedAt,
            status: $status,
            history: null !== $status ? [new StatusChange($status, $invoiceNumber, $updatedAt, $errors ? implode('; ', $errors) : null, code: (string) $status->code())] : [],
            errors: $errors,
            name: $read->textOrNull('name'),
            data: $flow,
        );
    }

    /**
     * A lifecycle message (CDAR) read for what the family needs - where the
     * DGFiP's Annex 2 (Flux 6, v2.3) puts it: the invoice's number
     * (ReferenceReferencedDocument/IssuerAssignedID), its status code
     * (ReferenceReferencedDocument/ProcessConditionCode), the reason given,
     * the date (ExchangedDocument/IssueDateTime).
     */
    public static function lifecycle(string $cdar, ?string $filename = null): ?StatusChange
    {
        $dom = new \DOMDocument();
        if ('' === trim($cdar) || !@$dom->loadXML($cdar, \LIBXML_NONET)) {
            return null;
        }
        $xpath = new \DOMXPath($dom);
        $first = static function (string $query) use ($xpath): ?string {
            $nodes = $xpath->query($query);
            $node = false !== $nodes ? $nodes->item(0) : null;

            return $node instanceof \DOMNode ? trim($node->textContent) : null;
        };
        $reference = "//*[local-name()='AcknowledgementDocument']/*[local-name()='ReferenceReferencedDocument']";
        $code = $first($reference."/*[local-name()='ProcessConditionCode']");
        $status = null !== $code ? LifecycleStatus::fromCode($code) : null;
        if (null === $status) {
            return null;
        }
        $reason = $first($reference."//*[local-name()='ReasonInformation' or local-name()='Reason']") ?? $first("//*[local-name()='AcknowledgementDocument']/*[local-name()='ReasonInformation']");
        $issued = $first("//*[local-name()='ExchangedDocument']/*[local-name()='IssueDateTime']/*[local-name()='DateTimeString']");
        $at = null !== $issued && preg_match('~^\d{14}$~', $issued) ? \DateTimeImmutable::createFromFormat('YmdHis', $issued, new \DateTimeZone('UTC')) ?: null : self::date($issued);

        return new StatusChange($status, $first($reference."/*[local-name()='IssuerAssignedID']"), $at, $reason ?: null, code: $code, file: $cdar, filename: $filename);
    }

    private static function date(mixed $value): ?\DateTimeImmutable
    {
        try {
            return \is_string($value) && '' !== $value ? new \DateTimeImmutable($value) : null;
        } catch (\Exception) {
            return null;
        }
    }

    private static function error(Answer $answer): string
    {
        $error = json_decode($answer->body, true);
        if (\is_array($error)) {
            return trim(Data::cast($error['errorCode'] ?? '').' '.Data::cast($error['errorMessage'] ?? '').(isset($error['details']) ? ' - '.Data::cast($error['details']) : ''));
        }

        return mb_substr(trim($answer->body), 0, 200) ?: 'no reason given';
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = \chr((\ord($bytes[6]) & 0x0F) | 0x40);
        $bytes[8] = \chr((\ord($bytes[8]) & 0x3F) | 0x80);

        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}
