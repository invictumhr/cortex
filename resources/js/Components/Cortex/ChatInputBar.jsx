import { Link, usePage } from '@inertiajs/react';
import { useEffect, useRef, useState } from 'react';
import { useT } from '@/i18n/I18nProvider';

/**
 * Composer at the bottom of a chat. Auto-growing textarea, attachments
 * collapsed behind an icon button, send button anchored on the right.
 * Enter sends, Shift+Enter inserts a newline. No multi-button cluster —
 * everything that isn't "send" lives in a chip row above the field.
 *
 * Wallet awareness:
 *   - if balance is below the send floor we lock the field, swap the
 *     placeholder, and render a Top-up CTA where the send button used to be.
 *   - if a previous send returned 402 the same CTA is highlighted as the
 *     error explanation rather than a separate alert().
 */
export default function ChatInputBar({ disabled, sending, onSend, onPowerShell, powershellEnabled, sendError, onClearError, initialText = '' }) {
    const { t } = useT();
    const wallet = usePage().props.wallet;
    // initialText carries the chat description into a freshly opened chat so
    // the user can just hit Enter to start the discussion. State seeded once
    // — after the user edits anything the value is theirs to manage.
    const [text, setText] = useState(initialText);
    const [url, setUrl] = useState('');
    const [showUrl, setShowUrl] = useState(false);
    const [image, setImage] = useState(null);
    const textareaRef = useRef(null);
    const fileRef = useRef(null);

    // Empty wallet → composer is locked and we surface a top-up CTA. A 402
    // bounce from the backend (sendError.kind === 'insufficient_funds')
    // upgrades the same UI from "warning" to "rejection".
    const walletEmpty = wallet && wallet.available < (wallet.min_send ?? 0.05);
    const fundsRejection = sendError?.kind === 'insufficient_funds';
    const locked = disabled || walletEmpty || fundsRejection;

    // Auto-grow: the textarea height tracks content up to a cap so the page
    // doesn't lose its message area to a runaway draft.
    useEffect(() => {
        const el = textareaRef.current;
        if (!el) return;
        el.style.height = 'auto';
        el.style.height = `${Math.min(el.scrollHeight, 240)}px`;
    }, [text]);

    const clearImage = () => {
        setImage(null);
        if (fileRef.current) fileRef.current.value = '';
    };

    const submit = () => {
        if (!text.trim() || locked || sending) return;
        onSend({ content: text, url: url.trim() || null, image });
        setText('');
        setUrl('');
        setShowUrl(false);
        clearImage();
    };

    const canSend = !locked && !sending && text.trim().length > 0;

    return (
        <div className="border-t border-ink-200/60 bg-white/80 px-4 py-4 backdrop-blur-md dark:border-ink-800/60 dark:bg-ink-900/80">
            <div className="mx-auto max-w-3xl">
                {/* Generic send error — non-funds. Dismissible inline notice. */}
                {sendError && sendError.kind !== 'insufficient_funds' && (
                    <div className="mb-2 flex items-center justify-between gap-3 rounded-xl bg-rose-50 px-3 py-2 text-xs text-rose-700 ring-1 ring-rose-200/60 dark:bg-rose-950/40 dark:text-rose-300 dark:ring-rose-900/60">
                        <span>{sendError.message}</span>
                        <button
                            type="button"
                            onClick={onClearError}
                            className="rounded p-1 hover:bg-rose-100 dark:hover:bg-rose-900/40"
                        >
                            <XIcon className="h-3.5 w-3.5" />
                        </button>
                    </div>
                )}

                {/* Insufficient-funds rejection — promotes to a primary CTA. */}
                {fundsRejection && (
                    <div className="mb-2 flex items-center justify-between gap-3 rounded-xl bg-rose-50 px-3.5 py-2.5 text-sm text-rose-700 ring-1 ring-rose-200/60 dark:bg-rose-950/40 dark:text-rose-300 dark:ring-rose-900/60">
                        <div className="flex items-start gap-2 min-w-0">
                            <AlertIcon className="mt-0.5 h-4 w-4 shrink-0" />
                            <span className="font-medium">{sendError.message}</span>
                        </div>
                        <Link
                            href={sendError.topupUrl}
                            className="shrink-0 inline-flex items-center gap-1 rounded-lg bg-rose-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-rose-500"
                        >
                            {t('input.topupBtn')} <ArrowRightIcon className="h-3 w-3" />
                        </Link>
                    </div>
                )}

                {/* Attachment chips — appear only when populated. */}
                {(image || showUrl) && (
                    <div className="mb-2 flex flex-wrap items-center gap-2">
                        {image && (
                            <div className="flex items-center gap-1.5 rounded-full bg-ink-100 px-2.5 py-1 text-xs text-ink-700 ring-1 ring-ink-200/60 dark:bg-ink-800 dark:text-ink-200 dark:ring-ink-700/60">
                                <PaperclipIcon className="h-3.5 w-3.5" />
                                <span className="max-w-[200px] truncate">{image.name}</span>
                                <button
                                    onClick={clearImage}
                                    className="text-ink-400 hover:text-red-500"
                                    aria-label="Remove attachment"
                                >
                                    <XIcon className="h-3.5 w-3.5" />
                                </button>
                            </div>
                        )}
                        {showUrl && (
                            <input
                                value={url}
                                onChange={(e) => setUrl(e.target.value)}
                                placeholder={t('input.urlPlaceholder')}
                                className="flex-1 min-w-[200px] rounded-full border-0 bg-ink-100 px-3.5 py-1 text-xs text-ink-700 ring-1 ring-ink-200/60 placeholder:text-ink-400 focus:ring-2 focus:ring-cortex-500/40 dark:bg-ink-800 dark:text-ink-200 dark:ring-ink-700/60"
                            />
                        )}
                    </div>
                )}

                <div className={`surface relative flex items-end gap-2 rounded-2xl px-4 py-3 transition-shadow focus-within:shadow-pop ${locked ? 'opacity-60' : ''}`}>
                    <textarea
                        ref={textareaRef}
                        value={text}
                        onChange={(e) => setText(e.target.value)}
                        onKeyDown={(e) => {
                            if (e.key === 'Enter' && !e.shiftKey) {
                                e.preventDefault();
                                submit();
                            }
                        }}
                        rows={1}
                        disabled={walletEmpty}
                        placeholder={walletEmpty ? t('input.walletEmpty') : t('input.placeholder')}
                        className="flex-1 resize-none border-0 bg-transparent p-0 text-[15px] leading-relaxed text-ink-900 placeholder:text-ink-400 focus:ring-0 disabled:cursor-not-allowed dark:text-ink-100"
                        style={{ maxHeight: '240px' }}
                    />

                    <div className="flex shrink-0 items-center gap-1">
                        <IconButton
                            title={t('input.attachImage')}
                            onClick={() => fileRef.current?.click()}
                        >
                            <PaperclipIcon className="h-4 w-4" />
                        </IconButton>
                        <IconButton
                            title={t('input.attachUrl')}
                            onClick={() => setShowUrl((v) => !v)}
                            active={showUrl}
                        >
                            <LinkIcon className="h-4 w-4" />
                        </IconButton>
                        {powershellEnabled && (
                            <IconButton title={t('input.powershell')} onClick={onPowerShell}>
                                <BoltIcon className="h-4 w-4" />
                            </IconButton>
                        )}
                        {walletEmpty ? (
                            // No funds → swap the send action for the top-up
                            // CTA so the user always knows the path out.
                            <a
                                href={wallet.topup_url}
                                className="flex h-8 items-center gap-1.5 rounded-full bg-rose-600 px-3 text-xs font-medium text-white shadow-soft transition-colors hover:bg-rose-500"
                            >
                                <ArrowUpIcon className="h-3.5 w-3.5" />
                                {t('input.topupBtn')}
                            </a>
                        ) : (
                            <button
                                onClick={submit}
                                disabled={!canSend}
                                aria-label={t('input.send')}
                                className="flex h-8 w-8 items-center justify-center rounded-full bg-cortex-600 text-white shadow-soft transition-all duration-150 ease-snap hover:bg-cortex-500 active:scale-95 disabled:cursor-not-allowed disabled:bg-ink-200 disabled:text-ink-400 dark:disabled:bg-ink-800 dark:disabled:text-ink-600"
                            >
                                {sending ? <SpinnerIcon className="h-4 w-4 animate-spin" /> : <ArrowUpIcon className="h-4 w-4" />}
                            </button>
                        )}
                    </div>
                </div>

                <p className="mt-2 text-center text-[11px] text-ink-400">
                    {t('input.enterHint')}
                </p>
            </div>

            <input
                ref={fileRef}
                type="file"
                accept="image/*"
                className="hidden"
                onChange={(e) => setImage(e.target.files?.[0] || null)}
            />
        </div>
    );
}

