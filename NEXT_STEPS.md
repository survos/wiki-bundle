# wiki-bundle — next steps (resume here)

Paused mid-refactor on 2026-06-02. The **data model is done and inert**; the
service rewrite that activates it is **not done**. Nothing here is wired into
`get()` yet, so meili keeps working on the old array path. Tracked as task #1
(wiki chunk 2), task #2 (fix md), task #3 (media MediaFlow probe — separate).

## Where we are

**Done (chunk 1 — lands as files, validates, but unused by the service):**
- `src/Entity/WikiProperty.php` — entity (was an enum): `code` (PK), `alias`, `label`, `datatype`, `description`, `refreshedAt`, virtual `isImage` (`datatype==='commonsMedia'`).
- `src/Entity/WikiClaim.php` — entity (was a DTO), grain **one row per (qid, code, value)**: `wikiData`, `code`, `value`, `datatype`, `qualifiers` (JSON), `position`; virtual `isImage`/`url`, `toImage()`.
- `src/Entity/WikiData.php` — rewritten: `qid`, `rawData` (core only), `claims` (OneToMany cascade/orphan), `fetchedProps`, `refreshedAt`; virtual `label`/`description`/`wikiUrl`; `claimsFor()`, `getImages(): Image[]`, `isStale($ttl,$now)`. **Dropped `createdAt`/`updatedAt`.**
- `src/Dto/Image.php` — `url`, `filename`, `captions[lang]`, `date`; `fromClaim()`, `caption($lang)`.
- `src/Repository/WikiClaimRepository.php` (`findByCode('P18')`), `WikiPropertyRepository.php` (`isEmpty()`).
- `SurvosWikiBundle` config: `properties` (alias⇒P-code map, default = old enum set).
- Old `src/Dto/WikiClaim.php` and the `WikiProperty` **enum** are deleted.
- Style: PHP 8.4 public props, `public private(set)` for set-once, property hooks for derived values (none are mapped columns). NO getter/setter boilerplate.

**Not done (chunk 2 — the activation):** `WikidataService::get()` still returns the old array via SPARQL. The constructor edit was reverted, so the service is in its original working state.

**md is currently broken** by chunk 1 (uses the removed enum + old WikiData API) — accepted, fixed in task #2.

## Chunk 2 — what to build (the spec we designed)

### `WikidataService` rewrite
- Constructor: add `EntityManagerInterface $em` and `array $properties = []` (alias⇒code). Keep cache/logger/http/searchLimit/cacheTtl/userAgent. `search()`/`searchBy()` keep the Symfony-pool cache; **`get()` moves to the DB cache** and stops using the pool. Remove `fetchClaimsForProps()` (SPARQL claims — lossy). Keep `sparqlSelect()` (searchBy uses it).
- `get(string $qid, string $lang='en', ?array $props=null): WikiData`
  1. `seedPropertiesIfEmpty($lang)`.
  2. `$wanted = $props ?? array_values($this->properties)`.
  3. find `WikiData` by qid or `new WikiData($qid)`; `$now = new \DateTimeImmutable()`.
  4. `$stale = id===null || isStale(cacheTtl,$now)`; `$missing = array_diff($wanted, fetchedProps)`. If `!$stale && $missing===[]` → return cached `$wd`.
  5. `$body = fetchEntityBody($qid,$lang)` (one wbgetentities call, props=`labels|descriptions|aliases|sitelinks/urls|claims`). persist if new; if stale set `rawData = extractCore($body,$lang)`.
  6. for each code in (`$stale ? $wanted : $missing`): `removeClaimsByCode($code)`; for each statement in `body['claims'][$code]` with `mainsnak.snaktype==='value'`, `addClaim(new WikiClaim($wd,$code, mainValue($snak), $snak['datatype']??null, simplifyQualifiers($statement['qualifiers']??[]), $pos++))`; append code to `fetchedProps`.
  7. `refreshedAt=$now`; `flush()`; return `$wd`.
- `getImages($qid,$lang='en'): Image[]` → `get($qid,$lang)->getImages()`.
- `cachedClaims($code): WikiClaim[]` → `em->getRepository(WikiClaim::class)->findByCode($code)`.

