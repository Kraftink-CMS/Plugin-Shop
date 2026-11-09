<?php

namespace KraftInk\Plugins\Shop\Services\Commerce;

use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Kraft\LocalePolicy;
use App\Kraft\Settings;
use App\Kraft\NavigationTargets;
use App\Models\Accounts\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Traits\Localizable;
use KraftInk\Plugins\Shop\Models\Billing\InvoiceSequence;
use KraftInk\Plugins\Shop\Models\Billing\Receipt;
use KraftInk\Plugins\Shop\Models\Billing\ReceiptSequence;
use KraftInk\Plugins\Shop\Models\Billing\Refund;
use KraftInk\Plugins\Shop\Models\Orders\Order;
use KraftInk\Plugins\Shop\Models\Orders\OrderItem;
use KraftInk\Plugins\Shop\Models\Orders\Subscription;
use Locale;

class ReceiptService
{
    use Localizable;

    public function termsUrl(): ?string
    {
        $url = app(Settings::class)->get('shop.commerce.terms_url');

        return is_string($url) &&
            in_array(strtolower(parse_url($url, PHP_URL_SCHEME) ?? ''), ['http', 'https'], true) &&
            NavigationTargets::validUrl($url)
            ? $url
            : null;
    }

    public function configuration(?User $user, ?string $guestLocale = null): array
    {
        $settings = app(Settings::class);
        $locales = app(LocalePolicy::class);
        $locale =
            $locales->allowsChoice() && $locales->valid($user?->locale ?? $guestLocale)
                ? $user?->locale ?? $guestLocale
                : $locales->defaultLocale();

        return [
            'seller_name' => $settings->get('shop.commerce.seller_name') ?: $settings->get('site.name', config('app.name')),
            'seller_address' => $settings->get('shop.commerce.seller_address', ''),
            'seller_tax_id' => $settings->get('shop.commerce.seller_tax_id', ''),
            'number_prefix' => $settings->get('shop.commerce.number_prefix', 'KI'),
            'tax_label' => $settings->get('shop.commerce.tax_label') ?: __('shop::commerce.documents.tax', [], $locale),
            'rate_bps' => (int) $settings->get('shop.commerce.rate_bps', 0),
            'locale' => $locale,
            'configured_at' => now()->toIso8601String(),
            'seller_type' => $settings->get('shop.commerce.seller_type', 'individual'),
            'seller_country' => $settings->get('shop.commerce.seller_country', ''),
            'seller_siren' => $settings->get('shop.commerce.seller_siren', ''),
            'seller_siret' => $settings->get('shop.commerce.seller_siret', ''),
            'seller_legal_form' => $settings->get('shop.commerce.seller_legal_form', ''),
            'seller_capital' => $settings->get('shop.commerce.seller_capital', ''),
            'seller_registration' => $settings->get('shop.commerce.seller_registration', ''),
            'seller_email' => $settings->get('shop.commerce.seller_email', ''),
            'tax_mode' => $settings->get('shop.commerce.tax_mode', 'none'),
            'tax_notice' => $settings->get('shop.commerce.tax_notice', ''),
            'legal_notice' => $settings->get('shop.commerce.legal_notice', ''),
            'terms_url' => $this->termsUrl(),
            'payment_terms' => $settings->get('shop.commerce.payment_terms', ''),
        ];
    }

    public function preview(User $user, array $values): string
    {
        $defaults = $this->configuration($user);
        $configuration = array_replace($defaults, array_map(fn ($value) => $value ?? '', $values));
        $configuration['seller_name'] = $configuration['seller_name'] ?: $defaults['seller_name'];
        $configuration['tax_label'] = $configuration['tax_label'] ?: $defaults['tax_label'];
        $gross = 1200;
        $tax = $this->roundedRatio($gross, (int) $configuration['rate_bps'], 10000 + (int) $configuration['rate_bps']);
        $net = $gross - $tax;
        $date = now()->utc()->toIso8601String();
        $unitNet = $this->roundedRatio($gross, 100000000, 10000 + (int) $configuration['rate_bps']);
        $receipt = $configuration + [
            'preview' => true,
            'document_type' => $configuration['seller_type'] === 'business' ? 'invoice' : 'payment',
            'number' => $configuration['number_prefix'].
                '-'.
                ($configuration['seller_type'] === 'business' ? 'INV-' : '').
                now()->utc()->year.
                '-000001',
            'kind' => 'payment',
            'currency' => 'EUR',
            'gross_minor' => $gross,
            'tax_minor' => $tax,
            'net_minor' => $net,
            'subtotal_minor' => $gross,
            'discount_minor' => 0,
            'upgrade_credit_minor' => 0,
            'giftcard_minor' => 0,
            'paid_minor' => $gross,
            'provider_minor' => $gross,
            'wallet_minor' => 0,
            'order_reference' => 'DEMO-000001',
            'account_reference' => '1001',
            'issued_at' => $date,
            'paid_at' => $date,
            'legacy' => false,
            'review' => false,
            'billing' => [
                'name' => __('shop::commerce.documents.preview_customer', [], $configuration['locale']),
                'address' => __('shop::commerce.documents.preview_address', [], $configuration['locale']),
                'country' => 'FR',
            ],
            'items' => [
                [
                    'name' => __('shop::commerce.documents.preview_product', [], $configuration['locale']),
                    'quantity' => 1,
                    'unit_minor' => $gross,
                    'unit_net_amount' => intdiv($unitNet, 1000000).'.'.str_pad((string) ($unitNet % 1000000), 6, '0', STR_PAD_LEFT),
                    'unit_net_minor' => $net,
                    'net_minor' => $net,
                    'tax_minor' => $tax,
                    'gross_minor' => $gross,
                ],
            ],
        ];

        return $this->withLocale(
            $configuration['locale'],
            fn () => view('shop::documents.receipts.show', ['receipt' => $receipt])->render(),
        );
    }

