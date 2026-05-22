import { Link, router, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useRef, useState } from 'react';
import ThemeToggle from './ThemeToggle';
import { useT } from '@/i18n/I18nProvider';

/**
 * App sidebar — chat list grouped by "Today / Yesterday / Earlier", new-chat
 * button, account footer with theme + sign-out. Collapses to a 56px rail on
 * narrow screens so the chat content gets the breathing room.
 */
export default function ChatSidebar({ chats, activeId }) {
    const { t } = useT();
    const user = usePage().props.auth?.user;
    const [collapsed, setCollapsed] = useState(false);

    // Optimistic-hide set — adding a chat id here removes its row from the
    // sidebar the same tick the user clicks Archive or Delete. The actual
    // request fires in the background; if it fails the id is removed and an
    // alert surfaces. On a real DB-backed Inertia reload the chat will
    // already be gone from `chats`, so the set just naturally empties.
    const [hiddenIds, setHiddenIds] = useState(() => new Set());

    const hide = (id) => setHiddenIds((s) => new Set(s).add(id));
    const unhide = (id) => setHiddenIds((s) => {
        const next = new Set(s);
        next.delete(id);
        return next;
    });

    const visibleChats = useMemo(
        () => (chats ?? []).filter((c) => !hiddenIds.has(c.id)),
        [chats, hiddenIds],
    );
    const groups = useMemo(() => groupByDay(visibleChats), [visibleChats]);

    return (
        <aside
            className={`relative flex shrink-0 flex-col border-r border-ink-200/60 bg-white text-ink-700 transition-[width] duration-200 ease-snap dark:border-ink-800/60 dark:bg-ink-900 dark:text-ink-200 ${
                collapsed ? 'w-14' : 'w-64'
            }`}
        >
            <div className="flex items-center justify-between gap-2 px-3 py-3">
                <Link
                    href="/chats"
                    className={`flex items-center gap-2 overflow-hidden text-sm font-semibold tracking-tight ${
                        collapsed ? 'justify-center' : ''
                    }`}
                >
                    <BrandMark className="h-6 w-6 shrink-0" />
                    {!collapsed && <span className="text-ink-900 dark:text-ink-50">Cortex</span>}
                </Link>
                <button
                    onClick={() => setCollapsed((v) => !v)}
                    className="rounded-lg p-1 text-ink-500 transition-colors hover:bg-ink-100 hover:text-ink-700 dark:hover:bg-ink-800 dark:hover:text-ink-200"
                    title={collapsed ? t('sidebar.expand') : t('sidebar.collapse')}
                >
                    <ChevronIcon className={`h-4 w-4 transition-transform ${collapsed ? 'rotate-180' : ''}`} />
                </button>
            </div>

            <div className="px-2 pb-2">
                <Link
                    href="/chats"
                    className={`flex items-center gap-2 rounded-xl bg-cortex-600 px-3 py-2 text-sm font-medium text-white shadow-soft transition-colors hover:bg-cortex-500 ${
                        collapsed ? 'justify-center' : ''
                    }`}
                >
                    <PlusIcon className="h-4 w-4 shrink-0" />
                    {!collapsed && <span>{t('sidebar.newChat')}</span>}
                </Link>
            </div>

            <nav className="flex-1 overflow-y-auto px-1.5 pb-2">
                {Object.entries(groups).map(([labelKey, items]) => (
                    <div key={labelKey} className="mt-2">
                        {!collapsed && (
                            <div className="px-2.5 pb-1 text-[11px] font-medium uppercase tracking-wider text-ink-400 dark:text-ink-500">
                                {t(labelKey)}
                            </div>
                        )}
                        <div className="space-y-0.5">
                            {items.map((c) => (
                                <ChatItem
                                    key={c.id}
                                    chat={c}
                                    active={c.id === activeId}
                                    collapsed={collapsed}
                                    onHide={hide}
                                    onUnhide={unhide}
                                />
                            ))}
                        </div>
                    </div>
                ))}
                {visibleChats.length === 0 && !collapsed && (
                    <div className="mt-6 px-3 text-center text-xs text-ink-400">
                        {t('sidebar.empty')}
                    </div>
                )}
            </nav>

            <div className="border-t border-ink-200/60 px-2 py-2 dark:border-ink-800/60">
                {!collapsed && (
                    <div className="mb-2 flex items-center justify-between px-1">
                        <ThemeToggle />
                        <a
                            href="/admin"
                            className="rounded-lg px-2 py-1 text-[11px] text-ink-400 transition-colors hover:bg-ink-100 hover:text-ink-700 dark:hover:bg-ink-800 dark:hover:text-ink-200"
                        >
                            {t('sidebar.admin')}
                        </a>
                    </div>
                )}
                <a
                    href="/user"
                    className={`flex items-center gap-2 rounded-xl px-2 py-1.5 text-sm text-ink-700 transition-colors hover:bg-ink-100 dark:text-ink-200 dark:hover:bg-ink-800 ${
                        collapsed ? 'justify-center' : ''
                    }`}
                >
                    <Avatar name={user?.name} />
                    {!collapsed && (
                        <span className="min-w-0 flex-1 truncate text-left">
                            <span className="block truncate text-sm">{user?.name}</span>
                            <span className="block truncate text-[11px] text-ink-400">{user?.email}</span>
                        </span>
                    )}
                </a>
            </div>
        </aside>
    );
}

