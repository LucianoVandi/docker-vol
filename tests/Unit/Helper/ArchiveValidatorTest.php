<?php

declare(strict_types=1);

namespace DockerVol\Tests\Unit\Helper;

use DockerVol\Helper\ArchiveValidator;
use DockerVol\Tests\TestCase;

class ArchiveValidatorTest extends TestCase
{
    public function testLightweightValidationAcceptsTarArchive(): void
    {
        $archivePath = $this->createTempTarArchive('.tar');

        $this->assertNull(ArchiveValidator::validateLightweight($archivePath));
    }

    public function testLightweightValidationAcceptsGzipArchive(): void
    {
        $archivePath = $this->createTempTarArchive('.tar.gz');

        $this->assertNull(ArchiveValidator::validateLightweight($archivePath));
    }

    public function testLightweightValidationRejectsInvalidGzipHeader(): void
    {
        $archivePath = $this->createTempFile('not gzip', '.tar.gz');

        $this->assertSame('Invalid gzip header', ArchiveValidator::validateLightweight($archivePath));
    }

    public function testLightweightValidationRejectsInvalidTarHeader(): void
    {
        $archivePath = $this->createTempFile('not tar', '.tar');

        $this->assertSame('Invalid tar header', ArchiveValidator::validateLightweight($archivePath));
    }

    public function testHostTarListsArchiveContentsWhenAvailable(): void
    {
        $archivePath = $this->createTempTarArchive('.tar');
        $result = ArchiveValidator::listContentsWithHostTar($archivePath);

        if (!$result['available']) {
            $this->markTestSkipped('Host tar is not available.');
        }

        $this->assertTrue($result['successful']);
        $this->assertStringContainsString('file.txt', $result['output']);
    }
}
