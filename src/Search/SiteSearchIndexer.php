<?php

declare(strict_types=1);

namespace Pdir\SiteSearchBundle\Search;

use Contao\CoreBundle\Search\Document;
use Contao\CoreBundle\Search\Indexer\IndexerException;
use Contao\CoreBundle\Search\Indexer\IndexerInterface;
use Contao\System;
use Pdir\SiteSearchBundle\Engine\IndexSchema;
use Pdir\SiteSearchBundle\Engine\SearchEngineInterface;
use Pdir\SiteSearchBundle\Source\SourceRegistry;
use Symfony\Component\DomCrawler\Crawler;

class SiteSearchIndexer implements IndexerInterface
{
    /**
     * Longer page texts are cut off to keep the index small.
     */
    public const MAX_CONTENT_LENGTH = 50000;

    private const NON_INDEXABLE_ELEMENTS = ['script', 'style', 'noscript', 'template', 'svg', 'iframe', 'object', 'canvas'];

    public function __construct(
        private readonly SearchEngineInterface $engine,
        private readonly bool $indexProtected = false,
    ) {
    }

    public function index(Document $document): void
    {
        $data = $this->createIndexDocument($document);

        try {
            $existing = $this->engine->getDocument($data['url']);
        } catch (\Throwable) {
            $existing = null;
        }

        // A provider (e.g. news) has a better document for this URL than the crawler
        if (\is_array($existing) && SourceRegistry::PAGE_SOURCE !== ($existing['source'] ?? SourceRegistry::PAGE_SOURCE)) {
            $this->throwBecause(\sprintf('Already indexed by the source "%s".', $existing['source']));
        }

        try {
            $errors = $this->engine->addDocuments([$data]);
        } catch (\Throwable $t) {
            $this->throwBecause('Could not add a search index entry: '.$t->getMessage(), false);
        }

        if ([] !== $errors) {
            $this->throwBecause('Could not add a search index entry: '.implode(' ', $errors), false);
        }
    }

    public function delete(Document $document): void
    {
        try {
            $this->engine->deleteDocuments([(string) $document->getUri()]);
        } catch (\Throwable $t) {
            $this->throwBecause('Could not delete the search index entry: '.$t->getMessage(), false);
        }
    }

    public function clear(): void
    {
        $this->engine->drop();
    }

    /**
     * Maps a crawled page to the document stored in the index.
     *
     * @return array<string, mixed>
     *
     * @throws IndexerException if the page must not be indexed
     */
    public function createIndexDocument(Document $document): array
    {
        if (200 !== $document->getStatusCode()) {
            $this->throwBecause('HTTP Statuscode is not equal to 200.');
        }

        if ('' === $document->getBody()) {
            $this->throwBecause('Cannot index empty response.');
        }

        if (($canonical = $document->extractCanonicalUri()) && (string) $canonical !== (string) $document->getUri()) {
            $this->throwBecause(\sprintf('Ignored because canonical URI "%s" does not match document URI.', $canonical));
        }

        $meta = $this->getPageMeta($document);

        if (empty($meta['pageId'])) {
            $this->throwBecause('No page ID could be determined.');
        }

        // If search was disabled in the page settings, we do not index
        if (true === ($meta['noSearch'] ?? false)) {
            $this->throwBecause('Was explicitly marked "noSearch" in page settings.');
        }

        // If the front end preview is activated, we do not index
        if (true === ($meta['fePreview'] ?? false)) {
            $this->throwBecause('Indexing when the front end preview is enabled is not possible.');
        }

        $protected = true === ($meta['protected'] ?? false);

        // If the page is protected and indexing protected pages is disabled, we do not index
        if ($protected && !$this->indexProtected) {
            $this->throwBecause('Indexing protected pages is disabled.');
        }

        $crawler = $document->getContentCrawler();
        $uri = $document->getUri();

        return [
            'url' => (string) $uri,
            'source' => SourceRegistry::PAGE_SOURCE,
            'domain' => strtolower($uri->getHost()),
            'title' => $this->getTitle($crawler, $meta),
            'description' => $this->getMetaContent($crawler, ['description']),
            'content' => $this->extractContent($document->getBody()),
            'image' => $this->getImage($crawler, $document),
            'language' => $this->getLanguage($crawler),
            'record' => '',
            ...IndexSchema::normalizeAccessFields($protected, (array) ($meta['groups'] ?? [])),
        ];
    }

