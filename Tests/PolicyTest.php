<?php

namespace Omnibill\Afnor\Tests;

use Omnibill\Exception\UsageLimitReachedException;
use Omnibill\Afnor\AfnorGatewayFactory;
use Omnibill\Registry;
use Omnisense\Contracts\Policy\Permission;
use Omnisense\Contracts\Policy\UsagePolicy;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Omnibill\Model\Flow;

/** An approved platform (XP Z12-013)'s usage policy: declared in policy.json, read by the gateway, enforced through the registry. */
final class PolicyTest extends TestCase
{
    public function testThePolicyIsDeclaredAndRead(): void
    {
        $declared = UsagePolicy::fromFile(__DIR__.'/../policy.json');
        $composer = json_decode((string) file_get_contents(__DIR__.'/../composer.json'), true);

        self::assertIsArray($composer);
        $extra = $composer['extra'] ?? null;
        self::assertIsArray($extra);
        self::assertSame(['policy' => 'policy.json'], $extra['omnisense'] ?? null);
        self::assertSame('afnor', $declared->provider);
        self::assertSame('2026-11-10', $declared->provenance->verifiedAt);
        self::assertSame($declared->toArray(), AfnorGatewayFactory::usagePolicy()->toArray());
        self::assertNull($declared->rateLimits, 'each platform\'s own, unknown here');
        self::assertSame(Permission::Unknown, $declared->rights->commercial);
        self::assertTrue($declared->requirements->key);

        $registry = new Registry([new AfnorGatewayFactory(new MockHttpClient())], ['afnor' => ['factory' => 'afnor', 'options' => ['flow_url' => 'https://pa.example/flow-service', 'token_url' => 'https://pa.example/token', 'client_id' => 'id', 'client_secret' => 's']]]);
        self::assertSame($declared->toArray(), $registry->policy('afnor')->toArray());
    }

    public function testA429AndItsRetryAfterHoldEveryCallUntilTheWaitIsOver(): void
    {
        $requests = 0;
        $http = new MockHttpClient(function () use (&$requests): MockResponse {
            ++$requests;

            return new MockResponse('{"error":"too many requests"}', ['http_code' => 429, 'response_headers' => ['retry-after' => '30', 'content-type' => 'application/json']]);
        });
        $registry = new Registry([new AfnorGatewayFactory($http)], ['afnor' => ['factory' => 'afnor', 'options' => ['flow_url' => 'https://pa.example/flow-service', 'token_url' => 'https://pa.example/token', 'client_id' => 'id', 'client_secret' => 's']]]);

        try {
            $registry->get('afnor')->fetchStatus(new Flow('r-1'));
            self::fail('A 429 was read as an answer.');
        } catch (UsageLimitReachedException $e) {
            self::assertSame(30, $e->retryAfter);
        }
        try {
            $registry->get('afnor')->fetchStatus(new Flow('r-1'));
            self::fail('A call went out while An approved platform (XP Z12-013) asked to wait.');
        } catch (UsageLimitReachedException $e) {
            self::assertGreaterThanOrEqual(29, $e->retryAfter);
        }
        self::assertSame(1, $requests, 'the wait held the next request back');
    }
}
