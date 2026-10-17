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

it('falls back to the normal HTML page when rendering throws', function (): void {
    $response = markdown('/am-throws/');

    expect($response->status)->toBe(200)
        ->and($response->isMarkdown())->toBeFalse()
        ->and($response->body)->toContain('amThrowsMarker')->not->toContain('amExceptionMarker');
});
