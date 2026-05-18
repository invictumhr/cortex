import { useState } from 'react';

export default function PowerShellModal({ chatId, onClose }) {
    const [command, setCommand] = useState('');
    const [result, setResult] = useState(null);
    const [running, setRunning] = useState(false);

    const run = async () => {
        setRunning(true);
        setResult(null);
        try {
            const { data } = await window.axios.post(`/chats/${chatId}/powershell`, {
                command,
                approved: true,
            });
            setResult(data);
        } catch (e) {
            setResult({ ok: false, error: e.response?.data?.error || 'Greška pri izvršavanju.' });
        } finally {
            setRunning(false);
        }
    };

    const outputText = () => {
        if (!result) return '';
        if (result.blocked) return `BLOKIRANO: ${result.reason}`;
        if (result.error) return `GREŠKA: ${result.error}`;
        return result.result?.output || '(bez izlaza)';
    };

    return (
        <div
            className="fixed inset-0 z-50 flex items-center justify-center bg-black/40 p-4"
            onClick={onClose}
        >
            <div
                className="w-full max-w-lg rounded-xl bg-white p-5 shadow-xl"
                onClick={(e) => e.stopPropagation()}
            >
                <h3 className="font-semibold text-gray-800">⚡ PowerShell naredba</h3>
                <p className="mt-2 rounded-lg bg-amber-50 p-2 text-xs text-amber-700">
                    Naredba se izvršava lokalno i samo ako je na allowlist-i. Sidecar mora biti
                    pokrenut: <code className="font-mono">php artisan cortex:ps-executor</code>
                </p>
                <input
                    value={command}
                    onChange={(e) => setCommand(e.target.value)}
                    placeholder="npr. git status"
                    className="mt-3 w-full rounded-lg border-gray-300 font-mono text-sm"
                />
                <div className="mt-3 flex justify-end gap-2">
                    <button onClick={onClose} className="rounded-lg bg-gray-100 px-3 py-1.5 text-sm text-gray-700">
                        Odustani
                    </button>
                    <button
                        onClick={run}
                        disabled={running || !command.trim()}
                        className="rounded-lg bg-indigo-600 px-3 py-1.5 text-sm text-white disabled:opacity-40"
                    >
                        {running ? 'Izvršavam…' : 'Dozvoli i izvrši'}
                    </button>
                </div>
                {result && (
                    <pre className="mt-3 max-h-60 overflow-auto rounded-lg bg-gray-900 p-3 text-xs text-green-300">
                        {outputText()}
                    </pre>
                )}
            </div>
        </div>
    );
}
