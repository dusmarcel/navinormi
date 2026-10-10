<?php

use dokuwiki\Extension\Plugin;

/**
 * DokuWiki Plugin navinormi (Helper Component)
 *
 * Determines the previous/next provision of a norm page from the table of
 * contents on the start page of its regulation. Regulation data (synonyms,
 * start pages) is taken from the normi plugin.
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author Marcel Keienborg <marcel@aufentha.lt>
 */
class helper_plugin_navinormi extends Plugin
{
    /**
     * Navigation data for a norm page, or null if the page is not a norm page
     * of a regulation known to normi.
     *
     * @param string $id page ID, e.g. "art._2_asylgesetz"
     * @return array|null ['toc' => page ID, 'prev' => entry|null, 'next' => entry|null],
     *                    entry = ['id' => page ID, 'label' => "§ 1 AsylG", 'title' => TOC line]
     */
    public function getNavigation(string $id): ?array
    {
        if (!preg_match('/^art\._([0-9]+[a-z]?)_(.+)$/', $id, $m)) {
            return null;
        }
        // plugin_load() returns null if normi is missing or disabled
        if (plugin_load('syntax', 'normi') === null) {
            return null;
        }
        $slug = $this->findSlug($m[2]);
        if ($slug === null) {
            return null;
        }

        $tocId = cleanID(syntax_plugin_normi::START_PAGES[$slug]);
        $entries = $this->parseToc(rawWiki($tocId), syntax_plugin_normi::REGULATIONS[$slug], $slug);
        [$prev, $next] = $this->neighbours($entries, $m[1]);

        return ['toc' => $tocId, 'prev' => $prev, 'next' => $next];
    }

    /**
     * Regulation a page belongs to: a norm page ("art._2_asylgesetz") or a start page ("asylgesetz")
     *
     * @return string|null regulation slug as used in normi
     */
    public function getRegulation(string $id): ?string
    {
        if (plugin_load('syntax', 'normi') === null) {
            return null;
        }
        if (preg_match('/^art\._[0-9]+[a-z]?_(.+)$/', $id, $m)) {
            return $this->findSlug($m[1]);
        }
        foreach (syntax_plugin_normi::START_PAGES as $slug => $page) {
            if (cleanID($page) === $id) {
                return $slug;
            }
        }
        return null;
    }

    /**
     * Index of all provisions of all regulations, for the quick jump dialog.
     *
     * Regulations are ordered national laws first (in normi's order), then the others.
     * The index is cached until a start page (or normi/navinormi) changes; provisions
     * of regulations the current user may not read are left out.
     *
     * @return array ['regs' => [['slug', 'syn' => synonyms]], 'entries' => [['r' => reg index,
     *               'a' => article, 'l' => "§ 5 AufenthG", 'h' => heading, 'u' => URL]]]
     */
    public function getIndex(): array
    {
        if (plugin_load('syntax', 'normi') === null) {
            return ['regs' => [], 'entries' => []];
        }

        $slugs = array_values(array_unique(array_merge(
            array_intersect(syntax_plugin_normi::NATIONAL_LAW_SLUGS, array_keys(syntax_plugin_normi::START_PAGES)),
            array_keys(syntax_plugin_normi::START_PAGES)
        )));

        $files = [__FILE__, DOKU_PLUGIN . 'normi/syntax.php'];
        foreach ($slugs as $slug) {
            $files[] = wikiFN(cleanID(syntax_plugin_normi::START_PAGES[$slug]));
        }
        $cache = new \dokuwiki\Cache\Cache('navinormi_index', '.json');
        // "age" also picks up start pages created after the cache was built
        if ($cache->useCache(['files' => $files, 'age' => 3600])) {
            $index = json_decode($cache->retrieveCache(), true);
        } else {
            $index = $this->buildIndex($slugs);
            $cache->storeCache(json_encode($index));
        }

        $readable = [];
        foreach ($index['regs'] as $r => $reg) {
            $readable[$r] = auth_quickaclcheck($reg['toc']) >= AUTH_READ;
        }
        $index['entries'] = array_values(array_filter($index['entries'], fn($e) => $readable[$e['r']]));
        return $index;
    }

    private function buildIndex(array $slugs): array
    {
        $index = ['regs' => [], 'entries' => []];
        foreach ($slugs as $slug) {
            $tocId = cleanID(syntax_plugin_normi::START_PAGES[$slug]);
            if (!page_exists($tocId)) {
                continue;
            }
            $r = count($index['regs']);
            $index['regs'][] = [
                'slug' => $slug,
                'toc' => $tocId,
                'syn' => array_values(array_filter(syntax_plugin_normi::REGULATIONS[$slug], fn($s) => $s !== '')),
            ];
            foreach ($this->parseToc(rawWiki($tocId), syntax_plugin_normi::REGULATIONS[$slug], $slug) as $entry) {
                $index['entries'][] = [
                    'r' => $r,
                    'a' => $entry['article'],
                    'l' => $entry['label'],
                    'h' => trim(substr($entry['title'], strlen($entry['label'])), " \t:"),
                    'u' => wl($entry['id']),
                ];
            }
        }
        return $index;
    }

