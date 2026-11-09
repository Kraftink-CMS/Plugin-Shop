@php
    $shopTranslations = old('translations', ($translationRecord?->translations ?? [])) ?? [];
@endphp
<p class="text-body-secondary">{{ __('shop::admin.translations.translations_help') }}</p>
<div class="accordion" id="shop-translations">
    @foreach (config('kraft.locales') as $code => $label)
        @php
            $translationErrors = $errors->has('translations.' . $code . '*');
            $translationConfigured =
                filled($shopTranslations[$code]['name'] ?? null) ||
                filled($shopTranslations[$code]['description'] ?? null);
        @endphp
        <div class="accordion-item" data-shop-translation="{{ $code }}" data-completion-group>
            <h3 class="accordion-header">
                <button @class(['accordion-button', 'collapsed' => !$translationErrors]) type="button" id="shop-translation-heading-{{ $code }}"
                    data-bs-toggle="collapse" data-bs-target="#shop-translation-{{ $code }}"
                    aria-controls="shop-translation-{{ $code }}"
                    aria-expanded="{{ $translationErrors ? 'true' : 'false' }}">
                    <span class="d-flex flex-wrap align-items-center gap-2">
                        <span>{{ $label }}</span>
                        <span @class([
                            'badge',
                            'text-bg-success' => $translationConfigured,
                            'text-bg-secondary' => !$translationConfigured,
                        ]) data-completion-status
                            data-filled-label="{{ __('shop::admin.translations.translation_configured') }}"
                            data-empty-label="{{ __('shop::admin.translations.translation_fallback') }}"
                            aria-live="polite">{{ __('shop::admin.translations.' . ($translationConfigured ? 'translation_configured' : 'translation_fallback')) }}</span>
                    </span>
                </button>
            </h3>
            <div @class(['accordion-collapse collapse', 'show' => $translationErrors]) id="shop-translation-{{ $code }}"
                aria-labelledby="shop-translation-heading-{{ $code }}">
                <div class="accordion-body">
                    <div class="mb-3">
                        <label class="form-label" for="translation_name_{{ $code }}">
                            {{ __('shop::messages.labels.name') }} <span
                                class="text-body-secondary">{{ __('shop::admin.translations.optional') }}</span>
                        </label>
                        <input @class([
                            'form-control',
                            'is-invalid' => $errors->has('translations.' . $code . '.name'),
                        ]) id="translation_name_{{ $code }}"
                            name="translations[{{ $code }}][name]" lang="{{ $code }}"
                            maxlength="{{ $translationNameLimit ?? 180 }}"
                            value="{{ $shopTranslations[$code]['name'] ?? '' }}"
                            @error('translations.' . $code . '.name') aria-invalid="true" aria-describedby="translation_name_{{ $code }}_error" @enderror>
                        @error('translations.' . $code . '.name')
                            <div class="invalid-feedback" id="translation_name_{{ $code }}_error">
                                {{ $message }}</div>
                        @enderror
                    </div>
                    <div>
                        <label class="form-label" for="translation_description_{{ $code }}">
                            {{ __('shop::messages.labels.description') }} <span
                                class="text-body-secondary">{{ __('shop::admin.translations.optional') }}</span>
                        </label>
                        <textarea @class([
                            'form-control',
                            'is-invalid' => $errors->has('translations.' . $code . '.description'),
                        ]) id="translation_description_{{ $code }}"
                            name="translations[{{ $code }}][description]" lang="{{ $code }}" rows="8"
                            data-editor="tinymce" maxlength="{{ $translationLimit }}"
                            @error('translations.' . $code . '.description') aria-invalid="true" aria-describedby="translation_description_{{ $code }}_error" @enderror>{{ $shopTranslations[$code]['description'] ?? '' }}</textarea>
                        @error('translations.' . $code . '.description')
                            <div class="invalid-feedback" id="translation_description_{{ $code }}_error">
                                {{ $message }}</div>
                        @enderror
                    </div>
                </div>
            </div>
        </div>
    @endforeach
</div>
