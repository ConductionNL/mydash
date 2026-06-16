<?php

/**
 * DashboardTranslationMapper
 *
 * Database mapper for {@see DashboardTranslation} entities. Owns the
 * oc_mydash_dash_translations table; implements per-language CRUD
 * plus the locale-resolution lookup. REQ-DASH-038..044 (per-language
 * dashboard content variants).
 *
 * @category  Database
 * @package   OCA\MyDash\Db
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2026 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT:auto
 * @link      https://conduction.nl
 *
 * SPDX-FileCopyrightText: 2026 MyDash Contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

declare(strict_types=1);

namespace OCA\MyDash\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * Mapper for DashboardTranslation entities.
 *
 * @extends QBMapper<DashboardTranslation>
 */
class DashboardTranslationMapper extends QBMapper
{
    /**
     * Constructor
     *
     * @param IDBConnection $db The database connection.
     */
    public function __construct(IDBConnection $db)
    {
        parent::__construct(
            db: $db,
            tableName: 'mydash_dash_translations',
            entityClass: DashboardTranslation::class
        );
    }//end __construct()

    /**
     * Normalise an arbitrary locale string to the canonical 2-character
     * base code stored in the table. REQ-DASH-038, design D2.
     *
     * Accepts any of: `nl`, `nl_NL`, `nl-NL`, `nl-BE`, `EN`, `fr_FR`.
     * Returns the lowercased characters before the first separator
     * (`_` or `-`). An empty input returns the empty string — caller
     * decides whether to fall back to {@see DashboardTranslation::DEFAULT_LANGUAGE}.
     *
     * @param string $raw The raw locale string.
     *
     * @return string The 2-char (or fewer) base code in lowercase.
     */
    public static function normaliseLanguageCode(string $raw): string
    {
        $trimmed = trim($raw);
        if ($trimmed === '') {
            return '';
        }

        $lower = strtolower($trimmed);
        // Split on either the underscore or hyphen separator and take
        // the first segment — handles BCP-47 (`nl-BE`), POSIX
        // (`nl_NL`), and bare codes (`nl`) uniformly.
        $separatorPos = strcspn($lower, '_-');
        return substr($lower, 0, $separatorPos);
    }//end normaliseLanguageCode()

    /**
     * Find a translation by ID.
     *
     * @param int $id The translation ID.
     *
     * @return DashboardTranslation The translation entity.
     *
     * @throws DoesNotExistException When not found.
     */
    public function find(int $id): DashboardTranslation
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select(selects: '*')
            ->from(from: $this->getTableName())
            ->where(
                $qb->expr()->eq(
                    x: 'id',
                    y: $qb->createNamedParameter(
                        value: $id,
                        type: IQueryBuilder::PARAM_INT
                    )
                )
            );

