<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Model\Data;

use ReadyData\Import\Api\Data\StockDataInterface;

class StockData implements StockDataInterface
{
    private float $qty = 0.0;
    private ?bool $isInStock = null;
    private ?string $sourceCode = null;
    private ?bool $manageStock = null;

    public function getQty(): float
    {
        return $this->qty;
    }

    public function setQty(float $qty): StockDataInterface
    {
        $this->qty = $qty;
        return $this;
    }

    public function getIsInStock(): ?bool
    {
        return $this->isInStock;
    }

    public function setIsInStock(bool $isInStock): StockDataInterface
    {
        $this->isInStock = $isInStock;
        return $this;
    }

    public function getSourceCode(): ?string
    {
        return $this->sourceCode;
    }

    public function setSourceCode(string $sourceCode): StockDataInterface
    {
        $this->sourceCode = $sourceCode;
        return $this;
    }

    public function getManageStock(): ?bool
    {
        return $this->manageStock;
    }

    public function setManageStock(bool $manageStock): StockDataInterface
    {
        $this->manageStock = $manageStock;
        return $this;
    }
}
