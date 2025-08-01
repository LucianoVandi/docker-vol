<?php

declare(strict_types=1);

namespace DockerBackup\Command;

use DockerBackup\Helper\CommandHelper;
use DockerBackup\Service\ImageRestoreService;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class RestoreImagesCommand extends AbstractRestoreCommand
{
    public function __construct(
        private readonly ImageRestoreService $imageRestoreService
    ) {
        parent::__construct();
    }

    protected function getCommandName(): string
    {
        return 'restore:images';
    }

    protected function getCommandDescription(): string
    {
        return 'Restore Docker images from tar.gz archives';
    }

    protected function getDefaultBackupDir(): string
    {
        return getcwd() . '/backups/images';
    }

    protected function getOverwriteOptionDescription(): string
    {
        return 'Overwrite existing images with the same name';
    }

    protected function getCommandHelp(): string
    {
        return <<<'HELP'
The <info>%command.name%</info> command restores Docker images from backup archives.

<info>Examples:</info>

  # Restore specific archives
  <info>php %command.full_name% nginx_latest.tar.gz mysql_8.0.tar.gz</info>

  # Restore with custom backup directory
  <info>php %command.full_name% nginx_latest.tar.gz --backup-dir=/tmp/backups</info>

  # Overwrite existing images
  <info>php %command.full_name% nginx_latest.tar.gz --overwrite</info>

  # List available backup archives
  <info>php %command.full_name% --list</info>

The command uses Docker's native load functionality to restore images from archives.
Both compressed (.tar.gz) and uncompressed (.tar) archives are supported.
By default, existing images with the same name will not be overwritten.
HELP;
    }

    protected function getOperationTitle(): string
    {
        return 'Docker Image Restore';
    }

    protected function getResourceType(): string
    {
        return 'images';
    }

    protected function displayAdditionalModeMessages(SymfonyStyle $io, InputInterface $input): void
    {
        $overwrite = $input->getOption('overwrite');

        if (!$overwrite) {
            $io->text('<info>ℹ️  Existing images will be skipped (use --overwrite to replace them)</info>');
        }
    }

    protected function performSingleRestore(string $archivePath, InputInterface $input, bool $overwrite)
    {
        return $this->imageRestoreService->restoreSingleImage($archivePath, $overwrite);
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
        return $this->imageRestoreService->getAvailableBackups($backupDir);
    }

    protected function formatResourceForTable($backup): array
    {
        return [
            $backup['name'],
            basename($backup['path']),
            $backup['compressed'] ? 'Yes' : 'No',
            CommandHelper::formatFileSize($backup['size']),
        ];
    }

    protected function getTableHeaders(): array
    {
        return ['Image Name', 'Archive File', 'Compressed', 'Size'];
    }

    protected function getListTitle(): string
    {
        return 'Available Image Backup Archives';
    }

    protected function getNoResourcesMessage(InputInterface $input): string
    {
        $backupDir = $input->getOption('backup-dir');
        return "No backup archives found in: {$backupDir}";
    }

    protected function getResourceCountLabel(InputInterface $input): string
    {
        $backupDir = $input->getOption('backup-dir');
        return "backup archives in {$backupDir}";
    }

    protected function getOverwriteWarningMessage(): string
    {
        return 'Overwrite mode enabled - existing images will be replaced';
    }

    protected function getManyArchivesThreshold(): int
    {
        return 5;
    }

    protected function getLargeArchiveThreshold(): int
    {
        return 500 * 1024 * 1024; // 500MB for images
    }

    protected function getOperationWarningTitle(): string
    {
        return 'This operation may impact your Docker registry:';
    }

    protected function getEmptyArgumentsErrorMessage(): string
    {
        return 'You must specify at least one archive file, or use --list to see available backups.';
    }

    protected function getUsageExamples(): array
    {
        return [
            'Usage: restore:images archive1.tar.gz [archive2.tar.gz ...]',
            '   or: restore:images --list'
        ];
    }
}