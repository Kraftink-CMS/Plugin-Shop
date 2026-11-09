const initialized = new WeakSet();
const select = (root, selector) => {
    const elements = [...(root.matches?.(selector) ? [root] : []), ...root.querySelectorAll(selector)];
    return elements.filter((element) => {
        if (initialized.has(element)) {
            return false;
        }
        initialized.add(element);
        return true;
    });
};

const initializeShopBilling = (root = document) => {
    select(root, '[data-billing-settings]').forEach((form) => {
        const seller = form.querySelector('[name="seller_type"]');
        const country = form.querySelector('[name="seller_country"]');
        const tax = form.querySelector('[name="tax_mode"]');
        const numberSuffix = form.querySelector('[data-billing-number-suffix]');
        const update = () => {
            const business = seller.value === 'business';
            if (numberSuffix) {
                numberSuffix.textContent = business
                    ? numberSuffix.dataset.businessSuffix
                    : numberSuffix.dataset.individualSuffix;
            }
            form.querySelectorAll(
                '[data-billing-business], [data-billing-french], [data-billing-tax], [data-billing-exemption]',
            ).forEach((container) => {
                const visible =
                    (!container.hasAttribute('data-billing-business') || business) &&
                    (!container.hasAttribute('data-billing-french') ||
                        (business && country.value.trim().toUpperCase() === 'FR')) &&
                    (!container.hasAttribute('data-billing-tax') || (business && tax.value === 'standard')) &&
                    (!container.hasAttribute('data-billing-exemption') || (business && tax.value !== 'standard'));
                container.hidden = !visible;
                container.querySelectorAll('input, select, textarea').forEach((field) => {
                    field.disabled = !visible;
                });
            });
        };
        seller.addEventListener('change', update);
        country.addEventListener('input', update);
        tax.addEventListener('change', update);
        update();
        const preview = document.querySelector('[data-billing-preview]');
        if (!preview) {
            return;
        }
        const frame = document.querySelector('[data-billing-preview-frame]');
        const feedback = document.querySelector('[data-billing-preview-feedback]');
        preview.addEventListener('click', async () => {
            const fields = [
                '_token',
                'seller_type',
                'seller_country',
                'seller_name',
                'seller_address',
                'seller_email',
                'seller_siren',
                'seller_siret',
                'seller_legal_form',
                'seller_capital',
                'seller_registration',
                'seller_tax_id',
                'number_prefix',
                'tax_mode',
                'tax_rate',
                'tax_label',
                'tax_notice',
                'payment_terms',
                'legal_notice',
            ];
            const data = new FormData(form);
            [...data.keys()].forEach((key) => {
                if (!fields.includes(key)) {
                    data.delete(key);
                }
            });
            preview.disabled = true;
            frame.hidden = true;
            frame.srcdoc = '';
            feedback.textContent = preview.dataset.loading;
            try {
                const endpoint = new URL(preview.dataset.previewUrl, window.location.href);
                if (endpoint.origin !== window.location.origin) {
                    feedback.textContent = preview.dataset.error;
                    return;
                }
                const response = await fetch(endpoint.href, {
                    method: 'POST',
                    body: data,
                    headers: { Accept: 'application/json' },
                });
                const payload = await response.json();
                if (!response.ok || payload.success !== true) {
                    feedback.textContent =
                        Object.values(payload.data?.errors || {})
                            .flat()
                            .join(' ') || preview.dataset.error;
                    return;
                }
                frame.srcdoc = payload.data.html;
                frame.hidden = false;
                feedback.textContent = '';
            } catch {
                feedback.textContent = preview.dataset.error;
            } finally {
                preview.disabled = false;
            }
        });
    });
};

initializeShopBilling();
document.addEventListener('kraft:content-added', (event) => initializeShopBilling(event.target));
