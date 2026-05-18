import { useEffect, useState } from 'react';

/**
 * Reveals text progressively for a "streaming" feel. When `animate` is false
 * the full text is shown immediately (used for already-seen messages).
 */
export default function TypewriterText({ text, animate = false, speed = 14 }) {
    const [shown, setShown] = useState(animate ? '' : text);

    useEffect(() => {
        if (!animate) {
            setShown(text);
            return;
        }

        let i = 0;
        setShown('');
        const step = Math.max(2, Math.ceil(text.length / 400));
        const id = setInterval(() => {
            i += step;
            setShown(text.slice(0, i));
            if (i >= text.length) {
                clearInterval(id);
            }
        }, speed);

        return () => clearInterval(id);
    }, [text, animate, speed]);

    return <span className="whitespace-pre-wrap break-words">{shown}</span>;
}
