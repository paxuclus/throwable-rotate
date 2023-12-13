<?php
declare(strict_types=1);

namespace Lala\ThrowableRotate\Log;

use Neos\Flow\Annotations as Flow;
use Neos\Flow\Core\Bootstrap;
use Neos\Flow\Log\PsrLoggerFactoryInterface;
use Neos\Flow\Log\ThrowableStorage\FileStorage;
use Neos\Flow\Log\ThrowableStorageInterface;
use Neos\Flow\Log\Utility\LogEnvironment;
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

    public static function createWithOptions(array $options): ThrowableStorageInterface
    {
        $storage = new static(
            $options['storagePath'] ?? (FLOW_PATH_DATA . 'Logs/Exceptions'),
            $options['archiveStoragePath'] ?? (FLOW_PATH_DATA . 'Logs/Exceptions'),
            $options['exceptionsToKeep'] ?? 50,
            $options['archiveThreshold'] ?? 10
        );

        return $storage;
    }

    public function __construct(
        string $storagePath,
        string $archiveStoragePath,
        int $exceptionsToKeep,
        int $archiveThreshold
    ) {
        parent::__construct($storagePath, 0, 0);

        $this->archiveStoragePath = $archiveStoragePath;
        $this->unbundledExceptions = $exceptionsToKeep;
        $this->archiveThreshold = $archiveThreshold;
    }

    public function logThrowable(Throwable $throwable, array $additionalData = [])
    {
        $message = parent::logThrowable($throwable, $additionalData);

        try {
            $this->archiveExceptionFiles();
        } catch (Throwable $t) {
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
        $exceptions = $this->loadExceptionFiles();
        if (count($exceptions) <= $this->unbundledExceptions + $this->archiveThreshold) {
            return;
        }

        $exceptionsToBundle = array_slice($exceptions, $this->unbundledExceptions);
        if (!is_dir($this->archiveStoragePath)) {
            Files::createDirectoryRecursively($this->archiveStoragePath);
        }
        $targetZipFile = Files::concatenatePaths([
            $this->archiveStoragePath,
            'exceptions-' . date('Ymd') . '.zip'
        ]);
        $this->appendFilesToArchive($targetZipFile, $exceptionsToBundle);
    }

    protected function appendFilesToArchive(string $target, array $files): void
    {
        $archive = new PharData($target);
        foreach ($files as $file) {
            $archive->addFile($file, basename($file));
            unlink($file);
        }
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

            $exceptionFiles[] = $filePath;
        }
        closedir($dir);

        usort($exceptionFiles, function (string $a, string $b) {
            return filemtime($b) <=> filemtime($a);
        });

        return $exceptionFiles;
    }

}
