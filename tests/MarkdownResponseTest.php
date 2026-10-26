<?php

declare(strict_types=1);

it('serves a published post as Markdown with the full header set', function (): void {
    $response = markdown('/am-post/');

    expect($response->status)->toBe(200)
        ->and($response->header('content-type'))->toBe('text/markdown; charset=UTF-8')
        ->and($response->header('vary'))->toContain('Accept')
        ->and($response->header('cache-control'))->toBe(browser('/am-post/')->header('cache-control'))
        ->and($response->header('x-content-type-options'))->toBe('nosniff')
        ->and($response->header('content-security-policy'))->toBe("default-src 'none'; sandbox")
        ->and($response->header('x-robots-tag'))->toBe('noindex')
        ->and($response->body)->toStartWith("# Accept Markdown Post\n\n")
        ->toContain('## A heading')
        ->toContain('amPostMarker body text.');
});

it('serves the normal HTML page with Vary: Accept to browsers', function (): void {
    $response = browser('/am-post/');

    expect($response->status)->toBe(200)
        ->and($response->header('content-type'))->toStartWith('text/html')
        ->and($response->header('vary'))->toContain('Accept')
        ->and($response->body)->toContain('<html');
});

it('advertises the Markdown alternate with a Link header and a head tag on eligible posts only', function (): void {
    $link = '; rel="alternate"; type="text/markdown"';
    $tag = '<link rel="alternate" type="text/markdown" href="'.baseUrl().'/am-post/" />';

    expect(browser('/am-post/')->header('link'))->toContain('/am-post/>'.$link)
        ->and(browser('/am-post/')->body)->toContain($tag)
        ->and(markdown('/am-post/')->header('link'))->toContain('/am-post/>'.$link);

    foreach (['/am-blocked/', '/am-protected/', '/'] as $path) {
        $response = browser($path);
        expect((string) $response->header('link'))->not->toContain('text/markdown')
            ->and($response->body)->not->toContain('type="text/markdown"');
    }
});

it('adds Vary: Accept to every front-end response', function (string $path): void {
    expect(browser($path)->header('vary'))->toContain('Accept');
})->with(['/', '/am-page/', '/does-not-exist/', '/feed/', '/wp-sitemap.xml']);
