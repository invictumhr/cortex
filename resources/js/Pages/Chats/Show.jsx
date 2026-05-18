import { Head } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import ChatInputBar from '@/Components/Cortex/ChatInputBar';
import ChatSidebar from '@/Components/Cortex/ChatSidebar';
import MessageBubble from '@/Components/Cortex/MessageBubble';
import PersonaInfoPanel from '@/Components/Cortex/PersonaInfoPanel';
import PowerShellModal from '@/Components/Cortex/PowerShellModal';

export default function Show({
    chat: initialChat,
    messages: initialMessages,
    allPersonas,
    chats,
    powershellEnabled,
    roundPresets,
}) {
    const [chat, setChat] = useState(initialChat);
    const [messages, setMessages] = useState(initialMessages);
    const [personas, setPersonas] = useState(initialChat.personas ?? []);
    const [typing, setTyping] = useState(null);
    const [sending, setSending] = useState(false);
    const [turnRunning, setTurnRunning] = useState(false);
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
            setChat((c) => ({ ...c, current_round: e.current_round, rounds_per_turn: e.rounds_per_turn }));
        });

        channel.listen('.turn.completed', (e) => {
            setTyping(null);
            setTurnRunning(false);
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
            setTurnRunning(true);
            setChat((c) => ({ ...c, status: 'active' }));
        } catch (err) {
            window.alert(err.response?.data?.error || 'Slanje poruke nije uspjelo.');
        } finally {
            setSending(false);
        }
    };

    const togglePersona = async (persona, active) => {
        const { data } = await window.axios.put(`/chats/${chat.id}/personas/${persona.id}`, { active });
        setPersonas(data.personas);
    };

    const setRounds = async (n) => {
        await window.axios.patch(`/chats/${chat.id}/rounds`, { rounds_per_turn: n });
        setChat((c) => ({ ...c, rounds_per_turn: n }));
    };

    const addRounds = async (n) => {
        const { data } = await window.axios.post(`/chats/${chat.id}/add-rounds`, { rounds: n });
        setChat((c) => ({ ...c, rounds_per_turn: data.rounds_per_turn }));
    };

    const pause = async () => {
        await window.axios.post(`/chats/${chat.id}/pause`);
        setChat((c) => ({ ...c, status: 'paused' }));
    };

    const resume = async () => {
        await window.axios.post(`/chats/${chat.id}/resume`);
        setTurnRunning(true);
        setChat((c) => ({ ...c, status: 'active' }));
    };

    return (
        <>
            <Head title={chat.title} />
            <div className="flex h-screen overflow-hidden bg-gray-100 text-gray-800">
                <ChatSidebar chats={chats} activeId={chat.id} />

                <main className="flex min-w-0 flex-1 flex-col">
                    <header className="flex items-center justify-between border-b border-gray-200 bg-white px-5 py-3">
                        <div className="min-w-0">
                            <h1 className="truncate font-semibold text-gray-800">{chat.title}</h1>
                            {chat.description && (
                                <p className="truncate text-xs text-gray-400">{chat.description}</p>
                            )}
                        </div>
                        <span
                            className={`shrink-0 rounded-full px-2.5 py-0.5 text-xs font-medium ${
                                chat.status === 'active'
                                    ? 'bg-emerald-100 text-emerald-700'
                                    : 'bg-amber-100 text-amber-700'
                            }`}
                        >
                            {chat.status}
                        </span>
                    </header>

                    <div ref={scrollRef} className="flex-1 space-y-4 overflow-y-auto p-5">
                        {messages.length === 0 && (
                            <p className="mt-10 text-center text-sm text-gray-400">
                                Pošalji prvu poruku da pokreneš raspravu.
                            </p>
                        )}
                        {messages.map((m) => (
                            <MessageBubble key={m.id} message={m} animate={animateIds.current.has(m.id)} />
                        ))}
                        {typing && (
                            <div className="flex items-center gap-2 pl-12 text-xs text-gray-400">
                                <span className="animate-pulse text-base">●</span>
                                {typing.name} razmišlja… (krug {typing.round})
                            </div>
                        )}
                    </div>

                    <ChatInputBar
                        disabled={turnRunning}
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
                    presets={roundPresets}
                    onToggle={togglePersona}
                    onSetRounds={setRounds}
                    onAddRounds={addRounds}
                    onPause={pause}
                    onResume={resume}
                />

                {showPs && <PowerShellModal chatId={chat.id} onClose={() => setShowPs(false)} />}
            </div>
        </>
    );
}
