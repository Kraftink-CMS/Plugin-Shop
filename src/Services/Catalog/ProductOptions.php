<?php

namespace KraftInk\Plugins\Shop\Services\Catalog;

use App\Kraft\Auth\AccountIdentity;
use App\Kraft\Games\GameRegistry;
use App\Kraft\LocalePolicy;
use App\Kraft\Wallet\Money;
use App\Models\Accounts\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use KraftInk\Plugins\Shop\Models\Catalog\ProductServer;
use InvalidArgumentException;

class ProductOptions
{
    public function definitions(array $definitions, array $commands, array $existing = []): array
    {
        $definitionsValidator = Validator::make(
            ['variables' => $definitions],
            [
                'variables' => ['array', 'max:20'],
                'variables.*' => ['array:key,label,type,required,min,max,choices'],
                'variables.*.key' => ['required', 'string', 'regex:/^[a-z][a-z0-9_]{0,31}$/D', 'distinct'],
                'variables.*.label' => ['nullable', 'array:'.implode(',', array_keys(config('kraft.locales')))],
                'variables.*.label.*' => ['nullable', 'string', 'max:120'],
                'variables.*.type' => ['required', 'in:text,integer,choice'],
                'variables.*.required' => ['sometimes', 'boolean'],
                'variables.*.min' => ['nullable', 'integer', 'min:-1000000000', 'max:1000000000'],
                'variables.*.max' => ['nullable', 'integer', 'min:-1000000000', 'max:1000000000'],
                'variables.*.choices' => ['nullable', 'array', 'max:30'],
                'variables.*.choices.*' => [
                    'required',
                    'string',
                    'max:120',
                    'distinct',
                    'regex:/^[\p{L}\p{N}_.:-]+$/uD',
                ],
            ],
        );
        $definitionsValidator->validate();
        $policy = app(LocalePolicy::class);
        $existingByKey = array_column($existing, null, 'key');
        foreach ($definitions as &$definition) {
            $submittedLabels = $definition['label'] ?? [];
            $definition['label'] = array_filter(
                array_map(fn ($label) => trim($label ?? ''), $definition['label'] ?? []),
                fn ($label) => $label !== '',
            );
            if (!$policy->allowsChoice()) {
                $locale = $policy->defaultLocale();
                $labels = $existingByKey[$definition['key']]['label'] ?? [];
                if (array_key_exists($locale, $submittedLabels)) {
                    if (isset($definition['label'][$locale])) {
                        $labels[$locale] = $definition['label'][$locale];
                    } else {
                        unset($labels[$locale]);
                    }
                }
                $definition['label'] = $labels;
            }
            $definition['required'] = (bool) ($definition['required'] ?? true);
            $definition['min'] = isset($definition['min']) ? (int) $definition['min'] : null;
            $definition['max'] = isset($definition['max']) ? (int) $definition['max'] : null;
            if (
                ($definition['type'] === 'choice' && empty($definition['choices'])) ||
                ($definition['min'] !== null &&
                    $definition['max'] !== null &&
                    $definition['min'] > $definition['max']) ||
                ($definition['type'] === 'text' &&
                    (($definition['min'] ?? 0) < 0 ||
                        ($definition['min'] ?? 0) > ($definition['max'] ?? 120) ||
                        ($definition['max'] ?? 120) < 1 ||
                        ($definition['max'] ?? 120) > 120))
            ) {
                throw ValidationException::withMessages([
                    'variables' => __('shop::messages.products.options.definition_invalid'),
                ]);
            }
        }
        unset($definition);
        $byKey = array_column($definitions, null, 'key');
        foreach ($commands as $command) {
            preg_match_all('/\{var:([a-z][a-z0-9_]{0,31})\}/', $command, $matches);
            if (array_diff($matches[1], array_keys($byKey))) {
                throw ValidationException::withMessages([
                    'commands' => __('shop::messages.products.options.unknown_variable'),
                ]);
            }
            $expanded = preg_replace_callback(
                '/\{var:([a-z][a-z0-9_]{0,31})\}/',
                function ($match) use ($byKey) {
                    $field = $byKey[$match[1]];
                    $length =
                        $field['type'] === 'integer'
                            ? 11
                            : ($field['type'] === 'choice'
                                ? max(array_map('strlen', $field['choices']))
                                : $field['max'] ?? 120);

                    return str_repeat('X', $length);
                },
                $command,
            );
            if (strlen($expanded) > 2048) {
                throw ValidationException::withMessages([
                    'commands' => __('shop::integrations.servers.invalid_command'),
                ]);
            }
        }

        return array_values($definitions);
    }

