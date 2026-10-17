<?php

declare(strict_types=1);

it('serves public posts, pages and opted-in custom post types', function (string $path, string $marker): void {
    $response = markdown($path);

    expect($response->isMarkdown())->toBeTrue()
        ->and($response->body)->toContain($marker);
})->with([
    ['/am-post/', 'amPostMarker'],
    ['/am-page/', 'amPageMarker'],
    ['/am_doc/am-doc/', 'amDocMarker'],
]);

it('never serves non-public content as Markdown, signed in or not', function (string $path): void {
    expect(markdown($path)->isMarkdown())->toBeFalse()
        ->and(markdown($path, cookieJar: signIn())->isMarkdown())->toBeFalse();
})->with([
    'draft' => '/?name=am-draft',
    'pending' => '/?name=am-pending',
    'private' => '/?name=am-private',
    'scheduled' => '/?name=am-future',
    'protected post, allow filter returns true' => '/am-protected/',
    'protected page' => '/am-protected-page/',
    'blocked by the allow filter' => '/am-blocked/',
    'public CPT without support' => '/am_plain/am-plain/',
    'public CPT with support removed' => '/am_removed/am-removed/',
    'non-viewable CPT with support' => '/?am_test=internal',
]);

it('reaches the non-viewable CPT post as HTML, so the check above is real', function (): void {
    expect(browser('/?am_test=internal')->body)->toContain('amInternalMarker');
});

it('honours content gates on the_content and the_posts, signed in or not', function (string $path, string $marker): void {
    $jar = signIn();

    expect(browser($path, $jar)->body)->toContain($marker)
        ->and(browser($path)->body)->toContain('amTeaserMarker')->not->toContain($marker);

    foreach ([markdown($path), markdown($path, cookieJar: $jar)] as $response) {
        expect($response->isMarkdown())->toBeTrue()
            ->and($response->body)->toContain('amTeaserMarker')->not->toContain($marker);
    }
})->with([
    'the_content gate' => ['/am-members/', 'amMembersBodyMarker'],
    'the_posts gate' => ['/am-members-loop/', 'amMembersLoopMarker'],
    'gated post inside a Query Loop' => ['/am-members-index/', 'amMembersBodyMarker'],
]);

it('lets a later-loaded plugin\'s login wall run first', function (): void {
    $gated = markdown('/am-post/?am_test=gated');

    expect($gated->status)->toBe(302)
        ->and($gated->isMarkdown())->toBeFalse()
        ->and($gated->header('location'))->toContain('wp-login.php')
        ->and(markdown('/am-post/?am_test=gated', cookieJar: signIn())->isMarkdown())->toBeTrue();
});

it('serves HTML and never a draft body when a same-slug draft collides with a published post', function (): void {
    $html = browser('/am-collision/');

    expect($html->body)->toContain('amCollisionPublishedMarker')->not->toContain('amCollisionDraftMarker')->not->toContain('type="text/markdown"')
        ->and((string) $html->header('link'))->not->toContain('text/markdown');

    $response = markdown('/am-collision/');

    expect($response->isMarkdown())->toBeFalse()
        ->and($response->body)->not->toContain('amCollisionDraftMarker');
});

it('never serves a protected post to a visitor who knows its password', function (): void {
    $jar = enterPostPassword('am-secret');

    expect(browser('/am-protected/', $jar)->body)->toContain('amProtectedMarker')
        ->and(markdown('/am-protected/', cookieJar: $jar)->isMarkdown())->toBeFalse();
});

it('handles Markdown requests as signed out, so dynamic blocks never list private posts', function (): void {
    $jar = signIn();
    $html = browser('/am-listing/', $jar);
    $response = markdown('/am-listing/', cookieJar: $jar);

    expect($html->body)->toContain('amPrivateMarker')->toContain('wpadminbar')
        ->and($response->isMarkdown())->toBeTrue()
        ->and($response->body)->toContain('Accept Markdown Post')->not->toContain('amPrivateMarker');
});

it('never serves previews or unpublished autosaves', function (): void {
    $jar = signIn();

    expect(markdown('/am-autosave/?preview=true', cookieJar: $jar)->isMarkdown())->toBeFalse()
        ->and(markdown('/am-autosave/', cookieJar: $jar)->body)->not->toContain('amAutosaveMarker');
});

it('ignores methods other than GET and HEAD', function (): void {
    expect(markdown('/am-post/', method: 'POST')->isMarkdown())->toBeFalse();
});

it('leaves feeds, REST, embeds and admin alone', function (string $path, string $type): void {
    $response = markdown($path, cookieJar: str_starts_with($path, '/wp-admin') ? signIn() : null);

    expect($response->status)->toBe(200)
        ->and($response->header('content-type'))->toStartWith($type);
})->with([
    'site feed' => ['/feed/', 'application/rss+xml'],
    'comment feed' => ['/am-post/feed/', 'application/rss+xml'],
    'REST' => ['/wp-json/wp/v2/posts', 'application/json'],
    'embed' => ['/am-post/embed/', 'text/html'],
    'admin' => ['/wp-admin/', 'text/html'],
]);
