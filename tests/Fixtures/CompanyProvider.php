<?php

declare(strict_types=1);

namespace Pdir\SiteSearchBundle\Tests\Fixtures;

use Pdir\SiteSearchBundle\Source\RecordProviderInterface;

/**
 * Example provider as a host project would write it.
 */
class CompanyProvider implements RecordProviderInterface
{
    /**
     * @var array<int, array<string, mixed>>
     */
    public array $companies = [
        1 => ['name' => 'Müller <b>GmbH</b>', 'description' => '<p>Heizungsbau &amp; Sanitär</p><p>seit 1950</p> Notdienst', 'category' => 'Heizung & Sanitär', 'website' => 'https://mueller.example'],
        2 => ['name' => 'Schmidt KG', 'description' => 'Heizungsbau und Solar', 'category' => 'Heizung & Sanitär', 'website' => null],
        3 => ['name' => 'Interner Eintrag', 'description' => 'Heizungsbau Intern', 'protected' => true, 'groups' => ['7']],
    ];

    public function getSource(): string
    {
        return 'company';
    }

    public function getTable(): string
    {
        return 'tl_company';
    }

    public function getDocuments(): iterable
    {
        foreach (array_keys($this->companies) as $id) {
            if (null !== ($document = $this->getDocument($id))) {
                yield $document;
            }
        }
    }

    public function getDocument(int $id): array|null
    {
        $company = $this->companies[$id] ?? null;

        if (null === $company || false === ($company['published'] ?? true)) {
            return null;
        }

        return [
            'id' => $id,
            'url' => $company['url'] ?? 'https://www.example.com/de/firmen.html#company-'.$id,
            'title' => $company['name'],
            'content' => $company['description'] ?? '',
            'language' => 'de',
            'protected' => $company['protected'] ?? false,
            'groups' => $company['groups'] ?? [],
            'category' => $company['category'] ?? '',
            'website' => $company['website'] ?? null,
        ];
    }
}
