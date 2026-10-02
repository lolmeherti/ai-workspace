<?php

namespace App\Actions\Chat;

use App\Actions\BaseAction;
use App\Database;
use App\Repositories\ChatSessionRepository;
use App\ThoughtExtractor;

class ChatExportAction extends BaseAction
{
    /**
     * Context rows are filed under the three groups the panel offers. Anything that
     * returns file text — the file tool and a local index search — counts as Files;
     * everything else the session pulled in (calendar, memories, session evidence,
     * email) is personal data.
     */
    private const CONTEXT_GROUPS = [
        'file' => 'files',
        'search_files' => 'files',
        'search_local' => 'files',
        'search_web' => 'web',
    ];

    /** @var array<string, array<string, mixed>|null> */
    private array $attachmentCache = [];

    private const DEFAULT_GROUP = 'personal';

    private const GROUP_LABELS = [
        'web' => 'web search',
        'files' => 'files',
        'personal' => 'personal data',
    ];

    private const CALL_LABELS = [
        'firstpass' => 'first pass',
        'answer' => 'answer',
        'condenser' => 'condenser',
        'tools' => 'tools',
    ];

    public function __construct(
        private ChatSessionRepository $sessions,
        private ?Database $db = null,
        private ?string $uploadDir = null,
    ) {
        $this->uploadDir ??= __DIR__ . '/../../../uploads/';
    }

    public function execute(): void
    {
        $sessionId = (int) ($_GET['session_id'] ?? 0);
        if ($sessionId <= 0) {
            $this->jsonResponse(['status' => 'error', 'message' => 'No conversation selected.'], 400);
            return;
        }

        $session = $this->sessions->getById($sessionId);
        if ($session === null) {
            $this->jsonResponse(['status' => 'error', 'message' => 'Conversation not found.'], 404);
            return;
        }

        $options = $this->readOptions();
        $result = $this->collectRecords($this->loadHistory($sessionId), $options);
        $meta = $this->buildMeta($session, $result, $options);

        $format = ($_GET['format'] ?? 'txt') === 'json' ? 'json' : 'txt';
        $body = $format === 'json' ? $this->renderJson($meta, $result['records']) : $this->renderText($meta, $result['records']);

        if (($_GET['delivery'] ?? 'download') === 'inline') {
            $this->jsonResponse(['status' => 'success', 'text' => $body]);
            return;
        }

        $this->sendDownload($body, $format, (string) ($session['title'] ?? 'conversation'), $sessionId);
    }

