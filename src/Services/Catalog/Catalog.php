<?php

namespace KraftInk\Plugins\Shop\Services\Catalog;

use Illuminate\Support\Facades\Validator;
use App\Kraft\Games\GameRegistry;
use App\Kraft\Html;
use App\Kraft\LocalePolicy;
use App\Kraft\Media\MediaLibrary;
use App\Kraft\Media\MediaSelection;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use KraftInk\Plugins\Shop\Models\Catalog\Category;
use KraftInk\Plugins\Shop\Models\Catalog\Product;

class Catalog
{
    public function product(Request $request, array $data, ?int $id): array
    {
        $productOptionsValidator = Validator::make($request->all(), [
            'slug' => [
                'nullable',
                'string',
                'max:180',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/D',
                Rule::unique('shop__products')->where('game', app(GameRegistry::class)->activeId())->ignore($id),
            ],
            ...$this->imageRules($request),
            'translations' => app(LocalePolicy::class)->allowsChoice()
                ? ['nullable', 'array:'.implode(',', array_keys(config('kraft.locales')))]
                : ['prohibited'],
            'translations.*' => ['array:name,description'],
            'translations.*.name' => ['nullable', 'string', 'max:180'],
            'translations.*.description' => ['nullable', 'string', 'max:100000'],
        ]);
        $extra = $productOptionsValidator->validate();
        $existing = $id ? Product::query()->select('slug', 'translations')->find($id) : null;
        $translations = $existing?->translations ?? [];
        if (app(LocalePolicy::class)->allowsChoice()) {
            $translations = $this->translations($extra['translations'] ?? $translations);
        }
        $data['translations'] = json_encode($translations, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        $data['game'] = app(GameRegistry::class)->activeId();
        $data['slug'] = $extra['slug'] ?? $existing?->slug ?? $this->productSlug($data['name']);
        if ($request->has('image_id')) {
            $data['image_id'] = $extra['image_id'] ?? null;
        }
        if ($request->hasFile('image')) {
            $data['image_id'] = app(MediaLibrary::class)->store(
                $request->file('image'),
                $request->user()->id,
                $data['name'],
            )->id;
        }
        $data['search_text'] = $this->productSearchText($data['name'], $data['description'], $translations);

        return $data;
    }

    public function productSlug(string $name, ?string $identifier = null): string
    {
        $suffix = '-'.($identifier ?? Str::lower(Str::random(8)));
        $base = rtrim(substr(Str::slug($name), 0, 180 - strlen($suffix)), '-');

        return ($base ?: 'product').$suffix;
    }

    public function category(Request $request, array $data, ?int $id): array
    {
        $categoryOptionsValidator = Validator::make($request->all(), [
            'parent_id' => ['nullable', 'integer', 'exists:shop__categories,id'],
            ...$this->imageRules($request),
            'description' => ['nullable', 'string', 'max:10000'],
            'translations' => app(LocalePolicy::class)->allowsChoice()
                ? ['nullable', 'array:'.implode(',', array_keys(config('kraft.locales')))]
                : ['prohibited'],
            'translations.*' => ['array:name,description'],
            'translations.*.name' => ['nullable', 'string', 'max:120'],
            'translations.*.description' => ['nullable', 'string', 'max:10000'],
            'exclusive' => ['sometimes', 'boolean'],
        ]);
        $extra = $categoryOptionsValidator->validate();
        $existing = $id
            ? Category::query()->select('parent_id', 'translations', 'description', 'exclusive')->find($id)
            : null;
        $parentId = $request->has('parent_id') ? $extra['parent_id'] ?? null : $existing?->parent_id;
        $parent = $parentId;
        $seen = $id ? [$id] : [];
        for ($depth = 0; $parent; $depth++) {
            if ($depth >= 19 || in_array((int) $parent, $seen, true)) {
                throw ValidationException::withMessages(['parent_id' => __('shop::admin.catalog.invalid_parent')]);
            }
            $seen[] = (int) $parent;
            $parent = Category::query()->where('id', $parent)->value('parent_id');
        }
        $level = $id ? [$id] : [];
        for (; $level; $depth++) {
            if ($depth >= 20) {
                throw ValidationException::withMessages(['parent_id' => __('shop::admin.catalog.invalid_parent')]);
            }
            $level = Category::query()->whereIn('parent_id', $level)->pluck('id')->all();
        }
        $data['parent_id'] = $parentId;
        $data['description'] = Html::clean($extra['description'] ?? ($existing?->description ?? ''));
        $data['translations'] = json_encode(
            app(LocalePolicy::class)->allowsChoice()
                ? $this->translations($extra['translations'] ?? ($existing?->translations ?? []))
                : $existing?->translations ?? [],
            JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
        $data['exclusive'] = $request->boolean('exclusive');
        if ($request->has('image_id')) {
            $data['image_id'] = $extra['image_id'] ?? null;
        }
        if ($request->hasFile('image')) {
            $data['image_id'] = app(MediaLibrary::class)->store(
                $request->file('image'),
                $request->user()->id,
                $data['name'],
            )->id;
        }

        return $data;
    }

    public function categories(): Collection
    {
        $categories = Category::query()
            ->orderBy('position')
            ->orderBy('id')
            ->get()
            ->map(fn ($category) => $this->localize($category));
        $parents = $categories->pluck('parent_id', 'id');
        foreach ($categories as $category) {
            $depth = 0;
            $parent = $category->parent_id;
            $seen = [$category->id];
            while ($parent && $depth < 20 && !in_array($parent, $seen, true)) {
                $depth++;
                $seen[] = $parent;
                $parent = $parents[$parent] ?? null;
            }
            $category->depth = $depth;
        }

        return $categories;
    }

    private function imageRules(Request $request): array
    {
        $selection = app(MediaSelection::class);
        $uploading = $request->hasFile('image');

        return [
            'image_id' => [
                'bail',
                'nullable',
                'integer',
                function (string $attribute, mixed $id, Closure $fail) use ($selection, $uploading): void {
                    if (!$uploading && !$selection->selected($id, 'shop-image')) {
                        $fail('validation.exists')->translate();
                    }
                },
            ],
            'image' => $selection->rules('shop-image', required: false),
        ];
    }

    /**
     * @template TRecord of Category|Product
     *
     * @param  TRecord  $record
     * @return TRecord
     */
    public function localize(Category|Product $record): Category|Product
    {
        $localized = clone $record;
        if (!app(LocalePolicy::class)->allowsChoice()) {
            return $localized;
        }
        $translations = $record->translations ?? [];
        $locales = array_unique([app()->getLocale(), 'en', app(LocalePolicy::class)->defaultLocale()]);
        foreach (['name', 'description'] as $field) {
            foreach ($locales as $locale) {
                $value = $translations[$locale][$field] ?? null;
                if (!$this->hasContent($value, $field === 'description') && ($record->locale ?? null) === $locale) {
                    $value = $record->$field ?? null;
                }
                if ($this->hasContent($value, $field === 'description')) {
                    $localized->$field = $value;
                    break;
                }
            }
        }

        return $localized;
    }

    public function searchText(string $text): string
    {
        return Str::lower(Str::ascii(preg_replace('/\s+/u', ' ', $text) ?? $text));
    }

    public function productSearchText(string $name, string $description, array $translations): string
    {
        $parts = [$name, strip_tags($description)];
        foreach ($translations as $translation) {
            $parts[] = $translation['name'] ?? '';
            $parts[] = strip_tags($translation['description'] ?? '');
        }

        return $this->searchText(implode(' ', $parts));
    }

    private function translations(array $translations): array
    {
        $normalized = [];
        foreach ($translations as $locale => $translation) {
            foreach (['name', 'description'] as $field) {
                $value =
                    $field === 'description'
                        ? Html::clean($translation[$field] ?? '')
                        : trim($translation[$field] ?? '');
                if ($this->hasContent($value, $field === 'description')) {
                    $normalized[$locale][$field] = $value;
                }
            }
        }

        return $normalized;
    }

    private function hasContent(?string $value, bool $html): bool
    {
        $value ??= '';
        if ($html && preg_match('/<img\b/i', $value)) {
            return true;
        }

        return preg_match(
            '/[^\s\p{Z}]/u',
            html_entity_decode($html ? strip_tags($value) : $value, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
        ) === 1;
    }
}
