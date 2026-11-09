<?php

namespace KraftInk\Plugins\Shop\Http\Controllers\Admin;

use Symfony\Component\HttpKernel\Exception\HttpException;
use App\Kraft\System\TableQuery;
use App\Kraft\Audit;
use App\Kraft\Currencies\CurrencyRegistry;
use App\Kraft\Games\GameRegistry;
use App\Kraft\Html;
use App\Kraft\Link\DeliveryMonitor;
use App\Kraft\Media\FileDeletionQueue;
use App\Kraft\Media\Images;
use App\Kraft\Settings;
use App\Kraft\SiteDates;
use App\Kraft\System\SensitiveAction;
use App\Kraft\Wallet\Money;
use App\Kraft\Wallet\Wallet;
use App\Models\Accounts\Role;
use App\Models\Media\MediaAsset;
use App\Models\Servers\Server;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use KraftInk\Plugins\Shop\Models\Billing\CreditRecovery;
use KraftInk\Plugins\Shop\Models\Billing\FinancialEvent;
use KraftInk\Plugins\Shop\Models\Billing\ManualPayment;
use KraftInk\Plugins\Shop\Models\Billing\ManualRefund;
use KraftInk\Plugins\Shop\Models\Catalog\Category;
use KraftInk\Plugins\Shop\Models\Catalog\Coupon;
use KraftInk\Plugins\Shop\Models\Catalog\Product;
use KraftInk\Plugins\Shop\Models\Catalog\ProductCode;
use KraftInk\Plugins\Shop\Models\Catalog\ProductServer;
use KraftInk\Plugins\Shop\Models\GiftCards\GiftCard;
use KraftInk\Plugins\Shop\Models\Orders\Order;
use KraftInk\Plugins\Shop\Models\Orders\OrderItem;
use KraftInk\Plugins\Shop\Models\Pricing\UpgradeGroup;
use KraftInk\Plugins\Shop\Services\Catalog\Catalog;
use KraftInk\Plugins\Shop\Services\Catalog\ProductOptions;
use KraftInk\Plugins\Shop\Services\Finance\IssuedCreditScope;
use KraftInk\Plugins\Shop\Services\Orders\OrderService;
use KraftInk\Plugins\Shop\Services\Payments\GatewayRegistry;
use KraftInk\Plugins\Shop\Services\Payments\PaymentPolicy;
use KraftInk\Plugins\Shop\Services\Roles\RolePolicy;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use App\Kraft\LocalePolicy;
use RuntimeException;
use Throwable;

