<?php

declare(strict_types=1);

namespace Pdir\SiteSearchBundle\Search;

use Contao\FrontendUser;

final class MemberGroups
{
    /**
     * Group IDs of a logged-in member; empty for guests and back end users.
     *
     * @return list<int>
     */
    public static function of(mixed $user): array
    {
        if (!$user instanceof FrontendUser) {
            return [];
        }

        return array_values(array_filter(array_map('intval', (array) $user->groups), static fn (int $id): bool => $id > 0));
    }
}
