<?php

declare(strict_types=1);

namespace Pdir\SiteSearchBundle\Engine;

use Pdir\SiteSearchBundle\Search\LoupeIndexFactory;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * Creates the engine from the DSN in pdir_site_search.engine:
 *
 * - loupe:// (default, uses index_path)
 * - meilisearch://<key>@127.0.0.1:7700?index=site_search
 * - typesense://<key>@127.0.0.1:8108?collection=site_search
 *
 * The API key can also be passed as "?key=…"; "tls=1" switches to HTTPS.
 * Environment variables work as usual: engine: '%env(SITE_SEARCH_ENGINE)%'.
 */
class EngineFactory
{
    public const DEFAULT_INDEX = 'pdir_site_search';

    public function __construct(
        private readonly LoupeIndexFactory $loupeIndexFactory,
        private readonly HttpClientInterface|null $httpClient = null,
    ) {
    }

    public function create(string $dsn): SearchEngineInterface
    {
        $dsn = trim($dsn);
        $scheme = strtolower((string) strstr($dsn, '://', true));

        if ('' === $dsn || 'loupe' === $scheme) {
            return new LoupeEngine($this->loupeIndexFactory);
        }

        $parts = parse_url($dsn);

        if (false === $parts || !isset($parts['host'])) {
            throw new \InvalidArgumentException(\sprintf('Invalid search engine DSN "%s".', preg_replace('#//[^@/]*@#', '//***@', $dsn)));
        }

        parse_str($parts['query'] ?? '', $query);

        $key = rawurldecode((string) ($parts['user'] ?? $query['key'] ?? ''));
        $tls = filter_var($query['tls'] ?? false, FILTER_VALIDATE_BOOL);
        $name = (string) ($query['index'] ?? $query['collection'] ?? self::DEFAULT_INDEX);

        if (!preg_match('/^[a-zA-Z0-9_-]{1,100}$/', $name)) {
            throw new \InvalidArgumentException(\sprintf('Invalid index name "%s".', $name));
        }

        $httpClient = $this->httpClient ?? HttpClient::create();

        return match ($scheme) {
            'meilisearch' => new MeilisearchEngine($httpClient, $this->baseUrl($parts, $tls, 7700), $key, $name),
            'typesense' => new TypesenseEngine($httpClient, $this->baseUrl($parts, $tls, 8108), $key, $name),
            default => throw new \InvalidArgumentException(\sprintf('Unsupported search engine "%s" (supported: loupe, meilisearch, typesense).', $scheme)),
        };
    }

    /**
     * @param array<string, mixed> $parts
     */
    private function baseUrl(array $parts, bool $tls, int $defaultPort): string
    {
        return ($tls ? 'https' : 'http').'://'.$parts['host'].':'.($parts['port'] ?? $defaultPort).rtrim((string) ($parts['path'] ?? ''), '/');
    }
}