class AdminController
{
    public function index(Request $request, GatewayRegistry $registry): RedirectResponse|View
    {
        $data = $request->validate([
            'tab' => ['nullable', Rule::in(['products', 'orders', 'categories', 'coupons', 'giftcards', 'payments'])],
            'q' => 'nullable|string|max:100',
            'category' => 'nullable|integer|exists:shop__categories,id',
            'locale' => ['nullable', Rule::in(array_keys(config('kraft.locales')))],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'page' => ['nullable', 'integer', 'min:1'],
            'products_page' => ['nullable', 'integer', 'min:1'],
            'orders_page' => ['nullable', 'integer', 'min:1'],
            'categories_page' => ['nullable', 'integer', 'min:1'],
            'coupons_page' => ['nullable', 'integer', 'min:1'],
            'giftcards_page' => ['nullable', 'integer', 'min:1'],
        ]);
        $sections = ['products', 'orders', 'categories', 'coupons', 'giftcards', 'payments'];
        $previousTab = $request->old('catalogue_tab');
        $tab =
            $request->route('section') ??
            (in_array($previousTab, $sections, true) ? $previousTab : $data['tab'] ?? 'products');
        if ($request->route('section')) {
            return redirect()->to(
                route('plugins.shop.admin.catalog.index', array_replace($request->query(), ['tab' => $tab])).
                    '#catalogue-'.
                    $tab,
            );
        }
        $paginate = function ($query, string $section) use ($request) {
            $sorts = match ($section) {
                'products' => [
                    'name' => 'shop__products.name',
                    'category' => 'shop__categories.name',
                    'stock' => 'shop__products.stock',
                    'enabled' => 'shop__products.enabled',
                ],
                'categories' => [
                    'name' => 'shop__categories.name',
                    'parent' => 'parent.name',
                    'locale' => 'shop__categories.locale',
                    'position' => 'shop__categories.position',
                ],
                'coupons' => ['code' => 'code', 'expires_at' => 'expires_at', 'enabled' => 'enabled'],
                'giftcards' => [
                    'last_four' => 'last_four',
                    'balance_minor' => 'balance_minor',
                    'expires_at' => 'expires_at',
                    'enabled' => 'enabled',
                ],
                'orders' => [
                    'id' => 'id',
                    'provider' => 'provider',
                    'status' => 'status',
                    'created_at' => 'created_at',
                ],
                default => [],
            };
            $search = match ($section) {
                'categories' => ['shop__categories.name'],
                'coupons' => ['code'],
                'giftcards' => ['last_four'],
                'orders' => ['reference'],
                default => [],
            };
            $pageName = $section.'_page';

            return TableQuery::paginate($query, $sorts, pageName: $pageName, search: $search)
                ->withPath(route('plugins.shop.admin.catalog.index'))
                ->appends($request->except(['tab', 'page']))
                ->appends(['tab' => $section])
                ->fragment('catalogue-'.$section);
        };
        $query = Product::query()
            ->leftJoin('shop__categories', 'shop__categories.id', '=', 'shop__products.category_id')
            ->select('shop__products.*', 'shop__categories.name as category_name')
            ->orderBy('shop__products.position')
            ->orderBy('shop__products.id');
        $query->where(
            fn ($query) => $query
                ->where('shop__products.game', app(GameRegistry::class)->activeId())
                ->orWhereNull('shop__products.game'),
        );
        if (($data['q'] ?? '') !== '') {
            TableQuery::whereContains($query, ['shop__products.name'], $data['q']);
        }
        if (!empty($data['category'])) {
            $query->where('shop__products.category_id', $data['category']);
        }
        if (!empty($data['locale'])) {
            $query->where('shop__products.locale', $data['locale']);
        }
        if (!empty($data['status'])) {
            $query->where('shop__products.enabled', $data['status'] === 'active');
        }

        return view('shop::admin.catalog.index', [
            'tab' => $tab,
            'catalogueSections' => $sections,
            'products' => $paginate($query, 'products'),
            'categoryOptions' => Category::query()
                ->orderBy('position')
                ->orderBy('id')
                ->get(['id', 'name']),
            'categories' => $paginate(
                Category::query()
                    ->leftJoin('shop__categories as parent', 'parent.id', '=', 'shop__categories.parent_id')
                    ->select('shop__categories.*', 'parent.name as parent_name')
                    ->orderBy('shop__categories.position')
                    ->orderBy('shop__categories.id'),
                'categories',
            ),
            'roles' => app(RolePolicy::class)->allowed($request->user()),
            'coupons' => $paginate(Coupon::query()->orderByDesc('id'), 'coupons'),
            'giftcards' => $paginate(GiftCard::query()->orderByDesc('id'), 'giftcards'),
            'orders' => $paginate(Order::query()->orderByDesc('id'), 'orders'),
            'gateways' => $request->user()->hasPermission('shop.finance.configure') ? $registry->all() : [],
            'registry' => $registry,
            'paymentPolicies' => $request->user()->hasPermission('shop.finance.configure')
                ? app(PaymentPolicy::class)->configuration()
                : [],
        ]);
    }

    public function create(Request $request, string $kind = 'product'): View
    {
        return $this->edit($request, null, $kind);
    }

    public function edit(Request $request, ?int $id = null, string $kind = 'product'): View
    {
        if ($kind === 'category') {
            $category = $id ? Category::query()->find($id) : null;
            if ($id && !$category) {
                abort(404);
            }

            return view('shop::admin.categories.edit', [
                'category' => $category,
                'categories' => Category::query()
                    ->when($id, fn ($query) => $query->whereNotIn('id', Category::descendantIds($id)))
                    ->orderBy('position')
                    ->get(),
                'selectedImage' => $category?->image_id ? MediaAsset::query()->find($category->image_id) : null,
            ]);
        }
        if ($kind === 'coupon') {
            $coupon = $id ? Coupon::query()->find($id) : null;
            if ($id && !$coupon) {
                abort(404);
            }

            return view('shop::admin.coupons.edit', [
                'coupon' => $coupon,
                'categories' => Category::query()->orderBy('position')->get(),
                'products' => Product::query()
                    ->orderBy('name')
                    ->get(['id', 'name', 'locale']),
                'roles' => Role::query()->orderBy('position')->get(),
            ]);
        }
        $product = $id ? Product::query()->find($id) : null;
        if ($id && !$product) {
            abort(404);
        }
        if ($product) {
            if ($product->game !== null && $product->game !== app(GameRegistry::class)->activeId()) {
                abort(404);
            }
            if (($product->wallet_credit_minor ?? 0) > 0 && !$request->user()->hasPermission('wallet.manage')) {
                throw new HttpException(403);
            }
            app(RolePolicy::class)->assertManage($request->user(), $product->role_id);
        }

        return view('shop::admin.products.edit', [
            'product' => $product,
            'categories' => Category::query()->orderBy('position')->get(),
            'servers' => Server::query()->where('game', app(GameRegistry::class)->activeId())->orderBy('name')->get(),
            'serverChoices' => $product
                ? ProductServer::query()->where('product_id', $product->id)->pluck('server_id')->all()
                : [],
            'roles' => app(RolePolicy::class)->allowed($request->user()),
            'upgradeGroups' => UpgradeGroup::query()
                ->where('game', app(GameRegistry::class)->activeId())
                ->orderBy('name')
                ->get(),
            'selectedImage' => $product?->image_id ? MediaAsset::query()->find($product->image_id) : null,
            'codeCount' => $product ? ProductCode::query()->where('product_id', $product->id)->count() : 0,
            'availableCodes' => $product
                ? ProductCode::query()
                    ->where('product_id', $product->id)
                    ->whereNull('order_item_id')
                    ->count()
                : 0,
        ]);
    }

