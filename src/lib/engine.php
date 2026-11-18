<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/util.php';
require_once __DIR__ . '/rbac.php';
require_once __DIR__ . '/vault.php';
require_once __DIR__ . '/hostctl.php';

/** The agent runtime. The model has no tools of its own: it can only ask this
 *  class for a tool, and every ask goes through RBAC before a backend is
 *  touched. Results are scrubbed before they go back into the conversation. */
final class Engine {
    private const META_TOOLS = ['request_escalation', 'noop'];

    public static function modelEndpoint(): string {
        return getenv('PANEL_LLM_URL') ?: 'http://host.docker.internal:4097/v1/chat/completions';
    }

    public static function modelKey(): string {
        return getenv('PANEL_LLM_KEY') ?: '';
    }

    public static function callModel(array $messages, string $model, int $maxTokens = 1400): array {
        $payload = [
            'model' => $model,
            'messages' => $messages,
            'max_tokens' => $maxTokens,
            'temperature' => 0.3,
            'stream' => false,
        ];
        $ch = curl_init(self::modelEndpoint());
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . self::modelKey(),
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 180,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        if ($raw === false) return ['ok' => false, 'error' => 'model request failed: ' . $err];
        if ($code < 200 || $code >= 300) {
            return ['ok' => false, 'error' => 'model HTTP ' . $code . ': ' . substr($raw, 0, 300)];
        }
        $d = json_decode($raw, true);
        $text = $d['choices'][0]['message']['content'] ?? '';
        if (!is_string($text)) $text = '';
        return ['ok' => true, 'text' => Util::scrub($text)];
    }

    private static function systemPrompt(array $agent): string {
        $tools = [];
        foreach ($agent['tools'] as $t) {
            if (isset(RBAC::TOOLS[$t])) {
                $tools[] = '- ' . $t . ': ' . RBAC::TOOLS[$t]['desc'];
            }
        }
        $tools[] = '- request_escalation: ask the owner for one-time access to a privileged tool or a named secret (args: capability, reason, ttl). You keep working; the answer arrives later.';
        $tools[] = '- noop: nothing to do.';

        $inbox = DB::one('SELECT body FROM messages WHERE to_agent=? AND read_at IS NULL ORDER BY id DESC LIMIT 1',
            [$agent['id']]);
        $inboxText = $inbox ? "\nA message is waiting for you: " . Util::scrub($inbox['body']) : '';

        return implode("\n", [
            $agent['system_prompt'],
            '',
            'TOOLS YOU MAY ASK FOR (asking does not run them):',
            implode("\n", $tools),
            '',
            'PROTOCOL: to use a tool, reply with exactly one line and nothing else:',
            'TOOL_CALL: {"name":"tool_name","args":{...}}',
            'After a tool runs you will receive a line beginning TOOL_RESULT:. Use it, then continue.',
            'Never invent a result. Never output, guess, repeat or summarise a password, token or secret value.',
            'If you need more permission, ask with request_escalation and continue with other useful work.',
            $inboxText,
        ]);
    }

    /** Parse a tool request without trusting the model's formatting. */
    public static function parseToolCall(string $text): ?array {
        $lines = preg_split('/\r?\n/', $text) ?: [];
        foreach ($lines as $line) {
            if (preg_match('/^\s*TOOL_CALL:\s*(\{.*\})\s*$/', $line, $m)) {
                $d = json_decode($m[1], true);
                if (is_array($d) && isset($d['name'])) return $d;
            }
        }
        if (preg_match('/```(?:json)?\s*(\{\s*"tool"\s*:\s*".*?"[\s\S]*?\})\s*```/', $text, $m)) {
            $d = json_decode($m[1], true);
            if (is_array($d) && isset($d['tool'])) {
                return ['name' => $d['tool'], 'args' => $d['args'] ?? []];
            }
        }
        $d = json_decode(trim($text), true);
        if (is_array($d) && isset($d['tool'])) {
            return ['name' => $d['tool'], 'args' => $d['args'] ?? []];
        }
        return null;
    }

