<?php

namespace Omnibill\Afnor\Action;

use Omnibill\Afnor\Api;
use Omnibill\Request\Report;
use Omnibill\Request\Request;

/** An e-reporting file, submitted as a flow (flowSyntax FRR). */
final class ReportAction extends AbstractAction
{
    public function supports(Request $request): bool
    {
        return $request instanceof Report;
    }

    public function execute(Request $request): void
    {
        \assert($request instanceof Report);
        $report = $request->report;
        $info = $this->api->submit($report->content, $report->filename, $report->syntax, $request->trackingId, null, $report->rule);
        $request->setResult(Api::flow(['flowSyntax' => $report->syntax->value, 'flowDirection' => 'Out', 'acknowledgement' => ['status' => 'Pending']] + $info));
    }
}
