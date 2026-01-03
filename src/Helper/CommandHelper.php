<?php

declare(strict_types=1);

namespace DockerVol\Helper;

class CommandHelper
{
    /**
     * Format file size from bytes to human-readable format.
     */
    public static function formatFileSize(int $bytes): string
    {
        if ($bytes === 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $size = $bytes;
        $unitIndex = 0;

        while ($size >= 1024 && $unitIndex < count($units) - 1) {
            $size /= 1024;
            $unitIndex++;
        }

        return sprintf('%.2f %s', $size, $units[$unitIndex]);
    }

    /**
     * Resolve archive paths from names and backup directory.
     * Handles both absolute and relative paths.
     *
     * @param array<string> $archiveNames
     *
     * @return array<string>
     */
    public static function resolveArchivePaths(array $archiveNames, string $backupDir): array
    {
        $paths = [];

        foreach ($archiveNames as $archiveName) {
            // If it's already an absolute path, use it as-is
            if (str_starts_with($archiveName, '/')) {
                $paths[] = $archiveName;
            } else {
                // Resolve relative to backup directory
                $paths[] = $backupDir . DIRECTORY_SEPARATOR . $archiveName;
            }
        }

        return $paths;
    }

    /**
     * Validate archive files integrity with basic checks.
     *
     * @param array<string> $archivePaths
     *
     * @return array<string, string> Array of invalid archives with reasons
     */
    public static function validateArchivesIntegrity(array $archivePaths): array
    {
        $invalidArchives = [];

        foreach ($archivePaths as $archivePath) {
            $archiveName = basename($archivePath);

            try {
                $failureReason = ArchiveValidator::validateLightweight($archivePath);
                if ($failureReason !== null) {
                    $invalidArchives[$archiveName] = $failureReason;
                }
            } catch (\Throwable $e) {
                $invalidArchives[$archiveName] = $e->getMessage();
            }
        }

        return $invalidArchives;
    }

    /**
     * Check if archives exist and return missing ones.
     *
     * @param array<string> $archivePaths
     *
     * @return array<string>
     */
    public static function findMissingArchives(array $archivePaths): array
    {
        return array_filter($archivePaths, fn ($path) => !file_exists($path));
    }

    /**
     * Check if archive file has valid extension.
     */
    public static function hasValidArchiveExtension(string $archivePath): bool
    {
        return ArchiveValidator::hasValidArchiveExtension($archivePath);
    }

    /**
     * Get file extension for compression type.
     */
    public static function getArchiveExtension(bool $compress): string
    {
        return $compress ? '.tar.gz' : '.tar';
    }
}
