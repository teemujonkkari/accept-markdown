<?php

declare(strict_types=1);

it('serves a published post as Markdown with the full header set', function (): void {
    $response = markdown('/am-post/');

    expect($response->status)->toBe(200)
        ->and($response->header('content-type'))->toBe('text/markdown; charset=UTF-8')
        ->and($response->header('vary'))->toContain('Accept')
        ->and((string) $response->header('cache-control'))->not->toContain('no-store')
        ->and($response->header('x-content-type-options'))->toBe('nosniff')
        ->and($response->header('content-security-policy'))->toBe("default-src 'none'; sandbox")
        ->and($response->header('x-robots-tag'))->toBe('noindex')
        ->and($response->body)->toStartWith("# Accept Markdown Post\n\n")
        ->toContain('## A heading')
        ->toContain('amPostMarker body text.');
});

it('sends no-store when the cacheable filter says no', function (): void {
    expect(markdown('/am-page/')->header('cache-control'))->toBe('no-store, private');
});

it('serves the normal HTML page with Vary: Accept to browsers', function (): void {
    $response = browser('/am-post/');

    expect($response->status)->toBe(200)
        ->and($response->header('content-type'))->toStartWith('text/html')
        ->and($response->header('vary'))->toContain('Accept')
        ->and($response->body)->toContain('<html');
});

it('adds Vary: Accept to every front-end response', function (string $path): void {
    expect(browser($path)->header('vary'))->toContain('Accept');
})->with(['/', '/am-page/', '/does-not-exist/', '/feed/', '/wp-sitemap.xml']);
