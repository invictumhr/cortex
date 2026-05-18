import { useState } from 'react';
import TypewriterText from './TypewriterText';

function Attachment({ attachment }) {
    if (attachment.type === 'image') {
        return (
            <img
                src={`/attachments/${attachment.id}/file`}
                alt="prilog"
                className="mt-2 max-h-64 rounded-lg border border-gray-200"
            />
        );
    }

    if (attachment.type === 'url') {
        return (
            <a
                href={attachment.url}
                target="_blank"
                rel="noreferrer"
                className="mt-2 block truncate rounded-lg border border-gray-200 bg-gray-50 px-2 py-1.5 text-xs text-blue-600 hover:bg-gray-100"
            >
                🔗 {attachment.url}
            </a>
        );
    }

    if (attachment.type === 'powershell_output') {
        return (
            <pre className="mt-2 max-h-64 overflow-auto rounded-lg bg-gray-900 p-3 text-xs leading-relaxed text-green-300">
                {attachment.extracted_content || '(bez izlaza)'}
            </pre>
        );
    }

    return null;
}

export default function MessageBubble({ message, animate = false, showMeta = true }) {
    const [collapsed, setCollapsed] = useState(false);
    const attachments = message.attachments ?? [];

    if (message.role === 'user') {
        return (
            <div className="flex justify-end">
                <div className="max-w-[80%] rounded-2xl rounded-br-sm bg-indigo-600 px-4 py-2.5 text-white">
                    <div className="mb-0.5 text-xs font-medium text-indigo-200">Ti 👤</div>
                    <div className="whitespace-pre-wrap break-words">{message.content}</div>
                    {attachments.map((a) => <Attachment key={a.id} attachment={a} />)}
                </div>
            </div>
        );
    }

    if (message.role === 'system') {
        return (
            <div className="flex justify-center">
                <div className="max-w-[90%] rounded-lg bg-amber-50 px-3 py-2 text-center text-xs text-amber-700">
                    <div className="whitespace-pre-wrap">{message.content}</div>
                    {attachments.map((a) => <Attachment key={a.id} attachment={a} />)}
                </div>
            </div>
        );
    }

    if (message.role === 'scribe') {
        return (
            <div className="mx-auto w-full max-w-3xl">
                <div className="rounded-xl border-2 border-amber-300 bg-amber-50 shadow-sm">
                    <button
                        onClick={() => setCollapsed(!collapsed)}
                        className="flex w-full items-center justify-between px-4 py-2 text-left"
                    >
                        <span className="font-semibold text-amber-800">📝 Scribe — sažetak rasprave</span>
                        <span className="text-amber-600">{collapsed ? '▸' : '▾'}</span>
                    </button>
                    {!collapsed && (
                        <div className="whitespace-pre-wrap break-words px-4 pb-4 text-sm leading-relaxed text-amber-900">
                            {message.content}
                        </div>
                    )}
                </div>
            </div>
        );
    }

    const persona = message.persona ?? {};
    const color = persona.avatar_color || '#6366f1';

    return (
        <div className="flex gap-3">
            <div
                className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-lg"
                style={{ backgroundColor: color + '22' }}
            >
                {persona.avatar_emoji || '🤖'}
            </div>
            <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                    <span className="font-semibold" style={{ color }}>{persona.name || 'Persona'}</span>
                    {persona.title && <span className="text-xs text-gray-400">{persona.title}</span>}
                    {message.model_used && (
                        <span
                            className="rounded bg-gray-100 px-1.5 py-0.5 text-[10px] font-medium text-gray-500"
                            title="Model koji pogoni ovu personu"
                        >
                            ⚙ {message.model_used}
                        </span>
                    )}
                </div>
                <div className="mt-0.5 rounded-2xl rounded-tl-sm bg-white px-4 py-2.5 text-gray-800 shadow-sm ring-1 ring-gray-100">
                    <TypewriterText text={message.content} animate={animate} />
                    {attachments.map((a) => <Attachment key={a.id} attachment={a} />)}
                </div>
                {showMeta && message.input_tokens != null && (
                    <div className="mt-1 text-[11px] text-gray-400">
                        {message.input_tokens}+{message.output_tokens} tok · €{Number(message.cost ?? 0).toFixed(5)} · {message.response_time_ms}ms
                    </div>
                )}
            </div>
        </div>
    );
}
