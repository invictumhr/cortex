export default function RoundControls({ chat, presets, onSetRounds, onPause, onResume, onAddRounds }) {
    const total = chat.rounds_per_turn || 1;
    const pct = total > 0 ? Math.min(100, Math.round((chat.current_round / total) * 100)) : 0;

    return (
        <div className="space-y-3">
            <div>
                <label className="text-xs font-medium text-gray-500">Krugova po unosu</label>
                <select
                    value={chat.rounds_per_turn}
                    onChange={(e) => onSetRounds(Number(e.target.value))}
                    className="mt-1 w-full rounded-lg border-gray-300 text-sm"
                >
                    {(presets ?? [1, 2, 5, 10, 20, 50, 100, 200]).map((p) => (
                        <option key={p} value={p}>{p}</option>
                    ))}
                </select>
            </div>

            <div>
                <div className="flex justify-between text-xs text-gray-500">
                    <span>Krug {chat.current_round} / {total}</span>
                    <span className="capitalize">{chat.status}</span>
                </div>
                <div className="mt-1 h-2 overflow-hidden rounded-full bg-gray-200">
                    <div className="h-full bg-indigo-500 transition-all duration-500" style={{ width: pct + '%' }} />
                </div>
            </div>

            <div className="flex gap-2">
                {chat.status === 'active' ? (
                    <button
                        onClick={onPause}
                        className="flex-1 rounded-lg bg-amber-100 px-2 py-1.5 text-xs font-medium text-amber-800 hover:bg-amber-200"
                    >
                        ⏸ Pauziraj
                    </button>
                ) : (
                    <button
                        onClick={onResume}
                        className="flex-1 rounded-lg bg-emerald-100 px-2 py-1.5 text-xs font-medium text-emerald-800 hover:bg-emerald-200"
                    >
                        ▶ Nastavi
                    </button>
                )}
                <button
                    onClick={() => onAddRounds(5)}
                    className="flex-1 rounded-lg bg-gray-100 px-2 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-200"
                >
                    +5 krugova
                </button>
            </div>
        </div>
    );
}