    /** Labels for script.js, in the language set in the plugin config */
    public function getJsLabels(): array
    {
        $labels = [];
        foreach (['jump_aria', 'jump_placeholder', 'jump_loading', 'jump_empty', 'jump_hint'] as $key) {
            $labels[substr($key, 5)] = $this->label($key);
        }
        return $labels;
    }

    /** Regulation slug whose cleaned form matches the slug part of a page ID */
    private function findSlug(string $idSlug): ?string
    {
        foreach (array_keys(syntax_plugin_normi::START_PAGES) as $slug) {
            if (cleanID($slug) === $idSlug) {
                return $slug;
            }
        }
        return null;
    }

    /**
     * Extracts the provisions listed on a start page, in TOC order.
     *
     * Only list items starting with "§ N <synonym>" or "Art. N <synonym>" count;
     * annexes, recitals etc. are explicit [[links]] and are thereby ignored.
     *
     * @return array list of entries (see getNavigation())
     */
    public function parseToc(string $raw, array $synonyms, string $slug): array
    {
        usort($synonyms, fn($a, $b) => strlen($b) - strlen($a));
        $synonymPattern = implode('|', array_map(fn($s) => preg_quote($s, '/'), $synonyms));
        $pattern = '/^[ \t]+[*-][ \t]*((?:§|Art\.|Artikel)[ \t]+([0-9]+[a-z]?)[ \t]+(?:' . $synonymPattern . '))'
            . '(?![\p{L}\p{N}\/-])[^\n]*$/mu';

        preg_match_all($pattern, $raw, $matches, PREG_SET_ORDER);

        $entries = [];
        foreach ($matches as $match) {
            $article = strtolower($match[2]);
            if (isset($entries[$article])) {
                continue;
            }
            $entries[$article] = [
                'article' => $article,
                'id' => cleanID('art._' . $article . '_' . $slug),
                'label' => $match[1],
                'title' => trim(preg_replace('/^[ \t]+[*-][ \t]*/', '', $match[0])),
            ];
        }
        return array_values($entries);
    }

    /**
     * Previous and next entry of an article; wraps around at both ends.
     *
     * @return array [prev|null, next|null] — null if the article is not listed
     *               or is the only entry
     */
    public function neighbours(array $entries, string $article): array
    {
        $count = count($entries);
        $index = array_search(strtolower($article), array_column($entries, 'article'), true);
        if ($index === false || $count < 2) {
            return [null, null];
        }
        return [
            $entries[($index - 1 + $count) % $count],
            $entries[($index + 1) % $count],
        ];
    }

    /** @var array|null button labels in the configured language */
    private $labels = null;

    /** Button label in the language set in the plugin config (not the wiki's interface language) */
    private function label(string $key): string
    {
        if ($this->labels === null) {
            $language = in_array($this->getConf('lang'), ['de', 'en'], true) ? $this->getConf('lang') : 'de';
            $lang = [];
            include __DIR__ . '/lang/' . $language . '/lang.php';
            $this->labels = $lang;
        }
        return $this->labels[$key] ?? '';
    }

    /** HTML of the navigation bar */
    public function renderNavigation(array $nav, string $position): string
    {
        $html = '<nav class="plugin_navinormi plugin_navinormi_' . $position . '"'
            . ' aria-label="' . hsc($this->label('aria')) . '">';

        // empty placeholders keep the TOC button centred if a neighbour is missing
        $html .= $nav['prev'] === null ? '<span></span>'
            : $this->button($nav['prev']['id'], 'prev', '‹ ' . $nav['prev']['label'],
                sprintf($this->label('prev'), $nav['prev']['title']));
        $html .= $this->button($nav['toc'], 'toc', $this->label('toc'), $this->label('toc'));
        $html .= $nav['next'] === null ? '<span></span>'
            : $this->button($nav['next']['id'], 'next', $nav['next']['label'] . ' ›',
                sprintf($this->label('next'), $nav['next']['title']));

        return $html . '</nav>';
    }

    /** Keyboard shortcuts handled in script.js */
    const SHORTCUTS = ['prev' => '-', 'toc' => 'i', 'next' => '+'];

    private function button(string $id, string $type, string $text, string $title): string
    {
        $rel = $type === 'toc' ? '' : ' rel="' . $type . '"';
        $title .= ' ' . sprintf($this->label('key'), self::SHORTCUTS[$type]);
        return '<a href="' . wl($id) . '" class="navinormi_' . $type . '"' . $rel
            . ' title="' . hsc($title) . '">' . hsc($text) . '</a>';
    }
}
