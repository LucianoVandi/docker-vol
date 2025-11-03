<?php

declare(strict_types=1);

namespace DockerVol\Command;

use DockerVol\Helper\CommandHelper;
use DockerVol\Trait\ArgumentValidationTrait;
use DockerVol\Trait\DestructiveOperationTrait;
use DockerVol\Trait\ListableResourceTrait;
use DockerVol\Trait\ProgressDisplayTrait;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

abstract class AbstractRestoreCommand extends Command
{
    use ProgressDisplayTrait;
    use ListableResourceTrait;
    use DestructiveOperationTrait;
    use ArgumentValidationTrait;

    protected function configure(): void
    {
        $this->setName($this->getCommandName())
            ->setDescription($this->getCommandDescription())
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
                $this->getDefaultBackupDir()
            )
            ->addOption(
                'overwrite',
                null,
                InputOption::VALUE_NONE,
                $this->getOverwriteOptionDescription()
            )
            ->addOption(
                'list',
                'l',
                InputOption::VALUE_NONE,
                'List available backup archives and exit'
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

        $archiveNames = $input->getArgument('archives');
        $backupDir = $input->getOption('backup-dir');
        $overwrite = $input->getOption('overwrite');

        $timeoutOption = $input->getOption('timeout');
        if ($timeoutOption !== null) {
            $this->applyDockerTimeout((int) $timeoutOption);
        }

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

        $io->title($this->getOperationTitle());
        $io->text("Restoring {$this->getResourceType()} from: <info>{$backupDir}</info>");

        $this->displayOperationModeMessages($io, $input, $overwrite);

        // Perform restores
        $io->writeln(sprintf('Starting restore of <info>%d</info> archive(s)...', count($archivePaths)));
        $io->newLine();

        $results = $this->performOperationsWithProgress(
            $archivePaths,
            $io,
            fn ($archivePath) => $this->performSingleRestore($archivePath, $input, $overwrite)
        );

        $this->displaySummary($io, $results);

        // Return appropriate exit code
        $failedCount = count(array_filter($results, fn ($r) => $r->isFailed()));

        return $failedCount > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * Display mode-specific messages (overwrite warnings, etc.).
     */
    protected function displayOperationModeMessages(SymfonyStyle $io, InputInterface $input, bool $overwrite): void
    {
        if ($overwrite) {
            $io->text('<comment>⚠️  Overwrite mode enabled - existing ' . $this->getResourceType() . ' will be replaced</comment>');
        }

        $this->displayAdditionalModeMessages($io, $input);
    }

    // Template methods - must be implemented by subclasses
    abstract protected function getCommandName(): string;

    abstract protected function getCommandDescription(): string;

    abstract protected function getDefaultBackupDir(): string;

    abstract protected function getOverwriteOptionDescription(): string;

    abstract protected function getCommandHelp(): string;

    abstract protected function getOperationTitle(): string;

    abstract protected function getResourceType(): string;

    abstract protected function performSingleRestore(string $archivePath, InputInterface $input, bool $overwrite);

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
