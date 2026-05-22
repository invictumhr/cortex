import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useEffect, useState } from 'react';
import BalanceBanner from '@/Components/Cortex/BalanceBanner';
import ChatSidebar from '@/Components/Cortex/ChatSidebar';
import LanguageToggle from '@/Components/Cortex/LanguageToggle';
import { useT } from '@/i18n/I18nProvider';

/**
 * Welcome screen — a single composer + persona picker, with recent chats
 * rolled into the sidebar so this page is purely about starting something.
 * The layout deliberately echoes Claude / GPT (centered headline, big input,
 * suggestion chips below) without copying their exact composition: Cortex's
 * boardroom multi-persona angle is what the configuration rail emphasises.
 */
export default function Index({ chats, personas }) {
    const { t, lang } = useT();
    const { auth, wallet } = usePage().props;
    const user = auth?.user;
    const [showConfig, setShowConfig] = useState(false);

    // Disable "Start discussion" when the wallet is at or below the send
    // floor — a new chat creation immediately tries to dispatch the first
    // turn, and we'd rather block here than fail in the orchestrator.
    const walletEmpty = wallet && wallet.available < (wallet.min_send ?? 0.05);

    const { data, setData, post, processing } = useForm({
        title: '',
        description: '',
        context: '',
        constraints: '',
        strong: false,
        architect: false,
        language: lang,
        persona_ids: [],
    });

    useEffect(() => { setData('language', lang); }, [lang]);

    const togglePersona = (id) => {
        setData(
            'persona_ids',
            data.persona_ids.includes(id)
                ? data.persona_ids.filter((x) => x !== id)
                : [...data.persona_ids, id],
        );
    };

    const submit = (e) => {
        e.preventDefault();
        post('/chats');
    };

    const ready = !processing && (data.architect || data.persona_ids.length > 0) && !walletEmpty;

    return (
        <>
            <Head title="Cortex" />
            <div className="flex h-screen overflow-hidden bg-ink-50 text-ink-800 dark:bg-ink-950 dark:text-ink-200">
                <ChatSidebar chats={chats} activeId={null} />

                <main className="flex min-w-0 flex-1 flex-col">
                    {/* Top bar — minimal, just brand & language */}
                    <header className="flex shrink-0 items-center justify-between border-b border-ink-200/60 px-6 py-3 dark:border-ink-800/60">
                        <span className="text-sm font-medium text-ink-500 dark:text-ink-400">
                            New boardroom
                        </span>
                        <LanguageToggle />
                    </header>

                    <div className="flex-1 overflow-y-auto px-6 py-12">
                        <div className="mx-auto max-w-2xl space-y-8">
                            {/* Hero */}
                            <div className="space-y-3 text-center">
                                <h1 className="text-3xl font-semibold tracking-tight text-ink-900 dark:text-ink-50 sm:text-4xl">
                                    {user?.name?.split(' ')[0] ? `Welcome back, ${user.name.split(' ')[0]}.` : 'What should the boardroom discuss?'}
                                </h1>
                                <p className="text-[15px] text-ink-500 dark:text-ink-400">
                                    Multi-model AI panel — every persona on its own model, one synthesis at the end.
                                </p>
                            </div>

                            {/* Low/empty balance reminder — silent when fine. */}
                            <BalanceBanner />

                            {/* Composer-shaped form */}
                            <form onSubmit={submit} className="surface p-5 space-y-4">
                                <input
                                    value={data.title}
                                    onChange={(e) => setData('title', e.target.value)}
                                    placeholder="Title (optional)"
                                    className="w-full border-0 border-b border-ink-200/60 bg-transparent px-0 py-2 text-base font-medium text-ink-900 placeholder:text-ink-400 focus:border-cortex-500 focus:ring-0 dark:border-ink-700/60 dark:text-ink-100"
                                />
                                <textarea
                                    value={data.description}
                                    onChange={(e) => setData('description', e.target.value)}
                                    placeholder="What should the boardroom discuss?"
                                    rows={3}
                                    className="w-full resize-none border-0 bg-transparent p-0 text-[15px] leading-relaxed text-ink-800 placeholder:text-ink-400 focus:ring-0 dark:text-ink-200"
                                />

                                {/* Mode chips */}
                                <div className="flex flex-wrap items-center gap-2 pt-2">
                                    <ModeChip
                                        active={data.architect}
                                        onClick={() => setData('architect', !data.architect)}
                                        icon={<ArchitectIcon className="h-3.5 w-3.5" />}
                                        label="Architect"
                                        sub="Auto-design panel"
                                    />
                                    <ModeChip
                                        active={data.strong}
                                        onClick={() => setData('strong', !data.strong)}
                                        icon={<SparkIcon className="h-3.5 w-3.5" />}
                                        label="Strong"
                                        sub="Flagship models"
                                    />
                                    <button
                                        type="button"
                                        onClick={() => setShowConfig((v) => !v)}
                                        className="ml-auto inline-flex items-center gap-1.5 rounded-full px-3 py-1.5 text-xs text-ink-500 hover:bg-ink-100 dark:text-ink-400 dark:hover:bg-ink-800"
                                    >
                                        <SlidersIcon className="h-3.5 w-3.5" />
                                        {showConfig ? 'Hide options' : 'More options'}
                                    </button>
                                </div>

                                {/* Persona picker — only when architect is off */}
                                {!data.architect && (
                                    <div>
                                        <div className="mb-2 flex items-center justify-between text-xs font-medium text-ink-500 dark:text-ink-400">
                                            <span>Personas ({data.persona_ids.length} selected)</span>
                                            <span className="text-ink-400">
                                                Pick at least one
                                            </span>
                                        </div>
                                        <div className="grid grid-cols-2 gap-1.5 sm:grid-cols-3">
                                            {personas.map((p) => (
                                                <PersonaTile
                                                    key={p.id}
                                                    persona={p}
                                                    selected={data.persona_ids.includes(p.id)}
                                                    onClick={() => togglePersona(p.id)}
                                                />
                                            ))}
                                        </div>
                                    </div>
                                )}

                                {showConfig && (
                                    <div className="space-y-3 border-t border-ink-200/60 pt-4 dark:border-ink-700/60">
                                        <textarea
                                            value={data.context}
                                            onChange={(e) => setData('context', e.target.value)}
                                            placeholder="Context (data model, current state…)"
                                            rows={3}
                                            className="w-full resize-none rounded-xl border border-ink-200/60 bg-ink-50 px-3 py-2 text-sm text-ink-800 placeholder:text-ink-400 focus:border-cortex-500 focus:ring-1 focus:ring-cortex-500/30 dark:border-ink-700/60 dark:bg-ink-800 dark:text-ink-200"
                                        />
                                        <textarea
                                            value={data.constraints}
                                            onChange={(e) => setData('constraints', e.target.value)}
                                            placeholder="Hard constraints (must / must not)…"
                                            rows={2}
                                            className="w-full resize-none rounded-xl border border-ink-200/60 bg-ink-50 px-3 py-2 text-sm text-ink-800 placeholder:text-ink-400 focus:border-cortex-500 focus:ring-1 focus:ring-cortex-500/30 dark:border-ink-700/60 dark:bg-ink-800 dark:text-ink-200"
                                        />
                                    </div>
                                )}

                                <div className="flex items-center justify-between gap-3 pt-1">
                                    {wallet && (
                                        <span className="text-xs tabular-nums text-ink-400">
                                            Balance: €{(wallet.available ?? 0).toFixed(2)}
                                        </span>
                                    )}
                                    {walletEmpty ? (
                                        <Link
                                            href={wallet.topup_url}
                                            className="btn-primary !bg-rose-600 hover:!bg-rose-500"
                                        >
                                            Top up to start
                                            <ArrowRightIcon className="h-4 w-4" />
                                        </Link>
                                    ) : (
                                        <button
                                            type="submit"
                                            disabled={!ready}
                                            className="btn-primary"
                                        >
                                            Start discussion
                                            <ArrowRightIcon className="h-4 w-4" />
                                        </button>
                                    )}
                                </div>
                            </form>

                            {/* Recent chats — quick jump */}
                            {chats.length > 0 && (
                                <div>
                                    <h2 className="mb-3 px-1 text-xs font-medium uppercase tracking-wider text-ink-400">
                                        Recent
                                    </h2>
                                    <div className="space-y-1">
                                        {chats.slice(0, 5).map((c) => (
                                            <Link
                                                key={c.id}
                                                href={`/chats/${c.id}`}
                                                className="flex items-center justify-between gap-3 rounded-xl px-3 py-2.5 text-sm transition-colors hover:bg-white dark:hover:bg-ink-900"
                                            >
                                                <span className="truncate text-ink-700 dark:text-ink-200">{c.title}</span>
                                                <span className="shrink-0 text-[11px] tabular-nums text-ink-400">
                                                    €{Number(c.total_cost ?? 0).toFixed(4)} · {c.total_messages ?? 0} msgs
                                                </span>
                                            </Link>
                                        ))}
                                    </div>
                                </div>
                            )}
                        </div>
                    </div>
                </main>
            </div>
        </>
    );
}

