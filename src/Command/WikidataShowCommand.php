<?php
declare(strict_types=1);

namespace Survos\WikiBundle\Command;

use Survos\WikiBundle\Service\WikidataService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand('wiki:show', 'Show basic Wikidata info and selected properties', help: <<< 'HELP'
bin/console wiki:show Q71981788

https://www.wikidata.org/wiki/Q71981788 -P625
HELP
)]
final class WikidataShowCommand
{
    public function __construct(private readonly WikidataService $wikidata) {}

    public function __invoke(
        SymfonyStyle $io,
        #[Argument('Wikidata QID (e.g., Q42)')] string $qid,
        #[Option('Language code (default: en)')] string $lang = 'en',
        #[Option('Comma-separated property codes (e.g., P18,P279). If omitted, no properties are fetched.')] ?string $props = null,
        #[Option('Output format: text or json')] string $format = 'text',
    ): int {
        // Parse and validate props CSV -> ['P18','P279',...]
        $propList = [];
        if ($props !== null && $props !== '') {
            $parts = preg_split('/[,\s]+/', $props) ?: [];
            foreach ($parts as $p) {
                $p = trim($p);
                if ($p !== '' && preg_match('/^P\d+$/', $p)) {
                    $propList[] = $p;
                }
            }
            $propList = array_values(array_unique($propList));
        }

        // Fetch core entity + optional selected claims
        $entity = $this->wikidata->get($qid, $lang, $propList);

        if ($format === 'json') {
            $io->writeln(json_encode($entity, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE));
            return Command::SUCCESS;
        }

        // Human-friendly output
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
                    static fn($v) => is_string($v)
                        ? $v
                        : json_encode($v, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE),
                    $values
                );
                $rows[] = [$pcode, implode(', ', $displayValues)];
            }
            $io->table(['Property', 'Values'], $rows);
        } else {
            if ($propList !== []) {
                $io->warning('No values found for requested properties: ' . implode(', ', $propList));
            } else {
                $io->comment('No properties were requested. Use --props=P18,P279 to fetch specific properties.');
            }
        }

        return Command::SUCCESS;
    }
}