    public static function countryName(string $code, string $locale): string
    {
        $code = strtoupper(trim($code));

        return class_exists(Locale::class) && preg_match('/^[A-Z]{2}$/D', $code)
            ? (Locale::getDisplayRegion('und_'.$code, $locale) ?:
                $code)
            : $code;
    }

    public function payment(int $orderId): object
    {
        return DB::transaction(function () use ($orderId) {
            $order = Order::query()->where('id', $orderId)->lockForUpdate()->first();
            if (!($order && $order->paid_at)) {
                throw new HttpException(422, __('shop::commerce.documents.not_paid'));
            }
            $existing = Receipt::query()
                ->where('source_key', 'payment:'.$orderId)
                ->first();
            if ($existing) {
                return $existing;
            }
            $configuration = $order->commercial_snapshot
                ? $order->commercial_snapshot
                : $this->configuration($order->user_id ? User::query()->findOrFail($order->user_id) : null);
            $gross =
                $configuration['payment_review']['received_minor'] ??
                (int) $order->subtotal_minor - (int) $order->discount_minor - (int) $order->upgrade_credit_minor;
            $tax = $this->roundedRatio(
                $gross,
                (int) $configuration['rate_bps'],
                10000 + (int) $configuration['rate_bps'],
            );
            $snapshot = $configuration + [
                'order_reference' => $order->reference,
                'account_reference' => $order->user_id ?? 'guest-'.$order->guest_id,
                'subtotal_minor' => (int) $order->subtotal_minor,
                'discount_minor' => (int) $order->discount_minor,
                'upgrade_credit_minor' => (int) $order->upgrade_credit_minor,
                'giftcard_minor' => (int) $order->giftcard_minor,
                'wallet_minor' => (int) ($order->wallet_minor ?? 0),
                'provider_minor' => (int) $order->total_minor,
                'paid_minor' => (int) $order->total_minor + (int) ($order->wallet_minor ?? 0),
                'paid_at' => $order->paid_at,
                'legacy' => !$order->commercial_snapshot,
                'review' => $order->pricing_hold || $order->giftcard_hold || $order->financial_hold,
                'document_type' => ($configuration['seller_type'] ?? '') === 'business' &&
                    !empty($configuration['billing']['name']) &&
                    !empty($configuration['billing']['address']) &&
                    !empty($configuration['billing']['country']) &&
                    empty($configuration['payment_review'])
                        ? 'invoice'
                        : 'payment',
                'provider' => $order->provider,
                'mail_category' => $order->recurring
                    ? (Subscription::query()->where('order_id', $orderId)->exists()
                        ? 'subscription_initial'
                        : 'subscription_renewal')
                    : 'purchase',
                'items' => OrderItem::query()
                    ->where('order_id', $orderId)
                    ->select('name', 'quantity', 'unit_minor', 'paid_minor', 'promotion_minor', 'upgrade_credit_minor')
                    ->orderBy('id')
                    ->get()
                    ->map(fn ($item) => $item->getAttributes())
                    ->all(),
            ];
            $cumulativeGross = 0;
            $cumulativeTax = 0;
            foreach ($snapshot['items'] as &$item) {
                $item['gross_minor'] = (int) ($item['paid_minor'] ?? $item['unit_minor'] * $item['quantity']);
                $cumulativeGross += $item['gross_minor'];
                $nextTax = $gross > 0 ? $this->roundedRatio($tax, min($cumulativeGross, $gross), $gross) : 0;
                $item['tax_minor'] = $nextTax - $cumulativeTax;
                $item['net_minor'] = $item['gross_minor'] - $item['tax_minor'];
                $item['unit_net_minor'] = $this->roundedRatio(
                    (int) $item['unit_minor'],
                    10000,
                    10000 + (int) $configuration['rate_bps'],
                );
                $unitNet = $this->roundedRatio(
                    (int) $item['unit_minor'],
                    100000000,
                    10000 + (int) $configuration['rate_bps'],
                );
                $item['unit_net_amount'] =
                    intdiv($unitNet, 1000000).'.'.str_pad((string) ($unitNet % 1000000), 6, '0', STR_PAD_LEFT);
                $cumulativeTax = $nextTax;
            }
            unset($item);
            if ($snapshot['document_type'] === 'invoice' && $cumulativeGross !== $gross) {
                $snapshot['document_type'] = 'payment';
                $snapshot['review'] = true;
            }

            return $this->issue($orderId, 'payment:'.$orderId, 'payment', $order->currency, $gross, $tax, $snapshot);
        }, 3);
    }

