<?php

namespace KraftInk\Plugins\Shop\Services;

use App\Kraft\SiteDates;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use KraftInk\Plugins\Shop\Models\Billing\Dispute;
use KraftInk\Plugins\Shop\Models\Billing\Refund;
use KraftInk\Plugins\Shop\Models\Orders\Order;
use KraftInk\Plugins\Shop\Services\Payments\GatewayRegistry;

class SalesReport
{
    public function filters(array $input): array
    {
        $today = CarbonImmutable::now(app(SiteDates::class)->timezone());

        $filtersValidator = Validator::make(
            array_replace(
                [
                    'from' => $today->startOfMonth()->toDateString(),
                    'to' => $today->toDateString(),
                    'state' => '',
                    'q' => '',
                ],
                $input,
            ),
            [
                'from' => 'required|date_format:Y-m-d',
                'to' => 'required|date_format:Y-m-d|after_or_equal:from',
                'state' => ['nullable', Rule::in(['pending', 'paid', 'refunded', 'cancelled', 'charged_back'])],
                'q' => 'nullable|string|max:100',
            ],
        );

        return $filtersValidator->validate();
    }

    public function orders(array $filters): Builder
    {
        $timezone = app(SiteDates::class)->timezone();
        $from = CarbonImmutable::createFromFormat('!Y-m-d', $filters['from'], $timezone)->utc();
        $nextDay = CarbonImmutable::createFromFormat('!Y-m-d', $filters['to'], 'UTC')->addDay()->toDateString();
        $until = CarbonImmutable::createFromFormat('!Y-m-d', $nextDay, $timezone)->utc();
        $refunds = Refund::query()
            ->toBase()
            ->where('status', 'succeeded')
            ->groupBy('order_id', 'currency')
            ->select('order_id', 'currency')
            ->selectRaw('SUM(amount_minor) AS confirmed_minor');
        $disputes = Dispute::query()
            ->toBase()
            ->where('status', 'lost')
            ->groupBy('order_id', 'currency')
            ->select('order_id', 'currency')
            ->selectRaw('SUM(amount_minor) AS lost_minor');
        $query = Order::query()
            ->from('shop__orders as orders')
            ->toBase()
            ->leftJoin('users as buyers', 'buyers.id', '=', 'orders.user_id')
            ->leftJoinSub(
                $refunds,
                'refunds',
                fn ($join) => $join
                    ->on('refunds.order_id', '=', 'orders.id')
                    ->on('refunds.currency', '=', 'orders.currency'),
            )
            ->leftJoinSub(
                $disputes,
                'disputes',
                fn ($join) => $join
                    ->on('disputes.order_id', '=', 'orders.id')
                    ->on('disputes.currency', '=', 'orders.currency'),
            )
            ->where(function ($query) use ($from, $until) {
                $query
                    ->where(
                        fn ($query) => $query
                            ->whereNotNull('orders.paid_at')
                            ->where('orders.paid_at', '>=', $from)
                            ->where('orders.paid_at', '<', $until),
                    )
                    ->orWhere(
                        fn ($query) => $query
                            ->whereNull('orders.paid_at')
                            ->where('orders.created_at', '>=', $from)
                            ->where('orders.created_at', '<', $until),
                    );
            });
        if (!empty($filters['state'])) {
            $query->where('orders.status', $filters['state']);
        }
        if (!empty($filters['q'])) {
            $query->where(
                fn ($query) => $query
                    ->whereLike('orders.reference', '%'.$filters['q'].'%')
                    ->orWhereLike('buyers.username', '%'.$filters['q'].'%'),
            );
        }

        return $query;
    }

    public function summary(array $filters): Collection
    {
        [$external, $bindings] = $this->externalAmount();

        return $this->orders($filters)
            ->select('orders.currency')
            ->selectRaw(
                "COUNT(*) AS order_count, SUM(CASE WHEN orders.paid_at IS NOT NULL THEN 1 ELSE 0 END) AS settled_orders, SUM(CASE WHEN orders.status IN ('pending', 'paid') AND orders.financial_hold = ? AND orders.pricing_hold = ? AND orders.giftcard_hold = ? THEN 1 ELSE 0 END) AS active_orders, SUM(CASE WHEN orders.paid_at IS NOT NULL THEN orders.total_minor + orders.giftcard_minor + orders.wallet_minor ELSE 0 END) AS gross_minor, SUM(".
                    $external.
                    ') AS external_minor, SUM(COALESCE(refunds.confirmed_minor, 0)) AS refunded_minor, SUM(COALESCE(disputes.lost_minor, 0)) AS disputed_minor',
                [false, false, false, ...$bindings],
            )
            ->groupBy('orders.currency')
            ->orderBy('orders.currency')
            ->get();
    }

    public function details(array $filters): Builder
    {
        [$external, $bindings] = $this->externalAmount();

        return $this->orders($filters)
            ->select([
                'orders.id',
                'orders.reference',
                'orders.status',
                'orders.provider',
                'orders.currency',
                'orders.total_minor',
                'orders.giftcard_minor',
                'orders.wallet_minor',
                'orders.paid_at',
                'orders.created_at',
                'buyers.username as buyer_username',
            ])
            ->selectRaw(
                'COALESCE(refunds.confirmed_minor, 0) AS refunded_minor, COALESCE(disputes.lost_minor, 0) AS disputed_minor, '.
                    $external.
                    ' AS external_minor',
                $bindings,
            )
            ->orderByRaw('COALESCE(orders.paid_at, orders.created_at) DESC')
            ->orderByDesc('orders.id');
    }

    private function externalAmount(): array
    {
        $internal = ['wallet', 'giftcard'];
        foreach (app(GatewayRegistry::class)->all() as $gateway) {
            if (in_array('internal_balance', $gateway->capabilities(), true)) {
                $internal[] = $gateway->id();
            }
        }
        $internal = array_values(array_unique($internal));

        return [
            'CASE WHEN orders.paid_at IS NOT NULL AND orders.provider NOT IN ('.
            implode(',', array_fill(0, count($internal), '?')).
            ") AND COALESCE(orders.payment_kind, '') != 'wallet' THEN orders.total_minor ELSE 0 END",
            $internal,
        ];
    }

    public function csvCell(mixed $value): string
    {
        $value = mb_scrub(str_replace("\0", '', (string) ($value ?? '')), 'UTF-8');

        return preg_match('/^[\s\p{Z}\p{Cf}]*[=+\-@]/u', $value) || preg_match('/^[\t\r\n]/', $value)
            ? "'".$value
            : $value;
    }
}
