<?php

declare(strict_types=1);

namespace DockerBackup\Trait;

use DockerBackup\Helper\CommandHelper;
use Symfony\Component\Console\Style\SymfonyStyle;

trait DestructiveOperationTrait
{
    /**
     * Confirm destructive operation with user.
     * Shows warnings and asks for confirmation based on operation risk factors.
     */
    protected function confirmDestructiveOperation(
        array $archivePaths,
        bool $overwrite,
        SymfonyStyle $io
    ): bool {
        $needsConfirmation = false;
        $reasons = [];

        // Check if overwrite mode is enabled
        if ($overwrite) {
            $needsConfirmation = true;
            $reasons[] = $this->getOverwriteWarningMessage();
        }

        // Check if processing many archives
        $manyArchivesThreshold = $this->getManyArchivesThreshold();
        if (count($archivePaths) > $manyArchivesThreshold) {
            $needsConfirmation = true;
            $reasons[] = sprintf('Processing %d archives', count($archivePaths));
        }

        // Check if any archive is very large
        $largeArchiveThreshold = $this->getLargeArchiveThreshold();
        $largeArchives = [];
        foreach ($archivePaths as $archivePath) {
            $size = filesize($archivePath) ?: 0;
            if ($size > $largeArchiveThreshold) {
                $largeArchives[] = basename($archivePath) . ' (' . CommandHelper::formatFileSize($size) . ')';
            }
        }

        if (!empty($largeArchives)) {
            $needsConfirmation = true;
            $reasons[] = 'Large archives detected: ' . implode(', ', $largeArchives);
        }

        // If no confirmation needed, proceed
        if (!$needsConfirmation) {
            return true;
        }

        // Show warning and ask for confirmation
        $io->warning($this->getOperationWarningTitle());
        foreach ($reasons as $reason) {
            $io->text("  • {$reason}");
        }
        $io->newLine();

        return $io->confirm('Do you want to continue?', false);
    }

    /**
     * Get the warning message for overwrite mode.
     * Override in implementing classes for resource-specific messages.
     */
    protected function getOverwriteWarningMessage(): string
    {
        return 'Overwrite mode enabled - existing resources will be replaced';
    }

    /**
     * Get the threshold for considering "many archives".
     * Override in implementing classes for different thresholds.
     */
    protected function getManyArchivesThreshold(): int
    {
        return 5; // Default threshold
    }

    /**
     * Get the threshold for considering an archive "large" (in bytes).
     * Override in implementing classes for different thresholds.
     */
    protected function getLargeArchiveThreshold(): int
    {
        return 100 * 1024 * 1024; // 100MB default
    }

    /**
     * Get the warning title for the operation.
     * Override in implementing classes for operation-specific titles.
     */
    protected function getOperationWarningTitle(): string
    {
        return 'This operation may be destructive:';
    }
}
