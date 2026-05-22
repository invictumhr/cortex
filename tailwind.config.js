import defaultTheme from 'tailwindcss/defaultTheme';
import forms from '@tailwindcss/forms';

/** @type {import('tailwindcss').Config} */
export default {
    content: [
        './vendor/laravel/framework/src/Illuminate/Pagination/resources/views/*.blade.php',
        './storage/framework/views/*.php',
        './resources/views/**/*.blade.php',
        './resources/js/**/*.jsx',
    ],

    // Toggled via a `.dark` class on <html>. The Theme module in app.jsx
    // manages it: initial value reads system preference, after that the
    // user's choice is persisted to localStorage('cortex.theme').
    darkMode: 'class',

    theme: {
        extend: {
            fontFamily: {
                // Inter is the workhorse — crisp at 13–15px, supports
                // tabular-nums for ledger tables. Loaded from Google Fonts
                // in resources/css/app.css.
                sans: ['Inter', ...defaultTheme.fontFamily.sans],
                mono: ['JetBrains Mono', ...defaultTheme.fontFamily.mono],
            },
            colors: {
                // Cortex accent — sits between Claude's orange and GPT's
                // emerald: a refined sapphire that reads "thoughtful, neutral
                // tool" rather than "consumer chatbot". 500 is the workhorse.
                cortex: {
                    50:  '#f0f5ff',
                    100: '#dde7ff',
                    200: '#bccdff',
                    300: '#94a9ff',
                    400: '#6a82f8',
                    500: '#4c5fe6',
                    600: '#3a47c7',
                    700: '#2f3a9c',
                    800: '#272f7a',
                    900: '#1f2660',
                    950: '#141738',
                },
                // Slightly warmed neutrals — pure zinc reads too clinical.
                ink: {
                    50:  '#fafaf9',
                    100: '#f4f4f3',
                    200: '#e7e7e5',
                    300: '#d1d1ce',
                    400: '#a3a3a0',
                    500: '#74746f',
                    600: '#54544f',
                    700: '#3d3d39',
                    800: '#27272a',
                    900: '#1a1a1c',
                    950: '#0f0f10',
                },
            },
            boxShadow: {
                // Card surfaces sit on a single soft shadow + 1px ring of
                // mostly-transparent ink — that's what gives Claude/GPT
                // their "weightless paper" feel. No multi-layered drop shadow.
                'soft':   '0 1px 2px 0 rgba(15, 15, 16, 0.05)',
                'pop':    '0 8px 24px -6px rgba(15, 15, 16, 0.12), 0 2px 6px -2px rgba(15, 15, 16, 0.06)',
                'inset-line': 'inset 0 0 0 1px rgba(15, 15, 16, 0.06)',
            },
            ringWidth: { 'DEFAULT': '1px' },
            borderRadius: {
                '4xl': '2rem',
            },
            transitionTimingFunction: {
                // Apple-ish ease used across hover / press transitions.
                'snap': 'cubic-bezier(0.32, 0.72, 0, 1)',
            },
            keyframes: {
                'fade-up': {
                    '0%': { opacity: '0', transform: 'translateY(4px)' },
                    '100%': { opacity: '1', transform: 'translateY(0)' },
                },
                'soft-pulse': {
                    '0%, 100%': { opacity: '0.4' },
                    '50%': { opacity: '1' },
                },
            },
            animation: {
                'fade-up': 'fade-up 0.25s ease-out',
                'soft-pulse': 'soft-pulse 1.4s ease-in-out infinite',
            },
        },
    },

    plugins: [forms],
};
