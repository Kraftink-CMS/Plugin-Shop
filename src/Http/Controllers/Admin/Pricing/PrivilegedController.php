<?php

namespace KraftInk\Plugins\Shop\Http\Controllers\Admin\Pricing;

use App\Kraft\System\TableQuery;
use App\Kraft\Audit;
use App\Kraft\Auth\RoleHierarchy;
use App\Kraft\Currencies\CurrencyRegistry;
use App\Kraft\Games\GameRegistry;
use App\Kraft\SiteDates;
use App\Models\Accounts\Role;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use KraftInk\Plugins\Shop\Models\Catalog\Category;
use KraftInk\Plugins\Shop\Models\Catalog\PrivilegedPrice;
use KraftInk\Plugins\Shop\Models\Catalog\Product;
use KraftInk\Plugins\Shop\Services\Catalog\Catalog;
use KraftInk\Plugins\Shop\Services\Finance\IssuedCreditScope;
use Illuminate\View\View;
use Illuminate\Http\RedirectResponse;

class PrivilegedController
{
    public function index(): View
    {
        return view('shop::admin.privileged.index', [
            'rules' => TableQuery::paginate(
                PrivilegedPrice::query()
                    ->join('roles', 'roles.id', '=', 'shop__privileged_prices.role_id')
                    ->where('game', app(GameRegistry::class)->activeId())
                    ->select('shop__privileged_prices.*', 'roles.name AS role_name')
                    ->orderBy('shop__privileged_prices.name'),
                [
                    'name' => 'shop__privileged_prices.name',
                    'role' => 'roles.name',
                    'scope' => 'shop__privileged_prices.scope',
                    'enabled' => 'shop__privileged_prices.enabled',
                ],
                pageName: 'page',
                search: ['shop__privileged_prices.name', 'roles.name'],
            ),
        ]);
    }

    public function edit(Request $request, ?int $id = null): View
    {
        $rule = $id ? $this->find($id) : null;
        if ($rule) {
            app(RoleHierarchy::class)->assertRole($request->user(), Role::query()->findOrFail($rule->role_id));
        }

        return view('shop::admin.privileged.edit', [
            'rule' => $rule,
            'roles' => Role::query()
                ->orderByDesc('position')
                ->get()
                ->filter(fn ($role) => app(RoleHierarchy::class)->canManageRole($request->user(), $role)),
            'currencies' => app(CurrencyRegistry::class)->codes(),
            'products' => Product::query()->where('game', app(GameRegistry::class)->activeId())->orderBy('name')->get(),
            'categories' => app(Catalog::class)->categories(),
        ]);
    }

    public function save(Request $request, ?int $id = null): RedirectResponse
    {
        app(SiteDates::class)->normalize($request, ['starts_at', 'expires_at']);
        $game = app(GameRegistry::class)->activeId();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'role_id' => ['required', 'integer', 'exists:roles,id'],
            'currency' => ['required', Rule::in(app(CurrencyRegistry::class)->codes())],
            'scope' => ['required', Rule::in(['all', 'category', 'product'])],
            'category_id' => ['nullable', 'required_if:scope,category', 'integer', 'exists:shop__categories,id'],
            'product_id' => [
                'nullable',
                'required_if:scope,product',
                'integer',
                Rule::exists('shop__products', 'id')->where('game', $game),
            ],
            'type' => ['required', Rule::in(['percent', 'fixed'])],
            'value' => ['required', 'string', 'regex:/^[0-9]{1,6}(?:[.,][0-9]{1,2})?$/D'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => ['nullable', 'date'],
            'combine_coupon' => ['sometimes', 'boolean'],
            'enabled' => ['sometimes', 'boolean'],
        ]);
        [$whole, $fraction] = array_pad(explode('.', str_replace(',', '.', $data['value']), 2), 2, '');
        $data['amount'] = (int) $whole * 100 + (int) str_pad($fraction, 2, '0');
        if ($data['amount'] < 1 || ($data['type'] === 'percent' && $data['amount'] > 10000)) {
            throw ValidationException::withMessages(['value' => __('shop::admin.discounts.privileged.invalid_value')]);
        }
        unset($data['value']);
        foreach (['starts_at', 'expires_at'] as $date) {
            $data[$date] = !empty($data[$date])
                ? CarbonImmutable::parse($data[$date], 'UTC')->utc()->format('Y-m-d H:i:s')
                : null;
        }
        if ($data['starts_at'] && $data['expires_at'] && $data['expires_at'] <= $data['starts_at']) {
            throw ValidationException::withMessages([
                'expires_at' => __('shop::admin.discounts.privileged.invalid_dates'),
            ]);
        }
        $data['game'] = $game;
        $data['category_id'] = $data['scope'] === 'category' ? $data['category_id'] : null;
        $data['product_id'] = $data['scope'] === 'product' ? $data['product_id'] : null;
        $data['enabled'] = $request->boolean('enabled');
        $data['combine_coupon'] = $request->boolean('combine_coupon');
        app(RoleHierarchy::class)->assertRole($request->user(), Role::query()->findOrFail($data['role_id']));
        DB::transaction(function () use ($request, $id, $data) {
            if ($id) {
                $existing = $this->find($id);
                app(RoleHierarchy::class)->assertRole($request->user(), Role::query()->findOrFail($existing->role_id));
            }
            $products = Product::query()->where('game', $data['game'])->where('currency', $data['currency']);
            if ($data['scope'] === 'product') {
                $products->where('id', $data['product_id']);
            } elseif ($data['scope'] === 'category') {
                $products->whereIn('category_id', Category::descendantIds((int) $data['category_id']));
            }
            $captured = app(IssuedCreditScope::class)->capture($request->user());
            app(IssuedCreditScope::class)->assert(new PrivilegedPrice()->setRawAttributes($captured), $products->get());
            $values = $data + $captured + ['updated_at' => now()];
            if ($id) {
                PrivilegedPrice::query()->where('id', $id)->update($values);
            } else {
                $id = PrivilegedPrice::query()->insertGetId($values + ['created_at' => now()]);
            }
            Audit::record('shop.privileged_price.saved', ['id' => $id], $request->user());
        }, 3);

        return redirect()
            ->route('plugins.shop.admin.privileged.index')
            ->with('status', __('shop::messages.feedback.saved'));
    }

    public function delete(Request $request, int $id): RedirectResponse
    {
        $rule = $this->find($id);
        app(RoleHierarchy::class)->assertRole($request->user(), Role::query()->findOrFail($rule->role_id));
        PrivilegedPrice::query()->where('id', $id)->delete();
        Audit::record('shop.privileged_price.deleted', ['id' => $id], $request->user());

        return back()->with('status', __('shop::messages.feedback.saved'));
    }

    private function find(int $id): object
    {
        $rule = PrivilegedPrice::query()->where('game', app(GameRegistry::class)->activeId())->find($id);
        if ($rule === null) {
            abort(404);
        }

        return $rule;
    }
}
