<?php

namespace KraftInk\Plugins\Shop\Http\Controllers;

use Symfony\Component\HttpKernel\Exception\HttpException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use KraftInk\Plugins\Shop\Contracts\Payments\GuestCheckout;
use KraftInk\Plugins\Shop\Models\Orders\Order;
use KraftInk\Plugins\Shop\Services\GuestAuth;
use KraftInk\Plugins\Shop\Services\Orders\OrderService;
use KraftInk\Plugins\Shop\Services\Payments\GatewayRegistry;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;

class GuestController
{
    public function cancel(): RedirectResponse
    {
        return redirect()->route('plugins.shop.public.cart.index');
    }

    public function index(Request $request, GuestAuth $auth): Response
    {
        $guest = $auth->guest($request->session()->get('shop.guest_token'));
        if (!($guest || $auth->enabled())) {
            abort(404);
        }
        if (!$request->session()->has('shop.guest_nonce')) {
            $request->session()->put('shop.guest_nonce', bin2hex(random_bytes(32)));
        }

        return response()
            ->view('shop::default.guests.access', [
                'guest' => $guest,
                'hasChallenge' => $request->session()->has('shop.guest_challenge'),
            ])
            ->header('Cache-Control', 'private, no-store');
    }

    public function start(Request $request, GuestAuth $auth): RedirectResponse
    {
        $data = $request->validate(['email' => 'required|email:rfc|max:254']);
        $nonce = $request->session()->get('shop.guest_nonce') ?: bin2hex(random_bytes(32));
        $request->session()->put('shop.guest_nonce', $nonce);
        $id = $auth->start($data['email'], app()->getLocale(), $nonce);
        $request->session()->put('shop.guest_challenge', $id);

        return redirect()
            ->route('plugins.shop.public.guests.index')
            ->with('status', __('shop::messages.guests.code_sent'));
    }

    public function verify(Request $request, GuestAuth $auth): RedirectResponse
    {
        $data = $request->validate(['code' => ['required', 'regex:/^[0-9]{8}$/D']]);
        $token = $auth->verify(
            $request->session()->get('shop.guest_challenge', ''),
            $data['code'],
            $request->session()->get('shop.guest_nonce', ''),
        );
        if ($old = $request->session()->get('shop.guest_token')) {
            $auth->revoke($old);
        }
        $request->session()->regenerate();
        $request->session()->put('shop.guest_token', $token);
        $request->session()->forget('shop.guest_challenge');

        return redirect()->route(
            $request->session()->get('shop.cart', [])
                ? 'plugins.shop.public.cart.index'
                : 'plugins.shop.public.guests.orders.index',
        );
    }

    public function logout(Request $request, GuestAuth $auth): RedirectResponse
    {
        if ($token = $request->session()->get('shop.guest_token')) {
            $auth->revoke($token);
        }
        $request->session()->forget(['shop.guest_token', 'shop.guest_challenge', 'shop.guest_nonce']);
        $request->session()->regenerate();

        return redirect()->route('plugins.shop.public.cart.index');
    }

    public function checkout(
        Request $request,
        GuestAuth $auth,
        OrderService $orders,
        GatewayRegistry $gateways,
    ): RedirectResponse {
        $guest = $auth->guest($request->session()->get('shop.guest_token'));
        if (!($guest && $auth->enabled() && !$request->user())) {
            throw new HttpException(403);
        }
        $available = collect($gateways->available())
            ->filter(fn ($gateway) => $gateway instanceof GuestCheckout)
            ->keys()
            ->all();
        $data = $request->validate([
            'provider' => ['required', Rule::in($available)],
            'checkout_key' => ['required', 'uuid'],
            'coupon' => ['nullable', 'string', 'max:60'],
            'terms' => ['accepted'],
            'targets' => ['nullable', 'array', 'max:50'],
            'targets.*' => ['array:player_name,player_uuid'],
            'targets.*.player_name' => ['nullable', 'string', 'max:60'],
            'targets.*.player_uuid' => ['nullable', 'string', 'max:64'],
            'giftcard' => ['prohibited'],
            'wallet_amount' => ['prohibited'],
        ]);
        $options = $request->session()->get('shop.options', []);
        foreach ($data['targets'] ?? [] as $id => $target) {
            $options[$id]['guest_target'] = $target;
        }
        $order = $orders->create(
            null,
            $request->session()->get('shop.cart', []),
            $data['provider'],
            $data['checkout_key'],
            $data['coupon'] ?? null,
            null,
            $options,
            (array) $request->input('billing', []),
            0,
            $guest,
        );
        if ($order->total_minor === 0) {
            $orders->complete($order->id, $order->provider, 'zero-'.$order->reference, 0, $order->currency);
            $url = route('plugins.shop.public.guests.orders.show', $order->reference);
        } else {
            if (!$order->isPayable()) {
                throw new HttpException(422);
            }
            $url = $gateways->guestCheckout($order, $guest);
        }
        $request->session()->forget(['shop.cart', 'shop.options']);

        return redirect()->away($url);
    }

    public function orders(Request $request, GuestAuth $auth): Response
    {
        $guest = $auth->guest($request->session()->get('shop.guest_token'));
        if ($guest === null) {
            abort(404);
        }

        return response()
            ->view('shop::default.orders.index', [
                'orders' => Order::query()
                    ->where('guest_id', $guest->id)
                    ->whereNull('user_id')
                    ->orderByDesc('created_at')
                    ->paginate(25),
                'subscriptions' => collect(),
                'guest' => $guest,
            ])
            ->header('Cache-Control', 'private, no-store');
    }
}