    public static function executeTool(array $agent, string $name, array $args): array {
        $name = strtolower(trim($name));

        if ($name === 'request_escalation') {
            $cap = Util::str($args['capability'] ?? '', 120);
            if ($cap === '') return ['ok' => false, 'error' => 'capability is required'];
            $id = RBAC::requestEscalation((int)$agent['id'], $cap,
                Util::str($args['reason'] ?? '', 300), (int)($args['ttl'] ?? 300));
            return ['ok' => true, 'message' => "Escalation request #$id queued for the owner. Continue with other work."];
        }
        if ($name === 'noop') return ['ok' => true, 'message' => 'nothing to do'];

        [$allowed, $why] = RBAC::can($agent, $name, $args);
        if (!$allowed) {
            DB::audit('agent:' . $agent['id'], 'tool.denied', "$name: $why");
            return ['ok' => false, 'error' => $why];
        }

        $result = match ($name) {
            'chat' => ['ok' => true, 'message' => 'continue'],
            'notes_read' => ['ok' => true, 'notes' => self::layoutGet('notes', [])],
            'notes_write' => self::notesWrite($args),
            'host_status' => HostCtl::call('system_status', [], $agent),
            'host_scan'   => HostCtl::call('security_scan', [], $agent),
            'host_apply'  => self::hostApply($agent, $args),
            'cve_lookup'  => HostCtl::call('cve_lookup', ['query' => Util::str($args['query'] ?? '', 200)], $agent),
            'webui_read'  => ['ok' => true, 'layout' => self::layoutGet('layout', [])],
            'webui_edit'  => self::webuiEdit($agent, $args),
            'vault_list'  => Vault::listSecrets(),
            'vault_use'   => Vault::use($agent, $args),
            'message_agent' => self::messageAgent($agent, $args),
            default => ['ok' => false, 'error' => "tool $name has no implementation"],
        };

        if (RBAC::TOOLS[$name]['risk'] === 'privileged' && !empty($result['ok'])) {
            RBAC::consumeEscalation((int)$agent['id'], $name === 'vault_use'
                ? 'vault:' . ($args['name'] ?? '') : $name);
        }
        DB::audit('agent:' . $agent['id'], 'tool.call', $name);
        return is_array($result) ? $result : ['ok' => true, 'result' => $result];
    }

    private static function hostApply(array $agent, array $args): array {
        $action = Util::str($args['action'] ?? '', 80);
        if (!isset(HostCtl::ACTIONS[$action])) {
            return ['ok' => false, 'error' => 'action must be one of: ' . implode(', ', array_keys(HostCtl::ACTIONS))];
        }
        DB::run('INSERT INTO approvals(agent_id,kind,summary,payload,created_at) VALUES(?,?,?,?,?)', [
            $agent['id'], 'host_apply', Util::str($args['reason'] ?? $action, 300),
            json_encode(['action' => $action, 'args' => $args['args'] ?? []]), time(),
        ]);
        // The escalation allows the call; the owner already saw the proposed
        // action in the approval queue. The broker executes allowlisted IDs.
        return HostCtl::call($action, is_array($args['args'] ?? null) ? $args['args'] : [], $agent);
    }

    private static function layoutGet(string $key, $default) {
        $row = DB::one('SELECT value FROM layout WHERE key=?', [$key]);
        return $row ? (json_decode($row['value'], true) ?? $default) : $default;
    }

    private static function layoutSet(string $key, $value, string $by): void {
        DB::run('INSERT INTO layout(key,value,updated_by,updated_at) VALUES(?,?,?,?)
                 ON CONFLICT(key) DO UPDATE SET value=excluded.value, updated_by=excluded.updated_by, updated_at=excluded.updated_at',
            [$key, json_encode($value), $by, time()]);
    }

    private static function notesWrite(array $args): array {
        $notes = self::layoutGet('notes', []);
        $notes[] = ['body' => Util::scrub(Util::str($args['body'] ?? '', 1000)),
                    'by' => 'agent', 'at' => time()];
        $notes = array_slice($notes, -100);
        self::layoutSet('notes', $notes, 'agent');
        return ['ok' => true, 'message' => 'note saved'];
    }