    private function sendDownload(string $body, string $format, string $title, int $sessionId): void
    {
        $slug = strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', $title), '-'));
        if ($slug === '') {
            $slug = 'conversation';
        }
        $slug = substr($slug, 0, 60);
        $filename = "localsy-{$slug}-{$sessionId}-" . date('Ymd-His') . ".{$format}";

        header('Content-Type: ' . ($format === 'json' ? 'application/json' : 'text/plain') . '; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . strlen($body));
        header('X-Content-Type-Options: nosniff');
        echo $body;
        exit;
    }

    private function loadHistory(int $sessionId): array
    {
        $rows = $this->sessions->getHistory($sessionId);
        usort($rows, static fn(array $a, array $b): int => ((int) $a['id']) <=> ((int) $b['id']));
        return $rows;
    }

    /**
     * Every switch defaults to off: the panel always sends all flags explicitly, so
     * a bare request (no flags at all) exports the plain transcript and nothing else
     * — the reader has to opt into each extra block. Each context switch covers its
     * whole payload: a group is on or off, never "names but not contents".
     */
    private function readOptions(): array
    {
        return [
            'thoughts' => $this->flag('thoughts', false),
            'citations' => $this->flag('citations', false),
            'metrics' => $this->flag('metrics', false),
            'web' => $this->flag('web', false),
            'files' => $this->flag('files', false),
            'personal' => $this->flag('personal', false),
        ];
    }

    private function flag(string $key, bool $default): bool
    {
        if (!isset($_GET[$key])) {
            return $default;
        }
        return (string) $_GET[$key] === '1';
    }

    /**
     * @return array{records: array<int, array<string, mixed>>, counts: array<string, int>, omitted: array<string, int>, models: array<int, string>}
     */
    private function collectRecords(array $rows, array $options): array
    {
        $records = [];
        $counts = ['user' => 0, 'assistant' => 0, 'context' => 0, 'summary' => 0, 'note' => 0];
        $omitted = ['context' => 0, 'other' => 0];
        $models = [];
        $index = 0;

        foreach ($rows as $row) {
            $kind = $this->classify($row);

            if ($kind === null) {
                $omitted['other']++;
                continue;
            }

            if ($kind === 'context') {
                $group = $this->contextGroup((string) ($row['tool_name'] ?? ''));
                if (!$options[$group]) {
                    $omitted['context']++;
                    continue;
                }
            }

            $index++;
            $record = [
                'index' => $index,
                'id' => (int) $row['id'],
                'kind' => $kind,
                'at' => (string) ($row['created_at'] ?? ''),
            ];

            if ($kind === 'user') {
                $record['text'] = (string) $row['message'];
                if (!empty($row['image_path'])) {
                    $record['attachment'] = basename((string) $row['image_path']);
                }
            } elseif ($kind === 'assistant' || $kind === 'summary') {
                $extracted = ThoughtExtractor::extract((string) $row['message']);
                $record['text'] = $extracted['content'];
                if ($options['thoughts'] && $extracted['thought'] !== '') {
                    $record['reasoning'] = $extracted['thought'];
                }
                if (!empty($row['model'])) {
                    $record['model'] = (string) $row['model'];
                    $models[(string) $row['model']] = true;
                }
                if ($options['citations']) {
                    $sources = $this->decodeSources($row['source_map'] ?? null);
                    if ($sources !== []) {
                        $record['sources'] = $sources;
                    }
                }
                if ($options['metrics']) {
                    $metrics = $this->summarizeMetrics($row['perf_metrics'] ?? null);
                    if ($metrics !== null) {
                        $record['metrics'] = $metrics;
                    }
                }
            } elseif ($kind === 'context') {
                $record = array_merge($record, $this->contextRecord($row));
            } else {
                $record['text'] = (string) $row['message'];
            }

            $counts[$kind]++;
            $records[] = $record;
        }

        return [
            'records' => $records,
            'counts' => $counts,
            'omitted' => $omitted,
            'models' => array_keys($models),
        ];
    }

    private function classify(array $row): ?string
    {
        $role = (string) ($row['role'] ?? '');
        $type = (string) ($row['message_type'] ?? 'text');

        if ($role === 'user') {
            return 'user';
        }
        if ($role === 'assistant') {
            if ($type === 'condensation_summary') {
                return 'summary';
            }
            return $type === 'text' ? 'assistant' : null;
        }
        if ($role === 'system') {
            if ($type === 'data_fetching') {
                return 'context';
            }
            return $type === 'text' ? 'note' : null;
        }
        return null;
    }

    private function contextGroup(string $toolName): string
    {
        return self::CONTEXT_GROUPS[$toolName] ?? self::DEFAULT_GROUP;
    }

    /**
     * @return array<string, mixed>
     */
    private function contextRecord(array $row): array
    {
        $tool = (string) ($row['tool_name'] ?? '');
        $message = (string) $row['message'];
        $record = [
            'group' => $this->contextGroup($tool),
            'tool' => $tool,
            'query' => (string) ($row['search_query'] ?? ''),
            'chars' => mb_strlen($message),
        ];

        $sources = $this->decodeSources($row['source_map'] ?? null);

        if ($tool === 'file') {
            $record['label'] = (string) ($row['search_query'] ?? 'attachment');
            $attachment = $this->resolveAttachment($record['label']);
            if ($attachment !== null) {
                $record['attachment_type'] = $attachment['file_type'];
                $record['attachment_path'] = $attachment['path'];
                $record['attachment_present'] = $attachment['on_disk'];
                if ($attachment['degraded']) {
                    $record['degraded'] = true;
                }
            }
            if (isset($record['degraded'])) {
                return $record;
            }
            // The group switch is the whole payload: the stored text (document body or
            // image transcription) travels with the record whenever it exists at all.
            // A record with no text keeps its name and is flagged content-missing.
            if (trim($message) === '') {
                $record['content_missing'] = true;
            } else {
                $record['evidence'] = $message;
            }
            return $record;
        }

        $parsed = ContextDataViewAction::parseSources($message);
        if ($sources === [] && $parsed !== []) {
            foreach ($parsed as $p) {
                $sources[] = [
                    'id' => (string) ($p['id'] ?? ''),
                    'title' => (string) ($p['title'] ?? ''),
                    'domain' => (string) ($p['domain'] ?? ''),
                    'url' => '',
                ];
            }
        }
        if ($sources !== []) {
            $record['sources'] = $sources;
        }

        $evidence = $parsed !== [] ? $this->evidenceFromParsed($parsed) : trim($message);
        if ($evidence === [] || $evidence === '') {
            $record['content_missing'] = true;
        } else {
            $record['evidence'] = $evidence;
        }

        return $record;
    }

    /**
     * Resolves the uploaded_files row behind a file/image context record so the
     * export can name the attachment type, spot a failed transcription, and point
     * at the stored file when its content is not in the export.
     *
     * @return array{file_type: string, degraded: bool, path: string, on_disk: bool}|null
     */
    private function resolveAttachment(string $label): ?array
    {
        if ($this->db === null || $label === '') {
            return null;
        }
        if (array_key_exists($label, $this->attachmentCache)) {
            return $this->attachmentCache[$label];
        }

        $rows = $this->db->query(
            'SELECT physical_name, original_name, file_type, searchable_text FROM uploaded_files
             WHERE original_name = ? OR physical_name = ? ORDER BY id DESC LIMIT 5',
            [$label, $label]
        );

        $resolved = null;
        foreach ($rows as $row) {
            $name = (string) ($row['physical_name'] ?? '');
            $original = trim((string) ($row['original_name'] ?? ''));
            $text = trim((string) ($row['searchable_text'] ?? ''));
            $type = (string) ($row['file_type'] ?? '');
            $onDisk = $name !== '' && is_file(rtrim($this->uploadDir, '/\\') . '/' . $name);

            $candidate = [
                'file_type' => $type !== '' ? $type : 'file',
                'degraded' => $text === '' || $text === $original,
                'path' => $name === '' ? '' : 'uploads/' . $name,
                'on_disk' => $onDisk,
            ];

            $resolved = $candidate;
            if ($onDisk) {
                break;
            }
        }

        $this->attachmentCache[$label] = $resolved;
        return $resolved;
    }

    /**
     * @return array<int, array{id: string, text: string}>
     */
    private function evidenceFromParsed(array $parsed): array
    {
        $out = [];
        foreach ($parsed as $source) {
            foreach ($source['chunks'] as $chunk) {
                $out[] = ['id' => (string) $source['id'], 'text' => (string) $chunk];
            }
        }
        return $out;
    }

    /**
     * @return array<int, array{id: string, title: string, domain: string, url: string}>
     */
    private function decodeSources(mixed $raw): array
    {
        if (empty($raw)) {
            return [];
        }
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded)) {
            return [];
        }

