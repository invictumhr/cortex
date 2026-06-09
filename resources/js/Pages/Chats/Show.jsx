import { Head, Link, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import BalanceBanner from '@/Components/Cortex/BalanceBanner';
import ChatInputBar from '@/Components/Cortex/ChatInputBar';
import ChatSidebar from '@/Components/Cortex/ChatSidebar';
import MessageBubble from '@/Components/Cortex/MessageBubble';
import PersonaInfoPanel from '@/Components/Cortex/PersonaInfoPanel';
import PowerShellModal from '@/Components/Cortex/PowerShellModal';
import { useT } from '@/i18n/I18nProvider';

export default function Show({
    chat: initialChat,
    messages: initialMessages,
    allPersonas,
    chats,
    powershellEnabled,
}) {
    const { t, lang } = useT();
    const [chat, setChat] = useState(initialChat);
    const [messages, setMessages] = useState(initialMessages);
    const [personas, setPersonas] = useState(initialChat.personas ?? []);
    const [typing, setTyping] = useState(null);
    const [sending, setSending] = useState(false);
    const [showPs, setShowPs] = useState(false);
    const [drawerOpen, setDrawerOpen] = useState(false);

    const scrollRef = useRef(null);
    const seenIds = useRef(new Set(initialMessages.map((m) => m.id)));
    const animateIds = useRef(new Set());

    useEffect(() => {
        scrollRef.current?.scrollTo({ top: scrollRef.current.scrollHeight, behavior: 'smooth' });
    }, [messages, typing]);

    useEffect(() => {
        const channel = window.Echo.private(`chat.${chat.id}`);
        channel.listen('.message.created', (e) => {
            const m = e.message;
            if (seenIds.current.has(m.id)) return;
            seenIds.current.add(m.id);
            if (m.role === 'persona') animateIds.current.add(m.id);
            setMessages((prev) => [...prev, m]);
            setTyping(null);
        });
        channel.listen('.persona.typing', (e) => {
            setTyping({ name: e.persona_name, round: e.round });
        });
        channel.listen('.round.completed', (e) => {
            setChat((c) => ({ ...c, current_round: e.current_round }));
        });
        channel.listen('.turn.completed', (e) => {
            setTyping(null);
            setChat((c) => ({ ...c, status: e.status }));
        });
        channel.listen('.cost.updated', (e) => {
            setChat((c) => ({
                ...c,
                total_cost: e.total_cost,
                total_input_tokens: e.total_input_tokens,
                total_output_tokens: e.total_output_tokens,
                total_messages: e.total_messages,
                current_round: e.current_round,
            }));
        });
        return () => window.Echo.leave(`chat.${chat.id}`);
    }, [chat.id]);

    useEffect(() => {
        const ping = () => window.axios.post(`/chats/${chat.id}/heartbeat`).catch(() => {});
        const leave = () => navigator.sendBeacon(`/chats/${chat.id}/leave`);
        ping();
        const timer = setInterval(ping, 20000);
        window.addEventListener('pagehide', leave);
        return () => {
            clearInterval(timer);
            window.removeEventListener('pagehide', leave);
            leave();
        };
    }, [chat.id]);

    const [sendError, setSendError] = useState(null);

    const sendMessage = async ({ content, url, image }) => {
        setSending(true);
        setSendError(null);
        setChat((c) => ({ ...c, status: 'active' }));

        const form = new FormData();
        form.append('content', content);
        if (lang) form.append('language', lang);
        if (url) form.append('url', url);
        if (image) form.append('image', image);
        try {
            const { data } = await window.axios.post(`/chats/${chat.id}/messages`, form);
            if (data.message && !seenIds.current.has(data.message.id)) {
                seenIds.current.add(data.message.id);
                setMessages((prev) => [...prev, data.message]);
            }
            setChat((c) => ({ ...c, status: 'active' }));
        } catch (err) {
            const payload = err.response?.data;
            if (err.response?.status === 402 || payload?.error === 'insufficient_funds') {
                setSendError({
                    kind: 'insufficient_funds',
                    message: payload?.message || 'Your wallet is empty.',
                    topupUrl: payload?.topup_url || '/user/redeem-code',
                });
            } else {
                setSendError({
                    kind: 'generic',
                    message: payload?.error || payload?.message || t('show.sendFailed') || 'Failed to send.',
                });
            }
        } finally {
            setSending(false);
        }
    };

    const togglePersona = async (persona, active) => {
        const { data } = await window.axios.put(`/chats/${chat.id}/personas/${persona.id}`, { active });
        setPersonas(data.personas);
    };

    const pause = () => {
        setChat((c) => ({ ...c, status: 'paused' }));
        window.axios.post(`/chats/${chat.id}/pause`).catch(() => {});
    };
    const resume = () => {
        setChat((c) => ({ ...c, status: 'active' }));
        window.axios.post(`/chats/${chat.id}/resume`).catch(() => {});
    };

    const running = chat.status === 'active';
    const started = messages.length > 0;

    return (
        <>
            <Head title={chat.title} />
            <div className="flex h-dvh overflow-hidden bg-ink-50 text-ink-800 dark:bg-ink-950 dark:text-ink-100">
                {/* Sidebar — hidden on mobile, visible on desktop */}
                <ChatSidebar chats={chats} activeId={chat.id} mobileHidden />

                {/* Main content — slides in on mobile */}
                <main className="flex min-w-0 flex-1 flex-col animate-slide-in-right sm:animate-none">
                    {/* Sticky header */}
                    <header className="flex shrink-0 items-center justify-between gap-2 border-b border-ink-200/60 bg-white/80 px-3 py-3 backdrop-blur-md sm:gap-3 sm:px-6 dark:border-ink-800/60 dark:bg-ink-900/80">
                        <div className="flex min-w-0 items-center gap-2">
                            {/* Mobile back button */}
                            <Link
                                href="/chats"
                                className="flex h-8 w-8 items-center justify-center rounded-lg text-ink-500 hover:bg-ink-100 sm:hidden dark:hover:bg-ink-800"
                            >
                                <BackIcon className="h-5 w-5" />
                            </Link>
                            <div className="min-w-0">
                                <div className="flex items-center gap-2">
                                    <h1 className="truncate text-sm font-semibold text-ink-900 sm:text-base dark:text-ink-50">
                                        {chat.title}
                                    </h1>
                                    <StatusBadge status={chat.status} />
                                </div>
                                <div className="mt-0.5 flex items-center gap-2 text-[11px] text-ink-400 sm:gap-3">
                                    <span>
                                        Round {chat.current_round ?? 0}
                                        {!chat.continuous && chat.rounds_per_turn ? ` / ${chat.rounds_per_turn}` : ''}
                                    </span>
                                    <span>·</span>
                                    <span className="tabular-nums">€{Number(chat.total_cost ?? 0).toFixed(5)}</span>
                                    <span className="hidden sm:inline">·</span>
                                    <span className="hidden tabular-nums sm:inline">{chat.total_messages ?? 0} msgs</span>
                                </div>
                            </div>
                        </div>
                        <div className="flex shrink-0 items-center gap-1.5 sm:gap-2">
                            {started && (
                                <button
                                    onClick={running ? pause : resume}
                                    className={running ? 'btn-soft' : 'btn-primary'}
                                >
                                    {running ? (
                                        <>
                                            <PauseIcon className="h-4 w-4" />
                                            <span className="hidden sm:inline">{t('show.pause').replace(/^[⏸▶]\s*/, '')}</span>
                                        </>
                                    ) : (
                                        <>
                                            <PlayIcon className="h-4 w-4" />
                                            <span className="hidden sm:inline">{t('show.resume').replace(/^[⏸▶]\s*/, '')}</span>
                                        </>
                                    )}
                                </button>
                            )}
                            <button
                                onClick={() => setDrawerOpen((v) => !v)}
                                className="btn-ghost"
                                title={t('show.headerPersonas')}
                            >
                                <UsersIcon className="h-4 w-4" />
                                <span className="hidden sm:inline">{t('show.headerPersonas')}</span>
                            </button>
                        </div>
                    </header>

                    {/* Messages area */}
                    <div
                        ref={scrollRef}
                        className="flex-1 overflow-y-auto"
                    >
                        <div className="mx-auto max-w-3xl space-y-4 px-4 py-4 sm:space-y-6 sm:px-6 sm:py-8">
                            <BalanceBanner />

                            {messages.length === 0 && (
                                <div className="flex h-[60vh] flex-col items-center justify-center text-center">
                                    <SparkleIcon className="mb-4 h-8 w-8 text-cortex-500" />
                                    <h2 className="text-lg font-medium text-ink-700 dark:text-ink-200">
                                        {t('show.heroTitle')}
                                    </h2>
                                    <p className="mt-1 max-w-sm text-sm text-ink-400">
                                        {t('show.heroBody')}
                                    </p>
                                </div>
                            )}
                            {messages.map((m) => (
                                <MessageBubble key={m.id} message={m} animate={animateIds.current.has(m.id)} />
                            ))}

                            {running && !typing && messages.some(m => m.role === 'user') && !messages.some(m => m.role === 'persona' || m.role === 'scribe') && (
                                <PreparingIndicator t={t} />
                            )}

                            {typing && (
                                <div className="flex items-center gap-3 pl-12 text-xs text-ink-500 dark:text-ink-400">
                                    <span className="flex gap-1">
                                        <span className="h-1.5 w-1.5 animate-soft-pulse rounded-full bg-cortex-500" style={{ animationDelay: '0ms' }} />
                                        <span className="h-1.5 w-1.5 animate-soft-pulse rounded-full bg-cortex-500" style={{ animationDelay: '200ms' }} />
                                        <span className="h-1.5 w-1.5 animate-soft-pulse rounded-full bg-cortex-500" style={{ animationDelay: '400ms' }} />
                                    </span>
                                    <ThinkingLine t={t} name={typing.name} round={typing.round} />
                                </div>
                            )}
                            {started && !running && !typing && (
                                <PausedHint t={t} />
                            )}
                        </div>
                    </div>

                    <ChatInputBar
                        sending={sending}
                        powershellEnabled={powershellEnabled}
                        onSend={sendMessage}
                        onPowerShell={() => setShowPs(true)}
                        sendError={sendError}
                        onClearError={() => setSendError(null)}
                        initialText={
                            initialMessages.length === 0 && initialChat.description
                                ? String(initialChat.description)
                                : ''
                        }
                    />
                </main>

                {/* Persona drawer — full width on mobile, 320px on desktop */}
                {drawerOpen && (
                    <div className="fixed inset-y-0 right-0 z-30 w-full border-l border-ink-200/60 bg-white shadow-pop sm:w-80 dark:border-ink-800/60 dark:bg-ink-900">
                        <div className="flex items-center justify-between border-b border-ink-200/60 px-4 py-3 dark:border-ink-800/60">
                            <h2 className="text-sm font-semibold text-ink-900 dark:text-ink-50">{t('show.drawerTitle')}</h2>
                            <button
                                onClick={() => setDrawerOpen(false)}
                                className="rounded-lg p-1 text-ink-400 hover:bg-ink-100 hover:text-ink-700 dark:hover:bg-ink-800 dark:hover:text-ink-200"
                            >
                                <XIcon className="h-4 w-4" />
                            </button>
                        </div>
                        <div className="h-[calc(100dvh-3.5rem)] overflow-y-auto p-4">
                            <PersonaInfoPanel
                                chat={chat}
                                chatPersonas={personas}
                                allPersonas={allPersonas}
                                onToggle={togglePersona}
                            />
                        </div>
                    </div>
                )}

                {showPs && <PowerShellModal chatId={chat.id} onClose={() => setShowPs(false)} />}
            </div>
        </>
    );
}

function StatusBadge({ status }) {
    const { t } = useT();
    if (status === 'active') {
        return (
            <span className="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 px-2 py-0.5 text-[10px] font-medium text-emerald-700 ring-1 ring-emerald-200/60 dark:bg-emerald-950/40 dark:text-emerald-300 dark:ring-emerald-900/60">
                <span className="h-1.5 w-1.5 animate-soft-pulse rounded-full bg-emerald-500" />
                {t('show.statusActive')}
            </span>
        );
    }
    if (status === 'paused') {
        return (
            <span className="inline-flex items-center gap-1.5 rounded-full bg-amber-50 px-2 py-0.5 text-[10px] font-medium text-amber-700 ring-1 ring-amber-200/60 dark:bg-amber-950/40 dark:text-amber-300 dark:ring-amber-900/60">
                <span className="h-1.5 w-1.5 rounded-full bg-amber-500" />
                {t('show.statusPaused')}
            </span>
        );
    }
    return null;
}

function PreparingIndicator({ t }) {
    return (
        <div className="surface-muted flex items-start gap-3 px-4 py-3 animate-fade-up">
            <span className="mt-1.5 flex shrink-0 gap-1">
                <span className="h-1.5 w-1.5 animate-soft-pulse rounded-full bg-cortex-500" style={{ animationDelay: '0ms' }} />
                <span className="h-1.5 w-1.5 animate-soft-pulse rounded-full bg-cortex-500" style={{ animationDelay: '200ms' }} />
                <span className="h-1.5 w-1.5 animate-soft-pulse rounded-full bg-cortex-500" style={{ animationDelay: '400ms' }} />
            </span>
            <div className="min-w-0 text-sm text-ink-600 dark:text-ink-300">
                {t('show.preparing')}
            </div>
        </div>
    );
}

function ThinkingLine({ t, name, round }) {
    const raw = t('show.thinking', { round, name: ' NAME ' });
    const [before, after] = raw.split(' NAME ');
    return (
        <span>
            {before}
            <span className="font-medium text-ink-700 dark:text-ink-200">{name}</span>
            {after}
        </span>
    );
}

function PausedHint({ t }) {
    const resumeLabel = t('show.resume').replace(/^[⏸▶]\s*/, '');
    const raw = t('show.pausedShort', { resume: ' RESUME ' });
    const [before, after] = raw.split(' RESUME ');
    return (
        <div className="pl-12 text-xs italic text-ink-400">
            {before}<strong>{resumeLabel}</strong>{after}
        </div>
    );
}

function BackIcon({ className }) { return <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M19 12H5M12 19l-7-7 7-7" /></svg>; }
function PauseIcon({ className }) { return <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><rect x="6" y="4" width="4" height="16" /><rect x="14" y="4" width="4" height="16" /></svg>; }
function PlayIcon({ className }) { return <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><polygon points="5 3 19 12 5 21 5 3" /></svg>; }
function UsersIcon({ className }) { return <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8zM23 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75" /></svg>; }
function SparkleIcon({ className }) { return <svg className={className} viewBox="0 0 24 24" fill="currentColor"><path d="M12 0l2.121 6.879L21 9l-6.879 2.121L12 18l-2.121-6.879L3 9l6.879-2.121L12 0z" opacity="0.85" /><path d="M19 14l1 3 3 1-3 1-1 3-1-3-3-1 3-1 1-3z" opacity="0.5" /></svg>; }
function XIcon({ className }) { return <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round"><path d="M18 6L6 18M6 6l12 12" /></svg>; }
