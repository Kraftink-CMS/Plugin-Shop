<?php

namespace KraftInk\Plugins\Shop\Services\Roles;

use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Kraft\Auth\RoleHierarchy;
use App\Models\Accounts\Role;
use App\Models\Accounts\User;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class RolePolicy
{
    public function allowed(User $actor): Collection
    {
        return Role::query()
            ->orderByDesc('position')
            ->get()
            ->filter(fn (Role $role) => $this->canManage($actor, $role))
            ->values();
    }

    public function assertManage(User $actor, ?int $id): void
    {
        if ($id === null) {
            return;
        }
        if (!$this->canManage($actor, Role::query()->findOrFail($id))) {
            throw new HttpException(403);
        }
    }

    public function assertSellable(?int $id): void
    {
        if ($id !== null && !$this->sellable(Role::query()->findOrFail($id))) {
            throw ValidationException::withMessages(['role_id' => __('shop::messages.errors.protected_role')]);
        }
    }

    public function sellable(Role $role): bool
    {
        return $role->slug !== 'owner' && !in_array('*', $role->effectivePermissions(), true);
    }

    private function canManage(User $actor, Role $role): bool
    {
        if (!$this->sellable($role)) {
            return false;
        }

        return app(RoleHierarchy::class)->canManageRole($actor, $role);
    }
}
