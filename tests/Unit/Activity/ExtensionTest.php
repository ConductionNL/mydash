<?php

/**
 * ExtensionTest
 *
 * Unit tests for the Activity Feed Integration capability:
 *  - REQ-ACT-001: Extension parses each known event type and rejects
 *    unknown types via UnknownActivityException.
 *  - REQ-ACT-002: ALL_EVENTS holds exactly 13 unique values; unknown
 *    types are dropped by the publisher with a warning log.
 *  - REQ-ACT-003: ActivityPublisher::publish populates the IEvent with
 *    the canonical {app, type, object_type, object_name(=uuid), link}
 *    quadruple and never bypasses IManager.
 *  - REQ-ACT-007: Reaction debounce suppresses the second emission
 *    inside the 900-second window.
 *  - REQ-ACT-008: Global fan-out debounce skips IUserManager
 *    enumeration entirely on suppressed events.
 *  - REQ-ACT-011/REQ-ACT-011b: Icons and parsed subjects are present
 *    for every known event type.
 *
 * @category  Test
 * @package   OCA\MyDash\Tests\Unit\Activity
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 *
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace Unit\Activity;

use OCA\MyDash\Activity\ActivityPublisher;
use OCA\MyDash\Activity\DebounceHelper;
use OCA\MyDash\Activity\Extension;
use OCP\Activity\Exceptions\UnknownActivityException;
use OCP\Activity\IEvent;
use OCP\Activity\IManager;
use OCP\IGroupManager;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUserManager;
use OCP\L10N\IFactory;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

/**
 * Tests for the canonical Activity Extension and its publisher.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Mirrors event surface.
 * @SuppressWarnings(PHPMD.TooManyPublicMethods)   Required by REQ-ACT-011b.
 */
class ExtensionTest extends TestCase
{

    /**
     * IManager mock shared across publisher-style tests.
     *
     * @var IManager&MockObject
     */
    private IManager $manager;

    /**
     * IGroupManager mock used by publishToGroup tests.
     *
     * @var IGroupManager&MockObject
     */
    private IGroupManager $groupManager;

    /**
     * IUserManager mock used by publishGlobal tests.
     *
     * @var IUserManager&MockObject
     */
    private IUserManager $userManager;

    /**
     * Test-controlled clock used by the debounce helper.
     *
     * @var integer
     */
    private int $now = 1000;

    /**
     * Debounce helper bound to {@see self::$now}.
     *
     * @var DebounceHelper
     */
    private DebounceHelper $debounce;

    /**
     * Recorded events handed to IManager::publish.
     *
     * @var IEvent[]
     */
    private array $publishedEvents = [];

    /**
     * Set up shared test doubles before each test.
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        // DebounceHelper falls through to APCu when the extension is
        // loaded, which persists claims across tests in the same suite
        // and causes `testPublishPopulatesCanonicalFields` to drop the
        // EVENT_REACTED publish whenever it runs after another test
        // that already claimed the same `(actor, uuid)` key. Flushing
        // here keeps each test's debounce state isolated regardless of
        // test execution order.
        if (function_exists(function: 'apcu_clear_cache') === true) {
            apcu_clear_cache();
        }

        $this->manager      = $this->createMock(originalClassName: IManager::class);
        $this->groupManager = $this->createMock(originalClassName: IGroupManager::class);
        $this->userManager  = $this->createMock(originalClassName: IUserManager::class);

        // Bind the debounce window to a controllable in-memory clock.
        $this->now      = 1000;
        $this->debounce = new DebounceHelper(clock: function (): int {
            return $this->now;
        });

        // Wire up the IManager mock to return a fresh recording IEvent
        // for each generateEvent() call and capture the event handed
        // to publish() so individual tests can assert on it.
        $this->publishedEvents = [];
        $this->manager
            ->method('generateEvent')
            ->willReturnCallback(function (): IEvent {
                return new RecordingEvent();
            });
        $this->manager
            ->method('publish')
            ->willReturnCallback(function (IEvent $event): void {
                $this->publishedEvents[] = $event;
            });
    }//end setUp()

    /**
     * REQ-ACT-002: ALL_EVENTS is exactly the 13 documented strings.
     *
     * @return void
     */
    public function testAllEventsHasThirteenUniqueValues(): void
    {
        $expected = [
            'dashboard_created',
            'dashboard_updated',
            'dashboard_deleted',
            'dashboard_published',
            'dashboard_unpublished',
            'dashboard_scheduled',
            'dashboard_shared',
            'dashboard_public_share_created',
            'dashboard_commented',
            'dashboard_reacted',
            'dashboard_restored',
            'dashboard_lock_overridden',
            'dashboard_role_changed',
        ];
        $actual   = Extension::ALL_EVENTS;
        sort(array: $expected);
        sort(array: $actual);

        $this->assertSame(expected: $expected, actual: $actual);
        $this->assertCount(
            expectedCount: 13,
            haystack: array_unique(array: Extension::ALL_EVENTS)
        );
        $this->assertNotContains(
            needle: 'dashboard_viewed',
            haystack: Extension::ALL_EVENTS
        );
    }//end testAllEventsHasThirteenUniqueValues()

