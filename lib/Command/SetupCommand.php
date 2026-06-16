<?php

/**
 * SetupCommand
 *
 * `php occ mydash:setup --config=/path/setup.yaml` — non-interactive
 * IaC-friendly entry point for the setup wizard (REQ-WIZ-010). Walks
 * the same step sequence as the Vue modal but reads every choice from
 * a YAML config file. Idempotent: re-running with the same config
 * detects existing values and skips them.
 *
 * @category  Command
 * @package   OCA\MyDash\Command
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

namespace OCA\MyDash\Command;

use InvalidArgumentException;
use OCA\MyDash\Service\AdminSettingsService;
use OCA\MyDash\Service\SetupWizardService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Throwable;

/**
 * `mydash:setup` console command.
 */
class SetupCommand extends Command
{
    /**
     * Constructor.
     *
     * @param SetupWizardService   $wizardService Wizard orchestrator.
     * @param AdminSettingsService $settings      Group-order persistence
     *                                            (Step 3 in the YAML
     *                                            schema).
     */
    public function __construct(
        private readonly SetupWizardService $wizardService,
        private readonly AdminSettingsService $settings,
    ) {
        parent::__construct();
    }//end __construct()

    /**
     * Configure CLI options.
     *
     * @return void
     *
     * @spec openspec/specs/setup-wizard/spec.md
     */
    protected function configure(): void
    {
        $this->setName(name: 'mydash:setup')
            ->setDescription(
                description: 'Run the MyDash setup wizard non-interactively from a YAML config (REQ-WIZ-010).'
            )
            ->addOption(
                name: 'config',
                shortcut: 'c',
                mode: InputOption::VALUE_REQUIRED,
                description: 'Path to the YAML config file describing every step.'
            );
    }//end configure()

    /**
     * Execute the wizard from a YAML file.
     *
     * @param InputInterface  $input  CLI input.
     * @param OutputInterface $output CLI output.
     *
     * @return int Exit code (0 success, 1 error).
     *
     * @spec openspec/specs/setup-wizard/spec.md
     */
    protected function execute(
        InputInterface $input,
        OutputInterface $output
    ): int {
        $configPath = (string) ($input->getOption(name: 'config') ?? '');

        if ($configPath === '') {
            $output->writeln(messages: '<error>--config parameter is required</error>');
            return self::FAILURE;
        }

        if (file_exists(filename: $configPath) === false) {
            $output->writeln(messages: '<error>File not found: '.$configPath.'</error>');
            return self::FAILURE;
        }

        try {
            $config = Yaml::parseFile(filename: $configPath);
        } catch (ParseException $e) {
            $output->writeln(
                messages: '<error>Invalid setup.yaml: '.$e->getMessage().'</error>'
            );
            return self::FAILURE;
        }

        if (is_array($config) === false) {
            $output->writeln(
                messages: '<error>Invalid setup.yaml: top-level structure must be a map.</error>'
            );
            return self::FAILURE;
        }

        if (isset($config['storage_backend']) === false
            || is_string($config['storage_backend']) === false
        ) {
            $output->writeln(
                messages: "<error>Invalid setup.yaml: missing field 'storage_backend'</error>"
            );
            return self::FAILURE;
        }

        try {
            $this->applySteps(config: $config, output: $output);
        } catch (InvalidArgumentException $e) {
            $output->writeln(messages: '<error>'.$e->getMessage().'</error>');
            return self::FAILURE;
        } catch (Throwable $e) {
            $output->writeln(
                messages: '<error>Setup failed: '.$e->getMessage().'</error>'
            );
            return self::FAILURE;
        }

        $this->wizardService->markWizardComplete();
        $output->writeln(messages: 'Setup wizard completed successfully.');
        return self::SUCCESS;
    }//end execute()

    /**
     * Apply each non-Welcome step in order, logging progress + idempotency.
     *
     * @param array<string,mixed> $config Parsed YAML config.
     * @param OutputInterface     $output CLI output for progress logging.
     *
     * @return void
     */
    private function applySteps(array $config, OutputInterface $output): void
    {
        $output->writeln(messages: 'Step 1: Welcome... done');

        $this->applyStorageStep(config: $config, output: $output);
        $this->applyGroupOrderStep(config: $config, output: $output);
        $this->skipUnimplementedStep(
            stepNumber: 4,
            stepName: 'Demo data',
            present: array_key_exists(key: 'demo_packages', array: $config),
            output: $output
        );
        $this->skipUnimplementedStep(
            stepNumber: 5,
            stepName: 'Admin roles',
            present: array_key_exists(key: 'admin_role_group', array: $config),
            output: $output
        );
        $this->skipUnimplementedStep(
            stepNumber: 6,
            stepName: 'Footer config',
            present: array_key_exists(key: 'footer_config', array: $config),
            output: $output
        );

        $output->writeln(messages: 'Step 7: Done... done');
    }//end applySteps()

    /**
     * Apply Step 2 — storage backend (REQ-WIZ-003).
     *
     * @param array<string,mixed> $config Parsed YAML.
     * @param OutputInterface     $output CLI output.
     *
     * @return void
     */
    private function applyStorageStep(array $config, OutputInterface $output): void
    {
        $current = $this->wizardService->getContentStorage();
        $target  = (string) $config['storage_backend'];

        if ($current === $target) {
            $output->writeln(
                messages: 'Step 2: Storage backend... already configured, skipping'
            );
            return;
        }

        $this->wizardService->setContentStorage(value: $target);
        $output->writeln(messages: 'Step 2: Storage backend... done');
    }//end applyStorageStep()

    /**
     * Apply Step 3 — group priority order (REQ-WIZ-004).
     *
     * @param array<string,mixed> $config Parsed YAML.
     * @param OutputInterface     $output CLI output.
     *
     * @return void
     */
    private function applyGroupOrderStep(
        array $config,
        OutputInterface $output
    ): void {
        if (array_key_exists(key: 'group_priority_order', array: $config) === false) {
            $output->writeln(messages: 'Step 3: Group order... skipped (not in config)');
            return;
        }

        $groups = $config['group_priority_order'];
        if (is_array($groups) === false) {
            throw new InvalidArgumentException(
                message: "Invalid setup.yaml: 'group_priority_order' must be a list of strings"
            );
        }

        $current = $this->settings->getGroupOrder();
        if ($current === array_values(array: $groups)) {
            $output->writeln(
                messages: 'Step 3: Group order... already configured, skipping'
            );
            return;
        }

        $this->settings->setGroupOrder(groupIds: $groups);
        $output->writeln(messages: 'Step 3: Group order... done');
    }//end applyGroupOrderStep()

    /**
     * Log a placeholder for steps whose sibling capabilities ship later.
     *
     * @param int             $stepNumber Step index for the log line.
     * @param string          $stepName   Human-readable step name.
     * @param bool            $present    Whether the YAML key was provided.
     * @param OutputInterface $output     CLI output.
     *
     * @return void
     */
    private function skipUnimplementedStep(
        int $stepNumber,
        string $stepName,
        bool $present,
        OutputInterface $output
    ): void {
        if ($present === false) {
            $output->writeln(
                messages: 'Step '.$stepNumber.': '.$stepName.'... skipped (not in config)'
            );
            return;
        }

        $output->writeln(
            messages: 'Step '.$stepNumber.': '.$stepName.'... skipped (capability pending)'
        );
    }//end skipUnimplementedStep()
}//end class
