<?php

namespace Survos\WikiBundle\Meta;

interface WikiInterface
{
    public function getWikiDataId(): ?string;
    public function setWikiDataId($wikDataId): void;

//    public function getWikData();
    public function setWikiData(?object $data): self;
    public function getWikiData();

    public function getWikiUrl(): ?string;
    public function setWikiUrl(?string $wikiUrl): self;

    public function getWikiDescription(): ?string;

    public function getWikiTitle(): string;
    static public function getWikipediaPage(string $title, string $lang = 'en');

    public function setParent(WikiInterface $wiki): self;
}
