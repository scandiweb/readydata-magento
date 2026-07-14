<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Api\Data;

/**
 * Stock payload for a single product.
 *
 * @api
 */
interface StockDataInterface
{
    public const QTY = 'qty';
    public const IS_IN_STOCK = 'is_in_stock';
    public const SOURCE_CODE = 'source_code';
    public const MANAGE_STOCK = 'manage_stock';

    /**
     * @return float
     */
    public function getQty(): float;

    /**
     * @param float $qty
     * @return $this
     */
    public function setQty(float $qty): self;

    /**
     * @return bool|null
     */
    public function getIsInStock(): ?bool;

    /**
     * @param bool $isInStock
     * @return $this
     */
    public function setIsInStock(bool $isInStock): self;

    /**
     * MSI source code. Defaults to "default".
     *
     * @return string|null
     */
    public function getSourceCode(): ?string;

    /**
     * @param string $sourceCode
     * @return $this
     */
    public function setSourceCode(string $sourceCode): self;

    /**
     * @return bool|null
     */
    public function getManageStock(): ?bool;

    /**
     * @param bool $manageStock
     * @return $this
     */
    public function setManageStock(bool $manageStock): self;
}