    public function label(array $field): string
    {
        $policy = app(LocalePolicy::class);
        $locales = $policy->allowsChoice()
            ? array_unique([app()->getLocale(), 'en', $policy->defaultLocale()])
            : [$policy->defaultLocale()];
        foreach ($locales as $locale) {
            $label = $field['label'][$locale] ?? null;
            if (is_string($label) && trim($label) !== '') {
                return trim($label);
            }
        }

        return $field['key'];
    }

    public function fromRequest(Request $request, object $product, array $existing = []): array
    {
        if (!$product->custom_price) {
            $existing['price_minor'] = (int) $product->price_minor;
        }
        $optionsValidator = Validator::make($request->all(), [
            'price_amount' => ['nullable', 'string', 'max:30'],
            'variables' => ['nullable', 'array', 'max:20'],
            'server_id' => ['nullable', 'integer'],
            'giftcard_recipient' => ['nullable', 'string', 'max:64'],
            'recipient' => ['nullable', 'string', 'max:64'],
            'trial_requested' => ['nullable', 'boolean'],
        ]);
        $data = $optionsValidator->validate();
        if ($request->has('price_amount')) {
            if (!$product->custom_price) {
                throw ValidationException::withMessages([
                    'price_amount' => __('shop::messages.products.options.price_invalid'),
                ]);
            }
            try {
                $existing['price_minor'] = Money::parse($data['price_amount'] ?? '');
            } catch (ValidationException) {
                throw ValidationException::withMessages([
                    'price_amount' => __('shop::messages.products.options.price_invalid'),
                ]);
            }
        }
        if ($request->has('variables')) {
            $existing['variables'] = $data['variables'] ?? [];
        }
        if ($request->has('server_id')) {
            $existing['server_id'] = !empty($data['server_id']) ? (int) $data['server_id'] : null;
        }

        if ($request->has('giftcard_recipient')) {
            $recipient = trim($data['giftcard_recipient'] ?? '');
            if ($recipient !== ($existing['giftcard_recipient'] ?? '')) {
                unset($existing['giftcard_recipient_id']);
            }
            $existing['giftcard_recipient'] = $recipient;
        }
        if ($request->has('recipient')) {
            $existing['recipient'] = trim($data['recipient'] ?? '');
            if ($existing['recipient'] !== '' && !$request->user()) {
                throw ValidationException::withMessages(['recipient' => __('shop::gifting.member_required')]);
            }
            unset($existing['recipient_id']);
        }
        if ($request->has('trial_requested')) {
            $existing['trial_requested'] = $request->boolean('trial_requested');
        }

        return $this->validate($product, $existing);
    }

