(() => {
    const modeButton = document.querySelector('[data-bp-mode-toggle]');
    if (modeButton) {
        const root = document.documentElement;
        const system = window.matchMedia('(prefers-color-scheme: dark)');
        const isDark = () => root.dataset.bpColorMode === 'dark' || (root.dataset.bpColorMode === 'system' && system.matches);
        const updateLabel = () => modeButton.setAttribute('aria-pressed', String(isDark()));
        try {
            const saved = localStorage.getItem('bp-color-mode');
            if (saved === 'light' || saved === 'dark') root.dataset.bpColorMode = saved;
        } catch (_) { /* Storage is optional. */ }
        updateLabel();
        modeButton.hidden = false;
        system.addEventListener('change', updateLabel);
        modeButton.addEventListener('click', () => {
            root.dataset.bpColorMode = isDark() ? 'light' : 'dark';
            try { localStorage.setItem('bp-color-mode', root.dataset.bpColorMode); } catch (_) { /* Storage is optional. */ }
            updateLabel();
        });
    }
    const scrollTop = document.querySelector('[data-bp-scroll-top]');
    if (scrollTop) {
        const update = () => { scrollTop.hidden = document.documentElement.scrollHeight <= window.innerHeight + 1; };
        update();
        window.addEventListener('resize', update);
        window.addEventListener('load', update);
        if ('ResizeObserver' in window) new ResizeObserver(update).observe(document.body);
        scrollTop.addEventListener('click', () => {
            window.scrollTo({top: 0, behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'instant' : 'smooth'});
            document.querySelector('.bp-skip-link')?.focus({preventScroll:true});
        });
    }
    document.querySelectorAll('[data-bp-load-more]').forEach((listing) => {
        const grid = listing.querySelector('.bp-post-grid');
        let next = listing.querySelector('.bp-pagination a[rel="next"]');
        if (!grid || !next) return;
        const button = document.createElement('button');
        button.type = 'button'; button.className = 'bp-button bp-button-secondary'; button.textContent = 'Load more';
        const status = document.createElement('p'); status.setAttribute('role', 'status');
        listing.append(button, status);
        button.addEventListener('click', async () => {
            button.disabled = true; status.textContent = 'Loading posts…';
            try {
                const url = new URL(next.href, location.href);
                if (url.origin !== location.origin) throw new Error('Invalid archive URL');
                const response = await fetch(url, {headers:{Accept:'text/html'}});
                if (!response.ok) throw new Error('Request failed');
                const doc = new DOMParser().parseFromString(await response.text(), 'text/html');
                const incoming = doc.querySelector('[data-bp-load-more]');
                const cards = incoming?.querySelectorAll('.bp-post-grid > .bp-post-card');
                if (!cards?.length) throw new Error('No archive results');
                cards.forEach((card) => grid.append(document.importNode(card, true)));
                next = incoming.querySelector('.bp-pagination a[rel="next"]');
                const nav = listing.querySelector('.bp-pagination');
                const incomingNav = incoming.querySelector('.bp-pagination');
                if (nav && incomingNav) nav.replaceWith(document.importNode(incomingNav, true));
                button.hidden = !next;
                status.textContent = cards.length + ' more posts loaded.';
            } catch (_) { status.textContent = 'Could not load posts. Use the page navigation links to continue.'; }
            finally { button.disabled = false; }
        });
    });
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
