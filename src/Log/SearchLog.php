<?php

declare(strict_types=1);

namespace Pdir\SiteSearchBundle\Log;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;

/**
 * Anonymous search log: search terms are counted per day, domain and language. No
 * IP addresses, user IDs or sessions are stored, single searches cannot be traced
 * back to a visitor.
 *
 * The log is the vocabulary for "Did you mean …?", popular and related
 * searches, and the source of the statistics in the back end.
 */
class SearchLog
{
    public const TABLE = 'tl_site_search_log';

    public const MAX_TERM_LENGTH = 100;

    private const BOT_PATTERN = '/bot|crawl|spider|slurp|escargot|contao|preview|headless|curl|wget|python|httpclient|scrapy|lighthouse/i';

    /**
     * @param int $minSearches A term needs this many searches before it is shown to visitors
     *                         (popular, related, "Did you mean"), so single or spammed
     *                         entries do not appear publicly (inlined in HAVING, because
     *                         SQLite compares bound parameters there as strings)
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly bool $enabled = true,
        private readonly int $retentionDays = 90,
        private readonly int $minSearches = 3,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Lowercase, single spaces, 2–100 characters; null if the term is not loggable.
     */
    public static function normalize(string $term): string|null
    {
        $term = trim((string) preg_replace('/\s+/u', ' ', mb_strtolower($term)));

        if (mb_strlen($term) < 2 || mb_strlen($term) > self::MAX_TERM_LENGTH || preg_match('/\p{C}/u', $term)) {
            return null;
        }

        return $term;
    }

    public static function isBot(string|null $userAgent): bool
    {
        return null === $userAgent || '' === trim($userAgent) || 1 === preg_match(self::BOT_PATTERN, $userAgent);
    }

    /**
     * Counts a search (and optionally a click on a hit).
     */
    public function log(string $term, string|null $language, string|null $domain, int $hits, bool $click = false): void
    {
        if (!$this->enabled || null === ($term = self::normalize($term))) {
            return;
        }

        $key = [
            'day' => strtotime('today'),
            'domain' => strtolower((string) $domain),
            'language' => substr(strtolower((string) $language), 0, 2),
            'term' => $term,
        ];

        // Portable upsert: update, insert if missing, update again on a race
        if ($this->increment($key, $hits, $click)) {
            return;
        }

        try {
            $this->connection->insert(self::TABLE, [...$key, 'tstamp' => time(), 'searches' => 1, 'hits' => max(0, $hits), 'clicks' => (int) $click]);
        } catch (UniqueConstraintViolationException) {
            $this->increment($key, $hits, $click);
        }
    }

    /**
     * Deletes entries older than the retention period.
     */
    public function purge(): int
    {
        return (int) $this->connection->executeStatement(
            'DELETE FROM '.self::TABLE.' WHERE day < ?',
            [strtotime('today') - $this->retentionDays * 86400],
        );
    }

    /**
     * Frequent terms with hits, e.g. for the empty search field.
     *
     * @return list<string>
     */
    public function getPopular(string|null $language, string|null $domain, int $limit = 5, int $days = 30): array
    {
        [$where, $params] = $this->scope($language, $domain, $days);

        return array_map(
            'strval',
            $this->connection->fetchFirstColumn(
                'SELECT term FROM '.self::TABLE.' WHERE '.$where.' GROUP BY term HAVING SUM(searches) >= '.$this->minSearches.' AND MAX(hits) > 0 ORDER BY SUM(searches) DESC, term LIMIT '.max(1, $limit),
                $params,
            ),
        );
    }

    /**
     * Frequent terms with hits that contain a word of the term ("heizung" →
     * "heizung wartung", "heizungsbau").
     *
     * @return list<string>
     */
    public function getRelated(string $term, string|null $language, string|null $domain, int $limit = 5, int $days = 90): array
    {
        if (null === ($term = self::normalize($term))) {
            return [];
        }

        $words = array_values(array_filter(array_unique(explode(' ', $term)), static fn (string $word): bool => mb_strlen($word) >= 3));

        if ([] === $words) {
            return [];
        }

        [$where, $params] = $this->scope($language, $domain, $days);
        $platform = $this->connection->getDatabasePlatform();
        $words = \array_slice($words, 0, 5);
        $like = implode(' OR ', array_fill(0, \count($words), "term LIKE ? ESCAPE '!'"));
        $patterns = array_map(static fn (string $word): string => '%'.$platform->escapeStringForLike($word, '!').'%', $words);

        return array_map(
            'strval',
            $this->connection->fetchFirstColumn(
                'SELECT term FROM '.self::TABLE.' WHERE '.$where.' AND term <> ? AND ('.$like.') GROUP BY term HAVING SUM(searches) >= '.$this->minSearches.' AND MAX(hits) > 0 ORDER BY SUM(searches) DESC, term LIMIT '.max(1, $limit),
                [...$params, $term, ...$patterns],
            ),
        );
    }

