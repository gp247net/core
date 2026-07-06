/*
 * GP247 admin shell client script — SOURCE (ADR-002).
 *
 * Plain browser ES (no bundler needed): it is copied verbatim to
 * `../../dist/js/admin.js`, published to `public/GP247/Core/AdminShell/`, and
 * loaded via `gp247_file()` in the layout's <head> — before Livewire boots
 * Alpine, so the `alpine:init` listener below is registered in time.
 *
 * Alpine.js itself is provided by Livewire 4's bundle; we never import it here.
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.store('gp247', {
        // Theme is mirrored to <html class="dark"> and persisted to localStorage.
        dark: document.documentElement.classList.contains('dark'),

        toggleTheme() {
            this.dark = !this.dark;
            document.documentElement.classList.toggle('dark', this.dark);
            try {
                localStorage.setItem('gp247-theme', this.dark ? 'dark' : 'light');
            } catch (e) {
                // Private mode / storage disabled: degrade silently, theme is per-session.
            }
        },

        // Sidebar starts open on desktop; the layout collapses it on small screens.
        sidebarOpen: window.innerWidth >= 1024,

        // Desktop-only collapse of the static sidebar (mobile uses the off-canvas
        // `sidebarOpen` drawer instead). Persisted so the choice survives reloads.
        sidebarCollapsed: (() => {
            try {
                return localStorage.getItem('gp247-sidebar') === 'collapsed';
            } catch (e) {
                return false;
            }
        })(),

        toggleSidebar() {
            // WHY: on desktop the sidebar is in normal flow (lg:static), so the
            // toggle resizes/collapses it; on mobile it slides the off-canvas drawer.
            if (window.innerWidth >= 1024) {
                this.sidebarCollapsed = !this.sidebarCollapsed;
                try {
                    localStorage.setItem('gp247-sidebar', this.sidebarCollapsed ? 'collapsed' : 'expanded');
                } catch (e) {
                    // Storage disabled: degrade silently, collapse is per-session.
                }
            } else {
                this.sidebarOpen = !this.sidebarOpen;
            }
        },
    });
});

/*
 * Dashboard chart — ApexCharts bar/area chart factory (ADR-004, US-AUI-005).
 *
 * Registered as an Alpine `data` component so each dashboard card can mount its
 * own chart instance without conflicting. The component reads the current dark
 * theme from <html class="dark"> at init time; re-renders on page reload (RISK-TECH-009
 * — toggling dark mid-session does not update the chart colour, accepted trade-off).
 *
 * Markup contract (see dashboard-chart.blade.php):
 *   <div x-data="gp247DashboardChart({ labels: [...], values: [...], color: '#…' })"
 *        wire:ignore></div>
 *
 * ApexCharts is self-hosted at public/GP247/Core/AdminShell/vendor/apexcharts/
 * and loaded via @assets in the dashboard view.
 *
 * @aidlc-unit admin-shell
 * @aidlc-story US-AUI-005
 * @aidlc-adr ADR-004
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('gp247DashboardChart', (config) => ({
        _chart: null,

        init() {
            const isDark = document.documentElement.classList.contains('dark');
            const labels  = (config && config.labels)  ? config.labels  : [];
            const values  = (config && config.values)  ? config.values  : [];
            const color   = (config && config.color)   ? config.color   : '#3b82f6';
            const name    = (config && config.name)    ? config.name    : '';

            if (typeof ApexCharts === 'undefined' || labels.length === 0) {
                return;
            }

            this._chart = new ApexCharts(this.$el, {
                series: [{ name: name, data: values }],
                chart: {
                    type: 'bar',
                    height: 224,
                    toolbar: { show: false },
                    background: 'transparent',
                    animations: { enabled: true, speed: 400 },
                },
                plotOptions: {
                    bar: { borderRadius: 3, columnWidth: '62%' },
                },
                dataLabels: { enabled: false },
                xaxis: {
                    categories: labels,
                    labels: {
                        rotate: -45,
                        style: { fontSize: '10px' },
                        // WHY: show every Nth label to avoid crowding when many data points.
                        formatter: (val, idx) => (idx % Math.max(1, Math.ceil(labels.length / 12)) === 0 ? val : ''),
                    },
                    axisBorder: { show: false },
                    axisTicks: { show: false },
                },
                yaxis: { labels: { style: { fontSize: '10px' } } },
                colors: [color],
                grid: {
                    borderColor: isDark ? 'rgba(255,255,255,0.08)' : 'rgba(0,0,0,0.06)',
                    strokeDashArray: 4,
                },
                tooltip: {
                    theme: isDark ? 'dark' : 'light',
                    y: { formatter: (v) => Number(v).toLocaleString() },
                },
                theme: { mode: isDark ? 'dark' : 'light' },
            });

            this._chart.render();
        },

        destroy() {
            if (this._chart) {
                this._chart.destroy();
                this._chart = null;
            }
        },
    }));
});

/*
 * Drag-to-reorder for tree lists (e.g. the Menu manager). Reorders siblings
 * WITHIN their parent list only (re-parenting is done via the edit form, keeping
 * the tree safe). Uses event delegation on document so it survives Livewire DOM
 * morphing without re-binding. On drop it calls the owning Livewire component's
 * reorder(parentId, orderedIds).
 *
 * Markup contract:
 *   <ul data-gp247-sortable data-parent="<id>">
 *     <li data-gp247-item data-id="<id>" draggable="true"> … </li>
 *
 * @aidlc-unit admin-shell-rbac
 * @aidlc-story US-RBAC-003
 * @aidlc-adr ADR-005
 */
