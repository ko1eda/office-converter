<?php

namespace Breadlesscode\Office;

use Spatie\TemporaryDirectory\TemporaryDirectory;
use Symfony\Component\Process\Exception\ProcessFailedException;
use Symfony\Component\Process\Process;
use Breadlesscode\Office\Exception\ConverterException;

class Converter
{
    public static $converters = [
        \Breadlesscode\Office\Programs\WriterProgram::class,
        \Breadlesscode\Office\Programs\CalcProgram::class,
        \Breadlesscode\Office\Programs\ImpressProgram::class,
        \Breadlesscode\Office\Programs\DrawProgram::class
    ];

    protected $paramters = ['--headless'];
    protected $fileInfo = null;
    protected $file = null;
    protected $converter = null;
    protected $binaryPath = 'libreoffice';
    protected $temporaryPath = 'temp';
    protected $timeout = 2000;

    public function __construct(string $file, string $fileType = null)
    {
        if (!file_exists($file)) {
            throw new ConverterException("File ".$file." not found!", 1);
        }

        $fileExtension = $fileType ?: pathinfo(realpath($file), PATHINFO_EXTENSION);

        foreach (self::$converters as $converter) {
            if (!$converter::canHandleExtension($fileExtension)) {
                continue;
            }

            $this->fileInfo  = pathinfo($file);
            $this->file = $file;
            $this->converter = $converter;
            break;
        }

        if ($this->file === null) {
            throw new ConverterException('Can not handle file type '.$fileExtension);
        }
    }

    public static function canHandleExtension(string $fileExtension): bool
    {
        foreach (self::$converters as $converter) {
            if ($converter::canHandleExtension($fileExtension)) {
                return true;
            }
        }

        return false;
    }
    public static function file(string $file, string $fileType = null)
    {
        return new static($file, $fileType);
    }

    public function setLibreofficeBinaryPath(string $path)
    {
        $this->binaryPath = $path;

        return $this;
    }

    public function setTemporaryPath(string $path)
    {
        $this->temporaryPath = $path;

        return $this;
    }

    public function setTimeout(int $timeout)
    {
        $this->timeout = $timeout;

        return $this;
    }

    public function thumbnail(string $extension = 'jpg')
    {
        $this->save($this->fileInfo['dirname'].DIRECTORY_SEPARATOR.$this->getNewFilename($extension));
    }

    public function text()
    {
        return trim($this->content('txt'));
    }

    public function save(string $path, string $extension = null): bool
    {
        $pathInfo = pathinfo($path);
        $rename = is_null($extension);

        if (is_null($extension)) {
            $extension = $pathInfo['extension'] ? $pathInfo['extension'] : $extension;
        }

        if (!$this->isConvertable($extension)) {
            throw new ConverterException("Invalid conversion. Can not convert ".$this->fileInfo['extension']." to ".$extension, 1);
        }


        $this->setFilter($extension);
        $this->setOutputDir($rename ? $pathInfo['dirname'] : $path);
        $this->callLibreofficeBinary();

        if ($rename) {
            // rename to new name
            $oldName = $pathInfo['dirname'].DIRECTORY_SEPARATOR.$this->getNewFilename($extension);
            $newName = $pathInfo['dirname'].DIRECTORY_SEPARATOR.$pathInfo['basename'];
            rename($oldName, $newName);
        }

        return true;
    }

    public function content(string $extension = null): string
    {
        if (!$this->isConvertable($extension)) {
            throw new ConverterException("Invalid conversion. Can not convert ".$this->fileInfo['extension']." to ".$extension, 1);
        }

        $tempDir = (new TemporaryDirectory(__DIR__))
            ->name('temp')
            ->force()
            ->create()
            ->empty();

        $this->setFilter($extension);
        $this->setOutputDir($tempDir->path());
        $this->callLibreofficeBinary();

        $content = file_get_contents($tempDir->path($this->getNewFilename($extension)));
        $tempDir->delete();

        return $content;
    }

    protected function getNewFilename(string $extension)
    {
        $path = $this->file;

        return str_replace($this->fileInfo['extension'], $extension, $this->fileInfo['basename']);
    }

    protected function setOutputDir(string $dir)
    {
        $this->paramters[] = '--outdir '.$this->escapeArgument($dir);
    }

    protected function setFilter(string $extension, string $filter = '')
    {
        $arg = empty($filter) ? $extension : $extension.':'.$filter;

        $this->paramters[] = '--convert-to '.$this->escapeArgument($arg);
    }

    protected function escapeArgument(string $arg): string
    {
        return $arg;
    }

    protected function callLibreofficeBinary(): string
    {
        // add file to convert
        $filePath = $this->file;
        $this->paramters[] = $filePath;
        // glue parameters
        $cliStr = escapeshellarg($this->binaryPath);
        $cliStr.= ' '.implode(' ', $this->paramters);
        // start convert process
        $process = (new Process($cliStr))->setTimeout($this->timeout);
        $process->run();

        if (! $process->isSuccessful()) {
            throw new ProcessFailedException($process);
        }

        return $process->getOutput();
    }

    protected function isConvertable(string $extension): bool
    {
        if (is_null($extension)) {
            throw new ConverterException('No extension is set.');
        }

        return $this->converter::canConvertTo($extension);
    }
}
