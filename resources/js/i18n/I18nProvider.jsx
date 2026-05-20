import { createContext, useContext, useEffect, useState } from 'react';
import { translations } from './translations';

const I18nContext = createContext({
    lang: 'en',
    setLang: () => {},
    t: (k) => k,
});

function detectInitial() {
    if (typeof window === 'undefined') return 'en';
    try {
        const stored = window.localStorage.getItem('cortex.lang');
        if (stored === 'hr' || stored === 'en') return stored;
    } catch (e) {
        /* ignore */
    }
    const nav = (navigator.language || 'en').toLowerCase();
    return nav.startsWith('hr') ? 'hr' : 'en';
}

export function I18nProvider({ children }) {
    const [lang, setLangState] = useState(detectInitial);

    useEffect(() => {
        try {
            document.documentElement.lang = lang;
        } catch (e) {
            /* ignore */
        }
    }, [lang]);

    const setLang = (next) => {
        if (next !== 'hr' && next !== 'en') return;
        setLangState(next);
        try {
            window.localStorage.setItem('cortex.lang', next);
        } catch (e) {
            /* ignore */
        }
    };

    const dict = translations[lang] || translations.en;

    const t = (key, vars = {}) => {
        let str = dict[key];
        if (str === undefined) str = translations.en[key];
        if (str === undefined) str = key;
        for (const [k, v] of Object.entries(vars)) {
            str = str.split('{' + k + '}').join(String(v));
        }
        return str;
    };

    return (
        <I18nContext.Provider value={{ lang, setLang, t }}>
            {children}
        </I18nContext.Provider>
    );
}

export function useT() {
    return useContext(I18nContext);
}
