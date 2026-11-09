@use('Illuminate\Support\Collection')
@use('KraftInk\Plugins\Shop\Models\Catalog\Category')
@use('App\Kraft\LocalePolicy')
@php
    /** @var Collection<int, Category> $categories */
@endphp
@extends('admin.layouts.layout')
@section('title', $category ? __('shop::admin.categories.edit_category') . ' · ' . $category->name :
    __('shop::admin.navigation.add.categories'))
@section('actions')
    <a class="btn btn-outline-secondary" href="{{ route('plugins.shop.admin.categories.index') }}">
        {{ __('shop::admin.catalog.categories') }}</a>
@endsection
@section('content')
    @php
        $value = fn($key, $fallback = null) => old($key, $category?->{$key} ?? $fallback);
        $translationsEnabled = app(LocalePolicy::class)->allowsChoice();
    @endphp
    <form data-ajax="true" data-redirect-on-upload="true"
        data-redirect-url="{{ $category ? '' : route('plugins.shop.admin.categories.index') }}" method="post"
        action="{{ $category ? route('plugins.shop.admin.categories.update', $category->id) : route('plugins.shop.admin.categories.store') }}"
        enctype="multipart/form-data" data-shop-category data-shop-errors='@json($errors->keys())'>
        @csrf
        @if ($category)
            @method('PUT')
        @endif
        <section class="card">
            <div class="card-header p-0">
                <ul class="nav nav-tabs card-header-tabs m-0" role="tablist">
                    @foreach (['details', 'translations'] as $panel)
                        @continue($panel === 'translations' && !$translationsEnabled)
                        <li class="nav-item" role="presentation">
                            <button @class(['nav-link', 'active' => $loop->first]) id="category-tab-{{ $panel }}" type="button"
                                data-bs-toggle="tab" data-bs-target="#category-{{ $panel }}" role="tab"
                                aria-controls="category-{{ $panel }}"
                                aria-selected="{{ $loop->first ? 'true' : 'false' }}">{{ __('shop::admin.products.tabs.' . $panel) }}</button>
                        </li>
                    @endforeach
                </ul>
            </div>
            <div class="card-body tab-content">
                <div class="tab-pane fade show active" id="category-details" role="tabpanel"
                    aria-labelledby="category-tab-details">
                    <div class="row g-3 mb-3">
                        <div class="col-md-8">
                            <label class="form-label" for="category_name">{{ __('shop::messages.labels.name') }}</label>
                            <input class="form-control" id="category_name" name="name" maxlength="120"
                                value="{{ $value('name') }}" required>
                        </div>
                        <div class="col-md-4">
                            @if ($translationsEnabled)
                                <label class="form-label"
                                    for="category_locale">{{ __('shop::admin.products.fields.primary_language') }}</label>
                                <select class="form-select" id="category_locale" name="locale" data-shop-locale>
                                    @foreach (config('kraft.locales') as $code => $label)
                                        <option value="{{ $code }}" @selected($value('locale', app()->getLocale()) === $code)>
                                            {{ $label }}
                                        </option>
                                    @endforeach
                                </select>
                            @else
                                <input type="hidden" name="locale"
                                    value="{{ $category?->locale ?? app(LocalePolicy::class)->defaultLocale() }}">
                            @endif
                        </div>
                    </div>
                    <div class="row g-3 mb-3">
                        <div class="col-md-8">
                            <label class="form-label"
                                for="parent_id">{{ __('shop::admin.categories.parent_category') }}</label>
                            <select class="form-select" id="parent_id" name="parent_id">
                                <option value="">{{ __('shop::admin.categories.no_parent') }}</option>
                                @foreach ($categories as $parent)
                                    <option value="{{ $parent->id }}" @selected((int) $value('parent_id') === $parent->id)>{{ $parent->name }}
                                        @if ($translationsEnabled)
                                            · {{ strtoupper($parent->locale) }}
                                        @endif
                                    </option>
                                @endforeach
                            </select>
                            <div class="form-text">
                                {{ __('shop::admin.categories.parent_help') }}
                            </div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label"
                                for="category_position">{{ __('shop::messages.labels.position') }}</label>
                            <input class="form-control" id="category_position" name="position" type="number" min="0"
                                max="10000" value="{{ $value('position', 0) }}" required>
                        </div>
                    </div>
                    <div class="mb-4">
                        <label class="form-label"
                            for="category_description">{{ __('shop::messages.labels.description') }}</label>
                        <textarea class="form-control" id="category_description" name="description" rows="10" maxlength="10000"
                            data-editor="tinymce">{{ $value('description') }}</textarea>
                    </div>
                    <input type="hidden" name="exclusive" value="0">
                    <div class="form-check mb-4">
                        <input class="form-check-input" id="category_exclusive" name="exclusive" type="checkbox"
                            value="1" @checked($value('exclusive', false))>
                        <label class="form-check-label"
                            for="category_exclusive">{{ __('shop::admin.categories.exclusive_category') }}</label>
                        <div class="form-text">
                            {{ __('shop::admin.categories.exclusive_help') }}
                        </div>
                    </div>
                    @include('shop::admin.catalog.partials.image_fields', ['imageRecord' => $category])
                </div>
                @if ($translationsEnabled)
                    <div class="tab-pane fade" id="category-translations" role="tabpanel"
                        aria-labelledby="category-tab-translations">
                        @include('shop::admin.catalog.partials.translation_fields', [
                            'translationRecord' => $category,
                            'translationLimit' => 10000,
                            'translationNameLimit' => 120,
                        ])
                    </div>
                @endif
            </div>
            <div class="card-footer">
                <button class="btn btn-primary" type="submit">{{ __('shop::messages.actions.save') }}</button>
            </div>
        </section>
    </form>
    @push('scripts')
        <script type="module" src="{{ route('site.extensions.assets.show', ['id' => 'shop', 'asset' => 'admin.js']) }}">
        </script>
    @endpush
@endsection
