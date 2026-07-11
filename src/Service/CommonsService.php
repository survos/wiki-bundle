<?php
declare(strict_types=1);

namespace Survos\WikiBundle\Service;

use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Attribute\Option;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Style\SymfonyStyle;
use Symfony\Contracts\Cache\CacheInterface;
use Symfony\Contracts\Cache\ItemInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Wikimedia Commons client — category browsing + per-file metadata.
 *
 * Separate from WikidataService (Wikidata QID lookups). Commons categories are
 * enumerated via `categorymembers`; per-file metadata (url, license, author,
 * description, GPS) via `imageinfo` + the Coordinates extension (`coordinates`),
 * which is more reliable than parsing EXIF strings out of extmetadata.
 */
final class CommonsService
{
    private const API = 'https://commons.wikimedia.org/w/api.php';

    public function __construct(
        private CacheInterface $cache,
        private readonly LoggerInterface $logger,
        private readonly HttpClientInterface $http,
        private int $cacheTtl = 86400,
        private readonly string $userAgent = 'SurvosWikiBundle/2025 (+https://survos.com)',
    ) {
    }

    /**
     * List file titles ("File:Foo.jpg") directly in a Commons category (not recursive).
     *
     * @return string[]
     */
    public function categoryFiles(string $category, int $limit = 0): array
    {
        $category = self::normalizeCategoryTitle($category);
        $titles = [];
        $cmcontinue = null;

        do {
            $query = [
                'action' => 'query',
                'format' => 'json',
                'list' => 'categorymembers',
                'cmtitle' => $category,
                'cmtype' => 'file',
                'cmlimit' => 500,
            ];
            if ($cmcontinue !== null) {
                $query['cmcontinue'] = $cmcontinue;
            }

            $resp = $this->http->request('GET', self::API, [
                'headers' => $this->ua() + ['Accept' => 'application/json'],
                'query' => $query,
            ]);
            $data = $this->safeJson($resp);

            foreach ($data['query']['categorymembers'] ?? [] as $member) {
                if (isset($member['title']) && \is_string($member['title'])) {
                    $titles[] = $member['title'];
                }
                if ($limit > 0 && \count($titles) >= $limit) {
                    return \array_slice($titles, 0, $limit);
                }
            }

            $cmcontinue = $data['continue']['cmcontinue'] ?? null;
        } while ($cmcontinue !== null);

        return $titles;
    }

    /**
     * Fetch url/license/author/description/GPS for a batch of file titles.
     *
     * @param string[] $titles
     * @return array<string, array{
     *   title:string, url:?string, descriptionUrl:?string, width:?int, height:?int, mime:?string,
     *   artist:?string, credit:?string, licenseShortName:?string, license:?string, licenseUrl:?string,
     *   description:?string, dateOriginal:?string, categories:string[],
     *   latitude:?float, longitude:?float,
     * }> keyed by title
     */
    public function imageInfo(array $titles): array
    {
        $titles = \array_values(\array_unique(\array_filter($titles)));
        if ($titles === []) {
            return [];
        }

        $out = [];
        foreach (\array_chunk($titles, 50) as $chunk) {
            $key = 'commons.imageinfo.' . \md5(\implode('|', $chunk));

            $rows = $this->cache->get($key, function (ItemInterface $item) use ($chunk): array {
                $item->expiresAfter($this->cacheTtl);

                $resp = $this->http->request('GET', self::API, [
                    'headers' => $this->ua() + ['Accept' => 'application/json'],
                    'query' => [
                        'action' => 'query',
                        'format' => 'json',
                        'titles' => \implode('|', $chunk),
                        'prop' => 'imageinfo|coordinates',
                        'iiprop' => 'url|extmetadata|size|mime',
                        'coprop' => 'type|dim',
                        'colimit' => 50,
                    ],
                ]);
                $data = $this->safeJson($resp);

                $rows = [];
                foreach ($data['query']['pages'] ?? [] as $page) {
                    if (!\is_array($page) || !isset($page['title'])) {
                        continue;
                    }
                    $rows[$page['title']] = $this->mapPage($page);
                }
                return $rows;
            });

            $out += $rows;
        }

        return $out;
    }

    /** @param array<string,mixed> $page */
    private function mapPage(array $page): array
    {
        $info = $page['imageinfo'][0] ?? [];
        $meta = $info['extmetadata'] ?? [];

        [$lat, $lon] = $this->extractCoordinates($page, $meta);

        return [
            // Commons' permanent MediaWiki page id — survives file renames, unlike title.
            // Wikimedia's own convention for this exact entity is "M<pageid>" (its Structured
            // Data / Wikibase MediaInfo id) — use that as our stable identifier, not a hash of
            // the (mutable) title, so AI claims/thumbnails/media rows stay keyed correctly even
            // if a file gets renamed on Commons.
            'pageid' => isset($page['pageid']) ? (int) $page['pageid'] : null,
            'title' => (string) $page['title'],
            'url' => $this->clean($info['url'] ?? null),
            'descriptionUrl' => $this->clean($info['descriptionurl'] ?? null),
            'width' => isset($info['width']) ? (int) $info['width'] : null,
            'height' => isset($info['height']) ? (int) $info['height'] : null,
            'mime' => $this->clean($info['mime'] ?? null),
            'artist' => $this->metaText($meta, 'Artist'),
            'credit' => $this->metaText($meta, 'Credit'),
            'licenseShortName' => $this->metaText($meta, 'LicenseShortName'),
            'license' => $this->metaText($meta, 'License'),
            'licenseUrl' => $this->metaText($meta, 'LicenseUrl'),
            'description' => $this->metaText($meta, 'ImageDescription'),
            'dateOriginal' => $this->metaText($meta, 'DateTimeOriginal') ?? $this->metaText($meta, 'DateTime'),
            'categories' => $this->metaList($meta, 'Categories'),
            'latitude' => $lat,
            'longitude' => $lon,
        ];
    }

