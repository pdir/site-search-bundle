<?php

declare(strict_types=1);

namespace Pdir\SiteSearchBundle\Command;

use Pdir\SiteSearchBundle\Engine\SearchEngineInterface;
use Pdir\SiteSearchBundle\Search\SourceIndexer;
use Pdir\SiteSearchBundle\Source\SourceRegistry;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(
    name: 'pdir:site-search:reindex',
    description: 'Rebuilds the site search index: documents of the providers (news, …) and pages via the Contao crawler.',
)]
class ReindexCommand extends Command
{
    public function __construct(
        private readonly SearchEngineInterface $engine,
        private readonly SourceIndexer $sourceIndexer,
        private readonly SourceRegistry $registry,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('source', 's', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'Only update these sources (e.g. "news", "page" = crawler); the rest of the index is kept')
            ->addOption('list', null, InputOption::VALUE_NONE, 'List the available sources')
            ->addOption('no-crawl', null, InputOption::VALUE_NONE, 'Do not start the crawler')
            ->addOption('concurrency', 'c', InputOption::VALUE_REQUIRED, 'Concurrent requests of the crawler', '5')
            ->addOption('max-depth', null, InputOption::VALUE_REQUIRED, 'Maximum crawl depth (0 = no limit)', '3')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $available = [SourceRegistry::PAGE_SOURCE, ...array_keys($this->registry->all())];

        if ($input->getOption('list')) {
            $io->listing($available);

            return Command::SUCCESS;
        }

        /** @var list<string> $sources */
        $sources = array_values(array_unique(array_filter(array_map('trim', explode(',', implode(',', $input->getOption('source')))))));

        if ($unknown = array_diff($sources, $available)) {
            $io->error(\sprintf('Unknown source(s) "%s". Available: %s', implode('", "', $unknown), implode(', ', $available)));

            return Command::INVALID;
        }

        if ([] === $sources) {
            // Full rebuild: start with an empty index (also applies schema changes)
            $this->engine->drop();
            $info = $this->engine->getInfo();
            $io->success(\sprintf('Deleted the site search index (%s, %s).', $info['engine'], $info['location']));
            $sources = $available;
        }

        $status = Command::SUCCESS;

        // Providers first: the crawler then skips URLs that a provider already indexed
        foreach ($sources as $source) {
            if (SourceRegistry::PAGE_SOURCE !== $source && !$this->reindexSource($source, $io)) {
                $status = Command::FAILURE;
            }
        }

        if (\in_array(SourceRegistry::PAGE_SOURCE, $sources, true)) {
            $crawlStatus = $this->crawl($input, $output, $io);
            $status = Command::SUCCESS === $status ? $crawlStatus : $status;
        }

        return $status;
    }

    private function reindexSource(string $source, SymfonyStyle $io): bool
    {
        $io->section(\sprintf('Source "%s"', $source));

        $progress = $io->createProgressBar();
        $progress->start();

        try {
            $result = $this->sourceIndexer->reindex(
                $this->registry->get($source),
                static function (int $indexed) use ($progress): void {
                    $progress->setProgress($indexed);
                },
            );
        } catch (\Throwable $e) {
            $progress->clear();
            $io->error(\sprintf('Could not index the source "%s": %s', $source, $e->getMessage()));

            return false;
        }

        $progress->finish();
        $io->newLine(2);

        foreach (\array_slice($result['errors'], 0, 20, true) as $url => $message) {
            $io->warning($url.': '.$message);
        }

        $io->text(\sprintf('%d documents indexed, %d removed, %d errors.', $result['indexed'], $result['removed'], \count($result['errors'])));

        return true;
    }

    private function crawl(InputInterface $input, OutputInterface $output, SymfonyStyle $io): int
    {
        if ($input->getOption('no-crawl')) {
            $io->note('Run "contao:crawl --subscribers=search-index" to index the pages.');

            return Command::SUCCESS;
        }

        $application = $this->getApplication();

        if (null === $application || !$application->has('contao:crawl')) {
            $io->warning('The command "contao:crawl" is not available. Index the pages via the Contao back end (Maintenance → Crawler).');

            return Command::SUCCESS;
        }

        $io->section('Pages (Contao crawler)');

        // The search-index subscriber passes every crawled page to all indexers,
        // including this bundle's indexer
        return $application->find('contao:crawl')->run(
            new ArrayInput([
                '--subscribers' => ['search-index'],
                '--concurrency' => (string) $input->getOption('concurrency'),
                '--max-depth' => (string) $input->getOption('max-depth'),
            ]),
            $output,
        );
    }
}
