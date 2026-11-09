<?php

namespace KraftInk\Plugins\Shop\Services\Imports\KraftInk;

use App\Kraft\Migration\ImportContext;
use App\Kraft\Migration\ImportedHtml;
use App\Kraft\Migration\MappingRegistry;
use Illuminate\Support\Str;
use KraftInk\Plugins\Shop\Services\Catalog\Catalog;
use RuntimeException;

final class Mappings
{
    public function register(MappingRegistry $registry): void
    {
        $registry->register('kraftink', 'shop__categories', $this->nativeCategory(...), 50, 'shop');
        $registry->register('kraftink', 'shop__upgrade_groups', $this->nativeGroup(...), 52, 'shop');
        $registry->register(
            'kraftink',
            'shop__products',
            $this->nativeProduct(...),
            60,
            'shop',
            dependencies: ['shop__categories', 'shop__upgrade_groups', 'roles', 'servers'],
        );
        $registry->register(
            'kraftink',
            'shop__product_servers',
            $this->nativeProductServer(...),
            62,
            'shop',
            dependencies: ['shop__products', 'servers'],
        );
        $registry->register(
            'kraftink',
            'shop__coupons',
            $this->nativeCoupon(...),
            64,
            'shop',
            dependencies: ['shop__categories', 'shop__products', 'roles', 'users'],
        );
        $registry->register(
            'kraftink',
            'shop__giftcards',
            $this->nativeGiftcard(...),
            66,
            'shop',
            dependencies: ['users'],
        );
        $registry->register(
            'kraftink',
            'shop__coupon_usage_baselines',
            fn (array $row, ImportContext $context) => $this->nativeInsert('shop__coupon_usage_baselines', $context, [
                'coupon_id' => $context->required('shop__coupons', $row['coupon_id']),
                'user_id' => $this->nativeRelation($context, 'users', $row['user_id'] ?? null),
                'uses' => $this->nativeInteger($row['uses']),
                'source_key' => hash('sha256', $context->runId.':coupon-usage:'.$row['id']),
                'occurred_at' => $row['occurred_at'] ?? null,
            ]),
            68,
            'shop',
            dependencies: ['shop__coupons', 'users'],
        );
        $registry->register(
            'kraftink',
            'shop__orders',
            $this->nativeOrder(...),
            70,
            'shop',
            dependencies: ['users', 'shop__coupons', 'shop__giftcards'],
        );
        $registry->register(
            'kraftink',
            'shop__order_items',
            $this->nativeItem(...),
            80,
            'shop',
            dependencies: ['shop__orders', 'shop__products', 'shop__categories', 'roles', 'users', 'servers'],
        );
        $registry->register(
            'kraftink',
            'shop__financial_events',
            $this->nativeFinancialEvent(...),
            85,
            'shop',
            dependencies: ['shop__orders'],
        );
        $registry->register(
            'kraftink',
            'shop__disputes',
            $this->nativeDispute(...),
            86,
            'shop',
            dependencies: ['shop__orders'],
        );
    }

    private function nativeCategory(array $row, ImportContext $context, array $parents = []): array
    {
        if ($existing = $context->mapped('shop__categories', $row['id'])) {
            return ['target_table' => 'shop__categories', 'target_id' => $existing];
        }
        if (count($parents) >= 20 || in_array((string) $row['id'], $parents, true)) {
            throw new RuntimeException('Imported shop categories contain an invalid hierarchy.');
        }
        $parent = null;
        if (($row['parent_id'] ?? null) !== null) {
            $parent = $context->mapped('shop__categories', $row['parent_id']);
            if ($parent === null) {
                foreach ($context->sourceRows('shop__categories') ?? [] as $candidate) {
                    if ((string) $candidate['id'] === (string) $row['parent_id']) {
                        $result = $this->nativeCategory($candidate, $context, [...$parents, (string) $row['id']]);
                        $context->remember('shop__categories', $candidate, $result);
                        $parent = $result['target_id'];
                        break;
                    }
                }
            }
            if ($parent === null) {
                throw new RuntimeException('An imported shop category parent is missing.');
            }
        }

        return $this->nativeInsert('shop__categories', $context, [
            'name' => $row['name'],
            'locale' => $row['locale'],
            'position' => $this->nativeInteger($row['position'] ?? 0),
            'parent_id' => $parent,
            'description' => ImportedHtml::clean($row['description'] ?? ''),
            'translations' => $this->nativeTranslations($row['translations'] ?? null),
            'exclusive' => (bool) ($row['exclusive'] ?? false),
            'image_id' => null,
        ]);
    }

