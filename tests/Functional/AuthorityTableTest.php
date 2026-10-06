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

namespace Uhifadhi\Patrol\Tests\Functional;

use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Uid\Uuid;
use Uhifadhi\Bundle\AreaBundle\Entity\AreaOfInterest;
use Uhifadhi\Bundle\RegistryBundle\Service\AreaModuleService;
use Uhifadhi\Bundle\RegistryBundle\Service\RegistrySyncService;
use Uhifadhi\Bundle\ShellBundle\Widget\Entity\WidgetCustomPreset;
use Uhifadhi\Bundle\ShellBundle\Widget\Model\WidgetDom;
use Uhifadhi\Bundle\ShellBundle\Widget\Service\WidgetEndpoint;
use Uhifadhi\Bundle\TeamBundle\Deletion\DeletionPage;
use Uhifadhi\Bundle\TeamBundle\Entity\User;
use Uhifadhi\Patrol\Controller\ObservationAmendmentController;
use Uhifadhi\Patrol\Controller\PatrolHoldController;
use Uhifadhi\Patrol\Controller\PatrolRecordController;
use Uhifadhi\Patrol\Controller\PatrolSettingsController;
use Uhifadhi\Patrol\Controller\PatrolTaxonomyController;
use Uhifadhi\Patrol\Controller\PatrolVocabularyController;
use Uhifadhi\Patrol\Entity\Observation;
use Uhifadhi\Patrol\Entity\Patrol;
use Uhifadhi\Patrol\Entity\PatrolType;
use Uhifadhi\Patrol\Enum\PatrolSourceEnum;
use Uhifadhi\Patrol\Enum\PatrolStatusEnum;
use Uhifadhi\Patrol\Module\PatrolModuleProvider;
use Uhifadhi\Patrol\Repository\PatrolTypeRepository;
use Uhifadhi\Patrol\Service\PatrolVocabularyService;
use Uhifadhi\Patrol\Service\TaxonomyAdminService;
use Uhifadhi\Patrol\Tests\Integration\TestKernel;
use Uhifadhi\Patrol\Widget\PatrolWidgets;
use Uhifadhi\Test\Authority\Probe;
use Uhifadhi\Test\Authority\World;
use Uhifadhi\Test\AuthorityTableTestCase;

/**
 * THE PATROL MODULE'S AUTHORITY TABLE: every route this module mounts, called
 * as every kind of person, against the reviewed table beside it.
 *
 *     UHIFADHI_RECORD_AUTHORITY_TABLE=1 vendor/bin/phpunit tests/Functional/AuthorityTableTest.php
 *
 * The kernel boots its authority environment: the core's voters alone, and a
 * sign-in page for somebody signed out.
 *
 * The records the probes act on are Kilimani's: a patrol the colleague led,
 * with a track and an observation; a discarded patrol, the one a hold acts on;
 * the area's patrol types; an observation kind with a subcategory, and a
 * retired kind and subcategory to bring back; and the colleague's own custom
 * layout of this module's dashboard.
 */
final class AuthorityTableTest extends AuthorityTableTestCase
{
    private string $patrol = '';
    private string $patrolRef = '';
    private string $discarded = '';
    private string $observation = '';
    private string $type = '';
    private string $kind = '';
    private string $retiredKind = '';
    private string $subcategory = '';
    private string $retiredSubcategory = '';
    private string $preset = '';
    private string $holdToken = '';
    private string $amendToken = '';

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    /**
     * @param array<string, mixed> $options
     */
    protected static function createKernel(array $options = []): KernelInterface
    {
        return parent::createKernel(['environment' => TestKernel::AUTHORITY_ENVIRONMENT] + $options);
    }

    protected static function tableFile(): string
    {
        return __DIR__.'/authority-table.md';
    }

    protected static function scope(): string
    {
        return 'the patrol module';
    }

    protected static function generator(): string
    {
        return 'tests/Functional/AuthorityTableTest.php';
    }

    protected static function controllerNamespace(): string
    {
        return 'Uhifadhi\\Patrol\\Controller\\';
    }

    protected function notSavableHere(): array
    {
        return [
            'POST patrol_widgets_save' => "it edits the sender's own layout, and nobody here is on one",
        ];
    }

