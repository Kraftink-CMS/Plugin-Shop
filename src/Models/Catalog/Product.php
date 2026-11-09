<?php

namespace KraftInk\Plugins\Shop\Models\Catalog;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;
use App\Kraft\Auth\PermissionAccess;
use App\Kraft\Games\GameRegistry;
use App\Models\Accounts\Role;
use App\Models\Accounts\User;
use App\Models\Media\MediaAsset;
use App\Models\Servers\Server;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use KraftInk\Plugins\Shop\Models\Orders\OrderItem;
use KraftInk\Plugins\Shop\Models\Orders\Subscription;

/**
 * @mixin Builder<static>
 *
 * @property int $id
 * @property int|null $category_id
 * @property int|null $role_id
 * @property int|null $server_id
 * @property int|null $image_id
 * @property string $kind
 * @property string|null $game
 * @property string $locale
 * @property string|null $slug
 * @property string $name
 * @property string $description
 * @property array|null $translations
 * @property string|null $search_text
 * @property int $price_minor
 * @property string $currency
 * @property bool $custom_price
 * @property int|null $minimum_price_minor
 * @property int|null $maximum_price_minor
 * @property int $wallet_credit_minor
 * @property string|null $wallet_currency
 * @property int|null $stock
 * @property int|null $limit_per_user
 * @property int|null $global_limit
 * @property string|null $limit_period
 * @property string|null $required_permission
 * @property string|null $billing_interval
 * @property int|null $role_days
 * @property string|null $upgrade_group
 * @property int $upgrade_weight
 * @property string|null $commands
 * @property string|null $refund_commands
 * @property array|null $variables
 * @property string|null $download_path
 * @property bool $uses_codes
 * @property int|null $giftcard_days
 * @property bool $requires_online
 * @property bool $enabled
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Category|string|null $category
 * @property-read Server|null $server
 * @property-read Collection<int, Server> $servers
 */
class Product extends Model
{
    protected $table = 'shop__products';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'category_id' => 'integer',
            'role_id' => 'integer',
            'server_id' => 'integer',
            'image_id' => 'integer',
            'translations' => 'array',
            'price_minor' => 'integer',
            'custom_price' => 'boolean',
            'minimum_price_minor' => 'integer',
            'maximum_price_minor' => 'integer',
            'wallet_credit_minor' => 'integer',
            'stock' => 'integer',
            'limit_per_user' => 'integer',
            'global_limit' => 'integer',
            'role_days' => 'integer',
            'trial_days' => 'integer',
            'trial_requires_payment_method' => 'boolean',
            'upgrade_weight' => 'integer',
            'variables' => 'array',
            'uses_codes' => 'boolean',
            'giftcard_days' => 'integer',
            'requires_online' => 'boolean',
            'enabled' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'category_id');
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'role_id');
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class, 'server_id');
    }

    public function image(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'image_id');
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class, 'product_id');
    }

    public function codes(): HasMany
    {
        return $this->hasMany(ProductCode::class, 'product_id');
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class, 'product_id');
    }

    public function quantityLimit(): int
    {
        if ($this->billing_interval) {
            return 1;
        }

        return max(0, min(99, $this->stock ?? 99, $this->limit_per_user ?? 99, $this->global_limit ?? 99));
    }

    public function servers(): BelongsToMany
    {
        return $this->belongsToMany(Server::class, 'shop__product_servers', 'product_id', 'server_id')->using(
            ProductServer::class,
        );
    }

    public function scopeActiveGame(Builder $query): void
    {
        $game = app(GameRegistry::class)->activeId();
        $query
            ->where(
                fn (Builder $query) => $query->whereNull('shop__products.game')->orWhere('shop__products.game', $game),
            )
            ->where(
                fn (Builder $query) => $query
                    ->whereNull('shop__products.server_id')
                    ->orWhereIn('shop__products.server_id', Server::query()->where('game', $game)->select('id')),
            );
    }

    public function scopeVisibleTo(Builder $query, ?User $user): void
    {
        $query->activeGame()->where('shop__products.enabled', true);
        $access = app(PermissionAccess::class);
        $access->filterQuery($query, 'shop__products.required_permission', $access->permissions($user), 'shop.view', allowEmpty: false);
    }
}
