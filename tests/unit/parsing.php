<?php
declare(strict_types=1);
require_once __DIR__ . '/../bootstrap.php';
lib('util.php');
lib('scheduler.php');

// 20 tool-call parser cases.
$valid = [
    'TOOL_CALL: {"name":"host_status","args":{}}',
    "TOOL_CALL: {\"name\":\"vault_use\",\"args\":{\"name\":\"API_KEY\",\"kind\":\"http\"}}",
    "Before text\nTOOL_CALL: {\"name\":\"noop\",\"args\":{}}\nAfter text",
    '```json' . "\n" . '{"tool":"cve_lookup","args":{"package":"openssl"}}' . "\n" . '```',
    '{"tool":"notes_read","args":{}}',
];
foreach ($valid as $i => $text) {
    test("parse.tool.valid." . ($i + 1), static function () use ($text, $i): void {
        $call = Util::parseToolCall($text);
        ok(is_array($call), "case $i did not parse");
        ok(isset($call['name']) && is_string($call['name']), "case $i missing name");
        ok(isset($call['args']) && is_array($call['args']), "case $i missing args");
    });
}
$invalid = [
    'plain answer with no tool call',
    'TOOL_CALL: not json',
    'TOOL_CALL: {"name":"host_status","args":"bad"}',
    '{"tool":"host_status"}',
    'TOOL_CALL: {"name":"","args":{}}',
    "text\nTOOL_CALL: {\"name\":\"host_status\",\"args\":{}}\nTOOL_CALL: {\"name\":\"host_apply\",\"args\":{}}",
    '{"tool":"unknown_tool","args":{}} extra trailing garbage',
    '',
    'TOOL_CALL: {"name":"host_status","args":[]}',
    "JSON\n" . str_repeat('x', 3000),
];
foreach ($invalid as $i => $text) {
    test("parse.tool.invalid." . ($i + 1), static function () use ($text, $i): void {
        $call = Util::parseToolCall($text);
        ok($call === null || !isset($call['name']), "case $i wrongly parsed " . json_encode($call));
    });
}

// 10 slug validation cases.
$slugs = ['system', 'web-ui', 'security_agent', 'a1', '-bad', 'Bad Slug', 'x' . str_repeat('y', 80), '', 'agent..name', 'chat'];
foreach ($slugs as $i => $slug) {
    test("parse.slug.$i", static function () use ($slug, $i): void {
        $valid = Util::validSlug($slug);
        $expected = (bool) preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $slug);
        eq($valid, $expected, "slug case $i");
    });
}

// 10 schedule parsing cases.
$schedules = [
    'daily 03:15' => true,
    'daily 04:20' => true,
    'daily 25:00' => false,
    'every 30m' => true,
    'every 2h' => false, // only minutes are supported by Scheduler::parse
    'every 0m' => false,
    'hourly' => false,
    '' => false,
    'daily 4:20' => false,
    'every 45m' => true,
];
$i = 0;
foreach ($schedules as $expr => $expected) {
    $i++;
    test("parse.schedule.$i", static function () use ($expr, $expected, $i): void {
        eq(Scheduler::parse($expr) !== null, $expected, "schedule case $i ($expr)");
    });
}