    public function validate(object $product, array $input): array
    {
        if (
            !$product->custom_price &&
            isset($input['price_minor']) &&
            $input['price_minor'] !== (int) $product->price_minor
        ) {
            throw ValidationException::withMessages([
                'checkout' => __('shop::messages.products.options.price_changed'),
            ]);
        }
        $price = $product->custom_price ? $input['price_minor'] ?? null : (int) $product->price_minor;
        $minimum = $product->custom_price
            ? $product->minimum_price_minor ?? $product->price_minor
            : $product->price_minor;
        $maximum = $product->custom_price ? $product->maximum_price_minor ?? 99999999 : $product->price_minor;
        if (!is_int($price) || $price < $minimum || $price > $maximum) {
            throw ValidationException::withMessages([
                'price_amount' => __('shop::messages.products.options.price_invalid'),
            ]);
        }
        $definitions = $product->variables ?? [];
        $values = $input['variables'] ?? [];
        if (!is_array($values) || array_diff(array_keys($values), array_column($definitions, 'key'))) {
            throw ValidationException::withMessages([
                'variables' => __('shop::messages.products.options.values_invalid'),
            ]);
        }
        foreach ($definitions as $field) {
            $key = $field['key'];
            $value = $values[$key] ?? '';
            if (!is_string($value) && !is_int($value)) {
                throw ValidationException::withMessages([
                    'variables.'.$key => __('shop::messages.products.options.values_invalid'),
                ]);
            }
            $value = (string) $value;
            $valid = $value === '' && !($field['required'] ?? true);
            if ($value !== '') {
                $valid = match ($field['type']) {
                    'text' => preg_match('/^[\p{L}\p{N}_.:-]+$/uD', $value) &&
                        mb_strlen($value) >= ($field['min'] ?? 1) &&
                        mb_strlen($value) <= ($field['max'] ?? 120),
                    'integer' => preg_match('/^-?[0-9]{1,10}$/D', $value) &&
                        (int) $value >= ($field['min'] ?? -1000000000) &&
                        (int) $value <= ($field['max'] ?? 1000000000),
                    'choice' => in_array($value, $field['choices'] ?? [], true),
                    default => false,
                };
            }
            if (!$valid || preg_match('/[\x00-\x1F\x7F]/', $value)) {
                throw ValidationException::withMessages([
                    'variables.'.$key => __('shop::messages.products.options.values_invalid'),
                ]);
            }
            $values[$key] = $field['type'] === 'integer' && $value !== '' ? (string) (int) $value : $value;
        }
        $servers = ProductServer::query()
            ->join('servers', 'servers.id', '=', 'shop__product_servers.server_id')
            ->where('product_id', $product->id)
            ->where('servers.game', app(GameRegistry::class)->activeId())
            ->pluck('server_id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $server = $servers ? $input['server_id'] ?? null : ($product->server_id ? (int) $product->server_id : null);
        if ($servers && !in_array($server, $servers, true)) {
            throw ValidationException::withMessages([
                'server_id' => __('shop::messages.products.options.server_required'),
            ]);
        }

        $recipient = $input['giftcard_recipient'] ?? '';
        if (
            !is_string($recipient) ||
            strlen($recipient) > 64 ||
            (($recipient !== '' || isset($input['giftcard_recipient_id'])) && $product->kind !== 'giftcard')
        ) {
            throw ValidationException::withMessages([
                'giftcard_recipient' => __('shop::commerce.giftcards.recipient_invalid'),
            ]);
        }
        $cardRecipient = $this->recipientAccount(
            $recipient,
            $input['giftcard_recipient_id'] ?? null,
            'giftcard_recipient',
        );
        $beneficiary = $this->recipientAccount($input['recipient'] ?? '', $input['recipient_id'] ?? null, 'recipient');
        if ($product->kind === 'giftcard' && $beneficiary) {
            throw ValidationException::withMessages(['recipient' => __('shop::gifting.recipient_invalid')]);
        }
        $trial = $input['trial_requested'] ?? false;
        if (!is_bool($trial) || ($trial && (!$product->billing_interval || ($product->trial_days ?? 0) < 1))) {
            throw ValidationException::withMessages(['trial_requested' => __('shop::subscriptions.trial_unavailable')]);
        }

        return [
            'price_minor' => $price,
            'variables' => $values,
            'server_id' => $server,
            'giftcard_recipient' => $cardRecipient?->username ?? '',
            'giftcard_recipient_id' => $cardRecipient?->id,
            'recipient' => $beneficiary?->username ?? '',
            'recipient_id' => $beneficiary?->id,
            'trial_requested' => $trial,
        ];
    }

    public function equivalent(?User $buyer, array $left, array $right): bool
    {
        $left['recipient_id'] ??= $buyer?->id;
        $right['recipient_id'] ??= $buyer?->id;
        unset($left['recipient'], $left['giftcard_recipient'], $right['recipient'], $right['giftcard_recipient']);

        return $left == $right;
    }

    public function beneficiary(?User $buyer, array $options, bool $lock = false): ?User
    {
        if (empty($options['recipient_id']) && empty($options['recipient'])) {
            return $buyer;
        }
        if (!$buyer) {
            throw ValidationException::withMessages(['recipient' => __('shop::gifting.member_required')]);
        }

        return $this->recipientAccount(
            empty($options['recipient_id']) ? $options['recipient'] : '',
            $options['recipient_id'] ?? null,
            'recipient',
            $lock,
        );
    }

    private function recipientAccount(mixed $username, mixed $id, string $field, bool $lock = false): ?User
    {
        $message =
            $field === 'recipient' ? 'shop::gifting.recipient_invalid' : 'shop::commerce.giftcards.recipient_invalid';
        if ($username === '' && $id === null) {
            return null;
        }
        if (!is_string($username) || strlen($username) > 64 || ($id !== null && (!is_int($id) || $id < 1))) {
            throw ValidationException::withMessages([$field => __($message)]);
        }
        $query = User::members()->whereNull('anonymized_at');
        if ($id === null) {
            $query->whereRaw(AccountIdentity::expression().' = ?', [mb_strtolower($username)]);
        } else {
            $query->whereKey($id);
        }
        $account = $query->when($lock, fn ($query) => $query->lockForUpdate())->first();
        if (!$account || $account->isBanned() || $account->import_locked) {
            throw ValidationException::withMessages([$field => __($message)]);
        }

        return $account;
    }

    public function commands(array $commands, array $values): array
    {
        return array_map(
            fn ($command) => preg_replace_callback(
                '/\{var:([a-z][a-z0-9_]{0,31})\}/',
                fn ($match) => $values[$match[1]] ?? throw new InvalidArgumentException('Unknown product variable.'),
                $command,
            ),
            $commands,
        );
    }
}
