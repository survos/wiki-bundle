<?php
declare(strict_types=1);

namespace Survos\WikiBundle\Dto;

final class SearchResult
{
    public function __construct(
        public readonly string $id,
        public readonly ?string $label = null,
        public readonly ?string $description = null,
        /** @var string[] */
        public readonly array $aliases = [],
        public readonly ?string $wikiUrl = null,
        public readonly string $lang = 'en',
    ) {}

    /**
     * @param array{
     *   id?: string,
     *   label?: ?string,
     *   description?: ?string,
     *   aliases?: array<int, string>,
     *   sitelinks?: array<string, mixed>,
     *   wiki_url?: ?string
     * } $data
     */
    public static function fromArray(array $data, string $lang = 'en'): self
    {
        $id = (string)($data['id'] ?? '');
        $aliases = $data['aliases'] ?? [];
        if (!\is_array($aliases)) {
            $aliases = [];
        }

        $wikiUrl = $data['wiki_url'] ?? null;
        if (!$wikiUrl && isset($data['sitelinks'][$lang]['url'])) {
            $wikiUrl = $data['sitelinks'][$lang]['url'];
        } elseif (!$wikiUrl && $id) {
            $wikiUrl = "https://{$lang}.wikipedia.org/wiki/{$id}";
        }

        return new self(
            id: $id,
            label: $data['label'] ?? null,
            description: $data['description'] ?? null,
            aliases: \array_values($aliases),
            wikiUrl: $wikiUrl,
            lang: $lang
        );
    }
}
