<?php

declare(strict_types=1);

namespace DockerVol\Service;

use DockerVol\Contract\DockerServiceInterface;
use DockerVol\Exception\BackupException;
use DockerVol\Helper\ArchiveMetadata;
use DockerVol\Helper\ArchiveNamer;
use DockerVol\Trait\BackupFileSystemTrait;
use DockerVol\ValueObject\DockerImage;
use DockerVol\ValueObject\ImageBackupResult;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final readonly class ImageBackupService
{
    use BackupFileSystemTrait;

    private LoggerInterface $logger;

    public function __construct(
        private DockerServiceInterface $dockerService,
        ?LoggerInterface $logger = null
    ) {
        $this->logger = $logger ?? new NullLogger();
    }

    public function setDockerTimeout(?int $seconds): void
    {
        $this->dockerService->setTimeoutOverride($seconds);
    }

    /**
     * @param string[] $imageReferences
     *
     * @return ImageBackupResult[]
     */
    public function backupImages(array $imageReferences, string $backupDirectory, bool $compress = true): array
    {
        $this->ensureBackupDirectoryExists($backupDirectory);

        $results = [];

        foreach ($imageReferences as $imageReference) {
            $results[] = $this->backupSingleImage($imageReference, $backupDirectory, $compress);
        }

        return $results;
    }

    public function backupSingleImage(string $imageReference, string $backupDirectory, bool $compress = true): ImageBackupResult
    {
        $this->logger->info("Starting backup of image: {$imageReference}");

        try {
            $this->ensureBackupDirectoryExists($backupDirectory);

            // Verify image exists
            if (!$this->dockerService->imageExists($imageReference)) {
                throw new BackupException("Image '{$imageReference}' not found");
            }

            $archivePath = $this->getArchivePath($imageReference, $backupDirectory, $compress);

            // Skip if backup already exists
            if (file_exists($archivePath)) {
                $this->logger->warning("Backup file already exists, skipping: {$archivePath}");

                return ImageBackupResult::skipped($imageReference, "File already exists: {$archivePath}");
            }

            // Perform backup using docker save
            $this->performImageBackupAtomically($imageReference, $archivePath, $compress);

            $this->logger->info("Successfully backed up image: {$imageReference}");

            return ImageBackupResult::success($imageReference, $archivePath);
        } catch (\Throwable $exception) {
            $this->logger->error("Failed to backup image: {$imageReference}", [
                'error' => $exception->getMessage(),
            ]);

            return ImageBackupResult::failed($imageReference, $exception->getMessage());
        }
    }

    /**
     * @return DockerImage[]
     */
    public function getAvailableImages(): array
    {
        return $this->dockerService->listImages();
    }

    private function performImageBackupAtomically(string $imageReference, string $archivePath, bool $compress = true): void
    {
        $temporaryArchivePath = $this->createTemporaryArchivePath($archivePath);

        try {
            $this->performImageBackup($imageReference, $temporaryArchivePath, $compress);

            if (!@rename($temporaryArchivePath, $archivePath)) {
                throw new BackupException("Failed to move completed backup into place: {$archivePath}");
            }

            ArchiveMetadata::writeSidecar($archivePath, [
                'source_type' => 'image',
                'source' => $imageReference,
            ]);
        } finally {
            if (file_exists($temporaryArchivePath)) {
                @unlink($temporaryArchivePath);
            }
        }
    }

    private function performImageBackup(string $imageReference, string $archivePath, bool $compress = true): void
    {
        if ($compress) {
            // Use docker save with gzip compression
            $this->performCompressedImageBackup($imageReference, $archivePath);
        } else {
            // Use docker save directly
            $this->dockerService->saveImage($imageReference, $archivePath);
        }

        if (!file_exists($archivePath)) {
            throw new BackupException("Backup archive was not created: {$archivePath}");
        }
    }

    private function performCompressedImageBackup(string $imageReference, string $archivePath): void
    {
        $outputHandle = gzopen($archivePath, 'wb6');
        if (!$outputHandle) {
            throw new BackupException('Failed to create compressed output file');
        }

        $writtenBytes = 0;

        try {
            $this->dockerService->streamSavedImage($imageReference, function (string $chunk) use ($outputHandle, &$writtenBytes): void {
                if (gzwrite($outputHandle, $chunk) === false) {
                    throw new BackupException('Failed to write compressed data');
                }

                $writtenBytes += strlen($chunk);
            });

            if ($writtenBytes === 0) {
                throw new BackupException('Docker save produced no data');
            }

            $this->logger->info('Successfully compressed image backup: ' . basename($archivePath));
        } finally {
            gzclose($outputHandle);
        }
    }

    private function getArchivePath(string $imageReference, string $backupDirectory, bool $compress = true): string
    {
        return ArchiveNamer::imageArchivePath($imageReference, $backupDirectory, $compress);
    }

    private function createTemporaryArchivePath(string $archivePath): string
    {
        $directory = dirname($archivePath);
        $filename = basename($archivePath);

        return $directory . DIRECTORY_SEPARATOR . '.' . $filename . '.tmp.' . getmypid() . '.' . bin2hex(random_bytes(4));
    }

    private function ensureBackupDirectoryExists(string $backupDirectory): void
    {
        $result = $this->ensureDirectoryExists($backupDirectory);

        if (!$result['success']) {
            throw new BackupException(
                "Failed to create backup directory '{$backupDirectory}': " . $result['error']
            );
        }
    }
}
