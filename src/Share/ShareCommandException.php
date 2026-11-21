<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Share;

use InvalidArgumentException;

/**
 * A {@see ShareCommandsInterface} call refused its input (2.1.0). The message is the stable code only, never any
 * submitted content: `invalid_ciphertext`, `ciphertext_too_large`, `invalid_expiration`, `invalid_max_reads`,
 * `invalid_payload_kind`, `file_not_enabled`, `invalid_share_id`.
 */
final class ShareCommandException extends InvalidArgumentException
{
    public const INVALID_CIPHERTEXT   = 'invalid_ciphertext';
    public const CIPHERTEXT_TOO_LARGE = 'ciphertext_too_large';
    public const INVALID_EXPIRATION   = 'invalid_expiration';
    public const INVALID_MAX_READS    = 'invalid_max_reads';
    public const INVALID_PAYLOAD_KIND = 'invalid_payload_kind';
    public const FILE_NOT_ENABLED     = 'file_not_enabled';
    public const INVALID_SHARE_ID     = 'invalid_share_id';

    /**
     * @param self::CIPHERTEXT_TOO_LARGE|self::FILE_NOT_ENABLED|self::INVALID_* $errorCode
     */
    public function __construct(public readonly string $errorCode)
    {
        parent::__construct($errorCode);
    }
}