    /**
     * Visible text of the page body without scripts, styles and areas between <!--
     * indexer::stop --> and <!-- indexer::continue -->.
     */
    public function extractContent(string $html): string
    {
        $html = $this->removeNonIndexableAreas($html);

        // HOOK: add custom logic (same signature as in older versions of the bundle)
        if (isset($GLOBALS['TL_HOOKS']['siteSearchIndexPage']) && \is_array($GLOBALS['TL_HOOKS']['siteSearchIndexPage'])) {
            foreach ($GLOBALS['TL_HOOKS']['siteSearchIndexPage'] as $callback) {
                System::importStatic($callback[0])->{$callback[1]}($html);
            }
        }

        // Separate the text of adjacent block elements ("<p>a</p><p>b</p>" → "a b")
        $html = str_replace('<', ' <', $html);

        $body = (new Crawler($html))->filterXPath('//body');

        if (0 === $body->count() || !($node = $body->getNode(0)) instanceof \DOMElement) {
            return '';
        }

        $xpath = new \DOMXPath($node->ownerDocument);
        $query = implode('|', array_map(static fn (string $tag): string => './/'.$tag, self::NON_INDEXABLE_ELEMENTS));

        foreach (iterator_to_array($xpath->query($query, $node) ?: []) as $element) {
            $element->parentNode?->removeChild($element);
        }

        $text = trim((string) preg_replace('/\s+/u', ' ', str_replace("\u{00AD}", '', $node->textContent)));

        return mb_substr($text, 0, self::MAX_CONTENT_LENGTH);
    }

    /**
     * @return array<string, mixed>
     */
    private function getPageMeta(Document $document): array
    {
        $jsonLds = $document->extractJsonLdScripts('https://schema.contao.org/', 'Page');

        if (!$jsonLds) {
            $this->throwBecause('No JSON-LD found.');
        }

        // Merge all entries to one meta array (the latter overrides the former)
        return array_merge(...$jsonLds);
    }

    /**
     * @param array<string, mixed> $meta
     */
    private function getTitle(Crawler $crawler, array $meta): string
    {
        // The page title from the JSON-LD has no website title suffix
        if (\is_string($meta['title'] ?? null) && '' !== trim($meta['title'])) {
            return trim($meta['title']);
        }

        $title = $crawler->filterXPath('//head/title');

        return $title->count() ? trim($title->text()) : '';
    }

    private function getLanguage(Crawler $crawler): string
    {
        $html = $crawler->filterXPath('//html[@lang]');
        $language = $html->count() ? (string) $html->attr('lang') : '';

        return preg_match('/^([a-z]{2})(?:[-_]|$)/i', $language, $matches) ? strtolower($matches[1]) : '';
    }

    private function getImage(Crawler $crawler, Document $document): string
    {
        $image = $this->getMetaContent($crawler, ['og:image', 'twitter:image']);

        if ('' === $image) {
            return '';
        }

        if (preg_match('#^https?://#i', $image)) {
            return $image;
        }

        $uri = $document->getUri();

        if (str_starts_with($image, '//')) {
            return $uri->getScheme().':'.$image;
        }

        if (str_starts_with($image, '/')) {
            return $uri->getScheme().'://'.$uri->getAuthority().$image;
        }

        // Relative paths are ambiguous (<base href>), ignore them
        return '';
    }

    /**
     * Returns the content of the first non-empty <meta name|property="…"> tag.
     *
     * @param list<string> $names
     */
    private function getMetaContent(Crawler $crawler, array $names): string
    {
        foreach ($names as $name) {
            $nodes = $crawler->filterXPath(\sprintf('//head/meta[@name="%1$s" or @property="%1$s"]', $name));

            foreach ($nodes as $node) {
                if ($node instanceof \DOMElement && '' !== ($content = trim($node->getAttribute('content')))) {
                    return $content;
                }
            }
        }

        return '';
    }

    private function removeNonIndexableAreas(string $html): string
    {
        $stop = '<!-- indexer::stop -->';
        $continue = '<!-- indexer::continue -->';

        while (false !== ($start = strpos($html, $stop))) {
            $end = strpos($html, $continue, $start);

            if (false === $end) {
                // No matching continue marker: keep the rest (as before)
                break;
            }

            // Handle nested markers
            $current = $start;

            while (false !== ($nested = strpos($html, $stop, $current + \strlen($stop))) && $nested < $end) {
                if (false === ($newEnd = strpos($html, $continue, $end + \strlen($continue)))) {
                    break;
                }

                $end = $newEnd;
                $current = $nested;
            }

            $html = substr($html, 0, $start).substr($html, $end + \strlen($continue));
        }

        return $html;
    }

    /**
     * @return never
     */
    private function throwBecause(string $message, bool $onlyWarning = true): void
    {
        if ($onlyWarning) {
            throw IndexerException::createAsWarning($message);
        }

        throw new IndexerException($message);
    }
}
