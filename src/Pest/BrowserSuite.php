<?php

declare(strict_types=1);

namespace Nwrman\LaravelToolkit\Pest;

use SimpleXMLElement;

/**
 * The directories of the project's Browser test suite, read from phpunit.xml (or
 * phpunit.xml.dist) as absolute paths.
 */
final readonly class BrowserSuite
{
    public const string NAME = 'Browser';

    /**
     * @param  array<int, string>  $directories
     */
    public function __construct(public array $directories) {}

    public static function in(string $root): self
    {
        foreach (['phpunit.xml', 'phpunit.xml.dist'] as $file) {
            $path = $root.DIRECTORY_SEPARATOR.$file;

            if (is_file($path)) {
                return new self(self::directoriesIn($path, $root));
            }
        }

        return new self([]);
    }

    public function contains(string $file): bool
    {
        $file = realpath($file) ?: $file;

        return array_any($this->directories, fn (string $directory): bool => str_starts_with($file, $directory.DIRECTORY_SEPARATOR));
    }

    /**
     * @return array<int, string>
     */
    private static function directoriesIn(string $path, string $root): array
    {
        $xml = @simplexml_load_file($path);

        if (! $xml instanceof SimpleXMLElement) {
            return [];
        }

        $directories = [];

        foreach ($xml->xpath('//testsuite') ?: [] as $suite) {
            if (strcasecmp((string) $suite['name'], self::NAME) !== 0) {
                continue;
            }

            foreach ($suite->directory as $directory) {
                $absolute = $root.DIRECTORY_SEPARATOR.mb_trim((string) $directory, " \t\n\r\0\x0B/");
                $directories[] = mb_rtrim(realpath($absolute) ?: $absolute, DIRECTORY_SEPARATOR);
            }
        }

        return $directories;
    }
}
