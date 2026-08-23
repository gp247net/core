<?php

namespace GP247\Core\Commands;

use GP247\Core\Library\LibraryClient;

/**
 * Browse/search the marketplace catalog for a group (soft-degrades to an empty
 * list when the API is unreachable).
 *
 * @aidlc-unit system-cli
 * @aidlc-story US-CLI-002
 * @aidlc-adr system-cli_service-extraction
 */
class ExtSearch extends ExtCommand
{
    /** @var string */
    protected $signature = 'gp247:ext-search
        {--type=plugin : plugin|template}
        {--keyword= : Search keyword}
        {--free : Only free extensions}
        {--page=1 : Result page}';

    /** @var string */
    protected $description = 'Search the GP247 marketplace for plugins/templates';

    /**
     * @return int
     */
    protected function handleGp247(): int
    {
        $type = $this->resolveType();
        if ($type === null) {
            return $this->failInvalidType();
        }

        $data = (new LibraryClient)->list($type, [
            'page[size]'   => 20,
            'page[number]' => (int) $this->option('page'),
            'keyword'      => (string) $this->option('keyword'),
            'is_free'      => $this->option('free') ? 1 : 0,
        ]);

        if (isset($data['error'])) {
            $this->addWarning('Marketplace unreachable: '.$data['error']);
        }

        $items = [];
        foreach (($data['data'] ?? []) as $item) {
            $items[] = [
                'key'     => $item['key'] ?? '',
                'name'    => $item['name'] ?? '',
                'version' => $item['version'] ?? '',
                'is_free' => (bool) ($item['is_free'] ?? 0),
                'price'   => $item['price'] ?? 0,
            ];
        }

        if (!$this->isJson()) {
            if ($items) {
                $this->table(
                    ['Key', 'Name', 'Version', 'Free', 'Price'],
                    array_map(fn ($i) => [$i['key'], $i['name'], $i['version'], $i['is_free'] ? 'yes' : 'no', $i['price']], $items)
                );
            } else {
                $this->info('No results.');
            }
        }

        return $this->respondSuccess(['type' => $type, 'count' => count($items), 'items' => $items]);
    }
}
