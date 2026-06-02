<?php
declare(strict_types=1);

namespace Survos\WikiBundle\Service;

use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Survos\WikiBundle\Dto\Image;
use Survos\WikiBundle\Dto\SearchResult;
use Survos\WikiBundle\Entity\WikiClaim;
use Survos\WikiBundle\Entity\WikiData;
use Survos\WikiBundle\Entity\WikiProperty;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Symfony-native Wikidata client backed by a DB cache.
 *
 * - search() / searchBy() -> SearchResult[]  (HTTP-cached in the pool)
 * - get()                 -> WikiData entity (persisted; claims normalized into WikiClaim rows)
 * - getImages()           -> Image[]         (commonsMedia claims as rich DTOs)
 *
 * get() fetches the configured property set by default; pass $props to override.
 */
final class WikidataService
{
    private const WD_API    = 'https://www.wikidata.org/w/api.php';
    private const WD_SPARQL = 'https://query.wikidata.org/sparql';

    public function __construct(
        private CacheInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly HttpClientInterface $http,
        private readonly EntityManagerInterface $em,
        private readonly int $searchLimit = 10,
        private int $cacheTtl = 3600, // default 1h
        /** @var array<string,string> alias => P-code, from config */
        private readonly array $properties = [],
        private readonly string $userAgent = 'SurvosWikiBundle/2025 (+https://survos.com)',
    ) {}

    public function withCacheTtl(int $seconds): self
    {
        $this->cacheTtl = $seconds;
        return $this;
    }

    /**
     * @return SearchResult[]
     */
    public function search(string $query, string $lang = 'en', ?int $limit = null): array
    {
        $limit ??= $this->searchLimit;
        $key = sprintf('wd.search.%s.%s.%d', md5($query), $lang, $limit);

        return $this->cache->get($key, function (ItemInterface $item) use ($query, $lang, $limit): array {
            $item->expiresAfter($this->cacheTtl);

            // 1) wbsearchentities (lightweight label search)
            $hits = $this->wbSearchEntities($query, $lang, $limit);

            // 2) Enrich via wbgetentities (labels/descriptions/aliases/sitelinks)
            $ids = [];
            foreach ($hits as $h) {
                if (isset($h['id']) && \is_string($h['id'])) {
                    $ids[] = $h['id'];
                }
            }
            if ($ids === []) {
                return [];
            }

            $entities = $this->wbGetEntities($ids, $lang, includeSitelinks: true);

            $out = [];
            foreach ($entities as $e) {
                $out[] = SearchResult::fromArray($e, $lang);
            }
            return $out;
        });
    }

    /**
     * @return SearchResult[]
     */
    public function searchBy(string $property, string $value, string $lang = 'en', ?int $limit = null): array
    {
        if (!\preg_match('/^P\d+$/', $property)) {
            throw new \InvalidArgumentException('searchBy(): $property must be a P-code like "P18".');
        }
        $limit ??= $this->searchLimit;

        $key = sprintf('wd.searchBy.%s.%s.%s.%d', $property, $value, $lang, $limit);

        return $this->cache->get($key, function (ItemInterface $item) use ($property, $value, $lang, $limit): array {
            $item->expiresAfter($this->cacheTtl);

            $subject = \preg_match('/^Q\d+$/', $value) ? "wd:$value" : '"' . \addslashes($value) . '"';

            $sparql = <<<SPARQL
                SELECT ?item WHERE {
                    ?item wdt:{$property} {$subject} .
                }
                LIMIT {$limit}
                SPARQL;

            $rows = $this->sparqlSelect($sparql);

            $ids = [];
            foreach ($rows as $row) {
                $uri = $row['item'] ?? null;
                if (\is_string($uri)) {
                    $qid = \preg_replace('#^https?://www\.wikidata\.org/entity/#', '', $uri);
                    if ($qid) {
                        $ids[] = $qid;
                    }
                }
            }

            if ($ids === []) {
                return [];
            }

            $entities = $this->wbGetEntities($ids, $lang, includeSitelinks: true);

            $out = [];
            foreach ($entities as $e) {
                $out[] = SearchResult::fromArray($e, $lang);
            }
            return $out;
        });
    }

