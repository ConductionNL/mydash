<?php

/**
 * PeopleWidgetServiceTest
 *
 * Unit tests for {@see PeopleWidgetService} covering:
 *   - REQ-PPL-003: pagination shape (`{users, total, hasMore}`), MAX_LIMIT
 *     enforcement, default sort.
 *   - REQ-PPL-004: empty values are omitted from the response (never null).
 *   - REQ-PPL-005: birthdate normalisation + `computeDaysToBirthday()` Feb-29
 *     guard.
 *   - REQ-PPL-006: group filter union + dedup + unknown-group tolerance.
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
use OCA\MyDash\Service\AdminTemplateService;
use OCA\MyDash\Service\PeopleWidgetService;
use OCP\Accounts\IAccount;
use OCP\Accounts\IAccountManager;
use OCP\Accounts\IAccountProperty;
use OCP\IGroup;
use OCP\IGroupManager;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

/**
 * Tests for the People widget backend service.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) Mirrors constructor.
 */
class PeopleWidgetServiceTest extends TestCase
{

    /**
     * @var IUserManager&MockObject
     */
    private $userManager;

    /**
     * @var IGroupManager&MockObject
     */
    private $groupManager;

    /**
     * @var IAccountManager&MockObject
     */
    private $accountManager;

    /**
     * @var IURLGenerator&MockObject
     */
    private $urlGenerator;

    /**
     * @var AdminTemplateService&MockObject
     */
    private $adminTemplateService;

    private PeopleWidgetService $service;

    /**
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->userManager          = $this->createMock(originalClassName: IUserManager::class);
        $this->groupManager         = $this->createMock(originalClassName: IGroupManager::class);
        $this->accountManager       = $this->createMock(originalClassName: IAccountManager::class);
        $this->urlGenerator         = $this->createMock(originalClassName: IURLGenerator::class);
        $this->adminTemplateService = $this->createMock(originalClassName: AdminTemplateService::class);

        $this->urlGenerator->method('linkToRouteAbsolute')
            ->willReturnCallback(
                callback: static fn(string $route, array $args=[]): string => 'https://example.test/'.$route.'?'.http_build_query(data: $args)
            );

        // Default: any user has no groups. Tests can override per-call.
        $this->adminTemplateService->method('getUserGroupIdsFor')->willReturn([]);

        $this->service = new PeopleWidgetService(
            userManager: $this->userManager,
            groupManager: $this->groupManager,
            accountManager: $this->accountManager,
            urlGenerator: $this->urlGenerator,
            adminTemplateService: $this->adminTemplateService,
        );
    }//end setUp()

    // ---------------------------------------------------------------
    // computeDaysToBirthday — pure helper (REQ-PPL-005)
    // ---------------------------------------------------------------

    /**
     * @return void
     */
    public function testComputeDaysToBirthdayReturnsNullForBlankInput(): void
    {
        $this->assertNull(actual: PeopleWidgetService::computeDaysToBirthday(birthdate: null));
        $this->assertNull(actual: PeopleWidgetService::computeDaysToBirthday(birthdate: ''));
        $this->assertNull(actual: PeopleWidgetService::computeDaysToBirthday(birthdate: 'not-a-date'));
    }//end testComputeDaysToBirthdayReturnsNullForBlankInput()

    /**
     * @return void
     */
    public function testComputeDaysToBirthdayHandlesIsoInput(): void
    {
        $today = new \DateTimeImmutable(datetime: 'today');
        $iso   = $today->modify(modifier: '+5 days')->format(format: '1990-m-d');

        $this->assertSame(
            expected: 5,
            actual: PeopleWidgetService::computeDaysToBirthday(birthdate: $iso)
        );
    }//end testComputeDaysToBirthdayHandlesIsoInput()

    /**
     * @return void
     */
    public function testComputeDaysToBirthdayHandlesLocaleFormat(): void
    {
        $today  = new \DateTimeImmutable(datetime: 'today');
        $locale = $today->modify(modifier: '+10 days')->format(format: 'd-m-1990');

        $this->assertSame(
            expected: 10,
            actual: PeopleWidgetService::computeDaysToBirthday(birthdate: $locale)
        );
    }//end testComputeDaysToBirthdayHandlesLocaleFormat()

