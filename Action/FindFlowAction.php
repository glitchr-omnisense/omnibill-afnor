<?php

namespace Omnibill\Afnor\Action;

use Omnibill\Afnor\Api;
use Omnibill\Afnor\Data;
use Omnibill\Model\Direction;
use Omnibill\Request\FindFlow;
use Omnibill\Request\Request;

/**
 * POST /v1/flows/search with where.trackingId and where.flowDirection (Out
 * for what the application sent): the flow it submitted with that key,
 * found again after an OutcomeUnknownException. When it is there more than
 * once, the one the platform did not reject, the earliest submitted; none:
 * null. A flow just deposited may take a moment to show in the search.
 */
final class FindFlowAction extends AbstractAction
{
    public function supports(Request $request): bool
    {
        return $request instanceof FindFlow;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof FindFlow);
        $found = $this->api->searchByTrackingId($request->trackingId, Direction::IN === $request->direction ? 'In' : 'Out');
        usort($found, static fn (array $a, array $b) => [self::rejected($a), Data::cast($a['submittedAt'] ?? '')] <=> [self::rejected($b), Data::cast($b['submittedAt'] ?? '')]);
        $request->setResult([] === $found ? null : Api::flow($found[0]));
    }

    /** @param array<string, mixed> $flow */
    private static function rejected(array $flow): bool
    {
        return 'Error' === Data::of($flow)->text('acknowledgement.status');
    }
}
