<?php

declare(strict_types=1);

namespace DockerBackup\Command;

use DockerBackup\Helper\CommandHelper;
use DockerBackup\Service\VolumeRestoreService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Style\SymfonyStyle;

final class RestoreVolumesCommand extends AbstractRestoreCommand
{
    public function __construct(
        private readonly VolumeRestoreService $volumeRestoreService
    ) {
        parent::__construct();
    }

    protected function getCommandName(): string
    {
        return 'restore:volumes';
    }

    protected function getCommandDescription(): string
    {
        return 'Restore Docker volumes from tar.gz archives';
    }

    protected function getDefaultBackupDir(): string
    {
        return getcwd() . '/backups/volumes';
    }

    protected function getOverwriteOptionDescription(): string
    {
        return 'Overwrite existing volumes';
    }

    protected function getCommandHelp(): string
    {
        return <<<'HELP'
The <info>%command.name%</info> command restores Docker volumes from backup archives.

<info>Examples:</info>

  # Restore specific archives
  <info>php %command.full_name% volume1.tar.gz volume2.tar.gz</info>

  # Restore with custom backup directory
  <info>php %command.full_name% volume1.tar.gz --backup-dir=/tmp/backups</info>

  # Overwrite existing volumes
  <info>php %command.full_name% volume1.tar.gz --overwrite</info>

  # List available backup archives
  <info>php %command.full_name% --list</info>

The command extracts compressed or uncompressed tar archives to Docker volumes.
Each volume is restored using a temporary Alpine container to ensure consistency.
HELP;
    }

    protected function getOperationTitle(): string
    {
        return 'Docker Volume Restore';
    }

    protected function getResourceType(): string
    {
        return 'volumes';
    }

    protected function configureAdditionalOptions(): void
    {
        $this->addOption(
            'no-create-volume',
            null,
            InputOption::VALUE_NONE,
            'Do not create volumes if they don\'t exist'
        );
    }

    protected function displayAdditionalModeMessages(SymfonyStyle $io, InputInterface $input): void
    {
        $createVolumes = !$input->getOption('no-create-volume');

        if (!$createVolumes) {
            $io->text('<comment>⚠️  Volume creation disabled - only existing volumes will be restored</comment>');
        }
    }

    protected function performSingleRestore(string $archivePath, InputInterface $input, bool $overwrite)
    {
        $createVolumes = !$input->getOption('no-create-volume');
        return $this->volumeRestoreService->restoreSingleVolume($archivePath, $overwrite, $createVolumes);
    }

    // Trait implementations
    protected function getOperationEmoji(): string
    {
        return '📦';
    }

    protected function getOperationVerb(): string
    {
        return 'Restoring';
    }

    protected function getAvailableResources(InputInterface $input): array
    {
        $backupDir = $input->getOption('backup-dir');
        return $this->volumeRestoreService->getAvailableBackups($backupDir);
    }

    protected function formatResourceForTable($backup): array
    {
        return [
            $backup['volume'],
            basename($backup['path']),
            $backup['compressed'] ? 'Yes' : 'No',
            CommandHelper::formatFileSize($backup['size']),
        ];
    }

    protected function getTableHeaders(): array
    {
        return ['Volume Name', 'Archive File', 'Compressed', 'Size'];
    }

    protected function getListTitle(): string
    {
        return 'Available Backup Archives';
    }

    protected function getNoResourcesMessage(InputInterface $input): string
    {
        $backupDir = $input->getOption('backup-dir');
        return "No backup archives found in: $backupDir";
    }

    protected function getResourceCountLabel(InputInterface $input): string
    {
        $backupDir = $input->getOption('backup-dir');
        return "backup archives in $backupDir";
    }

    protected function getOverwriteWarningMessage(): string
    {
        return 'Overwrite mode enabled - existing volumes will be replaced';
    }

    protected function getManyArchivesThreshold(): int
    {
        return 3;
    }

    protected function getLargeArchiveThreshold(): int
    {
        return 100 * 1024 * 1024; // 100MB for volumes
    }

    protected function getOperationWarningTitle(): string
    {
        return 'This operation may be destructive:';
    }

    protected function getEmptyArgumentsErrorMessage(): string
    {
        return 'You must specify at least one archive file, or use --list to see available backups.';
    }

    protected function getUsageExamples(): array
    {
        return [
            'Usage: restore:volumes archive1.tar.gz [archive2.tar.gz ...]',
            '   or: restore:volumes --list'
        ];
    }
}