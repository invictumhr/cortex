import { useState } from 'react';
import TypewriterText from './TypewriterText';

/**
 * Message bubble — Claude / GPT-style asymmetric layout.
 *
 *   User msgs:   rounded rectangle, soft cortex-tinted background, right-aligned
 *                in a narrow column. Has chrome.
 *   AI msgs:     no bubble. Just avatar + name + body in the page flow.
 *                Lets long responses breathe.
 *   System msgs: tiny inline notice, centered, muted.
 *   Scribe msgs: full-width amber-tinged card, collapsible — these are big
 *                synthesis dumps and deserve to be tucked away.
 */
function Attachment({ attachment }) {
    if (attachment.type === 'image') {
        return (
            <img
                src={`/attachments/${attachment.id}/file`}
                alt="attachment"
                className="mt-3 max-h-64 rounded-xl ring-1 ring-ink-200/60 dark:ring-ink-700/60"
            />
        );
    }

    if (attachment.type === 'url') {
        return (
            <a
                href={attachment.url}
                target="_blank"
                rel="noreferrer"
                className="mt-3 inline-flex max-w-full items-center gap-1.5 truncate rounded-lg bg-ink-100 px-2.5 py-1.5 text-xs text-cortex-700 ring-1 ring-ink-200/60 hover:bg-ink-200 dark:bg-ink-800 dark:text-cortex-300 dark:ring-ink-700/60 dark:hover:bg-ink-700"
            >
                <LinkIcon className="h-3.5 w-3.5 shrink-0" />
                <span className="truncate">{attachment.url}</span>
            </a>
        );
    }

    if (attachment.type === 'powershell_output') {
        return (
            <pre className="mt-3 max-h-72 overflow-auto rounded-xl bg-ink-950 p-4 font-mono text-xs leading-relaxed text-emerald-300 ring-1 ring-ink-800">
                {attachment.extracted_content || '(no output)'}
            </pre>
        );
    }

    return null;
}

export default function MessageBubble({ message, animate = false, showMeta = true }) {
    const [collapsed, setCollapsed] = useState(false);
    const attachments = message.attachments ?? [];

    /* ────────────────────────── USER ────────────────────────── */
    if (message.role === 'user') {
        return (
            <div className="flex justify-end animate-fade-up">
                <div className="max-w-[92%] rounded-2xl rounded-br-md bg-cortex-50 px-4 py-2.5 text-ink-800 ring-1 ring-cortex-100 sm:max-w-[85%] dark:bg-cortex-950/60 dark:text-ink-100 dark:ring-cortex-900/60">
                    <div className="whitespace-pre-wrap break-words text-[15px] leading-relaxed">
                        {message.content}
                    </div>
                    {attachments.map((a) => <Attachment key={a.id} attachment={a} />)}
                </div>
            </div>
        );
    }

    /* ────────────────────────── SYSTEM ──────────────────────── */
    if (message.role === 'system') {
        return (
            <div className="flex justify-center animate-fade-up">
                <div className="max-w-[80%] rounded-full bg-amber-50 px-3.5 py-1.5 text-center text-xs text-amber-700 ring-1 ring-amber-200/60 dark:bg-amber-950/40 dark:text-amber-300 dark:ring-amber-900/60">
                    <span className="whitespace-pre-wrap">{message.content}</span>
                </div>
            </div>
        );
    }

    /* ────────────────────────── SCRIBE ──────────────────────── */
    if (message.role === 'scribe') {
        return (
            <div className="animate-fade-up surface overflow-hidden">
                <button
                    onClick={() => setCollapsed(!collapsed)}
                    className="flex w-full items-center justify-between gap-3 px-4 py-3 text-left transition-colors hover:bg-ink-50 dark:hover:bg-ink-800/40"
                >
                    <div className="flex items-center gap-2.5">
                        <ScribeIcon className="h-4 w-4 text-amber-600 dark:text-amber-400" />
                        <span className="text-sm font-semibold text-ink-900 dark:text-ink-100">Scribe synthesis</span>
                    </div>
                    <ChevronIcon className={`h-4 w-4 text-ink-400 transition-transform ${collapsed ? '' : 'rotate-90'}`} />
                </button>
                {!collapsed && (
                    <div className="border-t border-ink-200/60 px-4 py-4 dark:border-ink-700/60">
                        <div className="whitespace-pre-wrap break-words text-[14px] leading-relaxed text-ink-800 dark:text-ink-200">
                            {message.content}
                        </div>
                    </div>
                )}
            </div>
        );
    }

    /* ────────────────────────── PERSONA ─────────────────────── */
    const persona = message.persona ?? {};
    const color = persona.avatar_color || '#4c5fe6';

    return (
        <div className="flex gap-3 animate-fade-up">
            <div
                className="flex h-9 w-9 shrink-0 items-center justify-center rounded-full text-base ring-1 ring-ink-200/60 dark:ring-ink-700/60"
                style={{ backgroundColor: color + '1a', color }}
                title={persona.title || ''}
            >
                {persona.avatar_emoji || '◆'}
            </div>
            <div className="min-w-0 flex-1">
                <div className="flex flex-wrap items-baseline gap-x-2 gap-y-0.5">
                    <span className="text-sm font-semibold tracking-tight text-ink-900 dark:text-ink-100" style={{ color }}>
                        {persona.name || 'Persona'}
                    </span>
                    {persona.title && (
                        <span className="text-xs text-ink-400">{persona.title}</span>
                    )}
                    {message.model_used && (
                        <span className="pill" title="AI model powering this persona">
                            {message.model_used}
                        </span>
                    )}
                </div>
                <div className="mt-1.5 whitespace-pre-wrap break-words text-[15px] leading-relaxed text-ink-800 dark:text-ink-200">
                    <TypewriterText text={message.content} animate={animate} />
                </div>
                {attachments.map((a) => <Attachment key={a.id} attachment={a} />)}
                {showMeta && message.input_tokens != null && (
                    <div className="mt-2 text-[11px] tabular-nums text-ink-400">
                        {message.input_tokens}+{message.output_tokens} tok · €{Number(message.cost ?? 0).toFixed(5)} · {message.response_time_ms}ms
                    </div>
                )}
            </div>
        </div>
    );
}

function LinkIcon({ className }) {
    return (
        <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
            <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.72M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.72-1.72" />
        </svg>
    );
}

function ScribeIcon({ className }) {
    return (
        <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
            <path d="M4 19h16M4 15h16M4 11h12M4 7h8" />
        </svg>
    );
}

function ChevronIcon({ className }) {
    return (
        <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
            <path d="M9 18l6-6-6-6" />
        </svg>
    );
}
