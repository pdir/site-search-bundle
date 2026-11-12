<?php

declare(strict_types=1);

namespace Pdir\SiteSearchBundle\Tests\Source\Contao;

use Contao\ContentModel;
use Contao\CoreBundle\Framework\Adapter;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Routing\ContentUrlGenerator;
use Contao\FilesModel;
use Contao\Model;
use Contao\NewsArchiveModel;
use Contao\NewsModel;
use Contao\PageModel;
use Pdir\SiteSearchBundle\Source\Contao\NewsDocumentProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class NewsDocumentProviderTest extends TestCase
{
    public function testCreatesDocumentFromNewsEntry(): void
    {
        $document = $this->provider()->getDocument(5);

        $this->assertSame(
            [
                'id' => 5,
                'url' => 'https://www.example.com/de/news/wartung.html',
                'title' => 'Heizung warten',
                // HTML is converted to text by the SourceIndexer
                'description' => '<p>Kurz erklärt</p>',
                'content' => 'Unterzeile <p>Kurz erklärt</p> Abschnitt <p>Der Wartungsvertrag</p>',
                'image' => 'https://www.example.com/files/news/b%C3%BCro%201.jpg',
                'language' => 'de',
                'protected' => false,
                'groups' => [],
                'date' => 1700000000,
                'archive' => 'Ratgeber',
            ],
            $document,
        );
    }

    public function testUsesPageTitleAndDescriptionIfSet(): void
    {
        $document = $this->provider(['pageTitle' => 'SEO-Titel', 'description' => 'SEO-Beschreibung'])->getDocument(5);

        $this->assertSame('SEO-Titel', $document['title']);
        $this->assertSame('SEO-Beschreibung', $document['description']);
    }

    public function testSkipsUnpublishedExternalAndNoindexEntries(): void
    {
        $this->assertNull($this->provider(['published' => false])->getDocument(5));
        $this->assertNull($this->provider(['start' => (string) (time() + 3600)])->getDocument(5));
        $this->assertNull($this->provider(['source' => 'external'])->getDocument(5));
        $this->assertNull($this->provider(['robots' => 'noindex,follow'])->getDocument(5));
        $this->assertNull($this->provider([], [], ['noSearch' => true])->getDocument(5));
    }

    public function testCollectsGroupsOfProtectedArchiveAndPage(): void
    {
        $document = $this->provider([], ['protected' => true, 'groups' => serialize(['3'])], ['protected' => true, 'groups' => serialize([4])])->getDocument(5);

        $this->assertTrue($document['protected']);
        $this->assertSame([3, 4], $document['groups']);
    }

    public function testGetDocumentsYieldsPublishedEntriesOfAllArchives(): void
    {
        $documents = iterator_to_array($this->provider()->getDocuments(), false);

        $this->assertCount(1, $documents);
        $this->assertSame(5, $documents[0]['id']);
    }

    /**
     * @param array<string, mixed> $news
     * @param array<string, mixed> $archive
     * @param array<string, mixed> $page
     */
    private function provider(array $news = [], array $archive = [], array $page = []): NewsDocumentProvider
    {
        $newsModel = $this->model(NewsModel::class, [
            'id' => 5,
            'pid' => 1,
            'headline' => 'Heizung warten',
            'subheadline' => 'Unterzeile',
            'teaser' => '<p>Kurz erklärt</p>',
            'source' => 'default',
            'published' => true,
            'start' => '',
            'stop' => '',
            'date' => 1700000000,
            'addImage' => true,
            'singleSRC' => 'uuid',
            ...$news,
        ]);

        $archiveModel = $this->model(NewsArchiveModel::class, ['id' => 1, 'title' => 'Ratgeber', 'jumpTo' => 10, ...$archive]);
        $pageModel = $this->model(PageModel::class, ['id' => 10, 'rootLanguage' => 'de', ...$page]);
        $element = $this->model(ContentModel::class, ['headline' => serialize(['unit' => 'h2', 'value' => 'Abschnitt']), 'text' => '<p>Der Wartungsvertrag</p>']);
        $file = $this->model(FilesModel::class, ['path' => 'files/news/büro 1.jpg']);

        $framework = $this->createMock(ContaoFramework::class);
        $framework
            ->method('getAdapter')
            ->willReturnMap([
                [NewsModel::class, $this->adapter(['findById' => $newsModel, 'findPublishedByPids' => [$newsModel]])],
                [NewsArchiveModel::class, $this->adapter(['findById' => $archiveModel, 'findAll' => [$archiveModel]])],
                [PageModel::class, $this->adapter(['findPublishedById' => $pageModel])],
                [ContentModel::class, $this->adapter(['findPublishedByPidAndTable' => [$element]])],
                [FilesModel::class, $this->adapter(['findByUuid' => $file])],
            ])
        ;

        $urlGenerator = $this->createMock(ContentUrlGenerator::class);
        $urlGenerator
            ->method('generate')
            ->with($newsModel, [], UrlGeneratorInterface::ABSOLUTE_URL)
            ->willReturn('https://www.example.com/de/news/wartung.html')
        ;

        return new NewsDocumentProvider($framework, $urlGenerator);
    }

    /**
     * @template T of Model
     *
     * @param class-string<T>      $class
     * @param array<string, mixed> $data
     *
     * @return T&MockObject
     */
    private function model(string $class, array $data): Model
    {
        $model = $this->createMock($class);
        $model
            ->method('__get')
            ->willReturnCallback(static fn (string $key): mixed => $data[$key] ?? null)
        ;
        $model
            ->method('__isset')
            ->willReturnCallback(static fn (string $key): bool => isset($data[$key]))
        ;

        return $model;
    }

    /**
     * @param array<string, mixed> $methods
     *
     * @return Adapter<object>
     */
    private function adapter(array $methods): Adapter
    {
        /** @extends Adapter<object> */
        return new class($methods) extends Adapter {
            /**
             * @param array<string, mixed> $methods
             */
            public function __construct(private readonly array $methods)
            {
            }

            /**
             * @param array<mixed> $arguments
             */
            public function __call(string $name, array $arguments): mixed
            {
                return $this->methods[$name] ?? null;
            }
        };
    }
}
