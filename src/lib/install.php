<?php
declare(strict_types=1);

/** Static checks for the two-container compose file and the one-line installer. */
final class InstallChecks {
    public static function composeServices(string $yaml): array {
        $services = [];
        $inServices = false;
        foreach (preg_split('/\r?\n/', $yaml) ?: [] as $line) {
            if (preg_match('/^services\s*:\s*(?:#.*)?$/', $line)) { $inServices = true; continue; }
            if (!$inServices) continue;
            if ($line !== '' && $line[0] !== ' ' && $line[0] !== "\t") break;
            if (preg_match('/^  ([A-Za-z0-9_-]+)\s*:\s*(?:#.*)?$/', $line, $m)) $services[] = $m[1];
        }
        return $services;
    }

    public static function installerSafe(string $script): bool {
        $script = trim($script);
        if ($script === '') return false;
        if (!str_contains($script, 'set -euo pipefail')) return false;
        if (!preg_match('/\bmain\s+"\$@"\s*$/', $script)) return false;
        if (str_contains($script, 'set -x')) return false;
        if (!str_contains($script, 'mktemp')) return false;
        if (!str_contains($script, 'trap')) return false;
        if (!str_contains($script, 'openssl rand')) return false;
        if (!str_contains($script, 'chmod 600')) return false;
        if (preg_match('/(?:echo|printf|echo -n)[^\n]*\$(?:[A-Z_]*(?:SECRET|TOKEN|PASSWORD|KEY)[A-Z_]*)/i', $script)) return false;
        if (str_contains($script, 'curl -s') && !str_contains($script, 'curl -fsSL')) return false;
        return true;
    }
}
