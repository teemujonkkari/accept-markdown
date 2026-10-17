<?php

declare(strict_types=1);

it('reaches the site', function (): void {
    $response = browser('/am-post/');

    expect($response->status)->toBe(200)
        ->and($response->header('content-type'))->toStartWith('text/html');
});
