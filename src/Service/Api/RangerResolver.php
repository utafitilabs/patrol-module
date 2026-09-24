<?php

declare(strict_types=1);

/*
 * This file is part of the UhifadhiLabs Patrol Module.
 *
 * (c) Ezekiel Mjema <https://github.com/eemjema>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Uhifadhi\Patrol\Service\Api;

use Doctrine\ORM\EntityManagerInterface;
use Uhifadhi\Contracts\Entity\UserInterface;

/**
 * Turns the ranger ids a phone sends back into the people they name.
 *
 * The field app knows a team as the ids it received from `/api/areas/mine`
 * (a service number, or an email address for staff who have none), so a patrol
 * arrives with `["sl-0142", "nk-0088"]`. The module stores BOTH: the ids, because
 * they are the identity and will be sent again, and the resolved names, because
 * that is what the web module has always shown.
 *
 * An id that resolves to nobody is kept, not dropped. A team member who has
 * since left the organization was still on that patrol, and silently shortening
 * the roster would misreport who was in the field that day.
 */
final class RangerResolver
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * The users behind these ids, keyed by the id that found them. Ids that
     * match nobody are simply absent from the result.
     *
     * @param list<string> $rangerIds
     *
     * @return array<string, UserInterface>
     */
    public function resolve(array $rangerIds): array
    {
        if ([] === $rangerIds) {
            return [];
        }

        $needles = array_values(array_unique(array_map(
            static fn (string $id): string => strtolower(trim($id)),
            $rangerIds,
        )));

        /** @var list<UserInterface> $users */
        $users = $this->entityManager->createQueryBuilder()
            ->select('u')
            ->from(UserInterface::class, 'u')
            ->where('LOWER(u.rangerCode) IN (:needles)')
            ->orWhere('LOWER(u.email) IN (:needles)')
            ->setParameter('needles', $needles)
            ->getQuery()
            ->getResult();

        $byId = [];
        foreach ($users as $user) {
            $code = $user->getRangerCode();
            if (null !== $code) {
                $byId[strtolower($code)] = $user;
            }
            $email = $user->getEmail();
            if (null !== $email) {
                $byId[strtolower($email)] = $user;
            }
        }

        $resolved = [];
        foreach ($rangerIds as $id) {
            $key = strtolower(trim($id));
            if (isset($byId[$key])) {
                $resolved[$id] = $byId[$key];
            }
        }

        return $resolved;
    }

    /**
     * The team as the web module renders it: real names where we know them,
     * the raw id where we do not — never a silently shorter list.
     *
     * @param list<string> $rangerIds
     */
    public function describe(array $rangerIds): ?string
    {
        if ([] === $rangerIds) {
            return null;
        }

        $resolved = $this->resolve($rangerIds);

        $names = array_map(
            static fn (string $id): string => ($resolved[$id] ?? null)?->getFullName() ?: $id,
            $rangerIds,
        );

        $sentence = implode(', ', array_filter($names, static fn (string $name): bool => '' !== $name));

        // The column is 255; a very large team is truncated rather than
        // rejected — the ids are stored in full either way.
        return '' === $sentence ? null : mb_substr($sentence, 0, 255);
    }
}