    private function nativeGroup(array $row, ImportContext $context): array
    {
        $currency = $this->nativeCurrency($row['currency']);
        $slug = $row['slug'];
        if (
            $context->target
                ->table('shop__upgrade_groups')
                ->where('game', $row['game'])
                ->where('currency', $currency)
                ->where('slug', $slug)
                ->exists()
        ) {
            $slug =
                substr($slug, 0, 60).'-import-'.substr(hash('sha256', $context->runId.':'.$row['id']), 0, 12);
        }
        $result = $this->nativeInsert('shop__upgrade_groups', $context, [
            'name' => $row['name'],
            'slug' => $slug,
            'game' => $row['game'],
            'currency' => $currency,
            'strategy' => $row['strategy'] ?? 'net-paid',
            'expired_policy' => $row['expired_policy'] ?? 'reset',
            'enabled' => false,
            'created_at' => $row['created_at'] ?? now(),
            'updated_at' => $row['updated_at'] ?? now(),
        ]);

        return $result + ['metadata' => ['slug' => $slug]];
    }

    private function nativeProduct(array $row, ImportContext $context): array
    {
        $slug = $row['slug'] ?? app(Catalog::class)->productSlug($row['name'], (string) $row['id']);
        if (
            $context->target
                ->table('shop__products')
                ->where('game', $row['game'] ?? null)
                ->where('slug', $slug)
                ->exists()
        ) {
            $slug =
                substr($slug, 0, 155).'-import-'.substr(hash('sha256', $context->runId.':'.$row['id']), 0, 12);
        }
        $group = null;
        if (!empty($row['upgrade_group'])) {
            foreach ($context->sourceRows('shop__upgrade_groups') ?? [] as $candidate) {
                if (
                    $candidate['slug'] === $row['upgrade_group'] &&
                    $candidate['game'] === ($row['game'] ?? null) &&
                    $candidate['currency'] === $row['currency']
                ) {
                    $context->required('shop__upgrade_groups', $candidate['id']);
                    $group = $context->metadata('shop__upgrade_groups', $candidate['id'])['slug'] ?? null;
                    break;
                }
            }
            if ($group === null) {
                throw new RuntimeException('An imported shop upgrade group is missing.');
            }
        }
        $data = [
            'name' => $row['name'],
            'locale' => $row['locale'],
            'game' => $row['game'] ?? null,
            'slug' => $slug,
            'category_id' => $this->nativeRelation($context, 'shop__categories', $row['category_id'] ?? null),
            'role_id' => $this->nativeRelation($context, 'roles', $row['role_id'] ?? null),
            'server_id' => $this->nativeRelation($context, 'servers', $row['server_id'] ?? null),
            'description' => ImportedHtml::clean($row['description'] ?? ''),
            'price_minor' => $this->nativeInteger($row['price_minor']),
            'currency' => $this->nativeCurrency($row['currency']),
            'stock' => isset($row['stock']) ? $this->nativeInteger($row['stock']) : null,
            'position' => $this->nativeInteger($row['position'] ?? 0),
            'kind' => in_array($row['kind'] ?? 'product', ['product', 'giftcard'], true)
                ? $row['kind'] ?? 'product'
                : throw new RuntimeException('Unsupported imported shop product kind.'),
            'enabled' => false,
            'commands' => null,
            'refund_commands' => null,
            'billing_interval' => null,
            'download_path' => null,
            'wallet_credit_minor' => 0,
            'wallet_currency' => null,
            'variables' => '[]',
            'uses_codes' => false,
            'upgrade_group' => $group,
            'upgrade_weight' => $this->nativeInteger($row['upgrade_weight'] ?? 0),
            'translations' => $this->nativeTranslations($row['translations'] ?? null),
            'image_id' => null,
            'search_text' => Str::lower(Str::ascii($row['name'].' '.strip_tags($row['description'] ?? ''))),
            'created_at' => $row['created_at'] ?? now(),
            'updated_at' => $row['updated_at'] ?? now(),
        ];
        foreach (
            [
                'limit_per_user',
                'global_limit',
                'minimum_price_minor',
                'maximum_price_minor',
                'giftcard_days',
                'role_days',
            ] as $field
        ) {
            $data[$field] = isset($row[$field]) ? $this->nativeInteger($row[$field]) : null;
        }
        $data['custom_price'] = (bool) ($row['custom_price'] ?? false);
        $data['required_permission'] = $row['required_permission'] ?? null;
        $data['limit_period'] = $row['limit_period'] ?? null;
        $data['requires_online'] = (bool) ($row['requires_online'] ?? false);

        return $this->nativeInsert('shop__products', $context, $data);
    }

