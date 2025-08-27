<?php

declare(strict_types=1);

namespace DockerBackup\Command;

use DockerBackup\Service\VolumeBackupService;
use DockerBackup\ValueObject\DockerVolume;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class BackupVolumesCommand extends AbstractBackupCommand
{
    public function __construct(
        private readonly VolumeBackupService $volumeBackupService
    ) {
        parent::__construct();
    }

    protected function getCommandName(): string
    {
        return 'backup:volumes';
    }

    protected function getCommandDescription(): string
    {
        return 'Backup Docker volumes to tar.gz archives';
    }

    protected function getArgumentName(): string
    {
        return 'volumes';
    }

    protected function getArgumentDescription(): string
    {
        return 'Names of volumes to backup';
    }

    protected function getDefaultOutputDir(): string
    {
        return getcwd() . '/backups/volumes';
    }

    protected function getCommandHelp(): string
    {
        return <<<'HELP'
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
HELP;
    }

    protected function getOperationTitle(): string
    {
        return 'Docker Volume Backup';
    }

    protected function getResourceType(): string
    {
        return 'volumes';
    }

    protected function validateResourcesExist(array $volumeNames, SymfonyStyle $io): int
    {
        $availableVolumes = $this->volumeBackupService->getAvailableVolumes();
        $availableVolumeNames = array_map(fn ($vol) => $vol->name, $availableVolumes);

        $invalidVolumes = array_diff($volumeNames, $availableVolumeNames);
        if (!empty($invalidVolumes)) {
            $io->error('The following volumes do not exist: ' . implode(', ', $invalidVolumes));

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    protected function performSingleBackup(string $volumeName, InputInterface $input, string $outputDir, bool $compress)
    {
        return $this->volumeBackupService->backupSingleVolume($volumeName, $outputDir, $compress);
    }

    // Trait implementations
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
            '   or: backup:volumes --list',
        ];
    }
}
