<?php

namespace KraftInk\Plugins\Shop\Services\Payments\Gateways;

use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Kraft\Currencies\Currency;
use App\Models\Accounts\User;
use KraftInk\Plugins\Shop\Contracts\Payments\DefaultAvailability;
use KraftInk\Plugins\Shop\Contracts\Payments\PaymentGateway;
use KraftInk\Plugins\Shop\Contracts\Payments\ProvidesInstructions;
use KraftInk\Plugins\Shop\Contracts\Payments\RefundsPayments;
use KraftInk\Plugins\Shop\Contracts\Payments\ReservationPolicy;
use KraftInk\Plugins\Shop\Contracts\Payments\SupportsCurrencies;
use KraftInk\Plugins\Shop\Data\Payments\CheckoutSession;
use KraftInk\Plugins\Shop\Data\Payments\PaymentReference;
use KraftInk\Plugins\Shop\Data\Payments\RefundResult;
use KraftInk\Plugins\Shop\Models\Billing\ManualPayment;
use KraftInk\Plugins\Shop\Models\Billing\ManualRefund;
use KraftInk\Plugins\Shop\Models\Billing\Refund;
use KraftInk\Plugins\Shop\Models\Orders\Order;
use KraftInk\Plugins\Shop\Services\Payments\GatewayRegistry;

class ManualGateway implements DefaultAvailability, PaymentGateway, ProvidesInstructions, RefundsPayments, ReservationPolicy, SupportsCurrencies
{
    public function id(): string
    {
        return 'manual';
    }

    public function name(): string
    {
        return __('shop::commerce.payments.manual.name');
    }

    public function capabilities(): array
    {
        return ['one_time', 'manual_confirmation'];
    }

    public function defaultEnabled(): bool
    {
        return false;
    }

    public function supportsCurrency(Currency $currency): bool
    {
        return $currency->decimals === 2;
    }

    public function instructions(): string
    {
        return app(GatewayRegistry::class)->setting('manual', 'instructions');
    }

    public function configurationFields(): array
    {
        return [
            'instructions' => [
                'label' => 'shop::commerce.payments.manual.instructions',
                'type' => 'textarea',
                'rules' => ['nullable', 'string', 'max:1024'],
            ],
            'reservation_hours' => [
                'label' => 'shop::commerce.payments.manual.reservation_hours',
                'type' => 'number',
                'rules' => ['nullable', 'integer', 'min:1', 'max:168'],
                'default' => '72',
            ],
        ];
    }

    public function configured(): bool
    {
        return trim(app(GatewayRegistry::class)->setting('manual', 'instructions')) !== '';
    }

    public function reservationSeconds(): int
    {
        return max(1, min(168, (int) app(GatewayRegistry::class)->setting('manual', 'reservation_hours', '72'))) * 3600;
    }

    public function createCheckout(object $order, User $user): CheckoutSession
    {
        if (!($order->user_id === $user->id && !$order->recurring)) {
            throw new HttpException(422);
        }

        return new CheckoutSession(
            'manual:'.$order->reference,
            route('plugins.shop.public.orders.show', $order->reference),
        );
    }

    public function payment(object $order): PaymentReference
    {
        $payment = ManualPayment::query()->where('order_id', $order->id)->first();
        if (!($payment && $payment->amount_minor === $order->total_minor && $payment->currency === $order->currency)) {
            throw new HttpException(422, __('shop::commerce.payments.manual.unconfirmed'));
        }

        return new PaymentReference('manual:'.$payment->id, 'manual', $payment->amount_minor, $payment->currency);
    }

    public function refund(PaymentReference $payment, object $refund): RefundResult
    {
        if ($payment->kind !== 'manual') {
            throw new HttpException(422);
        }

        return $this->getRefund('manual:refund:'.$refund->id);
    }

    public function getRefund(string $reference): RefundResult
    {
        if (!preg_match('/^manual:refund:([a-f0-9-]{36})$/D', $reference, $match)) {
            throw new HttpException(422);
        }
        $refund = Refund::query()->where('provider', 'manual')->find($match[1]);
        if ($refund === null) {
            abort(404);
        }
        $order = Order::query()->find($refund->order_id);
        $payment = $this->payment($order);
        $confirmed = ManualRefund::query()->where('refund_id', $refund->id)->first();
        if ($confirmed && ($confirmed->order_id !== $order->id || $confirmed->amount_minor !== $refund->external_minor || $confirmed->currency !== $refund->currency)) {
            throw new HttpException(422, __('shop::commerce.payments.manual.mismatch'));
        }

        return new RefundResult(
            $reference,
            $payment->id,
            $refund->external_minor,
            $refund->currency,
            $confirmed ? 'succeeded' : 'pending',
            $refund->id,
        );
    }
}
