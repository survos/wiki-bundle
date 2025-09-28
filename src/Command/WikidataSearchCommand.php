<?php
declare(strict_types=1);

namespace Survos\WikiBundle\Command;

use Survos\WikiBundle\Dto\SearchResult;
use Survos\WikiBundle\Service\WikidataService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('wiki:search', 'Search Wikidata by label or property expression')]
final class WikidataSearchCommand
{
    public function __construct(private readonly WikidataService $wikidata) {}

    public function __invoke(
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
            $results = $this->wikidata->searchBy($property, $value, $locale, $limit);
        } else {
            if ($query === null || $query === '') {
                $io->error('Provide a search string (label), a property expression like "P31=Q33506", or use --property with --value.');
                return Command::FAILURE;
            }
            $results = $this->wikidata->search($query, $locale, $limit);
        }

        // Optionally enrich each hit with requested properties
        $enrichedClaims = []; // [qid => ['P18' => [...], ...]]
        if (!empty($propsList)) {
            foreach ($results as $hit) {
                $entity = $this->wikidata->get($hit->id, $locale, $propsList);
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

    /**
     * Accepts expressions like:
     *   P31=Q33506
     *   P31:Q33506
     *   P18="File:Foo.jpg"
     * Returns [property, value] or null if not a property expression.
     */
    private function parsePropertyExpression(string $expr): ?array
    {
        $expr = trim($expr);
        if ($expr === '') {
            return null;
        }

        if (!preg_match('/^(P\d+)\s*(?:=|:)\s*(.+)$/u', $expr, $m)) {
            return null;
        }
        $property = $m[1];
        $valueRaw = trim($m[2]);

        // Strip quotes if present
        if ((str_starts_with($valueRaw, '"') && str_ends_with($valueRaw, '"')) ||
            (str_starts_with($valueRaw, "'") && str_ends_with($valueRaw, "'"))) {
            $valueRaw = mb_substr($valueRaw, 1, -1);
        }

        return [$property, $valueRaw];
    }
}
