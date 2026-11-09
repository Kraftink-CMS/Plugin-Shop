<?php

namespace KraftInk\Plugins\Shop\Services\Imports\MineWeb;

use App\Kraft\Migration\ImportContext;
use App\Kraft\Migration\ImportedHtml;
use App\Kraft\Migration\MappingRegistry;
use App\Kraft\Wallet\Wallet;
use Illuminate\Support\Str;
use KraftInk\Plugins\Shop\Services\Catalog\Catalog;
use KraftInk\Plugins\Shop\Services\Imports\ImportRecords;
use RuntimeException;

final class Mappings extends ImportRecords
{
    public function register(MappingRegistry $registry): void
    {
        $registry->register(
            'mineweb',
            'shop__categories',
            fn ($row, $context) => $this->category($row, $context, 'shop__categories'),
            50,
            'shop',
        );
        $registry->register(
            'mineweb',
            'shop__sections',
            fn ($row, $context) => $this->category($row, $context, 'shop__sections'),
            49,
            'shop',
        );
        $registry->register(
            'mineweb',
            'shop__items',
            $this->product(...),
            60,
            'shop',
            dependencies: ['shop__categories'],
        );
        $registry->register(
            'mineweb',
            'shop__vouchers',
            $this->coupon(...),
            64,
            'shop',
            dependencies: ['users', 'shop__items', 'shop__categories'],
        );
        $registry->register('mineweb', 'shop__items_configs', $this->settings(...), 90, 'shop');
        foreach (['shop__paypal_histories', 'shop__nano_histories', 'shop__paysafecard_histories'] as $table) {
            $registry->register(
                'mineweb',
                $table,
                fn ($row, $context) => $this->payment($row, $context, $table),
                70,
                'shop',
                dependencies: ['users'],
            );
        }
        foreach (
            [
                'shop__items_buy_histories',
                'shop__starpass_histories',
                'shop__dedipass_histories',
                'shop__points_transfer_histories',
                'shop__vouchers_histories',
            ] as $table
        ) {
            $registry->register(
                'mineweb',
                $table,
                fn ($row, $context) => $this->archive($context, $table, $row['id'], 'missing_paid_amount'),
                85,
                'shop',
            );
        }
    }

    private function category(array $row, ImportContext $context, string $table, array $seen = []): array
    {
        if ($id = $context->mapped($table, $row['id'])) {
            return ['target_table' => 'shop__categories', 'target_id' => $id];
        }
        if (count($seen) >= 20 || in_array((string) $row['id'], $seen, true)) {
            throw new RuntimeException('Invalid imported shop category hierarchy.');
        }
        $parent = null;
        $parentTable = $table;
        $parentId = $row['parent_id'] ?? null;
        if ($table === 'shop__categories' && !empty($row['section']) && !empty($row['section_id'])) {
            $parentTable = 'shop__sections';
            $parentId = $row['section_id'];
        }
        if ($parentId) {
            $parent = $context->mapped($parentTable, $parentId);
            if ($parent === null) {
                $candidate = $this->row($context, $parentTable, $parentId);
                if ($candidate === null) {
                    throw new RuntimeException('Missing imported shop category parent.');
                }
                $parent = $context->remember(
                    $parentTable,
                    $candidate,
                    $this->category($candidate, $context, $parentTable, [...$seen, (string) $row['id']]),
                )['target_id'];
            }
        }

        if (!empty($row['cumulate_purchases']) || !empty($row['cumulate_strict'])) {
            $this->notice($context, 'review', $table, $row['id'], 'upgrade_policy');
        }

        return $this->insert($context, 'shop__categories', [
            'name' => $row['name'],
            'locale' => config('imports.locale'),
            'position' => $this->integer($row['position'] ?? ($row['order'] ?? 0)),
            'parent_id' => $parent,
            'description' => ImportedHtml::clean($row['description'] ?? ''),
            'exclusive' => (bool) ($row['single_purchase'] ?? false),
        ]);
    }

    private function product(array $row, ImportContext $context): array
    {
        $data = $this->productData($row, $context, 'shop__categories', $row['category']);
        $data['currency'] = app(Wallet::class)->currency();
        $data['requires_online'] = (bool) ($row['need_connect'] ?? false);
        $review = ['site_money_units'];
        $servers = $this->decode($row['servers'] ?? null);
        $mappedServers = [];
        foreach ($servers as $server) {
            $mappedServers[] = $this->relation($context, 'servers', $server, $review);
        }
        $mappedServers = array_values(array_filter(array_unique($mappedServers)));
        if (count($mappedServers) === 1) {
            $data['server_id'] = $mappedServers[0];
            $data['commands'] = $this->commands(
                explode('[{+}]', $row['commands'] ?? ''),
                ['{PLAYER}' => '{player}'],
                $review,
            );
        } elseif (!empty($row['commands'])) {
            $review[] = 'multiple_server_delivery';
        }
        foreach (['timedCommand', 'prerequisites_type', 'give_skin', 'give_cape'] as $field) {
            if (!empty($row[$field])) {
                $review[] = $field;
            }
        }
        foreach (['reductional_items', 'wait_time'] as $field) {
            if (!empty($row[$field])) {
                $review[] = $field;
            }
        }
        if (!empty($row['img_url'])) {
            $this->notice($context, 'media', 'shop__items', $row['id'], 'media_missing', [
                'field' => 'img_url',
                'path' => $row['img_url'],
            ]);
        }
        $data['required_permission'] = 'imports.review';
        foreach (array_unique($review) as $reason) {
            $this->notice($context, 'review', 'shop__items', $row['id'], $reason);
        }

        return $this->insert($context, 'shop__products', $data);
    }

