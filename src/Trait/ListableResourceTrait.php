<?php

declare(strict_types=1);

namespace DockerVol\Trait;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

trait ListableResourceTrait
{
    /**
     * Handle the --list option for showing available resources.
     */
    protected function handleListOption(SymfonyStyle $io, InputInterface $input): int
    {
        $resources = $this->getAvailableResources($input);

        if (empty($resources)) {
            $io->warning($this->getNoResourcesMessage($input));

            return self::SUCCESS;
        }

        $io->title($this->getListTitle());

        $tableData = [];
        foreach ($resources as $resource) {
            $formattedData = $this->formatResourceForTable($resource);

            // Handle case where a resource can have multiple rows (e.g., images with multiple tags)
            if (isset($formattedData[0]) && is_array($formattedData[0])) {
                // Multiple rows returned
                foreach ($formattedData as $row) {
                    $tableData[] = $row;
                }
            } else {
                // Single row returned
                $tableData[] = $formattedData;
            }
        }

        $io->table($this->getTableHeaders(), $tableData);
        $io->text(sprintf('Total: <info>%d</info> %s', count($tableData), $this->getResourceCountLabel($input)));

        return self::SUCCESS;
    }

    /**
     * Get the available resources to list.
     * Must be implemented by the using class.
     */
    abstract protected function getAvailableResources(InputInterface $input): array;

    /**
     * Format a single resource for table display.
     * Must be implemented by the using class.
     *
     * @param mixed $resource
     */
    abstract protected function formatResourceForTable($resource): array;

    /**
     * Get table headers for the list display.
     * Must be implemented by the using class.
     */
    abstract protected function getTableHeaders(): array;

    /**
     * Get the title for the list display.
     * Must be implemented by the using class.
     */
    abstract protected function getListTitle(): string;

    /**
     * Get the message when no resources are found.
     * Must be implemented by the using class.
     */
    abstract protected function getNoResourcesMessage(InputInterface $input): string;

    /**
     * Get the label for resource count (e.g., "volumes", "images", "backup archives").
     * Must be implemented by the using class.
     */
    abstract protected function getResourceCountLabel(InputInterface $input): string;
}
