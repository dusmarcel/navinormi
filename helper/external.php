<?php

use dokuwiki\Extension\Plugin;
use dokuwiki\HTTP\DokuHTTPClient;

/**
 * DokuWiki Plugin navinormi (Helper Component "external")
 *
 * Links a norm page or table of contents to the same provision on other websites
 * (gesetze-im-internet.de, dejure.org, buzer.de, geas-gesetz.de, geas-tool.de,
 * namigra.de/nano) and the start page of an EU act to EUR-Lex.
 *
 * A link is only shown if the target exists. For that, the plugin keeps an index of
 * each website (which provisions of which regulation it carries) in DokuWiki's meta
 * directory. The index is built in the background (one HTTP request per run of
 * DokuWiki's task runner, see refreshNext()) and renewed weekly; page views never wait
 * for another website. The identifiers of each regulation on the websites are taken
 * from the normi plugin (EXTERNAL_IDS).
 *
 * Index keys of a provision: "25a" (§/article), "toc" (table of contents), "recitals"
 * (first recital), "praeambel", "eingangsformel", "annex" / "annex-i" / "annex-d2a".
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author Marcel Keienborg
 */
class helper_plugin_navinormi_external extends Plugin
{
    /** an index is renewed after this many seconds … */
    const MAX_AGE = 7 * 86400;
    /** … a failed request is retried after this many */
    const RETRY = 86400;
    /** at least this many seconds between two requests to the same host (dejure.org answers 429 otherwise) */
    const HOST_DELAY = 60;

    const USER_AGENT = 'Mozilla/5.0 (compatible; DokuWiki navinormi plugin)';

    /**
     * Websites in display order. "source" is fetched to build the index; with "{id}" it is
     * fetched once per regulation, otherwise once for all regulations.
     */
    const SITES = [
        'gesetze-im-internet' => ['name' => 'gesetze-im-internet.de', 'source' => 'https://www.gesetze-im-internet.de/{id}/index.html'],
        'dejure'              => ['name' => 'dejure.org', 'source' => 'https://dejure.org/gesetze/{id}'],
        'buzer'               => ['name' => 'buzer.de', 'source' => 'https://www.buzer.de/{id}.htm'],
        'geas-gesetz'         => ['name' => 'GEAS-Gesetz.de', 'source' => 'https://geas-gesetz.de/sitemap.xml'],
        'geas-tool'           => ['name' => 'GEAS-Tool', 'source' => 'https://geas-tool.de/data.json'],
        'nano'                => ['name' => 'NaNo', 'source' => 'https://namigra.de/nano/'],
    ];

    /** official SPARQL endpoint of the EU Publications Office (EUR-Lex itself blocks automated requests) */
    const SPARQL = 'https://publications.europa.eu/webapi/rdf/sparql';

    /** @var array unit => state, per request */
    private $states = [];

    /* ================================================================ display */

    /**
     * Links to the given page on other websites.
     *
     * @param string $id page ID of a norm page, table of contents, recitals or annex
     * @return array|null null if the page is none of these; otherwise
     *                    ['label' => "§ 25 AufenthG", 'sites' => [link], 'eurlex' => [link]],
     *                    link = ['name' => text, 'url' => URL, 'title' => tooltip]
     */
    public function getLinks(string $id): ?array
    {
        $navinormi = plugin_load('helper', 'navinormi');
        $slug = $navinormi->getRegulation($id);
        if ($slug === null) {
            return null;
        }
        $page = $this->pageKey($id, $slug);
        if ($page === null) {
            return null;
        }
        [$key, $label] = $page;

        $result = ['label' => $label, 'sites' => [], 'eurlex' => []];
        foreach ($this->externalIds($slug) as $site => $lawId) {
            $law = $this->load($this->unit($site, $lawId))['laws'][$lawId] ?? null;
            $url = $law === null ? null : ($key === 'toc' ? $law['toc'] : ($law['keys'][$key] ?? null));
            if ($url === null) {
                continue;
            }
            $title = $this->label('ext_site_' . $site);
            if (!empty($law['draft'])) {
                $title .= ' – ' . $this->label('ext_draft');
            }
            if (!empty($law['stand'])) {
                $title .= ' – ' . sprintf($this->label('ext_stand'), $law['stand']);
            }
            if ($key === 'recitals') {
                $title .= ' – ' . $this->label('ext_recitals');
            }
            $result['sites'][] = ['name' => self::SITES[$site]['name'], 'url' => $url, 'title' => $title];
        }

        if ($key === 'toc') {
            $result['eurlex'] = $this->eurlexLinks($slug);
        }
        return $result;
    }

