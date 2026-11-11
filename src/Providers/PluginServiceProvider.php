<?php

namespace KraftInk\Plugins\Shop\Providers;

use App\Events\AcknowledgingLinkCommand;
use App\Events\CollectingLinkCommands;
use App\Events\CollectingLinkDeliverySources;
use App\Events\CollectingRoleAssignments;
use App\Events\CollectingSeoPages;
use App\Events\PruningData;
use App\Events\ResolvingUserRoles;
use App\Kraft\Events\PluginEvents;
use App\Kraft\Extensions\SlotRegistry;
use App\Kraft\Features;
use App\Kraft\Migration\MappingRegistry;
use App\Kraft\Notifications\NotificationRegistry;
use App\Kraft\Widgets\WidgetRegistry;
use App\Models\Accounts\User;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\View\View;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use KraftInk\Plugins\Shop\Console\Commands\ProcessTasks;
use KraftInk\Plugins\Shop\Listeners\DataRetention;
use KraftInk\Plugins\Shop\Listeners\Link\DeliveryListener;
use KraftInk\Plugins\Shop\Listeners\Link\DeliveryMonitor;
use KraftInk\Plugins\Shop\Models\Catalog\Product;
use KraftInk\Plugins\Shop\Models\Orders\Order;
use KraftInk\Plugins\Shop\Services\Catalog\Catalog;
use KraftInk\Plugins\Shop\Services\Imports\ImportMappings;
use KraftInk\Plugins\Shop\Services\Payments\GatewayRegistry;
use KraftInk\Plugins\Shop\Services\Payments\Gateways\GiftCardGateway;
use KraftInk\Plugins\Shop\Services\Payments\Gateways\ManualGateway;
use KraftInk\Plugins\Shop\Services\Payments\Gateways\PayPalGateway;
use KraftInk\Plugins\Shop\Services\Payments\Gateways\PaymentwallGateway;
use KraftInk\Plugins\Shop\Services\Payments\Gateways\PaysafecardGateway;
use KraftInk\Plugins\Shop\Services\Payments\Gateways\StripeGateway;
use KraftInk\Plugins\Shop\Services\Payments\Gateways\WalletGateway;
use KraftInk\Plugins\Shop\Services\Pricing;
use KraftInk\Plugins\Shop\Services\Roles\RoleAssignments;
use KraftInk\Plugins\Shop\View\AccountPanel;
use KraftInk\Plugins\Shop\View\Composers\PublicSales;
use KraftInk\Plugins\Shop\View\Composers\PublicViews;
use App\Kraft\Media\MediaSelection;

class PluginServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singletonIf(Pricing\PricingRegistry::class);
        $pricing = $this->app->make(Pricing\PricingRegistry::class);
        $pricing->register('net-paid', Pricing\Strategies\NetPaid::class);
        $pricing->register('full-price', Pricing\Strategies\FullPrice::class);
        $this->app->singletonIf(GatewayRegistry::class);
        $registry = $this->app->make(GatewayRegistry::class);
        $registry->register('stripe', StripeGateway::class);
        $registry->register('paypal', PayPalGateway::class);
        $registry->register('paysafecard', PaysafecardGateway::class);
        $registry->register('paymentwall', PaymentwallGateway::class);
        $registry->register('giftcard', GiftCardGateway::class);
        $registry->register('wallet', WalletGateway::class);
        $registry->register('manual', ManualGateway::class);
    }

    public function boot(): void
    {
        RateLimiter::for('shop.payment-setup', fn (Request $request) => Limit::perMinute(10)->by(
            $request->route()->getName().':'.$request->route('provider').':'.($request->user()?->id ?? $request->ip()),
        ));
        app(MediaSelection::class)->register('shop-image', 'shop.manage', 'image');
        $this->registerEvents(app(PluginEvents::class));
        $this->registerNotifications(app(NotificationRegistry::class));
        $this->registerWidgets(app(WidgetRegistry::class));
        app(Features::class)->register('shop', fn ($user) => Product::query()->visibleTo($user)->exists());
        app(Features::class)->register('shop.account', fn ($user) => AccountPanel::forUser($user)->isAvailable());
        app(SlotRegistry::class)->add(
            'shop',
            'account.sections',
            'shop',
            fn (array $context) => AccountPanel::section($context),
        );
        Event::listen(CollectingRoleAssignments::class, [RoleAssignments::class, 'collect']);
        Event::listen(PruningData::class, [DataRetention::class, 'prune']);
        app(SlotRegistry::class)->add(
            'shop',
            'admin.dashboard.metrics',
            'sales',
            fn (array $context) => $this->sales($context),
            permission: 'shop.manage',
        );
        Event::listen(CollectingLinkCommands::class, [DeliveryListener::class, 'collect']);
        Event::listen(CollectingLinkDeliverySources::class, [DeliveryMonitor::class, 'collect']);
        Event::listen(AcknowledgingLinkCommand::class, [DeliveryListener::class, 'acknowledge']);
        app(ImportMappings::class)->register(app(MappingRegistry::class));
        Event::listen(ResolvingUserRoles::class, function (ResolvingUserRoles $event) {
            $event->roles = $event->roles->merge(app(RoleAssignments::class)->roles($event->user));
        });
        $this->loadViewsFrom(__DIR__.'/../../resources/views', 'shop');
        view()->composer('shop::default.catalog.index', PublicSales::class);
        view()->composer(
            [
                'shop::default.cart.index',
                'shop::default.subscriptions.checkout',
                'shop::default.orders.show',
                'shop::default.orders.index',
            ],
            PublicViews::class,
        );
        $this->loadTranslationsFrom(__DIR__.'/../../lang', 'shop');
        Event::listen(CollectingSeoPages::class, function (CollectingSeoPages $event): void {
            $event->add(
                'plugins.shop.public.catalog.index',
                __('shop::seo.pages.catalog'),
                [
                    'category' => __('shop::seo.variables.category'),
                    'category_description' => __('shop::seo.variables.category_description'),
                    'products_count' => __('shop::seo.variables.products_count'),
                ],
                defaults: ['title' => '{category}', 'description' => '{description}'],
                excerptVariables: ['category_description'],
            );
            $event->add(
                'plugins.shop.public.products.show',
                __('shop::seo.pages.product'),
                [
                    'product' => __('shop::seo.variables.product'),
                    'product_description' => __('shop::seo.variables.product_description'),
                    'price' => __('shop::seo.variables.price'),
                    'currency' => __('shop::seo.variables.currency'),
                ],
                defaults: ['title' => '{product}', 'description' => '{description}'],
                excerptVariables: ['product_description'],
            );
        });
        $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
        $this->loadRoutesFrom(__DIR__.'/../../routes/web.php');
        $this->loadRoutesFrom(__DIR__.'/../../routes/admin.php');
        $this->loadRoutesFrom(__DIR__.'/../../routes/api.php');
        $this->commands([ProcessTasks::class]);
        $this->app->afterResolving(
            Schedule::class,
            fn (Schedule $schedule) => $schedule->command('shop:process')->everyMinute()->withoutOverlapping(10),
        );
    }

    private function registerEvents(PluginEvents $events): void
    {
        $events->define('shop.order.paid');
        $events->define('shop.refund.succeeded');
    }

    private function registerNotifications(NotificationRegistry $registry): void
    {
        foreach (['paid', 'review', 'refunded'] as $kind) {
            $registry->register(
                'shop',
                'shop.order.'.$kind,
                'shop::integrations.notifications.'.$kind.'_title',
                function (array $data) use ($kind): array {
                    $order = Order::query()->find($data['order_id']);

                    return [
                        'title' => __('shop::integrations.notifications.'.$kind.'_title'),
                        'body' => e(
                            __('shop::integrations.notifications.'.$kind.'_body', ['number' => $order->id]),
                        ),
                        'url' => route('plugins.shop.public.orders.show', $order->reference),
                    ];
                },
                fn (?User $user, array $data): bool => $user &&
                    Order::query()->where('id', $data['order_id'])->where('user_id', $user->id)->exists(),
            );
        }
    }

    private function sales(array $context = []): View
    {
        $count = Order::usable()->whereNotNull('paid_at')->count();

        return view('shop::admin.dashboard.sales', compact('count'));
    }

    private function registerWidgets(WidgetRegistry $widgets): void
    {
        $widgets->register(
            owner: 'shop',
            id: 'products',
            label: 'shop::widgets.products',
            render: fn (array $context, array $config) => view('shop::widgets.products', [
                'products' => Product::query()
                    ->visibleTo($context['user'] ?? null)
                    ->orderBy('shop__products.position')
                    ->orderBy('shop__products.id')
                    ->limit($config['limit'])
                    ->get([
                        'shop__products.id',
                        'shop__products.name',
                        'shop__products.slug',
                        'shop__products.locale',
                        'shop__products.translations',
                    ])
                    ->map(fn ($product) => app(Catalog::class)->localize($product)),
            ]),
            fields: ['limit' => ['type' => 'integer', 'label' => 'shop::widgets.count', 'min' => 1, 'max' => 12]],
            permission: 'shop.view',
            zones: ['home'],
            defaults: ['limit' => 4],
        );
    }
}
