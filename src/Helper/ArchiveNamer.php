<?php

declare(strict_types=1);

namespace DockerVol\Helper;

final class ArchiveNamer
{
    public const TAR_EXTENSION = '.tar';
    public const COMPRESSED_TAR_EXTENSION = '.tar.gz';

    public static function extension(bool $compress): string
    {
        return $compress ? self::COMPRESSED_TAR_EXTENSION : self::TAR_EXTENSION;
    }

    public static function isCompressed(string $archivePath): bool
    {
        return str_ends_with($archivePath, self::COMPRESSED_TAR_EXTENSION);
    }

    public static function hasValidExtension(string $archivePath): bool
    {
        return str_ends_with($archivePath, self::TAR_EXTENSION)
            || str_ends_with($archivePath, self::COMPRESSED_TAR_EXTENSION);
    }

    public static function archiveGlob(): string
    {
        return '*.{tar,tar.gz}';
    }

    public static function imageArchivePath(string $imageReference, string $backupDirectory, bool $compress): string
    {
        return $backupDirectory . DIRECTORY_SEPARATOR . rawurlencode($imageReference) . self::extension($compress);
    }

    public static function volumeArchivePath(string $volumeName, string $backupDirectory, bool $compress): string
    {
        return $backupDirectory . DIRECTORY_SEPARATOR . $volumeName . self::extension($compress);
    }

    public static function volumeNameFromArchivePath(string $archivePath): string
    {
        return self::removeArchiveExtension(basename($archivePath));
    }

    public static function imageNameFromArchivePath(string $archivePath): string
    {
        $name = self::removeArchiveExtension(basename($archivePath));

        if (preg_match('/%[0-9A-Fa-f]{2}/', $name) === 1) {
            return rawurldecode($name);
        }

        return self::legacyImageName($name);
    }

    public static function removeArchiveExtension(string $filename): string
    {
        if (str_ends_with($filename, self::COMPRESSED_TAR_EXTENSION)) {
            return substr($filename, 0, -strlen(self::COMPRESSED_TAR_EXTENSION));
        }

        if (str_ends_with($filename, self::TAR_EXTENSION)) {
            return substr($filename, 0, -strlen(self::TAR_EXTENSION));
        }

        throw new \InvalidArgumentException("Invalid archive file format: {$filename}. Expected .tar or .tar.gz");
    }

    private static function legacyImageName(string $fileNameWithoutExtension): string
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
}
