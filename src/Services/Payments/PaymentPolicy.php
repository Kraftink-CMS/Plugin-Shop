<?php

namespace KraftInk\Plugins\Shop\Services\Payments;

use App\Kraft\Currencies\CurrencyRegistry;
use App\Kraft\Wallet\Money;
use Illuminate\Validation\ValidationException;

class PaymentPolicy
{
    public function __construct(private GatewayRegistry $gateways, private CurrencyRegistry $currencies)
    {
    }

    public function canMixWallet(string $provider): bool
    {
        $capabilities = $this->gateways->get($provider)->capabilities();

        return in_array('one_time', $capabilities, true) &&
            !array_intersect(['internal_balance', 'zero_total'], $capabilities);
    }

    public function allowsWallet(string $provider): bool
    {
        return $this->canMixWallet($provider) &&
            $this->gateways->setting($provider, 'policy_allow_wallet', '0') === '1';
    }

    public function allowsGiftcard(string $provider): bool
    {
        return in_array('zero_total', $this->gateways->get($provider)->capabilities(), true) ||
            $this->gateways->setting($provider, 'policy_allow_giftcard', '1') === '1';
    }

    public function limits(string $provider, string $currency): array
    {
        $limits = [];
        foreach (['minimum', 'maximum'] as $field) {
            $value = $this->gateways->setting($provider, 'policy_'.$field.'_'.$currency);
            $limits[$field] = $value === '' ? null : (int) $value;
        }

        return $limits;
    }

    public function assertCheckout(
        string $provider,
        string $currency,
        int $providerAmount,
        int $walletAmount,
        int $giftcardAmount,
    ): void {
        if ($providerAmount < 0 || $giftcardAmount < 0) {
            throw ValidationException::withMessages([
                'provider' => __('shop::commerce.payments.policy.invalid_amount'),
            ]);
        }
        if ($providerAmount > 0 && in_array('zero_total', $this->gateways->get($provider)->capabilities(), true)) {
            throw ValidationException::withMessages([
                'provider' => __('shop::messages.errors.payment_method_required'),
            ]);
        }
        if ($walletAmount < 0 || ($walletAmount > 0 && !$this->allowsWallet($provider))) {
            throw ValidationException::withMessages([
                'wallet_amount' => __('shop::commerce.payments.policy.wallet_disabled'),
            ]);
        }
        if (
            $giftcardAmount > 0 &&
            !$this->allowsGiftcard($provider) &&
            !(
                $providerAmount === 0 &&
                $walletAmount === 0 &&
                isset($this->gateways->available()['giftcard']) &&
                $this->gateways->supportsCurrency('giftcard', $currency)
            )
        ) {
            throw ValidationException::withMessages([
                'giftcard' => __('shop::commerce.payments.policy.giftcard_disabled'),
            ]);
        }
        if ($providerAmount === 0) {
            return;
        }
        $limits = $this->limits($provider, $currency);
        foreach (['minimum', 'maximum'] as $field) {
            $limit = $limits[$field];
            if ($limit !== null && ($field === 'minimum' ? $providerAmount < $limit : $providerAmount > $limit)) {
                throw ValidationException::withMessages([
                    'provider' => __('shop::commerce.payments.policy.'.$field.'_error', [
                        'provider' => $this->gateways->get($provider)->name(),
                        'amount' => Money::format($limit),
                        'currency' => $currency,
                    ]),
                ]);
            }
        }
    }

    public function configuration(): array
    {
        $result = [];
        foreach ($this->gateways->all() as $id => $gateway) {
            $zero = in_array('zero_total', $gateway->capabilities(), true);
            $codes = array_values(
                array_filter($this->currencies->codes(), fn ($code) => $this->gateways->supportsCurrency($id, $code)),
            );
            $limits = [];
            foreach ($codes as $code) {
                $limits[$code] = array_map(
                    fn ($value) => $value === null ? '' : Money::decimal($value),
                    $this->limits($id, $code),
                );
            }
            $result[$id] = [
                'currencies' => $zero ? [] : $codes,
                'limits' => $limits,
                'can_mix_wallet' => $this->canMixWallet($id),
                'can_use_giftcard' => !$zero,
                'allow_wallet' => $this->allowsWallet($id),
                'allow_giftcard' => $this->allowsGiftcard($id),
            ];
        }

        return $result;
    }

    public function rules(): array
    {
        $rules = [];
        foreach ($this->configuration() as $id => $policy) {
            $prefix = 'gateways.'.$id.'.policy';
            $rules[$prefix] = ['sometimes', 'array'];
            if ($policy['can_mix_wallet']) {
                $rules[$prefix.'.allow_wallet'] = ['sometimes', 'boolean'];
            }
            if ($policy['can_use_giftcard']) {
                $rules[$prefix.'.allow_giftcard'] = ['sometimes', 'boolean'];
            }
            $rules[$prefix.'.limits'] = ['sometimes', 'array'];
            foreach ($policy['currencies'] as $code) {
                $rules[$prefix.'.limits.'.$code] = ['sometimes', 'array'];
                foreach (['minimum', 'maximum'] as $field) {
                    $rules[$prefix.'.limits.'.$code.'.'.$field] = ['nullable', 'string', 'max:20'];
                }
            }
        }

        return $rules;
    }

    public function normalize(array $data): array
    {
        $normalized = [];
        foreach ($this->configuration() as $id => $configuration) {
            if (!array_key_exists('policy', $data['gateways'][$id] ?? [])) {
                continue;
            }
            $policy = $data['gateways'][$id]['policy'];
            $values = [];
            if ($configuration['can_mix_wallet'] && array_key_exists('allow_wallet', $policy)) {
                $values['policy_allow_wallet'] = (string) (int) $policy['allow_wallet'];
            }
            if ($configuration['can_use_giftcard'] && array_key_exists('allow_giftcard', $policy)) {
                $values['policy_allow_giftcard'] = (string) (int) $policy['allow_giftcard'];
            }
            foreach ($configuration['currencies'] as $code) {
                $limits = $this->limits($id, $code);
                foreach (['minimum', 'maximum'] as $field) {
                    if (!array_key_exists($field, $policy['limits'][$code] ?? [])) {
                        continue;
                    }
                    $value = $policy['limits'][$code][$field];
                    try {
                        $amount = $value === null || trim($value) === '' ? null : Money::parse($value);
                        if ($amount !== null && $amount > 99_999_999) {
                            throw ValidationException::withMessages([]);
                        }
                    } catch (ValidationException) {
                        throw ValidationException::withMessages([
                            'gateways.'.$id.'.policy.limits.'.$code.'.'.$field => __(
                                'shop::commerce.payments.policy.invalid_amount',
                            ),
                        ]);
                    }
                    $limits[$field] = $amount;
                    $values['policy_'.$field.'_'.$code] = $amount === null ? '' : (string) $amount;
                }
                if (
                    $limits['minimum'] !== null &&
                    $limits['maximum'] !== null &&
                    $limits['minimum'] > $limits['maximum']
                ) {
                    throw ValidationException::withMessages([
                        'gateways.'.$id.'.policy.limits.'.$code.'.maximum' => __(
                            'shop::commerce.payments.policy.invalid_range',
                        ),
                    ]);
                }
            }
            $normalized[$id] = $values;
        }

        return $normalized;
    }

    public function save(array $normalized): void
    {
        foreach ($normalized as $id => $values) {
            foreach ($values as $key => $value) {
                $this->gateways->set($id, $key, $value);
            }
        }
    }
}