    protected function arrange(World $world): void
    {
        $em = $this->em();

        $sync = static::getContainer()->get('test_public.'.RegistrySyncService::class);
        self::assertInstanceOf(RegistrySyncService::class, $sync);
        $sync->sync();
        $modules = static::getContainer()->get('test_public.'.AreaModuleService::class);
        self::assertInstanceOf(AreaModuleService::class, $modules);

        $vocabulary = static::getContainer()->get('test_public.'.PatrolVocabularyService::class);
        self::assertInstanceOf(PatrolVocabularyService::class, $vocabulary);
        foreach ([$world->kilimani, $world->tambarare] as $uuid) {
            $area = $em->getRepository(AreaOfInterest::class)->findOneBy(['uuid' => $uuid]);
            self::assertInstanceOf(AreaOfInterest::class, $area);
            $modules->install($area, PatrolModuleProvider::SLUG);
            $vocabulary->seedTypes($area);
        }

        $kilimani = $em->getRepository(AreaOfInterest::class)->findOneBy(['uuid' => $world->kilimani]);
        $member = $em->getRepository(User::class)->findOneBy(['uuid' => $world->member]);
        self::assertInstanceOf(AreaOfInterest::class, $kilimani);
        self::assertInstanceOf(User::class, $member);

        $types = static::getContainer()->get('test_public.'.PatrolTypeRepository::class);
        self::assertInstanceOf(PatrolTypeRepository::class, $types);
        $type = $types->findByAreaActive($kilimani)[0] ?? null;
        self::assertInstanceOf(PatrolType::class, $type);

        $patrol = new Patrol($kilimani, $type)
            ->setLead($member)
            ->setSource(PatrolSourceEnum::Gpx)
            ->setStatus(PatrolStatusEnum::Complete)
            ->setStartedAt(new \DateTimeImmutable('-2 days 06:00'))
            ->setEndedAt(new \DateTimeImmutable('-2 days 11:00'))
            ->setDistanceKm(9.5)
            ->setTrack('{"type":"LineString","coordinates":[[37.05,-2.85],[37.1,-2.8],[37.15,-2.75]]}');
        $observation = new Observation($patrol, 'maintenance')
            ->setNote('A culvert washed out on the ridge track.')
            ->setPosition('{"type":"Point","coordinates":[37.1,-2.8]}')
            ->setLoggedAt(new \DateTimeImmutable('-2 days 07:30'))
            ->setRecordedBy($member);
        $discarded = new Patrol($kilimani, $type)
            ->setLead($member)
            ->setSource(PatrolSourceEnum::Manual)
            ->setStartedAt(new \DateTimeImmutable('-1 day 06:00'))
            ->discard('Started by mistake.');
        $preset = new WidgetCustomPreset(PatrolWidgets::SURFACE, $member, $kilimani->getUuid(), 'Morning');

        foreach ([$patrol, $observation, $discarded, $preset] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        $taxonomy = static::getContainer()->get('test_public.'.TaxonomyAdminService::class);
        self::assertInstanceOf(TaxonomyAdminService::class, $taxonomy);
        $kind = $taxonomy->createKind($kilimani, 'Fence damage');
        $subcategory = $taxonomy->createSubcategory($kind, 'Wire cut');
        $retiredSubcategory = $taxonomy->deactivateSubcategory($taxonomy->createSubcategory($kind, 'Post down'));
        $retiredKind = $taxonomy->deactivateKind($taxonomy->createKind($kilimani, 'Signage'));

        $this->patrol = $patrol->getUuid()->toRfc4122();
        $this->patrolRef = $patrol->getRef();
        $this->discarded = $discarded->getUuid()->toRfc4122();
        $this->observation = $observation->getUuid()->toRfc4122();
        $this->type = $type->getUuid()->toRfc4122();
        $this->kind = $kind->getUuid()->toRfc4122();
        $this->retiredKind = $retiredKind->getUuid()->toRfc4122();
        $this->subcategory = $subcategory->getUuid()->toRfc4122();
        $this->retiredSubcategory = $retiredSubcategory->getUuid()->toRfc4122();
        $this->preset = (string) $preset->getUuidString();
        $this->holdToken = PatrolHoldController::csrfTokenId($discarded);
        $this->amendToken = ObservationAmendmentController::csrfTokenId($observation);
    }

    protected function probes(World $world): array
    {
        $area = ['uuid' => $world->kilimani];
        $patrol = $area + ['patrol' => $this->patrol];
        $observation = $patrol + ['observation' => $this->observation];
        $taxonomy = PatrolTaxonomyController::CSRF_TOKEN_ID;
        $vocabulary = PatrolVocabularyController::CSRF_TOKEN_ID;
        $widgets = WidgetEndpoint::csrfTokenId(PatrolWidgets::SURFACE, Uuid::fromString($world->kilimani));
        $mine = $area + ['presetUuid' => $this->preset];

        return [
            Probe::get('patrol_dashboard', $area),
            Probe::get('patrol_list', $area),
            Probe::get('patrol_calendar', $area),
            Probe::get('patrol_widgets', $area),
            Probe::get('patrol_export', $area + ['_format' => 'csv']),
            Probe::get('patrol_types', $area),
            Probe::get('patrol_kinds', $area),
            Probe::get('patrol_taxonomy', $area),
            Probe::get('patrol_kinds_overview', $area + ['kind' => $this->kind]),
            Probe::get('patrol_show', $patrol),
            Probe::get('patrol_export_gpx', $patrol),
            Probe::get('patrol_observation_show', $observation),
            Probe::get('patrol_delete', $patrol),
            Probe::get('patrol_import', $area),
            Probe::get('patrol_log', $area),

            Probe::post('patrol_log', $area, PatrolRecordController::CSRF_TOKEN_ID, [
                'type' => 'walk',
                'startedAt' => '2026-09-01 06:00',
                'endedAt' => '2026-09-01 10:00',
                'distanceKm' => '8.2',
            ]),
            Probe::post('patrol_delete', $patrol, DeletionPage::CSRF_ID, ['reference' => $this->patrolRef]),
            Probe::post('patrol_hold', $area + ['patrol' => $this->discarded], $this->holdToken, ['hold' => '1']),
            Probe::post('patrol_observation_amend', $observation, $this->amendToken, ['kind' => 'note', 'body' => 'The culvert is on the lower track.']),
            Probe::post('patrol_settings_save', $area, PatrolSettingsController::CSRF_TOKEN_ID, ['gap_threshold_minutes' => '20', 'discard_retention_days' => '30']),
            Probe::post('patrol_kinds_kind_create', $area, $taxonomy, ['label' => 'Snare found']),
            Probe::post('patrol_kinds_kind_rename', $area + ['kind' => $this->kind], $taxonomy, ['label' => 'Fence broken']),
            Probe::post('patrol_kinds_kind_deactivate', $area + ['kind' => $this->kind], $taxonomy),
            Probe::post('patrol_kinds_kind_reactivate', $area + ['kind' => $this->retiredKind], $taxonomy),
            Probe::post('patrol_kinds_sub_create', $area + ['kind' => $this->kind], $taxonomy, ['label' => 'Gate open']),
            Probe::post('patrol_kinds_sub_rename', $area + ['sub' => $this->subcategory], $taxonomy, ['label' => 'Wire snapped']),
            Probe::post('patrol_kinds_sub_deactivate', $area + ['sub' => $this->subcategory], $taxonomy),
            Probe::post('patrol_kinds_sub_reactivate', $area + ['sub' => $this->retiredSubcategory], $taxonomy),
            Probe::post('patrol_types_save', $area, $vocabulary, ['label' => 'Horseback']),
            Probe::post('patrol_type_act', $area + ['type' => $this->type, 'action' => 'rename'], $vocabulary, ['label' => 'On foot']),

            Probe::json('patrol_widgets_save', $area, $widgets, '{"order":[],"widgets":{}}', WidgetDom::CSRF_HEADER),
            Probe::post('patrol_widgets_reset', $area, $widgets),
            Probe::post('patrol_widgets_preset', $area + ['presetId' => 'a'], $widgets),
            Probe::post('patrol_widgets_preset_copy', $area + ['presetId' => 'a'], $widgets, ['name' => 'My morning']),
            Probe::post('patrol_widgets_preset_create', $area, $widgets, ['name' => 'My evening']),
            Probe::post('patrol_widgets_preset_apply', $mine, $widgets),
            Probe::post('patrol_widgets_preset_rename', $mine, $widgets, ['name' => 'My afternoon']),
            Probe::post('patrol_widgets_preset_delete', $mine, $widgets),
        ];
    }
}
