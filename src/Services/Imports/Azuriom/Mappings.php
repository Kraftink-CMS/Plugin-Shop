<?php

namespace KraftInk\Plugins\Shop\Services\Imports\Azuriom;

use App\Kraft\Currencies\CurrencyRegistry;
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
        $registry->settings('azuriom', $this->setting(...), 'shop');
        $registry->register(
            'azuriom',
            'shop_categories',
            fn ($row, $context) => $this->category($row, $context, 'shop_categories'),
            50,
            'shop',
        );
        $registry->register('azuriom', 'shop_variables', $this->variable(...), 52, 'shop');
        $registry->register(
            'azuriom',
            'shop_packages',
            $this->product(...),
            60,
            'shop',
            dependencies: ['shop_categories'],
        );
        $registry->register('azuriom', 'shop_offers', $this->offer(...), 60, 'shop');
        $registry->register(
            'azuriom',
            'shop_package_variable',
            $this->productVariable(...),
            62,
            'shop',
            dependencies: ['shop_packages', 'shop_variables'],
        );
        $registry->register('azuriom', 'shop_coupons', $this->coupon(...), 64, 'shop', dependencies: ['shop_packages']);
        $registry->register(
            'azuriom',
            'shop_coupon_package',
            $this->couponProduct(...),
            65,
            'shop',
            dependencies: ['shop_coupons', 'shop_packages'],
        );
        $registry->register('azuriom', 'shop_giftcards', $this->giftcard(...), 66, 'shop');
        $registry->register(
            'azuriom',
            'shop_discounts',
            $this->discount(...),
            67,
            'shop',
            dependencies: ['roles', 'shop_packages'],
        );
        $registry->register(
            'azuriom',
            'shop_discount_package',
            fn ($row, $context) => [
                'target_table' => 'shop__privileged_prices',
                'target_id' => $context->mapped('shop_discounts', $row['discount_id']),
            ],
            68,
            'shop',
            dependencies: ['shop_discounts', 'shop_packages'],
        );
        $registry->register('azuriom', 'shop_payments', $this->payment(...), 70, 'shop', dependencies: ['users']);
        $registry->register(
            'azuriom',
            'shop_payment_items',
            $this->paymentItem(...),
            80,
            'shop',
            dependencies: ['shop_payments'],
        );
        $registry->register(
            'azuriom',
            'shop_coupon_payment',
            $this->couponPayment(...),
            82,
            'shop',
            dependencies: ['shop_payments', 'shop_coupons'],
        );
        $registry->register(
            'azuriom',
            'shop_giftcard_payment',
            $this->giftcardPayment(...),
            82,
            'shop',
            dependencies: ['shop_payments', 'shop_giftcards'],
        );
        $registry->register(
            'azuriom',
            'shop_subscriptions',
            $this->subscription(...),
            84,
            'shop',
            dependencies: ['shop_payments', 'shop_packages', 'users'],
        );
        foreach (['shop_gateways', 'shop_gateway_metadatas', 'shop_package_codes'] as $table) {
            $registry->register(
                'azuriom',
                $table,
                fn ($row, $context) => $this->archive($context, $table, $row['id'] ?? null, 'manual_configuration'),
                90,
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
        $data = $this->productData($row, $context, 'shop_categories', $row['category_id']);
        $review = [];
        $category = $this->row($context, 'shop_categories', $row['category_id']);
        if (!empty($category['cumulate_purchases']) || !empty($category['cumulate_strict'])) {
            $review[] = 'upgrade_policy';
        }
        $data['role_id'] = $this->relation($context, 'roles', $row['role_id'] ?? null, $review);
        $data['custom_price'] = (bool) ($row['custom_price'] ?? false);
        $data['minimum_price_minor'] = $data['custom_price'] ? $data['price_minor'] : null;
        $data['global_limit'] = $this->limit($row['global_limit'] ?? null);
        $userPeriod = $row['user_limit_period'] ?? null;
        $globalPeriod = $row['global_limit_period'] ?? null;
        $period = $userPeriod ?: $globalPeriod;
        if (
            $period !== null &&
            (!in_array($period, ['day', 'week', 'month', 'year'], true) ||
                ($userPeriod && $globalPeriod && $userPeriod !== $globalPeriod))
        ) {
            $review[] = 'purchase_limit_period';
        } else {
            $data['limit_period'] = $period;
        }
        if (!empty($row['required_roles']) && $this->decode($row['required_roles']) !== []) {
            $review[] = 'required_roles';
        }
        if (!empty($row['required_packages']) && $this->decode($row['required_packages']) !== []) {
            $review[] = 'required_products';
        }
        if (!empty($row['limits_no_expired']) || !empty($row['expired_role_id'])) {
            $review[] = 'expiration_policy';
        }
        $billing = $row['billing_type'] ?? 'one-off';
        if ($billing === 'subscription' && in_array($row['billing_period'] ?? null, ['1 month', '1 year'], true)) {
            $data['billing_interval'] = $row['billing_period'] === '1 month' ? 'month' : 'year';
        } elseif (
            $billing === 'expiring' &&
            preg_match('/^([1-9][0-9]{0,4}) days?$/D', $row['billing_period'] ?? '', $match) &&
            $data['role_id']
        ) {
            $data['role_days'] = min(36500, (int) $match[1]);
        } elseif ($billing !== 'one-off') {
            $review[] = 'billing_period';
        }
        if (isset($row['money']) && $this->minor($row['money']) > 0) {
            $data['wallet_credit_minor'] = $this->minor($row['money']);
            $data['wallet_currency'] = app(Wallet::class)->currency();
            $review[] = 'site_money_units';
        }
        if (isset($row['giftcard_balance'])) {
            $data['kind'] = 'giftcard';
            if (
                $this->minor($row['giftcard_balance']) !== 0 &&
                $this->minor($row['giftcard_balance']) !== $data['price_minor']
            ) {
                $review[] = 'giftcard_face_value';
            }
        }
        if ($this->decode($row['files'] ?? null) !== []) {
            $review[] = 'download_files';
            foreach ($this->decode($row['files']) as $path => $name) {
                if (is_string($path) && basename($path) === $path) {
                    $this->notice($context, 'media', 'shop_packages', $row['id'], 'download_files', [
                        'field' => 'files',
                        'path' => 'storage/app/shop/packages/'.$path,
                    ]);
                }
            }
        }
        $commands = $this->productCommands($row, $context, $review);
        $data += $commands;
        $data['requires_online'] = $commands['requires_online'] ?? false;
        if (!empty($row['image'])) {
            $this->notice($context, 'media', 'shop_packages', $row['id'], 'media_missing', [
                'field' => 'image',
                'path' => 'storage/app/public/packages/'.$row['image'],
            ]);
        }
        if (!app(CurrencyRegistry::class)->find($data['currency'])) {
            $review[] = 'unsupported_currency';
        }
        if ($review !== []) {
            $data['required_permission'] = 'imports.review';
            foreach (array_unique($review) as $reason) {
                $this->notice($context, 'review', 'shop_packages', $row['id'], $reason);
            }
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
            'currency' => $this->catalogCurrency($context),
            'limit_per_user' => $this->limit($row['user_limit'] ?? ($row['buy_limit'] ?? null)),
            'enabled' => false,
            'position' => $this->integer($row['position'] ?? ($row['order'] ?? 0)),
            'search_text' => Str::lower(Str::ascii($row['name'].' '.strip_tags($row['description'] ?? ''))),
            'created_at' => $row['created_at'] ?? ($row['created'] ?? now()),
            'updated_at' => $row['updated_at'] ?? ($row['updated'] ?? now()),
        ];
    }

    private function offer(array $row, ImportContext $context): array
    {
        $data = $this->productData($row, $context, 'shop_categories', null);
        $data['currency'] = $this->currency(
            $this->sourceSettings($context)['currency'] ?? config('imports.currency', 'USD'),
        );
        $data['wallet_credit_minor'] = $this->minor($row['money']);
        $data['wallet_currency'] = app(Wallet::class)->currency();
        $data['required_permission'] = 'imports.review';
        $this->notice($context, 'review', 'shop_offers', $row['id'], 'site_money_units');
        if (!empty($row['image'])) {
            $this->notice($context, 'media', 'shop_offers', $row['id'], 'media_missing', [
                'field' => 'image',
                'path' => 'storage/app/public/offers/'.$row['image'],
            ]);
        }

        return $this->insert($context, 'shop__products', $data);
    }

    private function productCommands(array $row, ImportContext $context, array &$review): array
    {
        $groups = $this->decode($row['commands'] ?? null);
        $global = $this->decode($this->sourceSettings($context)['shop.commands'] ?? null);
        $groups = array_merge($groups, $global);
        $purchase = [];
        $refund = [];
        $servers = [];
        $online = false;
        foreach ($groups as $group) {
            if (is_string($group)) {
                $purchase[] = $group;

                continue;
            }
            if (
                !is_array($group) ||
                !in_array($group['trigger'] ?? 'purchase', ['purchase', 'refund'], true) ||
                !empty($group['ignore_quantity'])
            ) {
                $review[] = 'command_triggers';

                continue;
            }
            $server = $group['server'] ?? null;
            if ($server !== null && !is_numeric($server)) {
                $review[] = 'dynamic_server';

                continue;
            }
            if ($server !== null) {
                $servers[] = $this->relation($context, 'servers', $server, $review);
            }
            $online = $online || !empty($group['require_online']);
            $list = $group['commands'] ?? [$group['command'] ?? ''];
            if (($group['trigger'] ?? 'purchase') === 'refund') {
                $refund = array_merge($refund, $list);
            } else {
                $purchase = array_merge($purchase, $list);
            }
        }
        if (count(array_unique(array_filter($servers))) > 1) {
            $review[] = 'multiple_server_delivery';
            $purchase = $refund = [];
        }
        $variables = [];
        foreach ($this->rows($context, 'shop_package_variable') as $pivot) {
            if ((string) $pivot['package_id'] === (string) $row['id']) {
                $variable = $this->row($context, 'shop_variables', $pivot['variable_id']);
                if ($variable && preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $variable['name'])) {
                    $variables['{'.$variable['name'].'}'] = '{var:'.$variable['name'].'}';
                }
            }
        }

        return [
            'commands' => $this->commands($purchase, $variables, $review),
            'refund_commands' => $this->commands($refund, $variables, $review),
            'server_id' => count(array_unique(array_filter($servers))) === 1 ? array_values(array_filter($servers))[0] : null,
            'requires_online' => $online,
        ];
    }

    private function variable(array $row, ImportContext $context): array
    {
        if (
            !preg_match('/^[a-z][a-z0-9_]{0,31}$/D', $row['name']) ||
            !in_array($row['type'], ['text', 'number', 'dropdown'], true)
        ) {
            return $this->archive($context, 'shop_variables', $row['id'], 'variable_validation');
        }
        $validation = $this->decode($row['validation'] ?? null);
        if (!empty($validation['filter']) || $row['type'] === 'number') {
            return $this->archive($context, 'shop_variables', $row['id'], 'variable_validation');
        }
        $definition = [
            'key' => $row['name'],
            'label' => [
                config('imports.locale') => Str::limit(strip_tags($row['description'] ?? '' ?: $row['name']), 120, ''),
            ],
            'type' => $row['type'] === 'dropdown' ? 'choice' : 'text',
            'required' => (bool) ($row['is_required'] ?? false),
            'min' => max(0, (int) ($validation['min'] ?? 0)),
            'max' => min(120, max(1, (int) ($validation['max'] ?? 100))),
        ];
        if ($definition['type'] === 'choice') {
            $definition['choices'] = array_values(
                array_map(fn ($option) => $option['value'], $this->decode($row['options'] ?? null)),
            );
            if (
                $definition['choices'] === [] ||
                count($definition['choices']) > 30 ||
                array_filter(
                    $definition['choices'],
                    fn ($value) => !is_string($value) || !preg_match('/^[\p{L}\p{N}_.:-]{1,120}$/uD', $value),
                )
            ) {
                return $this->archive($context, 'shop_variables', $row['id'], 'variable_validation');
            }
        }

        return ['target_table' => null, 'target_id' => null, 'metadata' => ['definition' => $definition]];
    }

    private function productVariable(array $row, ImportContext $context): array
    {
        $product = $context->required('shop_packages', $row['package_id']);
        $definition = $context->metadata('shop_variables', $row['variable_id'])['definition'] ?? null;
        if ($definition === null) {
            $context->target
                ->table('shop__products')
                ->where('id', $product)
                ->update(['required_permission' => 'imports.review']);

            return $this->archive($context, 'shop_package_variable', $row['package_id'], 'variable_validation');
        }
        $definitions = json_decode(
            $context->target->table('shop__products')->where('id', $product)->value('variables') ?? '[]',
            true,
        );
        $definitions[$definition['key']] = $definition;
        $context->target
            ->table('shop__products')
            ->where('id', $product)
            ->update(['variables' => json_encode(array_values($definitions), JSON_THROW_ON_ERROR)]);

        return ['target_table' => 'shop__products', 'target_id' => $product];
    }

    private function coupon(array $row, ImportContext $context): array
    {
        $products = [];
        if (empty($row['is_global'])) {
            foreach ($this->rows($context, 'shop_coupon_package') as $pivot) {
                if ((string) $pivot['coupon_id'] === (string) $row['id']) {
                    $products[] = $context->required('shop_packages', $pivot['package_id']);
                }
            }
            if ($products === []) {
                $products = [0];
            }
        }
        $fixed = (bool) ($row['is_fixed'] ?? false);

        return $this->insert($context, 'shop__coupons', [
            'code' => $this->couponCode($row['code'], $context),
            'type' => $fixed ? 'fixed' : 'percent',
            'amount' => $fixed ? $this->minor($row['discount']) : min(10000, $this->integer($row['discount']) * 100),
            'currency' => $this->catalogCurrency($context),
            'product_ids' => json_encode($products, JSON_THROW_ON_ERROR),
            'max_uses' => $this->limit($row['global_limit'] ?? null),
            'per_user_limit' => $this->limit($row['user_limit'] ?? null),
            'starts_at' => $row['start_at'] ?? null,
            'expires_at' => $row['expire_at'] ?? null,
            'enabled' => false,
        ]);
    }

    private function couponProduct(array $row, ImportContext $context): array
    {
        return [
            'target_table' => 'shop__coupons',
            'target_id' => $context->required('shop_coupons', $row['coupon_id']),
        ];
    }

    private function giftcard(array $row, ImportContext $context): array
    {
        $code = strtoupper(trim($row['code']));
        if (
            $code === '' ||
            strlen($code) > 120 ||
            $context->target->table('shop__giftcards')->where('code_hash', hash('sha256', $code))->exists()
        ) {
            return $this->archive($context, 'shop_giftcards', $row['id'], 'giftcard_code_conflict');
        }

        return $this->insert($context, 'shop__giftcards', [
            'code_hash' => hash('sha256', $code),
            'last_four' => substr($code, -4),
            'balance_minor' => $this->minor($row['balance']),
            'currency' => $this->catalogCurrency($context),
            'expires_at' => $row['expire_at'] ?? null,
            'enabled' => false,
            'created_at' => $row['created_at'] ?? now(),
        ]);
    }

    private function discount(array $row, ImportContext $context): array
    {
        if (empty($context->options['game'])) {
            return $this->archive($context, 'shop_discounts', $row['id'], 'target_game_required');
        }
        $roles = $this->decode($row['roles'] ?? null);
        if ($roles === []) {
            $roles = array_column($this->rows($context, 'roles'), 'id');
        }
        $products = [null];
        if (empty($row['is_global'])) {
            $products = [];
            foreach ($this->rows($context, 'shop_discount_package') as $pivot) {
                if ((string) $pivot['discount_id'] === (string) $row['id']) {
                    $products[] = $context->required('shop_packages', $pivot['package_id']);
                }
            }
        }
        $first = null;
        foreach ($roles as $role) {
            $roleId = $context->mapped('roles', $role);
            if ($roleId === null) {
                continue;
            }
            foreach ($products as $product) {
                $result = $this->insert($context, 'shop__privileged_prices', [
                    'name' => Str::limit($row['name'], 120, ''),
                    'game' => $context->options['game'],
                    'currency' => $this->catalogCurrency($context),
                    'role_id' => $roleId,
                    'scope' => $product === null ? 'all' : 'product',
                    'product_id' => $product,
                    'type' => 'percent',
                    'amount' => min(10000, $this->integer($row['discount']) * 100),
                    'combine_coupon' => false,
                    'enabled' => false,
                    'starts_at' => $row['start_at'] ?? null,
                    'expires_at' => $row['end_at'] ?? null,
                    'created_at' => $row['created_at'] ?? now(),
                    'updated_at' => $row['updated_at'] ?? now(),
                ]);
                $first ??= $result['target_id'];
            }
        }
        $this->notice($context, 'review', 'shop_discounts', $row['id'], 'discount_policy');

        return ['target_table' => 'shop__privileged_prices', 'target_id' => $first];
    }

    private function payment(array $row, ImportContext $context): array
    {
        if (empty($row['user_id']) || $context->mapped('users', $row['user_id']) === null) {
            return $this->archive($context, 'shop_payments', $row['id'], 'unresolved_buyer');
        }
        $status = match ($row['status']) {
            'completed' => 'paid',
            'refunded', 'chargeback' => 'refunded',
            default => 'expired',
        };
        $currency = $this->currency($row['currency'] ?? $this->catalogCurrency($context));
        $amount = $this->minor($row['price']);
        $date = $row['created_at'] ?? now();

        return $this->historicalOrder($context, [
            'id' => $row['id'],
            'user_id' => $context->required('users', $row['user_id']),
            'status' => $status,
            'currency' => $currency,
            'amount_minor' => $amount,
            'created_at' => $date,
            'updated_at' => $row['updated_at'] ?? $date,
            'source_status' => $row['status'],
        ]);
    }

    private function paymentItem(array $row, ImportContext $context): array
    {
        $order = $context->mapped('shop_payments', $row['payment_id']);
        if ($order === null) {
            return $this->archive($context, 'shop_payment_items', $row['id'], 'unresolved_buyer');
        }
        $type = $row['buyable_type'] ?? '';
        $source = in_array($type, ['shop.packages', 'Azuriom\\Plugin\\Shop\\Models\\Package'], true)
            ? 'shop_packages'
            : (in_array($type, ['shop.offers', 'Azuriom\\Plugin\\Shop\\Models\\Offer'], true)
                ? 'shop_offers'
                : null);
        $product = $source ? $context->mapped($source, $row['buyable_id'] ?? null) : null;
        $category = $product
            ? $context->target->table('shop__products')->where('id', $product)->value('category_id')
            : null;
        $quantity = $this->integer($row['quantity']);
        if ($quantity < 1) {
            throw new RuntimeException('Invalid imported shop quantity.');
        }
        $amount = $this->minor($row['price']);

        return $this->insert($context, 'shop__order_items', [
            'order_id' => $order,
            'product_id' => $product,
            'category_id' => $category,
            'name' => $row['name'],
            'quantity' => $quantity,
            'unit_minor' => $amount,
            'paid_minor' => null,
            'pricing_snapshot' => json_encode(
                ['imported' => true, 'source' => 'azuriom', 'expires_at' => $row['expires_at'] ?? null],
                JSON_THROW_ON_ERROR,
            ),
            'commands' => null,
            'role_id' => null,
            'variables' => json_encode($this->decode($row['variables'] ?? null), JSON_THROW_ON_ERROR),
        ]);
    }

    private function couponPayment(array $row, ImportContext $context): array
    {
        $coupon = $context->mapped('shop_coupons', $row['coupon_id']);
        $payment = $this->row($context, 'shop_payments', $row['payment_id']);
        if ($coupon === null || $payment === null || $payment['status'] !== 'completed') {
            return ['target_table' => null, 'target_id' => null];
        }
        $this->usage(
            $context,
            $coupon,
            $context->mapped('users', $payment['user_id']),
            1,
            'coupon-payment:'.$row['coupon_id'].':'.$row['payment_id'],
            $payment['created_at'] ?? null,
        );

        return ['target_table' => 'shop__coupons', 'target_id' => $coupon];
    }

    private function giftcardPayment(array $row, ImportContext $context): array
    {
        $order = $context->mapped('shop_payments', $row['payment_id']);
        if ($order === null) {
            return ['target_table' => null, 'target_id' => null];
        }
        $amount = $this->minor($row['amount']);
        $record = $context->target->table('shop__orders')->find($order);
        $giftcard = $context->mapped('shop_giftcards', $row['giftcard_id']);
        $context->target
            ->table('shop__orders')
            ->where('id', $order)
            ->update([
                'giftcard_minor' => $this->integer($record->giftcard_minor + $amount),
                'giftcard_id' => $record->giftcard_id ?: $giftcard,
                'subtotal_minor' => $this->integer($record->subtotal_minor + $amount),
            ]);

        return ['target_table' => 'shop__orders', 'target_id' => $order];
    }

    private function subscription(array $row, ImportContext $context): array
    {
        $order = null;
        foreach ($this->rows($context, 'shop_payments') as $payment) {
            if (
                (string) ($payment['subscription_id'] ?? '') === (string) $row['id'] &&
                ($mapped = $context->mapped('shop_payments', $payment['id']))
            ) {
                $order = $mapped;
            }
        }
        if ($order === null || !$context->mapped('users', $row['user_id'])) {
            return $this->archive($context, 'shop_subscriptions', $row['id'], 'subscription_reconnect');
        }
        $this->notice($context, 'review', 'shop_subscriptions', $row['id'], 'subscription_reconnect');

        return $this->insert($context, 'shop__subscriptions', [
            'provider' => 'legacy',
            'provider_reference' => 'import:'.hash('sha256', $context->runId.':subscription:'.$row['id']),
            'order_id' => $order,
            'user_id' => $context->required('users', $row['user_id']),
            'product_id' => $context->mapped('shop_packages', $row['package_id']),
            'status' => 'expired',
            'paid_until' => $row['ends_at'] ?? null,
            'cancel_at_period_end' => true,
            'created_at' => $row['created_at'] ?? now(),
            'updated_at' => $row['updated_at'] ?? now(),
        ]);
    }

    private function setting(array $row, ImportContext $context): void
    {
        $key = $row['key'];
        $value = $row['value'];
        if ($key === 'shop.month_goal') {
            $amount = $value === null || $value === '' ? 0 : $this->minor($value);
            $context->setting('shop.storefront.goal_minor', $amount);
            $context->setting('shop.storefront.goal_currency', $this->catalogCurrency($context));
            $context->setting('shop.storefront.goal_enabled', false);
        } elseif ($key === 'shop.recent_payments') {
            $context->setting('shop.storefront.recent_limit', max(1, min(20, (int) $value)));
            $context->setting('shop.storefront.recent_enabled', false);
        } elseif ($key === 'shop.display_amount') {
            $context->setting('shop.storefront.recent_amounts', (bool) $value);
        } elseif (str_starts_with($key, 'shop.')) {
            $this->notice($context, 'review', 'settings', null, 'manual_configuration', ['field' => $key]);
        }
    }

    private function sourceSettings(ImportContext $context): array
    {
        $settings = [];
        foreach ($this->rows($context, 'settings') as $row) {
            $settings[$row['name'] ?? $row['key']] = $row['value'];
        }

        return $settings;
    }

    private function catalogCurrency(ImportContext $context): string
    {
        $settings = $this->sourceSettings($context);
        if (!empty($settings['shop.use_site_money'])) {
            return app(Wallet::class)->currency();
        }

        return $this->currency($settings['currency'] ?? config('imports.currency', 'EUR'));
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
