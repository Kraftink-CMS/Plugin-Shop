document.querySelectorAll('[data-shop-purchase]').forEach((container) => {
    if (container.dataset.purchaseReady) {
        return;
    }
    container.dataset.purchaseReady = '1';
    const quantity = container.querySelector('[name="quantity"]');
    let availability = null;
    const update = () => {
        const quantityReason =
            container.dataset.purchaseUnavailable || (quantity.validity.valid ? '' : container.dataset.quantityError);
        container.querySelectorAll('[data-shop-submit-wrapper]').forEach((wrapper) => {
            const button = wrapper.querySelector('[data-shop-submit]');
            const reason =
                quantityReason ||
                (availability?.canPurchase === false
                    ? availability.reason || container.dataset.availabilityUnavailable
                    : button.value === '1' && availability?.canTrial === false
                      ? availability.trialReason || container.dataset.trialUnavailable
                      : '');
            button.disabled = reason !== '';
            wrapper.title = reason;
            wrapper.tabIndex = reason ? 0 : -1;
            if (reason) {
                wrapper.setAttribute('aria-label', reason);
            } else {
                wrapper.removeAttribute('aria-label');
            }
        });
    };
    if (!container.dataset.availabilityUrl) {
        quantity.addEventListener('input', update);
        quantity.addEventListener('change', update);
        update();
        return;
    }
    const form = container.closest('form');
    const recipient = form.querySelector('input[name="recipient"]:not([type="hidden"])');
    let controller;
    let timer;
    let version = 0;
    const schedule = () => {
        const requestVersion = ++version;
        controller?.abort();
        clearTimeout(timer);
        availability = null;
        update();
        if (!quantity.validity.valid) {
            return;
        }
        timer = setTimeout(async () => {
            controller = new AbortController();
            try {
                const url = new URL(container.dataset.availabilityUrl, window.location.href);
                if (url.origin !== window.location.origin) {
                    return;
                }
                url.searchParams.set('quantity', quantity.value);
                url.searchParams.set('intent', form.querySelector('[name="intent"]')?.value || 'set');
                url.searchParams.set('recipient', recipient && !recipient.disabled ? recipient.value.trim() : '');
                const response = await fetch(url, {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                    cache: 'no-store',
                    signal: controller.signal,
                });
                if (!response.ok) {
                    throw new Error();
                }
                const data = await response.json();
                if (requestVersion !== version) {
                    return;
                }
                if (typeof data.canPurchase !== 'boolean' || typeof data.canTrial !== 'boolean') {
                    throw new Error();
                }
                availability = data;
            } catch {
                if (requestVersion !== version) {
                    return;
                }
                availability = null;
            }
            update();
        }, 250);
    };
    quantity.addEventListener('input', schedule);
    quantity.addEventListener('change', schedule);
    recipient?.addEventListener('input', schedule);
    form.querySelector('[data-shop-gift-toggle]')?.addEventListener('click', () => Promise.resolve().then(schedule));
    schedule();
});

document.querySelectorAll('[data-shop-gifting]').forEach((container) => {
    if (container.dataset.giftingReady) {
        return;
    }
    container.dataset.giftingReady = '1';
    const button = container.querySelector('[data-shop-gift-toggle]');
    const fields = container.querySelector('[data-shop-gift-fields]');
    const recipient = fields.querySelector('[name="recipient"]');
    const update = (expanded) => {
        button.setAttribute('aria-expanded', String(expanded));
        fields.hidden = !expanded;
        recipient.disabled = !expanded;
        recipient.required = expanded;
    };
    button.addEventListener('click', () => {
        const expanded = button.getAttribute('aria-expanded') !== 'true';
        update(expanded);
        if (expanded) {
            recipient.focus();
        }
    });
    update(button.getAttribute('aria-expanded') === 'true');
});

document.querySelectorAll('[data-shop-billing]').forEach((container) => {
    if (container.dataset.billingReady) {
        return;
    }
    container.dataset.billingReady = '1';
    const type = container.querySelector('[name="billing[type]"]');
    const company = container.querySelector('[data-shop-business]');
    const update = () => {
        const business = type.value === 'business';
        company.hidden = !business;
        company.querySelectorAll('input').forEach((field) => {
            field.disabled = !business;
        });
    };
    type.addEventListener('change', update);
    update();
});

document.querySelectorAll('[data-payment-checkout]').forEach((form) => {
    if (form.dataset.paymentPolicyReady) {
        return;
    }
    const provider = form.querySelector('[name="provider"]');
    form.dataset.paymentPolicyReady = '1';
    const policies = JSON.parse(form.dataset.paymentPolicies || '{}');
    const giftcard = form.querySelector('[data-payment-giftcard]');
    const wallet = form.querySelector('[data-payment-wallet]');
    const toggle = (fields, visible) => {
        if (fields) {
            fields.hidden = !visible;
            fields.disabled = !visible;
        }
    };
    const update = () => {
        const method = provider?.value || '';
        const policy = policies[method] || {};
        toggle(
            giftcard,
            form.dataset.paymentGiftcardEligible === '1' &&
                (policy.allow_giftcard === true || policies['']?.allow_giftcard === true),
        );
        toggle(
            wallet,
            form.dataset.paymentWalletEligible === '1' &&
                policy.allow_wallet === true &&
                policy.currency === form.dataset.paymentWalletCurrency,
        );
        form.querySelectorAll('[data-payment-conditions]').forEach((conditions) => {
            conditions.hidden = conditions.dataset.paymentConditions !== method;
        });
    };
    provider?.addEventListener('change', update);
    update();
});