### Private helpers
- `fetchEntityBody($qid,$lang): ?array` — wbgetentities w/ claims → `entities[$qid]`.
- `extractCore($body,$lang): array` — refactor of the body→core logic in `wbGetEntities()` (id/label/description/aliases/sitelinks/wiki_url).
- `mainValue($snak): string` — reduce datavalue to a string by `datavalue.type`:
  `wikibase-entityid`→`value.id` (QID), `time`→`value.time`, `monolingualtext`→`value.text`,
  `quantity`→`value.amount`, `string`→value, else `json_encode(value)`.
- `simplifyQualifiers($qualifiers): array` — `code => [datavalue.value, …]` for value-snaks only.
  (P2096 monolingualtext value = `{text,language}`; P585 time value = `{time,…}` — exactly what `Image::fromClaim` reads.)
- `seedPropertiesIfEmpty($lang)` — if `WikiPropertyRepository::isEmpty()`, `wbGetRaw(array_values($properties), $lang, ['labels','descriptions','datatype'])` and persist a `WikiProperty($code, aliasByCode[$code])` each with label/datatype/description/refreshedAt.
- `wbGetRaw($ids,$lang,$props): array` — raw wbgetentities bodies keyed by id (≤50 ids ok).

### Confirmed Wikidata statement shape (so the simplifiers are right)
`wbgetclaims Q42 P18` → `mainsnak.datavalue = {value:"Douglas adams portrait.jpg", type:"string"}`,
`mainsnak.datatype:"commonsMedia"`, `qualifiers.P2096` = media legend (monolingualtext, multi-lang),
`qualifiers.P585` = point in time. So a P18 claim carries url + captions + date — never return a bare URL.

### Bundle wiring — `SurvosWikiBundle::loadExtension`
Add `->arg('$properties', $config['properties'])` to the `WikidataService` definition (it already
sets `$searchLimit`/`$cacheTtl` and `->autowire()` covers `$em`).

### Update the folded commands (same file)
`showCommand`/`searchCommand` currently read array shape (`$entity['label']`, `$entity['claims']`).
Switch to the entity: `$wd = $this->get($qid,$lang,$propList ?: [])` (pass `[]` for "core only" to keep
"no --props = no claims"; `--props=` list still works). Add a private
`claimsArray(WikiData $wd): array` (group `$wd->claims` → `code=>[values]`) for the tables/JSON.

### meili (this app — finish task #1 here)
`src/Workflow/OfficialWorkflow.php::onFetchWiki`:
```php
$wd = $this->wikiService->get($official->getWikidataId());   // no props → configured (incl P18)
$official->setWikiData($wd->rawData);
foreach ($wd->getImages() as $image) {
    $official->getOriginalImageUrl() ?: $official->setOriginalImageUrl($image->url);
    $this->mediaRegistry->ensureMedia($image->url, flush: true);
}
```
Then `bin/console make:migration && doctrine:migrations:migrate -n` (adds `wiki_claim`, `wiki_property`;
alters `wiki_data`: +`fetchedProps`,+`refreshedAt`, −`createdAt`/`updatedAt`).

### Verify
- `bin/console wiki:show Q76 --props=P18` → image claim with a caption.
- `bin/console iterate official --sync --limit 1 -m new -t fetch_wiki` → media row + `Image` w/ `url`+`caption`.
- `WikidataService::cachedClaims('P18')` → all cached image claims in one query (the goal).

## Task #2 — fix md (after chunk 2 finalizes the API)
`md/src/Workflow/GrpWorkflow.php` (uses `WikiProperty::IMAGE->value`, `new WikiData()+setRawData()`,
`->image()/->getDescription()/->getLabel()`) and `GlamWorkflow.php` (`(object)` cast + `->claims['P18']`):
switch to `$wd = get($qid)` + `$wd->getImages()` + `$wd->label`/`$wd->description`. Reconsider
`Grp.wikiData` `OneToOne(cascade remove)` vs the shared cache (qid is unique → should be ManyToOne, no
remove). Needs an md migration. Do md once, against the final API, to avoid double work.
