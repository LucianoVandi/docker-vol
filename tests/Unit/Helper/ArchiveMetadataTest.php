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
}
