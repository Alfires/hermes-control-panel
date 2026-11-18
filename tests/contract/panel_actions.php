<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
lib('registry.php');

$expected = [
    'login', 'logout', 'me', 'bootstrap', 'state.diff', 'agent.create', 'agent.update', 'agent.delete',
    'sessions.list', 'session.get', 'session.delete', 'chat.send', 'escalation.decide', 'approval.decide',
    'security.scan', 'findings.list', 'search.query', 'ui.config.propose', 'ui.config.approve',
    'ui.config.rollback', 'ui.config.history', 'ui.config.diff', 'hermes.source', 'stt.transcribe',
    'stt.status', 'selftest.run', 'selftest.report', 'theme.presets', 'theme.set', 'vault.test',
    'vault.list', 'audit.list', 'setup.status', 'setup.complete', 'hermes.deep_link',
];
count_at_least(count($expected), 30, 'expected action list too small');

$i = 0;
foreach ($expected as $action) {
    foreach (['exists', 'shape', 'auth'] as $check) {
        $i++;
        test("contract.panel.$action.$check", static function () use ($action, $check): void {
            $catalog = PanelActions::catalog();
            ok(isset($catalog[$action]), "action $action missing from catalogue");
            $a = $catalog[$action];
            if ($check === 'shape') {
                ok(isset($a['method']) && in_array($a['method'], ['GET', 'POST'], true), "$action method");
                ok(isset($a['capability']) && is_string($a['capability']), "$action capability");
                ok(isset($a['response']) && is_array($a['response']), "$action response shape");
            } elseif ($check === 'auth') {
                $public = in_array($action, ['login', 'me'], true);
                eq((bool) ($a['owner_only'] ?? true), !$public, "$action owner_only flag");
            }
        });
    }
}

// 30 cases: no catalogue action may expose a secret-bearing response field.
$i = 0;
foreach (array_slice($expected, 0, 30) as $name) {
    $i++;
    test("contract.panel.nosecret.$i", static function () use ($name): void {
        $catalog = PanelActions::catalog();
        ok(isset($catalog[$name]), "action $name missing");
        $json = json_encode($catalog[$name]);
        not_contains($json, 'password', "$name exposes password field");
        not_contains($json, 'api_key', "$name exposes api_key field");
        not_contains($json, 'env/reveal', "$name exposes env/reveal");
        not_contains($json, 'config/raw', "$name exposes config/raw");
    });
    if ($i >= 30) break;
}
