<?php
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/registry.php';
require_once __DIR__ . '/hermes.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/util.php';

/** Unified search across pages, agents, sessions, findings, audit and Hermes data. */
final class Search {
    public static function index(): int {
        DB::run('DELETE FROM search_docs');
        $now = time();
        $insert = static function (string $kind, string $ref, string $title, string $body) use ($now): void {
            DB::run('INSERT OR REPLACE INTO search_docs(kind,ref_id,title,body,updated_at) VALUES(?,?,?,?,?)',
                [$kind, $ref, Util::scrub(mb_substr($title, 0, 200)),
                 Util::scrub(mb_substr($body, 0, 20000)), $now]);
        };

        foreach (PageRegistry::builtin() as $id => $page) {
            $insert('page', $id, $page['nav']['label'],
                $page['nav']['group'] . ' ' . $page['route'] . ' ' . implode(' ', $page['capabilities']));
        }
        foreach (DB::all('SELECT id,slug,name,role,description,system_prompt FROM agents') as $a) {
            $insert('agent', (string)$a['id'], $a['name'], $a['slug'] . ' ' . $a['role'] . ' ' . $a['description'] . ' ' . $a['system_prompt']);
        }
        foreach (DB::all('SELECT id,title,messages FROM agent_sessions ORDER BY updated_at DESC LIMIT 500') as $s) {
            $msgs = json_decode($s['messages'], true) ?: [];
            $text = '';
            foreach (array_slice($msgs, -20) as $m) {
                $text .= ' ' . (string)($m['content'] ?? '');
            }
            $insert('session', (string)$s['id'], $s['title'], $text);
        }
        foreach (DB::all('SELECT id,title,detail,severity FROM findings') as $f) {
            $insert('finding', (string)$f['id'], $f['title'], $f['severity'] . ' ' . $f['detail']);
        }
        foreach (DB::all('SELECT id,actor,action,detail FROM audit ORDER BY id DESC LIMIT 500') as $a) {
            $insert('audit', (string)$a['id'], $a['action'], $a['actor'] . ' ' . $a['detail']);
        }
        foreach (array_keys(HermesSources::catalog()) as $source) {
            $insert('hermes', $source, $source, 'Hermes data source: ' . $source);
        }
        return (int) DB::one('SELECT COUNT(*) AS n FROM search_docs')['n'];
    }

    public static function query(string $q, array $kinds = [], int $limit = 20): array {
        $q = trim(mb_substr(Util::scrub($q), 0, 120));
        if ($q === '') return ['ok' => true, 'results' => []];
        $like = '%' . $q . '%';
        $where = '(title LIKE ? OR body LIKE ?)';
        $args = [$like, $like];
        if ($kinds) {
            $kinds = array_values(array_intersect($kinds, ['page', 'agent', 'session', 'finding', 'audit', 'hermes']));
            if ($kinds) {
                $where .= ' AND kind IN (' . implode(',', array_fill(0, count($kinds), '?')) . ')';
                $args = array_merge($args, $kinds);
            }
        }
        $args[] = $like;
        $limit = max(1, min(50, $limit));
        $rows = DB::all("SELECT kind,ref_id,title,body FROM search_docs WHERE $where
                         ORDER BY CASE WHEN title LIKE ? THEN 0 ELSE 1 END, updated_at DESC LIMIT $limit", $args);
        $results = [];
        foreach ($rows as $r) {
            $results[] = [
                'kind' => $r['kind'], 'ref_id' => $r['ref_id'], 'title' => $r['title'],
                'snippet' => Util::scrub(mb_substr($r['body'], 0, 180)),
            ];
        }
        return ['ok' => true, 'query' => $q, 'results' => $results];
    }
}
