<?php

declare(strict_types=1);

namespace DockerBackup\Trait;

use DockerBackup\Helper\CommandHelper;

trait BackupFileSystemTrait
{
    /**
     * Convert a container path to the equivalent host path.
     *
     * This handles the mapping between container paths and host paths
     * when running in Docker development mode.
     */
    private function getHostPath(string $containerPath): string
    {
        if (!$this->envFlagEnabled('DOCKER_BACKUP_DEV_MODE')) {
            return $containerPath;
        }

        $containerProjectDir = $this->getEnvValue('CONTAINER_PROJECT_DIR');
        $hostProjectDir = $this->getEnvValue('HOST_PROJECT_DIR');

        if ($containerProjectDir === null || $hostProjectDir === null) {
            return $containerPath;
        }

        $containerProjectDir = rtrim($containerProjectDir, DIRECTORY_SEPARATOR);
        $hostProjectDir = rtrim($hostProjectDir, DIRECTORY_SEPARATOR);

        if ($containerPath === $containerProjectDir) {
            return $hostProjectDir;
        }

        if (!str_starts_with($containerPath, $containerProjectDir . DIRECTORY_SEPARATOR)) {
            return $containerPath;
        }

        return $hostProjectDir . substr($containerPath, strlen($containerProjectDir));
    }

    /**
     * Check if directory exists and is writable, create if necessary.
     *
     * @return array{success: bool, error?: string} Result with success status and optional error message
     */
    private function ensureDirectoryExists(string $directory): array
    {
        // Resolve absolute path to avoid issues with relative paths
        if (is_dir($directory)) {
            $directory = realpath($directory);
        }

        // If directory exists and is writable, all good
        if (is_dir($directory) && is_writable($directory)) {
            return ['success' => true];
        }

        // If directory doesn't exist, create it
        if (!is_dir($directory)) {
            $success = @mkdir($directory, 0755, true);

            if (!$success) {
                $error = error_get_last();

                return [
                    'success' => false,
                    'error' => 'Failed to create directory: ' . ($error['message'] ?? 'Unknown error'),
                ];
            }
        }

        // Final check that it's writable
        if (!is_writable($directory)) {
            return [
                'success' => false,
                'error' => 'Directory exists but is not writable. Check permissions or run with sudo if needed.',
            ];
        }

        return ['success' => true];
    }

    /**
     * Format bytes into human-readable format (B, KB, MB, GB, TB).
     */
    private function formatFileSize(int $bytes): string
    {
        return CommandHelper::formatFileSize($bytes);
    }

    /**
     * Check if file exists and is readable.
     *
     * @return array{exists: bool, readable: bool} File access status
     */
    private function checkFileAccess(string $filePath): array
    {
        return [
            'exists' => file_exists($filePath),
            'readable' => is_readable($filePath),
        ];
    }

    /**
     * Check if file has valid archive extension.
     */
    private function hasValidArchiveExtension(string $filePath): bool
    {
        return str_ends_with($filePath, '.tar') || str_ends_with($filePath, '.tar.gz');
    }

    private function envFlagEnabled(string $name): bool
    {
        $value = $this->getEnvValue($name);

        return $value !== null && !in_array(strtolower($value), ['', '0', 'false', 'off', 'no'], true);
    }

    private function getEnvValue(string $name): ?string
    {
        $value = $_ENV[$name] ?? getenv($name);

        if ($value === false || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