    public function refund(string $refundId): object
    {
        return DB::transaction(function () use ($refundId) {
            $refund = Refund::query()->find($refundId);
            if (!($refund && $refund->status === 'succeeded')) {
                throw new HttpException(422);
            }
            $parent = $this->payment((int) $refund->order_id);
            $existing = Receipt::query()
                ->where('source_key', 'refund:'.$refundId)
                ->first();
            if ($existing) {
                return $existing;
            }
            $previous = Receipt::query()
                ->where('parent_id', $parent->id)
                ->where('kind', 'refund')
                ->selectRaw('COALESCE(SUM(gross_minor), 0) AS gross, COALESCE(SUM(tax_minor), 0) AS tax')
                ->toBase()
                ->first();
            $gross = (int) $refund->amount_minor;
            if (!($gross + $previous->gross <= $parent->gross_minor)) {
                throw new HttpException(409);
            }
            $cumulativeTax = $parent->gross_minor
                ? $this->roundedRatio(
                    (int) $parent->tax_minor,
                    $gross + (int) $previous->gross,
                    (int) $parent->gross_minor,
                )
                : 0;
            $snapshot = $parent->snapshot ?? [];
            $snapshot['parent_number'] = $parent->number;
            $snapshot['refund_reference'] = $refundId;
            $snapshot['refund_reason'] = $refund->reason;
            $snapshot['external_minor'] = (int) $refund->external_minor;
            $snapshot['giftcard_minor'] = (int) $refund->giftcard_minor;
            $snapshot['wallet_minor'] = (int) ($refund->wallet_minor ?? 0);
            $snapshot['document_type'] = ($snapshot['document_type'] ?? '') === 'invoice' ? 'credit_note' : 'refund';
            $snapshot['mail_category'] = 'refund';

            return $this->issue(
                (int) $refund->order_id,
                'refund:'.$refundId,
                'refund',
                $parent->currency,
                $gross,
                max(0, $cumulativeTax - (int) $previous->tax),
                $snapshot,
                (int) $parent->id,
            );
        }, 3);
    }

    public static function money(int $minor, string $currency): string
    {
        return intdiv($minor, 100).'.'.str_pad((string) ($minor % 100), 2, '0', STR_PAD_LEFT).' '.$currency;
    }

    private function issue(
        int $orderId,
        string $key,
        string $kind,
        string $currency,
        int $gross,
        int $tax,
        array $snapshot,
        ?int $parent = null,
    ): object {
        if (
            !(
                $gross >= 0 &&
                $gross <= 99999999 &&
                $tax >= 0 &&
                $tax <= $gross &&
                $snapshot['rate_bps'] >= 0 &&
                $snapshot['rate_bps'] <= 10000
            )
        ) {
            throw new HttpException(422);
        }
        $year = (int) now()->utc()->year;
        $invoice = in_array($snapshot['document_type'] ?? '', ['invoice', 'credit_note'], true);
        $sequence = ($invoice ? InvoiceSequence::class : ReceiptSequence::class)::reserveNumber(
            $year,
            $snapshot['number_prefix'],
        );
        $number =
            $sequence['prefix'].
            ($invoice ? '-INV' : '').
            '-'.
            $year.
            '-'.
            str_pad((string) $sequence['number'], 6, '0', STR_PAD_LEFT);
        $snapshot = array_replace($snapshot, [
            'number' => $number,
            'kind' => $kind,
            'currency' => $currency,
            'gross_minor' => $gross,
            'tax_minor' => $tax,
            'net_minor' => $gross - $tax,
            'issued_at' => now()->utc()->toIso8601String(),
        ]);
        $body = $this->withLocale(
            $snapshot['locale'],
            fn () => view('shop::documents.receipts.show', ['receipt' => $snapshot])->render(),
        );
        $id = Receipt::query()->insertGetId([
            'order_id' => $orderId,
            'parent_id' => $parent,
            'source_key' => $key,
            'number' => $number,
            'kind' => $kind,
            'currency' => $currency,
            'gross_minor' => $gross,
            'tax_minor' => $tax,
            'net_minor' => $gross - $tax,
            'rate_bps' => $snapshot['rate_bps'],
            'snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
            'body' => $body,
            'body_hash' => hash('sha256', $body),
            'issued_at' => now(),
        ]);

        $receipt = Receipt::query()->find($id);
        app(DocumentMessages::class)->enqueue($receipt);

        return $receipt;
    }

    private function roundedRatio(int $amount, int $numerator, int $denominator): int
    {
        return intdiv($amount * $numerator + intdiv($denominator, 2), $denominator);
    }
}