    /**
     * Prefer the structured Coordinates-extension `coordinates` array (decimal degrees, one
     * per page); fall back to the extmetadata GPSLatitude/GPSLongitude strings (also decimal
     * degrees on Commons, unlike raw EXIF DMS) when the extension didn't run for this file.
     *
     * @param array<string,mixed> $page
     * @param array<string,mixed> $meta
     * @return array{0: ?float, 1: ?float}
     */
    private function extractCoordinates(array $page, array $meta): array
    {
        $coord = $page['coordinates'][0] ?? null;
        if (\is_array($coord) && isset($coord['lat'], $coord['lon'])) {
            return [(float) $coord['lat'], (float) $coord['lon']];
        }

        $lat = $this->metaText($meta, 'GPSLatitude');
        $lon = $this->metaText($meta, 'GPSLongitude');
        if ($lat !== null && $lon !== null && \is_numeric($lat) && \is_numeric($lon)) {
            return [(float) $lat, (float) $lon];
        }

        return [null, null];
    }

    /**
     * extmetadata values may carry HTML (e.g. Artist is often a linked username). Templates like
     * {{unknown|author}} and {{circa}} also embed a `display:none` element carrying a duplicate
     * or a machine-readable QuickStatements string (e.g. "Unknown author<span style=\"display:
     * none\">Unknown author</span>", "circa 1925<div style=\"display: none;\">date QS:P,+1925…
     * </div>") — strip_tags alone keeps that hidden text inline, so drop those elements whole
     * (including their content) before stripping the remaining visible tags.
     */
    private function metaText(array $meta, string $key): ?string
    {
        $value = $meta[$key]['value'] ?? null;
        if (!\is_string($value) || $value === '') {
            return null;
        }
        $value = \preg_replace('/<([a-z]+)[^>]*style="[^"]*display:\s*none[^"]*"[^>]*>.*?<\/\1>/is', '', $value) ?? $value;
        $text = \trim(\html_entity_decode(\strip_tags($value), \ENT_QUOTES | \ENT_HTML5));
        return $text !== '' ? $text : null;
    }

    /** @return string[] */
    private function metaList(array $meta, string $key): array
    {
        $text = $this->metaText($meta, $key);
        if ($text === null) {
            return [];
        }
        return \array_values(\array_filter(\array_map('trim', \explode('|', $text))));
    }

    private function clean(mixed $value): ?string
    {
        return \is_string($value) && $value !== '' ? $value : null;
    }

    public static function normalizeCategoryTitle(string $category): string
    {
        $category = \trim($category);
        $category = \str_replace(' ', '_', $category);
        return \str_starts_with($category, 'Category:') ? $category : 'Category:' . $category;
    }

    // -------------------- Console command --------------------

    #[AsCommand('wiki:commons:category', 'List files in a Wikimedia Commons category with resolved metadata')]
    public function categoryCommand(
        SymfonyStyle $io,
        #[Argument('Category name (e.g. "Quality images of Geneva"), with or without the "Category:" prefix')]
        string $category,
        #[Option('Max files to list (0 = all)')] int $limit = 20,
        #[Option('Output format: text or json')] string $format = 'text',
    ): int {
        $titles = $this->categoryFiles($category, $limit);
        if ($titles === []) {
            $io->warning(\sprintf('No files found in %s', self::normalizeCategoryTitle($category)));
            return Command::SUCCESS;
        }

        $info = $this->imageInfo($titles);

        if (\strtolower($format) === 'json') {
            $io->writeln(\json_encode(\array_values($info), \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE));
            return Command::SUCCESS;
        }

        $io->title(\sprintf('%s (%d file(s), showing %d)', self::normalizeCategoryTitle($category), \count($titles), \count($info)));
        $rows = [];
        foreach ($info as $row) {
            $rows[] = [
                $row['title'],
                $row['licenseShortName'] ?? '—',
                $row['latitude'] !== null ? \sprintf('%.4f, %.4f', $row['latitude'], $row['longitude']) : '—',
                $row['artist'] ?? '—',
            ];
        }
        $io->table(['Title', 'License', 'Coordinates', 'Artist'], $rows);

        return Command::SUCCESS;
    }

    private function safeJson(\Symfony\Contracts\HttpClient\ResponseInterface $resp): array
    {
        $content = $resp->getContent(false);
        try {
            return \json_decode($content, true, flags: \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $snippet = \substr(\preg_replace('/\s+/', ' ', $content), 0, 300);
            $this->logger->error('Commons JSON decode failed', ['error' => $e->getMessage(), 'snippet' => $snippet]);
            throw new \RuntimeException('Commons API returned non-JSON or invalid JSON. ' . $snippet, previous: $e);
        }
    }

    private function ua(): array
    {
        return ['User-Agent' => $this->userAgent];
    }
}
