<?php

declare(strict_types=1);

namespace DockerVol\Tests\Unit\Service;

use DockerVol\Exception\DockerCommandException;
use DockerVol\Service\DockerService;
use DockerVol\Tests\TestCase;

class DockerServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('BACKUP_TIMEOUT');

        parent::tearDown();
    }

    public function testBackupTimeoutDefaultsToFiveMinutes(): void
    {
        putenv('BACKUP_TIMEOUT');

        $this->assertSame(300, $this->getBackupTimeout());
    }

    public function testBackupTimeoutUsesEnvironmentValue(): void
    {
        putenv('BACKUP_TIMEOUT=42');

        $this->assertSame(42, $this->getBackupTimeout());
    }

    public function testBackupTimeoutIgnoresInvalidEnvironmentValue(): void
    {
        putenv('BACKUP_TIMEOUT=invalid');

        $this->assertSame(300, $this->getBackupTimeout());
    }

    public function testListImagesRethrowsDockerErrors(): void
    {
        $binDir = $this->createTempDirectory();
        $dockerPath = $binDir . DIRECTORY_SEPARATOR . 'docker';
        file_put_contents($dockerPath, "#!/bin/sh\necho 'docker boom' >&2\nexit 12\n");
        chmod($dockerPath, 0755);

        $previousPath = getenv('PATH') ?: '';
        $previousServerPath = $_SERVER['PATH'] ?? null;
        $previousEnvPath = $_ENV['PATH'] ?? null;
        $testPath = $binDir . PATH_SEPARATOR . $previousPath;
        putenv('PATH=' . $testPath);
        $_SERVER['PATH'] = $testPath;
        $_ENV['PATH'] = $testPath;

        try {
            $this->expectException(DockerCommandException::class);
            $this->expectExceptionMessage('Failed to list images');

            (new DockerService())->listImages();
        } finally {
            putenv('PATH=' . $previousPath);
            if ($previousServerPath === null) {
                unset($_SERVER['PATH']);
            } else {
                $_SERVER['PATH'] = $previousServerPath;
            }
            if ($previousEnvPath === null) {
                unset($_ENV['PATH']);
            } else {
                $_ENV['PATH'] = $previousEnvPath;
            }
        }
    }

    private function getBackupTimeout(): int
    {
        $service = new DockerService();
        $method = new \ReflectionMethod(DockerService::class, 'getBackupTimeout');
        $method->setAccessible(true);

        return $method->invoke($service);
    }
}