    private static function webuiEdit(array $agent, array $args): array {
        $key = Util::str($args['key'] ?? '', 80);
        if ($key === '' || !preg_match('/^[a-z0-9_.-]+$/', $key)) {
            return ['ok' => false, 'error' => 'invalid layout key'];
        }
        $value = $args['value'] ?? null;
        DB::run('INSERT INTO approvals(agent_id,kind,summary,payload,created_at) VALUES(?,?,?,?,?)', [
            $agent['id'], 'webui_edit', "layout key $key",
            json_encode(['key' => $key, 'value' => $value]), time(),
        ]);
        self::layoutSet($key, $value, 'agent:' . $agent['slug']);
        return ['ok' => true, 'message' => "layout '$key' updated and recorded for review"];
    }

    private static function messageAgent(array $agent, array $args): array {
        $slug = Util::str($args['agent'] ?? '', 80);
        $to = DB::one('SELECT * FROM agents WHERE slug=? AND enabled=1', [$slug]);
        if (!$to) return ['ok' => false, 'error' => "no agent named $slug"];
        if ((int)$to['id'] === (int)$agent['id']) return ['ok' => false, 'error' => 'cannot message self'];
        if (!(int)$to['can_message']) return ['ok' => false, 'error' => 'target agent does not accept messages'];
        DB::run('INSERT INTO messages(from_agent,to_agent,body,created_at) VALUES(?,?,?,?)', [
            $agent['id'], $to['id'], Util::scrub(Util::str($args['body'] ?? '', 1500)), time(),
        ]);
        return ['ok' => true, 'message' => "message delivered to $slug"];
    }

    /** One agent turn. Returns the visible reply and a tool trace for the UI. */
    public static function chat(array $agent, array &$session, string $input): array {
        $messages = json_decode($session['messages'], true) ?: [];
        $messages[] = ['role' => 'user', 'content' => Util::scrub(Util::str($input, 6000))];
        $trace = [];
        $reply = '';

        for ($step = 0; $step < 7; $step++) {
            $out = self::callModel(
                array_merge([['role' => 'system', 'content' => self::systemPrompt($agent)]], $messages),
                $agent['model']
            );
            if (!$out['ok']) {
                $reply = 'Model error: ' . $out['error'];
                break;
            }
            $reply = $out['text'];

            $call = self::parseToolCall($reply);
            if (!$call) {
                $messages[] = ['role' => 'assistant', 'content' => $reply];
                break;
            }

            $name = Util::str($call['name'] ?? '', 80);
            $args = is_array($call['args'] ?? null) ? $call['args'] : [];
            $result = self::executeTool($agent, $name, $args);
            $trace[] = ['tool' => $name, 'ok' => !empty($result['ok']),
                        'result' => Util::scrub(substr(json_encode($result, JSON_UNESCAPED_SLASHES) ?: '', 0, 2000))];
            $messages[] = ['role' => 'assistant', 'content' => $reply];
            $messages[] = ['role' => 'user', 'content' => 'TOOL_RESULT: ' . json_encode($result, JSON_UNESCAPED_SLASHES)];
            $input = 'continue';
            $reply = '';
        }

        if ($reply === '') $reply = 'I finished the requested work. See the tool trace for details.';
        $messages[] = ['role' => 'assistant', 'content' => $reply];
        $messages = array_slice($messages, -120);

        DB::run('UPDATE agent_sessions SET messages=?, updated_at=? WHERE id=?',
            [json_encode($messages), time(), $session['id']]);

        // Mark this agent's inbox as read once it has had a turn.
        DB::run('UPDATE messages SET read_at=? WHERE to_agent=? AND read_at IS NULL', [time(), $agent['id']]);

        return ['reply' => Util::scrub($reply), 'trace' => $trace];
    }

    public static function newSession(array $agent, string $title = 'New session'): array {
        DB::run('INSERT INTO agent_sessions(agent_id,title,messages,created_at,updated_at) VALUES(?,?,?,?,?)',
            [$agent['id'], Util::str($title, 120), '[]', time(), time()]);
        $id = (int)DB::pdo()->lastInsertId();
        return DB::one('SELECT * FROM agent_sessions WHERE id=?', [$id]) ?: [];
    }
}
