<?php

declare(strict_types=1);

namespace DockerBackup\Command;

use DockerBackup\Helper\CommandHelper;
use DockerBackup\Service\ImageRestoreService;
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

final class RestoreImagesCommand extends Command
{
    use ProgressDisplayTrait, ListableResourceTrait, DestructiveOperationTrait, ArgumentValidationTrait;

    public function __construct(
        private readonly ImageRestoreService $imageRestoreService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $defaultDir = getcwd() . '/backups/images';

        $this->setName('restore:images')
            ->setDescription('Restore Docker images from tar.gz archives')
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
                'Overwrite existing images with the same name'
            )
            ->addOption(
                'list',
                'l',
                InputOption::VALUE_NONE,
                'List available backup archives and exit'
            )
            ->setHelp(
                <<<'HELP'
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

        $io->title('Docker Image Restore');
        $io->text("Restoring images from: <info>{$backupDir}</info>");

        if ($overwrite) {
            $io->text('<comment>⚠️  Overwrite mode enabled - existing images will be replaced</comment>');
        } else {
            $io->text('<info>ℹ️  Existing images will be skipped (use --overwrite to replace them)</info>');
        }

        // Perform restores
        $io->writeln(sprintf('Starting restore of <info>%d</info> archive(s)...', count($archivePaths)));
        $io->newLine();

        $results = $this->performOperationsWithProgress(
            $archivePaths,
            $io,
            fn($archivePath) => $this->imageRestoreService->restoreSingleImage($archivePath, $overwrite)
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
