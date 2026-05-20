import { useT } from '@/i18n/I18nProvider';

export default function PersonaInfoPanel({ chat, chatPersonas, allPersonas, onToggle }) {
    const { t } = useT();
    const inChat = new Map((chatPersonas ?? []).map((p) => [p.id, p]));
    const totalTokens =
        Number(chat.total_input_tokens ?? 0) + Number(chat.total_output_tokens ?? 0);

    return (
        <aside className="flex w-72 shrink-0 flex-col border-l border-gray-200 bg-white">
            <div className="border-b border-gray-200 p-4">
                <div className="text-xs text-gray-400">{t('panel.totalCost')}</div>
                <div className="text-2xl font-semibold text-gray-800">
                    €{Number(chat.total_cost ?? 0).toFixed(4)}
                </div>
                <div className="mt-1 text-xs text-gray-400">
                    {t('panel.meta', {
                        messages: chat.total_messages ?? 0,
                        tokens: totalTokens.toLocaleString(),
                        round: chat.current_round ?? 0,
                    })}
                </div>
            </div>

            <div className="flex-1 overflow-y-auto p-4">
                <div className="mb-2 text-xs font-medium text-gray-500">
                    {t('panel.personasHeading')}
                </div>
                <div className="space-y-1">
                    {(allPersonas ?? []).map((p) => {
                        const cp = inChat.get(p.id);
                        const active = cp ? Boolean(cp.pivot?.is_active) : false;
                        return (
                            <label
                                key={p.id}
                                className="flex cursor-pointer items-start gap-2 rounded-lg p-1.5 hover:bg-gray-50"
                            >
                                <input
                                    type="checkbox"
                                    checked={active}
                                    onChange={(e) => onToggle(p, e.target.checked)}
                                    style={{ accentColor: p.avatar_color }}
                                    className="mt-1"
                                />
                                <span className="mt-0.5">{p.avatar_emoji}</span>
                                <span className="min-w-0 flex-1">
                                    <span className="flex items-baseline gap-1.5">
                                        <span
                                            className="truncate text-sm font-medium"
                                            style={{ color: active ? p.avatar_color : '#9ca3af' }}
                                        >
                                            {p.name}
                                        </span>
                                        <span className="truncate text-[10px] text-gray-400">{p.title}</span>
                                    </span>
                                    <span className="block truncate text-[10px] text-gray-400">
                                        ⚙ {p.ai_model ? p.ai_model.name : t('panel.noModel')}
                                    </span>
                                </span>
                            </label>
                        );
                    })}
                </div>
            </div>

            <div className="border-t border-gray-200 p-3">
                <a
                    href="/admin"
                    className="block rounded-lg bg-gray-100 px-3 py-2 text-center text-xs font-medium text-gray-600 hover:bg-gray-200"
                >
                    {t('panel.adminLink')}
                </a>
            </div>
        </aside>
    );
}
