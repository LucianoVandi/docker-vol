<?php

declare(strict_types=1);

namespace DockerBackup\Tests\Unit\Service;

use DockerBackup\Service\DockerService;
use DockerBackup\Tests\TestCase;

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

    private function getBackupTimeout(): int
    {
        $service = new DockerService();
        $method = new \ReflectionMethod(DockerService::class, 'getBackupTimeout');
        $method->setAccessible(true);

        return $method->invoke($service);
    }
}
