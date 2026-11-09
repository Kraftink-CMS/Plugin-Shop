<?php

namespace KraftInk\Plugins\Shop\Http\Controllers\Admin;

use App\Kraft\Audit;
use App\Kraft\Currencies\CurrencyRegistry;
use App\Kraft\Settings;
use App\Kraft\Wallet\Money;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;

class StorefrontController
{
    public function index(): View
    {
        $settings = app(Settings::class);

        return view('shop::admin.storefront.index', [
            'preferences' => [
                'guests_enabled' => $settings->get('shop.guests.enabled', false),
                'goal_enabled' => $settings->get('shop.storefront.goal_enabled', false),
                'goal_title' => $settings->get('shop.storefront.goal_title', ''),
                'goal_amount' => Money::decimal((int) $settings->get('shop.storefront.goal_minor', 0)),
                'goal_currency' => $settings->get('shop.storefront.goal_currency', 'EUR'),
                'recent_enabled' => $settings->get('shop.storefront.recent_enabled', false),
                'recent_limit' => $settings->get('shop.storefront.recent_limit', 5),
                'recent_amounts' => $settings->get('shop.storefront.recent_amounts', false),
            ],
            'currencies' => app(CurrencyRegistry::class)->codes(),
            'mailEnabled' => $settings->get('mail.enabled', false),
        ]);
    }

    public function save(Request $request): RedirectResponse
    {
        $validator = Validator::make($request->all(), [
            'guests_enabled' => ['required', 'boolean'],
            'goal_enabled' => ['required', 'boolean'],
            'goal_title' => ['nullable', 'string', 'max:100'],
            'goal_amount' => ['required', 'string', 'max:16'],
            'goal_currency' => ['required', Rule::in(app(CurrencyRegistry::class)->codes())],
            'recent_enabled' => ['required', 'boolean'],
            'recent_limit' => ['required', 'integer', 'min:1', 'max:20'],
            'recent_amounts' => ['required', 'boolean'],
        ]);
        $amount = 0;
        $validator->after(function ($validator) use ($request, &$amount) {
            if ($validator->errors()->has('goal_amount')) {
                return;
            }
            try {
                $amount = Money::parse($request->input('goal_amount'));
                if ($amount > 99999999 || ($request->boolean('goal_enabled') && $amount === 0)) {
                    $validator->errors()->add('goal_amount', __('site.account.wallet.invalid_amount'));
                }
            } catch (ValidationException) {
                $validator->errors()->add('goal_amount', __('site.account.wallet.invalid_amount'));
            }
        });
        $data = $validator->validate();
        DB::transaction(function () use ($request, $data, $amount) {
            $settings = app(Settings::class);
            $settings->set('shop.guests.enabled', $request->boolean('guests_enabled'));
            foreach (['goal_enabled', 'recent_enabled', 'recent_amounts'] as $key) {
                $settings->set('shop.storefront.'.$key, $request->boolean($key));
            }
            $settings->set('shop.storefront.goal_title', $data['goal_title'] ?? '');
            $settings->set('shop.storefront.goal_minor', $amount);
            $settings->set('shop.storefront.goal_currency', $data['goal_currency']);
            $settings->set('shop.storefront.recent_limit', (int) $data['recent_limit']);
            Audit::record('shop.storefront.updated');
        });

        return back()->with('status', __('shop::messages.feedback.saved'));
    }
}
