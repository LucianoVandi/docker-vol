<?php

declare(strict_types=1);

namespace DockerBackup\Command;

use DockerBackup\Service\ImageBackupService;
use DockerBackup\Trait\ArgumentValidationTrait;
use DockerBackup\Trait\ListableResourceTrait;
use DockerBackup\Trait\ProgressDisplayTrait;
use DockerBackup\ValueObject\DockerImage;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class BackupImagesCommand extends Command
{
    use ProgressDisplayTrait, ListableResourceTrait, ArgumentValidationTrait;

    public function __construct(
        private readonly ImageBackupService $imageBackupService
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $defaultDir = getcwd() . '/backups/images';

        $this->setName('backup:images')
            ->setDescription('Backup Docker images to tar.gz archives')
            ->addArgument(
                'images',
                InputArgument::IS_ARRAY,
                'Names or IDs of images to backup (e.g., nginx:latest, sha256:abc123...)'
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
                'List available images and exit'
            )
            ->setHelp(
                <<<'HELP'
The <info>%command.name%</info> command creates backups of Docker images.

<info>Examples:</info>

  # Backup specific images by name and tag
  <info>php %command.full_name% nginx:latest mysql:8.0 redis:alpine</info>

  # Backup image by ID
  <info>php %command.full_name% sha256:1234567890abcdef</info>

  # Backup with custom output directory
  <info>php %command.full_name% nginx:latest --output-dir=/tmp/backups</info>

  # Create uncompressed archives
  <info>php %command.full_name% nginx:latest --no-compression</info>

  # List available images
  <info>php %command.full_name% --list</info>

The command uses Docker's native save functionality to create portable image archives.
Compressed archives (.tar.gz) are created by default for space efficiency.
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

        $imageReferences = $input->getArgument('images');
        $compress = !$input->getOption('no-compression');

        // Check if images argument is provided
        if (!$this->validateRequiredArguments($imageReferences, $io)) {
            return Command::FAILURE;
        }

        $outputDir = $input->getOption('output-dir');

        $io->title('Docker Image Backup');
        $io->text("Backing up images to: <info>{$outputDir}</info>");

        // Validate images exist
        $availableImages = $this->imageBackupService->getAvailableImages();
        $availableImageRefs = $this->extractImageReferences($availableImages);

        $invalidImages = $this->findInvalidImageReferences($imageReferences, $availableImageRefs);
        if (!empty($invalidImages)) {
            $io->error('The following images do not exist:');
            foreach ($invalidImages as $invalid) {
                $io->text("  - {$invalid}");
            }
            $io->newLine();
            $io->text('💡 Tip: Use --list to see all available images');

            return Command::FAILURE;
        }

        // Perform backups
        $io->writeln(sprintf('Starting backup of <info>%d</info> image(s)...', count($imageReferences)));
        $io->newLine();

        $results = $this->performOperationsWithProgress(
            $imageReferences,
            $io,
            fn($imageReference) => $this->imageBackupService->backupSingleImage($imageReference, $outputDir, $compress)
        );

        $this->displaySummary($io, $results);

        // Return appropriate exit code
        $failedCount = count(array_filter($results, fn ($r) => $r->isFailed()));

        return $failedCount > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    private function extractImageReferences(array $images): array
    {
        $references = [];

        foreach ($images as $image) {
            // Add image ID
            $references[] = $image->id;
            $references[] = $image->getShortId();

            // Add all repository tags
            foreach ($image->repoTags as $tag) {
                $references[] = $tag;
            }
        }

        return array_unique($references);
    }

    private function findInvalidImageReferences(array $requestedImages, array $availableRefs): array
    {
        $invalid = [];

        foreach ($requestedImages as $imageRef) {
            $found = false;

            // Check exact match first
            if (in_array($imageRef, $availableRefs, true)) {
                $found = true;
            } else {
                // Check if it's a partial ID match (at least 12 chars)
                if (strlen($imageRef) >= 12) {
                    foreach ($availableRefs as $availableRef) {
                        if (str_starts_with($availableRef, $imageRef)) {
                            $found = true;

                            break;
                        }
                    }
                }
            }

            if (!$found) {
                $invalid[] = $imageRef;
            }
        }

        return $invalid;
    }

    /**
     * @throws \Exception
     */
    private function formatCreatedDate(int $timestamp): string
    {
        if ($timestamp === 0) {
            return 'Unknown';
        }

        $date = new \DateTimeImmutable('@' . $timestamp);
        $now = new \DateTimeImmutable();
        $diff = $now->diff($date);

        if ($diff->days === 0) {
            return 'Today';
        }
        if ($diff->days === 1) {
            return 'Yesterday';
        }
        if ($diff->days < 30) {
            return $diff->days . ' days ago';
        }

        return $date->format('M j, Y');
    }

    protected function getOperationEmoji(): string
    {
        return '💾';
    }

    protected function getOperationVerb(): string
    {
        return 'Backing up';
    }

    protected function getAvailableResources(InputInterface $input): array
    {
        return $this->imageBackupService->getAvailableImages();
    }

    /**
     * @param DockerImage $image
     * @throws \Exception
     */
    protected function formatResourceForTable($image): array
    {
        $tags = empty($image->repoTags) ? ['<none>'] : $image->repoTags;

        // Per ogni tag, crea una riga separata (come nel codice originale)
        $rows = [];
        foreach ($tags as $tag) {
            $rows[] = [
                $tag,
                $image->getShortId(),
                $image->getFormattedSize(),
                $this->formatCreatedDate($image->created),
            ];
        }
        return $rows;
    }

    protected function getTableHeaders(): array
    {
        return ['Repository:Tag', 'Image ID', 'Size', 'Created'];
    }

    protected function getListTitle(): string
    {
        return 'Available Docker Images';
    }

    protected function getNoResourcesMessage(InputInterface $input): string
    {
        return 'No Docker images found.';
    }

    protected function getResourceCountLabel(InputInterface $input): string
    {
        return 'images';
    }

    protected function getEmptyArgumentsErrorMessage(): string
    {
        return 'You must specify at least one image name or ID, or use --list to see available images.';
    }

    protected function getUsageExamples(): array
    {
        return [
            'Usage: backup:images nginx:latest [mysql:8.0 ...]',
            '   or: backup:images --list'
        ];
    }
}