    /** HTML of the box below the bottom navigation bar, or '' if there are no links */
    public function render(array $links): string
    {
        if ($links['sites'] === [] && $links['eurlex'] === []) {
            return '';
        }
        global $conf;
        $target = $conf['target']['extern'] ?? '';
        $a = function (array $link) use ($target) {
            return '<a href="' . hsc($link['url']) . '" class="urlextern" rel="noopener"'
                . ($target !== '' ? ' target="' . hsc($target) . '"' : '')
                . ' title="' . hsc($link['title']) . '">' . hsc($link['name']) . '</a>';
        };

        $html = '<aside class="plugin_navinormi_external" aria-label="' . hsc($this->label('ext_aria')) . '">'
            . '<div class="navinormi_external_heading">' . hsc(sprintf($this->label('ext_heading'), $links['label'])) . '</div>';
        foreach (['sites', 'eurlex'] as $group) {
            if ($links[$group] !== []) {
                $html .= '<ul class="navinormi_external_' . $group . '"><li>'
                    . implode('</li><li>', array_map($a, $links[$group])) . '</li></ul>';
            }
        }
        return $html . '</aside>';
    }

    /**
     * Index key and heading label of a page: "toc" for the start page, the number for a
     * norm page, "recitals"/"praeambel"/"eingangsformel"/"annex[-x]" for other pages listed in the TOC
     *
     * @return array|null [key, label]
     */
    private function pageKey(string $id, string $slug): ?array
    {
        $tocId = cleanID(syntax_plugin_normi::START_PAGES[$slug]);
        if ($id === $tocId) {
            return ['toc', p_get_first_heading($tocId) ?: syntax_plugin_normi::REGULATIONS[$slug][0]];
        }
        $navinormi = plugin_load('helper', 'navinormi');
        $entries = $navinormi->parseToc(rawWiki($tocId), syntax_plugin_normi::REGULATIONS[$slug], $slug, $tocId);
        foreach ($entries as $entry) {
            if ($entry['id'] !== $id) {
                continue;
            }
            if ($entry['article'] !== null) {
                return [$entry['article'], $entry['label']];
            }
            $key = self::extraKey($entry['label']);
            return $key === null ? null : [$key, $entry['label']];
        }
        // a norm page missing from the TOC: "art._25a_aufenthaltsgesetz" → "§ 25a Aufenthaltsgesetz"
        if (preg_match('/^art\._([0-9]+[a-z]?)_/', $id, $m)) {
            $sign = in_array($slug, syntax_plugin_normi::NATIONAL_LAW_SLUGS, true) ? '§' : 'Art.';
            return [$m[1], $sign . ' ' . $m[1] . ' ' . syntax_plugin_normi::REGULATIONS[$slug][0]];
        }
        return null;
    }

    /**
     * Index key of recitals, Präambel, Eingangsformel or annex from its label in the TOC:
     * "Erwägungsgründe der …" → recitals, "ANHANG I der …" → annex-i, "Anlage der …" → annex
     */
    public static function extraKey(string $label): ?string
    {
        if (preg_match('/^Erwägungsgr/iu', $label)) {
            return 'recitals';
        }
        if (preg_match('/^Präambel/iu', $label)) {
            return 'praeambel';
        }
        if (preg_match('/^Eingangsformel/iu', $label)) {
            return 'eingangsformel';
        }
        // "ANHANG I", "Anlage D2a", "Anhang der …" (designation: capital or digit first)
        if (preg_match('/^(?i:Anhang|Anlage)(?:\s+([A-Z0-9][A-Za-z0-9]*))?(?=\s|$)/u', $label, $m)) {
            return self::annexKey($m[1] ?? '');
        }
        return null;
    }