function ChatItem({ chat, active, collapsed, onHide, onUnhide }) {
    const { t } = useT();
    const [menuOpen, setMenuOpen] = useState(false);
    const [confirmDelete, setConfirmDelete] = useState(false);
    const menuRef = useRef(null);

    // Click-outside closes the popover. Pointerdown not click so we beat
    // the next ChatItem's potential click — keeps menu UX feeling instant.
    useEffect(() => {
        if (!menuOpen) return;
        const close = (e) => {
            if (menuRef.current && !menuRef.current.contains(e.target)) setMenuOpen(false);
        };
        document.addEventListener('pointerdown', close);
        return () => document.removeEventListener('pointerdown', close);
    }, [menuOpen]);

    /**
     * Optimistic remove: hide the row immediately, fire the request in the
     * background. If the request fails, un-hide and surface the error. On
     * the next Inertia visit the freshly fetched chat list won't include
     * this row anyway, so the local hidden set stays small.
     */
    const optimisticRemove = async (httpMethod, url) => {
        onHide?.(chat.id);
        setMenuOpen(false);
        try {
            await window.axios[httpMethod](url);
        } catch (err) {
            onUnhide?.(chat.id);
            // eslint-disable-next-line no-alert
            window.alert(err.response?.data?.error || 'Action failed.');
        }
    };

    const archive = (e) => {
        e.preventDefault();
        e.stopPropagation();
        optimisticRemove('post', `/chats/${chat.id}/archive`);
    };

    const askDelete = (e) => {
        e.preventDefault();
        e.stopPropagation();
        setMenuOpen(false);
        setConfirmDelete(true);
    };

    const doDelete = () => {
        setConfirmDelete(false);
        optimisticRemove('delete', `/chats/${chat.id}`);
    };

    return (
        // `group` lives on the wrapping div, not the inner Link — the (...)
        // button is a sibling of Link, and we want hovering ANYWHERE in the
        // row (Link area OR over the button itself) to keep the button
        // visible. Previous version had `group` on Link, so moving the
        // cursor from Link onto the button broke the hover state.
        <div className="group relative">
            <Link
                href={`/chats/${chat.id}`}
                className={`flex items-center gap-2 rounded-xl px-2.5 py-2 pr-7 text-sm transition-colors ${
                    active
                        ? 'bg-cortex-50 text-cortex-700 ring-1 ring-cortex-100 dark:bg-cortex-950/60 dark:text-cortex-200 dark:ring-cortex-900/60'
                        : 'text-ink-700 hover:bg-ink-100 dark:text-ink-200 dark:hover:bg-ink-800/60'
                }`}
                title={chat.title}
            >
                <ChatDot active={active} status={chat.status} />
                {!collapsed && (
                    <span className="min-w-0 flex-1 truncate">{chat.title || 'Untitled'}</span>
                )}
            </Link>

            {/* (...) actions button — absolute-positioned so the Link spans
                the full row but the menu trigger captures clicks separately.
                Hidden by default, shown on row hover or while its menu is
                open. Same gate for every row regardless of active state — the
                actions belong to the chat, not to "the currently selected"
                chat specifically. */}
            {!collapsed && (
                <button
                    type="button"
                    onClick={(e) => {
                        e.preventDefault();
                        e.stopPropagation();
                        setMenuOpen((v) => !v);
                    }}
                    title={t('chat.actions.open')}
                    className={`absolute right-1 top-1/2 -translate-y-1/2 flex h-6 w-6 items-center justify-center rounded-md text-ink-400 transition-opacity hover:bg-ink-200 hover:text-ink-700 dark:hover:bg-ink-700 dark:hover:text-ink-200 ${
                        menuOpen ? 'opacity-100' : 'opacity-0 group-hover:opacity-100 focus-visible:opacity-100'
                    }`}
                >
                    <DotsIcon className="h-3.5 w-3.5" />
                </button>
            )}

            {menuOpen && (
                <div
                    ref={menuRef}
                    className="absolute right-1 top-9 z-30 min-w-[140px] rounded-xl bg-white p-1 shadow-pop ring-1 ring-ink-200/60 dark:bg-ink-800 dark:ring-ink-700/60"
                >
                    <button
                        type="button"
                        onClick={archive}
                        className="flex w-full items-center gap-2 rounded-lg px-2.5 py-1.5 text-left text-xs text-ink-700 hover:bg-ink-100 dark:text-ink-200 dark:hover:bg-ink-700"
                    >
                        <ArchiveIcon className="h-3.5 w-3.5" />
                        {t('chat.actions.archive')}
                    </button>
                    <button
                        type="button"
                        onClick={askDelete}
                        className="flex w-full items-center gap-2 rounded-lg px-2.5 py-1.5 text-left text-xs text-rose-600 hover:bg-rose-50 dark:text-rose-400 dark:hover:bg-rose-900/30"
                    >
                        <TrashIcon className="h-3.5 w-3.5" />
                        {t('chat.actions.delete')}
                    </button>
                </div>
            )}

            {confirmDelete && (
                <DeleteConfirmModal
                    title={chat.title}
                    onConfirm={doDelete}
                    onCancel={() => setConfirmDelete(false)}
                />
            )}
        </div>
    );
}

