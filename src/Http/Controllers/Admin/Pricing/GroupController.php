<?php

namespace KraftInk\Plugins\Shop\Http\Controllers\Admin\Pricing;

use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Kraft\System\TableQuery;
use App\Kraft\Audit;
use App\Kraft\Currencies\CurrencyRegistry;
use App\Kraft\Games\GameRegistry;
use App\Kraft\System\SensitiveAction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use KraftInk\Plugins\Shop\Models\Catalog\Product;
use KraftInk\Plugins\Shop\Models\Pricing\UpgradeGroup;
use KraftInk\Plugins\Shop\Models\Pricing\UpgradeState;
use KraftInk\Plugins\Shop\Services\Pricing\PricingRegistry;
use KraftInk\Plugins\Shop\Services\Pricing\UpgradeLedger;
use KraftInk\Plugins\Shop\Services\Roles\RolePolicy;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;

class GroupController
{
    public function index(): View
    {
        $game = app(GameRegistry::class)->activeId();
        $states = TableQuery::paginate(
            UpgradeState::query()
                ->from('shop__upgrade_states as states')
                ->join('shop__upgrade_groups as groups', 'groups.id', '=', 'states.group_id')
                ->join('users', 'users.id', '=', 'states.user_id')
                ->where('groups.game', $game)
                ->where(function ($query) {
                    $query
                        ->where('states.status', 'review')
                        ->orWhere(
                            fn ($query) => $query
                                ->where('groups.expired_policy', 'block')
                                ->where('states.expires_at', '<=', now()),
                        )
                        ->orWhereExists(
                            fn ($query) => $query
                                ->selectRaw('1')
                                ->from('shop__order_items as items')
                                ->join(
                                    'shop__role_grants as grants',
                                    fn ($join) => $join
                                        ->on('grants.order_id', '=', 'items.order_id')
                                        ->on('grants.role_id', '=', 'items.role_id'),
                                )
                                ->whereColumn('items.id', 'states.current_item_id')
                                ->whereColumn('grants.user_id', 'states.user_id')
                                ->whereNotNull('grants.revoked_at'),
                        );
                })
                ->orderBy('states.id')
                ->select('states.*', 'groups.name as group_name', 'users.username'),
            ['user' => 'users.username', 'group' => 'groups.name'],
            pageName: 'page',
            search: ['users.username', 'groups.name'],
        )->withQueryString();

        return view('shop::admin.pricing.index', [
            'groups' => TableQuery::paginate(
                UpgradeGroup::query()->where('game', $game)->orderBy('name')->orderBy('id'),
                ['name' => 'name', 'currency' => 'currency', 'strategy' => 'strategy', 'enabled' => 'enabled'],
                pageName: 'groups_page',
                search: ['name'],
            )->withQueryString(),
            'strategies' => app(PricingRegistry::class)->all(),
            'states' => $states,
        ]);
    }

    public function review(Request $request, int $id): RedirectResponse
    {
        $data = $request->validate([
            'decision' => 'required|in:restore,reset',
            'note' => 'required|string|min:20|max:1000',
            'confirm_review' => 'accepted',
        ]);
        app(SensitiveAction::class)->confirm($request);
        app(UpgradeLedger::class)->review($id, $data['decision'], $data['note'], $request->user());

        return back()->with('status', __('shop::messages.feedback.saved'));
    }

    public function save(Request $request, ?int $id = null): RedirectResponse
    {
        $data = $request->validate([
            'name' => 'required|string|max:120',
            'slug' => 'nullable|regex:/^[a-zA-Z0-9_-]{1,80}$/D',
            'currency' => ['required', Rule::in(app(CurrencyRegistry::class)->codes())],
            'strategy' => ['required', Rule::in(array_keys(app(PricingRegistry::class)->all()))],
            'expired_policy' => 'required|in:reset,block',
            'enabled' => 'required|boolean',
        ]);
        $data['game'] = app(GameRegistry::class)->activeId();
        DB::transaction(function () use ($request, $id, $data) {
            $group = $id
                ? UpgradeGroup::query()->where('game', $data['game'])->where('id', $id)->lockForUpdate()->first()
                : null;
            if ($id && !$group) {
                abort(404);
            }
            $data['slug'] =
                $data['slug'] ??
                ($group?->slug ??
                    (mb_substr(Str::slug($data['name']), 0, 80) ?: 'group-'.Str::lower(Str::random(8))));
            if ($group && ($group->slug !== $data['slug'] || $group->currency !== $data['currency'])) {
                throw new HttpException(422, __('shop::commerce.upgrades.identity_locked'));
            }
            /** @var Product $product */
            foreach (
                Product::query()
                    ->where('game', $data['game'])
                    ->where('currency', $data['currency'])
                    ->where('upgrade_group', $group?->slug ?? $data['slug'])
                    ->get() as $product
            ) {
                app(RolePolicy::class)->assertManage($request->user(), $product->role_id);
            }
            if (UpgradeGroup::query()->where('slug', $data['slug'])->where('game', $data['game'])->where('currency', $data['currency'])->when($id, fn ($query) => $query->where('id', '!=', $id))->exists()) {
                throw new HttpException(422, __('shop::commerce.upgrades.duplicate'));
            }
            if ($id) {
                UpgradeGroup::query()
                    ->where('id', $id)
                    ->update($data + ['updated_at' => now()]);
            } else {
                $id = UpgradeGroup::query()->insertGetId($data + ['created_at' => now(), 'updated_at' => now()]);
            }
            Audit::record(
                'shop.upgrade.group_saved',
                ['id' => $id, 'strategy' => $data['strategy'], 'expired_policy' => $data['expired_policy']],
                $request->user(),
            );
        }, 3);

        return redirect()
            ->route('plugins.shop.admin.pricing.index')
            ->with('status', __('shop::messages.feedback.saved'));
    }

    public function delete(int $id): RedirectResponse
    {
        DB::transaction(function () use ($id) {
            $group = UpgradeGroup::query()
                ->where('game', app(GameRegistry::class)->activeId())
                ->where('id', $id)
                ->lockForUpdate()
                ->first();
            if ($group === null) {
                abort(404);
            }
            if (UpgradeState::query()->where('group_id', $id)->exists() || Product::query()->where('game', $group->game)->where('currency', $group->currency)->where('upgrade_group', $group->slug)->exists()) {
                throw new HttpException(422, __('shop::commerce.upgrades.group_used'));
            }
            UpgradeGroup::query()->where('id', $id)->delete();
        }, 3);

        return back();
    }
}
