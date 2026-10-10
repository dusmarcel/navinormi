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

    /** HTML of the navigation bar */
    public function renderNavigation(array $nav, string $position): string
    {
        $html = '<nav class="plugin_navinormi plugin_navinormi_' . $position . '"'
            . ' aria-label="' . hsc($this->getLang('aria')) . '">';

        // empty placeholders keep the TOC button centred if a neighbour is missing
        $html .= $nav['prev'] === null ? '<span></span>'
            : $this->button($nav['prev']['id'], 'prev', '‹ ' . $nav['prev']['label'],
                sprintf($this->getLang('prev'), $nav['prev']['title']));
        $html .= $this->button($nav['toc'], 'toc', $this->getLang('toc'), $this->getLang('toc'));
        $html .= $nav['next'] === null ? '<span></span>'
            : $this->button($nav['next']['id'], 'next', $nav['next']['label'] . ' ›',
                sprintf($this->getLang('next'), $nav['next']['title']));

        return $html . '</nav>';
    }

    private function button(string $id, string $type, string $text, string $title): string
    {
        $rel = $type === 'toc' ? '' : ' rel="' . $type . '"';
        return '<a href="' . wl($id) . '" class="navinormi_' . $type . '"' . $rel
            . ' title="' . hsc($title) . '">' . hsc($text) . '</a>';
    }
}