    /**
     * REQ-ACT-002: dashboard_viewed is intentionally excluded — design D2.
     *
     * @return void
     */
    public function testNoViewEventInCatalogue(): void
    {
        $this->assertNotContains(
            needle: 'dashboard_viewed',
            haystack: Extension::ALL_EVENTS
        );
    }//end testNoViewEventInCatalogue()

    /**
     * REQ-ACT-003: every published event carries the canonical fields.
     *
     * @return void
     */
    public function testPublishPopulatesCanonicalFields(): void
    {
        $publisher = $this->createPublisher();

        foreach (Extension::ALL_EVENTS as $type) {
            $publisher->publish(
                type: $type,
                actorUserId: 'alice',
                recipientUserId: 'alice',
                dashboardUuid: 'uuid-1',
                dashboardName: 'Dashboard A',
                dashboardLink: 'https://nc.example/apps/mydash#uuid-1'
            );
        }

        $this->assertCount(
            expectedCount: count(value: Extension::ALL_EVENTS),
            haystack: $this->publishedEvents
        );

        foreach ($this->publishedEvents as $idx => $event) {
            $expectedType = Extension::ALL_EVENTS[$idx];
            $this->assertSame(
                expected: Extension::APP_ID,
                actual: $event->getApp()
            );
            $this->assertSame(
                expected: $expectedType,
                actual: $event->getType()
            );
            $this->assertSame(
                expected: Extension::OBJECT_TYPE,
                actual: $event->getObjectType()
            );
            $this->assertSame(
                expected: 'uuid-1',
                actual: $event->getObjectName()
            );
            $this->assertSame(
                expected: 'https://nc.example/apps/mydash#uuid-1',
                actual: $event->getLink()
            );
            $this->assertSame(
                expected: 'alice',
                actual: $event->getAuthor()
            );
            $this->assertSame(
                expected: 'alice',
                actual: $event->getAffectedUser()
            );
        }//end foreach
    }//end testPublishPopulatesCanonicalFields()

    /**
     * REQ-ACT-002: unknown types are dropped silently after a warning.
     *
     * @return void
     */
    public function testUnknownTypeDropped(): void
    {
        $publisher = $this->createPublisher();

        $result = $publisher->publish(
            type: 'totally_unknown_type',
            actorUserId: 'alice',
            recipientUserId: 'alice',
            dashboardUuid: 'uuid-1',
            dashboardName: 'Dashboard A',
            dashboardLink: 'https://nc.example/apps/mydash#uuid-1'
        );

        $this->assertFalse(condition: $result);
        $this->assertSame(expected: [], actual: $this->publishedEvents);
    }//end testUnknownTypeDropped()

    /**
     * REQ-ACT-007: reaction debounce suppresses second emission.
     *
     * @return void
     */
    public function testReactionDebounceSuppressesSecondEmission(): void
    {
        $publisher = $this->createPublisher();

        $first  = $publisher->publish(
            type: Extension::EVENT_REACTED,
            actorUserId: 'bob',
            recipientUserId: 'bob',
            dashboardUuid: 'uuid-1',
            dashboardName: 'Marketing',
            dashboardLink: 'https://nc.example/apps/mydash#uuid-1'
        );
        $second = $publisher->publish(
            type: Extension::EVENT_REACTED,
            actorUserId: 'bob',
            recipientUserId: 'bob',
            dashboardUuid: 'uuid-1',
            dashboardName: 'Marketing',
            dashboardLink: 'https://nc.example/apps/mydash#uuid-1'
        );

        $this->assertTrue(condition: $first);
        $this->assertFalse(condition: $second);
        $this->assertCount(
            expectedCount: 1,
            haystack: $this->publishedEvents
        );

        // Advance the clock past the 900 s window — third emission allowed.
        $this->now += 901;
        $third      = $publisher->publish(
            type: Extension::EVENT_REACTED,
            actorUserId: 'bob',
            recipientUserId: 'bob',
            dashboardUuid: 'uuid-1',
            dashboardName: 'Marketing',
            dashboardLink: 'https://nc.example/apps/mydash#uuid-1'
        );
        $this->assertTrue(condition: $third);
        $this->assertCount(
            expectedCount: 2,
            haystack: $this->publishedEvents
        );
    }//end testReactionDebounceSuppressesSecondEmission()

