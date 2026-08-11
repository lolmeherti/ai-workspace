<?php
require_once __DIR__ . '/../../vendor/autoload.php';
\App\Config::load(__DIR__ . '/../..');

use App\Search\SourceCondenser;
use App\AgentManager;

$agent = new AgentManager();
$condenser = new SourceCondenser($agent);

// Simulate the exact chunks selected for news-event
// Manually construct the 9 non-oversized chunks (skip S1-C50)
$chunks = [];
// S3: tufin.com
$chunks[] = new \App\Search\WebChunk('S1','S1-C1','https://en.wikipedia.org/wiki/2024_CrowdStrike-related_IT_outages','','2024 CrowdStrike-related IT outages','en.wikipedia.org',null,null,date('c'),[],'paragraph','On 19 July 2024, an update to the CrowdStrike Falcon sensor caused widespread outages of Microsoft Windows computers. Approximately 8.5 million systems crashed worldwide.',1);
$chunks[] = new \App\Search\WebChunk('S1','S1-C3','','','2024 CrowdStrike-related IT outages','en.wikipedia.org',null,null,date('c'),['Outage'],'paragraph','Affected sectors included airlines, banking, hospitals, emergency services, and broadcasters. The financial impact was estimated at over $5 billion.',3);
$chunks[] = new \App\Search\WebChunk('S2','S2-C3','','','Widespread IT Outage','cisa.gov',null,null,date('c'),[],'paragraph','On July 19 2024, a defect in a CrowdStrike content update for Windows hosts caused widespread outages. CISA observed threat actors exploiting the incident for phishing.',3);

$ledger = $condenser->condense($chunks, 'CrowdStrike Microsoft outage July 19 2024');
echo "Ledger entries: " . count($ledger) . "\n";
foreach ($ledger as $entry) {
    echo "Source {$entry['sourceId']}: " . count($entry['items']) . " claims\n";
    foreach ($entry['items'] as $item) {
        echo "  [" . implode(',', $item['chunkIds']) . "] {$item['claim']}\n";
    }
}
