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

    public function testListVolumesIgnoresMalformedJsonLines(): void
    {
        $binDir = $this->createTempDirectory();
        $dockerPath = $binDir . DIRECTORY_SEPARATOR . 'docker';
        file_put_contents($dockerPath, <<<'SH'
#!/bin/sh
printf '{"Name":"volume-one","Driver":"local"}\n'
printf '{not-json}\n'
printf '{"Name":"volume-two","Driver":"local"}\n'
SH);
        chmod($dockerPath, 0755);

        $previousPath = getenv('PATH') ?: '';
        $previousServerPath = $_SERVER['PATH'] ?? null;
        $previousEnvPath = $_ENV['PATH'] ?? null;
        $testPath = $binDir . PATH_SEPARATOR . $previousPath;
        putenv('PATH=' . $testPath);
        $_SERVER['PATH'] = $testPath;
        $_ENV['PATH'] = $testPath;

        try {
            $volumes = (new DockerService())->listVolumes();

            $this->assertCount(2, $volumes);
            $this->assertSame('volume-one', $volumes[0]->name);
            $this->assertSame('volume-two', $volumes[1]->name);
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

    public function testExecuteCommandPreservesProcessExitCode(): void
    {
        $binDir = $this->createTempDirectory();
        $commandPath = $binDir . DIRECTORY_SEPARATOR . 'failing-command';
        file_put_contents($commandPath, "#!/bin/sh\necho 'command failed' >&2\nexit 12\n");
        chmod($commandPath, 0755);

        $service = new DockerService();
        $method = new \ReflectionMethod(DockerService::class, 'executeCommand');
        $method->setAccessible(true);

        try {
            $method->invoke($service, [$commandPath]);
            $this->fail('Expected DockerCommandException was not thrown.');
        } catch (DockerCommandException $exception) {
            $this->assertSame(12, $exception->getCode());
        }
    }

    public function testStreamSavedImagePreservesExitCodeAfterPartialOutput(): void
    {
        $binDir = $this->createTempDirectory();
        $dockerPath = $binDir . DIRECTORY_SEPARATOR . 'docker';
        file_put_contents($dockerPath, "#!/bin/sh\nprintf 'partial-tar'\nprintf 'stream failed' >&2\nexit 17\n");
        chmod($dockerPath, 0755);

        $previousPath = getenv('PATH') ?: '';
        $previousServerPath = $_SERVER['PATH'] ?? null;
        $previousEnvPath = $_ENV['PATH'] ?? null;
        $testPath = $binDir . PATH_SEPARATOR . $previousPath;
        putenv('PATH=' . $testPath);
        $_SERVER['PATH'] = $testPath;
        $_ENV['PATH'] = $testPath;

        $chunks = '';

        try {
            (new DockerService())->streamSavedImage('nginx:latest', function (string $chunk) use (&$chunks): void {
                $chunks .= $chunk;
            });
            $this->fail('Expected DockerCommandException was not thrown.');
        } catch (DockerCommandException $exception) {
            $this->assertSame(17, $exception->getCode());
            $this->assertStringContainsString('stream failed', $exception->getMessage());
            $this->assertSame('partial-tar', $chunks);
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