    private function nativeProductServer(array $row, ImportContext $context): array
    {
        $data = [
            'product_id' => $context->required('shop__products', $row['product_id']),
            'server_id' => $context->required('servers', $row['server_id']),
        ];
        $context->target->table('shop__product_servers')->insertOrIgnore($data);

        return ['target_table' => 'shop__product_servers', 'metadata' => $data];
    }

    private function nativeCoupon(array $row, ImportContext $context): array
    {
        $code = $row['code'];
        if ($context->target->table('shop__coupons')->where('code', $code)->exists()) {
            $code = 'IMPORT_'.strtoupper(substr(hash('sha256', $context->runId.':'.$row['id']), 0, 24));
        }

        return $this->nativeInsert('shop__coupons', $context, [
            'code' => $code,
            'type' => $row['type'],
            'amount' => $this->nativeInteger($row['amount']),
            'currency' => $this->nativeCurrency($row['currency']),
            'minimum_minor' => $this->nativeInteger($row['minimum_minor'] ?? 0),
            'max_uses' => $row['max_uses'] ?? null,
            'per_user_limit' => $row['per_user_limit'] ?? null,
            'period' => $row['period'] ?? null,
            'role_id' => $this->nativeRelation($context, 'roles', $row['role_id'] ?? null),
            'product_ids' => $this->nativeReferences($row['product_ids'] ?? null, 'shop__products', $context),
            'category_ids' => $this->nativeReferences($row['category_ids'] ?? null, 'shop__categories', $context),
            'starts_at' => $row['starts_at'] ?? null,
            'expires_at' => $row['expires_at'] ?? null,
            'enabled' => false,
            'issuer_id' => $this->nativeRelation($context, 'users', $row['issuer_id'] ?? null),
            'issuer_permissions' => '[]',
            'issuer_position' => -1,
        ]);
    }

    private function nativeGiftcard(array $row, ImportContext $context): array
    {
        return $this->nativeInsert('shop__giftcards', $context, [
            'code_hash' => hash('sha256', random_bytes(32)),
            'last_four' => $row['last_four'],
            'balance_minor' => $this->nativeInteger($row['balance_minor']),
            'currency' => $this->nativeCurrency($row['currency']),
            'owner_id' => $this->nativeRelation($context, 'users', $row['owner_id'] ?? null),
            'recipient_id' => $this->nativeRelation($context, 'users', $row['recipient_id'] ?? null),
            'issuer_id' => $this->nativeRelation($context, 'users', $row['issuer_id'] ?? null),
            'issuer_permissions' => '[]',
            'issuer_position' => -1,
            'claimed_at' => $row['claimed_at'] ?? null,
            'expires_at' => $row['expires_at'] ?? null,
            'revoked_at' => $row['revoked_at'] ?? null,
            'enabled' => false,
            'secret' => null,
            'claim_secret' => null,
            'claim_token_hash' => null,
            'source_item_id' => null,
            'created_at' => $row['created_at'] ?? now(),
        ]);
    }

