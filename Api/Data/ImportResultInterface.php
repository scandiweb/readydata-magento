<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Api\Data;

/**
 * Per-product import outcome.
 *
 * @api
 */
interface ImportResultInterface
{
    public const SKU = 'sku';
    public const ENTITY_ID = 'entity_id';
    public const STATUS = 'status';
    public const MESSAGES = 'messages';

    public const STATUS_CREATED = 'created';
    public const STATUS_UPDATED = 'updated';
    public const STATUS_ERROR = 'error';

    /**
     * @return string
     */
    public function getSku(): string;

    /**
     * @param string $sku
     * @return $this
     */
    public function setSku(string $sku): self;

    /**
     * @return int|null
     */
    public function getEntityId(): ?int;

    /**
     * @param int $entityId
     * @return $this
     */
    public function setEntityId(int $entityId): self;

    /**
     * One of: created, updated, error.
     *
     * @return string
     */
    public function getStatus(): string;

    /**
     * @param string $status
     * @return $this
     */
    public function setStatus(string $status): self;

    /**
     * Warnings and errors collected for this product.
     *
     * @return string[]
     */
    public function getMessages(): array;

    /**
     * @param string[] $messages
     * @return $this
     */
    public function setMessages(array $messages): self;
}
