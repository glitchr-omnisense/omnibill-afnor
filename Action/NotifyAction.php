<?php

namespace Omnibill\Afnor\Action;

use Omnibill\Afnor\Api;
use Omnibill\Model\Direction;
use Omnibill\Model\Event;
use Omnibill\Model\Notification;
use Omnibill\Request\Notify;
use Omnibill\Request\Request;

/**
 * A webhook's call: its signature checked, the Flow it carries read. A flow
 * coming in announces something to receive().
 */
final class NotifyAction extends AbstractAction
{
    public function supports(Request $request): bool
    {
        return $request instanceof Notify;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof Notify);
        $data = $this->api->verifyWebhook($request->body, $request->header('Afnor-Signature'), $request->header('Afnor-Signature-Timestamp'));
        $flow = Api::flow($data);
        $request->setResult(new Notification(
            Direction::IN === $flow->direction ? Event::RECEIVED : Event::FLOW_UPDATED,
            $flow,
            $flow->updatedAt,
            $flow->reference.'@'.($data['updatedAt'] ?? $request->header('Afnor-Signature-Timestamp')),
            $data,
        ));
    }
}
