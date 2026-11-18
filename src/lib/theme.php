<?php
declare(strict_types=1);

/** Theme presets and token validation for the config-driven UI. */
final class Theme {
    private const INK = '#172b40';
    private const ACCENT = '#ec8621';

    public static function presets(): array {
        $light = [
            '--ink' => self::INK, '--ink-2' => '#4a5f75', '--muted' => '#7a8ca0',
            '--line' => '#e6ebf1', '--surface' => '#ffffff', '--subtle' => '#f5f7fa',
            '--accent' => self::ACCENT, '--radius' => '16px', '--good' => '#1a9670',
            '--bad' => '#c4413a', '--info' => '#3f78d8', '--plum' => '#a8508a',
            '--chat-surface' => '#f6f7f9', '--font' => 'system',
        ];
        $dark = array_replace($light, [
            '--ink' => '#e8eef5', '--ink-2' => '#b7c7d6', '--muted' => '#8ba0b4',
            '--line' => '#2b3d4f', '--surface' => '#142130', '--subtle' => '#101a26',
            '--accent' => '#f29a3d', '--chat-surface' => '#101a26',
        ]);
        $square = ['--radius' => '0px'];
        return [
            'brezel-light-rounded' => ['mode' => 'light', 'radius' => 'rounded', 'label' => 'Brezel light', 'tokens' => $light],
            'brezel-dark-rounded' => ['mode' => 'dark', 'radius' => 'rounded', 'label' => 'Brezel dark', 'tokens' => $dark],
            'brezel-light-square' => ['mode' => 'light', 'radius' => 'square', 'label' => 'Brezel light square', 'tokens' => array_replace($light, $square)],
            'brezel-dark-square' => ['mode' => 'dark', 'radius' => 'square', 'label' => 'Brezel dark square', 'tokens' => array_replace($dark, $square)],
            'midnight' => ['mode' => 'dark', 'radius' => 'rounded', 'label' => 'Midnight', 'tokens' => array_replace($dark, ['--accent' => '#4dd0e1', '--radius' => '12px'])],
            'ember' => ['mode' => 'light', 'radius' => 'rounded', 'label' => 'Ember', 'tokens' => array_replace($light, ['--accent' => '#c2410c', '--radius' => '14px'])],
            'mono' => ['mode' => 'light', 'radius' => 'square', 'label' => 'Mono', 'tokens' => array_replace($light, [
                '--ink' => '#111827', '--accent' => '#374151', '--radius' => '0px',
            ])],
        ];
    }

    public static function validateTokens(array $tokens): bool {
        if (count($tokens) > 40) return false;
        foreach ($tokens as $key => $value) {
            if (!is_string($key) || !preg_match('/^--[a-z0-9-]{1,40}$/', $key)) return false;
            if (!is_string($value) || strlen($value) > 120) return false;
            $lower = strtolower($value);
            foreach (['url(', '@import', 'expression(', '<', 'javascript:', '</style', ';'] as $bad) {
                if (str_contains($lower, $bad)) return false;
            }
            $ok = preg_match('/^#[0-9a-fA-F]{3,8}$/', $value)
                || preg_match('/^\d+(px|rem|em|vh|vw)?$/', $value)
                || preg_match('/^\d+(\.\d+)?\s+\d+(px|rem|em)\s+\d+(px|rem|em)(?:\s+rgba?\([0-9.,\s%]+\))?$/', $value)
                || preg_match('/^rgba?\([0-9.,\s%]+\)$/', $value)
                || in_array($value, ['system', 'sans', 'mono', 'serif', 'auto'], true);
            if (!$ok) return false;
        }
        return true;
    }

    public static function cssVars(?string $presetId, array $overrides = []): string {
        $presets = self::presets();
        $preset = $presets[$presetId] ?? $presets['brezel-light-rounded'];
        $tokens = array_replace($preset['tokens'], $overrides);
        $lines = [];
        foreach ($tokens as $k => $v) {
            if (self::validateTokens([$k => $v])) $lines[] = "$k:$v";
        }
        return implode(';', $lines);
    }
}
