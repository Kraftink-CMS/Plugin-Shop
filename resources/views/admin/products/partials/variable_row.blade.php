@use('App\Kraft\LocalePolicy')
@php
    $variablePolicy = app(LocalePolicy::class);
    $variableTranslations = $variablePolicy->allowsChoice();
    $variableLocales = $variableTranslations
        ? config('kraft.locales')
        : [$variablePolicy->defaultLocale() => config('kraft.locales')[$variablePolicy->defaultLocale()]];
@endphp
<fieldset class="border rounded p-3" data-variable-row>
    <legend class="float-none w-auto fs-6 px-2">{{ __('shop::admin.products.variables.variable') }}</legend>
    <div class="d-flex justify-content-end mb-2">
        <button class="btn btn-sm btn-outline-danger" type="button"
            data-variable-remove>{{ __('common.actions.delete') }}</button>
    </div>
    <div class="row g-3 mb-3">
        <div class="col-md-6">
            <label class="form-label"
                for="variable-key-{{ $index }}">{{ __('shop::admin.products.variables.variable_key') }}</label>
            <input class="form-control font-monospace" id="variable-key-{{ $index }}"
                name="variables[{{ $index }}][key]" value="{{ $definition['key'] ?? '' }}" maxlength="32"
                pattern="[a-z][a-z0-9_]{0,31}" required>
            <div class="form-text">
                {{ __('shop::admin.products.variables.variable_key_help') }}
            </div>
        </div>
        <div class="col-md-6">
            <label class="form-label"
                for="variable-type-{{ $index }}">{{ __('shop::admin.products.variables.variable_type') }}</label>
            <select class="form-select" id="variable-type-{{ $index }}"
                name="variables[{{ $index }}][type]" data-variable-type>
                @foreach (['text', 'integer', 'choice'] as $type)
                    <option value="{{ $type }}" @selected(($definition['type'] ?? 'text') === $type)>
                        {{ __('shop::admin.products.variables.types.' . $type) }}</option>
                @endforeach
            </select>
        </div>
    </div>
    @if ($variableTranslations)
        <p class="form-label mb-1">{{ __('shop::admin.translations.variable_labels') }}</p>
        <p class="form-text mt-0">{{ __('shop::admin.translations.variable_labels_help') }}</p>
    @endif
    <div @class(['mb-3', 'accordion' => $variableTranslations])>
        @foreach ($variableLocales as $code => $label)
            @php
                $labelError = $errors->has('variables.' . $index . '.label.' . $code);
            @endphp
            <div @class(['accordion-item' => $variableTranslations]) @if ($variableTranslations) data-completion-group @endif>
                @if ($variableTranslations)
                    <h3 class="accordion-header">
                        <button @class(['accordion-button', 'collapsed' => !$labelError]) type="button"
                            id="variable-label-heading-{{ $index }}-{{ $code }}"
                            data-bs-toggle="collapse"
                            data-bs-target="#variable-label-panel-{{ $index }}-{{ $code }}"
                            aria-controls="variable-label-panel-{{ $index }}-{{ $code }}"
                            aria-expanded="{{ $labelError ? 'true' : 'false' }}">
                            <span class="d-flex flex-wrap align-items-center gap-2">
                                <span>{{ $label }}</span>
                                <span @class([
                                    'badge',
                                    'text-bg-success' => filled($definition['label'][$code] ?? null),
                                    'text-bg-secondary' => blank($definition['label'][$code] ?? null),
                                ]) data-completion-status
                                    data-filled-label="{{ __('shop::admin.translations.translation_configured') }}"
                                    data-empty-label="{{ __('shop::admin.translations.translation_fallback') }}"
                                    aria-live="polite">{{ __('shop::admin.translations.' . (filled($definition['label'][$code] ?? null) ? 'translation_configured' : 'translation_fallback')) }}</span>
                            </span>
                        </button>
                    </h3>
                    <div @class(['accordion-collapse collapse', 'show' => $labelError])
                        id="variable-label-panel-{{ $index }}-{{ $code }}"
                        aria-labelledby="variable-label-heading-{{ $index }}-{{ $code }}">
                        <div class="accordion-body">
                @endif
                <label class="form-label" for="variable-label-{{ $index }}-{{ $code }}">
                    {{ $variableTranslations ? __('shop::admin.products.variables.variable_label', ['language' => $label]) : __('common.labels.title') }}
                    <span class="text-body-secondary">{{ __('shop::admin.translations.optional') }}</span>
                </label>
                <input @class(['form-control', 'is-invalid' => $labelError]) id="variable-label-{{ $index }}-{{ $code }}"
                    name="variables[{{ $index }}][label][{{ $code }}]" lang="{{ $code }}"
                    value="{{ $definition['label'][$code] ?? '' }}" maxlength="120"
                    @if ($labelError) aria-invalid="true" aria-describedby="variable-label-error-{{ $index }}-{{ $code }}" @endif>
                @error('variables.' . $index . '.label.' . $code)
                    <div class="invalid-feedback" id="variable-label-error-{{ $index }}-{{ $code }}">
                        {{ $message }}
                    </div>
                @enderror
                @if ($variableTranslations)
            </div>
    </div>
    @endif
    </div>
    @endforeach
    </div>
    <div class="row g-3 mb-3" data-variable-bounds>
        <div class="col-md-6">
            <label class="form-label"
                for="variable-min-{{ $index }}">{{ __('shop::admin.products.variables.minimum') }}</label>
            <input class="form-control" id="variable-min-{{ $index }}"
                name="variables[{{ $index }}][min]" type="number" value="{{ $definition['min'] ?? '' }}"
                min="0" max="120" data-variable-min>
        </div>
        <div class="col-md-6">
            <label class="form-label"
                for="variable-max-{{ $index }}">{{ __('shop::admin.products.variables.maximum') }}</label>
            <input class="form-control" id="variable-max-{{ $index }}"
                name="variables[{{ $index }}][max]" type="number" value="{{ $definition['max'] ?? '' }}"
                min="1" max="120" data-variable-max>
        </div>
        <div class="form-text" data-variable-text-help>
            {{ __('shop::admin.products.variables.text_bounds') }}
        </div>
        <div class="form-text" data-variable-integer-help hidden>
            {{ __('shop::admin.products.variables.integer_bounds') }}
        </div>
    </div>
    <div class="mb-3" data-variable-choices hidden>
        <p class="form-label">{{ __('shop::admin.products.variables.variable_choices') }}</p>
        <div class="d-flex flex-column gap-2" data-choice-list>
            @foreach ($definition['choices'] ?? [] as $choice)
                <div class="input-group" data-choice-row>
                    <input class="form-control" name="variables[{{ $index }}][choices][]"
                        value="{{ $choice }}" maxlength="120" required
                        aria-label="{{ __('shop::admin.products.variables.choice_value') }}">
                    <button class="btn btn-outline-danger" type="button" data-choice-remove
                        aria-label="{{ __('common.actions.delete') }}">×</button>
                </div>
            @endforeach
        </div>
        <button class="btn btn-sm btn-outline-secondary mt-2" type="button"
            data-choice-add>{{ __('shop::admin.products.variables.add_choice') }}</button>
        <div class="form-text">
            {{ __('shop::admin.products.variables.choices_help') }}
        </div>
        <template data-choice-template>
            <div class="input-group" data-choice-row>
                <input class="form-control" name="variables[{{ $index }}][choices][]" maxlength="120" required
                    aria-label="{{ __('shop::admin.products.variables.choice_value') }}">
                <button class="btn btn-outline-danger" type="button" data-choice-remove
                    aria-label="{{ __('common.actions.delete') }}">×</button>
            </div>
        </template>
    </div>
    <input type="hidden" name="variables[{{ $index }}][required]" value="0">
    <div class="form-check">
        <input class="form-check-input" id="variable-required-{{ $index }}"
            name="variables[{{ $index }}][required]" type="checkbox" value="1"
            @checked($definition['required'] ?? true)>
        <label class="form-check-label"
            for="variable-required-{{ $index }}">{{ __('shop::admin.products.variables.variable_required') }}</label>
    </div>
</fieldset>