function IconButton({ children, title, onClick, active = false }) {
    return (
        <button
            onClick={onClick}
            title={title}
            type="button"
            className={`flex h-8 w-8 items-center justify-center rounded-full text-ink-500 transition-colors duration-150 ease-snap hover:bg-ink-100 hover:text-ink-700 dark:text-ink-400 dark:hover:bg-ink-800 dark:hover:text-ink-200 ${
                active ? 'bg-ink-100 text-ink-700 dark:bg-ink-800 dark:text-ink-200' : ''
            }`}
        >
            {children}
        </button>
    );
}

function PaperclipIcon({ className }) {
    return (
        <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
            <path d="M21.44 11.05l-9.19 9.19a6 6 0 0 1-8.49-8.49l9.19-9.19a4 4 0 0 1 5.66 5.66l-9.2 9.19a2 2 0 0 1-2.83-2.83l8.49-8.48" />
        </svg>
    );
}

function LinkIcon({ className }) {
    return (
        <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
            <path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.72M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.72-1.72" />
        </svg>
    );
}

function BoltIcon({ className }) {
    return (
        <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
            <path d="M13 2L3 14h9l-1 8 10-12h-9l1-8z" />
        </svg>
    );
}

function ArrowUpIcon({ className }) {
    return (
        <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
            <path d="M12 19V5M5 12l7-7 7 7" />
        </svg>
    );
}

function SpinnerIcon({ className }) {
    return (
        <svg className={className} viewBox="0 0 24 24" fill="none">
            <circle cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="3" strokeOpacity="0.25" />
            <path d="M12 2a10 10 0 0 1 10 10" stroke="currentColor" strokeWidth="3" strokeLinecap="round" />
        </svg>
    );
}

function XIcon({ className }) {
    return (
        <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
            <path d="M18 6L6 18M6 6l12 12" />
        </svg>
    );
}

function AlertIcon({ className }) {
    return (
        <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
            <circle cx="12" cy="12" r="10" />
            <line x1="12" y1="8" x2="12" y2="12" />
            <line x1="12" y1="16" x2="12.01" y2="16" />
        </svg>
    );
}

function ArrowRightIcon({ className }) {
    return (
        <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round">
            <path d="M5 12h14M13 5l7 7-7 7" />
        </svg>
    );
}
