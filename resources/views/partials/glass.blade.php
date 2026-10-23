{{-- Liquid Glass theme. Restyles Bootstrap 5 components globally; no markup changes needed in individual views. --}}
<style>
:root, [data-bs-theme="light"] {
    --g-bg1: #e8eefc; --g-bg2: #f6e9f7; --g-bg3: #e4f6f1;
    --g-glass: rgba(255,255,255,.58); --g-glass-strong: rgba(255,255,255,.78); --g-glass-soft: rgba(255,255,255,.35);
    --g-border: rgba(255,255,255,.75); --g-line: rgba(15,23,42,.08);
    --g-text: #1d1d1f; --g-muted: #6e6e73;
    --g-accent: #0a84ff; --g-accent2: #5e5ce6;
    --g-shadow: 0 8px 30px rgba(31,38,80,.10), 0 1px 2px rgba(31,38,80,.06);
    --g-shine: inset 0 1px 0 rgba(255,255,255,.9), inset 0 -1px 0 rgba(255,255,255,.25);
    --g-side: rgba(255,255,255,.45);
}
[data-bs-theme="dark"] {
    --g-bg1: #0b1020; --g-bg2: #1a1238; --g-bg3: #07262a;
    --g-glass: rgba(30,34,52,.55); --g-glass-strong: rgba(34,38,60,.8); --g-glass-soft: rgba(255,255,255,.05);
    --g-border: rgba(255,255,255,.12); --g-line: rgba(255,255,255,.09);
    --g-text: #f5f5f7; --g-muted: #a1a1aa;
    --g-accent: #409cff; --g-accent2: #7d7aff;
    --g-shadow: 0 10px 34px rgba(0,0,0,.45), 0 1px 2px rgba(0,0,0,.4);
    --g-shine: inset 0 1px 0 rgba(255,255,255,.14), inset 0 -1px 0 rgba(255,255,255,.03);
    --g-side: rgba(16,19,34,.55);
}
:root {
    --bs-body-font-family: -apple-system, BlinkMacSystemFont, "SF Pro Text", "Inter", "Segoe UI", Roboto, sans-serif;
    --bs-body-color: var(--g-text); --bs-secondary-color: var(--g-muted); --bs-body-color-rgb: 29,29,31;
    --bs-border-color: var(--g-line); --bs-primary: var(--g-accent); --bs-link-color: var(--g-accent);
    --bs-link-hover-color: var(--g-accent2); --bs-border-radius: 12px; --bs-border-radius-sm: 9px; --bs-border-radius-lg: 18px;
    --g-ease: cubic-bezier(.22,.8,.26,1);
}
[data-bs-theme="dark"] { --bs-body-color-rgb: 245,245,247; --bs-link-color: #6cb2ff; --bs-emphasis-color: #fff; }

body { color: var(--g-text); background: transparent; min-height: 100vh; -webkit-font-smoothing: antialiased; letter-spacing: -.006em; }
body::before { content: ""; position: fixed; inset: 0; z-index: -1;
    background: radial-gradient(60vw 50vh at 8% 0%, rgba(10,132,255,.28), transparent 60%),
                radial-gradient(55vw 55vh at 100% 10%, rgba(191,90,242,.22), transparent 60%),
                radial-gradient(60vw 60vh at 70% 100%, rgba(48,209,160,.22), transparent 60%),
                linear-gradient(135deg, var(--g-bg1), var(--g-bg2) 55%, var(--g-bg3)); }
h1,h2,h3,h4,h5,h6 { font-weight: 650; letter-spacing: -.02em; }
.text-muted, .text-body-secondary { color: var(--g-muted) !important; }
a { text-decoration-color: transparent; transition: color .2s, opacity .2s; }
::selection { background: rgba(10,132,255,.28); }
::-webkit-scrollbar { width: 10px; height: 10px; } ::-webkit-scrollbar-thumb { background: var(--g-line); border-radius: 10px; border: 2px solid transparent; background-clip: padding-box; }

/* Glass surfaces */
.card, .dropdown-menu, .modal-content, .offcanvas, .list-group-item, .popover, .toast {
    background: var(--g-glass); -webkit-backdrop-filter: blur(22px) saturate(170%); backdrop-filter: blur(22px) saturate(170%);
    border: 1px solid var(--g-border); box-shadow: var(--g-shadow), var(--g-shine); color: var(--g-text); }
.card { border-radius: 20px; position: relative; overflow: hidden; transition: transform .35s var(--g-ease), box-shadow .35s var(--g-ease); }
.card::before { content: ""; position: absolute; inset: 0 0 auto 0; height: 55%; pointer-events: none;
    background: linear-gradient(180deg, rgba(255,255,255,.22), transparent); opacity: .55; border-radius: inherit; }
[data-bs-theme="dark"] .card::before { opacity: .12; }
.card > * { position: relative; }
a.text-reset:hover .card, .card:has(> .list-group):hover { transform: translateY(-2px); }
a.text-reset:hover .card { box-shadow: 0 14px 40px rgba(31,38,80,.16), var(--g-shine); }
.card-header, .card-footer { background: transparent; border-color: var(--g-line); padding: .85rem 1.1rem; }
.card-header { font-weight: 600; } .card-body { padding: 1.1rem; }
.card.border-start { border-left-width: 4px !important; }
.list-group { --bs-list-group-bg: transparent; --bs-list-group-border-color: var(--g-line); }
.list-group-item { background: transparent; box-shadow: none; backdrop-filter: none; -webkit-backdrop-filter: none; border-color: var(--g-line); border-radius: 0 !important; border-width: 0 0 1px; transition: background .2s; }
.list-group-item:last-child { border-bottom: 0; }
.list-group-item-action:hover { background: var(--g-glass-soft); color: var(--g-text); }
.dropdown-menu { border-radius: 16px; padding: .4rem; animation: g-pop .18s var(--g-ease); }
.dropdown-item { border-radius: 10px; color: var(--g-text); transition: background .15s; }
.dropdown-item:hover, .dropdown-item:focus { background: rgba(10,132,255,.14); color: var(--g-text); }
.modal-content { border-radius: 24px; } .modal-backdrop { --bs-backdrop-opacity: .35; backdrop-filter: blur(6px); }
@keyframes g-pop { from { opacity: 0; transform: translateY(-4px) scale(.98); } to { opacity: 1; transform: none; } }
@keyframes g-rise { from { opacity: 0; transform: translateY(8px); } to { opacity: 1; transform: none; } }
main { animation: g-rise .5s var(--g-ease) both; }

/* Buttons */
.btn { border-radius: 11px; font-weight: 500; transition: transform .2s var(--g-ease), box-shadow .2s, background .2s, border-color .2s, color .2s; }
.btn:hover { transform: translateY(-1px); } .btn:active { transform: scale(.98); }
.btn-primary { --bs-btn-bg: var(--g-accent); --bs-btn-border-color: transparent; --bs-btn-hover-bg: var(--g-accent2); --bs-btn-hover-border-color: transparent;
    --bs-btn-active-bg: var(--g-accent2); --bs-btn-disabled-bg: var(--g-accent);
    background-image: linear-gradient(180deg, rgba(255,255,255,.28), rgba(255,255,255,0) 55%); box-shadow: 0 4px 14px rgba(10,132,255,.35), inset 0 1px 0 rgba(255,255,255,.4); }
.btn-primary:hover { box-shadow: 0 8px 22px rgba(10,132,255,.4), inset 0 1px 0 rgba(255,255,255,.45); }
.btn-success, .btn-danger, .btn-warning, .btn-info { background-image: linear-gradient(180deg, rgba(255,255,255,.25), transparent 55%); border-color: transparent; box-shadow: inset 0 1px 0 rgba(255,255,255,.35); }
[class*="btn-outline-"], .btn-light, .btn-secondary { --bs-btn-bg: var(--g-glass-soft); background: var(--g-glass-soft);
    -webkit-backdrop-filter: blur(10px); backdrop-filter: blur(10px); box-shadow: var(--g-shine); }
.btn-outline-secondary, .btn-light { --bs-btn-color: var(--g-text); --bs-btn-border-color: var(--g-line); --bs-btn-hover-bg: var(--g-glass-strong); --bs-btn-hover-color: var(--g-text); --bs-btn-hover-border-color: var(--g-border); --bs-btn-active-bg: var(--g-glass-strong); --bs-btn-active-color: var(--g-text); color: var(--g-text); border-color: var(--g-line); }
.btn-light:hover, .btn-outline-secondary:hover { background: var(--g-glass-strong); color: var(--g-text); }
.btn-outline-primary:hover { background-color: var(--g-accent); } .btn-outline-danger:hover { background-color: #ff453a; } .btn-outline-success:hover { background-color: #30d158; }
.btn:focus-visible, .form-control:focus, .form-select:focus, .form-check-input:focus { box-shadow: 0 0 0 4px rgba(10,132,255,.22) !important; outline: 0; }

/* Forms */
.form-control, .form-select, .input-group-text { background-color: var(--g-glass-strong); color: var(--g-text); border: 1px solid var(--g-line); border-radius: 11px;
    box-shadow: inset 0 1px 2px rgba(15,23,42,.06); transition: border-color .2s, box-shadow .2s, background .2s; }
[data-bs-theme="dark"] .form-control, [data-bs-theme="dark"] .form-select, [data-bs-theme="dark"] .input-group-text { background-color: rgba(255,255,255,.07); box-shadow: inset 0 1px 2px rgba(0,0,0,.3); }
.form-control::placeholder { color: var(--g-muted); opacity: .7; }
.form-control:focus, .form-select:focus { background-color: var(--g-glass-strong); color: var(--g-text); border-color: var(--g-accent); }
.form-control:disabled, .form-control[readonly], .form-select:disabled { background-color: var(--g-glass-soft); opacity: .8; }
.form-label { font-size: .82rem; font-weight: 600; color: var(--g-muted); margin-bottom: .3rem; }
.form-check-input { background-color: var(--g-glass-strong); border-color: var(--g-line); transition: background .2s, box-shadow .2s; }
.form-check-input:checked { background-color: var(--g-accent); border-color: var(--g-accent); }

/* Tables */
.table { --bs-table-bg: transparent; --bs-table-color: var(--g-text); --bs-table-border-color: var(--g-line); --bs-table-hover-bg: rgba(10,132,255,.07); --bs-table-hover-color: var(--g-text); --bs-table-striped-bg: var(--g-glass-soft); margin-bottom: 0; }
.table > :not(caption) > * > * { padding: .65rem .9rem; background-color: transparent; }
.table-light, .table-light > th, .table-light > td { --bs-table-bg: var(--g-glass-soft); --bs-table-color: var(--g-muted); --bs-table-border-color: var(--g-line); }
thead.table-light th { font-size: .72rem; text-transform: uppercase; letter-spacing: .06em; font-weight: 650; color: var(--g-muted); background: var(--g-glass-soft) !important; border-bottom: 1px solid var(--g-line); }
.table-secondary { --bs-table-bg: var(--g-glass-soft); --bs-table-color: var(--g-text); }
.table-hover > tbody > tr { transition: background .2s; }
tfoot td, tfoot th { font-weight: 650; }
.table-responsive { border-radius: inherit; }
.card > .table:last-child, .card > .table-responsive:last-child { border-radius: 0 0 20px 20px; }

/* Badges, alerts, misc */
.badge { border-radius: 999px; font-weight: 600; padding: .38em .7em; letter-spacing: .01em; }
.badge.text-bg-light { background: var(--g-glass-strong) !important; color: var(--g-text) !important; border-color: var(--g-line) !important; }
.badge.text-bg-secondary { background: rgba(120,120,140,.85) !important; }
.alert { border-radius: 16px; border: 1px solid var(--g-border); -webkit-backdrop-filter: blur(16px); backdrop-filter: blur(16px); box-shadow: var(--g-shadow); animation: g-rise .4s var(--g-ease) both; }
.alert-success { background: rgba(48,209,88,.16); color: var(--bs-success-text-emphasis); } .alert-danger { background: rgba(255,69,58,.14); }
.alert-warning { background: rgba(255,159,10,.17); } .alert-info { background: rgba(10,132,255,.14); }
.progress, .bg-primary.rounded { border-radius: 999px !important; }
.bg-primary.rounded { background: linear-gradient(90deg, var(--g-accent), var(--g-accent2)) !important; box-shadow: 0 2px 8px rgba(10,132,255,.35); transition: width .8s var(--g-ease); }
.img-thumbnail { background: var(--g-glass); border-color: var(--g-border); border-radius: 12px; }
hr { border-color: var(--g-line); opacity: 1; }
.pagination { --bs-pagination-bg: var(--g-glass); --bs-pagination-border-color: var(--g-line); --bs-pagination-color: var(--g-text); --bs-pagination-hover-bg: var(--g-glass-strong); --bs-pagination-active-bg: var(--g-accent); --bs-pagination-disabled-bg: var(--g-glass-soft); --bs-pagination-border-radius: 10px; gap: 4px; }
.page-link { border-radius: 10px !important; }

/* Sidebar + header */
:root { --sidebar-w: 262px; }
body .sidebar { position: fixed; width: var(--sidebar-w); overflow-y: auto; background: var(--g-side); -webkit-backdrop-filter: blur(30px) saturate(180%); backdrop-filter: blur(30px) saturate(180%);
    border-right: 1px solid var(--g-border); box-shadow: 8px 0 40px rgba(31,38,80,.08), var(--g-shine); inset: 12px auto 12px 12px; min-height: 0; height: auto; border-radius: 24px; z-index: 1030; transition: transform .35s var(--g-ease); }
.sidebar .brand { color: var(--g-text); font-weight: 650; padding: 1.1rem 1.25rem; border-bottom: 1px solid var(--g-line); letter-spacing: -.02em; display: flex; align-items: center; }
.sidebar .brand i { display: inline-grid; place-items: center; width: 34px; height: 34px; border-radius: 10px; color: #fff; margin-right: .6rem !important; background: linear-gradient(135deg, var(--g-accent), var(--g-accent2)); box-shadow: 0 4px 12px rgba(10,132,255,.4), inset 0 1px 0 rgba(255,255,255,.4); }
.sidebar .heading { color: var(--g-muted); font-size: .68rem; font-weight: 650; padding: 1.1rem 1.4rem .3rem; }
.sidebar ul.nav { padding: 0 .6rem; }
.sidebar a.nav-link { color: var(--g-text); opacity: .78; padding: .5rem .8rem; margin: 1px 0; border-radius: 11px; font-size: .88rem; display: flex; align-items: center; transition: background .2s, opacity .2s, transform .2s var(--g-ease); }
.sidebar a.nav-link i { width: 1.4rem; margin-right: .55rem; font-size: 1.02rem; transition: transform .25s var(--g-ease); }
.sidebar a.nav-link:hover { background: var(--g-glass-soft); opacity: 1; color: var(--g-text); transform: translateX(2px); }
.sidebar a.nav-link:hover i { transform: scale(1.12); }
.sidebar a.nav-link.active { opacity: 1; color: var(--g-text); background: var(--g-glass-strong); font-weight: 600; box-shadow: var(--g-shadow), var(--g-shine); }
.sidebar a.nav-link.active i { color: var(--g-accent); }
.content-wrap { margin-left: calc(var(--sidebar-w) + 24px); padding-right: 12px; }
header.navbar { position: sticky; top: 12px; z-index: 1020; margin: 12px 0 0; padding: .6rem 1.25rem !important; border-radius: 20px; background: var(--g-glass) !important;
    -webkit-backdrop-filter: blur(24px) saturate(180%); backdrop-filter: blur(24px) saturate(180%); border: 1px solid var(--g-border) !important; box-shadow: var(--g-shadow), var(--g-shine); }
header.navbar .navbar-text { font-size: 1.05rem; color: var(--g-text); letter-spacing: -.02em; }
header.navbar .dropdown-toggle { color: var(--g-text) !important; }
.theme-toggle, .menu-toggle { width: 38px; height: 38px; display: inline-grid; place-items: center; border-radius: 12px; border: 1px solid var(--g-line); background: var(--g-glass-soft); color: var(--g-text); transition: transform .3s var(--g-ease), background .2s; }
.theme-toggle:hover, .menu-toggle:hover { background: var(--g-glass-strong); transform: rotate(14deg) scale(1.06); }
.menu-toggle { display: none; } .menu-toggle:hover { transform: scale(1.06); }
main.p-4 { padding: 1.5rem .5rem 2rem !important; }
.sidebar-scrim { display: none; }
@media (max-width: 991px) {
    body .sidebar { position: fixed; width: min(300px, 86vw); inset: 10px auto 10px 10px; transform: translateX(-115%); }
    body.nav-open .sidebar { transform: none; }
    body.nav-open .sidebar-scrim { display: block; position: fixed; inset: 0; z-index: 1025; background: rgba(0,0,0,.3); backdrop-filter: blur(4px); }
    .content-wrap { margin-left: 0; padding: 0 10px; } .menu-toggle { display: inline-grid; }
    main.p-4 { padding: 1rem 0 2rem !important; } .table-responsive, .card > .table { overflow-x: auto; }
}
@media (max-width: 767px) { .card > .table { display: block; overflow-x: auto; white-space: nowrap; } }
@media (max-width: 575px) { .card-body { padding: .9rem; } header.navbar .badge { display: none; } }
@media (prefers-reduced-motion: reduce) { *, *::before, *::after { animation: none !important; transition: none !important; } }
@media (prefers-reduced-transparency: reduce) { .card, .sidebar, header.navbar, .dropdown-menu, .modal-content { backdrop-filter: none !important; -webkit-backdrop-filter: none !important; background: var(--g-glass-strong) !important; } }
@media print {
    body::before { display: none; } body { background: #fff !important; color: #000; }
    .card, .alert { background: #fff !important; backdrop-filter: none !important; box-shadow: none !important; border: 1px solid #ccc !important; border-radius: 6px; }
    .card::before { display: none; } header.navbar { display: none !important; } .content-wrap { margin-left: 0; padding: 0; } main.p-4 { padding: 0 !important; animation: none; }
}

/* Login */
.auth-shell { min-height: 100vh; display: grid; place-items: center; padding: 1.25rem; }
.auth-card { width: 100%; max-width: 430px; border-radius: 28px; animation: g-rise .6s var(--g-ease) both; }
.auth-logo { width: 56px; height: 56px; margin: 0 auto 1rem; display: grid; place-items: center; font-size: 1.5rem; color: #fff; border-radius: 17px; background: linear-gradient(135deg, var(--g-accent), var(--g-accent2)); box-shadow: 0 10px 26px rgba(10,132,255,.4), inset 0 1px 0 rgba(255,255,255,.45); }
.auth-theme { position: fixed; top: 16px; right: 16px; z-index: 5; }
</style>