    private function nativeOrder(array $row, ImportContext $context): array
    {
        if (!empty($row['guest_id'])) {
            throw new RuntimeException(
                'Guest shop orders require a dedicated identity import and cannot be converted to member orders. The source archive is preserved.',
            );
        }
        $status = $row['status'];
        if (in_array($status, ['pending', 'processing'], true)) {
            $status = 'expired';
        }
        if (!in_array($status, ['paid', 'refunded', 'charged_back', 'canceled', 'cancelled', 'expired'], true)) {
            throw new RuntimeException('Unsupported imported shop order status.');
        }
        $reference = $row['reference'];
        if (!Str::isUuid($reference)) {
            throw new RuntimeException('Invalid imported shop order reference.');
        }
        if ($context->target->table('shop__orders')->where('reference', $reference)->exists()) {
            $reference = (string) Str::uuid();
        }
        $data = [
            'reference' => $reference,
            'checkout_key' => (string) Str::uuid(),
            'user_id' => $context->required('users', $row['user_id'] ?? null),
            'status' => $status,
            'provider' => 'legacy',
            'provider_reference' => null,
            'checkout_url' => null,
            'payment_reference' => null,
            'payment_kind' => null,
            'payment_instructions' => null,
            'currency' => $this->nativeCurrency($row['currency']),
            'coupon_id' => $this->nativeRelation($context, 'shop__coupons', $row['coupon_id'] ?? null),
            'giftcard_id' => $this->nativeRelation($context, 'shop__giftcards', $row['giftcard_id'] ?? null),
            'expires_at' => $row['expires_at'] ?? ($row['created_at'] ?? now()),
            'paid_at' => $row['paid_at'] ?? null,
            'released_at' => $row['released_at'] ?? now(),
            'recurring' => false,
            'auto_expire' => false,
            'financial_hold' => false,
            'financial_review' => true,
            'pricing_hold' => true,
            'pricing_review_reason' => 'imported',
            'giftcard_hold' => false,
            'created_at' => $row['created_at'] ?? now(),
            'updated_at' => $row['updated_at'] ?? now(),
        ];
        foreach (
            [
                'subtotal_minor',
                'discount_minor',
                'giftcard_minor',
                'wallet_minor',
                'total_minor',
                'refunded_minor',
                'upgrade_credit_minor',
            ] as $field
        ) {
            $data[$field] = $this->nativeInteger($row[$field] ?? 0);
        }

        return $this->nativeInsert('shop__orders', $context, $data);
    }

    private function nativeItem(array $row, ImportContext $context): array
    {
        $data = [
            'order_id' => $context->required('shop__orders', $row['order_id']),
            'product_id' => $this->nativeRelation($context, 'shop__products', $row['product_id'] ?? null),
            'category_id' => $this->nativeRelation($context, 'shop__categories', $row['category_id'] ?? null),
            'recipient_id' => $this->nativeRelation($context, 'users', $row['recipient_id'] ?? null),
            'giftcard_recipient_id' => $this->nativeRelation($context, 'users', $row['giftcard_recipient_id'] ?? null),
            'role_id' => $this->nativeRelation($context, 'roles', $row['role_id'] ?? null),
            'server_id' => $this->nativeRelation($context, 'servers', $row['server_id'] ?? null),
            'name' => $row['name'],
            'game' => $row['game'] ?? null,
            'quantity' => $this->nativeInteger($row['quantity']),
            'commands' => null,
            'refund_commands' => null,
            'download_path' => null,
            'billing_interval' => null,
            'upgrade_group' => null,
            'wallet_currency' => isset($row['wallet_currency']) ? $this->nativeCurrency($row['wallet_currency']) : null,
            'kind' => $row['kind'] ?? 'product',
            'variables' => '[]',
        ];
        foreach (
            ['unit_minor', 'promotion_minor', 'upgrade_credit_minor', 'wallet_credit_minor'] as $field
        ) {
            $data[$field] = $this->nativeInteger($row[$field] ?? 0);
        }
        $data['paid_minor'] = isset($row['paid_minor']) ? $this->nativeInteger($row['paid_minor']) : null;

        return $this->nativeInsert('shop__order_items', $context, $data);
    }

