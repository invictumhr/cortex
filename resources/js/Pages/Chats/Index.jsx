import { Head, Link, useForm, usePage } from '@inertiajs/react';
import { useEffect, useMemo, useState } from 'react';
import BalanceBanner from '@/Components/Cortex/BalanceBanner';
import ChatSidebar from '@/Components/Cortex/ChatSidebar';
import LanguageToggle from '@/Components/Cortex/LanguageToggle';
import { useT } from '@/i18n/I18nProvider';

/**
 * Welcome screen — three init modes for the new boardroom:
 *
 *   QUICK         — pick head-count only. System picks models tier from the
 *                   first message's complexity and personas from architect.
 *   CUSTOM MODELS — pick exact models. One auto-generated persona per model.
 *   CUSTOM        — pick (persona, model) pairs by hand. Same persona may
 *                   appear twice with different models.
 *
 * Mobile layout:
 *   Sidebar fills the screen (chat list). Tapping "New Chat" slides a form
 *   overlay in from the right. Tapping a chat navigates to Show.
 *
 * Desktop layout:
 *   Sidebar (left) + form content (right), as before.
 */
export default function Index({ chats, personas, models }) {
    const { t, lang } = useT();
    const { auth, wallet } = usePage().props;
    const user = auth?.user;
    const walletEmpty = wallet && wallet.available < (wallet.min_send ?? 0.05);

    const [mode, setMode] = useState('quick');
    const [showForm, setShowForm] = useState(false);

    const { data, setData, post, processing } = useForm({
        title: '',
        description: '',
        context: '',
        constraints: '',
        strong: false,
        language: lang,
        init_mode: 'quick',
        agents: 5,
        model_ids: [],
        pairs: [],
    });
    useEffect(() => { setData('language', lang); }, [lang]);
    useEffect(() => { setData('init_mode', mode); }, [mode]);

    const submit = (e) => {
        e.preventDefault();
        post('/chats');
    };

    const ready = !processing && !walletEmpty && (
        (mode === 'quick' && data.agents >= 2) ||
        (mode === 'custom_models' && data.model_ids.length >= 2) ||
        (mode === 'custom' && data.pairs.length >= 2)
    );

    const formContent = (
        <>
            <BalanceBanner />

            <form onSubmit={submit} className="surface p-4 space-y-5 sm:p-5">
                <input
                    value={data.title}
                    onChange={(e) => setData('title', e.target.value)}
                    placeholder={t('welcome.titlePh')}
                    className="w-full border-0 border-b border-ink-200/60 bg-transparent px-0 py-2 text-base font-medium text-ink-900 placeholder:text-ink-400 focus:border-cortex-500 focus:ring-0 dark:border-ink-700/60 dark:text-ink-100"
                />
                <textarea
                    value={data.description}
                    onChange={(e) => setData('description', e.target.value)}
                    placeholder={t('welcome.topicPh')}
                    rows={3}
                    className="w-full resize-none border-0 bg-transparent p-0 text-base leading-relaxed text-ink-800 placeholder:text-ink-400 focus:ring-0 dark:text-ink-200"
                />

                <ModeTabs value={mode} onChange={setMode} t={t} />

                {mode === 'quick' && (
                    <QuickPanel
                        t={t}
                        agents={data.agents}
                        onAgents={(n) => setData('agents', n)}
                        strong={data.strong}
                        onStrong={(v) => setData('strong', v)}
                    />
                )}
                {mode === 'custom_models' && (
                    <ModelsPanel
                        t={t}
                        models={models}
                        selected={data.model_ids}
                        onChange={(ids) => setData('model_ids', ids)}
                    />
                )}
                {mode === 'custom' && (
                    <CustomPanel
                        t={t}
                        personas={personas}
                        models={models}
                        pairs={data.pairs}
                        onChange={(p) => setData('pairs', p)}
                    />
                )}

                <div className="flex items-center justify-between gap-3 pt-1">
                    {wallet && (
                        <span className="text-xs tabular-nums text-ink-400">
                            {t('welcome.balance', { balance: (wallet.available ?? 0).toFixed(2) })}
                        </span>
                    )}
                    {walletEmpty ? (
                        <a href={wallet.topup_url} className="btn-primary !bg-rose-600 hover:!bg-rose-500">
                            {t('welcome.topupBtn')}
                            <ArrowRightIcon className="h-4 w-4" />
                        </a>
                    ) : (
                        <button type="submit" disabled={!ready} className="btn-primary">
                            {t('welcome.startBtn')}
                            <ArrowRightIcon className="h-4 w-4" />
                        </button>
                    )}
                </div>
            </form>
        </>
    );

    return (
        <>
            <Head title="Cortex" />
            <div className="flex h-screen overflow-hidden bg-ink-50 text-ink-800 dark:bg-ink-950 dark:text-ink-200">
                {/* Sidebar — full-width on mobile, fixed on desktop.
                    On mobile: onNewChat opens the form overlay. */}
                <ChatSidebar chats={chats} activeId={null} onNewChat={() => setShowForm(true)} />

                {/* Desktop content — hidden on mobile */}
                <main className="hidden min-w-0 flex-1 flex-col sm:flex">
                    <header className="flex shrink-0 items-center justify-between border-b border-ink-200/60 px-6 py-3 dark:border-ink-800/60">
                        <span className="text-sm font-medium text-ink-500 dark:text-ink-400">
                            {t('index.newBoardroom')}
                        </span>
                        <LanguageToggle />
                    </header>

                    <div className="flex-1 overflow-y-auto px-6 py-12">
                        <div className="mx-auto max-w-2xl space-y-8">
                            <div className="space-y-3 text-center">
                                <h1 className="text-3xl font-semibold tracking-tight text-ink-900 sm:text-4xl dark:text-ink-50">
                                    {user?.name?.split(' ')[0]
                                        ? t('welcome.greeting', { name: user.name.split(' ')[0] })
                                        : t('welcome.greetingAnon')}
                                </h1>
                                <p className="text-[15px] text-ink-500 dark:text-ink-400">
                                    {t('welcome.lead')}
                                </p>
                            </div>

                            {formContent}

                            {chats.length > 0 && (
                                <div>
                                    <h2 className="mb-3 px-1 text-xs font-medium uppercase tracking-wider text-ink-400">
                                        {t('welcome.recent')}
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

                {/* Mobile form overlay — slides in from right */}
                <div
                    className={`fixed inset-0 z-40 flex flex-col bg-ink-50 transition-transform duration-300 ease-snap sm:hidden dark:bg-ink-950 ${
                        showForm ? 'translate-x-0' : 'translate-x-full pointer-events-none'
                    }`}
                >
                    <header className="flex shrink-0 items-center gap-3 border-b border-ink-200/60 px-3 py-3 dark:border-ink-800/60">
                        <button
                            type="button"
                            onClick={() => setShowForm(false)}
                            className="flex h-8 w-8 items-center justify-center rounded-lg text-ink-500 hover:bg-ink-100 dark:hover:bg-ink-800"
                        >
                            <BackIcon className="h-5 w-5" />
                        </button>
                        <span className="flex-1 text-sm font-medium text-ink-700 dark:text-ink-200">
                            {t('index.newBoardroom')}
                        </span>
                        <LanguageToggle />
                    </header>

                    <div className="flex-1 overflow-y-auto px-4 py-6">
                        <div className="mx-auto max-w-lg space-y-6">
                            <div className="space-y-2 text-center">
                                <h1 className="text-2xl font-semibold tracking-tight text-ink-900 dark:text-ink-50">
                                    {user?.name?.split(' ')[0]
                                        ? t('welcome.greeting', { name: user.name.split(' ')[0] })
                                        : t('welcome.greetingAnon')}
                                </h1>
                                <p className="text-sm text-ink-500 dark:text-ink-400">
                                    {t('welcome.lead')}
                                </p>
                            </div>

                            {formContent}
                        </div>
                    </div>
                </div>
            </div>
        </>
    );
}

/* ────────────────────── 3-tab segmented control ─────────────────────── */
function ModeTabs({ value, onChange, t }) {
    const tabs = [
        { id: 'quick',         labelKey: 'mode.quick.label',         hintKey: 'mode.quick.hint' },
        { id: 'custom_models', labelKey: 'mode.custommodels.label',  hintKey: 'mode.custommodels.hint' },
        { id: 'custom',        labelKey: 'mode.custom.label',        hintKey: 'mode.custom.hint' },
    ];
    return (
        <div className="grid grid-cols-3 gap-1 rounded-2xl bg-ink-100/60 p-1 ring-1 ring-ink-200/60 dark:bg-ink-800/60 dark:ring-ink-700/60">
            {tabs.map((tab) => {
                const active = value === tab.id;
                return (
                    <button
                        type="button"
                        key={tab.id}
                        onClick={() => onChange(tab.id)}
                        className={`flex flex-col items-center gap-0.5 rounded-xl px-2 py-2 text-xs font-medium transition-all duration-150 ease-snap sm:px-3 ${
                            active
                                ? 'bg-white text-cortex-700 shadow-soft dark:bg-ink-900 dark:text-cortex-200'
                                : 'text-ink-500 hover:text-ink-700 dark:text-ink-400 dark:hover:text-ink-200'
                        }`}
                    >
                        <span>{t(tab.labelKey)}</span>
                        <span className="hidden text-[10px] text-ink-400 sm:block">{t(tab.hintKey)}</span>
                    </button>
                );
            })}
        </div>
    );
}

/* ─────────────────────── Quick — head-count picker ───────────────────── */
function QuickPanel({ agents, onAgents, strong, onStrong, t }) {
    const options = [2, 3, 4, 5, 6, 7, 8];
    return (
        <div className="space-y-3">
            <div>
                <div className="mb-2 text-xs font-medium text-ink-500 dark:text-ink-400">
                    {t('quick.label')}
                </div>
                <div className="flex flex-wrap gap-1.5">
                    {options.map((n) => (
                        <button
                            key={n}
                            type="button"
                            onClick={() => onAgents(n)}
                            className={`flex h-10 w-10 items-center justify-center rounded-full text-sm font-semibold transition-all duration-150 ease-snap ${
                                agents === n
                                    ? 'bg-cortex-600 text-white shadow-soft'
                                    : 'bg-ink-100 text-ink-700 hover:bg-ink-200 dark:bg-ink-800 dark:text-ink-200 dark:hover:bg-ink-700'
                            }`}
                        >
                            {n}
                        </button>
                    ))}
                </div>
                <p className="mt-2 text-[11px] text-ink-400">
                    {t('quick.hint')}
                </p>
            </div>

            <label className="flex cursor-pointer items-start gap-2 text-xs text-ink-600 dark:text-ink-300">
                <input
                    type="checkbox"
                    checked={strong}
                    onChange={(e) => onStrong(e.target.checked)}
                    className="mt-0.5 rounded border-ink-300 text-cortex-600 focus:ring-cortex-500"
                />
                <span>
                    <span className="font-medium">{t('quick.strongLabel')}</span>
                    <span className="block text-[10px] text-ink-400">
                        {t('quick.strongHint')}
                    </span>
                </span>
            </label>
        </div>
    );
}

/* ────────────────── Custom models — checkbox grid ────────────────────── */
function ModelsPanel({ models, selected, onChange, t }) {
    const byProvider = useMemo(() => {
        const out = {};
        for (const m of models) {
            (out[m.provider] ||= []).push(m);
        }
        return out;
    }, [models]);

    const toggle = (id) => {
        onChange(selected.includes(id) ? selected.filter((x) => x !== id) : [...selected, id]);
    };

    return (
        <div className="space-y-3">
            <div className="flex items-center justify-between text-xs">
                <span className="font-medium text-ink-500 dark:text-ink-400">
                    {t('models.label')}
                </span>
                <span className="tabular-nums text-ink-400">
                    {t('models.selected', { n: selected.length })}
                </span>
            </div>
            <div className="max-h-72 space-y-3 overflow-y-auto rounded-xl border border-ink-200/60 p-3 dark:border-ink-700/60">
                {Object.entries(byProvider).map(([provider, list]) => (
                    <div key={provider}>
                        <div className="mb-1.5 text-[10px] font-medium uppercase tracking-wider text-ink-400">{provider}</div>
                        <div className="grid grid-cols-2 gap-1.5 sm:grid-cols-3">
                            {list.map((m) => {
                                const on = selected.includes(m.id);
                                return (
                                    <button
                                        key={m.id}
                                        type="button"
                                        onClick={() => toggle(m.id)}
                                        title={`In €${m.in_cost}/1M · Out €${m.out_cost}/1M`}
                                        className={`truncate rounded-lg px-2 py-1.5 text-left text-xs font-medium ring-1 transition-colors ${
                                            on
                                                ? 'bg-cortex-50 text-cortex-700 ring-cortex-300 dark:bg-cortex-950/60 dark:text-cortex-200 dark:ring-cortex-700'
                                                : 'bg-white text-ink-700 ring-ink-200/60 hover:bg-ink-50 dark:bg-ink-900 dark:text-ink-200 dark:ring-ink-700/60 dark:hover:bg-ink-800'
                                        }`}
                                    >
                                        {m.model_string}
                                    </button>
                                );
                            })}
                        </div>
                    </div>
                ))}
            </div>
            <p className="text-[11px] text-ink-400">
                {t('models.hint')}
            </p>
        </div>
    );
}

/* ───────────────── Custom — persona + model pair adder ───────────────── */
function CustomPanel({ personas, models, pairs, onChange, t }) {
    const [personaId, setPersonaId] = useState(personas[0]?.id ?? null);
    const [modelId, setModelId] = useState(null);

    useEffect(() => {
        const p = personas.find((x) => x.id === personaId);
        if (p?.ai_model_id) setModelId(p.ai_model_id);
    }, [personaId, personas]);

    const add = () => {
        if (!personaId || !modelId) return;
        onChange([...pairs, { persona_id: personaId, ai_model_id: modelId }]);
    };
    const remove = (i) => onChange(pairs.filter((_, idx) => idx !== i));

    const personaName = (id) => personas.find((p) => p.id === id)?.name || '?';
    const modelLabel = (id) => {
        const m = models.find((x) => x.id === id);
        return m ? `${m.model_string} (${m.provider})` : '?';
    };

    return (
        <div className="space-y-3">
            <div className="grid grid-cols-1 gap-2 sm:grid-cols-[1fr_1fr_auto]">
                <select
                    value={personaId ?? ''}
                    onChange={(e) => setPersonaId(Number(e.target.value))}
                    className="rounded-xl border-ink-200/60 bg-white text-base text-ink-800 focus:border-cortex-500 focus:ring-cortex-500/30 sm:text-sm dark:border-ink-700/60 dark:bg-ink-900 dark:text-ink-100"
                >
                    {personas.map((p) => (
                        <option key={p.id} value={p.id}>
                            {p.avatar_emoji} {p.name} — {p.title}
                        </option>
                    ))}
                </select>
                <select
                    value={modelId ?? ''}
                    onChange={(e) => setModelId(Number(e.target.value))}
                    className="rounded-xl border-ink-200/60 bg-white text-base text-ink-800 focus:border-cortex-500 focus:ring-cortex-500/30 sm:text-sm dark:border-ink-700/60 dark:bg-ink-900 dark:text-ink-100"
                >
                    <option value="">{t('custom.pickModel')}</option>
                    {models.map((m) => (
                        <option key={m.id} value={m.id}>
                            {m.model_string} ({m.provider})
                        </option>
                    ))}
                </select>
                <button
                    type="button"
                    onClick={add}
                    disabled={!personaId || !modelId}
                    className="btn-primary px-4"
                >
                    {t('custom.add')}
                </button>
            </div>

            <div className="text-xs font-medium text-ink-500 dark:text-ink-400">
                {t('custom.panelLabel', { n: pairs.length })}
            </div>

            {pairs.length === 0 ? (
                <p className="rounded-xl border border-dashed border-ink-200 p-4 text-center text-xs text-ink-400 dark:border-ink-700">
                    {t('custom.emptyHint')}
                </p>
            ) : (
                <ul className="space-y-1.5">
                    {pairs.map((pair, i) => (
                        <li
                            key={i}
                            className="flex items-center justify-between gap-2 rounded-xl bg-ink-100/60 px-3 py-2 text-sm dark:bg-ink-800/60"
                        >
                            <span className="min-w-0 truncate">
                                <span className="font-medium text-ink-900 dark:text-ink-100">{personaName(pair.persona_id)}</span>
                                <span className="mx-1.5 text-ink-400">→</span>
                                <span className="text-ink-700 dark:text-ink-300">{modelLabel(pair.ai_model_id)}</span>
                            </span>
                            <button
                                type="button"
                                onClick={() => remove(i)}
                                className="rounded-md p-1 text-ink-400 hover:bg-ink-200 hover:text-red-500 dark:hover:bg-ink-700"
                                aria-label="Remove"
                            >
                                <XIcon className="h-3.5 w-3.5" />
                            </button>
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}

function BackIcon({ className }) {
    return <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M19 12H5M12 19l-7-7 7-7" /></svg>;
}
function ArrowRightIcon({ className }) {
    return <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M5 12h14M12 5l7 7-7 7" /></svg>;
}
function XIcon({ className }) {
    return <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M18 6L6 18M6 6l12 12" /></svg>;
}
