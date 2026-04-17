<?php

declare(strict_types=1);

namespace DockerVol\Service;

use DockerVol\Contract\DockerServiceInterface;
use DockerVol\Exception\RestoreException;
use DockerVol\Helper\ArchiveInspector;
use DockerVol\Helper\ArchiveNamer;
use DockerVol\Helper\DockerHelperImage;
use DockerVol\Trait\BackupFileSystemTrait;
use DockerVol\ValueObject\RestoreResult;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final readonly class VolumeRestoreService
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
     * @return RestoreResult[]
     */
    public function restoreVolumes(
        array $archivePaths,
        bool $overwrite = false,
        bool $createVolumes = true,
        bool $deepValidate = false
    ): array {
        $results = [];

        foreach ($archivePaths as $archivePath) {
            $results[] = $this->restoreSingleVolume($archivePath, $overwrite, $createVolumes, $deepValidate);
        }

        return $results;
    }

    public function restoreSingleVolume(
        string $archivePath,
        bool $overwrite = false,
        bool $createVolume = true,
        bool $deepValidate = false
    ): RestoreResult {
        $volumeName = ArchiveNamer::volumeNameFromArchivePath($archivePath);
        $this->logger->info("Starting restore of volume: {$volumeName} from {$archivePath}");

        try {
            // Verify archive file exists
            $fileCheck = $this->checkFileAccess($archivePath);
            if (!$fileCheck['exists']) {
                throw new RestoreException("Archive file not found: {$archivePath}");
            }

            if (!$fileCheck['readable']) {
                throw new RestoreException("Archive file is not readable: {$archivePath}");
            }

            $this->validateArchive($archivePath, $deepValidate);

            // Check if volume already exists
            $volumeExists = $this->dockerService->volumeExists($volumeName);

            if (!$volumeExists) {
                if (!$createVolume) {
                    throw new RestoreException("Volume '{$volumeName}' does not exist and --no-create-volume was specified");
                }

                $this->createVolume($volumeName);
            } elseif ($overwrite) {
                $this->cleanVolume($volumeName);
            } else {
                $this->logger->warning("Volume already exists, skipping: {$volumeName}");

                return RestoreResult::skipped(
                    $volumeName,
                    'Volume already exists. Use --overwrite to replace it.'
                );
            }

            // Perform restore
            $extractedBytes = $this->performVolumeRestore($volumeName, $archivePath);
            $message = $extractedBytes !== null
                ? sprintf('Restore completed successfully (%s extracted)', $this->formatBytes($extractedBytes))
                : 'Restore completed successfully';

            $this->logger->info("Successfully restored volume: {$volumeName}");

            return RestoreResult::success($volumeName, $archivePath, $message);
        } catch (\Throwable $exception) {
            $this->logger->error("Failed to restore volume: {$volumeName}", [
                'error' => $exception->getMessage(),
                'archive' => $archivePath,
            ]);

            return RestoreResult::failed($volumeName, $exception->getMessage());
        }
    }

    /**
     * Get available backup archives from a directory.
     *
     * @return array<string, array{path: string, volume: string, compressed: bool, size: int}>
     */
    public function getAvailableBackups(string $backupDirectory): array
    {
        if (!is_dir($backupDirectory)) {
            return [];
        }

        $backups = [];
        $files = array_merge(
            glob($backupDirectory . '/*.tar') ?: [],
            glob($backupDirectory . '/*.tar.gz') ?: [],
        );
        sort($files);

        foreach ($files ?: [] as $file) {
            if (!is_file($file)) {
                continue;
            }

            $volumeName = ArchiveNamer::volumeNameFromArchivePath($file);
            $compressed = ArchiveNamer::isCompressed($file);
            $size = filesize($file) ?: 0;

            $backups[$volumeName] = [
                'path' => $file,
                'volume' => $volumeName,
                'compressed' => $compressed,
                'size' => $size,
            ];
        }

        return $backups;
    }

    private function createVolume(string $volumeName): void
    {
        $this->logger->info("Creating volume: {$volumeName}");

        $this->dockerService->createVolume($volumeName);
    }

    private function cleanVolume(string $volumeName): void
    {
        $this->logger->info("Cleaning existing volume: {$volumeName}");

        $this->dockerService->runContainer([
            '--rm',
            '--mount', "type=volume,source={$volumeName},target=/volume",
            DockerHelperImage::name(),
            'sh', '-c', 'rm -rf /volume/* /volume/.[!.]* /volume/..?*',
        ]);
    }

    private function performVolumeRestore(string $volumeName, string $archivePath): ?int
    {
        $backupDir = dirname($archivePath);
        $archiveFilename = basename($archivePath);

        // Convert container path to equivalent host path
        $hostBackupDir = $this->getHostPath($backupDir);

        // Determine if archive is compressed
        $isCompressed = ArchiveNamer::isCompressed($archiveFilename);

        $tarCommand = $isCompressed
            ? ['tar', 'xzf', "/backup/{$archiveFilename}", '-C', '/volume']
            : ['tar', 'xf', "/backup/{$archiveFilename}", '-C', '/volume'];

        $dockerArgs = [
            '--rm',
            '--mount', "type=volume,source={$volumeName},target=/volume",
            '--mount', "type=bind,source={$hostBackupDir},target=/backup,readonly",
            DockerHelperImage::name(),
            ...$tarCommand,
        ];

        $this->dockerService->runContainer($dockerArgs);

        // Try to get the size of extracted content (optional)
        return $this->getVolumeSize($volumeName);
    }

    private function getVolumeSize(string $volumeName): ?int
    {
        try {
            $process = $this->dockerService->runContainer([
                '--rm',
                '--mount', "type=volume,source={$volumeName},target=/volume,readonly",
                DockerHelperImage::name(),
                'du', '-sb', '/volume',
            ]);

            if ($process->isSuccessful()) {
                $output = trim($process->getOutput());
                $parts = explode("\t", $output);

                return (int) $parts[0];
            }
        } catch (\Throwable) {
            // Size calculation failed, not critical
        }

        return null;
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

        $unsafeEntryReason = ArchiveInspector::extractionFailureReason($archivePath);
        if ($unsafeEntryReason !== null) {
            throw new RestoreException(
                'Unsafe archive contents: ' . basename($archivePath) . ". {$unsafeEntryReason}"
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

        $output = trim($output);
        if (empty($output)) {
            throw new RestoreException("Archive appears to be empty: {$archiveFilename}");
        }

        $fileCount = count(explode("\n", $output));
        $this->logger->info("Archive validation successful: {$fileCount} files found");
    }

    private function formatBytes(int $bytes): string
    {
        return $this->formatFileSize($bytes);
    }
}
