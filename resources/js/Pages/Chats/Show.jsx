import { Head } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
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
    const { t } = useT();
    const [chat, setChat] = useState(initialChat);
    const [messages, setMessages] = useState(initialMessages);
    const [personas, setPersonas] = useState(initialChat.personas ?? []);
    const [typing, setTyping] = useState(null);
    const [sending, setSending] = useState(false);
    const [showPs, setShowPs] = useState(false);

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

    const sendMessage = async ({ content, url, image }) => {
        setSending(true);
        const form = new FormData();
        form.append('content', content);
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
            window.alert(err.response?.data?.error || t('show.sendFailed'));
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
            <div className="flex h-screen overflow-hidden bg-gray-100 text-gray-800">
                <ChatSidebar chats={chats} activeId={chat.id} />

                <main className="flex min-w-0 flex-1 flex-col">
                    <header className="flex items-center justify-between gap-3 border-b border-gray-200 bg-white px-5 py-3">
                        <div className="min-w-0">
                            <h1 className="truncate font-semibold text-gray-800">{chat.title}</h1>
                            {chat.description && (
                                <p className="truncate text-xs text-gray-400">{chat.description}</p>
                            )}
                        </div>
                        {started && (
                            <button
                                onClick={running ? pause : resume}
                                className={`flex shrink-0 items-center gap-1.5 rounded-lg px-4 py-2 text-sm font-semibold transition ${
                                    running
                                        ? 'bg-amber-100 text-amber-800 hover:bg-amber-200'
                                        : 'bg-emerald-600 text-white hover:bg-emerald-700'
                                }`}
                            >
                                {running ? t('show.pause') : t('show.resume')}
                            </button>
                        )}
                    </header>

                    <div ref={scrollRef} className="flex-1 space-y-4 overflow-y-auto p-5">
                        {messages.length === 0 && (
                            <p className="mt-10 text-center text-sm text-gray-400">{t('show.empty')}</p>
                        )}
                        {messages.map((m) => (
                            <MessageBubble key={m.id} message={m} animate={animateIds.current.has(m.id)} />
                        ))}
                        {typing && (
                            <div className="flex items-center gap-2 pl-12 text-xs text-gray-400">
                                <span className="animate-pulse text-base">●</span>
                                {t('show.typing', { name: typing.name, round: typing.round })}
                            </div>
                        )}
                        {started && !running && !typing && (
                            <div className="pl-12 text-xs text-gray-400">{t('show.pausedHint')}</div>
                        )}
                    </div>

                    <ChatInputBar
                        sending={sending}
                        powershellEnabled={powershellEnabled}
                        onSend={sendMessage}
                        onPowerShell={() => setShowPs(true)}
                    />
                </main>

                <PersonaInfoPanel
                    chat={chat}
                    chatPersonas={personas}
                    allPersonas={allPersonas}
                    onToggle={togglePersona}
                />

                {showPs && <PowerShellModal chatId={chat.id} onClose={() => setShowPs(false)} />}
            </div>
        </>
    );
}
