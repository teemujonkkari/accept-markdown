<?php

declare(strict_types=1);

it('negotiates the representation from the Accept header', function (string $accept, bool $wantsMarkdown): void {
    $response = request('/am-post/', ['Accept' => $accept]);

    expect($response->status)->toBe(200)
        ->and($response->isMarkdown())->toBe($wantsMarkdown);
})->with([
    'markdown only' => ['text/markdown', true],
    'case-insensitive (story 12)' => ['TEXT/Markdown', true],
    'html preferred by order and q (story 8)' => ['text/html, text/markdown;q=0.5', false],
    'markdown preferred by q (story 9)' => ['text/html;q=0.5, text/markdown;q=0.9', true],
    'markdown refused with q=0 (story 10)' => ['text/markdown;q=0, */*', false],
    'most specific range wins (story 11)' => ['text/*;q=0.1, text/markdown', true],
    'browser (story 13)' => [BROWSER, false],
    'no Accept header (story 14)' => ['', false],
    'neither type listed (story 15)' => ['application/json', false],
    'tie goes to html' => ['text/markdown, text/html', false],
    'wildcard only' => ['*/*', false],
    'type wildcard beats lower exact html' => ['text/*, text/html;q=0.1', true],
    'other params before q' => ['text/markdown;charset=utf-8;q=0.8, text/html;q=0.7', true],
    'q above 1 is clamped (story 55)' => ['text/markdown;q=2, text/html', false],
    'whitespace around = (story 55)' => ['text/markdown; q = 0.9, text/html; q = 0.5', true],
    'non-numeric q counts as 0' => ['text/markdown;q=abc, text/html;q=0.1', false],
    '32 entries is allowed' => [str_repeat('a/b,', 31).'text/markdown', true],
    '33 entries is too many (story 43)' => [str_repeat('a/b,', 32).'text/markdown', false],
    '4096 bytes is allowed' => ['text/markdown;x='.str_repeat('a', 4096 - 16), true],
    '4097 bytes is too long (story 43)' => ['text/markdown;x='.str_repeat('a', 4097 - 16), false],
]);

it('treats a 10 KB Accept header as not Markdown', function (): void {
    $response = request('/am-post/', ['Accept' => 'text/markdown;x='.str_repeat('a', 10240)]);

    expect($response->isMarkdown())->toBeFalse()
        ->and($response->status)->not->toBe(406);
});

it('leaves the sitemap and robots.txt alone', function (string $path, string $accept, string $type): void {
    $response = request($path, ['Accept' => $accept]);

    expect($response->status)->toBe(200)
        ->and($response->header('content-type'))->toStartWith($type);
})->with([
    ['/wp-sitemap.xml', 'application/xml', 'application/xml'],
    ['/robots.txt', 'text/plain', 'text/plain'],
    ['/wp-sitemap.xml', MARKDOWN, 'application/xml'],
    ['/robots.txt', MARKDOWN, 'text/plain'],
]);