    /**
     * Fetch a single entity core and (optionally) a reduced set of claims.
     *
     * @param string[] $props Array of P-codes (e.g. ['P18']) to fetch. Pass [] to skip claims.
     */
    public function get(string $qid, string $lang = 'en', array $props = []): array
    {
        if (!\preg_match('/^Q\d+$/', $qid)) {
            throw new \InvalidArgumentException('get(): $qid must be like "Q123".');
        }

        $propsKey = $props ? '.props.' . \implode(',', \array_values($props)) : '.props.none';
        $key = sprintf('wd.get.%s.%s%s', $qid, $lang, $propsKey);

        return $this->cache->get($key, function (ItemInterface $item) use ($qid, $lang, $props): array {
            $item->expiresAfter($this->cacheTtl);

            $entities = $this->wbGetEntities([$qid], $lang, includeSitelinks: true);
            $core = $entities[0] ?? null;
            if (!$core) {
                throw new \RuntimeException("Entity $qid not found.");
            }

            if ($props !== []) {
                $claims = $this->fetchClaimsForProps($qid, $lang, $props);
                $core['claims'] = $claims;
            }

            return $core;
        });
    }

    /**
     * Convenience helper: return the first claim value for a property, if present.
     */
    public function firstClaim(array $entity, string $pCode): mixed
    {
        if (!isset($entity['claims'][$pCode]) || !\is_array($entity['claims'][$pCode]) || $entity['claims'][$pCode] === []) {
            return null;
        }
        return $entity['claims'][$pCode][0];
    }

    // -------------------- Console commands --------------------

