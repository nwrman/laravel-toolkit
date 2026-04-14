<?php

declare(strict_types=1);

it('registers the toolkit:install command', function () {
    $this->artisan('list')
        ->assertSuccessful()
        ->expectsOutputToContain('toolkit:install');
});