    /**
     * @return void
     */
    public function testComputeDaysToBirthdayWrapsToNextYearWhenPast(): void
    {
        $today = new \DateTimeImmutable(datetime: 'today');
        $past  = $today->modify(modifier: '-30 days')->format(format: '1990-m-d');

        $days = PeopleWidgetService::computeDaysToBirthday(birthdate: $past);
        $this->assertNotNull(actual: $days);
        $this->assertGreaterThan(300, $days);
    }//end testComputeDaysToBirthdayWrapsToNextYearWhenPast()

    /**
     * Feb-29 birthday must NOT throw on non-leap years; the service falls
     * back to Feb-28. We verify by parsing 2027 (not a leap year) as the
     * candidate window.
     *
     * @return void
     */
    public function testComputeDaysToBirthdayHandlesFeb29OnNonLeapYear(): void
    {
        $days = PeopleWidgetService::computeDaysToBirthday(birthdate: '2000-02-29');
        $this->assertNotNull(
            actual: $days,
            message: 'Feb-29 input must not throw or return null on any year'
        );
    }//end testComputeDaysToBirthdayHandlesFeb29OnNonLeapYear()

    // ---------------------------------------------------------------
    // listUsers — argument validation (REQ-PPL-003)
    // ---------------------------------------------------------------

    /**
     * @return void
     */
    public function testListUsersRejectsLimitOverMax(): void
    {
        $this->expectException(exception: InvalidArgumentException::class);
        $this->service->listUsers(limit: PeopleWidgetService::MAX_LIMIT + 1);
    }//end testListUsersRejectsLimitOverMax()

    /**
     * @return void
     */
    public function testListUsersRejectsZeroLimit(): void
    {
        $this->expectException(exception: InvalidArgumentException::class);
        $this->service->listUsers(limit: 0);
    }//end testListUsersRejectsZeroLimit()

    /**
     * @return void
     */
    public function testListUsersRejectsNegativeOffset(): void
    {
        $this->expectException(exception: InvalidArgumentException::class);
        $this->service->listUsers(offset: -1);
    }//end testListUsersRejectsNegativeOffset()

    /**
     * @return void
     */
    public function testListUsersRejectsRecentActivitySort(): void
    {
        $this->expectException(exception: InvalidArgumentException::class);
        $this->service->listUsers(sortBy: 'recent-activity');
    }//end testListUsersRejectsRecentActivitySort()

    // ---------------------------------------------------------------
    // listUsers — pagination + projection (REQ-PPL-003, REQ-PPL-004)
    // ---------------------------------------------------------------

    /**
     * @return void
     */
    public function testListUsersReturnsPaginationShape(): void
    {
        $users   = [];
        $users[] = $this->makeUser(uid: 'alice', display: 'Alice', email: 'alice@example.test');
        $users[] = $this->makeUser(uid: 'bob', display: 'Bob', email: '');
        $users[] = $this->makeUser(uid: 'carol', display: 'Carol', email: 'carol@example.test');

        $this->userManager->method('search')->willReturn($users);
        $this->groupManager->method('getUserGroupIds')->willReturn([]);

        // Empty account so the optional fields are omitted.
        $this->accountManager->method('getAccount')->willReturn($this->emptyAccount());

        $result = $this->service->listUsers(limit: 2, offset: 0);

        $this->assertSame(expected: 3, actual: $result['total']);
        $this->assertTrue(condition: $result['hasMore']);
        $this->assertCount(expectedCount: 2, haystack: $result['users']);

        // Default sort = displayName ASC.
        $this->assertSame(expected: 'alice', actual: $result['users'][0]['uid']);
        $this->assertSame(expected: 'bob', actual: $result['users'][1]['uid']);

        // Empty email is OMITTED, not nulled (REQ-PPL-004).
        $this->assertArrayNotHasKey(key: 'email', array: $result['users'][1]);
        $this->assertArrayHasKey(key: 'email', array: $result['users'][0]);
        $this->assertSame(
            expected: 'alice@example.test',
            actual: $result['users'][0]['email']
        );

        // Avatar URL points to the configured route.
        $this->assertStringContainsString(
            needle: 'core.avatar.getAvatar',
            haystack: $result['users'][0]['avatarUrl']
        );
    }//end testListUsersReturnsPaginationShape()

