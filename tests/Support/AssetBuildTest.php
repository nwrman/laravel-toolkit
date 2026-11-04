<?php

declare(strict_types=1);

use Illuminate\Filesystem\Filesystem;
use Illuminate\Process\Factory;
use Nwrman\LaravelToolkit\Support\AssetBuild;

beforeEach(function (): void {
    $this->root = realpath(sys_get_temp_dir()).'/toolkit-assets-'.uniqid();
    mkdir($this->root.'/resources/js', 0755, true);
    mkdir($this->root.'/public/build', 0755, true);
    file_put_contents($this->root.'/resources/js/app.ts', 'export {}');
    touch($this->root.'/resources/js/app.ts', time() - 60);
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->root));
});

/**
 * A build that does what Vite does: writes the manifest and deletes the hot file.
 */
function assetBuild(string $root, string $command = 'printf {} > public/build/manifest.json && rm -f public/hot'): AssetBuild
{
    return new AssetBuild($root, ['command' => $command, 'watch_paths' => 'resources/ package.json'], new Filesystem, new Factory);
}

it('is stale with no build yet, and fresh right after one', function (): void {
    $build = assetBuild($this->root);
    $stale = $build->isStale();

    $built = $build->build(fn (string $output) => null);

    expect($stale)->toBeTrue()
        ->and($built)->toBeTrue()
        ->and($build->isStale())->toBeFalse();
});

it('is stale once a watched file is newer than the build', function (string $file): void {
    $build = assetBuild($this->root);
    $build->build(fn (string $output) => null);
    touch($this->root.'/public/build/.buildstamp', time() - 30);

    file_put_contents($this->root.'/'.$file, 'changed');

    expect($build->isStale())->toBeTrue();
})->with(['a file in a watched directory' => 'resources/js/nested.ts', 'a watched file' => 'package.json']);

it('puts back the hot file the build deleted, so the dev site keeps its dev server', function (string $command, bool $succeeds): void {
    file_put_contents($this->root.'/public/hot', 'http://[::1]:5173');

    $built = assetBuild($this->root, $command)->build(fn (string $output) => null);

    expect($built)->toBe($succeeds)
        ->and(file_get_contents($this->root.'/public/hot'))->toBe('http://[::1]:5173');
})->with([
    'a build that passes' => ['printf {} > public/build/manifest.json && rm -f public/hot', true],
    'a build that fails' => ['rm -f public/hot && exit 1', false],
]);

it('leaves no hot file when there was none before the build', function (): void {
    assetBuild($this->root)->build(fn (string $output) => null);

    expect(file_exists($this->root.'/public/hot'))->toBeFalse();
});

it('streams the build output and leaves no stamp when the build fails', function (): void {
    $streamed = '';

    assetBuild($this->root, 'echo vite exploded && exit 1')->build(function (string $output) use (&$streamed): void {
        $streamed .= $output;
    });

    expect($streamed)->toBe("vite exploded\n")
        ->and(file_exists($this->root.'/public/build/.buildstamp'))->toBeFalse();
});
