<?php

declare(strict_types=1);

namespace EbizChargeShopware\Storage\Dal;

final class DalSearchResultHelper
{
    private function __construct()
    {
    }

    public static function first(mixed $searchResult): mixed
    {
        if (!\is_object($searchResult) || !method_exists($searchResult, 'getEntities')) {
            return null;
        }

        $entities = $searchResult->getEntities();
        if (!\is_object($entities) || !method_exists($entities, 'first')) {
            return null;
        }

        return $entities->first();
    }
}
