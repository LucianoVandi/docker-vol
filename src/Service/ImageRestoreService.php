<?php

declare(strict_types=1);

namespace DockerVol\Service;

use DockerVol\Contract\DockerServiceInterface;
use DockerVol\Exception\RestoreException;
use DockerVol\Helper\ArchiveInspector;
use DockerVol\Helper\ArchiveNamer;
use DockerVol\Helper\DockerHelperImage;
use DockerVol\Trait\BackupFileSystemTrait;
use DockerVol\ValueObject\ImageRestoreResult;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final readonly class ImageRestoreService
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
     * @param string[] $archivePaths
     *
     * @return ImageRestoreResult[]
     */
    public function restoreImages(array $archivePaths, bool $overwrite = false, bool $deepValidate = false): array
    {
        $results = [];

        foreach ($archivePaths as $archivePath) {
            $results[] = $this->restoreSingleImage($archivePath, $overwrite, $deepValidate);
        }

        return $results;
    }

    public function restoreSingleImage(
        string $archivePath,
        bool $overwrite = false,
        bool $deepValidate = false
    ): ImageRestoreResult {
        $archiveName = basename($archivePath);
        $this->logger->info("Starting restore of image from: {$archiveName}");

        try {
            $fileCheck = $this->checkFileAccess($archivePath);

            // Validate archive exists and is readable
            if (!$fileCheck['exists']) {
                throw new RestoreException("Archive file not found: {$archivePath}");
            }

            if (!$fileCheck['readable']) {
                throw new RestoreException("Archive file is not readable: {$archivePath}");
            }

            // Validate archive format
            $this->validateArchive($archivePath, $deepValidate);

            // Extract image information from archive without loading it
            $imageInfo = $this->extractImageInfoFromArchive($archivePath);

            // Check if image already exists (if not overwriting)
            if (!$overwrite && $this->imageAlreadyExists($imageInfo)) {
                $message = "Image already exists: {$imageInfo['name']}. Use --overwrite to replace it.";
                $this->logger->warning($message);

                return ImageRestoreResult::skipped($archiveName, $message);
            }

            // Perform the restore
            $this->performImageRestore($archivePath);

            $this->logger->info("Successfully restored image from: {$archiveName}");

            return ImageRestoreResult::success($archiveName, $archivePath);
        } catch (\Throwable $exception) {
            $this->logger->error("Failed to restore image from: {$archiveName}", [
                'error' => $exception->getMessage(),
            ]);

            return ImageRestoreResult::failed($archiveName, $exception->getMessage());
        }
    }

    /**
     * Get list of available backup archives in a directory.
     *
     * @return array<array{name: string, path: string, size: int, compressed: bool}>
     */
    public function getAvailableBackups(string $backupDirectory): array
    {
        if (!is_dir($backupDirectory)) {
            return [];
        }

        $backups = [];
        $files = glob($backupDirectory . '/' . ArchiveNamer::archiveGlob(), GLOB_BRACE);

        foreach ($files ?: [] as $filePath) {
            if (!is_file($filePath)) {
                continue;
            }

            $fileName = basename($filePath);
            $compressed = ArchiveNamer::isCompressed($filePath);

            // Extract original image name from filename
            $imageName = ArchiveNamer::imageNameFromArchivePath($fileName);

            $backups[] = [
                'name' => $imageName,
                'path' => $filePath,
                'size' => filesize($filePath) ?: 0,
                'compressed' => $compressed,
            ];
        }

        // Sort by name
        usort($backups, fn ($a, $b) => strcmp($a['name'], $b['name']));

        return $backups;
    }

    private function validateArchive(string $archivePath, bool $deepValidate = false): void
    {
        $this->logger->info("Validating archive: {$archivePath}");

        $failureReason = ArchiveInspector::lightweightFailureReason($archivePath);
        if ($failureReason !== null) {
            throw new RestoreException(
                'Invalid archive format: ' . basename($archivePath) . ". {$failureReason}"
            );
        }

        if (!$deepValidate) {
            $this->logger->info('Archive lightweight validation successful');

            return;
        }

        $this->validateArchiveDeep($archivePath);
    }

    private function validateArchiveDeep(string $archivePath): void
    {
        $backupDir = dirname($archivePath);
        $hostBackupDir = $this->getHostPath($backupDir);
        $archiveFilename = basename($archivePath);

        try {
            $validationResult = ArchiveInspector::validateDeep(
                $archivePath,
                $this->dockerService,
                $hostBackupDir,
                DockerHelperImage::name()
            );

            $this->validateArchiveListResult(
                $archiveFilename,
                $validationResult['successful'],
                $validationResult['output'],
                $validationResult['error']
            );
        } catch (RestoreException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new RestoreException(
                "Failed to validate archive {$archiveFilename}: " . $e->getMessage()
            );
        }
    }

    private function validateArchiveListResult(
        string $archiveFilename,
        bool $successful,
        string $output,
        string $errorOutput
    ): void {
        if (!$successful) {
            throw new RestoreException(
                'Archive integrity check failed: ' . trim($errorOutput)
            );
        }

        if (trim($output) === '') {
            throw new RestoreException("Archive appears to be empty: {$archiveFilename}");
        }

        $this->logger->info('Archive validation successful');
    }

    private function extractImageInfoFromArchive(string $archivePath): array
    {
        $imageName = ArchiveNamer::imageNameFromArchivePath($archivePath);
        $repoTags = ArchiveInspector::imageRepoTags($archivePath);
        if ($repoTags !== []) {
            return [
                'name' => $repoTags[0],
                'repoTags' => $repoTags,
                'archive' => $archivePath,
            ];
        }

        return [
            'name' => $imageName,
            'repoTags' => [$imageName],
            'archive' => $archivePath,
        ];
    }

    private function imageAlreadyExists(array $imageInfo): bool
    {
        $repoTags = $imageInfo['repoTags'] ?? [$imageInfo['name']];

        try {
            foreach ($repoTags as $repoTag) {
                if (is_string($repoTag) && $repoTag !== '' && $this->dockerService->imageExists($repoTag)) {
                    return true;
                }
            }

            return false;
        } catch (\Exception) {
            // If we can't check, assume it doesn't exist
            return false;
        }
    }

    private function performImageRestore(string $archivePath): void
    {
        if (ArchiveNamer::isCompressed($archivePath)) {
            // Use pipe with gunzip for compressed files
            $this->performCompressedImageRestore($archivePath);
        } else {
            // Direct docker load for uncompressed
            $this->dockerService->loadImage($archivePath);
        }
    }

    private function performCompressedImageRestore(string $archivePath): void
    {
        $inputHandle = gzopen($archivePath, 'rb');
        if ($inputHandle === false) {
            throw new RestoreException('Failed to open compressed archive for reading');
        }

        try {
            $this->dockerService->loadImageFromStream(function (callable $write) use ($inputHandle): void {
                while (!gzeof($inputHandle)) {
                    $chunk = gzread($inputHandle, 1024 * 1024);
                    if ($chunk === false) {
                        throw new RestoreException('Failed to read compressed data');
                    }

                    $write($chunk);
                }
            });

            $this->logger->info('Successfully restored compressed image: ' . basename($archivePath));
        } finally {
            gzclose($inputHandle);
        }
    }
}
