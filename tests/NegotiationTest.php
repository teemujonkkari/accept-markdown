<?php

declare(strict_types=1);

it(
	'negotiates the representation from the Accept header',
	function ( string $accept, bool $wantsMarkdown ): void {
		$response = request( '/am-post/', array( 'Accept' => $accept ) );

		expect( $response->status )->toBe( 200 )
		->and( $response->isMarkdown() )->toBe( $wantsMarkdown );
	}
)->with(
	array(
		'markdown only'                           => array( 'text/markdown', true ),
		'case-insensitive (story 12)'             => array( 'TEXT/Markdown', true ),
		'html preferred by order and q (story 8)' => array( 'text/html, text/markdown;q=0.5', false ),
		'markdown preferred by q (story 9)'       => array( 'text/html;q=0.5, text/markdown;q=0.9', true ),
		'markdown refused with q=0 (story 10)'    => array( 'text/markdown;q=0, */*', false ),
		'most specific range wins (story 11)'     => array( 'text/*;q=0.1, text/markdown', true ),
		'browser (story 13)'                      => array( BROWSER, false ),
		'no Accept header (story 14)'             => array( '', false ),
		'neither type listed (story 15)'          => array( 'application/json', false ),
		'exact tie goes to markdown (story 59)'   => array( 'text/markdown, text/html', true ),
		'exact tie with */* (story 59)'           => array( 'text/markdown, text/html, */*', true ),
		'exact tie html first (story 59)'         => array( 'text/html, text/markdown', true ),
		'exact tie at lower q (story 59)'         => array( 'text/markdown;q=0.5, text/html;q=0.5', true ),
		'type wildcard tie goes to html'          => array( 'text/*, text/html', false ),
		'wildcard only'                           => array( '*/*', false ),
		'type wildcard only'                      => array( 'text/*', false ),
		'type wildcard beats lower exact html'    => array( 'text/*, text/html;q=0.1', true ),
		'other params before q'                   => array( 'text/markdown;charset=utf-8;q=0.8, text/html;q=0.7', true ),
		'q above 1 is clamped (story 55)'         => array( 'text/html;q=2, text/markdown', true ),
		'whitespace around = (story 55)'          => array( 'text/markdown; q = 0.9, text/html; q = 0.5', true ),
		'non-numeric q counts as 0'               => array( 'text/markdown;q=abc, text/html;q=0.1', false ),
		'32 entries is allowed'                   => array( str_repeat( 'a/b,', 31 ) . 'text/markdown', true ),
		'33 entries is too many (story 43)'       => array( str_repeat( 'a/b,', 32 ) . 'text/markdown', false ),
		'4096 bytes is allowed'                   => array( 'text/markdown;x=' . str_repeat( 'a', 4096 - 16 ), true ),
		'4097 bytes is too long (story 43)'       => array( 'text/markdown;x=' . str_repeat( 'a', 4097 - 16 ), false ),
	)
);

it(
	'treats a 10 KB Accept header as not Markdown',
	function (): void {
		$response = request( '/am-post/', array( 'Accept' => 'text/markdown;x=' . str_repeat( 'a', 10240 ) ) );

		expect( $response->isMarkdown() )->toBeFalse()
		->and( $response->status )->not->toBe( 406 );
	}
);

it(
	'leaves the sitemap and robots.txt alone',
	function ( string $path, string $accept, string $type ): void {
		$response = request( $path, array( 'Accept' => $accept ) );

		expect( $response->status )->toBe( 200 )
		->and( $response->header( 'content-type' ) )->toStartWith( $type );
	}
)->with(
	array(
		array( '/wp-sitemap.xml', 'application/xml', 'application/xml' ),
		array( '/robots.txt', 'text/plain', 'text/plain' ),
		array( '/wp-sitemap.xml', MARKDOWN, 'application/xml' ),
		array( '/robots.txt', MARKDOWN, 'text/plain' ),
	)
);