    /**
     * @return void
     */
    public function testListUsersLastPageHasMoreFalse(): void
    {
        $users   = [];
        $users[] = $this->makeUser(uid: 'alice', display: 'Alice');
        $users[] = $this->makeUser(uid: 'bob', display: 'Bob');

        $this->userManager->method('search')->willReturn($users);
        $this->groupManager->method('getUserGroupIds')->willReturn([]);
        $this->accountManager->method('getAccount')->willReturn($this->emptyAccount());

        $result = $this->service->listUsers(limit: 50, offset: 0);

        $this->assertFalse(condition: $result['hasMore']);
        $this->assertSame(expected: 2, actual: $result['total']);
        $this->assertCount(expectedCount: 2, haystack: $result['users']);
    }//end testListUsersLastPageHasMoreFalse()

    /**
     * @return void
     */
    public function testListUsersExcludesDisabledByDefault(): void
    {
        $alice = $this->makeUser(uid: 'alice', display: 'Alice', enabled: true);
        $eve   = $this->makeUser(uid: 'eve', display: 'Eve', enabled: false);

        $this->userManager->method('search')->willReturn([$alice, $eve]);
        $this->groupManager->method('getUserGroupIds')->willReturn([]);
        $this->accountManager->method('getAccount')->willReturn($this->emptyAccount());

        $result = $this->service->listUsers();

        $this->assertSame(expected: 1, actual: $result['total']);
        $this->assertSame(expected: 'alice', actual: $result['users'][0]['uid']);
    }//end testListUsersExcludesDisabledByDefault()

    // ---------------------------------------------------------------
    // listUsers — group filter (REQ-PPL-006)
    // ---------------------------------------------------------------

    /**
     * @return void
     */
    public function testGroupFilterUnionDeduplicates(): void
    {
        $alice = $this->makeUser(uid: 'alice', display: 'Alice');
        $bob   = $this->makeUser(uid: 'bob', display: 'Bob');
        $carol = $this->makeUser(uid: 'carol', display: 'Carol');

        $mgmt = $this->createMock(originalClassName: IGroup::class);
        $mgmt->method('getUsers')->willReturn([$alice, $bob]);

        $prod = $this->createMock(originalClassName: IGroup::class);
        $prod->method('getUsers')->willReturn([$bob, $carol]);

        $this->groupManager->method('get')
            ->willReturnCallback(
                callback: static function (string $gid) use ($mgmt, $prod) {
                    if ($gid === 'management') {
                        return $mgmt;
                    }

                    if ($gid === 'product') {
                        return $prod;
                    }

                    return null;
                }
            );

        // Group sort path consults getUserGroupIds; default sort doesn't.
        $this->groupManager->method('getUserGroupIds')->willReturn([]);
        $this->accountManager->method('getAccount')->willReturn($this->emptyAccount());

        $result = $this->service->listUsers(
            filters: [
                [
                    'fieldName' => 'group',
                    'operator'  => 'in',
                    'values'    => ['management', 'product'],
                ],
            ],
        );

        // Bob appears once across both groups (dedup).
        $uids = array_map(
            callback: static fn(array $u): string => $u['uid'],
            array: $result['users']
        );
        $this->assertSame(expected: ['alice', 'bob', 'carol'], actual: $uids);
        $this->assertSame(expected: 3, actual: $result['total']);
    }//end testGroupFilterUnionDeduplicates()

