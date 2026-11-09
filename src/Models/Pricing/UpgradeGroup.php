<?php

namespace KraftInk\Plugins\Shop\Models\Pricing;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * @mixin Builder<static>
 *
 * @property int $id
 * @property string $slug
 * @property string $name
 * @property string $game
 * @property string $currency
 * @property string $strategy
 * @property string $expired_policy
 * @property bool $enabled
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class UpgradeGroup extends Model
{
    protected $table = 'shop__upgrade_groups';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
        ];
    }

    public function states(): HasMany
    {
        return $this->hasMany(UpgradeState::class, 'group_id');
    }
}
