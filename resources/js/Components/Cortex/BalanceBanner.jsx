import { usePage } from '@inertiajs/react';
import { useT } from '@/i18n/I18nProvider';

/**
 * Above-the-fold balance reminder. Three states:
 *   empty:   spendable < min_send → red, "Top up to send messages" + CTA
 *   low:     spendable < low_warning → amber, "Running low" + CTA
 *   ok:      nothing rendered
 *
 * Reads wallet thresholds from the Inertia-shared `wallet` prop so anywhere
 * we drop this component it stays in sync with backend config.
 */
export default function BalanceBanner({ compact = false }) {
    const { t } = useT();
    const wallet = usePage().props.wallet;
    if (!wallet) return null;

    const { available, low_warning, min_send, topup_url, currency = 'EUR' } = wallet;

    const state = available < min_send ? 'empty' : (available < low_warning ? 'low' : 'ok');
    if (state === 'ok') return null;

    const empty = state === 'empty';

    const palette = empty
        ? {
            bg: 'bg-rose-50 dark:bg-rose-950/40',
            ring: 'ring-rose-200/60 dark:ring-rose-900/60',
            text: 'text-rose-700 dark:text-rose-300',
            icon: 'text-rose-500',
            btn: 'bg-rose-600 text-white hover:bg-rose-500',
        }
        : {
            bg: 'bg-amber-50 dark:bg-amber-950/40',
            ring: 'ring-amber-200/60 dark:ring-amber-900/60',
            text: 'text-amber-700 dark:text-amber-300',
            icon: 'text-amber-500',
            btn: 'bg-amber-600 text-white hover:bg-amber-500',
        };

    const title = empty ? t('balance.emptyTitle') : t('balance.lowTitle');
    const body  = empty
        ? t('balance.emptyBody')
        : t('balance.lowBody', { currency, amount: available.toFixed(2) });

    if (compact) {
        return (
            <a
                href={topup_url}
                className={`inline-flex items-center gap-2 rounded-full ${palette.bg} px-3 py-1 text-xs font-medium ${palette.text} ring-1 ${palette.ring}`}
            >
                <DotIcon className={`h-1.5 w-1.5 ${palette.icon} fill-current`} />
                <span>
                    {empty ? t('balance.compactEmpty') : t('balance.compactLow', { amount: available.toFixed(2) })}
                </span>
                <ArrowRightIcon className="h-3 w-3" />
            </a>
        );
    }

    return (
        <div className={`flex items-center justify-between gap-4 rounded-2xl ${palette.bg} px-4 py-3 ring-1 ${palette.ring}`}>
            <div className="flex items-start gap-3 min-w-0">
                <div className={`mt-0.5 shrink-0 ${palette.icon}`}>
                    <AlertIcon className="h-5 w-5" />
                </div>
                <div className="min-w-0">
                    <p className={`text-sm font-semibold ${palette.text}`}>{title}</p>
                    <p className={`text-xs ${palette.text} opacity-80`}>{body}</p>
                </div>
            </div>
            <a
                href={topup_url}
                className={`shrink-0 inline-flex items-center gap-1.5 rounded-xl ${palette.btn} px-3.5 py-2 text-xs font-medium shadow-soft transition-colors`}
            >
                {t('balance.topup')}
                <ArrowRightIcon className="h-3.5 w-3.5" />
            </a>
        </div>
    );
}

function AlertIcon({ className }) {
    return (
        <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2" strokeLinecap="round" strokeLinejoin="round">
            <circle cx="12" cy="12" r="10" />
            <line x1="12" y1="8" x2="12" y2="12" />
            <line x1="12" y1="16" x2="12.01" y2="16" />
        </svg>
    );
}
function DotIcon({ className }) {
    return <svg className={className} viewBox="0 0 8 8"><circle cx="4" cy="4" r="3" /></svg>;
}
function ArrowRightIcon({ className }) {
    return <svg className={className} viewBox="0 0 24 24" fill="none" stroke="currentColor" strokeWidth="2.5" strokeLinecap="round" strokeLinejoin="round"><path d="M5 12h14M13 5l7 7-7 7"/></svg>;
}
