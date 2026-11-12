<?php

declare(strict_types=1);

namespace Pdir\SiteSearchBundle\Source;

/**
 * All registered document providers, indexed by their source name.
 */
class SourceRegistry
{
    public const PAGE_SOURCE = 'page';

    /**
     * @var array<string, DocumentProviderInterface>
     */
    private array $providers = [];

    /**
     * @param iterable<DocumentProviderInterface> $providers
     */
    public function __construct(iterable $providers = [])
    {
        foreach ($providers as $provider) {
            $this->add($provider);
        }
    }

    public function add(DocumentProviderInterface $provider): void
    {
        $source = $provider->getSource();

        if (!self::isValidName($source) || self::PAGE_SOURCE === $source) {
            throw new \InvalidArgumentException(\sprintf('Invalid source name "%s" of %s.', $source, $provider::class));
        }

        if (isset($this->providers[$source])) {
            throw new \InvalidArgumentException(\sprintf('The source "%s" is registered twice (%s, %s).', $source, $this->providers[$source]::class, $provider::class));
        }

        $this->providers[$source] = $provider;
    }

    public function has(string $source): bool
    {
        return isset($this->providers[$source]);
    }

    public function get(string $source): DocumentProviderInterface
    {
        return $this->providers[$source] ?? throw new \InvalidArgumentException(\sprintf('Unknown source "%s".', $source));
    }

    /**
     * @return array<string, DocumentProviderInterface>
     */
    public function all(): array
    {
        return $this->providers;
    }

    /**
     * @return list<RecordProviderInterface>
     */
    public function getRecordProviders(string $table): array
    {
        return array_values(array_filter(
            $this->providers,
            static fn (DocumentProviderInterface $provider): bool => $provider instanceof RecordProviderInterface && $provider->getTable() === $table,
        ));
    }

    /**
     * Source names are used in filter expressions, so only allow a safe subset.
     */
    public static function isValidName(string $source): bool
    {
        return 1 === preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $source);
    }
}