    public function saveProduct(Request $request, ?int $id = null): RedirectResponse
    {
        $this->money($request, [
            'price_amount' => 'price_minor',
            'wallet_credit_amount' => 'wallet_credit_minor',
            'minimum_price_amount' => 'minimum_price_minor',
            'maximum_price_amount' => 'maximum_price_minor',
        ]);
        $policy = app(RolePolicy::class);
        $existing = null;
        if ($id) {
            $existing = Product::query()->where('id', $id)->first();
            if ($existing === null) {
                abort(404);
            }
            if ($existing->game && $existing->game !== app(GameRegistry::class)->activeId()) {
                abort(404);
            }
            if ($existing->wallet_credit_minor > 0 && !$request->user()->hasPermission('wallet.manage')) {
                throw new HttpException(403);
            }
            $policy->assertManage($request->user(), $existing->role_id);
        }
        $data = $request->validate([
            'kind' => ['sometimes', 'in:product,giftcard'],
            'giftcard_days' => ['nullable', 'integer', 'min:1', 'max:36500'],
            'name' => ['required', 'string', 'max:180'],
            'description' => ['required', 'string', 'max:100000'],
            'locale' => [
                'required',
                Rule::in(
                    app(LocalePolicy::class)->allowsChoice()
                        ? array_keys(config('kraft.locales'))
                        : [$existing?->locale ?? app(LocalePolicy::class)->defaultLocale()],
                ),
            ],
            'category_id' => ['nullable', 'integer', 'exists:shop__categories,id'],
            'price_minor' => ['required', 'integer', 'min:1', 'max:99999999'],
            'currency' => ['required', Rule::in(app(CurrencyRegistry::class)->codes())],
            'stock' => ['nullable', 'integer', 'min:0', 'max:1000000'],
            'limit_per_user' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'role_id' => ['nullable', 'integer', 'exists:roles,id'],
            'role_days' => ['nullable', 'integer', 'min:1', 'max:36500'],
            'billing_interval' => ['nullable', 'in:month,year'],
            'trial_days' => ['sometimes', 'integer', 'min:0', 'max:90'],
            'trial_requires_payment_method' => ['sometimes', 'boolean'],
            'subscription_group' => ['nullable', 'regex:/^[a-zA-Z0-9_-]{1,80}$/'],
            'upgrade_group' => ['nullable', 'regex:/^[a-zA-Z0-9_-]{1,80}$/'],
            'upgrade_weight' => ['required', 'integer', 'min:0', 'max:100000'],
            'download' => ['nullable', 'file', 'mimes:zip,pdf,txt,png,jpg,jpeg,webp', 'max:51200'],
            'required_permission' => ['nullable', 'regex:/^[a-zA-Z0-9_.-]+$/', 'max:100'],
            'commands' => ['nullable', 'string', 'max:10000'],
            'refund_commands' => ['nullable', 'string', 'max:10000'],
            'wallet_credit_minor' => ['sometimes', 'integer', 'min:0', 'max:99999999'],
            'wallet_currency' => ['nullable', 'string', 'size:3'],
            'position' => ['required', 'integer', 'min:0', 'max:10000'],
            'enabled' => ['sometimes', 'boolean'],
            'server_id' => ['nullable', 'integer', 'exists:servers,id'],
            'requires_online' => ['sometimes', 'boolean'],
        ]);
        $policy->assertManage($request->user(), isset($data['role_id']) ? (int) $data['role_id'] : null);
        $data['kind'] = $data['kind'] ?? ($existing?->kind ?? 'product');
        $data['trial_days'] = (int) ($data['trial_days'] ?? ($existing?->trial_days ?? 0));
        $data['trial_requires_payment_method'] = $request->has('trial_requires_payment_method')
            ? $request->boolean('trial_requires_payment_method')
            : $existing?->trial_requires_payment_method ?? true;
        $data['giftcard_days'] = $data['kind'] === 'giftcard' ? $data['giftcard_days'] ?? null : null;
        if ($existing && $existing->kind !== $data['kind'] && OrderItem::query()->where('product_id', $id)->exists()) {
            throw new HttpException(422, __('shop::commerce.giftcards.kind_locked'));
        }
        $data['wallet_credit_minor'] ??= 0;
        if ($data['wallet_credit_minor'] > 0) {
            if (!$request->user()->hasPermission('wallet.manage')) {
                throw new HttpException(403);
            }
            if (($data['wallet_currency'] ?? null) !== app(Wallet::class)->currency()) {
                throw new HttpException(422, __('shop::commerce.wallet.incompatible'));
            }
        }

        if ($data['kind'] === 'giftcard') {
            $data = array_replace($data, [
                'role_id' => null,
                'role_days' => null,
                'billing_interval' => null,
                'trial_days' => 0,
                'trial_requires_payment_method' => true,
                'subscription_group' => null,
                'upgrade_group' => null,
                'upgrade_weight' => 0,
                'download_path' => null,
                'wallet_credit_minor' => 0,
                'wallet_currency' => null,
                'server_id' => null,
                'commands' => '',
                'refund_commands' => '',
                'uses_codes' => false,
            ]);
            $request->files->remove('download');
        }
        if (!empty($data['server_id'])) {
            if (Server::query()->findOrFail($data['server_id'])->game !== app(GameRegistry::class)->activeId()) {
                throw new HttpException(422, __('shop::integrations.servers.invalid_server'));
            }
        }
        $effective = array_replace($existing?->getAttributes() ?? [], $data);
        if (!empty($effective['upgrade_group']) && !empty($effective['billing_interval'])) {
            throw new HttpException(422, __('shop::messages.errors.invalid_upgrade'));
        }
        if (!empty($effective['billing_interval']) && !empty($effective['role_days'])) {
            throw new HttpException(422, __('shop::messages.errors.invalid_subscription_cart'));
        }
        if (!empty($effective['billing_interval']) && ($effective['stock'] ?? null) !== null) {
            throw new HttpException(422, __('shop::messages.errors.invalid_subscription_stock'));
        }
        if ($existing && OrderItem::query()->where('product_id', $id)->exists()) {
            if (
                ($effective['upgrade_group'] ?? null) !== $existing->upgrade_group ||
                (int) $effective['upgrade_weight'] !== $existing->upgrade_weight
            ) {
                throw new HttpException(422, __('shop::commerce.upgrades.product_locked'));
            }
        }
        if (!empty($effective['upgrade_group'])) {
            if (
                !UpgradeGroup::query()
                    ->where('slug', $effective['upgrade_group'])
                    ->where('game', app(GameRegistry::class)->activeId())
                    ->where('currency', $effective['currency'])
                    ->where('enabled', true)
                    ->exists()
            ) {
                throw new HttpException(422, __('shop::commerce.upgrades.group_unavailable'));
            }
            if ($effective['wallet_credit_minor'] > 0) {
                throw new HttpException(422, __('shop::messages.errors.invalid_upgrade'));
            }
        }
        $download = $data['kind'] === 'product' ? ($data['download'] ?? null) : null;
        unset($data['download']);
        $data['description'] = Html::clean($data['description']);
        $data = app(Catalog::class)->product($request, $data, $id);
        foreach (['commands', 'refund_commands'] as $field) {
            $commands = array_values(array_filter(array_map('trim', preg_split('/\R/', $data[$field] ?? ''))));
            foreach ($commands as $command) {
                if (preg_match('/[\x00-\x1F\x7F]/', $command)) {
                    throw new HttpException(422);
                }
            }
            $data[$field] = json_encode($commands);
        }
        $extra = $request->validate([
            'custom_price' => ['sometimes', 'boolean'],
            'minimum_price_minor' => ['nullable', 'integer', 'min:1', 'max:99999999'],
            'maximum_price_minor' => ['nullable', 'integer', 'min:1', 'max:99999999'],
            'global_limit' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'limit_period' => ['nullable', 'in:day,week,month,year'],
            'variables' => ['nullable', 'array', 'max:20'],
            'server_choices' => ['nullable', 'array', 'max:100'],
            'server_choices.*' => [
                'integer',
                'distinct',
                Rule::exists('servers', 'id')->where('game', app(GameRegistry::class)->activeId()),
            ],
        ]);
        $definitions =
            $data['kind'] === 'giftcard'
                ? []
                : ($request->has('variables')
                    ? $extra['variables'] ?? []
                    : ($request->boolean('variables_present')
                        ? []
                        : $existing?->variables ?? []));
        $data['variables'] = json_encode(
            app(ProductOptions::class)->definitions(
                $definitions,
                array_merge(json_decode($data['commands'], true), json_decode($data['refund_commands'], true)),
                $existing?->variables ?? [],
            ),
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
        $data['custom_price'] = $request->has('custom_price')
            ? $request->boolean('custom_price')
            : $existing?->custom_price ?? false;
        $data['minimum_price_minor'] = $request->has('minimum_price_minor')
            ? $extra['minimum_price_minor'] ?? null
            : $existing?->minimum_price_minor;
        $data['maximum_price_minor'] = $request->has('maximum_price_minor')
            ? $extra['maximum_price_minor'] ?? null
            : $existing?->maximum_price_minor;
        $data['global_limit'] = $request->has('global_limit')
            ? $extra['global_limit'] ?? null
            : $existing?->global_limit;
        $data['limit_period'] = $request->has('limit_period')
            ? $extra['limit_period'] ?? null
            : $existing?->limit_period;
        if (!empty($effective['billing_interval'])) {
            $data['limit_per_user'] = null;
            $data['global_limit'] = null;
            $data['limit_period'] = null;
        }
        if (
            $data['custom_price'] &&
            (!empty($effective['billing_interval']) ||
                !empty($effective['upgrade_group']) ||
                ($data['minimum_price_minor'] ?? $data['price_minor']) > ($data['maximum_price_minor'] ?? 99999999))
        ) {
            throw ValidationException::withMessages([
                'custom_price' => __('shop::messages.products.options.price_configuration'),
            ]);
        }
        $data['enabled'] = $request->boolean('enabled');
        $data['uses_codes'] = $data['kind'] === 'product' && $request->boolean('uses_codes');
        $data['requires_online'] = $data['kind'] === 'product' && $request->boolean('requires_online');
        if ($data['uses_codes'] && !empty($effective['billing_interval'])) {
            throw new HttpException(422, __('shop::messages.errors.invalid_codes_subscription'));
        }
        $effective = array_replace($effective, $data);
        if ($download) {
            $effective['download_path'] = $download->getPathname();
        }
        if ($effective['trial_days'] > 0 || !empty($effective['subscription_group'])) {
            if (
                !app(\KraftInk\Plugins\Shop\Services\Orders\SubscriptionTrials::class)->reversible(
                    new Product()->newFromBuilder($effective),
                )
            ) {
                throw ValidationException::withMessages([
                    'trial_days' => __('shop::subscriptions.reversible_required'),
                ]);
            }
        }
        if (
            $existing &&
            ($effective['subscription_group'] ?? null) !== $existing->subscription_group &&
            $existing->orderItems()->exists()
        ) {
            throw ValidationException::withMessages(['subscription_group' => __('shop::subscriptions.group_locked')]);
        }
        $data['updated_at'] = now();
        $newDownload = null;
        try {
            if ($download) {
                $image = str_starts_with((string) $download->getMimeType(), 'image/')
                    ? app(Images::class)->prepareUpload($download, field: 'download')
                    : null;
                $filename = $image ? Str::random(40).'.'.$image->extension : $download->hashName();
                $newDownload = 'shop/downloads/'.$filename;
                if (!$download->storeAs('shop/downloads', $filename, 'local')) {
                    throw new RuntimeException('Cannot store product download.');
                }
                $data['download_path'] = $newDownload;
            }
            DB::transaction(function () use ($id, $data, $request, $extra) {
                if ($id) {
                    $product = Product::query()->lockForUpdate()->findOrFail($id);
                    Product::query()->where('id', $id)->update($data);
                    if (array_key_exists('download_path', $data) && $product->download_path !== $data['download_path']) {
                        $this->scheduleDownloadDeletion($product->download_path);
                    }
                } else {
                    $id = Product::query()->insertGetId($data + ['created_at' => now()]);
                }
                if (
                    $data['kind'] === 'giftcard' ||
                    $request->has('server_choices') ||
                    $request->boolean('server_choices_present')
                ) {
                    ProductServer::query()->where('product_id', $id)->delete();
                    foreach ($data['kind'] === 'giftcard' ? [] : $extra['server_choices'] ?? [] as $server) {
                        ProductServer::query()->insert(['product_id' => $id, 'server_id' => $server]);
                    }
                }
            }, 3);
        } catch (Throwable $exception) {
            $this->scheduleDownloadDeletion($newDownload);
            throw $exception;
        }
        if ($newDownload !== null && DB::transactionLevel() > 0) {
            DB::afterRollBack(fn () => $this->scheduleDownloadDeletion($newDownload));
        }

        return redirect()
            ->route('plugins.shop.admin.catalog.index')
            ->with('status', __('shop::messages.feedback.saved'));
    }

    public function deleteProduct(int $id): RedirectResponse
    {
        DB::transaction(function () use ($id): void {
            $product = Product::query()->lockForUpdate()->find($id);
            if (!$product) {
                return;
            }
            if ($product->orderItems()->exists()) {
                throw new HttpException(422, __('shop::admin.products.fields.product_used'));
            }
            Product::query()->where('id', $id)->delete();
            $this->scheduleDownloadDeletion($product->download_path);
        }, 3);

        return back();
    }

    private function scheduleDownloadDeletion(?string $path): void
    {
        if (
            $path !== null &&
            preg_match('~\Ashop/downloads/[A-Za-z0-9][A-Za-z0-9_.-]*\z~', $path) &&
            !str_contains($path, '..')
        ) {
            app(FileDeletionQueue::class)->schedule($path, 'shop/downloads', [
                'shop__products' => 'download_path',
                'shop__order_items' => 'download_path',
            ]);
        }
    }

    public function duplicateProduct(Request $request, int $id): RedirectResponse
    {
        $copyId = DB::transaction(function () use ($request, $id) {
            $product = Product::query()->where('id', $id)->lockForUpdate()->first();
            if (!($product && (!$product->game || $product->game === app(GameRegistry::class)->activeId()))) {
                abort(404);
            }
            app(RolePolicy::class)->assertManage($request->user(), $product->role_id);
            if ($product->wallet_credit_minor > 0 && !$request->user()->hasPermission('wallet.manage')) {
                throw new HttpException(403);
            }
            $data = $product->getAttributes();
            unset($data['id']);
            $data['name'] = __('shop::admin.catalog.copy_name', ['name' => $data['name']], $product->locale);
            $translations = json_decode($data['translations'] ?? '[]', true) ?? [];
            foreach ($translations as $locale => &$translation) {
                if (!empty($translation['name'])) {
                    $translation['name'] = mb_substr(
                        __('shop::admin.catalog.copy_name', ['name' => $translation['name']], $locale),
                        0,
                        180,
                    );
                }
            }
            unset($translation);
            $data['name'] = mb_substr($data['name'], 0, 180);
            $data['translations'] = json_encode($translations, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
            $catalog = app(Catalog::class);
            $data['slug'] = $catalog->productSlug($data['name']);
            $data['enabled'] = false;
            $data['game'] = app(GameRegistry::class)->activeId();
            $data['created_at'] = now();
            $data['updated_at'] = now();
            $data['search_text'] = $catalog->productSearchText(
                $data['name'],
                $data['description'],
                $translations,
            );
            $newId = Product::query()->insertGetId($data);
            foreach (ProductServer::query()->where('product_id', $id)->pluck('server_id') as $server) {
                ProductServer::query()->insert(['product_id' => $newId, 'server_id' => $server]);
            }

            return $newId;
        }, 3);

        return redirect()
            ->route('plugins.shop.admin.products.edit', $copyId)
            ->with('status', __('shop::admin.catalog.duplicated'));
    }

    public function saveCategory(Request $request, ?int $id = null): RedirectResponse
    {
        $policy = app(LocalePolicy::class);
        $locale = $id ? Category::query()->where('id', $id)->value('locale') : $policy->defaultLocale();
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'locale' => [
                'required',
                Rule::in($policy->allowsChoice() ? array_keys(config('kraft.locales')) : [$locale]),
            ],
            'position' => ['required', 'integer', 'min:0', 'max:10000'],
        ]);
        $data = app(Catalog::class)->category($request, $data, $id);
        if ($id) {
            Category::query()->where('id', $id)->update($data);
        } else {
            Category::query()->insert($data);
        }

        return back();
    }

