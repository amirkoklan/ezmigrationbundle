<?php

namespace Kaliop\eZMigrationBundle\API;

/**
 * Interface that all migration definition handlers need to implement if they can generate a migration definition.
 */
interface MigrationGeneratorInterface
{
    /**
     * Generate a migration definition in array format
     *
     * @param array $matchCondition
     * @param string $mode
     * @return array Migration data
     * @throws \Exception
     */
    public function generateMigration(array $matchCondition, $mode);
}
