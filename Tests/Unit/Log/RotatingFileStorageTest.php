<?php

declare(strict_types=1);

namespace Lala\ThrowableRotate\Tests\Unit\Log;

use Lala\ThrowableRotate\Log\RotatingFileStorage;
use Neos\Utility\Files;
use PharData;
use PHPUnit\Framework\TestCase;

class RotatingFileStorageTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/throwable-rotate-' . bin2hex(random_bytes(4));
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        Files::removeDirectoryRecursively($this->directory);
    }

    public function testOldestExceptionsAreBundledIntoOneNewZipArchive(): void
    {
        $this->createExceptionFiles(70);

        $this->createStorage()->archiveExceptionFiles();

        $remaining = $this->exceptionFiles();
        self::assertCount(50, $remaining);
        self::assertSame('exception-21.txt', basename(reset($remaining)));

        $archives = glob($this->directory . '/*.zip');
        self::assertCount(1, $archives);
        self::assertSame("PK\3\4", file_get_contents($archives[0], false, null, 0, 4));
        self::assertCount(20, new PharData($archives[0]));
        self::assertTrue(isset((new PharData($archives[0]))['exception-1.txt']));
    }

    public function testNothingIsArchivedBelowThreshold(): void
    {
        $this->createExceptionFiles(60);

        $this->createStorage()->archiveExceptionFiles();

        self::assertCount(60, $this->exceptionFiles());
        self::assertSame([], glob($this->directory . '/*.zip'));
    }

    public function testOnlyMaxFilesPerRunAreArchivedStartingWithTheOldest(): void
    {
        $this->createExceptionFiles(100);

        $this->createStorage(15)->archiveExceptionFiles();

        $remaining = $this->exceptionFiles();
        self::assertCount(85, $remaining);
        self::assertSame('exception-16.txt', basename(reset($remaining)));
    }

    public function testNothingIsArchivedWhileAnotherWorkerHoldsTheLock(): void
    {
        $this->createExceptionFiles(70);
        $lock = fopen($this->directory . '/.archive.lock', 'c');
        flock($lock, LOCK_EX);

        try {
            $this->createStorage()->archiveExceptionFiles();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        self::assertCount(70, $this->exceptionFiles());
        self::assertSame([], glob($this->directory . '/*.zip'));
    }

    private function createStorage(int $maxFilesPerRun = 500): RotatingFileStorage
    {
        return new RotatingFileStorage($this->directory, $this->directory, 50, 10, $maxFilesPerRun);
    }

    /**
     * exception-1.txt is the oldest one
     */
    private function createExceptionFiles(int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            $file = $this->directory . '/exception-' . $i . '.txt';
            file_put_contents($file, 'Exception ' . $i);
            touch($file, 1_700_000_000 + $i);
        }
    }

    /**
     * @return string[] oldest first
     */
    private function exceptionFiles(): array
    {
        $files = glob($this->directory . '/*.txt');
        usort($files, fn (string $a, string $b) => filemtime($a) <=> filemtime($b));
        return $files;
    }
}
