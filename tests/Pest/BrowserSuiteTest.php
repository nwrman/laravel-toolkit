<?php

declare(strict_types=1);

use Nwrman\LaravelToolkit\Pest\BrowserSuite;

beforeEach(function (): void {
    $this->root = realpath(sys_get_temp_dir()).'/toolkit-browser-'.uniqid();
    mkdir($this->root.'/tests/Browser', 0755, true);
    mkdir($this->root.'/tests/BrowserTools', 0755, true);
    mkdir($this->root.'/tests/Smoke', 0755, true);
});

afterEach(function (): void {
    exec('rm -rf '.escapeshellarg($this->root));
});

function phpunitXml(string $root, string $file, string $suites): void
{
    file_put_contents($root.'/'.$file, "<?xml version=\"1.0\"?>\n<phpunit><testsuites>{$suites}</testsuites></phpunit>");
}

it('reads every directory of the Browser suite, whatever its case', function (): void {
    phpunitXml($this->root, 'phpunit.xml', '<testsuite name="Unit"><directory>tests/Unit</directory></testsuite><testsuite name="browser"><directory>tests/Browser</directory><directory>./tests/Smoke/</directory></testsuite>');

    expect(BrowserSuite::in($this->root)->directories)->toBe([$this->root.'/tests/Browser', $this->root.'/tests/Smoke']);
});

it('prefers phpunit.xml to phpunit.xml.dist, as PHPUnit does', function (): void {
    phpunitXml($this->root, 'phpunit.xml', '<testsuite name="Browser"><directory>tests/Browser</directory></testsuite>');
    phpunitXml($this->root, 'phpunit.xml.dist', '<testsuite name="Browser"><directory>tests/Smoke</directory></testsuite>');

    expect(BrowserSuite::in($this->root)->directories)->toBe([$this->root.'/tests/Browser']);
});

it('holds the files inside its directories and no others', function (string $file, bool $inside): void {
    phpunitXml($this->root, 'phpunit.xml.dist', '<testsuite name="Browser"><directory>tests/Browser</directory></testsuite>');

    expect(BrowserSuite::in($this->root)->contains($this->root.'/'.$file))->toBe($inside);
})->with([
    'a spec in the suite' => ['tests/Browser/LoginTest.php', true],
    'a spec in a subdirectory' => ['tests/Browser/Admin/UsersTest.php', true],
    'a sibling whose name starts the same' => ['tests/BrowserTools/HelperTest.php', false],
    'a feature test' => ['tests/Feature/LoginTest.php', false],
]);

it('has no directories when the project has no Browser suite', function (): void {
    phpunitXml($this->root, 'phpunit.xml', '<testsuite name="Unit"><directory>tests/Unit</directory></testsuite>');

    expect(BrowserSuite::in($this->root)->directories)->toBe([]);
});