(function () {
    let dragId = null;
    let dragParent = null;
    let dropTarget = null;
    let dropBefore = true;

    /** Remove every drop-position marker from the page. */
    const clearMarkers = () => {
        document.querySelectorAll('.gp247-drop-before, .gp247-drop-after')
            .forEach((el) => el.classList.remove('gp247-drop-before', 'gp247-drop-after'));
    };

    /** Reset drag state and visual cues. */
    const endDrag = () => {
        document.querySelectorAll('.gp247-dragging').forEach((el) => el.classList.remove('gp247-dragging'));
        clearMarkers();
        dragId = null;
        dragParent = null;
        dropTarget = null;
    };

    document.addEventListener('dragstart', (e) => {
        const item = e.target.closest('[data-gp247-item]');
        if (!item) {
            return;
        }
        dragId = item.dataset.id;
        dragParent = item.closest('[data-gp247-sortable]')?.dataset.parent ?? null;
        item.classList.add('gp247-dragging');
        if (e.dataTransfer) {
            e.dataTransfer.effectAllowed = 'move';
        }
    });

    document.addEventListener('dragover', (e) => {
        const list = e.target.closest('[data-gp247-sortable]');
        // Only same-parent reorder is allowed (re-parent via the edit form).
        if (dragId === null || !list || list.dataset.parent !== dragParent) {
            return;
        }
        e.preventDefault();
        if (e.dataTransfer) {
            e.dataTransfer.dropEffect = 'move';
        }

        const item = e.target.closest('[data-gp247-item]');
        clearMarkers();
        if (item && item.parentElement === list && item.dataset.id !== dragId) {
            // Insert before or after depending on which half the cursor is over.
            const rect = item.getBoundingClientRect();
            dropBefore = (e.clientY - rect.top) < rect.height / 2;
            dropTarget = item;
            item.classList.add(dropBefore ? 'gp247-drop-before' : 'gp247-drop-after');
        } else {
            dropTarget = null;
        }
    });

    document.addEventListener('drop', (e) => {
        const list = e.target.closest('[data-gp247-sortable]');
        if (dragId === null || !list || list.dataset.parent !== dragParent) {
            endDrag();
            return;
        }
        e.preventDefault();

        const dragged = list.querySelector(':scope > [data-gp247-item][data-id="' + dragId + '"]');
        if (!dragged) {
            endDrag();
            return;
        }

        if (dropTarget && dropTarget !== dragged && dropTarget.parentElement === list) {
            list.insertBefore(dragged, dropBefore ? dropTarget : dropTarget.nextSibling);
        } else if (!dropTarget) {
            list.appendChild(dragged);
        }

        const ids = Array.from(list.children)
            .filter((c) => c.matches('[data-gp247-item]'))
            .map((c) => c.dataset.id);

        const host = list.closest('[wire\\:id]');
        if (host && window.Livewire) {
            window.Livewire.find(host.getAttribute('wire:id')).call('reorder', list.dataset.parent, ids);
        }
        endDrag();
    });

    // Clean up cues when a drag is abandoned (dropped outside any list).
    document.addEventListener('dragend', endDrag);
})();
