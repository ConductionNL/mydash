<?php

/**
 * MetricsQueryService
 *
 * Service for querying metrics data from the database.
 *
 * @category  Service
 * @package   OCA\MyDash\Service
 * @author    Conduction b.v. <info@conduction.nl>
 * @copyright 2024 Conduction b.v.
 * @license   EUPL-1.2 https://joinup.ec.europa.eu/collection/eupl/eupl-text-eupl-12
 * @version   GIT:auto
 * @link      https://conduction.nl
 */

declare(strict_types=1);

namespace OCA\MyDash\Service;

use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Queries metrics data from the database.
 */
class MetricsQueryService
{
    /**
     * Constructor
     *
     * @param IDBConnection   $db     The database connection.
     * @param LoggerInterface $logger Logger for error reporting.
     */
    public function __construct(
        private readonly IDBConnection $db,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Query dashboard counts grouped by type from the database.
     *
     * @return array<string, int> Map of type to count.
     *
     * @throws \Exception When the database query fails.
     */
    public function queryDashboardCounts(): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('type', $qb->createFunction('COUNT(*) AS cnt'))
            ->from('mydash_dashboards')
            ->groupBy('type');

        $result = $qb->executeQuery();
        $rows   = $result->fetchAll();
        $result->closeCursor();

        $counts = ['personal' => 0, 'template' => 0];
        foreach ($rows as $row) {
            $type = 'personal';
            if ($row['type'] !== null && $row['type'] !== '') {
                $type = $row['type'];
            }

            if (isset($counts[$type]) === false) {
                $counts[$type] = 0;
            }

            $counts[$type] = $counts[$type] + (int) $row['cnt'];
        }

        return $counts;
    }//end queryDashboardCounts()

    /**
     * Count rows in a given table.
     *
     * @param string $tableName The table name.
     *
     * @return int The row count.
     */
    public function countTable(string $tableName): int
    {
        try {
            $qb = $this->db->getQueryBuilder();
            $qb->select($qb->createFunction('COUNT(*) AS cnt'))
                ->from($tableName);

            $result = $qb->executeQuery();
            $count  = (int) $result->fetchOne();
            $result->closeCursor();

            return $count;
        } catch (\Exception $e) {
            $this->logger->warning('Could not count '.$tableName.' for metrics', ['exception' => $e->getMessage()]);
            return 0;
        }
    }//end countTable()
}//end class
