<?php

/**
 * MetricsCollector
 *
 * Service for collecting Prometheus metrics in text exposition format.
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

use OCA\MyDash\AppInfo\Application;
use OCP\IConfig;
use Psr\Log\LoggerInterface;

/**
 * Collects Prometheus metrics lines for text exposition.
 */
class MetricsCollector
{
    /**
     * Constructor
     *
     * @param IConfig             $config       The config service.
     * @param MetricsQueryService $queryService The metrics query service.
     * @param LoggerInterface     $logger       Logger for error reporting.
     */
    public function __construct(
        private readonly IConfig $config,
        private readonly MetricsQueryService $queryService,
        private readonly LoggerInterface $logger,
    ) {
    }//end __construct()

    /**
     * Collect all metrics lines for Prometheus exposition.
     *
     * @return array The lines of Prometheus metrics output.
     */
    public function collectAll(): array
    {
        $lines = [];

        $this->addInfoMetric(lines: $lines);
        $this->addUpMetric(lines: $lines);
        $this->addDashboardMetrics(lines: $lines);
        $this->addCountMetric(
            lines: $lines,
            tableName: 'mydash_widget_placements',
            metricName: 'mydash_widgets_total',
            helpText: 'Total number of widget placements'
        );
        $this->addCountMetric(
            lines: $lines,
            tableName: 'mydash_tiles',
            metricName: 'mydash_tiles_total',
            helpText: 'Total number of tiles'
        );

        return $lines;
    }//end collectAll()

    /**
     * Add the application info metric.
     *
     * @param array $lines Reference to the metrics output lines.
     *
     * @return void
     */
    private function addInfoMetric(array &$lines): void
    {
        $appVersion = $this->config->getAppValue(Application::APP_ID, 'installed_version', '0.0.0');
        $phpVersion = PHP_VERSION;
        $ncVersion  = $this->config->getSystemValueString(
            key: 'version',
            default: '0.0.0'
        );

        $lines[] = '# HELP mydash_info Application information';
        $lines[] = '# TYPE mydash_info gauge';
        $label   = 'version="'.$appVersion.'",php_version="'.$phpVersion.'"';
        $label   = $label.',nextcloud_version="'.$ncVersion.'"';
        $lines[] = 'mydash_info{'.$label.'} 1';
    }//end addInfoMetric()

    /**
     * Add the application up metric.
     *
     * @param array $lines Reference to the metrics output lines.
     *
     * @return void
     */
    private function addUpMetric(array &$lines): void
    {
        $lines[] = '# HELP mydash_up Whether the application is up';
        $lines[] = '# TYPE mydash_up gauge';
        $lines[] = 'mydash_up 1';
    }//end addUpMetric()

    /**
     * Add dashboard count metrics grouped by type.
     *
     * @param array $lines Reference to the metrics output lines.
     *
     * @return void
     */
    private function addDashboardMetrics(array &$lines): void
    {
        $lines[] = '# HELP mydash_dashboards_total Total dashboards by type';
        $lines[] = '# TYPE mydash_dashboards_total gauge';

        try {
            $counts = $this->queryService->queryDashboardCounts();
            foreach ($counts as $type => $count) {
                $lines[] = 'mydash_dashboards_total{type="'.$type.'"} '.$count;
            }
        } catch (\Exception $e) {
            $this->logger->warning('Could not count dashboards for metrics', ['exception' => $e->getMessage()]);
            $lines[] = 'mydash_dashboards_total{type="personal"} 0';
            $lines[] = 'mydash_dashboards_total{type="template"} 0';
        }//end try
    }//end addDashboardMetrics()

    /**
     * Add a simple table count metric.
     *
     * @param array  $lines      Reference to the metrics output lines.
     * @param string $tableName  The table to count.
     * @param string $metricName The Prometheus metric name.
     * @param string $helpText   The HELP text.
     *
     * @return void
     */
    private function addCountMetric(array &$lines, string $tableName, string $metricName, string $helpText): void
    {
        $count   = $this->queryService->countTable($tableName);
        $lines[] = '# HELP '.$metricName.' '.$helpText;
        $lines[] = '# TYPE '.$metricName.' gauge';
        $lines[] = $metricName.' '.$count;
    }//end addCountMetric()
}//end class