    /** "annex" or "annex-<designation>" (lower case), null for designations such as "(2)" */
    public static function annexKey(string $designation): ?string
    {
        if ($designation === '') {
            return 'annex';
        }
        return preg_match('/^[A-Za-z0-9]+$/', $designation) ? 'annex-' . strtolower($designation) : null;
    }

    /** EUR-Lex links for the start page of an EU act: original version and, if amended, the latest consolidated one */
    private function eurlexLinks(string $slug): array
    {
        $act = $this->euActs()[$slug] ?? null;
        if ($act === null) {
            return [];
        }
        $links = [[
            'name' => $this->label('ext_eurlex_oj'),
            'url' => 'https://eur-lex.europa.eu/eli/' . $act['type'] . '/' . $act['year'] . '/' . $act['number'] . '/oj/deu',
            'title' => $this->label('ext_eurlex_oj_title'),
        ]];
        $consolidated = $this->load('eurlex')['acts'][$act['celex']] ?? null;
        if ($consolidated !== null) {
            $date = DateTime::createFromFormat('Ymd', substr($consolidated, -8));
            $links[] = [
                'name' => sprintf($this->label('ext_eurlex_cons'), $date ? $date->format('d.m.Y') : ''),
                'url' => 'https://eur-lex.europa.eu/legal-content/DE/TXT/?uri=CELEX:' . $consolidated,
                'title' => $this->label('ext_eurlex_cons_title'),
            ];
        }
        return $links;
    }

    /**
     * EU acts known to normi: slug => ['type' => reg|dir, 'year', 'number', 'celex' => "32024R1347"]
     */
    private function euActs(): array
    {
        $acts = [];
        foreach (syntax_plugin_normi::EU_NUMBERS as $number => $slug) {
            if (isset($acts[$slug]) || !isset(syntax_plugin_normi::START_PAGES[$slug])) {
                continue;
            }
            [$a, $b] = array_map('intval', explode('/', $number));
            // "2024/1347", "2013/33" (year first) or "604/2013" (old regulations: number first)
            [$year, $num] = ($a >= 1950 && $a <= 2100) ? [$a, $b] : [$b, $a];
            $type = strpos(syntax_plugin_normi::START_PAGES[$slug], 'richtlinie') === 0 ? 'dir' : 'reg';
            $acts[$slug] = [
                'type' => $type,
                'year' => $year,
                'number' => $num,
                'celex' => '3' . $year . ($type === 'dir' ? 'L' : 'R') . str_pad((string) $num, 4, '0', STR_PAD_LEFT),
            ];
        }
        return $acts;
    }

    /** Identifiers of a regulation on the websites (from normi), in display order */
    private function externalIds(string $slug): array
    {
        if (!defined('syntax_plugin_normi::EXTERNAL_IDS')) {
            return [];
        }
        $ids = array_intersect_key(syntax_plugin_normi::EXTERNAL_IDS[$slug] ?? [], self::SITES);
        return array_intersect_key(array_replace(self::SITES, $ids), $ids);
    }

    /* ================================================================ index */

    /**
     * All units of the index: "site" for websites indexed with one request,
     * "site:id" for those indexed per regulation, and "eurlex"
     *
     * @return array unit => [site, URL to fetch]
     */
    public function units(): array
    {
        if (plugin_load('syntax', 'normi') === null || !defined('syntax_plugin_normi::EXTERNAL_IDS')) {
            return [];
        }
        $units = [];
        foreach (syntax_plugin_normi::EXTERNAL_IDS as $ids) {
            foreach ($ids as $site => $lawId) {
                if (!isset(self::SITES[$site])) {
                    continue;
                }
                $source = self::SITES[$site]['source'];
                $units[$this->unit($site, $lawId)] = [$site, str_replace('{id}', rawurlencode($lawId), $source)];
            }
        }
        if ($this->euActs() !== []) {
            $units['eurlex'] = ['eurlex', self::SPARQL];
        }
        return $units;
    }

