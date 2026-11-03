<?php

declare(strict_types=1);

namespace Nwrman\LaravelToolkit\Config;

use Closure;
use Dotenv\Dotenv;
use Dotenv\Repository\Adapter\ArrayAdapter;
use Dotenv\Repository\Adapter\EnvConstAdapter;
use Dotenv\Repository\Adapter\PutenvAdapter;
use Dotenv\Repository\Adapter\ServerConstAdapter;
use Dotenv\Repository\RepositoryBuilder;
use Dotenv\Repository\RepositoryInterface;
use Illuminate\Support\Env;
use Throwable;

/**
 * The toolkit's configuration, read without booting Laravel: the package's
 * config/toolkit.php with the app's merged over it, the way the service provider does.
 *
 * `env()` inside those files sees the process environment first, then the app's `.env`
 * as Dotenv::parse() reads it. The parsed values only exist while the files are read:
 * nothing is put in the environment, so no process this one starts can inherit them.
 * The app's config file can call `env()`, but no helper that needs the application.
 */
final class ToolkitConfig
{
    /**
     * @return array<string, mixed>
     */
    public static function load(string $root): array
    {
        $values = self::dotenv($root.DIRECTORY_SEPARATOR.'.env');

        return self::withEnvironment($values, function () use ($root): array {
            $package = self::read(dirname(__DIR__, 2).'/config/toolkit.php');
            $app = $root.DIRECTORY_SEPARATOR.'config'.DIRECTORY_SEPARATOR.'toolkit.php';

            return self::merge($package, is_file($app) ? self::read($app) : []);
        });
    }

    /**
     * The service provider's merge: the app's top-level keys replace the package's, but
     * gates merge per gate, so overriding one gate keeps the others, and a gate set to
     * null is removed.
     *
     * @param  array<string, mixed>  $package
     * @param  array<string, mixed>  $app
     * @return array<string, mixed>
     */
    public static function merge(array $package, array $app): array
    {
        $merged = array_merge($package, $app);

        if (is_array($app['gates'] ?? null)) {
            $packageGates = is_array($package['gates'] ?? null) ? $package['gates'] : [];
            $gates = array_replace_recursive($packageGates, $app['gates']);
            $merged['gates'] = array_filter($gates, fn (mixed $gate): bool => $gate !== null);
        }

        return $merged;
    }

    /**
     * @return array<string, mixed>
     */
    private static function read(string $file): array
    {
        $config = require $file;

        if (! is_array($config)) {
            return [];
        }

        /** @var array<string, mixed> $config */
        return $config;
    }

    /**
     * @return array<string, string|null>
     */
    private static function dotenv(string $file): array
    {
        if (! is_file($file)) {
            return [];
        }

        try {
            return Dotenv::parse((string) file_get_contents($file));
        } catch (Throwable) {
            return [];
        }
    }

    /**
     * Runs $callback with `env()` reading the process environment, then $values.
     *
     * @template T
     *
     * @param  array<string, string|null>  $values
     * @param  Closure(): T  $callback
     * @return T
     */
    private static function withEnvironment(array $values, Closure $callback): mixed
    {
        $file = ArrayAdapter::create()->get();

        foreach ($values as $name => $value) {
            if ($name !== '' && $value !== null) {
                $file->write($name, $value);
            }
        }

        $repository = RepositoryBuilder::createWithNoAdapters()
            ->addReader(ServerConstAdapter::class)
            ->addReader(EnvConstAdapter::class)
            ->addReader(PutenvAdapter::class)
            ->addReader($file)
            ->immutable()
            ->make();

        $swap = Closure::bind(static function (?RepositoryInterface $repository): ?RepositoryInterface {
            $previous = self::$repository;
            self::$repository = $repository;

            return $previous;
        }, null, Env::class);

        $previous = $swap($repository);

        try {
            return $callback();
        } finally {
            $swap($previous);
        }
    }
}
