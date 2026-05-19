<?php

/**
 * SetupWizardService Test
 *
 * Covers REQ-WIZ-001 (state flag), REQ-WIZ-003 (storage backend
 * persistence + GroupFolder gate), REQ-WIZ-008 (wizard state heuristic),
 * and REQ-WIZ-009 (idempotent completion).
 *
 * @category  Test
 * @package   OCA\MyDash\Tests\Unit\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Unit\Service;

use InvalidArgumentException;
use OCA\MyDash\Db\AdminSetting;
use OCA\MyDash\Db\AdminSettingMapper;
use OCA\MyDash\Service\SetupWizardService;
use OCP\App\IAppManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class SetupWizardServiceTest extends TestCase
{
    private SetupWizardService $service;

    /** @var AdminSettingMapper&MockObject */
    private $settingMapper;

    /** @var IAppManager&MockObject */
    private $appManager;

    protected function setUp(): void
    {
        $this->settingMapper = $this->createMock(AdminSettingMapper::class);
        $this->appManager    = $this->createMock(IAppManager::class);
        $this->service       = new SetupWizardService(
            settingMapper: $this->settingMapper,
            appManager: $this->appManager
        );
    }

    public function testGetWizardStateOnFreshInstance(): void
    {
        $this->settingMapper->method('getValue')
            ->with(AdminSetting::KEY_SETUP_WIZARD_COMPLETE, false)
            ->willReturn(false);
        $this->settingMapper->method('getAllAsArray')->willReturn([]);

        $state = $this->service->getWizardState();

        $this->assertFalse($state['complete']);
        $this->assertSame(2, $state['currentRecommendedStep']);
        $this->assertSame('done', $state['stepStatuses']['1']);
        $this->assertSame('pending', $state['stepStatuses']['2']);
        $this->assertSame('pending', $state['stepStatuses']['3']);
        $this->assertSame('skipped', $state['stepStatuses']['4']);
        $this->assertSame('skipped', $state['stepStatuses']['5']);
        $this->assertSame('skipped', $state['stepStatuses']['6']);
        $this->assertSame('pending', $state['stepStatuses']['7']);
    }

    public function testGetWizardStateAfterStorageWritten(): void
    {
        $this->settingMapper->method('getValue')
            ->with(AdminSetting::KEY_SETUP_WIZARD_COMPLETE, false)
            ->willReturn(false);
        $this->settingMapper->method('getAllAsArray')->willReturn([
            AdminSetting::KEY_CONTENT_STORAGE => 'database',
        ]);

        $state = $this->service->getWizardState();

        $this->assertSame('done', $state['stepStatuses']['2']);
        // Step 3 still pending so the recommended step jumps to 3.
        $this->assertSame(3, $state['currentRecommendedStep']);
    }

    public function testGetWizardStateAfterCompletion(): void
    {
        $this->settingMapper->method('getValue')
            ->with(AdminSetting::KEY_SETUP_WIZARD_COMPLETE, false)
            ->willReturn(true);
        $this->settingMapper->method('getAllAsArray')->willReturn([
            AdminSetting::KEY_CONTENT_STORAGE => 'database',
            AdminSetting::KEY_GROUP_ORDER     => ['engineering'],
            AdminSetting::KEY_FOOTER_CONFIG   => ['layout' => 'structured'],
        ]);

        $state = $this->service->getWizardState();

        $this->assertTrue($state['complete']);
        $this->assertSame('done', $state['stepStatuses']['7']);
        $this->assertSame('done', $state['stepStatuses']['6']);
        // Steps 4/5 are 'skipped' (sibling capabilities pending), so the
        // first non-'done' status is Step 4. The wizard "complete" flag
        // is the source of truth for hiding the banner; the recommended
        // step is purely a UX hint per REQ-WIZ-008.
        $this->assertSame(4, $state['currentRecommendedStep']);
    }

    public function testMarkWizardCompleteSetsFlagAndReturnsState(): void
    {
        $this->settingMapper
            ->expects($this->once())
            ->method('setSetting')
            ->with(AdminSetting::KEY_SETUP_WIZARD_COMPLETE, true);
        $this->settingMapper->method('getValue')
            ->with(AdminSetting::KEY_SETUP_WIZARD_COMPLETE, false)
            ->willReturn(true);
        $this->settingMapper->method('getAllAsArray')->willReturn([]);

        $state = $this->service->markWizardComplete();

        $this->assertTrue($state['complete']);
    }

    public function testMarkWizardCompleteIsIdempotent(): void
    {
        // Even when already true the service still writes (idempotent
        // semantics — the controller doesn't need a defensive guard).
        $this->settingMapper
            ->expects($this->exactly(2))
            ->method('setSetting');
        $this->settingMapper->method('getValue')->willReturn(true);
        $this->settingMapper->method('getAllAsArray')->willReturn([]);

        $first  = $this->service->markWizardComplete();
        $second = $this->service->markWizardComplete();

        $this->assertSame($first, $second);
    }

    public function testGetGroupfolderAvailabilityDelegates(): void
    {
        $this->appManager
            ->expects($this->once())
            ->method('isInstalled')
            ->with('groupfolders')
            ->willReturn(true);

        $this->assertTrue($this->service->hasGroupfolderApp());
    }

    public function testSetContentStorageRejectsUnsupportedValue(): void
    {
        $this->settingMapper->expects($this->never())->method('setSetting');
        $this->expectException(InvalidArgumentException::class);

        $this->service->setContentStorage(value: 'cassette-tape');
    }

    public function testSetContentStoragePersistsKnownValues(): void
    {
        $this->settingMapper
            ->expects($this->once())
            ->method('setSetting')
            ->with(AdminSetting::KEY_CONTENT_STORAGE, 'groupfolder');

        $this->service->setContentStorage(value: SetupWizardService::STORAGE_GROUPFOLDER);
    }

    public function testGetContentStorageDefaultsToDatabase(): void
    {
        $this->settingMapper->method('getValue')
            ->with(AdminSetting::KEY_CONTENT_STORAGE, null)
            ->willReturn(null);

        $this->assertSame('database', $this->service->getContentStorage());
    }

    public function testGetContentStorageReturnsPersisted(): void
    {
        $this->settingMapper->method('getValue')
            ->with(AdminSetting::KEY_CONTENT_STORAGE, null)
            ->willReturn('groupfolder');

        $this->assertSame('groupfolder', $this->service->getContentStorage());
    }
}