    private function unit(string $site, string $lawId): string
    {
        return strpos(self::SITES[$site]['source'], '{id}') === false ? $site : $site . ':' . $lawId;
    }

    /**
     * Fetches the unit that is due for longest (at most one HTTP request), as a task of
     * DokuWiki's task runner.
     *
     * @return bool true if a request was made
     */
    public function refreshNext(): bool
    {
        $now = time();
        $units = $this->units();
        $lastByHost = [];
        foreach ($units as $unit => [, $url]) {
            $host = parse_url($url, PHP_URL_HOST);
            $lastByHost[$host] = max($lastByHost[$host] ?? 0, $this->load($unit)['tried'] ?? 0);
        }

        $next = null;
        $nextTried = PHP_INT_MAX;
        foreach ($units as $unit => [, $url]) {
            $state = $this->load($unit);
            $fetched = $state['fetched'] ?? 0;
            $tried = $state['tried'] ?? 0;
            $due = $now - $fetched > self::MAX_AGE
                // after a failure, wait a day
                && ($tried === $fetched || $now - $tried > self::RETRY)
                && $now - $lastByHost[parse_url($url, PHP_URL_HOST)] > self::HOST_DELAY;
            if ($due && $tried < $nextTried) {
                $next = $unit;
                $nextTried = $tried;
            }
        }
        if ($next === null) {
            return false;
        }
        $this->refresh($next);
        return true;
    }

    /**
     * Fetches and indexes one unit now. On failure the previous index is kept.
     *
     * @return string|null error message, null on success
     */
    public function refresh(string $unit): ?string
    {
        $units = $this->units();
        if (!isset($units[$unit])) {
            return 'unknown unit';
        }
        [$site, $url] = $units[$unit];
        $state = $this->load($unit);
        // claim the unit before the (possibly slow) request, so a parallel run picks another one
        $state['tried'] = time();
        $this->save($unit, $state);

        @set_time_limit(300);
        $http = new DokuHTTPClient();
        $http->agent = self::USER_AGENT;
        $http->timeout = 60;
        $http->max_bodysize = 64 * 1024 * 1024;
        $http->keep_alive = false;
        if ($site === 'eurlex') {
            $http->headers['Accept'] = 'application/sparql-results+json';
            $url .= '?query=' . rawurlencode($this->sparqlQuery());
        }
        $body = $http->get($url);
        if ($body === false || (int) $http->status !== 200) {
            $state['error'] = 'HTTP ' . $http->status . ($http->error ? ': ' . $http->error : '');
            $this->save($unit, $state);
            return $state['error'];
        }

        $lawId = strpos($unit, ':') === false ? null : substr($unit, strpos($unit, ':') + 1);
        $parsed = $this->parse($site, (string) $body, $lawId);
        if ($parsed === null) {
            $state['error'] = 'unexpected content';
            $this->save($unit, $state);
            return $state['error'];
        }
        $this->save($unit, ['fetched' => $state['tried'], 'tried' => $state['tried']] + $parsed);
        return null;
    }

    /**
     * Index of one fetched source.
     *
     * @param string|null $lawId regulation of a per-regulation unit
     * @return array|null ['laws' => [id => ['toc' => URL, 'keys' => [key => URL], 'draft'?, 'stand'?]]]
     *                    or for EUR-Lex ['acts' => [CELEX => latest consolidated CELEX]];
     *                    null if the content is not what was expected
     */
    private function parse(string $site, string $body, ?string $lawId): ?array
    {
        switch ($site) {
            case 'gesetze-im-internet':
                $laws = [$lawId => self::parseGii($body, $lawId)];
                break;
            case 'dejure':
                $laws = [$lawId => self::parseDejure($body, $lawId)];
                break;
            case 'buzer':
                $laws = [$lawId => self::parseBuzer($body, $lawId)];
                break;
            case 'geas-gesetz':
                $laws = self::parseGeasGesetz($body);
                break;
            case 'geas-tool':
                $laws = self::parseGeasTool($body);
                break;
            case 'nano':
                $laws = self::parseNano($body);
                break;
            case 'eurlex':
                $acts = self::parseSparql($body);
                return $acts === null ? null : ['acts' => $acts];
            default:
                return null;
        }
        // a page without a single provision is not the law's TOC (e.g. dejure.org
        // redirects laws it does not carry to buzer.de): the law is not carried
        $laws = array_filter($laws, fn($law) => $law['keys'] !== []);
        if ($lawId === null && $laws === []) {
            return null;
        }
        foreach ($laws as &$law) {
            self::addSingleAnnex($law['keys']);
        }
        unset($law);

        // only the regulations this wiki has
        $wanted = [];
        foreach (syntax_plugin_normi::EXTERNAL_IDS as $ids) {
            if (isset($ids[$site])) {
                $wanted[$ids[$site]] = true;
            }
        }
        return ['laws' => array_intersect_key($laws, $wanted)];
    }

