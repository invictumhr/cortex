import { Head, Link, useForm } from '@inertiajs/react';

export default function Index({ chats, personas }) {
    const { data, setData, post, processing } = useForm({
        title: '',
        description: '',
        rounds_per_turn: 5,
        persona_ids: [],
    });

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

    return (
        <>
            <Head title="Cortex Boardroom" />
            <div className="min-h-screen bg-gray-100">
                <div className="mx-auto max-w-5xl p-6">
                    <div className="flex items-end justify-between">
                        <div>
                            <h1 className="text-2xl font-bold text-gray-800">🧠 Cortex Boardroom</h1>
                            <p className="text-sm text-gray-500">AI multi-model brainstorming platforma</p>
                        </div>
                        <a href="/admin" className="text-xs text-gray-400 hover:text-gray-600">⚙ Admin panel</a>
                    </div>

                    <div className="mt-6 grid gap-6 md:grid-cols-2">
                        <form onSubmit={submit} className="rounded-xl bg-white p-5 shadow-sm">
                            <h2 className="font-semibold text-gray-800">Novi boardroom</h2>
                            <input
                                value={data.title}
                                onChange={(e) => setData('title', e.target.value)}
                                placeholder="Naslov ili tema rasprave"
                                className="mt-3 w-full rounded-lg border-gray-300 text-sm"
                            />
                            <textarea
                                value={data.description}
                                onChange={(e) => setData('description', e.target.value)}
                                placeholder="Opis (opcionalno)"
                                rows={2}
                                className="mt-2 w-full rounded-lg border-gray-300 text-sm"
                            />
                            <div className="mt-2 flex items-center gap-2">
                                <label className="text-xs text-gray-500">Krugova po unosu</label>
                                <select
                                    value={data.rounds_per_turn}
                                    onChange={(e) => setData('rounds_per_turn', Number(e.target.value))}
                                    className="rounded-lg border-gray-300 text-sm"
                                >
                                    {[1, 2, 5, 10, 20, 50, 100, 200].map((n) => (
                                        <option key={n} value={n}>{n}</option>
                                    ))}
                                </select>
                            </div>

                            <div className="mt-3 text-xs font-medium text-gray-500">
                                Persone ({data.persona_ids.length} odabrano)
                            </div>
                            <div className="mt-1 max-h-72 overflow-y-auto rounded-lg border border-gray-100">
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

                            <button
                                disabled={processing || data.persona_ids.length === 0}
                                className="mt-3 w-full rounded-lg bg-indigo-600 py-2 text-sm font-medium text-white hover:bg-indigo-700 disabled:opacity-40"
                            >
                                Pokreni boardroom
                            </button>
                        </form>

                        <div className="rounded-xl bg-white p-5 shadow-sm">
                            <h2 className="font-semibold text-gray-800">Tvoji chatovi</h2>
                            <div className="mt-3 space-y-1">
                                {chats.length === 0 && (
                                    <p className="text-sm text-gray-400">Još nema chatova — pokreni prvi boardroom.</p>
                                )}
                                {chats.map((c) => (
                                    <Link
                                        key={c.id}
                                        href={`/chats/${c.id}`}
                                        className="flex items-center justify-between rounded-lg border border-gray-100 p-2.5 hover:bg-gray-50"
                                    >
                                        <span className="truncate font-medium text-gray-700">{c.title}</span>
                                        <span className="shrink-0 text-xs text-gray-400">
                                            €{Number(c.total_cost ?? 0).toFixed(4)} · {c.total_messages ?? 0} por.
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
