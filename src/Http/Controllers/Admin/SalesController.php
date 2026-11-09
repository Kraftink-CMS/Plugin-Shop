<?php

namespace KraftInk\Plugins\Shop\Http\Controllers\Admin;

use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Kraft\System\TableQuery;
use App\Kraft\Wallet\Money;
use Illuminate\Http\Request;
use KraftInk\Plugins\Shop\Services\SalesReport;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use RuntimeException;

class SalesController
{
    public function index(Request $request, SalesReport $report): Response
    {
        if (!$request->user()?->hasPermission('shop.manage')) {
            throw new HttpException(403);
        }
        $filters = $report->filters($request->query());

        return response()
            ->view('shop::admin.reports.sales', [
                'filters' => $filters,
                'summary' => $report->summary($filters),
                'orders' => TableQuery::paginate(
                    $report->details($filters),
                    [
                        'reference' => 'orders.reference',
                        'buyer' => 'buyers.username',
                        'status' => 'orders.status',
                        'created_at' => 'orders.created_at',
                    ],
                    pageName: 'page',
                    search: ['orders.reference', 'buyers.username'],
                )->withQueryString(),
            ])
            ->header('Cache-Control', 'private, no-store');
    }

    public function export(Request $request, SalesReport $report): StreamedResponse
    {
        if (!$request->user()?->hasPermission('shop.manage')) {
            throw new HttpException(403);
        }
        $filters = $report->filters($request->query());
        $lastId = $report->orders($filters)->max('orders.id') ?? 0;
        $rows = $report->details($filters)->where('orders.id', '<=', $lastId)->reorder();
        $headings = array_map(fn ($key) => __('shop::admin.reports.csv.'.$key), [
            'reference',
            'buyer',
            'state',
            'provider',
            'currency',
            'created_at',
            'paid_at',
            'gross',
            'external',
            'refunded',
            'disputed',
        ]);

        return response()->streamDownload(
            function () use ($report, $rows, $headings) {
                $stream = fopen('php://output', 'wb');
                if (!$stream) {
                    throw new RuntimeException('Cannot create the sales export.');
                }
                try {
                    fwrite($stream, "\xEF\xBB\xBF");
                    fputcsv($stream, $headings, ',', '"', '');
                    foreach ($rows->lazyById(500, 'orders.id', 'id') as $order) {
                        $values = [
                            $order->reference,
                            $order->buyer_username,
                            $order->status,
                            $order->provider,
                            $order->currency,
                            $order->created_at,
                            $order->paid_at,
                            Money::decimal(
                                $order->paid_at
                                    ? $order->total_minor + $order->giftcard_minor + ($order->wallet_minor ?? 0)
                                    : 0,
                            ),
                            Money::decimal((int) $order->external_minor),
                            Money::decimal((int) $order->refunded_minor),
                            Money::decimal((int) $order->disputed_minor),
                        ];
                        fputcsv($stream, array_map($report->csvCell(...), $values), ',', '"', '');
                    }
                } finally {
                    fclose($stream);
                }
            },
            'sales-'.$filters['from'].'-'.$filters['to'].'.csv',
            [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Cache-Control' => 'private, no-store',
                'X-Content-Type-Options' => 'nosniff',
            ],
        );
    }
}
