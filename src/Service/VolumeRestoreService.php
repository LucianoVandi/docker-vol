<?php

declare(strict_types=1);

namespace DockerVol\Service;

use DockerVol\Contract\DockerServiceInterface;
use DockerVol\Exception\RestoreException;
use DockerVol\Helper\ArchiveValidator;
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
        $volumeName = $this->extractVolumeNameFromPath($archivePath);
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

            if ($volumeExists && !$overwrite) {
                $this->logger->warning("Volume already exists, skipping: {$volumeName}");

                return RestoreResult::skipped(
                    $volumeName,
                    'Volume already exists. Use --overwrite to replace it.'
                );
            }

            // Create volume if it doesn't exist and createVolume is true
            if (!$volumeExists && $createVolume) {
                $this->createVolume($volumeName);
            // @phpstan-ignore-next-line
            } elseif (!$volumeExists && !$createVolume) {
                throw new RestoreException("Volume '{$volumeName}' does not exist and --no-create-volume was specified");
            }

            // If volume exists and overwrite is true, we need to clean it first
            // @phpstan-ignore-next-line
            if ($volumeExists && $overwrite) {
                $this->cleanVolume($volumeName);
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
        $files = glob($backupDirectory . '/*.{tar,tar.gz}', GLOB_BRACE);

        foreach ($files ?: [] as $file) {
            if (!is_file($file)) {
                continue;
            }

            $volumeName = $this->extractVolumeNameFromPath($file);
            $compressed = str_ends_with($file, '.tar.gz');
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

    private function extractVolumeNameFromPath(string $archivePath): string
    {
        $filename = basename($archivePath);

        // Remove .tar.gz or .tar extension
        if (str_ends_with($filename, '.tar.gz')) {
            return substr($filename, 0, -7);
        }

        if (str_ends_with($filename, '.tar')) {
            return substr($filename, 0, -4);
        }

        throw new RestoreException("Invalid archive file format: {$filename}. Expected .tar or .tar.gz");
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
            '-v', "{$volumeName}:/volume",
            'alpine',
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
        $isCompressed = str_ends_with($archiveFilename, '.tar.gz');

        $tarCommand = $isCompressed
            ? ['tar', 'xzf', "/backup/{$archiveFilename}", '-C', '/volume']
            : ['tar', 'xf', "/backup/{$archiveFilename}", '-C', '/volume'];

        $dockerArgs = [
            '--rm',
            '-v', "{$volumeName}:/volume",
            '-v', "{$hostBackupDir}:/backup:ro",
            'alpine',
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
                '-v', "{$volumeName}:/volume:ro",
                'alpine',
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

        // Test archive integrity by trying to list contents
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
                '-v', "{$hostBackupDir}:/backup:ro",
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
