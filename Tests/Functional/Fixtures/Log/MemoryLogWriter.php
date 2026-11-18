<?php

declare(strict_types=1);

namespace Ithilgers\ApiExport\Tests\Functional\Fixtures\Log;

use TYPO3\CMS\Core\Log\LogRecord;
use TYPO3\CMS\Core\Log\Writer\AbstractWriter;
use TYPO3\CMS\Core\Log\Writer\WriterInterface;

/**
 * Collects log records in memory, across frontend sub-requests of a test.
 */
final class MemoryLogWriter extends AbstractWriter
{
    /** @var list<LogRecord> */
    public static array $records = [];

    public function writeLog(LogRecord $record): WriterInterface
    {
        self::$records[] = $record;
        return $this;
    }

    public static function reset(): void
    {
        self::$records = [];
    }
}