    public function deleteCategory(int $id): RedirectResponse
    {
        Category::query()->where('id', $id)->delete();

        return back();
    }

    public function saveCoupon(Request $request, ?int $id = null): RedirectResponse
    {
        app(SiteDates::class)->normalize($request, ['starts_at', 'expires_at']);
        $request->validate(['code' => 'required|string|max:60']);
        $expiryRules = ['nullable', 'date'];
        if ($request->filled('starts_at')) {
            $expiryRules[] = 'after:starts_at';
        }
        $this->money(
            $request,
            ['minimum_amount' => 'minimum_minor'] +
                ($request->input('type') === 'fixed' ? ['discount_amount' => 'amount'] : ['percent_amount' => 'amount']),
        );
        $request->merge(['code' => strtoupper(trim($request->input('code', '')))]);
        $data = $request->validate([
            'code' => ['required', 'regex:/^[A-Z0-9_-]{3,60}$/', Rule::unique('shop__coupons')->ignore($id)],
            'type' => ['required', 'in:percent,fixed'],
            'amount' => [
                'required',
                'integer',
                'min:1',
                'max:'.($request->input('type') === 'percent' ? 10000 : 99999999),
            ],
            'currency' => ['required', Rule::in(app(CurrencyRegistry::class)->codes())],
            'minimum_minor' => ['required', 'integer', 'min:0', 'max:99999999'],
            'max_uses' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'per_user_limit' => ['nullable', 'integer', 'min:1', 'max:1000000'],
            'role_id' => ['nullable', 'integer', 'exists:roles,id'],
            'starts_at' => ['nullable', 'date'],
            'expires_at' => $expiryRules,
            'enabled' => ['sometimes', 'boolean'],
        ]);
        $data['enabled'] = $request->boolean('enabled');
        $extra = $request->validate([
            'product_ids' => ['nullable', 'array', 'max:500'],
            'product_ids.*' => ['integer', 'distinct', 'exists:shop__products,id'],
            'category_ids' => ['nullable', 'array', 'max:500'],
            'category_ids.*' => ['integer', 'distinct', 'exists:shop__categories,id'],
            'period' => ['nullable', 'in:day,week,month,year'],
        ]);
        foreach (['product_ids', 'category_ids'] as $field) {
            $data[$field] = json_encode(array_map('intval', $extra[$field] ?? []), JSON_THROW_ON_ERROR);
        }
        $data['period'] = $extra['period'] ?? null;
        $data += app(IssuedCreditScope::class)->capture($request->user());
        if ($id) {
            Coupon::query()->where('id', $id)->update($data);
        } else {
            Coupon::query()->insert($data);
        }

        return back();
    }

