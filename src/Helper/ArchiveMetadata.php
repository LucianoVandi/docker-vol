<?php

declare(strict_types=1);

namespace DockerVol\Helper;

final class ArchiveMetadata
{
    public const VERSION = 2;

    public const SIDECAR_MISSING = 'missing';
    public const SIDECAR_CORRUPTED = 'corrupted';
    public const SIDECAR_VALID = 'valid';

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
     * Result of reading a sidecar file.
     *
     * @return array{status: self::SIDECAR_*, data: null|array<string, mixed>} $result
     */
    public static function readSidecarWithStatus(string $archivePath): array
    {
        $sidecarPath = self::sidecarPath($archivePath);
        if (!is_file($sidecarPath)) {
            return ['status' => self::SIDECAR_MISSING, 'data' => null];
        }

        $json = file_get_contents($sidecarPath);
        if (!is_string($json)) {
            return ['status' => self::SIDECAR_CORRUPTED, 'data' => null];
        }

        $metadata = json_decode($json, true);

        if (!is_array($metadata)) {
            return ['status' => self::SIDECAR_CORRUPTED, 'data' => null];
        }

        return ['status' => self::SIDECAR_VALID, 'data' => $metadata];
    }

    /**
     * @return null|array<string, mixed>
     */
    public static function readSidecar(string $archivePath): ?array
    {
        $result = self::readSidecarWithStatus($archivePath);

        return $result['data'];
    }

    public static function sidecarPath(string $archivePath): string
    {
        return $archivePath . '.json';
    }

    /**
     * Verifies the checksum stored in the sidecar matches the actual archive.
     *
     * @return null|string Null if checksum is valid, or a failure reason string
     */
    public static function checksumFailureReason(string $archivePath): ?string
    {
        $result = self::readSidecarWithStatus($archivePath);

        // No sidecar = no checksum to verify (compatible with archives without metadata)
        if ($result['status'] === self::SIDECAR_MISSING) {
            return null;
        }

        // Sidecar exists but is corrupted/unreadable
        if ($result['status'] === self::SIDECAR_CORRUPTED) {
            return 'Sidecar metadata file is corrupted or invalid';
        }

        $sidecar = $result['data'];

        if (!isset($sidecar['checksum_sha256']) || !is_string($sidecar['checksum_sha256'])) {
            return 'No checksum found in sidecar metadata';
        }

        $storedChecksum = $sidecar['checksum_sha256'];
        $actualChecksum = hash_file('sha256', $archivePath);

        if (!is_string($actualChecksum)) {
            return 'Failed to compute archive checksum';
        }

        if ($storedChecksum !== $actualChecksum) {
            return 'Checksum mismatch: archive may be corrupted';
        }

        return null;
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
