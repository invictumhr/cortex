import { Link } from '@inertiajs/react';
import { useT } from '@/i18n/I18nProvider';

export default function GuestLayout({ children }) {
    const { t } = useT();
    const features = [
        t('guest.feature1'),
        t('guest.feature2'),
        t('guest.feature3'),
    ];

    return (
        <div className="flex min-h-screen">
            <div className="relative hidden flex-col justify-between overflow-hidden bg-gradient-to-br from-indigo-900 via-violet-900 to-slate-900 p-12 lg:flex lg:w-1/2">
                <div className="pointer-events-none absolute -left-24 -top-24 h-72 w-72 rounded-full bg-indigo-500/30 blur-3xl" />
                <div className="pointer-events-none absolute -bottom-32 -right-16 h-80 w-80 rounded-full bg-violet-500/20 blur-3xl" />

                <div className="relative flex items-center gap-3">
                    <div className="flex h-11 w-11 items-center justify-center rounded-xl bg-white/10 text-2xl backdrop-blur">
                        🧠
                    </div>
                    <span className="text-lg font-semibold tracking-tight text-white">
                        {t('app.brand')}
                    </span>
                </div>

                <div className="relative max-w-md">
                    <h1 className="text-4xl font-bold leading-tight text-white">
                        {t('guest.headline')}
                    </h1>
                    <p className="mt-4 text-base leading-relaxed text-indigo-200">
                        {t('guest.lead')}
                    </p>
                    <ul className="mt-8 space-y-3">
                        {features.map((line) => (
                            <li
                                key={line}
                                className="flex items-center gap-3 text-sm text-indigo-100"
                            >
                                <span className="flex h-5 w-5 shrink-0 items-center justify-center rounded-full bg-indigo-400/25 text-[11px] font-bold text-white">
                                    ✓
                                </span>
                                {line}
                            </li>
                        ))}
                    </ul>
                </div>

                <p className="relative text-xs text-indigo-300/60">
                    {t('guest.footer')}
                </p>
            </div>

            <div className="flex w-full flex-col items-center justify-center bg-white px-6 py-12 lg:w-1/2">
                <div className="w-full max-w-sm">
                    <Link
                        href="/"
                        className="mb-8 flex items-center gap-2.5 lg:hidden"
                    >
                        <div className="flex h-10 w-10 items-center justify-center rounded-xl bg-indigo-600 text-xl">
                            🧠
                        </div>
                        <span className="text-lg font-semibold tracking-tight text-gray-900">
                            {t('app.brand')}
                        </span>
                    </Link>

                    {children}
                </div>
            </div>
        </div>
    );
}
