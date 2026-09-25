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

namespace Uhifadhi\Patrol\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use Uhifadhi\Patrol\Service\PatrolCorridorService;

/**
 * `patrol:coverage:rebuild` — BUFFER THE TRACKS OF COMPLETE PATROLS THAT HAVE
 * NO STORED CORRIDOR, OR A STALE ONE.
 *
 * WHEN IT RUNS. Once after the deploy that brings stored corridors, for every
 * patrol completed before it; and after a patrol type's coverage width
 * changes, so its patrols are buffered again at the new width. The worker
 * buffers a patrol when it completes and the hourly facts run catches up the
 * month it measures, so this is the backfill, not the routine.
 *
 * Then `uhifadhi:facts:rebuild --module=patrols --from=<first month>` files
 * the months the corridors now measure.
 *
 * BATCHED: the entity manager is cleared after every `--batch-size` patrols,
 * so a history of years is held one batch at a time. IDEMPOTENT: a second run
 * finds nothing missing or stale and buffers nothing; `--all` buffers every
 * complete patrol with a track again.
 *
 * @see https://symfony.com/doc/current/console.html — #[AsCommand] names the command; a reusable bundle is not autoconfigured, so the bundle adds the 'console.command' tag by hand
 * @see vendor/symfony/console/DependencyInjection/AddConsoleCommandPass.php — the tag carries no name of its own; the attribute's is read
 */
#[AsCommand(
    name: 'patrol:coverage:rebuild',
    description: 'Buffer the tracks of complete patrols that have no stored coverage corridor, or a stale one',
)]
final class CoverageRebuildCommand extends Command
{
    public function __construct(
        private readonly PatrolCorridorService $corridors,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('all', null, InputOption::VALUE_NONE, 'Buffer every complete patrol with a track again, not only the missing and stale ones')
            ->addOption('batch-size', null, InputOption::VALUE_REQUIRED, 'Patrols buffered between two clears of the entity manager', (string) PatrolCorridorService::BATCH)
            ->setHelp(<<<'HELP'
                Buffers each complete patrol's track once and stores it, at its type's width
                and at the module's one width, so no coverage figure buffers a track again:

                    php bin/console patrol:coverage:rebuild
                    php bin/console patrol:coverage:rebuild --all --batch-size=50

                Run it once after the deploy that brings stored corridors, and after a patrol
                type's coverage width changes. Then file the months it measures:

                    php bin/console uhifadhi:facts:rebuild --module=patrols --from=2026-01
                HELP);
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        $size = $input->getOption('batch-size');
        if (!\is_string($size) || 1 !== preg_match('/^[1-9]\d*$/', $size)) {
            $io->error('--batch-size is a whole number of patrols, 1 or more.');

            return Command::FAILURE;
        }

        $buffered = $this->corridors->catchUp(
            all: true === $input->getOption('all'),
            batchSize: (int) $size,
            progress: static function (int $done) use ($io): void {
                $io->writeln(\sprintf('  %d %s buffered', $done, 1 === $done ? 'patrol' : 'patrols'));
            },
        );

        $io->success(\sprintf('%d %s buffered.', $buffered, 1 === $buffered ? 'patrol' : 'patrols'));

        return Command::SUCCESS;
    }
}