/** Modal that asks the user to commit to an irreversible delete. */
function DeleteConfirmModal({ title, onConfirm, onCancel }) {
    const { t } = useT();
    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center bg-ink-950/50 p-4">
            <div className="surface w-full max-w-sm p-5 animate-fade-up">
                <div className="mb-2 flex items-center gap-2">
                    <TrashIcon className="h-5 w-5 text-rose-500" />
                    <h3 className="text-sm font-semibold text-ink-900 dark:text-ink-50">
                        {t('chat.actions.deleteConfirmTitle')}
                    </h3>
                </div>
                <p className="text-sm text-ink-600 dark:text-ink-300">
                    {t('chat.actions.deleteConfirmBody')}
                </p>
                <p className="mt-2 truncate rounded-lg bg-ink-100 px-2.5 py-1.5 text-xs text-ink-700 dark:bg-ink-800 dark:text-ink-200">
                    {title || 'Untitled'}
                </p>
                <div className="mt-4 flex justify-end gap-2">
                    <button type="button" onClick={onCancel} className="btn-soft">
                        {t('chat.actions.deleteConfirmNo')}
                    </button>
                    <button
                        type="button"
                        onClick={onConfirm}
                        className="btn-primary !bg-rose-600 hover:!bg-rose-500"
                    >
                        {t('chat.actions.deleteConfirmYes')}
                    </button>
                </div>
            </div>
        </div>
    );
}

