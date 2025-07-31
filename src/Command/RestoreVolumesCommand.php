<?php

declare(strict_types=1);

namespace DockerBackup\Command;

use DockerBackup\Helper\CommandHelper;
use DockerBackup\Service\VolumeRestoreService;
use DockerBackup\Trait\ArgumentValidationTrait;
use DockerBackup\Trait\DestructiveOperationTrait;
use DockerBackup\Trait\ListableResourceTrait;
use DockerBackup\Trait\ProgressDisplayTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class RestoreVolumesCommand extends Command
{
    use ProgressDisplayTrait, ListableResourceTrait, DestructiveOperationTrait, ArgumentValidationTrait;

    public function __construct(
        private readonly VolumeRestoreService $volumeRestoreService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $defaultDir = getcwd() . '/backups/volumes';

        $this->setName('restore:volumes')
            ->setDescription('Restore Docker volumes from tar.gz archives')
            ->addArgument(
                'archives',
                InputArgument::IS_ARRAY,
                'Backup archive files to restore (.tar or .tar.gz)'
            )
            ->addOption(
                'backup-dir',
                'b',
                InputOption::VALUE_REQUIRED,
                'Directory containing backup files',
                $defaultDir
            )
            ->addOption(
                'overwrite',
                null,
                InputOption::VALUE_NONE,
                'Overwrite existing volumes'
            )
            ->addOption(
                'no-create-volume',
                null,
                InputOption::VALUE_NONE,
                'Do not create volumes if they don\'t exist'
            )
            ->addOption(
                'list',
                'l',
                InputOption::VALUE_NONE,
                'List available backup archives and exit'
            )
            ->setHelp(
                <<<'HELP'
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

        $archiveNames = $input->getArgument('archives');
        $backupDir = $input->getOption('backup-dir');
        $overwrite = $input->getOption('overwrite');
        $createVolumes = !$input->getOption('no-create-volume');

        // Check if archives argument is provided
        if (!$this->validateRequiredArguments($archiveNames, $io)) {
            return Command::FAILURE;
        }

        // Resolve full paths for archives
        $archivePaths = CommandHelper::resolveArchivePaths($archiveNames, $backupDir);

        // Validate all archives exist
        $missingArchives = array_filter($archivePaths, fn ($path) => !file_exists($path));
        if (!empty($missingArchives)) {
            $io->error('The following archive files do not exist:');
            foreach ($missingArchives as $missing) {
                $io->text("  - {$missing}");
            }

            return Command::FAILURE;
        }

        // Validate archive integrity (quick check)
        $io->text('🔍 Validating archives...');
        $invalidArchives = CommandHelper::validateArchivesIntegrity($archivePaths);
        if (!empty($invalidArchives)) {
            $io->error('The following archives failed validation:');
            foreach ($invalidArchives as $invalid => $reason) {
                $io->text("  - {$invalid}: {$reason}");
            }

            return Command::FAILURE;
        }

        $io->text('<info>✅ All archives validated successfully</info>');
        $io->newLine();

        if (!$this->confirmDestructiveOperation($archivePaths, $overwrite, $io)) {
            $io->text('Operation cancelled by user.');

            return Command::SUCCESS;
        }

        $io->title('Docker Volume Restore');
        $io->text("Restoring volumes from: <info>{$backupDir}</info>");

        if ($overwrite) {
            $io->text('<comment>⚠️  Overwrite mode enabled - existing volumes will be replaced</comment>');
        }

        if (!$createVolumes) {
            $io->text('<comment>⚠️  Volume creation disabled - only existing volumes will be restored</comment>');
        }

        // Perform restores
        $io->writeln(sprintf('Starting restore of <info>%d</info> archive(s)...', count($archivePaths)));
        $io->newLine();

        $results = $this->performOperationsWithProgress(
            $archivePaths,
            $io,
            fn($archivePath) => $this->volumeRestoreService->restoreSingleVolume($archivePath, $overwrite, $createVolumes)
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
