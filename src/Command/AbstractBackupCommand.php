<?php

declare(strict_types=1);

namespace DockerVol\Command;

use DockerVol\Trait\ArgumentValidationTrait;
use DockerVol\Trait\ListableResourceTrait;
use DockerVol\Trait\ProgressDisplayTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

abstract class AbstractBackupCommand extends Command
{
    use ProgressDisplayTrait;
    use ListableResourceTrait;
    use ArgumentValidationTrait;

    protected function configure(): void
    {
        $this->setName($this->getCommandName())
            ->setDescription($this->getCommandDescription())
            ->addArgument(
                $this->getArgumentName(),
                InputArgument::IS_ARRAY,
                $this->getArgumentDescription()
            )
            ->addOption(
                'output-dir',
                'o',
                InputOption::VALUE_REQUIRED,
                'Output directory for backup files',
                $this->getDefaultOutputDir()
            )
            ->addOption(
                'no-compression',
                null,
                InputOption::VALUE_NONE,
                'Create uncompressed tar archives instead of gzip compressed'
            )
            ->addOption(
                'list',
                'l',
                InputOption::VALUE_NONE,
                'List available ' . $this->getResourceType() . ' and exit'
            )
            ->addOption(
                'timeout',
                null,
                InputOption::VALUE_REQUIRED,
                'Override Docker command timeout in seconds (default: BACKUP_TIMEOUT env or 300)'
            )
            ->setHelp($this->getCommandHelp())
        ;

        // Allow subclasses to add additional options
        $this->configureAdditionalOptions();
    }

    final protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Handle list option
        if ($input->getOption('list')) {
            return $this->handleListOption($io, $input);
        }

        $resourceNames = $input->getArgument($this->getArgumentName());
        $outputDir = $input->getOption('output-dir');
        $compress = !$input->getOption('no-compression');

        $timeoutOption = $input->getOption('timeout');
        if ($timeoutOption !== null) {
            $this->applyDockerTimeout((int) $timeoutOption);
        }

        // Check if resources argument is provided
        if (!$this->validateRequiredArguments($resourceNames, $io)) {
            return Command::FAILURE;
        }

        $io->title($this->getOperationTitle());
        $io->text("Backing up {$this->getResourceType()} to: <info>{$outputDir}</info>");

        // Validate resources exist
        $validationResult = $this->validateResourcesExist($resourceNames, $io);
        if ($validationResult !== Command::SUCCESS) {
            return $validationResult;
        }

        $this->displayOperationModeMessages($io, $input, $compress);

        // Perform backups
        $io->writeln(sprintf('Starting backup of <info>%d</info> %s...', count($resourceNames), $this->getResourceType()));
        $io->newLine();

        $results = $this->performOperationsWithProgress(
            $resourceNames,
            $io,
            fn ($resourceName) => $this->performSingleBackup($resourceName, $input, $outputDir, $compress)
        );

        $this->displaySummary($io, $results);

        // Return appropriate exit code
        $failedCount = count(array_filter($results, fn ($r) => $r->isFailed()));

        return $failedCount > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Display mode-specific messages (compression info, etc.).
     */
    protected function displayOperationModeMessages(SymfonyStyle $io, InputInterface $input, bool $compress): void
    {
        if (!$compress) {
            $io->text('<info>ℹ️  Compression disabled - creating uncompressed tar archives</info>');
        }

        $this->displayAdditionalModeMessages($io, $input);
    }

    // Template methods - must be implemented by subclasses
    abstract protected function getCommandName(): string;

    abstract protected function getCommandDescription(): string;

    abstract protected function getArgumentName(): string;

    abstract protected function getArgumentDescription(): string;

    abstract protected function getDefaultOutputDir(): string;

    abstract protected function getCommandHelp(): string;

    abstract protected function getOperationTitle(): string;

    abstract protected function getResourceType(): string;

    abstract protected function validateResourcesExist(array $resourceNames, SymfonyStyle $io): int;

    abstract protected function performSingleBackup(string $resourceName, InputInterface $input, string $outputDir, bool $compress);

    // Optional template methods - can be overridden by subclasses
    protected function configureAdditionalOptions(): void
    {
        // Default: no additional options
    }

    protected function applyDockerTimeout(int $seconds): void
    {
        // Default: no-op; subclasses override to propagate to their service
    }

    protected function displayAdditionalModeMessages(SymfonyStyle $io, InputInterface $input): void
    {
        // Default: no additional messages
    }
}