    public function giftcard(Request $request): RedirectResponse
    {
        app(SiteDates::class)->normalize($request, ['expires_at']);
        $this->money($request, ['balance_amount' => 'balance_minor']);
        $data = $request->validate([
            'balance_minor' => ['required', 'integer', 'min:1', 'max:99999999'],
            'currency' => ['required', Rule::in(app(CurrencyRegistry::class)->codes())],
            'expires_at' => ['nullable', 'date', 'after:now'],
        ]);
        app(SensitiveAction::class)->confirm($request);
        $code = strtoupper(Str::random(24));
        GiftCard::query()->insert(
            $data +
                app(IssuedCreditScope::class)->capture($request->user()) + [
                    'code_hash' => hash('sha256', $code),
                    'last_four' => substr($code, -4),
                    'enabled' => true,
                    'created_at' => now(),
                ],
        );

        return back()->with('status', __('shop::commerce.giftcards.giftcard_created').' '.$code);
    }

    public function revokeGiftcard(Request $request, int $id): RedirectResponse
    {
        app(SensitiveAction::class)->confirm($request);
        GiftCard::query()
            ->where('id', $id)
            ->where('enabled', true)
            ->update(['enabled' => false, 'revoked_at' => now()]);

        return back()->with('status', __('shop::messages.feedback.saved'));
    }