    private function nativeFinancialEvent(array $row, ImportContext $context): array
    {
        return $this->nativeInsert('shop__financial_events', $context, [
            'order_id' => $context->required('shop__orders', $row['order_id']),
            'event_key' => 'import:'.$context->runId.':'.$row['id'],
            'type' => $row['type'],
            'status' => $row['status'],
            'reference' => 'import:'.$row['id'],
            'amount_minor' => $this->nativeInteger($row['amount_minor']),
            'currency' => $this->nativeCurrency($row['currency']),
            'created_at' => $row['created_at'] ?? now(),
        ]);
    }

    private function nativeDispute(array $row, ImportContext $context): array
    {
        return $this->nativeInsert('shop__disputes', $context, [
            'order_id' => $context->required('shop__orders', $row['order_id']),
            'provider' => 'legacy',
            'provider_reference' => 'import:'.$context->runId.':'.$row['id'],
            'payment_reference' => 'import:'.$context->runId.':order:'.$row['order_id'],
            'amount_minor' => $this->nativeInteger($row['amount_minor']),
            'currency' => $this->nativeCurrency($row['currency']),
            'status' => $row['status'],
            'created_at' => $row['created_at'] ?? now(),
            'updated_at' => $row['updated_at'] ?? now(),
        ]);
    }

    private function nativeInteger(mixed $value, int $maximum = 4294967295): int
    {
        if (
            (!is_int($value) && (!is_string($value) || !preg_match('/^\d{1,10}$/D', $value))) ||
            (int) $value < 0 ||
            (int) $value > $maximum
        ) {
            throw new RuntimeException('Imported shop amounts must be bounded non-negative integers.');
        }

        return (int) $value;
    }

    private function nativeCurrency(mixed $value): string
    {
        if (!is_string($value) || !preg_match('/^[A-Z]{3}$/D', $value)) {
            throw new RuntimeException('Invalid imported shop currency.');
        }

        return $value;
    }

    private function nativeRelation(ImportContext $context, string $table, int|string|null $id): ?int
    {
        return $id === null ? null : $context->required($table, $id);
    }

    private function nativeReferences(mixed $value, string $table, ImportContext $context): string
    {
        $ids = is_string($value) ? json_decode($value, true, flags: JSON_THROW_ON_ERROR) : $value ?? [];
        if (!is_array($ids) || !array_is_list($ids)) {
            throw new RuntimeException('Invalid imported shop scope.');
        }

        return json_encode(
            array_values(array_unique(array_map(fn ($id) => $context->required($table, $id), $ids))),
            JSON_THROW_ON_ERROR,
        );
    }

    private function nativeTranslations(mixed $value): string
    {
        $translations = is_string($value) ? json_decode($value, true, flags: JSON_THROW_ON_ERROR) : $value ?? [];
        if (!is_array($translations)) {
            throw new RuntimeException('Invalid imported shop translations.');
        }
        $clean = [];
        foreach ($translations as $locale => $entry) {
            if (!is_string($locale) || !is_array($entry)) {
                throw new RuntimeException('Invalid imported shop translation.');
            }
            $clean[$locale] = [
                'name' => $entry['name'] ?? '',
                'description' => ImportedHtml::clean($entry['description'] ?? ''),
            ];
        }

        return json_encode($clean, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }

    private function nativeInsert(string $table, ImportContext $context, array $data): array
    {
        return ['target_table' => $table, 'target_id' => $context->insert($table, $data)];
    }
}