function ModeChip({ active, onClick, icon, label, sub }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={`group inline-flex items-center gap-2 rounded-full px-3 py-1.5 text-xs font-medium ring-1 transition-colors ${
                active
                    ? 'bg-cortex-50 text-cortex-700 ring-cortex-200 dark:bg-cortex-950/60 dark:text-cortex-200 dark:ring-cortex-800'
                    : 'bg-white text-ink-600 ring-ink-200/60 hover:bg-ink-50 dark:bg-ink-900 dark:text-ink-300 dark:ring-ink-700/60 dark:hover:bg-ink-800'
            }`}
        >
            {icon}
            <span>{label}</span>
            <span className="text-[10px] text-ink-400 group-hover:text-ink-500 dark:text-ink-500 dark:group-hover:text-ink-400">{sub}</span>
        </button>
    );
}

function PersonaTile({ persona, selected, onClick }) {
    return (
        <button
            type="button"
            onClick={onClick}
            className={`flex items-center gap-2 overflow-hidden rounded-xl border px-2.5 py-2 text-left text-sm transition-all duration-150 ease-snap ${
                selected
                    ? 'border-cortex-300 bg-cortex-50 text-cortex-700 dark:border-cortex-700 dark:bg-cortex-950/60 dark:text-cortex-200'
                    : 'border-ink-200/60 bg-white hover:border-ink-300 hover:bg-ink-50 dark:border-ink-700/60 dark:bg-ink-900 dark:hover:border-ink-600 dark:hover:bg-ink-800'
            }`}
        >
            <span
                className="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-sm"
                style={{ backgroundColor: (persona.avatar_color || '#4c5fe6') + '1a', color: persona.avatar_color || '#4c5fe6' }}
            >
                {persona.avatar_emoji || '◆'}
            </span>
            <span className="min-w-0 flex-1">
                <span className="block truncate font-medium text-ink-800 dark:text-ink-100">{persona.name}</span>
                <span className="block truncate text-[11px] text-ink-400">{persona.title}</span>
            </span>
        </button>
    );
}

function ArchitectIcon({ className }) {
    return <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M3 21h18M5 21V7l7-4 7 4v14M9 9h.01M9 13h.01M9 17h.01M15 9h.01M15 13h.01M15 17h.01" /></svg>;
}
function SparkIcon({ className }) {
    return <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M12 3v3M12 18v3M3 12h3M18 12h3M5.6 5.6l2.1 2.1M16.3 16.3l2.1 2.1M5.6 18.4l2.1-2.1M16.3 7.7l2.1-2.1" /></svg>;
}
function SlidersIcon({ className }) {
    return <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M4 21v-7M4 10V3M12 21v-9M12 8V3M20 21v-5M20 12V3M1 14h6M9 8h6M17 16h6" /></svg>;
}
function ArrowRightIcon({ className }) {
    return <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M5 12h14M12 5l7 7-7 7" /></svg>;
}
