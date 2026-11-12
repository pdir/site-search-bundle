<?php

declare(strict_types=1);

namespace Pdir\SiteSearchBundle\Source\Contao;

use Contao\ContentModel;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Routing\ContentUrlGenerator;
use Contao\FilesModel;
use Contao\NewsArchiveModel;
use Contao\NewsModel;
use Contao\PageModel;
use Contao\StringUtil;
use Pdir\SiteSearchBundle\Source\RecordProviderInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * News entries with their own reader page (source "default"), straight from the
 * database. Enabled via "pdir_site_search.sources.news: true".
 *
 * Unlike the crawler, changes are in the index as soon as an entry is saved, and
 * news are marked as source "news" (e.g. for an own section in the results).
 */
class NewsDocumentProvider implements RecordProviderInterface
{
    /**
     * @var array<int, PageModel|false>
     */
    private array $pageCache = [];

    public function __construct(
        private readonly ContaoFramework $framework,
        private readonly ContentUrlGenerator $urlGenerator,
        private readonly LoggerInterface|null $logger = null,
    ) {
    }

    public function getSource(): string
    {
        return 'news';
    }

    public function getTable(): string
    {
        return 'tl_news';
    }

    public function getDocuments(): iterable
    {
        $this->framework->initialize();

        $archives = $this->framework->getAdapter(NewsArchiveModel::class)->findAll() ?? [];

        foreach ($archives as $archive) {
            $entries = $this->framework->getAdapter(NewsModel::class)->findPublishedByPids([(int) $archive->id]) ?? [];

            foreach ($entries as $news) {
                if (null !== ($document = $this->createDocument($news, $archive))) {
                    yield $document;
                }
            }
        }
    }

    public function getDocument(int $id): array|null
    {
        $this->framework->initialize();

        $news = $this->framework->getAdapter(NewsModel::class)->findById($id);

        if (!$news instanceof NewsModel || !$this->isPublished($news)) {
            return null;
        }

        $archive = $this->framework->getAdapter(NewsArchiveModel::class)->findById((int) $news->pid);

        return $archive instanceof NewsArchiveModel ? $this->createDocument($news, $archive) : null;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function createDocument(NewsModel $news, NewsArchiveModel $archive): array|null
    {
        // Links to an external page, an article or another page have no own content
        if ('default' !== ($news->source ?: 'default') || str_contains((string) $news->robots, 'noindex')) {
            return null;
        }

        $page = $this->getPage((int) $archive->jumpTo);

        if (null === $page) {
            return null;
        }

        try {
            $url = $this->urlGenerator->generate($news, [], UrlGeneratorInterface::ABSOLUTE_URL);
        } catch (\Throwable $e) {
            $this->logger?->warning(\sprintf('Site search: no URL for news ID %s: %s', $news->id, $e->getMessage()));

            return null;
        }

        $groups = [];
        $protected = false;

        foreach ([$archive, $page] as $model) {
            if ($model->protected) {
                $protected = true;
                $groups = [...$groups, ...array_map('intval', StringUtil::deserialize($model->groups, true))];
            }
        }

        return [
            'id' => (int) $news->id,
            'url' => $url,
            'title' => (string) ($news->pageTitle ?: $news->headline),
            'description' => (string) ($news->description ?: $news->teaser),
            'content' => $this->getContent($news),
            'image' => $this->getImage($news, $url),
            'language' => (string) $page->rootLanguage,
            'protected' => $protected,
            'groups' => $groups,
            'date' => (int) $news->date,
            'archive' => (string) $archive->title,
        ];
    }

    private function isPublished(NewsModel $news): bool
    {
        $time = time();

        return $news->published
            && ('' === (string) $news->start || (int) $news->start <= $time)
            && ('' === (string) $news->stop || (int) $news->stop > $time);
    }

    private function getPage(int $id): PageModel|null
    {
        if (!isset($this->pageCache[$id])) {
            $page = $this->framework->getAdapter(PageModel::class)->findPublishedById($id);
            $page?->loadDetails();

            // Pages excluded from the search are not indexed by the crawler either
            $this->pageCache[$id] = $page instanceof PageModel && !$page->noSearch ? $page : false;
        }

        return $this->pageCache[$id] ?: null;
    }

    private function getContent(NewsModel $news): string
    {
        $parts = [(string) $news->subheadline, (string) $news->teaser];
        $elements = $this->framework->getAdapter(ContentModel::class)->findPublishedByPidAndTable((int) $news->id, 'tl_news') ?? [];

        foreach ($elements as $element) {
            foreach (['headline', 'text', 'html'] as $field) {
                $value = $element->{$field};

                if ('headline' === $field) {
                    $value = StringUtil::deserialize($value, true)['value'] ?? '';
                }

                if (\is_string($value) && '' !== $value) {
                    $parts[] = $value;
                }
            }
        }

        return implode(' ', $parts);
    }

    private function getImage(NewsModel $news, string $url): string
    {
        if (!$news->addImage || !$news->singleSRC) {
            return '';
        }

        $file = $this->framework->getAdapter(FilesModel::class)->findByUuid($news->singleSRC);

        if (!$file instanceof FilesModel) {
            return '';
        }

        $base = parse_url($url, PHP_URL_SCHEME).'://'.parse_url($url, PHP_URL_HOST).(($port = parse_url($url, PHP_URL_PORT)) ? ':'.$port : '');

        return $base.'/'.implode('/', array_map('rawurlencode', explode('/', (string) $file->path)));
    }
}
