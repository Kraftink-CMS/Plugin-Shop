<?php

namespace KraftInk\Plugins\Shop\Services\Payments\Gateways;

use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Kraft\Currencies\Currency;
use App\Kraft\Wallet\Wallet;
use App\Models\Accounts\User;
use App\Models\Wallet\WalletTransaction;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use KraftInk\Plugins\Shop\Contracts\Payments\PaymentGateway;
use KraftInk\Plugins\Shop\Contracts\Payments\RefundsPayments;
use KraftInk\Plugins\Shop\Contracts\Payments\SupportsCurrencies;
use KraftInk\Plugins\Shop\Contracts\Payments\SupportsOrders;
use KraftInk\Plugins\Shop\Contracts\Payments\SupportsProducts;
use KraftInk\Plugins\Shop\Data\Payments\CheckoutSession;
use KraftInk\Plugins\Shop\Data\Payments\PaymentReference;
use KraftInk\Plugins\Shop\Data\Payments\RefundResult;
use KraftInk\Plugins\Shop\Models\Orders\Order;
use KraftInk\Plugins\Shop\Models\Orders\OrderItem;
use KraftInk\Plugins\Shop\Services\Orders\OrderService;

class WalletGateway implements PaymentGateway, RefundsPayments, SupportsCurrencies, SupportsOrders, SupportsProducts
{
    public function id(): string
    {
        return 'wallet';
    }

    public function name(): string
    {
        return __('shop::commerce.wallet.payment_name');
    }

    public function capabilities(): array
    {
        return ['one_time', 'internal_balance', 'idempotent_local_refunds'];
    }

    public function configurationFields(): array
    {
        return [];
    }

    public function configured(): bool
    {
        return true;
    }

    public function supportsCurrency(Currency $currency): bool
    {
        return $currency->decimals === 2 && $currency->code === app(Wallet::class)->currency();
    }

    public function supports(object $order): bool
    {
        return $order->currency === app(Wallet::class)->currency() &&
            !$order->recurring &&
            !OrderItem::query()->where('order_id', $order->id)->where('wallet_credit_minor', '>', 0)->exists();
    }

    public function supportsProducts(Collection $products): bool
    {
        return $products->every(
            fn ($product) => $product->currency === app(Wallet::class)->currency() &&
                !$product->billing_interval &&
                !$product->wallet_credit_minor,
        );
    }

    public function createCheckout(object $order, User $user): CheckoutSession
    {
        if (!($order->user_id === $user->id && $this->supports($order))) {
            throw new HttpException(422, __('shop::commerce.wallet.incompatible'));
        }

        return DB::transaction(function () use ($order, $user) {
            $transaction = app(Wallet::class)->debit(
                $user,
                $order->total_minor,
                'shop:payment:'.$order->reference,
                'shop.payment',
                ['order_id' => $order->id],
            );
            app(OrderService::class)->complete(
                $order->id,
                'wallet',
                $transaction->id,
                $order->total_minor,
                $order->currency,
                null,
                $transaction->id,
                'wallet',
            );

            return new CheckoutSession(
                'wallet-'.$order->reference,
                route('plugins.shop.public.orders.show', $order->reference),
            );
        }, 3);
    }

    public function payment(object $order): PaymentReference
    {
        $transaction = WalletTransaction::query()
            ->where('operation_key', 'shop:payment:'.$order->reference)
            ->where('kind', 'debit')
            ->whereHas(
                'entries',
                fn ($query) => $query->where('user_id', $order->user_id)->where('amount_minor', -$order->total_minor),
            )
            ->first();
        if (!($transaction && $transaction->currency === app(Wallet::class)->currency())) {
            throw new HttpException(422, __('shop::commerce.wallet.incompatible'));
        }

        return new PaymentReference($transaction->id, 'wallet', $transaction->amount_minor, $transaction->currency);
    }

    public function refund(PaymentReference $payment, object $refund): RefundResult
    {
        $order = Order::query()->find($refund->order_id);
        if ($refund->currency !== app(Wallet::class)->currency()) {
            throw new HttpException(422, __('shop::commerce.wallet.incompatible'));
        }
        $transaction = app(Wallet::class)->credit(
            $order->user_id,
            $refund->external_minor,
            'shop:refund:'.$refund->id,
            'shop.refund',
            ['order_id' => $order->id, 'refund_id' => $refund->id],
        );

        return new RefundResult(
            $transaction->id,
            $payment->id,
            $transaction->amount_minor,
            $transaction->currency,
            'succeeded',
            $refund->id,
        );
    }

    public function getRefund(string $reference): RefundResult
    {
        $transaction = WalletTransaction::query()->whereKey($reference)->where('kind', 'credit')->firstOrFail();
        $refundId = $transaction->context['refund_id'] ?? null;
        if (!($refundId && $transaction->operation_key === 'shop:refund:'.$refundId)) {
            throw new HttpException(422);
        }
        $order = Order::query()->find($transaction->context['order_id']);
        if (!($order && $transaction->entries()->where('user_id', $order->user_id)->where('amount_minor', $transaction->amount_minor)->exists())) {
            throw new HttpException(422);
        }

        return new RefundResult(
            $transaction->id,
            $order->payment_reference,
            $transaction->amount_minor,
            $transaction->currency,
            'succeeded',
            $refundId,
        );
    }
}
