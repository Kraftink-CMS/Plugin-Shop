<?php

namespace KraftInk\Plugins\Shop\Services\Commerce;

use App\Kraft\Settings;
use App\Models\Accounts\User;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

class BillingDetails
{
    public function required(): bool
    {
        return app(Settings::class)->get('shop.commerce.seller_type', 'individual') === 'business';
    }

    public function capture(?User $user, array $details, ?string $guestEmail = null): array
    {
        $required = $this->required();
        $details = array_map(fn ($value) => is_string($value) ? trim($value) : $value, $details);
        $billingValidator = Validator::make($details, [
            'type' => [$required ? 'required' : 'nullable', Rule::in(['individual', 'business'])],
            'name' => [$required ? 'required' : 'nullable', 'string', 'max:160'],
            'address' => [$required ? 'required' : 'nullable', 'string', 'max:1500'],
            'country' => [$required ? 'required' : 'nullable', 'string', 'regex:/^[A-Z]{2}$/D'],
            'registration' => ['nullable', 'string', 'max:100'],
            'vat_id' => ['nullable', 'string', 'max:100'],
        ]);
        $data = $billingValidator->validate();
        $data = array_map(fn ($value) => is_string($value) ? trim($value) : $value, $data);
        $data['email'] =
            $guestEmail ??
            ($user?->hasVerifiedEmail() &&
            filter_var($user->email, FILTER_VALIDATE_EMAIL) &&
            !str_ends_with($user->email, '.invalid')
                ? $user->email
                : null);

        return $data;
    }
}
