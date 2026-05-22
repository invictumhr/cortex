import { useEffect, useState } from 'react';

/**
 * Dark-mode controller. The toggle has three states: 'light', 'dark', and
 * 'system' (follow OS). We persist whatever the user chose to localStorage;
 * 'system' is treated as "no override, mirror prefers-color-scheme".
 *
 * The class on <html> is set imperatively rather than via React state so it
 * lands before paint — no flash of wrong theme on first mount.
 */
const STORAGE_KEY = 'cortex.theme';

function readStored() {
    try {
        return localStorage.getItem(STORAGE_KEY) || 'system';
    } catch {
        return 'system';
    }
}

function systemPrefersDark() {
    return window.matchMedia?.('(prefers-color-scheme: dark)').matches ?? false;
}

function applyTheme(theme) {
    const dark = theme === 'dark' || (theme === 'system' && systemPrefersDark());
    document.documentElement.classList.toggle('dark', dark);
}

// Run once at import time so the class is set before React mounts. No-op on
// SSR (no window).
if (typeof window !== 'undefined') {
    applyTheme(readStored());
}

export function useTheme() {
    const [theme, setThemeState] = useState(() => readStored());

    useEffect(() => {
        applyTheme(theme);
        try { localStorage.setItem(STORAGE_KEY, theme); } catch {}
    }, [theme]);

    // Keep us in sync with the OS toggle while the user is on 'system'.
    useEffect(() => {
        if (theme !== 'system') return;
        const mq = window.matchMedia('(prefers-color-scheme: dark)');
        const handler = () => applyTheme('system');
        mq.addEventListener?.('change', handler);
        return () => mq.removeEventListener?.('change', handler);
    }, [theme]);

    return {
        theme,
        setTheme: setThemeState,
        isDark: document.documentElement.classList.contains('dark'),
    };
}
