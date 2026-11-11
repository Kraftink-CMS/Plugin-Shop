const controls = (container) => container.querySelectorAll('input, select, textarea');
const toggleFields = (container, visible) => {
    container.hidden = !visible;
    controls(container).forEach((field) => {
        field.disabled = !visible;
    });
};

const initialized = new WeakMap();
const select = (root, selector, key) => {
    const elements = [...(root.matches?.(selector) ? [root] : []), ...root.querySelectorAll(selector)];
    return elements.filter((element) => {
        const keys = initialized.get(element) ?? new Set();
        if (keys.has(key)) {
            return false;
        }
        keys.add(key);
        initialized.set(element, keys);
        return true;
    });
};

const initializeShopAdmin = (root = document) => {
    select(root, '[data-shop-webhook-setup]', 'webhook').forEach((setup) => {
        const actions = setup.querySelector('[data-shop-webhook-actions]');
        if (!actions) {
            return;
        }
        const credentials = setup.querySelector('[data-shop-payment-credentials]');
        const unsaved = setup.querySelector('[data-shop-unsaved-credentials]');
        const inputs = credentials.querySelectorAll('input[type="password"], select');
        const update = () => {
            const changed = [...inputs].some((field) =>
                field instanceof HTMLSelectElement
                    ? !field.selectedOptions[0]?.defaultSelected
                    : field.value !== field.defaultValue,
            );
            actions.hidden = changed;
            unsaved.hidden = !changed;
        };
        credentials.addEventListener('input', update);
        credentials.addEventListener('change', update);
        update();
    });

    select(root, '[data-shop-catalogue]', 'catalogue').forEach((navigation) => {
        const tabs = [...navigation.querySelectorAll('[data-catalogue-section]')];
        tabs.forEach((tab) => {
            tab.addEventListener('shown.bs.tab', () => {
                document.querySelectorAll('[data-shop-catalogue-action]').forEach((action) => {
                    action.hidden = action.dataset.shopCatalogueAction !== tab.dataset.catalogueSection;
                });
                const address = new URL(window.location.href);
                address.searchParams.set('tab', tab.dataset.catalogueSection);
                address.hash = tab.dataset.bsTarget;
                window.history.replaceState(null, '', address.href);
            });
        });
        if (!JSON.parse(navigation.dataset.shopErrors || '[]').length) {
            tabs.find((tab) => tab.dataset.bsTarget === window.location.hash)?.click();
        }
    });

    select(root, '[data-billing-settings]', 'billing-tabs').forEach((form) => {
        const selected = form.querySelector('[data-billing-panel]');
        const tabs = document.querySelectorAll('[id^="billing-tab-"][data-bs-toggle="tab"]');
        tabs.forEach((tab) => {
            tab.addEventListener('shown.bs.tab', () => {
                selected.value = tab.dataset.bsTarget.replace('#billing-', '');
                window.history.replaceState(null, '', tab.dataset.bsTarget);
            });
        });
        if (!JSON.parse(form.dataset.shopErrors || '[]').length) {
            [...tabs].find((tab) => tab.dataset.bsTarget === window.location.hash)?.click();
        }
    });

    select(
        root,
        '[data-shop-product], [data-shop-category], [data-shop-coupon], [data-billing-settings]',
        'validation',
    ).forEach((form) => {
        form.noValidate = true;
        form.addEventListener('submit', (event) => {
            window.tinymce?.triggerSave();
            if (form.checkValidity()) {
                return;
            }
            event.preventDefault();
            const invalid = form.querySelector(':invalid');
            const pane = invalid?.closest('.tab-pane');
            if (pane) {
                document.querySelector(`[data-bs-target="#${pane.id}"]`)?.click();
            }
            window.setTimeout(() => {
                invalid?.focus();
                invalid?.reportValidity();
            }, 180);
        });
        const locale = form.querySelector('[data-shop-locale]');
        if (locale) {
            const updateTranslations = () => {
                form.querySelectorAll('[data-shop-translation]').forEach((fieldset) => {
                    toggleFields(fieldset, fieldset.dataset.shopTranslation !== locale.value);
                });
            };
            locale.addEventListener('change', updateTranslations);
            updateTranslations();
        }
        const errors = JSON.parse(form.dataset.shopErrors || '[]');
        for (const key of errors) {
            const [base, ...parts] = key.split('.');
            const name = base + parts.map((part) => `[${part}]`).join('');
            const field = [...form.elements].find(
                (item) => !item.disabled && (item.name === name || item.name.startsWith(`${name}[`)),
            );
            const pane = field?.closest('.tab-pane');
            if (pane) {
                document.querySelector(`[data-bs-target="#${pane.id}"]`)?.click();
                break;
            }
        }
    });

    select(root, '[data-shop-product]', 'product').forEach((form) => {
        const billing = form.querySelector('[data-shop-billing]');
        const customPrice = form.querySelector('[data-shop-custom-price]');
        const upgradeGroup = form.querySelector('[name="upgrade_group"]');
        const currency = form.querySelector('[name="currency"]');
        const trialDays = form.querySelector('[name="trial_days"]');
        const trialPaymentMethod = form.querySelector('[data-shop-trial-payment-method]');
        const updateTrial = () => {
            if (trialDays && trialPaymentMethod) {
                toggleFields(trialPaymentMethod, billing.value !== '' && Number(trialDays.value) > 0);
            }
        };
        const updateGroups = () => {
            for (const option of upgradeGroup.options) {
                if (!option.value) {
                    continue;
                }
                option.hidden = option.dataset.currency !== currency.value;
                option.disabled = option.hidden;
                if (option.selected && option.disabled) {
                    upgradeGroup.value = '';
                }
            }
        };
        currency.addEventListener('change', updateGroups);
        updateGroups();
        const updatePrice = () => {
            const prohibited = billing.value !== '' || upgradeGroup.value.trim() !== '';
            if (prohibited) {
                customPrice.checked = false;
            }
            customPrice.disabled = prohibited;
            toggleFields(form.querySelector('[data-shop-price-range]'), customPrice.checked && !prohibited);
        };
        const updateBilling = () => {
            const subscription = billing.value !== '';
            form.querySelectorAll('[data-shop-subscription-options]').forEach((container) => {
                toggleFields(container, subscription);
            });
            form.querySelectorAll('[data-shop-one-time]').forEach((container) => {
                container.hidden = subscription;
                controls(container).forEach((field) => {
                    if (subscription) {
                        if (field.type === 'checkbox') {
                            field.checked = false;
                        } else {
                            field.value = field.name === 'upgrade_weight' ? '0' : '';
                        }
                    }
                    if (field.name === 'upgrade_weight') {
                        field.required = !subscription;
                    }
                });
            });
            updatePrice();
            updateTrial();
        };
        trialDays?.addEventListener('input', updateTrial);
        billing.addEventListener('change', updateBilling);
        customPrice.addEventListener('change', updatePrice);
        upgradeGroup.addEventListener('input', updatePrice);
        updateBilling();
        const list = form.querySelector('[data-variable-list]');
        const add = form.querySelector('[data-variable-add]');
        const template = form.querySelector('[data-variable-template]');
        let nextIndex =
            Math.max(
                -1,
                ...[...list.querySelectorAll('[name$="[key]"]')].map((field) =>
                    Number(field.name.match(/\[(\d+)\]/)?.[1] ?? -1),
                ),
            ) + 1;
        const updateList = () => {
            const count = list.querySelectorAll('[data-variable-row]').length;
            add.disabled = count >= 20;
            form.querySelector('[data-variables-empty]').hidden = count > 0;
        };
        const updateRow = (row) => {
            const type = row.querySelector('[data-variable-type]').value;
            toggleFields(row.querySelector('[data-variable-bounds]'), type !== 'choice');
            toggleFields(row.querySelector('[data-variable-choices]'), type === 'choice');
            row.querySelector('[data-variable-text-help]').hidden = type !== 'text';
            row.querySelector('[data-variable-integer-help]').hidden = type !== 'integer';
            const minimum = row.querySelector('[data-variable-min]');
            const maximum = row.querySelector('[data-variable-max]');
            minimum.min = type === 'integer' ? '-1000000000' : '0';
            maximum.min = type === 'integer' ? '-1000000000' : '1';
            minimum.max = maximum.max = type === 'integer' ? '1000000000' : '120';
            row.querySelector('[data-choice-add]').disabled = row.querySelectorAll('[data-choice-row]').length >= 30;
        };
        const initializeRow = (row) => {
            row.querySelector('[data-variable-type]').addEventListener('change', () => {
                if (
                    row.querySelector('[data-variable-type]').value === 'choice' &&
                    !row.querySelector('[data-choice-row]')
                ) {
                    row.querySelector('[data-choice-list]').append(
                        row.querySelector('[data-choice-template]').content.cloneNode(true),
                    );
                }
                updateRow(row);
            });
            row.querySelector('[data-variable-remove]').addEventListener('click', () => {
                row.remove();
                updateList();
            });
            row.querySelector('[data-choice-add]').addEventListener('click', () => {
                if (row.querySelectorAll('[data-choice-row]').length >= 30) {
                    return;
                }
                row.querySelector('[data-choice-list]').append(
                    row.querySelector('[data-choice-template]').content.cloneNode(true),
                );
                updateRow(row);
                row.querySelector('[data-choice-list]').lastElementChild.querySelector('input').focus();
            });
            row.addEventListener('click', (event) => {
                const button = event.target.closest('[data-choice-remove]');
                if (button) {
                    button.closest('[data-choice-row]').remove();
                    updateRow(row);
                }
            });
            updateRow(row);
        };
        list.querySelectorAll('[data-variable-row]').forEach(initializeRow);
        add.addEventListener('click', () => {
            if (list.querySelectorAll('[data-variable-row]').length >= 20) {
                return;
            }
            const fragment = document.createElement('template');
            fragment.innerHTML = template.innerHTML.replaceAll('__INDEX__', String(nextIndex++));
            const row = fragment.content.firstElementChild;
            list.append(row);
            initializeRow(row);
            updateList();
            row.querySelector('input').focus();
        });
        updateList();
        const kind = form.querySelector('[data-shop-kind]');
        if (kind) {
            const updateKind = () => {
                const card = kind.value === 'giftcard';
                if (card) {
                    billing.value = '';
                    upgradeGroup.value = '';
                }
                updateBilling();
                form.querySelectorAll('[data-shop-product-only]').forEach((container) =>
                    toggleFields(container, !card),
                );
                form.querySelectorAll('[data-shop-giftcard-only]').forEach((container) =>
                    toggleFields(container, card),
                );
                form.querySelectorAll('[data-shop-product-tab]').forEach((tab) => {
                    tab.hidden = card;
                    if (card && tab.querySelector('.active')) {
                        form.querySelector('[data-bs-target="#product-details"]').click();
                    }
                });
            };
            kind.addEventListener('change', updateKind);
            updateKind();
        }
    });

    select(root, '[data-shop-coupon]', 'coupon').forEach((form) => {
        const type = form.querySelector('[data-coupon-type]');
        const update = () => {
            toggleFields(form.querySelector('[data-coupon-percent]'), type.value === 'percent');
            toggleFields(form.querySelector('[data-coupon-fixed]'), type.value === 'fixed');
        };
        type.addEventListener('change', update);
        update();
    });
};

initializeShopAdmin();
document.addEventListener('kraft:content-added', (event) => initializeShopAdmin(event.target));
