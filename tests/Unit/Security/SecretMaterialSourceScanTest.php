<?php

declare(strict_types=1);

namespace Nowo\YopassBundle\Tests\Unit\Security;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

use function dirname;
use function file_get_contents;
use function implode;
use function in_array;
use function is_array;
use function preg_match;
use function str_replace;
use function strlen;
use function substr;
use function token_get_all;

use const T_COMMENT;
use const T_CONSTANT_ENCAPSED_STRING;
use const T_DOC_COMMENT;
use const T_ENCAPSED_AND_WHITESPACE;
use const T_INLINE_HTML;

/**
 * Zero-knowledge guard on the source (pre-release audit EL-I2): the ciphertext may only be read where it is stored or
 * served to the browser, and nothing in src/ may dump or log values outside the reviewed logging call sites.
 * A mutant such as `error_log($share->getCiphertext())` in a service fails here.
 */
final class SecretMaterialSourceScanTest extends TestCase
{
    /** Files allowed to read the ciphertext (storage mapping and the endpoints that return it to the browser). */
    private const CIPHERTEXT_READERS = [
        'Entity/SecureShare.php',
        'Document/SecureShareDocument.php',
        'Repository/DoctrineMongoShareRepository.php',
        'Service/ShareRetriever.php',
        'Controller/PublicShareController.php',
    ];

    /** Reviewed logging call sites (none receives share content). */
    private const LOGGERS = [
        'Security/PublicEndpointRateLimiter.php',
        'Service/ShareDeleter.php',
        'Command/PurgeOldSharesCommand.php',
        // Logs the configured max_age of a skipped purge: configuration, no share data.
        'Service/ShareRetentionPurger.php',
        // Logs a failing SHARE_CONSUMED listener: share id and exception class only (no message, no ciphertext).
        'Service/ShareRetriever.php',
        // TreeBuilder ->info() node descriptions, not a logger.
        'DependencyInjection/Configuration.php',
    ];

    public function testCiphertextIsOnlyReadByTheAllowedFiles(): void
    {
        foreach ($this->sources() as $file => $code) {
            if (preg_match('/->getCiphertext\s*\(/', $code) === 1) {
                self::assertContains($file, self::CIPHERTEXT_READERS, $file . ' reads the ciphertext');
            }
        }
    }

    public function testNoDumpOrRawLogFunctionsInSources(): void
    {
        foreach ($this->sources() as $file => $code) {
            self::assertSame(0, preg_match('/(?<![\w>:$])(error_log|var_dump|var_export|print_r|dump|dd|syslog|file_put_contents)\s*\(/', $code), $file . ' dumps or logs raw values');
        }
    }

    public function testLoggerCallsOnlyInReviewedFiles(): void
    {
        foreach ($this->sources() as $file => $code) {
            if (preg_match('/->(debug|info|notice|warning|error|critical|alert|emergency|log)\s*\(/', $code) === 1) {
                self::assertTrue(in_array($file, self::LOGGERS, true), $file . ' logs; review it and add it to LOGGERS');
            }
        }
    }

    /**
     * PHP sources under src/ without comments and string literals, keyed by path relative to src/.
     *
     * @return array<string, string>
     */
    private function sources(): array
    {
        $root    = dirname(__DIR__, 3) . '/src/';
        $sources = [];

        /** @var SplFileInfo $info */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)) as $info) {
            if ($info->getExtension() !== 'php') {
                continue;
            }

            $code = [];
            foreach (token_get_all((string) file_get_contents($info->getPathname())) as $token) {
                if (is_array($token) && in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE, T_INLINE_HTML], true)) {
                    continue;
                }
                $code[] = is_array($token) ? $token[1] : $token;
            }

            $sources[str_replace('\\', '/', substr($info->getPathname(), strlen($root)))] = implode('', $code);
        }

        self::assertNotSame([], $sources);

        return $sources;
    }
}
