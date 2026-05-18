import { Link } from '@inertiajs/react';

export default function ChatSidebar({ chats, activeId }) {
    return (
        <aside className="flex w-60 shrink-0 flex-col border-r border-gray-800 bg-gray-900 text-gray-300">
            <div className="border-b border-gray-800 p-4">
                <Link href="/chats" className="text-lg font-bold text-white">🧠 Cortex</Link>
            </div>

            <div className="p-3">
                <Link
                    href="/chats"
                    className="block rounded-lg bg-indigo-600 px-3 py-2 text-center text-sm font-medium text-white hover:bg-indigo-500"
                >
                    + Novi chat
                </Link>
            </div>

            <div className="flex-1 overflow-y-auto px-2 pb-2">
                {(chats ?? []).map((c) => (
                    <Link
                        key={c.id}
                        href={`/chats/${c.id}`}
                        className={`mb-1 block truncate rounded-lg px-3 py-2 text-sm ${
                            c.id === activeId ? 'bg-gray-800 text-white' : 'hover:bg-gray-800'
                        }`}
                    >
                        {c.title}
                    </Link>
                ))}
            </div>

            <div className="border-t border-gray-800 p-3">
                <a href="/admin" className="block text-xs text-gray-500 hover:text-gray-300">⚙ Cortex Settings</a>
            </div>
        </aside>
    );
}