        $sources = [];
        foreach ($decoded as $id => $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $sources[] = [
                'id' => (string) (is_string($id) ? $id : ($entry['id'] ?? '')),
                'title' => (string) ($entry['title'] ?? ''),
                'domain' => (string) ($entry['domain'] ?? ''),
                'url' => (string) ($entry['url'] ?? ''),
            ];
        }
        return $sources;
    }

    /**
     * @return array{total_ms: int, ttft_ms: int|null, calls: array<int, array{purpose: string, elapsed_ms: int, prompt_ms: int, prompt_n: int, cache_n: int, content_tok: int, reasoning_tok: int, tps: int}>, cache_percent: int}|null
     */
    private function summarizeMetrics(mixed $raw): ?array
    {
        if (empty($raw)) {
            return null;
        }
        $decoded = json_decode((string) $raw, true);
        if (!is_array($decoded) || empty($decoded['calls'])) {
            return null;
        }

        $calls = [];
        $primary = null;
        foreach ($decoded['calls'] as $call) {
            if (!is_array($call)) {
                continue;
            }
            $entry = [
                'purpose' => (string) ($call['purpose'] ?? '?'),
                'elapsed_ms' => (int) round((float) ($call['elapsed_ms'] ?? 0)),
                'prompt_ms' => (int) round((float) ($call['prompt_ms'] ?? 0)),
                'prompt_n' => (int) ($call['prompt_n'] ?? 0),
                'cache_n' => (int) ($call['cache_n'] ?? 0),
                'content_tok' => (int) ($call['content_tok'] ?? 0),
                'reasoning_tok' => (int) ($call['reasoning_tok'] ?? 0),
                'tps' => (int) round((float) ($call['pred_tps'] ?? 0)),
            ];
            if ($entry['tps'] === 0 && !empty($call['content_ms']) && $entry['content_tok'] > 0) {
                $entry['tps'] = (int) round($entry['content_tok'] / ((float) $call['content_ms'] / 1000));
            }
            $calls[] = $entry;
            if ($entry['purpose'] === 'answer') {
                $primary = $entry;
            }
        }
        if ($calls === []) {
            return null;
        }
        if ($primary === null) {
            $primary = $calls[count($calls) - 1];
        }

        return [
            'total_ms' => (int) round((float) ($decoded['total_ms'] ?? 0)),
            'ttft_ms' => isset($decoded['ttft_ms']) ? (int) round((float) $decoded['ttft_ms']) : null,
            'calls' => $calls,
            'cache_percent' => $primary['prompt_n'] > 0 ? (int) round($primary['cache_n'] / $primary['prompt_n'] * 100) : 0,
        ];
    }

    private function buildMeta(array $session, array $result, array $options): array
    {
        $contextGroups = [];
        foreach (self::GROUP_LABELS as $key => $label) {
            if ($options[$key]) {
                $contextGroups[$key] = $label;
            }
        }

        $included = [];
        if ($options['thoughts']) {
            $included[] = 'reasoning';
        }
        if ($options['citations']) {
            $included[] = 'citations';
        }
        if ($contextGroups !== []) {
            $included[] = implode(', ', array_values($contextGroups));
        }
        if ($options['metrics']) {
            $included[] = 'metrics';
        }

        $excluded = [];
        if (!$options['thoughts']) {
            $excluded[] = 'reasoning';
        }
        if (!$options['citations']) {
            $excluded[] = 'citations';
        }
        foreach (['web' => 'web search', 'files' => 'files', 'personal' => 'personal data'] as $key => $label) {
            if (!$options[$key]) {
                $excluded[] = $label;
            }
        }
        if (!$options['metrics']) {
            $excluded[] = 'metrics';
        }
        if ($result['omitted']['context'] > 0) {
            $excluded[] = $result['omitted']['context'] . ' context record(s) filtered out';
        }
        if ($result['omitted']['other'] > 0) {
            $excluded[] = $result['omitted']['other'] . ' internal record(s)';
        }

        return [
            'title' => (string) ($session['title'] ?? ''),
            'session_id' => (int) ($session['id'] ?? 0),
            'session_created_at' => (string) ($session['created_at'] ?? ''),
            'exported_at' => date('Y-m-d H:i:s'),
            'models' => $result['models'],
            'counts' => $result['counts'],
            'included' => $included,
            'excluded' => $excluded,
            'total_records' => count($result['records']),
        ];
    }

    private function renderJson(array $meta, array $records): string
    {
        $payload = [
            'format' => 'localsy-conversation',
            'version' => 1,
            'title' => $meta['title'],
            'session_id' => $meta['session_id'],
            'session_created_at' => $meta['session_created_at'],
            'exported_at' => $meta['exported_at'],
            'models' => $meta['models'],
            'counts' => $meta['counts'],
            'included' => $meta['included'],
            'excluded' => $meta['excluded'],
            'records' => array_map(fn(array $r) => $this->jsonRecord($r), $records),
        ];

        return (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonRecord(array $record): array
    {
        $kind = $record['kind'];
        $out = [
            'index' => $record['index'],
            'role' => match ($kind) {
                'user' => 'user',
                'context' => 'context',
                'note' => 'system',
                default => 'assistant',
            },
            'kind' => $kind,
            'at' => $record['at'],
        ];

        if ($kind === 'user') {
            $out['text'] = $record['text'];
            if (isset($record['attachment'])) {
                $out['attachment'] = $record['attachment'];
            }
            return $out;
        }

        if ($kind === 'context') {
            $out['group'] = $record['group'];
            $out['tool'] = $record['tool'];
            $out['query'] = $record['query'];
            if (isset($record['label'])) {
                $out['label'] = $record['label'];
            }
            if (isset($record['degraded'])) {
                $out['degraded'] = true;
            }
            if (isset($record['attachment_type'])) {
                $out['attachment'] = [
                    'type' => $record['attachment_type'],
                    'path' => $record['attachment_path'] ?? '',
                    'on_disk' => $record['attachment_present'] ?? false,
                ];
            }
            if (isset($record['sources'])) {
                $out['sources'] = $record['sources'];
            }
            $out['evidence'] = $record['evidence'] ?? null;
            $out['chars'] = $record['chars'];
            if (isset($record['content_missing'])) {
                $out['content_missing'] = true;
            }
            return $out;
        }

        $out['text'] = $record['text'];
        $out['reasoning'] = $record['reasoning'] ?? null;
        if (isset($record['model'])) {
            $out['model'] = $record['model'];
        }
        if (isset($record['sources'])) {
            $out['sources'] = $record['sources'];
        }
        if (isset($record['metrics'])) {
            $out['metrics'] = $record['metrics'];
        }
        return $out;
    }

    private function renderText(array $meta, array $records): string
    {
        $body = [];
        foreach ($records as $record) {
            $body[] = $this->textRecord($record);
        }
        $bodyText = implode("\n", $body);

        $header = [];
        $header[] = 'Localsy conversation export';
        $header[] = $meta['title']
            . ($meta['models'] !== [] ? ' · ' . implode(', ', $meta['models']) : '')
            . ' · ' . substr($meta['session_created_at'], 0, 10);
        $header[] = $meta['total_records'] . ' records: ' . $meta['counts']['user'] . ' user · '
            . $meta['counts']['assistant'] . ' assistant'
            . ($meta['counts']['context'] > 0 ? ' · ' . $meta['counts']['context'] . ' context' : '')
            . ($meta['counts']['summary'] > 0 ? ' · ' . $meta['counts']['summary'] . ' summary' : '');
        if ($meta['included'] !== []) {
            $header[] = 'Included: ' . implode(', ', $meta['included']);
        }
        if ($meta['excluded'] !== []) {
            $header[] = 'Omitted: ' . implode(', ', $meta['excluded']);
        }

        $headText = implode("\n", $header);
        $estimate = $this->estimateTokens($headText . $bodyText);
        $headText .= "\n≈{$estimate} tokens";

        return $headText . "\n\n" . $bodyText;
    }

    private function estimateTokens(string $text): string
    {
        $tokens = (int) round(mb_strlen($text) / 4);
        if ($tokens < 1000) {
            return (string) $tokens;
        }
        return number_format($tokens / 1000, 1) . 'k';
    }

    private function textRecord(array $record): string
    {
        $lines = [];
        $lines[] = $this->textHeading($record);

        if ($record['kind'] === 'context') {
            $lines = array_merge($lines, $this->textContext($record));
        } else {
            if (isset($record['reasoning'])) {
                $lines[] = '[reasoning] ' . $this->inline($record['reasoning']);
            }
            $lines[] = $record['text'];
            if (isset($record['attachment'])) {
                $lines[] = '[image] ' . $record['attachment'];
            }
            if (isset($record['sources'])) {
                foreach ($record['sources'] as $source) {
                    $lines[] = $this->sourceLine($source);
                }
            }
            if (isset($record['metrics'])) {
                $lines[] = '[metrics] ' . $this->metricLine($record['metrics']);
            }
        }

        return implode("\n", $lines) . "\n";
    }

    private function inline(string $text): string
    {
        return (string) preg_replace('/\n{2,}/', "\n", trim($text));
    }

    private function textHeading(array $record): string
    {
        switch ($record['kind']) {
            case 'user':
                return 'USER';
            case 'assistant':
                return 'AI' . (isset($record['model']) ? ' [' . $record['model'] . ']' : '');
            case 'summary':
                return 'SUMMARY [earlier turns condensed — originals are gone from the session]';
            case 'note':
                return 'SYSTEM NOTE';
            default:
                $label = $record['tool'] !== '' ? $record['tool'] : (self::GROUP_LABELS[$record['group']] ?? $record['group']);
                return 'CONTEXT [' . $label . ']';
        }
    }

    /**
     * @return array<int, string>
     */
    private function textContext(array $record): array
    {
        $lines = [];

        if (isset($record['label'])) {
            $type = (string) ($record['attachment_type'] ?? 'file');
            $lines[] = '[' . $type . '] ' . $record['label'] . ' (' . number_format($record['chars']) . ' chars)';

            if (isset($record['degraded'])) {
                $lines[] = 'no text extracted from this ' . $type . ' — transcription failed, the content is NOT in this export';
                return $lines;
            }

            if (isset($record['evidence'])) {
                $lines[] = (string) $record['evidence'];
                return $lines;
            }

            $note = 'content not in this export';
            if (isset($record['attachment_type']) && ($record['attachment_present'] ?? false) === false) {
                $note .= ' — file no longer on disk';
            }
            $lines[] = $note;
            return $lines;
        }

        if ($record['query'] !== '') {
            $lines[] = 'query: ' . $record['query'];
        }

        if (isset($record['sources'])) {
            foreach ($record['sources'] as $source) {
                $lines[] = $this->sourceLine($source);
            }
        }

        if (isset($record['evidence'])) {
            $chunks = $record['evidence'];
            if (is_array($chunks)) {
                foreach ($chunks as $chunk) {
                    $lines[] = '[' . $chunk['id'] . '] ' . $this->inline((string) $chunk['text']);
                }
            } else {
                $lines[] = $this->inline((string) $chunks);
            }
        } else {
            $lines[] = 'no page text stored for this fetch';
        }

        return $lines;
    }

    private function sourceLine(array $source): string
    {
        $title = $source['title'] !== '' ? $source['title'] : ($source['domain'] !== '' ? $source['domain'] : $source['url']);
        $parts = [$title];
        if ($source['url'] !== '') {
            $parts[] = $source['url'];
        }
        return '[' . $source['id'] . '] ' . implode(' — ', $parts);
    }

    private function metricLine(array $metrics): string
    {
        $parts = [number_format($metrics['total_ms'] / 1000, 1) . 's'];
        if (!empty($metrics['ttft_ms'])) {
            $parts[] = 'TTFT ' . number_format($metrics['ttft_ms'] / 1000, 1) . 's';
        }
        $chain = array_map(fn(array $c) => self::CALL_LABELS[$c['purpose']] ?? $c['purpose'], $metrics['calls']);
        $parts[] = count($metrics['calls']) . ' call' . (count($metrics['calls']) === 1 ? '' : 's') . ' (' . implode(' → ', $chain) . ')';
        $primary = $metrics['calls'][count($metrics['calls']) - 1];
        foreach ($metrics['calls'] as $call) {
            if ($call['purpose'] === 'answer') {
                $primary = $call;
                break;
            }
        }
        if ($primary['tps'] > 0) {
            $parts[] = $primary['tps'] . ' tok/s';
        }
        if ($metrics['cache_percent'] > 0) {
            $parts[] = $metrics['cache_percent'] . '% cached';
        }
        return implode(' · ', $parts);
    }
}
