<?php

declare(strict_types=1);

namespace DockerBackup\Command;

use DockerBackup\Service\VolumeBackupService;
use DockerBackup\Trait\ArgumentValidationTrait;
use DockerBackup\Trait\ListableResourceTrait;
use DockerBackup\Trait\ProgressDisplayTrait;
use DockerBackup\ValueObject\DockerVolume;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class BackupVolumesCommand extends Command
{
    use ProgressDisplayTrait, ListableResourceTrait, ArgumentValidationTrait;

    public function __construct(
        private readonly VolumeBackupService $volumeBackupService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $defaultDir = getcwd() . '/backups/volumes';

        $this->setName('backup:volumes')
            ->setDescription('Backup Docker volumes to tar.gz archives')
            ->addArgument(
                'volumes',
                InputArgument::IS_ARRAY,  // Rimuovo REQUIRED
                'Names of volumes to backup'
            )
            ->addOption(
                'output-dir',
                'o',
                InputOption::VALUE_REQUIRED,
                'Output directory for backup files',
                $defaultDir
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
                'List available volumes and exit'
            )
            ->setHelp(
                <<<'HELP'
The <info>%command.name%</info> command creates backups of Docker volumes.

<info>Examples:</info>

  # Backup specific volumes
  <info>php %command.full_name% volume1 volume2 volume3</info>

  # Backup with custom output directory
  <info>php %command.full_name% volume1 --output-dir=/tmp/backups</info>

  # List available volumes
  <info>php %command.full_name% --list</info>

The command creates compressed tar.gz archives of volume contents.
Each volume is backed up using a temporary Alpine container to ensure consistency.
HELP
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);

        // Handle list option
        if ($input->getOption('list')) {
            return $this->handleListOption($io, $input);
        }

        $volumeNames = $input->getArgument('volumes');
        $compress = !$input->getOption('no-compression');

        // Check if volumes argument is provided
        if (!$this->validateRequiredArguments($volumeNames, $io)) {
            return Command::FAILURE;
        }

        $outputDir = $input->getOption('output-dir');

        $io->title('Docker Volume Backup');
        $io->text("Backing up volumes to: <info>{$outputDir}</info>");

        // Validate volumes exist
        $availableVolumes = $this->volumeBackupService->getAvailableVolumes();
        $availableVolumeNames = array_map(fn ($vol) => $vol->name, $availableVolumes);

        $invalidVolumes = array_diff($volumeNames, $availableVolumeNames);
        if (!empty($invalidVolumes)) {
            $io->error('The following volumes do not exist: ' . implode(', ', $invalidVolumes));

            return Command::FAILURE;
        }

        // Perform backups
        $io->writeln(sprintf('Starting backup of <info>%d</info> volume(s)...', count($volumeNames)));
        $io->newLine();

        $results = $this->performOperationsWithProgress(
            $volumeNames,
            $io,
            fn($volumeName) => $this->volumeBackupService->backupSingleVolume($volumeName, $outputDir, $compress)
        );

        $this->displaySummary($io, $results);

        // Return appropriate exit code
        $failedCount = count(array_filter($results, fn ($r) => $r->isFailed()));

        return $failedCount > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    protected function getOperationEmoji(): string
    {
        return '📦';
    }

    protected function getOperationVerb(): string
    {
        return 'Backing up';
    }

    protected function getAvailableResources(InputInterface $input): array
    {
        return $this->volumeBackupService->getAvailableVolumes();
    }

    /**
     * @param DockerVolume $volume
     */
    protected function formatResourceForTable($volume): array
    {
        return [
            $volume->name,
            $volume->driver,
            $volume->mountpoint ?: 'N/A',
        ];
    }

    protected function getTableHeaders(): array
    {
        return ['Name', 'Driver', 'Mount Point'];
    }

    protected function getListTitle(): string
    {
        return 'Available Docker Volumes';
    }

    protected function getNoResourcesMessage(InputInterface $input): string
    {
        return 'No Docker volumes found.';
    }

    protected function getResourceCountLabel(InputInterface $input): string
    {
        return 'volumes';
    }

    protected function getEmptyArgumentsErrorMessage(): string
    {
        return 'You must specify at least one volume name, or use --list to see available volumes.';
    }

    protected function getUsageExamples(): array
    {
        return [
            'Usage: backup:volumes volume1 [volume2 ...]',
            '   or: backup:volumes --list'
        ];
    }
}
