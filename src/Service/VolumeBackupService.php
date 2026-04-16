<?php

declare(strict_types=1);

namespace DockerVol\Service;

use DockerVol\Contract\DockerServiceInterface;
use DockerVol\Exception\BackupException;
use DockerVol\Helper\ArchiveMetadata;
use DockerVol\Helper\ArchiveNamer;
use DockerVol\Helper\DockerHelperImage;
use DockerVol\Trait\BackupFileSystemTrait;
use DockerVol\ValueObject\BackupResult;
use DockerVol\ValueObject\DockerVolume;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final readonly class VolumeBackupService
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
     * @param string[] $volumeNames
     *
     * @return BackupResult[]
     */
    public function backupVolumes(array $volumeNames, string $backupDirectory, bool $compress = true): array
    {
        $this->ensureBackupDirectoryExists($backupDirectory);

        $results = [];

        foreach ($volumeNames as $volumeName) {
            $results[] = $this->backupSingleVolume($volumeName, $backupDirectory, $compress);
        }

        return $results;
    }

    public function backupSingleVolume(string $volumeName, string $backupDirectory, bool $compress = true): BackupResult
    {
        $this->logger->info("Starting backup of volume: {$volumeName}");

        try {
            $this->ensureBackupDirectoryExists($backupDirectory);

            // Verify volume exists
            if (!$this->dockerService->volumeExists($volumeName)) {
                throw new BackupException("Volume '{$volumeName}' not found");
            }

            $archivePath = $this->getArchivePath($volumeName, $backupDirectory, $compress);

            // Skip if backup already exists
            if (file_exists($archivePath)) {
                $this->logger->warning("Backup file already exists, skipping: {$archivePath}");

                return BackupResult::skipped($volumeName, "File already exists: {$archivePath}");
            }

            // Perform backup using Docker container
            $this->performVolumeBackupAtomically($volumeName, $archivePath, $compress);

            $this->logger->info("Successfully backed up volume: {$volumeName}");

            return BackupResult::success($volumeName, $archivePath);
        } catch (\Throwable $exception) {
            $this->logger->error("Failed to backup volume: {$volumeName}", [
                'error' => $exception->getMessage(),
            ]);

            return BackupResult::failed($volumeName, $exception->getMessage());
        }
    }

    /**
     * @return DockerVolume[]
     */
    public function getAvailableVolumes(): array
    {
        return $this->dockerService->listVolumes();
    }

    private function performVolumeBackupAtomically(string $volumeName, string $archivePath, bool $compress = true): void
    {
        $temporaryArchivePath = $this->createTemporaryArchivePath($archivePath);

        try {
            $this->performVolumeBackup($volumeName, $temporaryArchivePath, $compress);

            if (!@rename($temporaryArchivePath, $archivePath)) {
                throw new BackupException("Failed to move completed backup into place: {$archivePath}");
            }

            ArchiveMetadata::writeSidecar($archivePath, [
                'source_type' => 'volume',
                'source' => $volumeName,
            ]);
        } finally {
            if (file_exists($temporaryArchivePath)) {
                @unlink($temporaryArchivePath);
            }
        }
    }

    private function performVolumeBackup(string $volumeName, string $archivePath, bool $compress = true): void
    {
        $backupDir = dirname($archivePath);
        $archiveFilename = basename($archivePath);

        // Converte il path del container nel path equivalente dell'host
        $hostBackupDir = $this->getHostPath($backupDir);

        $tarCommand = $compress
            ? ['tar', 'czf', "/backup/{$archiveFilename}", '-C', '/volume', '.']
            : ['tar', 'cf', "/backup/{$archiveFilename}", '-C', '/volume', '.'];

        $dockerArgs = [
            '--rm',
            '--mount', "type=volume,source={$volumeName},target=/volume,readonly",
            '--mount', "type=bind,source={$hostBackupDir},target=/backup",
            DockerHelperImage::name(),
            ...$tarCommand,
        ];

        $this->dockerService->runContainer($dockerArgs);

        if (!file_exists($archivePath)) {
            throw new BackupException("Backup archive was not created: {$archivePath}");
        }
    }

    private function getArchivePath(string $volumeName, string $backupDirectory, bool $compress = true): string
    {
        return ArchiveNamer::volumeArchivePath($volumeName, $backupDirectory, $compress);
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
