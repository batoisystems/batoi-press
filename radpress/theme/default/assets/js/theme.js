(() => {
    const toggle = document.querySelector('.bp-nav-toggle');
    const links = document.getElementById('bp-primary-links');
    if (toggle && links) {
        toggle.addEventListener('click', () => {
            const open = toggle.getAttribute('aria-expanded') !== 'true';
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            links.classList.toggle('is-open', open);
        });
    }

    document.querySelectorAll('[data-bp-quantity]').forEach((control) => {
        const input = control.querySelector('input');
        if (!(input instanceof HTMLInputElement)) return;
        control.querySelectorAll('button[data-step]').forEach((button) => {
            button.addEventListener('click', () => {
                const step = Number(button.getAttribute('data-step')) || 0;
                const minimum = Number(input.min || 1);
                const maximum = Number(input.max || 99);
                input.value = String(Math.max(minimum, Math.min(maximum, Number(input.value || minimum) + step)));
                input.dispatchEvent(new Event('change', { bubbles: true }));
            });
        });
    });

    document.querySelectorAll('[data-bp-swatch]').forEach((swatch) => {
        swatch.addEventListener('click', () => {
            const group = swatch.closest('.bp-swatches');
            group?.querySelectorAll('[data-bp-swatch]').forEach((item) => item.classList.remove('is-selected'));
            swatch.classList.add('is-selected');
        });
    });
})();