    private function productData(array $row, ImportContext $context, string $table, mixed $category): array
    {
        $slug = app(Catalog::class)->productSlug(
            $row['slug'] ?? $row['name'],
            'import-'.substr(hash('sha256', $context->runId.':'.$table.':'.$row['id']), 0, 12),
        );

        return [
            'name' => $row['name'],
            'locale' => config('imports.locale'),
            'game' => $context->options['game'] ?? null,
            'slug' => $slug,
            'category_id' => $category === null ? null : $context->required($table, $category),
            'description' => ImportedHtml::clean($row['description'] ?? ''),
            'price_minor' => $this->minor($row['price']),
            'currency' => app(Wallet::class)->currency(),
            'limit_per_user' => $this->limit($row['user_limit'] ?? ($row['buy_limit'] ?? null)),
            'enabled' => false,
            'position' => $this->integer($row['position'] ?? ($row['order'] ?? 0)),
            'search_text' => Str::lower(Str::ascii($row['name'].' '.strip_tags($row['description'] ?? ''))),
            'created_at' => $row['created_at'] ?? ($row['created'] ?? now()),
            'updated_at' => $row['updated_at'] ?? ($row['updated'] ?? now()),
        ];
    }

    private function coupon(array $row, ImportContext $context): array
    {
        $scope = $this->decode($row['effective_on']);
        if (!in_array($scope['type'] ?? '', ['all', 'items', 'categories'], true)) {
            return $this->archive($context, 'shop__vouchers', $row['id'], 'coupon_scope');
        }
        $products = [];
        if ($scope['type'] !== 'all') {
            foreach ($this->rows($context, 'shop__items') as $item) {
                if (
                    in_array(
                        (string) ($scope['type'] === 'items' ? $item['id'] : $item['category']),
                        array_map('strval', $scope['value'] ?? []),
                        true,
                    )
                ) {
                    $products[] = $context->required('shop__items', $item['id']);
                }
            }
            $products = $products ?: [0];
        }
        $fixed = (int) $row['type'] === 2;
        $result = $this->insert($context, 'shop__coupons', [
            'code' => $this->couponCode($row['code'], $context),
            'type' => $fixed ? 'fixed' : 'percent',
            'amount' => $fixed ? $this->minor($row['reduction']) : min(10000, $this->integer($row['reduction']) * 100),
            'currency' => app(Wallet::class)->currency(),
            'product_ids' => json_encode($products, JSON_THROW_ON_ERROR),
            'max_uses' => (int) ($row['limit_type'] ?? 1) === 2 ? $this->limit($row['limit'] ?? null) : null,
            'per_user_limit' => (int) ($row['limit_type'] ?? 1) === 1 ? $this->limit($row['limit'] ?? null) : null,
            'starts_at' => $row['start_date'] ?? null,
            'expires_at' => $row['end_date'] ?? null,
            'enabled' => false,
        ]);
        foreach (array_count_values(array_map('strval', $this->decode($row['used'] ?? null))) as $user => $uses) {
            $this->usage(
                $context,
                $result['target_id'],
                $context->mapped('users', $user),
                $uses,
                'voucher:'.$row['id'].':'.$user,
            );
        }

        return $result;
    }

    private function payment(array $row, ImportContext $context, string $table): array
    {
        $currency = $table === 'shop__nano_histories' ? $row['currency'] : 'EUR';
        $amount = $row['payment_amount'] ?? ($row['amount'] ?? null);
        if ($amount === null || empty($row['user_id']) || $context->mapped('users', $row['user_id']) === null) {
            return $this->archive($context, $table, $row['id'], 'unresolved_buyer');
        }
        $date = $row['created'] ?? now();
        $result = $this->historicalOrder($context, [
            'id' => $table.':'.$row['id'],
            'user_id' => $context->required('users', $row['user_id']),
            'status' => 'paid',
            'currency' => $this->currency($currency),
            'amount_minor' => $this->minor($amount),
            'created_at' => $date,
            'updated_at' => $date,
            'source_status' => 'completed',
        ]);
        $this->insert($context, 'shop__order_items', [
            'order_id' => $result['target_id'],
            'name' => $table === 'shop__paypal_histories'
                    ? 'PayPal'
                    : ($table === 'shop__nano_histories'
                        ? 'Nano'
                        : 'Paysafecard'),
            'quantity' => 1,
            'unit_minor' => $this->minor($amount),
            'paid_minor' => $this->minor($amount),
            'commands' => null,
            'role_id' => null,
        ]);

        return $result;
    }

    private function settings(array $row, ImportContext $context): array
    {
        if (isset($row['goal'])) {
            $context->setting('shop.storefront.goal_minor', $this->minor($row['goal']));
            $context->setting('shop.storefront.goal_currency', 'EUR');
            $context->setting('shop.storefront.goal_enabled', false);
        }

        return ['target_table' => null, 'target_id' => null];
    }

    private function decode(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }
        if (!is_string($value) || $value === '') {
            return [];
        }
        $array = json_decode($value, true);
        if (!is_array($array)) {
            $array = @unserialize($value, ['allowed_classes' => false, 'max_depth' => 20]);
        }
        if (!is_array($array)) {
            return [];
        }
        array_walk_recursive($array, function ($item): void {
            if (is_object($item) || is_resource($item)) {
                throw new RuntimeException('Invalid structured shop import value.');
            }
        });

        return $array;
    }
}