    /**
     * REQ-ACT-007: a different user reacting is not blocked by another
     * user's debounce key.
     *
     * @return void
     */
    public function testReactionDebounceIsPerActorPerDashboard(): void
    {
        $publisher = $this->createPublisher();

        $publisher->publish(
            type: Extension::EVENT_REACTED,
            actorUserId: 'bob',
            recipientUserId: 'bob',
            dashboardUuid: 'uuid-1',
            dashboardName: 'Marketing',
            dashboardLink: 'link'
        );
        $publisher->publish(
            type: Extension::EVENT_REACTED,
            actorUserId: 'carol',
            recipientUserId: 'carol',
            dashboardUuid: 'uuid-1',
            dashboardName: 'Marketing',
            dashboardLink: 'link'
        );

        $this->assertCount(
            expectedCount: 2,
            haystack: $this->publishedEvents
        );
    }//end testReactionDebounceIsPerActorPerDashboard()

    /**
     * REQ-ACT-008: global fan-out is debounced per (uuid, type).
     *
     * @return void
     */
    public function testGlobalFanoutIsDebounced(): void
    {
        $publisher = $this->createPublisher();

        // First fan-out enumerates users and emits one row per user.
        $this->userManager
            ->expects($this->once())
            ->method('callForAllUsers')
            ->willReturnCallback(
                    callback: function (callable $cb): void {
                        $cb($this->makeUser(uid: 'alice'));
                        $cb($this->makeUser(uid: 'bob'));
                    }
                    );

        $count = $publisher->publishGlobal(
            type: Extension::EVENT_UPDATED,
            actorUserId: 'root',
            dashboardUuid: 'uuid-default',
            dashboardName: 'Company News',
            dashboardLink: 'https://nc.example/'
        );
        $this->assertSame(expected: 2, actual: $count);
        $this->assertCount(
            expectedCount: 2,
            haystack: $this->publishedEvents
        );

        // Second call within window MUST NOT enumerate users.
        $countSuppressed = $publisher->publishGlobal(
            type: Extension::EVENT_UPDATED,
            actorUserId: 'root',
            dashboardUuid: 'uuid-default',
            dashboardName: 'Company News',
            dashboardLink: 'https://nc.example/'
        );
        $this->assertSame(expected: 0, actual: $countSuppressed);
        $this->assertCount(
            expectedCount: 2,
            haystack: $this->publishedEvents
        );
    }//end testGlobalFanoutIsDebounced()

    /**
     * REQ-ACT-005: publishToRecipients writes one row per recipient
     * plus one row to the actor — actor is not duplicated when also a
     * recipient.
     *
     * @return void
     */
    public function testPublishToRecipientsDeduplicatesActor(): void
    {
        $publisher = $this->createPublisher();

        $count = $publisher->publishToRecipients(
            type: Extension::EVENT_SHARED,
            actorUserId: 'alice',
            dashboardUuid: 'uuid-2',
            dashboardName: 'Marketing',
            dashboardLink: 'link',
            recipientUserIds: ['bob', 'carol', 'alice']
        );

        $this->assertSame(expected: 3, actual: $count);
        $this->assertCount(
            expectedCount: 3,
            haystack: $this->publishedEvents
        );
        $recipients = array_map(
            callback: static fn(IEvent $e): string => $e->getAffectedUser(),
            array: $this->publishedEvents
        );
        sort(array: $recipients);
        $this->assertSame(
            expected: ['alice', 'bob', 'carol'],
            actual: $recipients
        );
    }//end testPublishToRecipientsDeduplicatesActor()

    /**
     * REQ-ACT-006: publishToGroup resolves IGroupManager and emits one
     * row per member; actor inside the group is included once.
     *
     * @return void
     */
    public function testPublishToGroupResolvesMembers(): void
    {
        $group = $this->createMock(originalClassName: \OCP\IGroup::class);
        $group->method('getUsers')
            ->willReturn(
                    value: [
                        $this->makeUser(uid: 'alice'),
                        $this->makeUser(uid: 'bob'),
                        $this->makeUser(uid: 'carol'),
                    ]
                    );
        $this->groupManager
            ->method('get')
            ->willReturn($group);

        $publisher = $this->createPublisher();

        $count = $publisher->publishToGroup(
            type: Extension::EVENT_UPDATED,
            actorUserId: 'alice',
            groupId: 'marketing',
            dashboardUuid: 'uuid-3',
            dashboardName: 'Campaigns',
            dashboardLink: 'link'
        );

        $this->assertSame(expected: 3, actual: $count);
        $this->assertCount(
            expectedCount: 3,
            haystack: $this->publishedEvents
        );
    }//end testPublishToGroupResolvesMembers()

