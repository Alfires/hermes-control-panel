<?php
declare(strict_types=1);

/** Declarative registries: pages, blocks, panel actions and curated Hermes sources.
 *  Code owns capability; configuration only composes what is registered here. */
final class PanelActions {
    public static function catalog(): array {
        $public = ['login' => true, 'me' => true];
        $actions = [
            'login', 'logout', 'me', 'bootstrap', 'state.diff', 'agent.create', 'agent.update',
            'agent.delete', 'sessions.list', 'session.get', 'session.delete', 'chat.send',
            'escalation.decide', 'approval.decide', 'security.scan', 'findings.list', 'search.query',
            'ui.config.propose', 'ui.config.approve', 'ui.config.rollback', 'ui.config.history',
            'ui.config.diff', 'hermes.source', 'stt.transcribe', 'stt.status', 'selftest.run',
            'selftest.report', 'theme.presets', 'theme.set', 'vault.test', 'vault.list',
            'audit.list', 'setup.status', 'setup.complete', 'hermes.deep_link',
        ];
        $out = [];
        foreach ($actions as $i => $name) {
            $read = (bool) preg_match('/^(me|bootstrap|state\.diff|.*\.list|.*\.get|.*\.history|.*\.diff|.*\.status|.*\.report|theme\.presets|search\.query|vault\.list|audit\.list|setup\.status)$/', $name);
            $out[$name] = [
                'method' => $read ? 'GET' : 'POST',
                'capability' => 'panel.' . str_replace('.', '_', $name),
                'response' => ['ok' => 'boolean', 'data' => 'object'],
                'owner_only' => !isset($public[$name]),
                'id' => $i + 1,
            ];
        }
        return $out;
    }
}

final class HermesSources {
    /** Only these endpoints may be fetched from Hermes. Never add a secret-bearing route. */
    public static function catalog(): array {
        $def = static function (string $path, array $fields, string $cap = 'hermes.read', int $ttl = 60): array {
            return ['method' => 'GET', 'path' => $path, 'fields' => $fields,
                    'capability' => $cap, 'ttl' => $ttl, 'scrub' => true];
        };
        return [
            'hermes.status' => $def('/health', ['status', 'version', 'uptime'], 'hermes.status', 15),
            'hermes.sessions.list' => $def('/v1/sessions', ['id', 'title', 'created_at', 'updated_at'], 'hermes.sessions.list', 30),
            'hermes.session.get' => $def('/v1/sessions/{id}', ['id', 'title', 'messages'], 'hermes.sessions.list', 30),
            'hermes.models.options' => $def('/v1/models', ['id', 'name', 'context_length'], 'hermes.models.list', 300),
            'hermes.tools.toolsets' => $def('/v1/toolsets', ['id', 'name', 'description'], 'hermes.tools.list', 300),
            'hermes.skills.list' => $def('/v1/skills', ['id', 'name', 'description'], 'hermes.skills.list', 300),
            'hermes.cron.jobs' => $def('/v1/cron/jobs', ['id', 'name', 'schedule', 'enabled'], 'hermes.cron.list', 60),
            'hermes.analytics' => $def('/v1/analytics', ['requests', 'tokens', 'errors'], 'hermes.analytics.read', 120),
            'hermes.themes' => $def('/api/themes', ['id', 'name'], 'hermes.themes.list', 600),
            'hermes.config.schema' => $def('/api/config/schema', ['fields', 'version'], 'hermes.config.read', 600),
            'hermes.env.names' => $def('/api/env/names', ['names'], 'hermes.env.names', 600),
            'hermes.messaging.platforms' => $def('/api/messaging/platforms', ['id', 'name', 'connected'], 'hermes.messaging.read', 120),
            'hermes.webhooks' => $def('/api/webhooks', ['id', 'url', 'event'], 'hermes.webhooks.list', 120),
            'hermes.gateway.capabilities' => $def('/v1/capabilities', ['capabilities', 'version'], 'hermes.status', 120),
            'hermes.logs' => $def('/api/logs', ['level', 'message', 'created_at'], 'hermes.logs.read', 30),
            'hermes.files.meta' => $def('/api/files/meta', ['path', 'size', 'modified'], 'hermes.files.read', 60),
            'hermes.git.status' => $def('/api/git/status', ['branch', 'clean', 'ahead'], 'hermes.files.read', 60),
            'hermes.profiles' => $def('/api/profiles', ['id', 'name', 'description'], 'hermes.profiles.list', 300),
            'hermes.plugins' => $def('/api/plugins', ['id', 'name', 'enabled'], 'hermes.plugins.list', 300),
            'hermes.mcp' => $def('/api/mcp', ['id', 'name', 'connected'], 'hermes.mcp.list', 300),
        ];
    }

    private const FORBIDDEN_FRAGMENTS = [
        '/env/reveal', '/config/raw', '/debug-share', '/files/ticket', '/pty', '/host-terminal',
        '/auth/native/token', '/ws', '/audio/transcribe-stream', '/plugin-providers',
    ];

    public static function isForbidden(string $path): bool {
        if ($path === '') return true;
        foreach (self::FORBIDDEN_FRAGMENTS as $fragment) {
            if (str_contains($path, $fragment)) return true;
        }
        return false;
    }

    public static function get(string $id): ?array {
        return self::catalog()[$id] ?? null;
    }
}

final class PageRegistry {
    public static function builtin(): array {
        $groups = [
            'Core' => ['overview', 'chats', 'agents', 'approvals', 'security', 'vault', 'settings', 'audit', 'setup', 'search'],
            // Hermes capabilities are NOT separate sidebar sections. They are
            // one integrated workspace with internal area tabs, so the UI stays
            // simple and the pages feel built in rather than bolted on.
            'Workspace' => ['hermes', 'themes', 'self-test'],
        ];
        $icons = ['overview' => '◈', 'chats' => '✎', 'agents' => '◆', 'approvals' => '✓', 'security' => '⚑',
            'vault' => '⚿', 'settings' => '⚙', 'audit' => '≡', 'setup' => '⚒', 'search' => '⌕',
            'themes' => '◐', 'self-test' => '✦', 'hermes' => '▦'];
        $pages = [];
        $order = 0;
        foreach ($groups as $group => $ids) {
            foreach ($ids as $id) {
                $pages[$id] = [
                    'id' => $id,
                    'route' => $id === 'overview' ? '/' : '/' . $id,
                    'nav' => ['label' => ucwords(str_replace('-', ' ', $id)), 'icon' => $icons[$id] ?? '▦', 'order' => $order += 10, 'group' => $group],
                    'capabilities' => ['panel.bootstrap'],
                    'blocks' => [['instance' => 'main-' . $id, 'type' => 'table', 'source' => 'panel.bootstrap', 'props' => ['title' => ucwords(str_replace('-', ' ', $id))]]],
                    'origin' => 'core',
                ];
            }
        }
        return $pages;
    }

    public static function ids(): array {
        return array_keys(self::builtin());
    }
}

final class BlockRegistry {
    public static function catalog(): array {
        $types = ['hero', 'kpi', 'cardGrid', 'table', 'form', 'jsonEditor', 'chat', 'timeline', 'chart',
            'terminalView', 'configEditor', 'auditView', 'searchResults', 'wizardStep', 'voiceRecorder',
            'themeGallery', 'statusStrip'];
        $out = [];
        foreach ($types as $t) {
            $out[$t] = ['renderer' => $t, 'ai_editable' => !in_array($t, ['terminalView', 'configEditor'], true),
                'risk' => in_array($t, ['terminalView', 'configEditor'], true) ? 'privileged' : 'none'];
        }
        return $out;
    }
}
