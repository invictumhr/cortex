import { Head, Link, useForm } from '@inertiajs/react';
import { useEffect } from 'react';
import LanguageToggle from '@/Components/Cortex/LanguageToggle';
import { useT } from '@/i18n/I18nProvider';

export default function Index({ chats, personas }) {
    const { t, lang } = useT();

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

    // Keep the chat-language field synced with the current UI language so a
    // newly created chat is born in whatever language the toggle is set to.
    useEffect(() => {
        setData('language', lang);
    }, [lang]);

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

    const ready = !processing && (data.architect || data.persona_ids.length > 0);

    return (
        <>
            <Head title={t('app.brand')} />
            <div className="min-h-screen bg-gray-100">
                <div className="mx-auto max-w-5xl p-6">
                    <div className="flex items-end justify-between">
                        <div>
                            <h1 className="text-2xl font-bold text-gray-800">{t('index.heading')}</h1>
                            <p className="text-sm text-gray-500">{t('app.tagline')}</p>
                        </div>
                        <div className="flex items-center gap-2">
                            <LanguageToggle />
                            <a href="/admin" className="text-xs text-gray-400 hover:text-gray-600">
                                {t('index.adminLink')}
                            </a>
                        </div>
                    </div>

                    <div className="mt-6 grid gap-6 md:grid-cols-2">
                        <form onSubmit={submit} className="rounded-xl bg-white p-5 shadow-sm">
                            <h2 className="font-semibold text-gray-800">{t('index.newBoardroom')}</h2>
                            <input
                                value={data.title}
                                onChange={(e) => setData('title', e.target.value)}
                                placeholder={t('index.titlePlaceholder')}
                                className="mt-3 w-full rounded-lg border-gray-300 text-sm"
                            />
                            <textarea
                                value={data.description}
                                onChange={(e) => setData('description', e.target.value)}
                                placeholder={t('index.descriptionPlaceholder')}
                                rows={2}
                                className="mt-2 w-full rounded-lg border-gray-300 text-sm"
                            />
                            <textarea
                                value={data.context}
                                onChange={(e) => setData('context', e.target.value)}
                                placeholder={t('index.contextPlaceholder')}
                                rows={3}
                                className="mt-2 w-full rounded-lg border-gray-300 text-sm"
                            />
                            <textarea
                                value={data.constraints}
                                onChange={(e) => setData('constraints', e.target.value)}
                                placeholder={t('index.constraintsPlaceholder')}
                                rows={2}
                                className="mt-2 w-full rounded-lg border-gray-300 text-sm"
                            />

                            <div className="mt-3 space-y-1.5">
                                <label className="flex cursor-pointer items-start gap-2 text-sm text-gray-700">
                                    <input
                                        type="checkbox"
                                        checked={data.architect}
                                        onChange={(e) => setData('architect', e.target.checked)}
                                        className="mt-0.5"
                                    />
                                    <span>
                                        <span className="font-medium">{t('index.architectLabel')}</span>
                                        <span className="block text-xs text-gray-400">
                                            {t('index.architectDesc')}
                                        </span>
                                    </span>
                                </label>
                                <label className="flex cursor-pointer items-start gap-2 text-sm text-gray-700">
                                    <input
                                        type="checkbox"
                                        checked={data.strong}
                                        onChange={(e) => setData('strong', e.target.checked)}
                                        className="mt-0.5"
                                    />
                                    <span>
                                        <span className="font-medium">{t('index.strongLabel')}</span>
                                        <span className="block text-xs text-gray-400">
                                            {t('index.strongDesc')}
                                        </span>
                                    </span>
                                </label>
                            </div>

                            {data.architect ? (
                                <p className="mt-3 rounded-lg bg-indigo-50 p-2.5 text-xs text-indigo-700">
                                    {t('index.architectHint')}
                                </p>
                            ) : (
                                <>
                                    <div className="mt-3 text-xs font-medium text-gray-500">
                                        {t('index.personasLabel', { n: data.persona_ids.length })}
                                    </div>
                                    <div className="mt-1 max-h-60 overflow-y-auto rounded-lg border border-gray-100">
                                        {personas.map((p) => (
                                            <label
                                                key={p.id}
                                                className="flex cursor-pointer items-center gap-2 border-b border-gray-50 p-2 hover:bg-gray-50"
                                            >
                                                <input
                                                    type="checkbox"
                                                    checked={data.persona_ids.includes(p.id)}
                                                    onChange={() => togglePersona(p.id)}
                                                />
                                                <span>{p.avatar_emoji}</span>
                                                <span className="font-medium" style={{ color: p.avatar_color }}>
                                                    {p.name}
                                                </span>
                                                <span className="flex-1 truncate text-xs text-gray-400">{p.title}</span>
                                                {p.ai_model && (
                                                    <span className="shrink-0 rounded bg-gray-100 px-1.5 py-0.5 text-[10px] text-gray-500">
                                                        {p.ai_model.name}
                                                    </span>
                                                )}
                                            </label>
                                        ))}
                                    </div>
                                </>
                            )}

                            <button
                                disabled={!ready}
                                className="mt-3 w-full rounded-lg bg-indigo-600 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-40"
                            >
                                {t('index.submit')}
                            </button>
                        </form>

                        <div className="rounded-xl bg-white p-5 shadow-sm">
                            <h2 className="font-semibold text-gray-800">{t('index.chatsHeading')}</h2>
                            <div className="mt-3 space-y-1">
                                {chats.length === 0 && (
                                    <p className="text-sm text-gray-400">{t('index.noChats')}</p>
                                )}
                                {chats.map((c) => (
                                    <Link
                                        key={c.id}
                                        href={`/chats/${c.id}`}
                                        className="flex items-center justify-between rounded-lg border border-gray-100 p-2.5 hover:bg-gray-50"
                                    >
                                        <span className="truncate font-medium text-gray-700">{c.title}</span>
                                        <span className="shrink-0 text-xs text-gray-400">
                                            {t('index.chatMeta', {
                                                cost: Number(c.total_cost ?? 0).toFixed(4),
                                                n: c.total_messages ?? 0,
                                            })}
                                        </span>
                                    </Link>
                                ))}
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </>
    );
}
