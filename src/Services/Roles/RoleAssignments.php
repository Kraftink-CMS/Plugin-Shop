<?php

namespace KraftInk\Plugins\Shop\Services\Roles;

use App\Events\CollectingRoleAssignments;
use App\Models\Accounts\Role;
use App\Models\Accounts\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use KraftInk\Plugins\Shop\Models\Orders\RoleGrant;
use KraftInk\Plugins\Shop\Services\Pricing;

class RoleAssignments
{
    public function roles(User $user): Collection
    {
        $ids = $this->query()->where('shop__role_grants.user_id', $user->id)->pluck('shop__role_grants.role_id');

        return Role::query()
            ->whereIn('id', $ids)
            ->get()
            ->filter(fn (Role $role) => app(RolePolicy::class)->sellable($role))
            ->values();
    }

    public function grant(object $order, object $item, ?string $periodEnd = null): void
    {
        app(RolePolicy::class)->assertSellable($item->role_id);
        app(Pricing\UpgradeLedger::class)->activate($order, $item);
        if ($item->role_id) {
            $expiresAt = $periodEnd ?: ($item->role_days ? now()->addDays($item->role_days)->toDateTimeString() : null);
            RoleGrant::query()->updateOrInsert(
                [
                    'user_id' => $item->recipient_id ?? $order->user_id,
                    'role_id' => $item->role_id,
                    'order_id' => $order->id,
                ],
                ['expires_at' => $expiresAt, 'revoked_at' => null, 'revocation_reason' => null, 'created_at' => now()],
            );
        }
    }

    public function query(): Builder
    {
        return RoleGrant::query()->active();
    }

    public function collect(CollectingRoleAssignments $event): void
    {
        $roles = Role::query()
            ->whereIn('id', $event->roleIds)
            ->get()
            ->filter(fn ($role) => app(RolePolicy::class)->sellable($role));
        if ($roles->isNotEmpty()) {
            $event->add(
                $this->query()
                    ->whereIn('shop__role_grants.role_id', $roles->pluck('id'))
                    ->select('shop__role_grants.user_id', 'shop__role_grants.role_id')
                    ->toBase(),
            );
        }
    }
}
