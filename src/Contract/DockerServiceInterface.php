<?php

declare(strict_types=1);

namespace DockerVol\Contract;

use DockerVol\ValueObject\DockerImage;
use DockerVol\ValueObject\DockerVolume;
use Symfony\Component\Process\Process;

interface DockerServiceInterface
{
    /**
     * @return DockerVolume[]
     */
    public function listVolumes(): array;

    /**
     * @return DockerImage[]
     */
    public function listImages(): array;

    public function volumeExists(string $volumeName): bool;

    public function imageExists(string $imageReference): bool;

    public function runContainer(array $dockerArgs): Process;

    public function createVolume(string $volumeName): Process;

    public function saveImage(string $imageReference, string $outputPath): Process;

    public function streamSavedImage(string $imageReference, callable $onChunk): Process;

    public function loadImage(string $inputPath): Process;

    public function loadImageFromStream(callable $writeInput): Process;

    public function setTimeoutOverride(?int $seconds): void;
}
