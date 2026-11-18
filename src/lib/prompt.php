<?php
declare(strict_types=1);

require_once __DIR__ . '/util.php';

/** Casual-language normaliser. Messy human input stays verbatim; the wrapper
 *  only supplies context so the model answers well without demanding,
 *  engineer-style prompts. */
final class Prompt {
    public static function normalise(string $raw, array $agent = [], array $config = []): string {
        $raw = trim(mb_substr($raw, 0, 8000));
        $role = Util::str($agent['role'] ?? 'Hermes assistant', 120);
        $style = (string)($config['prompts']['style'] ?? 'plain');
        $styleLine = match ($style) {
            'concise' => 'STYLE: answer in at most four short lines.',
            'detailed' => 'STYLE: give a thorough but plain-language answer with a short next-steps list.',
            default => 'STYLE: answer in plain language, short and concrete, no jargon unless asked.',
        };
        $tools = is_array($agent['tools'] ?? null) ? implode(', ', $agent['tools']) : 'none';
        return implode("\n", [
            'ROLE: ' . $role,
            'TOOLS_AVAILABLE: ' . $tools,
            'USER_INPUT: ' . $raw,
            'INTERPRET_AS: a request from a non-technical owner. Infer the intent; do not require exact commands.',
            $styleLine,
            'SAFETY: never reveal secrets; describe privileged actions before asking for approval.',
            'OUTPUT: first line = plain answer; then an optional "Next steps" list.',
        ]);
    }

    /** Short label shown under the composer so casual input feels supported. */
    public static function hint(): string {
        return 'Plain language is fine — e.g. “make it darker” or “check if anything bad happened overnight”.';
    }
}
