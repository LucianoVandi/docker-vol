<?php

declare(strict_types=1);

namespace DockerBackup\Service;

use DockerBackup\Contract\DockerServiceInterface;
use DockerBackup\Exception\DockerCommandException;
use DockerBackup\ValueObject\DockerImage;
use DockerBackup\ValueObject\DockerVolume;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;

class DockerService implements DockerServiceInterface
{
    private const DOCKER_COMMAND = 'docker';

    private ?int $timeoutOverride = null;

    /**
     * @return DockerVolume[]
     */
    public function listVolumes(): array
    {
        try {
            $process = $this->runDockerCommand(['volume', 'ls', '--format', 'json']);
            $output = trim($process->getOutput());

            if (empty($output)) {
                return [];
            }

            $volumes = [];
            foreach (explode("\n", $output) as $line) {
                $line = trim($line);
                if (empty($line)) {
                    continue;
                }

                $data = json_decode($line, true);
                if (json_last_error() === JSON_ERROR_NONE && isset($data['Name'])) {
                    $volumes[] = DockerVolume::fromArray($data);
                }
            }

            return $volumes;
        } catch (DockerCommandException $e) {
            throw new DockerCommandException(
                'Failed to list volumes. Please ensure Docker 23+ is installed: ' . $e->getMessage()
            );
        }
    }

    /**
     * @return DockerImage[]
     */
    public function listImages(): array
    {
        try {
            $process = $this->runDockerCommand(['images', '--format', 'json']);
            $output = trim($process->getOutput());

            if (empty($output)) {
                return [];
            }

            $images = [];
            $lines = explode("\n", $output);

            foreach ($lines as $line) {
                $line = trim($line);
                if (empty($line)) {
                    continue;
                }

                $data = json_decode($line, true);
                if (json_last_error() === JSON_ERROR_NONE && is_array($data)) {
                    // Transform Docker CLI format to our expected format
                    $transformedData = $this->transformDockerImageData($data);
                    $images[] = DockerImage::fromArray($transformedData);
                }
            }

            return $images;
        } catch (DockerCommandException $e) {
            throw new DockerCommandException(
                'Failed to list images. Please ensure Docker 23+ is installed: ' . $e->getMessage()
            );
        }
    }

    public function volumeExists(string $volumeName): bool
    {
        try {
            $this->runDockerCommand(['volume', 'inspect', $volumeName]);

            return true;
        } catch (DockerCommandException) {
            return false;
        }
    }

    public function imageExists(string $imageReference): bool
    {
        try {
            $this->runDockerCommand(['image', 'inspect', $imageReference]);

            return true;
        } catch (DockerCommandException) {
            return false;
        }
    }

    public function runContainer(array $dockerArgs): Process
    {
        $command = array_merge([self::DOCKER_COMMAND, 'run'], $dockerArgs);

        return $this->executeCommand($command);
    }

    public function createVolume(string $volumeName): Process
    {
        return $this->runDockerCommand(['volume', 'create', $volumeName]);
    }

    public function saveImage(string $imageReference, string $outputPath): Process
    {
        return $this->runDockerCommand(['save', '-o', $outputPath, $imageReference]);
    }

    public function streamSavedImage(string $imageReference, callable $onChunk): Process
    {
        $process = $this->createDockerProcess(['save', $imageReference]);
        $process->start();
        $errorOutput = '';

        try {
            foreach ($process as $type => $chunk) {
                if ($type === Process::ERR) {
                    $errorOutput .= $chunk;

                    continue;
                }

                if ($chunk === '') {
                    continue;
                }

                $onChunk($chunk);
            }
        } catch (\Throwable $exception) {
            $process->stop();

            throw $exception;
        }

        if (!$process->isSuccessful()) {
            throw new DockerCommandException(
                sprintf('Docker command failed: %s', trim($errorOutput)),
                $process->getExitCode() ?? 1
            );
        }

        return $process;
    }

    public function loadImage(string $inputPath): Process
    {
        return $this->runDockerCommand(['load', '-i', $inputPath]);
    }

    public function setTimeoutOverride(?int $seconds): void
    {
        $this->timeoutOverride = $seconds;
    }

    private function parseSizeToBytes(string $sizeString): int
    {
        if (empty($sizeString) || $sizeString === 'N/A') {
            return 0;
        }

        $sizeString = strtoupper(trim($sizeString));

        if (preg_match('/^([0-9.]+)\s*([KMGT]?B)$/', $sizeString, $matches)) {
            $number = (float) $matches[1];
            $unit = $matches[2];

            return match ($unit) {
                'B' => (int) $number,
                'KB' => (int) ($number * 1024),
                'MB' => (int) ($number * 1024 * 1024),
                'GB' => (int) ($number * 1024 * 1024 * 1024),
                'TB' => (int) ($number * 1024 * 1024 * 1024 * 1024),
                default => 0,
            };
        }

        return 0;
    }

    private function parseCreatedToTimestamp(string $createdString): int
    {
        if (empty($createdString)) {
            return 0;
        }

        try {
            $date = new \DateTimeImmutable($createdString);

            return $date->getTimestamp();
        } catch (\Exception) {
            return 0;
        }
    }

    private function transformDockerImageData(array $dockerData): array
    {
        // Build RepoTags array
        $repoTags = [];
        $repository = $dockerData['Repository'] ?? '';
        $tag = $dockerData['Tag'] ?? '';

        if ($repository !== '<none>' && $tag !== '<none>') {
            $repoTags[] = $repository . ':' . $tag;
        }

        // Parse size from string like "805MB" to bytes
        $sizeBytes = $this->parseSizeToBytes($dockerData['Size'] ?? '0');

        // Parse created date to timestamp
        $createdTimestamp = $this->parseCreatedToTimestamp($dockerData['CreatedAt'] ?? '');

        return [
            'Id' => $dockerData['ID'] ?? $dockerData['Id'] ?? '',
            'RepoTags' => $repoTags,
            'Size' => $sizeBytes,
            'Created' => $createdTimestamp,
            'Labels' => [],
        ];
    }

    private function runDockerCommand(array $args): Process
    {
        return $this->executeCommand($this->createDockerCommand($args));
    }

    private function createDockerProcess(array $args): Process
    {
        return $this->createProcess($this->createDockerCommand($args));
    }

    private function createDockerCommand(array $args): array
    {
        return array_merge([self::DOCKER_COMMAND], $args);
    }

    private function executeCommand(array $command): Process
    {
        $process = $this->createProcess($command);

        try {
            $process->mustRun();

            return $process;
        } catch (ProcessFailedException $exception) {
            throw new DockerCommandException(
                sprintf('Docker command failed: %s', $exception->getMessage()),
                $exception->getCode(),
                $exception
            );
        }
    }

    private function createProcess(array $command): Process
    {
        $process = new Process($command);
        $process->setTimeout($this->getBackupTimeout());

        return $process;
    }

    private function getBackupTimeout(): int
    {
        if ($this->timeoutOverride !== null && $this->timeoutOverride > 0) {
            return $this->timeoutOverride;
        }

        $timeout = getenv('BACKUP_TIMEOUT');
        if ($timeout === false || !ctype_digit($timeout) || (int) $timeout <= 0) {
            return 300;
        }

        return (int) $timeout;
    }
}
