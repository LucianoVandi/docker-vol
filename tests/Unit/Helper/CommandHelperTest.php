<?php

declare(strict_types=1);

namespace DockerVol\Tests\Unit\Helper;

use DockerVol\Helper\CommandHelper;
use DockerVol\Tests\TestCase;

class CommandHelperTest extends TestCase
{
    public function testResolveArchivePathsReturnsRelativePathsUnderBackupDir(): void
    {
        $paths = CommandHelper::resolveArchivePaths(['myvolume.tar.gz'], '/backups');

        $this->assertSame(['/backups' . DIRECTORY_SEPARATOR . 'myvolume.tar.gz'], $paths);
    }

    public function testResolveArchivePathsKeepsUnixAbsolutePathsAsIs(): void
    {
        $paths = CommandHelper::resolveArchivePaths(['/tmp/myvolume.tar.gz'], '/backups');

        $this->assertSame(['/tmp/myvolume.tar.gz'], $paths);
    }

    public function testResolveArchivePathsKeepsWindowsDrivePathAsIs(): void
    {
        $paths = CommandHelper::resolveArchivePaths(['C:\\backups\\myvolume.tar.gz'], '/backups');

        $this->assertSame(['C:\\backups\\myvolume.tar.gz'], $paths);
    }

    public function testResolveArchivePathsKeepsWindowsDrivePathWithForwardSlashAsIs(): void
    {
        $paths = CommandHelper::resolveArchivePaths(['C:/backups/myvolume.tar.gz'], '/backups');

        $this->assertSame(['C:/backups/myvolume.tar.gz'], $paths);
    }

    public function testResolveArchivePathsKeepsUncPathAsIs(): void
    {
        $paths = CommandHelper::resolveArchivePaths(['\\\\server\\share\\myvolume.tar.gz'], '/backups');

        $this->assertSame(['\\\\server\\share\\myvolume.tar.gz'], $paths);
    }

    public function testResolveArchivePathsMixedAbsoluteAndRelative(): void
    {
        $paths = CommandHelper::resolveArchivePaths(
            ['/abs/path.tar.gz', 'relative.tar', 'C:\\win\\path.tar'],
            '/backups'
        );

        $this->assertSame([
            '/abs/path.tar.gz',
            '/backups' . DIRECTORY_SEPARATOR . 'relative.tar',
            'C:\\win\\path.tar',
        ], $paths);
    }
}
