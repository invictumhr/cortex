import { useT } from '@/i18n/I18nProvider';

export default function LanguageToggle({ className = '' }) {
    const { lang, setLang, t } = useT();

    return (
        <button
            type="button"
            onClick={() => setLang(lang === 'hr' ? 'en' : 'hr')}
            title={t('app.langToggle.title')}
            aria-label={t('app.langToggle.title')}
            className={
                'inline-flex items-center gap-1 rounded-md border border-gray-200 bg-white px-2 py-1 text-xs font-medium text-gray-500 hover:bg-gray-50 hover:text-gray-700 ' +
                className
            }
        >
            <span className={lang === 'hr' ? 'font-bold text-indigo-600' : ''}>HR</span>
            <span className="text-gray-300">|</span>
            <span className={lang === 'en' ? 'font-bold text-indigo-600' : ''}>EN</span>
        </button>
    );
}
