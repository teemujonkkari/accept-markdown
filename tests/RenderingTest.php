<?php

declare(strict_types=1);

it('runs shortcodes, the_content filters and render_block filters, as the public page does', function (): void {
    $body = markdown('/am-shortcodes/')->body;

    expect($body)->toContain('Self-closing: amShortcodeOutput')->toContain('Enclosing: amShortcodeOutput done.')
        ->toContain('Pattern text amShortcodeOutput amPatternText')
        ->toContain('amRenderBlockMarker')
        ->toContain('amTheContentMarker')
        // Markdown escapes "[" and "_", so look for leftover shortcode text without the backslashes.
        ->and(str_replace('\\', '', $body))->not->toContain('[am_test');
});

it('renders nested blocks through the same chain', function (): void {
    $body = markdown('/am-nested/')->body;

    expect($body)->toContain('Before the loop.')->toContain('amNestedSourceText amShortcodeOutput')->toContain('After the loop.')
        ->toContain('amTheContentMarker')
        ->and(str_replace('\\', '', $body))->not->toContain('[am_test');
});

it('never shows WordPress comments, counts or forms, including through comment blocks', function (): void {
    expect(browser('/am-comments/')->body)->toContain('amBlockCommentMarker')->toContain('amCommentAuthorMarker');

    expect(markdown('/am-comments/')->body)->toBe("# Comments\n\nBefore the comments.\n\nAfter the comments.\n\namTheContentMarker\n");
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

it('removes closed non-content elements innermost first and everything after an unclosed one', function (): void {
    $body = markdown('/am-removal/')->body;

    expect($body)->toContain('Before.')->toContain('Between.')->toContain('amAfterNestedMarker');

    foreach (['amInnerObjectMarker', 'amOuterObjectMarker', 'amUnclosedOneMarker', 'amUnclosedTwoMarker', 'amUnclosedThreeMarker', 'amAfterUnclosedMarker'] as $forbidden) {
        expect($body)->not->toContain($forbidden);
    }
});

it('converts tables, code and figures cleanly', function (): void {
    $body = markdown('/am-convert/')->body;

    expect($body)->toContain('| Name | Value |')->toContain('| alpha | 1 |')
        ->toContain("```\nif (a < b && c) { run(); }\n```")
        ->not->toContain('<span')->not->toContain('hl-k')
        ->toMatch('/!\[An image\]\(https:\/\/example\.com\/image\.png\)\s*\n\s*\n?amCaptionMarker/')
        ->toContain("amCaptionMarker\n\nParagraph after the figure.")
        ->toContain('[0401234567](tel:0401234567) <am@example.invalid> [amMoreLink](https://example.com/more)');
});

it('never emits more than one blank line in a row', function (string $path): void {
    expect(markdown($path)->body)->not->toContain("\n\n\n");
})->with(['/am-post/', '/am-shortcodes/', '/am-classic/', '/am-unsafe/', '/am-convert/']);