    public function addCodes(Request $request, int $id): RedirectResponse
    {
        if (!Product::query()->where('id', $id)->exists()) {
            abort(404);
        }
        $request->validate(['codes' => ['required', 'string', 'max:100000']]);
        $codes = array_values(
            array_unique(array_filter(array_map('trim', preg_split('/\R/', $request->input('codes'))))),
        );
        if (!(count($codes) <= 500)) {
            throw new HttpException(422);
        }
        DB::transaction(function () use ($codes, $id) {
            foreach ($codes as $code) {
                if (strlen($code) > 1000) {
                    throw new HttpException(422);
                }
                ProductCode::query()->insertOrIgnore([
                    'product_id' => $id,
                    'value' => Crypt::encryptString($code),
                    'code_hash' => hash('sha256', $code),
                    'created_at' => now(),
                ]);
            }
        });

        return back()->with('status', __('shop::messages.feedback.codes_added'));
    }

    public function settings(Request $request, GatewayRegistry $registry): RedirectResponse
    {
        if (!$request->user()->hasPermission('shop.finance.configure')) {
            throw new HttpException(403);
        }
        app(SensitiveAction::class)->confirm($request);
        $policy = app(PaymentPolicy::class);
        $rules = $policy->rules();
        foreach ($registry->all() as $id => $gateway) {
            $rules['gateways.'.$id.'.enabled'] = ['required', 'boolean'];
            foreach ($gateway->configurationFields() as $key => $field) {
                $rules['gateways.'.$id.'.'.$key] =
                    $field['rules'] ??
                    (($field['type'] ?? 'secret') === 'boolean'
                        ? ['required', 'boolean']
                        : ['nullable', 'string', 'max:1024']);
            }
        }
        $data = $request->validate($rules);
        $normalizedPolicies = $policy->normalize($data);
        DB::transaction(function () use ($data, $registry, $policy, $normalizedPolicies) {
            foreach ($registry->all() as $id => $gateway) {
                $registry->set($id, 'enabled', (string) (int) $data['gateways'][$id]['enabled']);
                foreach ($gateway->configurationFields() as $key => $field) {
                    $value = $data['gateways'][$id][$key] ?? null;
                    if (($field['type'] ?? 'secret') !== 'secret' || ($value !== null && $value !== '')) {
                        $registry->set($id, $key, (string) $value);
                    }
                }
            }
            $policy->save($normalizedPolicies);
        });
        Audit::record('shop.payment.configured', ['gateways' => array_keys($data['gateways'])], $request->user());

        return back()->with('status', __('shop::messages.feedback.saved'));
    }

