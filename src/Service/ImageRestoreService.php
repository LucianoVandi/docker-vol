<?php

declare(strict_types=1);

namespace DockerVol\Service;

use DockerVol\Contract\DockerServiceInterface;
use DockerVol\Exception\RestoreException;
use DockerVol\Helper\ArchiveValidator;
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
        $files = glob($backupDirectory . '/*.{tar,tar.gz}', GLOB_BRACE);

        foreach ($files ?: [] as $filePath) {
            if (!is_file($filePath)) {
                continue;
            }

            $fileName = basename($filePath);
            $compressed = str_ends_with($filePath, '.tar.gz');

            // Extract original image name from filename
            $imageName = $this->extractImageNameFromFilename($fileName);

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

        $failureReason = ArchiveValidator::validateLightweight($archivePath);
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
        $archiveFilename = basename($archivePath);
        $hostBackupDir = $this->getHostPath($backupDir);

        // Test archive integrity using Docker (like VolumeRestoreService)
        $isCompressed = str_ends_with($archiveFilename, '.tar.gz');
        $testCommand = $isCompressed
            ? ['tar', 'tzf', "/backup/{$archiveFilename}"]
            : ['tar', 'tf', "/backup/{$archiveFilename}"];

        try {
            $hostTarResult = ArchiveValidator::listContentsWithHostTar($archivePath);
            if ($hostTarResult['available']) {
                $this->validateArchiveListResult(
                    $archiveFilename,
                    $hostTarResult['successful'],
                    $hostTarResult['output'],
                    $hostTarResult['error']
                );

                return;
            }

            $process = $this->dockerService->runContainer([
                '--rm',
                '--mount', "type=bind,source={$hostBackupDir},target=/backup,readonly",
                'alpine',
                ...$testCommand,
            ]);

            $this->validateArchiveListResult(
                $archiveFilename,
                $process->isSuccessful(),
                $process->getOutput(),
                $process->getErrorOutput()
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
        $fileName = basename($archivePath);
        $imageName = $this->extractImageNameFromFilename($fileName);
        $manifest = $this->readManifestFromArchive($archivePath);
        $repoTags = $this->extractRepoTagsFromManifest($manifest);
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

    private function extractImageNameFromFilename(string $fileName): string
    {
        // Remove file extensions
        $name = preg_replace('/\.(tar\.gz|tar)$/', '', $fileName);

        if (!is_string($name) || $name === '') {
            return 'unknown';
        }

        if (preg_match('/%[0-9A-Fa-f]{2}/', $name)) {
            return rawurldecode($name);
        }

        return $this->extractLegacyImageName($name);
    }

    private function extractLegacyImageName(string $fileNameWithoutExtension): string
    {
        $parts = explode('_', $fileNameWithoutExtension);
        if (count($parts) < 2) {
            return $fileNameWithoutExtension;
        }

        $tag = array_pop($parts);

        if (count($parts) >= 2 && in_array($parts[0], ['docker', 'ghcr', 'quay'], true)) {
            $registry = array_shift($parts) . '.' . array_shift($parts);

            return $registry . '/' . implode('/', $parts) . ':' . $tag;
        }

        if (str_contains($parts[0], '.')) {
            $registry = array_shift($parts);

            return $registry . '/' . implode('/', $parts) . ':' . $tag;
        }

        return implode('_', $parts) . ':' . $tag;
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
        if (str_ends_with($archivePath, '.tar.gz')) {
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

    /**
     * @return array<int, array<string, mixed>>
     */
    private function readManifestFromArchive(string $archivePath): array
    {
        $manifestJson = ArchiveValidator::readFileFromArchive($archivePath, 'manifest.json');
        if ($manifestJson === null) {
            return [];
        }

        $manifest = json_decode($manifestJson, true);
        if (!is_array($manifest)) {
            return [];
        }

        return $manifest;
    }

    /**
     * @param array<int, array<string, mixed>> $manifest
     *
     * @return string[]
     */
    private function extractRepoTagsFromManifest(array $manifest): array
    {
        $repoTags = [];
        foreach ($manifest as $entry) {
            if (!isset($entry['RepoTags']) || !is_array($entry['RepoTags'])) {
                continue;
            }

            foreach ($entry['RepoTags'] as $repoTag) {
                if (is_string($repoTag) && $repoTag !== '<none>:<none>' && $repoTag !== '') {
                    $repoTags[] = $repoTag;
                }
            }
        }

        return array_values(array_unique($repoTags));
    }
}