    #[AsCommand('wiki:search', 'Search Wikidata by label or property expression')]
    public function searchCommand(
        SymfonyStyle $io,
        #[Argument('Search text (e.g., "Getty Museum") or a property expression (e.g., "P31=Q33506"). Omit when using --property/--value.')]
        ?string $query = null,
        #[Option('Locale/language (default: en)')]
        string $locale = 'en',
        #[Option('Max number of results')]
        int $limit = 10,
        #[Option('Property code for property-based search (e.g., P31)')]
        ?string $property = null,
        #[Option('Value for property-based search (e.g., Q33506 or a literal)')]
        ?string $value = null,
        #[Option('Comma-separated property codes to also fetch for each hit (e.g., P18,P279). Optional.')]
        ?string $props = null,
        #[Option('Output format: text or json')]
        string $format = 'text',
    ): int {
        // Parse CSV props -> array of P-codes
        $propsList = [];
        if ($props !== null && $props !== '') {
            $propsList = array_values(array_unique(array_filter(
                preg_split('/[,\s]+/', $props) ?: [],
                static fn(string $p) => preg_match('/^P\d+$/', $p) === 1
            )));
        }

        // Auto-detect "Pxx=..." expressions in $query if --property not provided
        if ($property === null && $query !== null) {
            $parsed = $this->parsePropertyExpression($query);
            if ($parsed !== null) {
                [$property, $value] = $parsed;
                $query = null; // treat as property search
            }
        }

        // Execute the search
        if ($property !== null) {
            if ($value === null || $value === '') {
                $io->error('When using --property, you must also provide --value (QID or literal).');
                return Command::FAILURE;
            }
            /** @var SearchResult[] $results */
            $results = $this->searchBy($property, $value, $locale, $limit);
        } else {
            if ($query === null || $query === '') {
                $io->error('Provide a search string (label), a property expression like "P31=Q33506", or use --property with --value.');
                return Command::FAILURE;
            }
            $results = $this->search($query, $locale, $limit);
        }

        // Optionally enrich each hit with requested properties
        $enrichedClaims = []; // [qid => ['P18' => [...], ...]]
        if (!empty($propsList)) {
            foreach ($results as $hit) {
                $entity = $this->get($hit->id, $locale, $propsList);
                $enrichedClaims[$hit->id] = $entity['claims'] ?? [];
            }
        }

        // Output
        if (\strtolower($format) === 'json') {
            $payload = array_map(function (SearchResult $r) use ($enrichedClaims): array {
                $row = [
                    'id'          => $r->id,
                    'label'       => $r->label,
                    'description' => $r->description,
                    'wiki_url'    => $r->wikiUrl,
                    'aliases'     => $r->aliases,
                ];
                if (isset($enrichedClaims[$r->id])) {
                    $row['claims'] = $enrichedClaims[$r->id];
                }
                return $row;
            }, $results);

            $io->writeln(json_encode($payload, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
            return Command::SUCCESS;
        }

        // Text output
        $io->title('Wikidata Search');
        $criteria = $property !== null
            ? sprintf('Property: %s  Value: %s  Locale: %s  Limit: %d', $property, $value, $locale, $limit)
            : sprintf('Query: %s  Locale: %s  Limit: %d', $query, $locale, $limit);
        $io->comment($criteria);

        $rows = array_map(static function (SearchResult $r): array {
            $desc = $r->description ?? '';
            if (\mb_strlen($desc) > 120) {
                $desc = \mb_substr($desc, 0, 117) . '…';
            }
            return [$r->id, $r->label ?? '—', $desc, $r->wikiUrl ?? '—'];
        }, $results);

        $io->table(['QID', 'Label', 'Description', 'Wikipedia'], $rows);

        if (!empty($propsList)) {
            $io->section('Requested properties');
            $propRows = [];
            foreach ($results as $r) {
                $claims = $enrichedClaims[$r->id] ?? [];
                if (empty($claims)) {
                    $propRows[] = [$r->id, '—', '—'];
                    continue;
                }
                foreach ($claims as $pcode => $values) {
                    $display = implode(', ', array_map(
                        static fn($v) => is_string($v) ? $v : json_encode($v, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),
                        $values
                    ));
                    $propRows[] = [$r->id, $pcode, $display];
                }
            }
            $io->table(['QID', 'Property', 'Values'], $propRows);
        } else {
            $io->comment('Tip: use --props=P18 to also fetch images, or multiple like --props=P18,P279.');
        }

        return Command::SUCCESS;
    }

    #[AsCommand('wiki:show', 'Show basic Wikidata info and selected properties')]
    public function showCommand(
        SymfonyStyle $io,
        #[Argument('Wikidata QID (e.g., Q42)')] string $qid,
        #[Option('Language code (default: en)')] string $lang = 'en',
        #[Option('Comma-separated property codes (e.g., P18,P279). If omitted, no properties are fetched.')] ?string $props = null,
        #[Option('Output format: text or json')] string $format = 'text',
    ): int {
        // Parse and validate props CSV -> ['P18','P279',...]
        $propList = [];
        if ($props !== null && $props !== '') {
            foreach (preg_split('/[,\s]+/', $props) ?: [] as $p) {
                $p = trim($p);
                if ($p !== '' && preg_match('/^P\d+$/', $p)) {
                    $propList[] = $p;
                }
            }
            $propList = array_values(array_unique($propList));
        }

        $entity = $this->get($qid, $lang, $propList);

        if ($format === 'json') {
            $io->writeln(json_encode($entity, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
            return Command::SUCCESS;
        }

        $io->title(sprintf('Wikidata %s (%s)', $entity['id'] ?? $qid, $lang));
        $io->definitionList(
            ['ID' => $entity['id'] ?? $qid],
            ['Label' => $entity['label'] ?? '—'],
            ['Description' => $entity['description'] ?? '—'],
            ['Wikipedia' => $entity['wiki_url'] ?? '—'],
        );

        if (!empty($entity['claims'])) {
            $io->section('Properties');
            $rows = [];
            foreach ($entity['claims'] as $pcode => $values) {
                $displayValues = array_map(
                    static fn($v) => is_string($v) ? $v : json_encode($v, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
                    $values
                );
                $rows[] = [$pcode, implode(', ', $displayValues)];
            }
            $io->table(['Property', 'Values'], $rows);
        } elseif ($propList !== []) {
            $io->warning('No values found for requested properties: ' . implode(', ', $propList));
        } else {
            $io->comment('No properties were requested. Use --props=P18,P279 to fetch specific properties.');
        }

        return Command::SUCCESS;
    }

    /**
     * Accepts expressions like P31=Q33506, P31:Q33506, P18="File:Foo.jpg".
     * Returns [property, value] or null if not a property expression.
     */
    private function parsePropertyExpression(string $expr): ?array
    {
        $expr = trim($expr);
        if ($expr === '' || !preg_match('/^(P\d+)\s*(?:=|:)\s*(.+)$/u', $expr, $m)) {
            return null;
        }
        $valueRaw = trim($m[2]);
        if ((str_starts_with($valueRaw, '"') && str_ends_with($valueRaw, '"')) ||
            (str_starts_with($valueRaw, "'") && str_ends_with($valueRaw, "'"))) {
            $valueRaw = mb_substr($valueRaw, 1, -1);
        }
        return [$m[1], $valueRaw];
    }

    /**
     * Try to extract a QID from a Wikipedia page with a Taxonbar (optional helper).
     */
    public function fetchWikipediaPage(string $title, string $lang = 'en'): ?string
    {
        $url = sprintf('https://%s.wikipedia.org/wiki/%s?action=raw', $lang, $title);
        $key = 'wd.wiki.raw.' . md5($url);

        $content = $this->cache->get($key, function (ItemInterface $item) use ($url) {
            $item->expiresAfter(3600 * 24 * 30);
            $this->logger->notice("Fetching $url");
            try {
                return $this->http->request('GET', $url, ['headers' => $this->ua()])->getContent();
            } catch (\Throwable) {
                return null;
            }
        });

        if ($content && \preg_match('/Taxonbar\|from=([A-Z]\d+)/', $content, $m)) {
            return $m[1];
        }
        return null;
    }

    // -------------------- Low-level HTTP helpers --------------------

    /**
     * @return array<int, array>
     */
    private function wbSearchEntities(string $search, string $lang, int $limit): array
    {
        $resp = $this->http->request('GET', self::WD_API, [
            'headers' => $this->ua() + ['Accept' => 'application/json'],
            'query' => [
                'action'   => 'wbsearchentities',
                'format'   => 'json',
                'language' => $lang,
                'search'   => $search,
                'limit'    => $limit,
                'type'     => 'item',
                'origin'   => '*',
            ],
        ]);

        return $this->safeJson($resp)['search'] ?? [];
    }

    /**
     * Normalize entity data for the requested IDs using MediaWiki API (wbgetentities).
     * This avoids HTML responses from Special:EntityData in some situations.
     *
     * @param string[] $ids
     * @return array<int, array{id:string,label:?string,description:?string,aliases:array<int,string>,sitelinks:array<string,mixed>,wiki_url:?string}>
     */
    private function wbGetEntities(array $ids, string $lang, bool $includeSitelinks = true): array
    {
        // sanitize and chunk
        $ids = array_values(array_unique(array_filter($ids)));
        if ($ids === []) {
            return [];
        }

        $chunks = array_chunk($ids, 50); // be nice

        $out = [];
        foreach ($chunks as $chunk) {
            $props = ['labels','descriptions','aliases'];
            if ($includeSitelinks) {
                $props[] = 'sitelinks/urls';
            }

            $resp = $this->http->request('GET', self::WD_API, [
                'headers' => $this->ua() + ['Accept' => 'application/json'],
                'query'   => [
                    'action'    => 'wbgetentities',
                    'format'    => 'json',
                    'ids'       => implode('|', $chunk),
                    'languages' => $lang . '|en',
                    'props'     => implode('|', $props),
                    'origin'    => '*',
                    // 'sitefilter' => $lang.'wiki', // uncomment to reduce sitelinks payload
                ],
            ]);

            $data = $this->safeJson($resp);

            $entities = (array)($data['entities'] ?? []);
            foreach ($entities as $id => $body) {
                if (!\is_array($body)) {
                    continue;
                }
                $label = $body['labels'][$lang]['value'] ?? ($body['labels']['en']['value'] ?? null);
                $desc  = $body['descriptions'][$lang]['value'] ?? ($body['descriptions']['en']['value'] ?? null);

                $aliases = [];
                foreach (($body['aliases'][$lang] ?? []) as $aliasRow) {
                    if (isset($aliasRow['value'])) {
                        $aliases[] = $aliasRow['value'];
                    }
                }

                $sitelinks = (array)($body['sitelinks'] ?? []);
                $wikiUrl = null;
                if ($includeSitelinks) {
                    $key = $lang . 'wiki';
                    if (isset($sitelinks[$key]['url'])) {
                        $wikiUrl = $sitelinks[$key]['url'];
                    }
                }

                $out[] = [
                    'id'          => (string)$id,
                    'label'       => $label,
                    'description' => $desc,
                    'aliases'     => $aliases,
                    'sitelinks'   => $sitelinks,
                    'wiki_url'    => $wikiUrl,
                ];
            }
        }

        return $out;
    }

    /**
     * Build a very small claims map for a restricted set of properties using SPARQL.
     *
     * Returns:
     *  [
     *    'P18' => ['File:Some_Image.jpg', ...],
     *    'P279' => ['Q123', 'Q456', ...],
     *    ...
     *  ]
     *
     * @param string[] $props
     * @return array<string, array<int, mixed>>
     */
    private function fetchClaimsForProps(string $qid, string $lang, array $props): array
    {
        // Sanitize to P-codes and dedupe
        $props = \array_values(\array_unique(\array_filter($props, static fn($p) => preg_match('/^P\d+$/', $p))));

        if ($props === []) {
            return [];
        }

        // VALUES ?property { wd:P18 wd:P279 ... }
        $values = \implode(' ', \array_map(static fn(string $p) => "wd:$p", $props));

        $sparql = <<<SPARQL
            SELECT ?property ?propertyLabel ?statement ?propertyValue ?propertyValueLabel ?ps ?pq ?qualifier ?qualifierValue ?qualifierValueLabel
            WHERE {
                VALUES (?item) {(wd:{$qid})}
                VALUES ?property { {$values} }
                ?property wikibase:claim ?prop .
                ?property wikibase:statementProperty ?ps .
                OPTIONAL { ?property wikibase:qualifier ?pq . }

                ?item ?prop ?statement .
                ?statement ?ps ?propertyValue .

                SERVICE wikibase:label { bd:serviceParam wikibase:language "{$lang},en" }
            }
            SPARQL;

        $rows = $this->sparqlSelect($sparql);

        // Reduce to property -> list of simplified values
        $claims = [];
        foreach ($rows as $r) {
            $propUri = $r['property'] ?? null;
            if (!\is_string($propUri)) {
                continue;
            }
            $pCode = \preg_replace('#^https?://www\.wikidata\.org/entity/#', '', $propUri) ?? null;
            if (!$pCode) {
                continue;
            }

            $val = $r['propertyValue'] ?? null;
            if (!\is_string($val)) {
                continue;
            }

            // Simplify: entity URI -> QID; else keep literal
            $value = \preg_match('#^https?://www\.wikidata\.org/entity/(Q\d+)$#', $val, $m) ? $m[1] : $val;

            $claims[$pCode] ??= [];
            if (!\in_array($value, $claims[$pCode], true)) {
                $claims[$pCode][] = $value;
            }
        }

        return $claims;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function sparqlSelect(string $sparql): array
    {
        $resp = $this->http->request('GET', self::WD_SPARQL, [
            'headers' => $this->ua() + [
                'Accept' => 'application/sparql-results+json',
            ],
            'query' => [
                'query' => $sparql,
            ],
        ]);

        $data = $this->safeJson($resp);

        $rows = [];
        foreach (($data['results']['bindings'] ?? []) as $b) {
            $row = [];
            foreach ($b as $k => $val) {
                $row[$k] = $val['value'] ?? null;
            }
            $rows[] = $row;
        }
        return $rows;
    }

    /**
     * Decode JSON safely and surface a helpful snippet if the body isn't JSON.
     */
    private function safeJson(\Symfony\Contracts\HttpClient\ResponseInterface $resp): array
    {
        $content = $resp->getContent(false); // don't throw on HTTP error
        $ct = '';
        foreach ($resp->getHeaders(false)['content-type'] ?? [] as $h) {
            $ct .= $h . ';';
        }

        try {
            /** @var array $decoded */
            $decoded = \json_decode($content, true, flags: \JSON_THROW_ON_ERROR);
            return $decoded;
        } catch (\JsonException $e) {
            $snippet = \substr(\preg_replace('/\s+/', ' ', $content), 0, 300);
            $this->logger->error('Wikidata JSON decode failed', [
                'error' => $e->getMessage(),
                'contentType' => $ct,
                'snippet' => $snippet,
            ]);
            throw new \RuntimeException('Wikidata returned non-JSON or invalid JSON. ' . $snippet, previous: $e);
        }
    }

    private function ua(): array
    {
        return ['User-Agent' => $this->userAgent];
    }
}
