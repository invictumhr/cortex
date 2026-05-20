import { useRef, useState } from 'react';
import { useT } from '@/i18n/I18nProvider';

export default function ChatInputBar({ disabled, sending, onSend, onPowerShell, powershellEnabled }) {
    const { t } = useT();
    const [text, setText] = useState('');
    const [url, setUrl] = useState('');
    const [showUrl, setShowUrl] = useState(false);
    const [image, setImage] = useState(null);
    const fileRef = useRef(null);

    const clearImage = () => {
        setImage(null);
        if (fileRef.current) {
            fileRef.current.value = '';
        }
    };

    const submit = () => {
        if (!text.trim() || disabled) {
            return;
        }
        onSend({ content: text, url: url.trim() || null, image });
        setText('');
        setUrl('');
        setShowUrl(false);
        clearImage();
    };

    return (
        <div className="border-t border-gray-200 bg-white p-3">
            {image && (
                <div className="mb-2 flex items-center gap-2 text-xs text-gray-500">
                    📎 {image.name}
                    <button onClick={clearImage} className="font-bold text-red-500">×</button>
                </div>
            )}
            {showUrl && (
                <input
                    value={url}
                    onChange={(e) => setUrl(e.target.value)}
                    placeholder={t('input.urlPlaceholder')}
                    className="mb-2 w-full rounded-lg border-gray-300 text-sm"
                />
            )}
            <div className="flex items-end gap-2">
                <textarea
                    value={text}
                    onChange={(e) => setText(e.target.value)}
                    onKeyDown={(e) => {
                        if (e.key === 'Enter' && !e.shiftKey) {
                            e.preventDefault();
                            submit();
                        }
                    }}
                    rows={2}
                    placeholder={t('input.placeholder')}
                    className="flex-1 resize-none rounded-xl border-gray-300 text-sm"
                />
                <div className="flex flex-col gap-1">
                    <div className="flex gap-1">
                        <button onClick={() => fileRef.current?.click()} title={t('input.attachImage')} className="rounded-lg bg-gray-100 p-2 hover:bg-gray-200">📎</button>
                        <button onClick={() => setShowUrl((v) => !v)} title={t('input.attachUrl')} className="rounded-lg bg-gray-100 p-2 hover:bg-gray-200">🔗</button>
                        {powershellEnabled && (
                            <button onClick={onPowerShell} title={t('input.powershell')} className="rounded-lg bg-gray-100 p-2 hover:bg-gray-200">⚡</button>
                        )}
                    </div>
                    <button
                        onClick={submit}
                        disabled={disabled || sending || !text.trim()}
                        className="rounded-xl bg-indigo-600 px-5 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-40"
                    >
                        {sending ? t('input.sending') : t('input.send')}
                    </button>
                </div>
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