function DotsIcon({ className }) {
    return (
        <svg className={className} viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">
            <circle cx="5"  cy="12" r="1.6" />
            <circle cx="12" cy="12" r="1.6" />
            <circle cx="19" cy="12" r="1.6" />
        </svg>
    );
}
function ArchiveIcon({ className }) {
    return (
        <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
            <rect x="3" y="4" width="18" height="4" rx="1" />
            <path d="M5 8v11a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V8M10 12h4" />
        </svg>
    );
}
function TrashIcon({ className }) {
    return (
        <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
            <path d="M3 6h18M8 6V4a1 1 0 0 1 1-1h6a1 1 0 0 1 1 1v2M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6M10 11v6M14 11v6" />
        </svg>
    );
}

function ChatDot({ active, status }) {
    const color = status === 'active' ? 'bg-emerald-500' : 'bg-ink-300 dark:bg-ink-600';
    return <span className={`h-1.5 w-1.5 shrink-0 rounded-full ${active ? 'bg-cortex-500' : color}`} />;
}

function Avatar({ name }) {
    const initials = (name || '?').split(/\s+/).map((p) => p[0]).slice(0, 2).join('').toUpperCase();
    return (
        <span className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-cortex-100 text-[11px] font-semibold text-cortex-700 dark:bg-cortex-950 dark:text-cortex-200">
            {initials}
        </span>
    );
}

function BrandMark({ className }) {
    // A simple monogram — three stacked nodes, gradient-filled. Distinct from
    // Claude's spark and GPT's hex, hints at the multi-persona core.
    return (
        <svg className={className} viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
            <defs>
                <linearGradient id="cortexBrand" x1="0" y1="0" x2="24" y2="24" gradientUnits="userSpaceOnUse">
                    <stop offset="0" stopColor="#4c5fe6" />
                    <stop offset="1" stopColor="#7c3aed" />
                </linearGradient>
            </defs>
            <circle cx="6"  cy="8"  r="3" fill="url(#cortexBrand)" opacity="0.85" />
            <circle cx="18" cy="8"  r="3" fill="url(#cortexBrand)" opacity="0.65" />
            <circle cx="12" cy="17" r="3" fill="url(#cortexBrand)" />
        </svg>
    );
}

function PlusIcon({ className }) {
    return (
        <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
            <path d="M12 5v14M5 12h14" />
        </svg>
    );
}

function ChevronIcon({ className }) {
    return (
        <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
            <path d="M15 18l-6-6 6-6" />
        </svg>
    );
}

function groupByDay(chats) {
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const yesterday = new Date(today);
    yesterday.setDate(yesterday.getDate() - 1);

    // Keys are i18n keys, not visible text — render-side calls t(labelKey) so
    // a HR/EN toggle flips group headings without rebuilding the structure.
    const out = { 'sidebar.today': [], 'sidebar.yesterday': [], 'sidebar.earlier': [] };
    for (const chat of chats) {
        const d = chat.updated_at ? new Date(chat.updated_at) : new Date(chat.created_at || Date.now());
        const day = new Date(d);
        day.setHours(0, 0, 0, 0);
        if (day.getTime() === today.getTime()) out['sidebar.today'].push(chat);
        else if (day.getTime() === yesterday.getTime()) out['sidebar.yesterday'].push(chat);
        else out['sidebar.earlier'].push(chat);
    }
    // Drop empty buckets so headings don't dangle.
    return Object.fromEntries(Object.entries(out).filter(([, v]) => v.length > 0));
}
