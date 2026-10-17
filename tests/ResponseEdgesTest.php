<?php

declare(strict_types=1);

it('answers HEAD with the same headers and no body', function (): void {
    $get = markdown('/am-post/');
    $head = markdown('/am-post/', method: 'HEAD');
    $contract = ['content-type', 'cache-control', 'x-content-type-options', 'content-security-policy', 'x-robots-tag'];

    expect($head->status)->toBe(200)
        ->and($head->body)->toBe('')
        ->and(array_map($head->header(...), $contract))->toBe(array_map($get->header(...), $contract));
});

it('lets the output filter change the Markdown', function (): void {
    expect(markdown('/am-post/')->body)->toEndWith("amFooterMarker\n");
});

it('discards stray output printed while rendering', function (): void {
    $response = markdown('/am-noisy/');

    expect($response->isMarkdown())->toBeTrue()
        ->and($response->header('x-content-type-options'))->toBe('nosniff')
        ->and($response->header('content-security-policy'))->toBe("default-src 'none'; sandbox")
        ->and($response->body)->toStartWith("# Noisy\n\n")->toContain('amNoisyMarker')->not->toContain('amStrayOutputMarker');
});

it('falls back to the normal HTML page when the cacheable filter throws', function (): void {
    $response = markdown('/am-cacheable-throws/');

    expect($response->status)->toBe(200)
        ->and($response->isMarkdown())->toBeFalse()
        ->and($response->body)->toContain('amCacheableThrowsMarker')->not->toContain('amCacheableExceptionMarker');
});

it('leaves a response alone when an earlier hook changed its status', function (): void {
    $response = markdown('/am-post/?am_test=forbidden');

    expect($response->status)->toBe(403)
        ->and($response->isMarkdown())->toBeFalse();
});

it('leaves trackback URLs alone', function (): void {
    expect(markdown('/am-post/trackback/')->isMarkdown())->toBeFalse();
});

it('falls back to the normal HTML page when rendering throws', function (): void {
    $response = markdown('/am-throws/');

    expect($response->status)->toBe(200)
        ->and($response->isMarkdown())->toBeFalse()
        ->and($response->body)->toContain('amThrowsMarker')->not->toContain('amExceptionMarker');
});

it('restores shortcodes, the_content filters and comments when rendering throws midway', function (): void {
    $response = markdown('/am-throws-render/');

    expect($response->status)->toBe(200)
        ->and($response->isMarkdown())->toBeFalse()
        ->and($response->body)->toContain('amShortcodeOutput')->toContain('amTheContentMarker')->toContain('amFallbackCommentMarker')
        ->not->toContain('amRenderExceptionMarker');
});
