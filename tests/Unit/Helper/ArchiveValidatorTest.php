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

    public function testEntryValidationRejectsParentDirectoryTraversal(): void
    {
        $archivePath = $this->createTempTarArchive('.tar', '../evil.txt');

        $this->assertStringContainsString(
            'parent directory traversal',
            (string) ArchiveValidator::validateEntriesForExtraction($archivePath)
        );
    }

    public function testEntryValidationRejectsAbsolutePaths(): void
    {
        $archivePath = $this->createTempTarArchive('.tar', '/absolute.txt');

        $this->assertStringContainsString(
            'absolute path',
            (string) ArchiveValidator::validateEntriesForExtraction($archivePath)
        );
    }

    public function testEntryValidationRejectsNullBytesInNames(): void
    {
        $archivePath = $this->createTempTarArchive('.tar', "bad\0name.txt");

        $this->assertStringContainsString(
            'null byte',
            (string) ArchiveValidator::validateEntriesForExtraction($archivePath)
        );
    }

    public function testEntryValidationRejectsSymlinks(): void
    {
        $archivePath = $this->createTempFile($this->createTarContent('linked', '', '2'), '.tar');

        $this->assertStringContainsString(
            'unsafe symlink',
            (string) ArchiveValidator::validateEntriesForExtraction($archivePath)
        );
    }

    public function testEntryValidationRejectsHardlinks(): void
    {
        $archivePath = $this->createTempFile($this->createTarContent('linked', '', '1'), '.tar');

        $this->assertStringContainsString(
            'unsafe hardlink',
            (string) ArchiveValidator::validateEntriesForExtraction($archivePath)
        );
    }

    public function testReadFileFromArchiveReadsManifestFromGzipArchive(): void
    {
        $manifest = '[{"RepoTags":["nginx:latest"]}]';
        $archivePath = $this->createTempFile(gzencode($this->createTarContent('manifest.json', $manifest)), '.tar.gz');

        $this->assertSame($manifest, ArchiveValidator::readFileFromArchive($archivePath, 'manifest.json'));
    }
}
