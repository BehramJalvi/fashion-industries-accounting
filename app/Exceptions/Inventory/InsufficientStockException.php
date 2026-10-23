<?php

namespace App\Exceptions\Inventory;

use DomainException;

class InsufficientStockException extends DomainException
{
    public function __construct(public readonly string $productName, public readonly string $available, public readonly string $requested)
    {
        parent::__construct("Not enough stock of {$productName}: {$available} available, {$requested} requested.");
    }
}
