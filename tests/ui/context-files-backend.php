<?php
// Regression coverage for retained evidence edits and filtering before pagination.
use App\Actions\Chat\ContextDataAtomizeAction;
use App\Actions\Chat\ContextDataViewAction;
use App\Actions\File\FileSearchAction;
use App\Search\EvidenceBuilder;
use App\Search\WebChunk;

$check = static function (bool $value, string $message): void {
    if (!$value) throw new RuntimeException($message);
};
$chunk = WebChunk::fromArray(['sourceId' => 'S1', 'chunkId' => 'S1-C1', 'title' => 'Source', 'text' => 'Old evidence']);
$row = ['id' => 303, 'message' => (new EvidenceBuilder())->build([$chunk]), 'selected_chunks' => json_encode([$chunk]), 'backing_chunks' => json_encode([$chunk]), 'raw_evicted' => 1];
$edited = ContextDataAtomizeAction::editedEvidence($row, [['id' => 'S1', 'text' => "New evidence\n<script>literal</script>"]]);
$check(ContextDataViewAction::parseSources($edited['message'])[0]['chunks'][0] === "\nNew evidence\n<script>literal</script>\n", 'Edits round trip as literal text');
$check($edited['selected_chunks'] === $edited['backing_chunks'], 'Fallback snapshot must use edits');
$check(!str_contains($edited['selected_chunks'], 'Old evidence'), 'Deleted evidence cannot return during extraction');
$check($edited['atomic_context'] === null && $edited['atomic_tokens'] === null, 'Stale facts are cleared');
$check(!array_key_exists('raw_evicted', $edited), 'Saving preserves inclusion choice');
$empty = ContextDataAtomizeAction::editedEvidence($row, [['id' => 'S1', 'text' => '']]);
$check($empty['message'] === '' && $empty['backing_chunks'] === '[]', 'Clear all evidence without fallback revival');
$plain = ContextDataAtomizeAction::editedEvidence(['message' => 'Plain evidence'], [['id' => 'manual', 'text' => 'Pasted additions']]);
$check($plain['message'] === 'Pasted additions' && json_decode($plain['selected_chunks'], true)[0]['text'] === 'Pasted additions', 'Plain evidence remains editable and extractable');
try {
    ContextDataAtomizeAction::editedEvidence($row, [['id' => 'S9', 'text' => 'Unknown source']]);
    throw new RuntimeException('Unknown source accepted');
} catch (InvalidArgumentException $expected) {}
$db = new class($row) {
    public array $saved = [];
    public function __construct(public array $row) {}
    public function query($sql, $params = []) { return [$this->row]; }
    public function update($table, $data, $conditions) { $this->saved = $data; return true; }
};
$agent = (new ReflectionClass(App\AgentManager::class))->newInstanceWithoutConstructor();
$action = new class($db, $agent) extends ContextDataAtomizeAction {
    public array $response;
    public int $code;
    protected function jsonResponse(array $data, int $statusCode = 200): void { $this->response = $data; $this->code = $statusCode; }
};
$_POST = ['id' => 303, 'op' => 'edit_raw', 'base_message' => 'stale', 'evidence' => json_encode([['id' => 'S1', 'text' => 'New evidence']])];
$action->execute();
$check($action->code === 409 && $db->saved === [], 'Reject stale editor without losing edits');
$_POST['base_message'] = $row['message'];
$action->execute();
$check($action->code === 200 && str_contains($db->saved['message'], 'New evidence'), 'Save endpoint persists edited evidence');

$db = new class {
    public function query($sql, $params = []) {
        $files = [];
        for ($i = 1; $i <= 12; $i++) $files[] = ['id' => $i, 'original_name' => "image$i.png", 'physical_name' => "fixture$i", 'file_type' => 'image/png'];
        $files[] = ['id' => 13, 'original_name' => 'report.pdf', 'physical_name' => 'fixture-report', 'file_type' => 'application/pdf'];
        return $files;
    }
};
$action = new class($db) extends FileSearchAction {
    public array $response;
    protected function jsonResponse(array $data, int $statusCode = 200): void { $this->response = $data; }
};
$_GET = ['source' => 'gallery', 'category' => 'docs', 'page' => 1, 'limit' => 12];
$action->execute();
$check(array_column($action->response['files'], 'id') === [13], 'Document from original page two appears on filtered page one');
$check($action->response['pagination']['total'] === 1 && $action->response['pagination']['pages'] === 1, 'Counts reflect filtered matches');
$_GET['page'] = 9;
$action->execute();
$check($action->response['pagination']['page'] === 1 && count($action->response['files']) === 1, 'Out of range page is clamped');
$_GET['category'] = 'images';
$action->execute();
$check(count($action->response['files']) === 12 && $action->response['pagination']['total'] === 12, 'Images are filtered before pagination');
$_GET['category'] = 'all'; $_GET['page'] = 2;
$action->execute();
$check(array_column($action->response['files'], 'id') === [13] && $action->response['pagination']['total'] === 13, 'Unfiltered pagination still works');
echo "Context evidence and file pagination regressions passed.\n";
