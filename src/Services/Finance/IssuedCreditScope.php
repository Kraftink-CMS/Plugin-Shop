<?php

namespace KraftInk\Plugins\Shop\Services\Finance;

use App\Kraft\Auth\PermissionAccess;
use App\Models\Accounts\Role;
use App\Models\Accounts\User;
use Illuminate\Validation\ValidationException;
use KraftInk\Plugins\Shop\Models\Catalog\Coupon;
use KraftInk\Plugins\Shop\Models\Catalog\PrivilegedPrice;
use KraftInk\Plugins\Shop\Models\GiftCards\GiftCard;

class IssuedCreditScope
{
    public function capture(User $actor): array
    {
        $roles = $actor->effectiveRoles();

        return [
            'issuer_id' => $actor->id,
            'issuer_permissions' => json_encode(
                collect(app(PermissionAccess::class)->permissions($actor, $roles))->sort()->values()->all(),
                JSON_THROW_ON_ERROR,
            ),
            'issuer_position' => (int) ($roles->max('position') ?? -1),
        ];
    }

    public function assert(Coupon|GiftCard|PrivilegedPrice $credit, iterable $products): void
    {
        if ($credit->issuer_permissions === null) {
            return;
        }
        $permissions = $credit->issuer_permissions;
        if (in_array('*', $permissions, true)) {
            return;
        }
        $ids = collect($products)->pluck('role_id')->filter()->unique();
        $memberPermissions = app(PermissionAccess::class)->permissions(new User(), collect());
        /** @var Role $role */
        foreach (Role::query()->whereIn('id', $ids)->get() as $role) {
            if (
                $role->position >= $credit->issuer_position ||
                array_diff($role->effectivePermissions(), $permissions, $memberPermissions)
            ) {
                throw ValidationException::withMessages([
                    'checkout' => __('shop::messages.products.options.credit_scope'),
                ]);
            }
        }
    }
}
