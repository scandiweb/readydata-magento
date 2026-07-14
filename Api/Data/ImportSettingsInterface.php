<?php
/**
 * Copyright © ReadyData. All rights reserved.
 */
declare(strict_types=1);

namespace ReadyData\Import\Api\Data;

/**
 * Per-request import settings. All fields optional; system configuration
 * provides the defaults.
 *
 * @api
 */
interface ImportSettingsInterface
{
    public const STORE_VIEW_CODE = 'store_view_code';
    public const CONTINUE_ON_ERROR = 'continue_on_error';
    public const BATCH_SIZE = 'batch_size';

    /**
     * Store view code for store-scoped attribute values. Defaults to the admin (global) scope.
     *
     * @return string|null
     */
    public function getStoreViewCode(): ?string;

    /**
     * @param string $storeViewCode
     * @return $this
     */
    public function setStoreViewCode(string $storeViewCode): self;

    /**
     * @return bool|null
     */
    public function getContinueOnError(): ?bool;

    /**
     * @param bool $continueOnError
     * @return $this
     */
    public function setContinueOnError(bool $continueOnError): self;

    /**
     * Override the configured batch size for this request.
     *
     * @return int|null
     */
    public function getBatchSize(): ?int;

    /**
     * @param int $batchSize
     * @return $this
     */
    public function setBatchSize(int $batchSize): self;
}
