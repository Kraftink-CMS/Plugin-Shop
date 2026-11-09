<?php

namespace KraftInk\Plugins\Shop\Services\Imports;

use App\Kraft\Migration\MappingRegistry;

final class ImportMappings
{
    public function register(MappingRegistry $registry): void
    {
        new KraftInk\Mappings()->register($registry);
        new Azuriom\Mappings()->register($registry);
        new MineWeb\Mappings()->register($registry);
    }
}
