<?php

namespace KraftInk\Plugins\Shop\Models\Catalog;

use Illuminate\Database\Eloquent\Builder;
use App\Models\Media\MediaAsset;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Validation\ValidationException;

/**
 * @mixin Builder<static>
 *
 * @property int $id
 * @property int|null $parent_id
 * @property int|null $image_id
 * @property string $name
 * @property string $locale
 * @property string|null $description
 * @property array|null $translations
 * @property bool $exclusive
 * @property int $position
 * @property-read Category|null $parent
 */
class Category extends Model
{
    protected $table = 'shop__categories';

    protected $guarded = ['id'];

    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'parent_id' => 'integer',
            'image_id' => 'integer',
            'translations' => 'array',
            'exclusive' => 'boolean',
            'position' => 'integer',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function image(): BelongsTo
    {
        return $this->belongsTo(MediaAsset::class, 'image_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class, 'category_id');
    }

    public static function descendantIds(int $id): array
    {
        $all = [$id];
        $level = $all;
        for ($depth = 0; $level; $depth++) {
            if ($depth >= 20 || count($all) > 5000) {
                throw ValidationException::withMessages(['category' => __('shop::admin.catalog.invalid_parent')]);
            }
            $level = self::query()
                ->whereIn('parent_id', $level)
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->diff($all)
                ->values()
                ->all();
            $all = array_merge($all, $level);
        }

        return $all;
    }
}
