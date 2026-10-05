<?php

namespace App\Actions;

use App\Search\UrlIndex;

/**
 * Index the URLs in the composer's current text.
 *
 * The strip above the chat asks this endpoint what is in the box, and renders one toggle per entry. Indexing
 * lives here (not in JS) so the chips and the send path use one implementation — a URL can never be counted
 * twice by one and missed by the other, and the default "on/off" decision is made in exactly one place.
 *
 * POST: text (string)
 * JSON: {entries:[{url,domain,fetchable,reason,key,default_on}], counts:{...}, summarize}
 */
class IndexUrlsAction extends BaseAction
{
    public function execute(): void
    {
        if (ob_get_length()) {
            ob_clean();
        }

        $text = (string) ($_POST['text'] ?? '');
        $index = UrlIndex::index($text);
        $defaults = UrlIndex::defaultSelection($index, $text);
        $on = array_flip($defaults['on']);

        $entries = [];
        foreach ($index['entries'] as $e) {
            $entries[] = [
                'url'        => $e['url'],
                'domain'     => $e['domain'],
                'key'        => $e['key'],
                'fetchable'  => $e['fetchable'],
                'reason'     => $e['reason'],
                'default_on' => isset($on[$e['key']]),
            ];
        }

        $this->jsonResponse([
            'entries'   => $entries,
            'counts'    => $index['counts'],
            'summarize' => $defaults['summarize'],
        ]);
    }
}
