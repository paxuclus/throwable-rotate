<?php
declare(strict_types=1);

namespace Lala\ThrowableRotate\Log;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Core\Bootstrap;
use Neos\Flow\Log\PsrLoggerFactoryInterface;
use Neos\Flow\Log\ThrowableStorage\FileStorage;
use Neos\Flow\Log\ThrowableStorageInterface;
use Neos\Flow\Log\Utility\LogEnvironment;
use ArrayIterator;
use Neos\Utility\Files;
use PharData;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * @Flow\Proxy(false)
 * @Flow\Autowiring(false)
 */
class RotatingFileStorage extends FileStorage
{

    /**
     * @var int
     */
    protected $unbundledExceptions = 50;

    /**
     * @var int
     */
    protected $archiveThreshold = 10;

    /**
     * @var string
     */
    protected $archiveStoragePath;

    /**
     * @var int
     */
    protected $maxFilesPerRun = 500;

    public static function createWithOptions(array $options): ThrowableStorageInterface
    {
        $storage = new static(
            $options['storagePath'] ?? (FLOW_PATH_DATA . 'Logs/Exceptions'),
            $options['archiveStoragePath'] ?? (FLOW_PATH_DATA . 'Logs/Exceptions'),
            $options['exceptionsToKeep'] ?? 50,
            $options['archiveThreshold'] ?? 10,
            $options['maxFilesPerRun'] ?? 500
        );

        return $storage;
    }

    public function __construct(
        string $storagePath,
        string $archiveStoragePath,
        int $exceptionsToKeep,
        int $archiveThreshold,
        int $maxFilesPerRun = 500
    ) {
        parent::__construct($storagePath, 0, 0);

        $this->archiveStoragePath = $archiveStoragePath;
        $this->unbundledExceptions = $exceptionsToKeep;
        $this->archiveThreshold = $archiveThreshold;
        $this->maxFilesPerRun = $maxFilesPerRun;
    }

    public function logThrowable(Throwable $throwable, array $additionalData = [])
    {
        $message = parent::logThrowable($throwable, $additionalData);

        try {
            $this->archiveExceptionFiles();
        } catch (Throwable $t) {
            if (Bootstrap::$staticObjectManager === null) {
                return $message;
            }

            $loggerFactory = Bootstrap::$staticObjectManager->get(PsrLoggerFactoryInterface::class);
            $systemLogger = $loggerFactory->get('systemLogger');
            assert($systemLogger instanceof LoggerInterface);

            $systemLogger->error('Could not bundle exceptions', array_merge(
                LogEnvironment::fromMethodName(__METHOD__),
                ['error' => $t]
            ));
        }

        return $message;
    }

    public function archiveExceptionFiles(): void
    {
        $lock = fopen(Files::concatenatePaths([$this->storagePath, '.archive.lock']), 'c');
        if ($lock === false) {
            return;
        }
        // Another worker is archiving, the next exception will pick up what is left
        if (!flock($lock, LOCK_EX | LOCK_NB)) {
            fclose($lock);
            return;
        }

        try {
            $exceptions = $this->loadExceptionFiles();
            if (count($exceptions) <= $this->unbundledExceptions + $this->archiveThreshold) {
                return;
            }

            $exceptionsToBundle = array_slice(
                array_reverse(array_slice($exceptions, $this->unbundledExceptions)),
                0,
                $this->maxFilesPerRun
            );
            if (!is_dir($this->archiveStoragePath)) {
                Files::createDirectoryRecursively($this->archiveStoragePath);
            }
            $targetZipFile = Files::concatenatePaths([
                $this->archiveStoragePath,
                sprintf('exceptions-%s-%s.zip', date('Ymd-His'), bin2hex(random_bytes(3)))
            ]);
            $this->writeArchive($targetZipFile, $exceptionsToBundle);
            array_map('unlink', $exceptionsToBundle);
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * Writes a new archive in one go. Appending to an existing one makes Phar rewrite the whole archive per file.
     */
    protected function writeArchive(string $target, array $files): void
    {
        $archive = new PharData($target);
        $archive->buildFromIterator(new ArrayIterator(array_combine(array_map('basename', $files), $files)));
    }

    protected function loadExceptionFiles(): array
    {
        $dir = opendir($this->storagePath);

        $exceptionFiles = [];
        while (($file = readdir($dir)) !== false) {
            $filePath = Files::concatenatePaths([$this->storagePath, $file]);
            if (is_dir($filePath)) {
                continue;
            }
            if (strrpos($file, '.txt') !== strlen($file) - 4) {
                continue;
            }

            $exceptionFiles[$filePath] = filemtime($filePath);
        }
        closedir($dir);

        arsort($exceptionFiles);

        return array_keys($exceptionFiles);
    }

}
