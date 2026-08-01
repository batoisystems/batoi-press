(() => {
    const navigation = document.querySelector('[data-bp-primary-navigation]');
    const toggle = document.querySelector('.bp-nav-toggle');
    const links = document.getElementById('bp-primary-links');
    const submenuButtons = navigation ? Array.from(navigation.querySelectorAll('.bp-submenu-toggle')) : [];

    const closeSubmenu = (button, restoreFocus = false) => {
        const item = button.closest('[data-bp-menu-item]');
        button.setAttribute('aria-expanded', 'false');
        item?.classList.remove('is-open');
        item?.querySelectorAll('.bp-submenu-toggle[aria-expanded="true"]').forEach((nested) => {
            nested.setAttribute('aria-expanded', 'false');
            nested.closest('[data-bp-menu-item]')?.classList.remove('is-open');
        });
        if (restoreFocus) button.focus();
    };

    const closeAllSubmenus = (except = null) => {
        submenuButtons.forEach((button) => {
            if (button !== except && !button.contains(except)) closeSubmenu(button);
        });
    };

    submenuButtons.forEach((button) => {
        button.addEventListener('click', (event) => {
            event.stopPropagation();
            const open = button.getAttribute('aria-expanded') !== 'true';
            if (open) {
                const item = button.closest('[data-bp-menu-item]');
                const parentList = item?.parentElement;
                parentList?.querySelectorAll(':scope > [data-bp-menu-item] > .bp-menu-item-control > .bp-submenu-toggle[aria-expanded="true"]').forEach((sibling) => {
                    if (sibling !== button) closeSubmenu(sibling);
                });
            }
            button.setAttribute('aria-expanded', open ? 'true' : 'false');
            button.closest('[data-bp-menu-item]')?.classList.toggle('is-open', open);
        });
        button.addEventListener('keydown', (event) => {
            if (event.key !== 'ArrowDown') return;
            if (button.getAttribute('aria-expanded') !== 'true') {
                event.preventDefault();
                button.click();
            }
            event.preventDefault();
            const panelId = button.getAttribute('aria-controls');
            const panel = panelId ? document.getElementById(panelId) : null;
            panel?.querySelector('a, button')?.focus();
        });
    });

    if (toggle && links) {
        const closeMenu = () => {
            toggle.setAttribute('aria-expanded', 'false');
            links.classList.remove('is-open');
            closeAllSubmenus();
        };
        toggle.addEventListener('click', () => {
            const open = toggle.getAttribute('aria-expanded') !== 'true';
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
            links.classList.toggle('is-open', open);
        });
        links.addEventListener('click', (event) => {
            if (event.target instanceof HTMLAnchorElement) closeMenu();
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape' && toggle.getAttribute('aria-expanded') === 'true') {
                closeMenu();
                toggle.focus();
            }
        });
        window.addEventListener('resize', () => {
            if (window.innerWidth > 760) closeMenu();
        });
    }

    document.addEventListener('click', (event) => {
        if (navigation && !navigation.contains(event.target)) closeAllSubmenus();
    });
    navigation?.addEventListener('keydown', (event) => {
        if (event.key !== 'Escape') return;
        const item = event.target.closest('[data-bp-menu-item]');
        const ownButton = item?.querySelector(':scope > .bp-menu-item-control > .bp-submenu-toggle');
        const parentPanel = item?.parentElement?.closest('[data-bp-submenu-panel]');
        const parentButton = parentPanel ? navigation.querySelector(`[aria-controls="${parentPanel.id}"]`) : null;
        if (ownButton?.getAttribute('aria-expanded') === 'true') {
            closeSubmenu(ownButton, true);
        } else if (parentButton) {
            closeSubmenu(parentButton, true);
        } else {
            closeAllSubmenus();
        }
    });

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