        return $this->findEntity(query: $qb);
    }//end find()

    /**
     * Find every translation variant for a dashboard.
     *
     * @param string $dashboardUuid The dashboard UUID.
     *
     * @return DashboardTranslation[] The translation rows, ordered by
     *                                language code.
     */
    public function findByDashboardUuid(string $dashboardUuid): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select(selects: '*')
            ->from(from: $this->getTableName())
            ->where(
                $qb->expr()->eq(
                    x: 'dashboard_uuid',
                    y: $qb->createNamedParameter(value: $dashboardUuid)
                )
            )
            ->orderBy(sort: 'language_code', order: 'ASC');

        return $this->findEntities(query: $qb);
    }//end findByDashboardUuid()

    /**
     * Find a single translation by `(dashboardUuid, languageCode)`.
     *
     * @param string $dashboardUuid The dashboard UUID.
     * @param string $languageCode  The (raw) language code; will be
     *                              normalised to the stored form.
     *
     * @return DashboardTranslation|null The variant or null when not found.
     */
    public function findByDashboardUuidAndLanguage(
        string $dashboardUuid,
        string $languageCode
    ): ?DashboardTranslation {
        $normalised = self::normaliseLanguageCode(raw: $languageCode);
        if ($normalised === '') {
            return null;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select(selects: '*')
            ->from(from: $this->getTableName())
            ->where(
                $qb->expr()->eq(
                    x: 'dashboard_uuid',
                    y: $qb->createNamedParameter(value: $dashboardUuid)
                )
            )
            ->andWhere(
                $qb->expr()->eq(
                    x: 'language_code',
                    y: $qb->createNamedParameter(value: $normalised)
                )
            )
            ->setMaxResults(maxResults: 1);

        try {
            return $this->findEntity(query: $qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }//end findByDashboardUuidAndLanguage()

    /**
     * Find the primary translation for a dashboard.
     *
     * @param string $dashboardUuid The dashboard UUID.
     *
     * @return DashboardTranslation|null The primary variant or null when
     *                                   no rows exist for the dashboard.
     */
    public function findPrimaryByDashboardUuid(
        string $dashboardUuid
    ): ?DashboardTranslation {
        $qb = $this->db->getQueryBuilder();
        $qb->select(selects: '*')
            ->from(from: $this->getTableName())
            ->where(
                $qb->expr()->eq(
                    x: 'dashboard_uuid',
                    y: $qb->createNamedParameter(value: $dashboardUuid)
                )
            )
            ->andWhere(
                $qb->expr()->eq(
                    x: 'is_primary',
                    y: $qb->createNamedParameter(
                        value: 1,
                        type: IQueryBuilder::PARAM_INT
                    )
                )
            )
            ->setMaxResults(maxResults: 1);

        try {
            return $this->findEntity(query: $qb);
        } catch (DoesNotExistException) {
            return null;
        }
    }//end findPrimaryByDashboardUuid()

    /**
     * Resolve the best translation for a dashboard given a preferred
     * language using the spec's matching strategy. REQ-DASH-039.
     *
     * Steps:
     *  1. Normalise `preferredLanguage` to its 2-char base code.
     *  2. If a row exists for `(uuid, normalised)`, return it (exact
     *     match — covers both true exact matches and spec D2 "prefix"
     *     matches because both reduce to the same normalised code).
     *  3. Else fall back to the primary variant.
     *  4. Else return null (no rows at all — caller treats as 404 or
     *     uses the legacy fields on the parent dashboard row).
     *
     * @param string $dashboardUuid     The dashboard UUID.
     * @param string $preferredLanguage The viewer's preferred locale
     *                                  (raw, may be `nl_NL`, `nl-BE`,
     *                                  empty, etc.).
     *
     * @return DashboardTranslation|null The resolved variant or null.
     */
    public function findByDashboardUuidWithLocaleMatching(
        string $dashboardUuid,
        string $preferredLanguage
    ): ?DashboardTranslation {
        $exact = $this->findByDashboardUuidAndLanguage(
            dashboardUuid: $dashboardUuid,
            languageCode: $preferredLanguage
        );
        if ($exact !== null) {
            return $exact;
        }

        return $this->findPrimaryByDashboardUuid(
            dashboardUuid: $dashboardUuid
        );
    }//end findByDashboardUuidWithLocaleMatching()

    /**
     * Delete every translation variant attached to a dashboard.
     *
     * Used by the cascade-delete path in
     * {@see \OCA\MyDash\Service\DashboardService::deleteDashboard()}.
     *
     * @param string $dashboardUuid The dashboard UUID.
     *
     * @return int The number of rows deleted.
     */
    public function deleteByDashboardUuid(string $dashboardUuid): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete(delete: $this->getTableName())
            ->where(
                $qb->expr()->eq(
                    x: 'dashboard_uuid',
                    y: $qb->createNamedParameter(value: $dashboardUuid)
                )
            );

        return $qb->executeStatement();
    }//end deleteByDashboardUuid()

    /**
     * Clear the `is_primary` flag on every variant for a dashboard,
     * optionally excepting one UUID. Used by the promote-primary
     * transactional flip. REQ-DASH-042.
     *
     * @param string   $dashboardUuid The dashboard UUID.
     * @param int|null $exceptId      Optional row ID to leave untouched.
     *
     * @return int The number of rows affected.
     */
    public function clearPrimary(
        string $dashboardUuid,
        ?int $exceptId=null
    ): int {
        $qb = $this->db->getQueryBuilder();
        $qb->update(update: $this->getTableName())
            ->set(
                key: 'is_primary',
                value: $qb->createNamedParameter(
                    value: 0,
                    type: IQueryBuilder::PARAM_INT
                )
            )
            ->where(
                $qb->expr()->eq(
                    x: 'dashboard_uuid',
                    y: $qb->createNamedParameter(value: $dashboardUuid)
                )
            );

        if ($exceptId !== null) {
            $qb->andWhere(
                $qb->expr()->neq(
                    x: 'id',
                    y: $qb->createNamedParameter(
                        value: $exceptId,
                        type: IQueryBuilder::PARAM_INT
                    )
                )
            );
        }

        return $qb->executeStatement();
    }//end clearPrimary()

    /**
     * Count translation variants for a dashboard.
     *
     * @param string $dashboardUuid The dashboard UUID.
     *
     * @return int The number of variants.
     */
    public function countByDashboardUuid(string $dashboardUuid): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->count('*', 'cnt'))
            ->from(from: $this->getTableName())
            ->where(
                $qb->expr()->eq(
                    x: 'dashboard_uuid',
                    y: $qb->createNamedParameter(value: $dashboardUuid)
                )
            );

        $cursor = $qb->executeQuery();
        $row    = $cursor->fetch();
        $cursor->closeCursor();

        if ($row === false || isset($row['cnt']) === false) {
            return 0;
        }

        return (int) $row['cnt'];
    }//end countByDashboardUuid()
}//end class
