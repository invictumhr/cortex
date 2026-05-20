<?php

namespace App\Services;

/**
 * Maps ISO 639-1 language codes to the full English language names used in
 * model prompts. One source of truth for what languages the CLI and GUI may
 * pick. The heuristic detect() method below is unused after the switch to
 * explicit language selection but kept for potential future smart-default use.
 */
class LanguageDetector
{
    /**
     * ISO 639-1 code => full language name (as used inside model prompts).
     * The first entry is the global default.
     */
    public const SUPPORTED = [
        'en' => 'English',
        'hr' => 'Croatian',
        'sr' => 'Serbian',
        'bs' => 'Bosnian',
        'sl' => 'Slovenian',
        'sk' => 'Slovak',
        'cs' => 'Czech',
        'pl' => 'Polish',
        'bg' => 'Bulgarian',
        'ru' => 'Russian',
        'uk' => 'Ukrainian',
        'de' => 'German',
        'fr' => 'French',
        'it' => 'Italian',
        'es' => 'Spanish',
        'pt' => 'Portuguese',
        'nl' => 'Dutch',
        'ro' => 'Romanian',
        'hu' => 'Hungarian',
        'sv' => 'Swedish',
        'el' => 'Greek',
        'da' => 'Danish',
        'fi' => 'Finnish',
    ];

    /**
     * Map an ISO 639-1 code (or full English name) to the full name used in
     * prompts. Unknown values fall back to the configured default language.
     */
    public static function fromIso(?string $code): string
    {
        $code = strtolower(trim((string) $code));

        if (isset(self::SUPPORTED[$code])) {
            return self::SUPPORTED[$code];
        }

        // Also accept the full English name case-insensitively (e.g. "French").
        foreach (self::SUPPORTED as $name) {
            if (strtolower($name) === $code) {
                return $name;
            }
        }

        return (string) config('cortex.output_language', 'English');
    }

    /** @return array<int, string> */
    public static function supportedIsoCodes(): array
    {
        return array_keys(self::SUPPORTED);
    }

    /** @return array<string, string> */
    public static function supported(): array
    {
        return self::SUPPORTED;
    }

    /**
     * Heuristic Croatian-vs-English detector. Currently unused (we now select
     * the language explicitly) — kept for potential future smart-default use.
     */
    public function detect(string $text): string
    {
        $text = mb_strtolower(trim($text));

        if ($text === '') {
            return $this->default();
        }

        $hrChars = preg_match_all('/[čćšžđ]/u', $text);
        if ($hrChars >= 2) {
            return 'Croatian';
        }

        $hrWords = preg_match_all(
            '/\b(je|na|da|kako|sto|sta|ali|sam|smo|nije|ima|treba|mora|moze|samo|jos|biti|tako|sve|jer|ovo|ovaj|ova|ako|ili|za|od|do|po|pri|kod|iz|bez|prema|protiv|gdje|kada|tko|cega|koliko|moj|moja|tvoj|tvoja|nas|vas|napravi|napisi|pomozi|hocu|zelim|imam|imamo|nemam|treba|trebamo|nismo|nisam)\b/u',
            $text
        );

        $enWords = preg_match_all(
            '/\b(the|and|of|to|a|in|for|is|on|that|by|this|with|are|as|was|be|or|have|from|but|not|what|when|where|which|how|why|should|would|could|will|i|we|you|they|it|do|does|can|make|build|create|design|test|run|new|need|use|using|please|hello|hi|like|just|some|all|any|more|most|than|so|then|about|over|under|while|been|has|had|its|if|out|up|down|into|other|only)\b/u',
            $text
        );

        if ($hrChars >= 1 || $hrWords > $enWords) {
            return 'Croatian';
        }

        if ($enWords > 0) {
            return 'English';
        }

        return $this->default();
    }

    public function default(): string
    {
        return (string) config('cortex.output_language', 'English');
    }
}
