<?php

declare(strict_types=1);

namespace DockerVol\Tests\Unit\Helper;

use DockerVol\Helper\ArchiveMetadata;
use DockerVol\Tests\TestCase;

class ArchiveMetadataTest extends TestCase
{
    public function testWritesAndReadsArchiveSidecar(): void
    {
        $archivePath = $this->createTempTarArchive('.tar.gz');

        ArchiveMetadata::writeSidecar($archivePath, [
            'source_type' => 'volume',
            'source' => 'postgres-data',
        ]);

        $metadata = ArchiveMetadata::readSidecar($archivePath);

        $this->assertIsArray($metadata);
        $this->assertSame(2, $metadata['format_version']);
        $this->assertSame('volume', $metadata['source_type']);
        $this->assertSame('postgres-data', $metadata['source']);
        $this->assertSame('gzip', $metadata['compression']);
        $this->assertSame(hash_file('sha256', $archivePath), $metadata['checksum_sha256']);
    }

    public function testMissingSidecarReturnsNull(): void
    {
        $archivePath = $this->createTempTarArchive('.tar');

        $this->assertNull(ArchiveMetadata::readSidecar($archivePath));
    }

    public function testChecksumValidReturnsNull(): void
    {
        $archivePath = $this->createTempTarArchive('.tar.gz');

        ArchiveMetadata::writeSidecar($archivePath, []);

        $failureReason = ArchiveMetadata::checksumFailureReason($archivePath);

        $this->assertNull($failureReason);
    }

    public function testMissingSidecarForChecksumIsNotAllowed(): void
    {
        $archivePath = $this->createTempTarArchive('.tar');

        // When no sidecar, checksumFailureReason returns null (compatible)
        $failureReason = ArchiveMetadata::checksumFailureReason($archivePath);

        $this->assertNull($failureReason);
    }

    public function testCorruptedArchiveChecksumMismatch(): void
    {
        $archivePath = $this->createTempTarArchive('.tar.gz');

        ArchiveMetadata::writeSidecar($archivePath, []);

        // Corrupt the archive by appending data
        file_put_contents($archivePath, 'corrupted data', FILE_APPEND);

        $failureReason = ArchiveMetadata::checksumFailureReason($archivePath);

        $this->assertSame('Checksum mismatch: archive may be corrupted', $failureReason);
    }

    public function testMissingChecksumInSidecar(): void
    {
        $archivePath = $this->createTempTarArchive('.tar.gz');

        // Write sidecar without checksum
        $sidecarPath = ArchiveMetadata::sidecarPath($archivePath);
        file_put_contents($sidecarPath, json_encode(['format_version' => 2]));

        $failureReason = ArchiveMetadata::checksumFailureReason($archivePath);

        $this->assertSame('No checksum found in sidecar metadata', $failureReason);
    }

    public function testReadSidecarWithStatusMissing(): void
    {
        $archivePath = $this->createTempTarArchive('.tar');

        $result = ArchiveMetadata::readSidecarWithStatus($archivePath);

        $this->assertSame(ArchiveMetadata::SIDECAR_MISSING, $result['status']);
        $this->assertNull($result['data']);
    }

    public function testReadSidecarWithStatusCorruptedInvalidJson(): void
    {
        $archivePath = $this->createTempTarArchive('.tar.gz');
        $sidecarPath = ArchiveMetadata::sidecarPath($archivePath);
        file_put_contents($sidecarPath, 'not valid json {{{');

        $result = ArchiveMetadata::readSidecarWithStatus($archivePath);

        $this->assertSame(ArchiveMetadata::SIDECAR_CORRUPTED, $result['status']);
        $this->assertNull($result['data']);
    }

    public function testReadSidecarWithStatusCorruptedNotArray(): void
    {
        $archivePath = $this->createTempTarArchive('.tar.gz');
        $sidecarPath = ArchiveMetadata::sidecarPath($archivePath);
        file_put_contents($sidecarPath, json_encode('string instead of object'));

        $result = ArchiveMetadata::readSidecarWithStatus($archivePath);

        $this->assertSame(ArchiveMetadata::SIDECAR_CORRUPTED, $result['status']);
        $this->assertNull($result['data']);
    }

    public function testChecksumFailsOnCorruptedSidecar(): void
    {
        $archivePath = $this->createTempTarArchive('.tar.gz');
        $sidecarPath = ArchiveMetadata::sidecarPath($archivePath);
        file_put_contents($sidecarPath, 'invalid json');

        $failureReason = ArchiveMetadata::checksumFailureReason($archivePath);

        $this->assertSame('Sidecar metadata file is corrupted or invalid', $failureReason);
    }
}
