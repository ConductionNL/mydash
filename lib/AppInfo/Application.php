<?php

/**
 * Application
 *
 * Main application bootstrap class for MyDash.
 *
 * @category  AppInfo
 * @package   OCA\MyDash\AppInfo
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT:auto
 * @link      https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\MyDash\AppInfo;

use OCA\MyDash\Activity\DebounceHelper;
use OCA\MyDash\BackgroundJob\PurgeViewsJob;
use OCA\MyDash\BackgroundJob\SaltRotationJob;
use OCA\MyDash\Event\DashboardDeletedEvent;
use OCA\MyDash\Job\FeedRefreshJob;
use OCA\MyDash\Listener\CommentsListener;
use OCA\MyDash\Listener\GroupDeletedListener;
use OCA\MyDash\Listener\LocksListener;
use OCA\MyDash\Listener\MetadataValuesListener;
use OCA\MyDash\Listener\PublicSharesListener;
use OCA\MyDash\Listener\ReactionsListener;
use OCA\MyDash\Listener\TranslationsListener;
use OCA\MyDash\Listener\TreeListener;
use OCA\MyDash\Listener\UserDeletedListener;
use OCA\MyDash\Listener\VersionsListener;
use OCA\MyDash\Listener\ViewAnalyticsListener;
use OCA\MyDash\Listener\WidgetPlacementsListener;
use OCA\MyDash\Notification\Notifier;
use OCA\MyDash\Search\MyDashSearchProvider;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\BackgroundJob\IJobList;
use OCP\Group\Events\GroupDeletedEvent;
use OCP\User\Events\UserDeletedEvent;

/**
 * Application bootstrap.
 *
 * @SuppressWarnings(PHPMD.CouplingBetweenObjects) The bootstrap
 *                                                  legitimately wires
 *                                                  every event listener
 *                                                  in the cascade-events
 *                                                  registry (REQ-CSC-002),
 *                                                  which inflates the
 *                                                  coupling count beyond
 *                                                  the default threshold.
 */
class Application extends App implements IBootstrap
{
    public const APP_ID = 'mydash';

    /**
     * Constructor
     *
     * @param array $urlParams The URL parameters.
     */
    public function __construct(array $urlParams=[])
    {
        parent::__construct(appName: self::APP_ID, urlParams: $urlParams);
    }//end __construct()

    /**
     * Register services, event listeners, etc.
     *
     * @param IRegistrationContext $context The registration context.
     *
     * @return void
     */
    public function register(IRegistrationContext $context): void
    {
        // Render `dashboard_shared` and `dashboard_ownership_transferred`
        // notifications via our INotifier. REQ-SHARE-011.
        $context->registerNotifierService(notifierClass: Notifier::class);

        // Cascade share cleanup + admin-retention transfer on user deletion.
        // Also fires the role-assignment cleanup. REQ-SHARE-012,
        // REQ-SHARE-013, REQ-ROLE-010. The same listener also satisfies the
        // owned-dashboard enumeration mandated by REQ-CSC-004 — every owned
        // dashboard is routed through the deletion path that dispatches
        // DashboardDeletedEvent below, triggering the full cascade stack.
        $context->registerEventListener(
            event: UserDeletedEvent::class,
            listener: UserDeletedListener::class
        );

        // REQ-ACT-007: register DebounceHelper as a shared singleton
        // so the in-memory fallback store (used when APCu is absent in
        // CLI / test runs) survives across all callers within a single
        // request. ActivityPublisher autowires from the app namespace
        // — no explicit binding needed (referenced here in this
        // docblock for the cross-capability discoverability contract:
        // {@see ActivityPublisher}).
        $context->registerService(
            name: DebounceHelper::class,
            factory: static fn(): DebounceHelper => new DebounceHelper(),
            shared: true
        );

        // Role-assignment cascade on group deletion. REQ-ROLE-011.
        // Group lifecycle cleanup. REQ-CSC-005.
        $context->registerEventListener(
            event: GroupDeletedEvent::class,
            listener: GroupDeletedListener::class
        );

        // DashboardDeletedEvent listener registry. REQ-CSC-002.
        // Each listener owns one dependent table (or, for TreeListener,
        // recursive child dispatch). Adding a new listener requires only
        // appending one registration line below — no edits to existing
        // listener classes, the event, or DashboardService.
        $context->registerEventListener(
            event: DashboardDeletedEvent::class,
            listener: WidgetPlacementsListener::class
        );
        $context->registerEventListener(
            event: DashboardDeletedEvent::class,
            listener: CommentsListener::class
        );
        $context->registerEventListener(
            event: DashboardDeletedEvent::class,
            listener: ReactionsListener::class
        );
        $context->registerEventListener(
            event: DashboardDeletedEvent::class,
            listener: LocksListener::class
        );
        $context->registerEventListener(
            event: DashboardDeletedEvent::class,
            listener: VersionsListener::class
        );
        $context->registerEventListener(
            event: DashboardDeletedEvent::class,
            listener: PublicSharesListener::class
        );
        $context->registerEventListener(
            event: DashboardDeletedEvent::class,
            listener: MetadataValuesListener::class
        );
        $context->registerEventListener(
            event: DashboardDeletedEvent::class,
            listener: TranslationsListener::class
        );
        $context->registerEventListener(
            event: DashboardDeletedEvent::class,
            listener: ViewAnalyticsListener::class
        );
        $context->registerEventListener(
            event: DashboardDeletedEvent::class,
            listener: TreeListener::class
        );

        // Surface dashboards, widget content, and metadata values in
        // Nextcloud's unified search (Ctrl+K). REQ-SRCH-001.
        $context->registerSearchProvider(class: MyDashSearchProvider::class);
    }//end register()

    /**
     * App initialization after all apps are registered.
     *
     * @param IBootContext $context The boot context.
     *
     * @return void
     */
    public function boot(IBootContext $context): void
    {
        // App initialization after all apps are registered.
        \OCP\Util::addStyle(application: self::APP_ID, file: 'mydash');

        // Register the dashboard view-analytics background jobs
        // (REQ-ANLT-003 design D2 + REQ-ANLT-009) plus the periodic
        // external-feed refresh job (REQ-FRJ-002). All are idempotent:
        // `IJobList::add()` is a no-op when the job is already registered.
        try {
            $serverContainer = $context->getServerContainer();
            $jobList         = $serverContainer->get(IJobList::class);
            if ($jobList instanceof IJobList === true) {
                $jobList->add(job: PurgeViewsJob::class);
                $jobList->add(job: SaltRotationJob::class);
                $jobList->add(job: FeedRefreshJob::class);
            }
        } catch (\Throwable $exception) {
            $context->getServerContainer()
                ->get(\Psr\Log\LoggerInterface::class)
                ->warning(
                    'Failed to register MyDash background jobs: '.$exception->getMessage(),
                    ['app' => self::APP_ID]
                );
        }
    }//end boot()
}//end class