    /** "annex" for a law whose only annex has a designation ("Anlage 1" where the wiki says "Anlage") */
    private static function addSingleAnnex(array &$keys): void
    {
        $annexes = array_values(array_filter(array_keys($keys), fn($key) => strpos($key, 'annex-') === 0));
        if (!isset($keys['annex']) && count($annexes) === 1) {
            $keys['annex'] = $keys[$annexes[0]];
        }
    }

    /**
     * gesetze-im-internet.de: TOC with "__25a.html" (§), "art_16a.html" (Artikel),
     * "anlage_a.html", "anhang_ev.html", "pr_ambel.html", "eingangsformel.html"
     */
    public static function parseGii(string $html, string $lawId): array
    {
        $base = 'https://www.gesetze-im-internet.de/' . $lawId . '/';
        preg_match_all('/href="((?:__|art_)([0-9]+[a-z]*)|(?:anlage|anhang)(?:_([a-z0-9]+))?|pr_ambel|eingangsformel)\.html"/', $html, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
        $keys = [];
        foreach ($matches as $m) {
            if ($m[2] !== null) {
                $key = $m[2];
            } elseif ($m[1] === 'pr_ambel') {
                $key = 'praeambel';
            } elseif ($m[1] === 'eingangsformel') {
                $key = 'eingangsformel';
            } else {
                $key = self::annexKey($m[3] ?? '');
            }
            if ($key !== null) {
                $keys[$key] = $keys[$key] ?? $base . $m[1] . '.html';
            }
        }
        return ['toc' => $base . 'index.html', 'keys' => $keys];
    }

    /** dejure.org: TOC with "/gesetze/AufenthG/25a.html", "/gesetze/AufenthG/Anlage.html", "/gesetze/GG/Praeambel.html" */
    public static function parseDejure(string $html, string $lawId): array
    {
        $base = 'https://dejure.org/gesetze/' . $lawId;
        preg_match_all('~href="/gesetze/' . preg_quote($lawId, '~') . '/(([0-9]+[a-z]*)|Anlage(?:_([A-Za-z0-9]+))?|Praeambel)\.html"~', $html, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
        $keys = [];
        foreach ($matches as $m) {
            $key = $m[2] ?? ($m[1] === 'Praeambel' ? 'praeambel' : self::annexKey($m[3] ?? ''));
            if ($key !== null) {
                $keys[$key] = $keys[$key] ?? $base . '/' . $m[1] . '.html';
            }
        }
        return ['toc' => $base, 'keys' => $keys];
    }

    /** buzer.de: full text with TOC links "/25a_AufenthG.htm", "/Anlage_I_AsylG.htm" */
    public static function parseBuzer(string $html, string $lawId): array
    {
        preg_match_all('~href="(?:https://www\.buzer\.de)?/(([0-9]+[a-z]*)|Anlage(?:_([A-Za-z0-9]+))?)_' . preg_quote($lawId, '~') . '\.htm"~', $html, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
        $keys = [];
        foreach ($matches as $m) {
            $key = $m[2] ?? self::annexKey($m[3] ?? '');
            if ($key !== null) {
                $keys[$key] = $keys[$key] ?? 'https://www.buzer.de/' . $m[1] . '_' . $lawId . '.htm';
            }
        }
        return ['toc' => 'https://www.buzer.de/' . $lawId . '.htm', 'keys' => $keys];
    }

    /** geas-gesetz.de: sitemap with "art-avfvo-12", "art-avfvo-anhang-i", "eg-avfvo-1" */
    public static function parseGeasGesetz(string $xml): array
    {
        preg_match_all('~<loc>(https://geas-gesetz\.de/(art|eg)-([a-z0-9-]+?)-([0-9]+[a-z]*|(?:anhang|anlage)(?:-([a-z0-9]+))?))</loc>~', $xml, $matches, PREG_SET_ORDER | PREG_UNMATCHED_AS_NULL);
        $laws = [];
        $firstRecital = [];
        foreach ($matches as $m) {
            [, $url, $type, $lawId, $part] = $m;
            $laws[$lawId] = $laws[$lawId] ?? ['toc' => 'https://geas-gesetz.de/rechtsakte#' . $lawId, 'keys' => []];
            if ($type === 'eg') {
                if (ctype_digit($part) && (int) $part < ($firstRecital[$lawId] ?? PHP_INT_MAX)) {
                    $firstRecital[$lawId] = (int) $part;
                    $laws[$lawId]['keys']['recitals'] = $url;
                }
                continue;
            }
            $key = preg_match('/^[0-9]/', $part) ? $part : self::annexKey($m[5] ?? '');
            if ($key !== null) {
                $laws[$lawId]['keys'][$key] = $url;
            }
        }
        return $laws;
    }

    /**
     * geas-tool.de: data.json (26 MB, so not decoded as a whole) with rows
     * {"id":"AMMVO_12","parent":"AMMVO_ROOT",…}, "AMMVO_E1" (recital), "AMMVO_anh_I" (annex)
     */
    public static function parseGeasTool(string $json): array
    {
        $meta = [];
        $start = strpos($json, '"docsMeta":');
        $end = strpos($json, ',"rows":');
        if ($start !== false && $end !== false) {
            $meta = json_decode(substr($json, $start + 11, $end - $start - 11), true) ?: [];
        }

        $string = '"((?:[^"\\\\]|\\\\.)*)"';
        preg_match_all('/\{"id":' . $string . ',"parent":' . $string . '/', $json, $matches, PREG_SET_ORDER);
        $laws = [];
        foreach ($matches as $m) {
            $id = json_decode('"' . $m[1] . '"');
            $parent = json_decode('"' . $m[2] . '"');
            if (!is_string($id) || !is_string($parent) || substr($parent, -5) !== '_ROOT') {
                continue;
            }
            $code = substr($parent, 0, -5);
            $part = substr($id, strlen($code) + 1);
            if (preg_match('/^[0-9]+[a-z]*$/', $part)) {
                $key = $part;
            } elseif ($part === 'E1') {
                $key = 'recitals';
            } elseif (preg_match('/^anh_([A-Za-z0-9]+)$/', $part, $a)) {
                $key = self::annexKey($a[1]);
            } else {
                continue;
            }
            $laws[$code] = $laws[$code] ?? [
                'toc' => 'https://geas-tool.de/#' . rawurlencode($code),
                'keys' => [],
                'draft' => !empty($meta[$code]['entwurf']),
            ];
            if ($key !== null) {
                $laws[$code]['keys'][$key] = 'https://geas-tool.de/#' . rawurlencode($id);
            }
        }
        return $laws;
    }

    /**
     * namigra.de/nano: single page with all texts as JSON in <script id="data">;
     * a norm is addressed as "#aufenthg-25a", "#statusvo-eg1", "#aufenthv-anlagea", "#gg-präambel"
     */
    public static function parseNano(string $html): array
    {
        if (!preg_match('~<script id="data" type="application/json">(.*?)</script>~s', $html, $m)) {
            return [];
        }
        $data = json_decode($m[1], true);
        $laws = [];
        foreach ($data['laws'] ?? [] as $law) {
            if (!isset($law['id'], $law['items']) || !preg_match('/^[a-z0-9]+$/', $law['id'])) {
                continue;
            }
            $base = 'https://namigra.de/nano/#' . $law['id'];
            $keys = [];
            foreach ($law['items'] as $item) {
                if (($item['t'] ?? '') !== 'n' || !isset($item['num'])) {
                    continue;
                }
                // nano's keyOf(): "§ 25" → 25, "Art. 9" → 9, "EG 47" → eg47, "Anlage I" → anlagei
                $num = mb_strtolower(preg_replace('/\s+/u', '', $item['num']));
                if (preg_match('/^(?:§§?|art\.?)([0-9]+[a-z]*)$/u', $num, $n)) {
                    $keys[$n[1]] = $keys[$n[1]] ?? $base . '-' . $n[1];
                } elseif ($num === 'eg1') {
                    $keys['recitals'] = $base . '-eg1';
                } elseif ($num === 'präambel') {
                    $keys['praeambel'] = $base . '-' . rawurlencode($num);
                } elseif (preg_match('/^(?:anlage|anhang)([a-z0-9]*)$/', $num, $n)) {
                    $key = self::annexKey($n[1]);
                    $keys[$key] = $keys[$key] ?? $base . '-' . $num;
                }
            }
            $laws[$law['id']] = ['toc' => $base, 'keys' => $keys, 'stand' => $law['stand'] ?? null];
        }
        return $laws;
    }

    /** CELEX numbers of all consolidated versions of the EU acts known to normi */
    private function sparqlQuery(): string
    {
        $filters = [];
        foreach ($this->euActs() as $act) {
            $filters[] = 'STRSTARTS(STR(?celex), "0' . substr($act['celex'], 1) . '-")';
        }
        return 'PREFIX cdm: <http://publications.europa.eu/ontology/cdm#> '
            . 'SELECT DISTINCT ?celex WHERE { ?work cdm:resource_legal_id_celex ?celex . '
            . 'FILTER(' . implode(' || ', $filters) . ') }';
    }

    /**
     * Latest consolidated version per act ("32024R1348" => "02024R1348-20260227"), only for
     * acts that were amended: the first consolidated version is the original text.
     */
    public static function parseSparql(string $json): ?array
    {
        $data = json_decode($json, true);
        if (!isset($data['results']['bindings'])) {
            return null;
        }
        $versions = [];
        foreach ($data['results']['bindings'] as $binding) {
            $celex = $binding['celex']['value'] ?? '';
            if (preg_match('/^0([0-9]{4}[A-Z][0-9]{4})-([0-9]{8})$/', $celex, $m)) {
                $versions['3' . $m[1]][] = $celex;
            }
        }
        $acts = [];
        foreach ($versions as $act => $list) {
            if (count($list) > 1) {
                sort($list);
                $acts[$act] = end($list);
            }
        }
        return $acts;
    }

    /* ================================================================ storage */

    /** State of a unit: ['fetched' => time, 'tried' => time, 'error'?, 'laws' | 'acts'] */
    public function load(string $unit): array
    {
        if (!isset($this->states[$unit])) {
            $data = @json_decode((string) @file_get_contents($this->file($unit)), true);
            $this->states[$unit] = is_array($data) ? $data : [];
        }
        return $this->states[$unit];
    }

    private function save(string $unit, array $state): void
    {
        $this->states[$unit] = $state;
        io_saveFile($this->file($unit), json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /** data/meta/_navinormi/<unit>.json; units are ASCII except for the hash */
    private function file(string $unit): string
    {
        global $conf;
        return $conf['metadir'] . '/_navinormi/'
            . preg_replace('/[^A-Za-z0-9_-]/', '_', $unit) . '-' . substr(md5($unit), 0, 6) . '.json';
    }

    /** @var array|null labels in the language set in the plugin config */
    private $labels = null;

    /** Label in the language set in the plugin config (not the wiki's interface language) */
    private function label(string $key): string
    {
        if ($this->labels === null) {
            $language = in_array($this->getConf('lang'), ['de', 'en'], true) ? $this->getConf('lang') : 'de';
            $lang = [];
            include dirname(__DIR__) . '/lang/' . $language . '/lang.php';
            $this->labels = $lang;
        }
        return $this->labels[$key] ?? '';
    }
}
