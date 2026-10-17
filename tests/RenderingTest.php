<?php

declare(strict_types=1);

it('never runs shortcodes or the_content filters, but keeps render_block output', function (): void {
    $body = markdown('/am-shortcodes/')->body;

    expect($body)->toContain('Self-closing:')->toContain('Enclosing:')->toContain('done.')
        ->toContain('amPatternText')
        ->toContain('amRenderBlockMarker')
        ->not->toContain('amShortcodeOutput')
        ->not->toContain('amEnclosed')
        ->not->toContain('amTheContentMarker')
        // Markdown escapes "[" and "_", so look for leaked shortcode text without the backslashes.
        ->and(str_replace('\\', '', $body))->not->toContain('[am_test');
});

it('restores paragraphs in classic-editor posts', function (): void {
    expect(markdown('/am-classic/')->body)->toContain("First classic paragraph.\n\nSecond classic paragraph.");
});

it('removes non-content elements, unsafe links, HTML comments and WordPress comments', function (): void {
    $body = markdown('/am-unsafe/')->body;

    expect($body)->toStartWith("# Unsafe title & more second line\n\n")
        ->toContain('Visible text.')
        ->toContain('[good link](https://example.com/)')
        ->toContain('Text before the unclosed script.');

    foreach (['javascript:', '<!--', 'amHtmlCommentMarker', 'amScriptMarker', 'amStyleMarker', 'amSvgInnerMarker', 'amSvgOuterMarker', 'amNoscriptMarker', 'amUnclosedMarker', 'amAfterUnclosedMarker', 'amCommentMarker'] as $forbidden) {
        expect($body)->not->toContain($forbidden);
    }
});

it('converts tables, code and figures cleanly', function (): void {
    $body = markdown('/am-convert/')->body;

    expect($body)->toContain('| Name | Value |')->toContain('| alpha | 1 |')
        ->toContain("```\nif (a < b && c) { run(); }\n```")
        ->not->toContain('<span')->not->toContain('hl-k')
        ->toMatch('/!\[An image\]\(https:\/\/example\.com\/image\.png\)\s*\n\s*\n?amCaptionMarker/')
        ->toContain("amCaptionMarker\n\nParagraph after the figure.");
});

it('never emits more than one blank line in a row', function (string $path): void {
    expect(markdown($path)->body)->not->toContain("\n\n\n");
})->with(['/am-post/', '/am-shortcodes/', '/am-classic/', '/am-unsafe/', '/am-convert/']);