    private function money(Request $request, array $fields): void
    {
        foreach ($fields as $friendly => $minor) {
            if (!$request->has($friendly)) {
                continue;
            }
            $value = $request->input($friendly);
            if (
                in_array($friendly, ['minimum_price_amount', 'maximum_price_amount'], true) &&
                ($value === '' || $value === null)
            ) {
                $request->merge([$minor => null]);

                continue;
            }
            if ($friendly === 'wallet_credit_amount' && ($value === '' || $value === null)) {
                $request->merge([$minor => 0]);

                continue;
            }
            if (!is_string($value)) {
                throw ValidationException::withMessages([$friendly => __('shop::commerce.documents.invalid_amount')]);
            }
            try {
                $request->merge([$minor => Money::parse($value)]);
            } catch (ValidationException) {
                throw ValidationException::withMessages([$friendly => __('shop::commerce.documents.invalid_amount')]);
            }
        }
    }

    public function order(string $reference): View
    {
        $order = Order::query()->where('reference', $reference)->first();
        if ($order === null) {
            abort(404);
        }
        $deliveries = TableQuery::paginate(
            $order->deliveries()->latest()->orderByDesc('id'),
            [
                'command' => 'command',
                'player_name' => 'player_name',
                'status' => 'status',
                'created_at' => 'created_at',
            ],
            pageName: 'deliveries_page',
            search: ['command', 'player_name'],
        )->withQueryString();
        $defaultServer = (int) app(Settings::class)->get('link.default_server', 0);
        $serverIds = $deliveries->pluck('server_id')->push($defaultServer)->filter()->unique()->all();
        $deliveryServers = Server::query()->whereIn('id', $serverIds)->get()->keyBy('id');
        $monitor = app(DeliveryMonitor::class);
        foreach ($deliveries as $delivery) {
            $delivery->pending_reason = $monitor->pendingReason(
                $delivery,
                $deliveryServers->get($delivery->server_id ?? $defaultServer),
            );
        }

        return view('shop::admin.orders.show', [
            'order' => $order,
            'items' => $order->items()->get(),
            'events' => $order->paymentEvents()
                ->orderByDesc('id')
                ->paginate(25, ['*'], 'events_page')
                ->withQueryString(),
            'deliveries' => $deliveries,
            'receipts' => $order->receipts()
                ->orderByDesc('id')
                ->paginate(25, ['*'], 'receipts_page')
                ->withQueryString(),
            'refunds' => $order->refunds()
                ->latest()
                ->orderByDesc('id')
                ->paginate(25, ['*'], 'refunds_page')
                ->withQueryString(),
            'disputes' => $order->disputes()
                ->orderByDesc('id')
                ->paginate(25, ['*'], 'disputes_page')
                ->withQueryString(),
            'creditRecoveries' => CreditRecovery::query()
                ->where('order_id', $order->id)
                ->orderByDesc('id')
                ->paginate(25, ['*'], 'recoveries_page')
                ->withQueryString(),
            'manualPayment' => $order->provider === 'manual' ? ManualPayment::query()->where('order_id', $order->id)->first() : null,
            'manualRefunds' => $order->provider === 'manual'
                    ? ManualRefund::query()
                        ->where('order_id', $order->id)
                        ->orderByDesc('id')
                        ->paginate(25, ['*'], 'manual_refunds_page')
                        ->withQueryString()
                    : collect(),
            'financialEvents' => FinancialEvent::query()
                ->where('order_id', $order->id)
                ->orderByDesc('id')
                ->paginate(25, ['*'], 'financial_events_page')
                ->withQueryString(),
        ]);
    }

    public function cancel(int $id, OrderService $orders): RedirectResponse
    {
        $order = Order::query()->find($id);
        if (!($order && $order->status === 'pending' && $order->auto_expire && now()->gte($order->expires_at))) {
            throw new HttpException(422, __('shop::messages.site.orders.wait_expiry'));
        }
        $orders->cancel($id);

        return back()->with('status', __('shop::messages.feedback.saved'));
    }
}
