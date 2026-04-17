<?php

declare(strict_types=1);

namespace DockerVol\Command;

use DockerVol\Service\ImageBackupService;
use DockerVol\ValueObject\DockerImage;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class BackupImagesCommand extends AbstractBackupCommand
{
    public function __construct(
        private readonly ImageBackupService $imageBackupService
    ) {
        parent::__construct();
    }

    protected function getCommandName(): string
    {
        return 'backup:images';
    }

    protected function getCommandDescription(): string
    {
        return 'Backup Docker images to tar.gz archives';
    }

    protected function getArgumentName(): string
    {
        return 'images';
    }

    protected function getArgumentDescription(): string
    {
        return 'Names or IDs of images to backup (e.g., nginx:latest, sha256:abc123...)';
    }

    protected function getDefaultOutputDir(): string
    {
        return getcwd() . '/backups/images';
    }

    protected function getCommandHelp(): string
    {
        return <<<'HELP'
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
HELP;
    }

    protected function getOperationTitle(): string
    {
        return 'Docker Image Backup';
    }

    protected function getResourceType(): string
    {
        return 'images';
    }

    protected function validateResourcesExist(array $imageReferences, SymfonyStyle $io): int
    {
        $invalidImages = [];

        foreach ($imageReferences as $imageRef) {
            if (!$this->imageBackupService->imageExists($imageRef)) {
                $invalidImages[] = $imageRef;
            }
        }

        if (!empty($invalidImages)) {
            $io->error('The following images do not exist:');
            foreach ($invalidImages as $invalid) {
                $io->text("  - {$invalid}");
            }
            $io->newLine();
            $io->text('💡 Tip: Use --list to see all available images');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    protected function performSingleBackup(string $imageReference, InputInterface $input, string $outputDir, bool $compress)
    {
        return $this->imageBackupService->backupSingleImage($imageReference, $outputDir, $compress);
    }

    // Trait implementations
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
     *
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
            '   or: backup:images --list',
        ];
    }

    protected function applyDockerTimeout(int $seconds): void
    {
        $this->imageBackupService->setDockerTimeout($seconds);
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
}
