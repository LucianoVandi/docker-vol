<?php

declare(strict_types=1);

namespace DockerVol\Helper;

final class ArchiveMetadata
{
    public const VERSION = 2;

    /**
     * @param array<string, mixed> $metadata
     */
    public static function writeSidecar(string $archivePath, array $metadata): bool
    {
        $checksum = hash_file('sha256', $archivePath);
        if (!is_string($checksum)) {
            return false;
        }

        $metadata = [
            'format_version' => self::VERSION,
            'tool_version' => self::toolVersion(),
            'created_at' => gmdate(DATE_ATOM),
            'archive' => basename($archivePath),
            'compression' => ArchiveNamer::isCompressed($archivePath) ? 'gzip' : 'none',
            'checksum_sha256' => $checksum,
        ] + $metadata;

        $json = json_encode($metadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
        if (!is_string($json)) {
            return false;
        }

        return file_put_contents(self::sidecarPath($archivePath), $json . "\n") !== false;
    }

    /**
     * @return null|array<string, mixed>
     */
    public static function readSidecar(string $archivePath): ?array
    {
        $sidecarPath = self::sidecarPath($archivePath);
        if (!is_file($sidecarPath)) {
            return null;
        }

        $json = file_get_contents($sidecarPath);
        if (!is_string($json)) {
            return null;
        }

        $metadata = json_decode($json, true);

        return is_array($metadata) ? $metadata : null;
    }

    public static function sidecarPath(string $archivePath): string
    {
        return $archivePath . '.json';
    }

    private static function toolVersion(): string
    {
        $versionFile = dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'VERSION';
        if (is_file($versionFile)) {
            return trim((string) file_get_contents($versionFile));
        }

        return 'dev';
    }
}