    /**
     * REQ-ACT-001 / REQ-ACT-011b: getIcon returns a non-empty string
     * for every known type and for an unknown fallback.
     *
     * @return void
     */
    public function testGetIconReturnsNonEmptyForAllTypes(): void
    {
        $extension = $this->createExtension();

        foreach (Extension::ALL_EVENTS as $type) {
            $url = $extension->getIcon(eventType: $type);
            $this->assertNotEmpty(actual: $url);
            $this->assertStringContainsString(
                needle: $type.'.svg',
                haystack: $url
            );
        }

        $fallback = $extension->getIcon(eventType: 'totally_unknown');
        $this->assertNotEmpty(actual: $fallback);
        $this->assertStringContainsString(
            needle: 'mydash.svg',
            haystack: $fallback
        );
    }//end testGetIconReturnsNonEmptyForAllTypes()

    /**
     * REQ-ACT-011b: parse() sets richSubject for every known type and
     * for both self / other variants.
     *
     * @return void
     */
    public function testParseSetsRichSubjectForAllTypes(): void
    {
        $extension = $this->createExtension();

        foreach (Extension::ALL_EVENTS as $type) {
            foreach ([true, false] as $isSelf) {
                $event = new RecordingEvent();
                $event->setApp(app: Extension::APP_ID);
                $event->setType(type: $type);
                $event->setSubject(
                    subject: $type,
                    parameters: [
                        'self'      => $isSelf,
                        'actor'     => 'alice',
                        'dashboard' => 'Dashboard A',
                        'recipient' => 'bob',
                        'role'      => 'editor',
                        'target'    => 'carol',
                    ]
                );
                $extension->parse(language: 'en', event: $event);

                if ($isSelf === true) {
                    $selfLabel = 'true';
                } else {
                    $selfLabel = 'false';
                }

                $this->assertNotEmpty(
                    actual: $event->getRichSubject(),
                    message: sprintf(
                        'richSubject empty for %s (self=%s)',
                        $type,
                        $selfLabel
                    )
                );
            }//end foreach
        }//end foreach
    }//end testParseSetsRichSubjectForAllTypes()

    /**
     * REQ-ACT-001: parse() throws UnknownActivityException for unknown
     * subjects so NC's provider chain can fall through.
     *
     * @return void
     */
    public function testParseThrowsForUnknownType(): void
    {
        $extension = $this->createExtension();
        $event     = new RecordingEvent();
        $event->setApp(app: Extension::APP_ID);
        $event->setType(type: 'totally_unknown_type');

        $this->expectException(exception: UnknownActivityException::class);
        $extension->parse(language: 'en', event: $event);
    }//end testParseThrowsForUnknownType()

    /**
     * Build a publisher wired to the test mocks and clock.
     *
     * @return ActivityPublisher The publisher under test.
     */
    private function createPublisher(): ActivityPublisher
    {
        return new ActivityPublisher(
            manager: $this->manager,
            groupManager: $this->groupManager,
            userManager: $this->userManager,
            debounce: $this->debounce,
            logger: new NullLogger()
        );
    }//end createPublisher()

    /**
     * Build a parsing-only Extension instance.
     *
     * @return Extension The extension under test.
     */
    private function createExtension(): Extension
    {
        $l10nFactory = $this->createMock(originalClassName: IFactory::class);
        $l           = $this->createMock(originalClassName: IL10N::class);
        $l->method('t')
            ->willReturnArgument(0);
        $l10nFactory
            ->method('get')
            ->willReturn($l);

        $url        = $this->createMock(originalClassName: IURLGenerator::class);
        $imageCb    = static fn(string $appName, string $file): string => '/apps/'.$appName.'/img/'.$file;
        $absoluteCb = static fn(string $path): string => 'https://nc.example'.$path;
        $url->method('imagePath')->willReturnCallback($imageCb);
        $url->method('getAbsoluteURL')->willReturnCallback($absoluteCb);

        return new Extension(
            l10nFactory: $l10nFactory,
            urlGenerator: $url
        );
    }//end createExtension()

    /**
     * Create a minimal IUser mock returning the given UID.
     *
     * @param string $uid The user identifier.
     *
     * @return \OCP\IUser&MockObject The user mock.
     */
    private function makeUser(string $uid): \OCP\IUser
    {
        $user = $this->createMock(originalClassName: \OCP\IUser::class);
        $user->method('getUID')->willReturn($uid);
        return $user;
    }//end makeUser()
}//end class