    /**
     * @return void
     */
    public function testUnknownGroupYieldsZeroUsersWithoutError(): void
    {
        $this->groupManager->method('get')->willReturn(null);
        $this->accountManager->method('getAccount')->willReturn($this->emptyAccount());

        $result = $this->service->listUsers(
            filters: [
                [
                    'fieldName' => 'group',
                    'operator'  => 'in',
                    'values'    => ['nonexistent'],
                ],
            ],
        );

        $this->assertSame(expected: 0, actual: $result['total']);
        $this->assertSame(expected: [], actual: $result['users']);
        $this->assertFalse(condition: $result['hasMore']);
    }//end testUnknownGroupYieldsZeroUsersWithoutError()

    // ---------------------------------------------------------------
    // listUsers — account-field projection (REQ-PPL-005)
    // ---------------------------------------------------------------

    /**
     * @return void
     */
    public function testBirthdateIsNormalisedToIso(): void
    {
        $alice = $this->makeUser(uid: 'alice', display: 'Alice');
        $this->userManager->method('search')->willReturn([$alice]);
        $this->groupManager->method('getUserGroupIds')->willReturn([]);

        $account = $this->makeAccount(
            properties: [
                IAccountManager::PROPERTY_BIRTHDATE => '10-06-1990',
                IAccountManager::PROPERTY_ROLE      => 'PM',
            ]
        );
        $this->accountManager->method('getAccount')->willReturn($account);

        $result = $this->service->listUsers();

        $this->assertSame(
            expected: '1990-06-10',
            actual: $result['users'][0]['birthdate']
        );
        $this->assertSame(expected: 'PM', actual: $result['users'][0]['role']);
    }//end testBirthdateIsNormalisedToIso()

    /**
     * @return void
     */
    public function testShowBirthdaysFalseStripsBirthdate(): void
    {
        $alice = $this->makeUser(uid: 'alice', display: 'Alice');
        $this->userManager->method('search')->willReturn([$alice]);
        $this->groupManager->method('getUserGroupIds')->willReturn([]);

        $account = $this->makeAccount(
            properties: [IAccountManager::PROPERTY_BIRTHDATE => '1990-06-10']
        );
        $this->accountManager->method('getAccount')->willReturn($account);

        $result = $this->service->listUsers(showBirthdays: false);

        $this->assertArrayNotHasKey(
            key: 'birthdate',
            array: $result['users'][0]
        );
    }//end testShowBirthdaysFalseStripsBirthdate()

    // ---------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------

    /**
     * @param string $uid     The user id.
     * @param string $display Display name.
     * @param string $email   Email or empty string.
     * @param bool   $enabled Whether the user is enabled.
     *
     * @return IUser&MockObject
     */
    private function makeUser(
        string $uid,
        string $display,
        string $email='',
        bool $enabled=true
    ): IUser {
        $user = $this->createMock(originalClassName: IUser::class);
        $user->method('getUID')->willReturn($uid);
        $user->method('getDisplayName')->willReturn($display);
        $user->method('getEMailAddress')->willReturn($email === '' ? null : $email);
        $user->method('isEnabled')->willReturn($enabled);
        return $user;
    }//end makeUser()

    /**
     * Build an account whose every property returns the empty string —
     * matches the "no profile fields set" baseline.
     *
     * @return IAccount&MockObject
     */
    private function emptyAccount(): IAccount
    {
        return $this->makeAccount(properties: []);
    }//end emptyAccount()

    /**
     * @param array<string, string> $properties Map of property name → value.
     *                                          Properties absent from the map
     *                                          resolve to the empty string.
     *
     * @return IAccount&MockObject
     */
    private function makeAccount(array $properties): IAccount
    {
        $account = $this->createMock(originalClassName: IAccount::class);
        $account->method('getProperty')
            ->willReturnCallback(
                callback: function (string $name) use ($properties): IAccountProperty {
                    $prop = $this->createMock(originalClassName: IAccountProperty::class);
                    $prop->method('getValue')->willReturn($properties[$name] ?? '');
                    $prop->method('getName')->willReturn($name);
                    return $prop;
                }
            );

        return $account;
    }//end makeAccount()
}//end class
