import { useTheme } from '@/hooks/useTheme';

/**
 * Three-state segmented control: Light / System / Dark. Compact form fits in
 * the sidebar footer or topbar without screaming for attention.
 */
export default function ThemeToggle() {
    const { theme, setTheme } = useTheme();
    const options = [
        { value: 'light', label: 'Light', icon: SunIcon },
        { value: 'system', label: 'System', icon: SystemIcon },
        { value: 'dark', label: 'Dark', icon: MoonIcon },
    ];

    return (
        <div className="inline-flex items-center gap-0.5 rounded-full bg-ink-100 p-0.5 ring-1 ring-ink-200/60 dark:bg-ink-800 dark:ring-ink-700/60">
            {options.map(({ value, label, icon: Icon }) => {
                const active = theme === value;
                return (
                    <button
                        key={value}
                        onClick={() => setTheme(value)}
                        title={label}
                        aria-label={label}
                        aria-pressed={active}
                        className={`flex h-7 w-7 items-center justify-center rounded-full transition-colors duration-150 ease-snap ${
                            active
                                ? 'bg-white text-cortex-600 shadow-soft dark:bg-ink-700 dark:text-cortex-300'
                                : 'text-ink-500 hover:text-ink-700 dark:text-ink-400 dark:hover:text-ink-200'
                        }`}
                    >
                        <Icon className="h-4 w-4" />
                    </button>
                );
            })}
        </div>
    );
}

function SunIcon({ className }) {
    return (
        <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
            <circle cx="12" cy="12" r="4" />
            <path d="M12 2v2m0 16v2M4.93 4.93l1.41 1.41m11.32 11.32l1.41 1.41M2 12h2m16 0h2M4.93 19.07l1.41-1.41m11.32-11.32l1.41-1.41" />
        </svg>
    );
}

function MoonIcon({ className }) {
    return (
        <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
            <path d="M21 12.79A9 9 0 1 1 11.21 3 7 7 0 0 0 21 12.79z" />
        </svg>
    );
}

function SystemIcon({ className }) {
    return (
        <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
            <rect x="3" y="4" width="18" height="12" rx="2" />
            <path d="M8 20h8M12 16v4" />
        </svg>
    );
}