    /**
     * "Did you mean …?": the most similar frequent term with hits (Levenshtein
     * distance ≤ 1 for short terms, ≤ 2 from 6 characters, ≤ 3 from 12).
     */
    public function getSuggestion(string $term, string|null $language, string|null $domain, int $days = 90): string|null
    {
        if (null === ($term = self::normalize($term))) {
            return null;
        }

        $length = mb_strlen($term);
        $maxDistance = $length >= 12 ? 3 : ($length >= 6 ? 2 : 1);

        [$where, $params] = $this->scope($language, $domain, $days);

        $candidates = $this->connection->fetchAllKeyValue(
            'SELECT term, SUM(searches) AS total FROM '.self::TABLE.' WHERE '.$where.' AND term <> ? GROUP BY term HAVING SUM(searches) >= '.$this->minSearches.' AND MAX(hits) > 0 ORDER BY total DESC LIMIT 5000',
            [...$params, $term],
        );

        $best = null;
        $bestDistance = PHP_INT_MAX;

        foreach ($candidates as $candidate => $total) {
            $candidate = (string) $candidate;

            if (abs(mb_strlen($candidate) - $length) > $maxDistance) {
                continue;
            }

            $distance = self::levenshtein($term, $candidate);

            // Candidates are ordered by frequency: on a tie the more frequent one wins
            if ($distance <= $maxDistance && $distance < $bestDistance) {
                $best = $candidate;
                $bestDistance = $distance;
            }
        }

        return $best;
    }

    /**
     * Figures for the back end.
     *
     * @return array{searches: int, clicks: int, zeroHits: int, terms: int, top: list<array{term: string, searches: int, hits: int, clicks: int}>, noHits: list<array{term: string, searches: int}>}
     */
    public function getStatistics(int $days = 30, string|null $domain = null, int $limit = 25): array
    {
        [$where, $params] = $this->scope(null, $domain, $days);
        $table = self::TABLE;

        $totals = $this->connection->fetchAssociative(
            "SELECT COALESCE(SUM(searches), 0) AS searches, COALESCE(SUM(clicks), 0) AS clicks, COALESCE(SUM(CASE WHEN hits = 0 THEN searches ELSE 0 END), 0) AS zero_hits, COUNT(DISTINCT term) AS terms FROM $table WHERE $where",
            $params,
        ) ?: [];

        $top = $this->connection->fetchAllAssociative(
            "SELECT term, SUM(searches) AS searches, MAX(hits) AS hits, SUM(clicks) AS clicks FROM $table WHERE $where GROUP BY term ORDER BY SUM(searches) DESC, term LIMIT ".max(1, $limit),
            $params,
        );

        // Terms whose latest searches had no hits: ideas for new content
        $noHits = $this->connection->fetchAllAssociative(
            "SELECT term, SUM(searches) AS searches FROM $table WHERE $where GROUP BY term HAVING MAX(hits) = 0 ORDER BY SUM(searches) DESC, term LIMIT ".max(1, $limit),
            $params,
        );

        return [
            'searches' => (int) ($totals['searches'] ?? 0),
            'clicks' => (int) ($totals['clicks'] ?? 0),
            'zeroHits' => (int) ($totals['zero_hits'] ?? 0),
            'terms' => (int) ($totals['terms'] ?? 0),
            'top' => array_map(static fn (array $row): array => ['term' => (string) $row['term'], 'searches' => (int) $row['searches'], 'hits' => (int) $row['hits'], 'clicks' => (int) $row['clicks']], $top),
            'noHits' => array_map(static fn (array $row): array => ['term' => (string) $row['term'], 'searches' => (int) $row['searches']], $noHits),
        ];
    }

    /**
     * Levenshtein distance on characters instead of bytes ("ü" counts as one).
     */
    public static function levenshtein(string $a, string $b): int
    {
        $map = [];

        $encode = static function (string $text) use (&$map): string {
            $result = '';

            foreach (mb_str_split($text) as $char) {
                if (\strlen($char) > 1) {
                    // Map multibyte characters to unused single bytes
                    $char = $map[$char] ??= \chr(128 + \count($map) % 128);
                }

                $result .= $char;
            }

            return $result;
        };

        return levenshtein($encode($a), $encode($b));
    }

    /**
     * @param array{day: int, domain: string, language: string, term: string} $key
     */
    private function increment(array $key, int $hits, bool $click): bool
    {
        return $this->connection->executeStatement(
            'UPDATE '.self::TABLE.' SET searches = searches + 1, clicks = clicks + ?, hits = ?, tstamp = ? WHERE day = ? AND domain = ? AND language = ? AND term = ?',
            [(int) $click, max(0, $hits), time(), $key['day'], $key['domain'], $key['language'], $key['term']],
        ) > 0;
    }

    /**
     * @return array{string, list<int|string>}
     */
    private function scope(string|null $language, string|null $domain, int $days): array
    {
        $where = ['day >= ?'];
        $params = [strtotime('today') - max(0, $days - 1) * 86400];

        if (null !== $language && '' !== $language) {
            $where[] = 'language = ?';
            $params[] = substr(strtolower($language), 0, 2);
        }

        if (null !== $domain && '' !== $domain) {
            $where[] = 'domain = ?';
            $params[] = strtolower($domain);
        }

        return [implode(' AND ', $where), $params];
    }
}
