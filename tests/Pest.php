<?php

declare(strict_types=1);

// One seam: black-box HTTP requests against ACCEPT_MARKDOWN_BASE_URL (default: the Local dev site).

const MARKDOWN = 'text/markdown';
const BROWSER = 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8';

final readonly class Response
{
    /** @param array<string, list<string>> $headers lowercased names, every value kept */
    public function __construct(public int $status, public array $headers, public string $body) {}

    public function header(string $name): ?string
    {
        $values = $this->headers[strtolower($name)] ?? [];

        return $values === [] ? null : implode(', ', $values);
    }

    public function isMarkdown(): bool
    {
        return str_starts_with((string) $this->header('content-type'), MARKDOWN);
    }
}

function baseUrl(): string
{
    return rtrim(getenv('ACCEPT_MARKDOWN_BASE_URL') ?: 'http://accept-markdown.local', '/');
}

/**
 * @param array<string, string> $headers
 * @param array<string, string>|null $form sent as a POST body
 */
function request(string $path, array $headers = [], string $method = 'GET', ?string $cookieJar = null, ?array $form = null): Response
{
    $responseHeaders = [];
    $curl = curl_init(baseUrl().$path);
    curl_setopt_array($curl, [
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_NOBODY => $method === 'HEAD',
        CURLOPT_RETURNTRANSFER => true,
        // An empty value ("Accept:") makes curl omit the header entirely.
        CURLOPT_HTTPHEADER => array_map(fn (string $name, string $value): string => $value === '' ? "$name:" : "$name: $value", array_keys($headers), $headers),
        CURLOPT_HEADERFUNCTION => function ($curl, string $line) use (&$responseHeaders): int {
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $responseHeaders[strtolower(trim($name))][] = trim($value);
            }

            return strlen($line);
        },
    ]);
    if ($form !== null) {
        curl_setopt($curl, CURLOPT_POSTFIELDS, http_build_query($form));
    }
    if ($cookieJar !== null) {
        curl_setopt_array($curl, [CURLOPT_COOKIEFILE => $cookieJar, CURLOPT_COOKIEJAR => $cookieJar]);
    }

    $body = curl_exec($curl);
    if ($body === false) {
        throw new RuntimeException('Request to '.baseUrl().$path.' failed: '.curl_error($curl));
    }

    return new Response(curl_getinfo($curl, CURLINFO_RESPONSE_CODE), $responseHeaders, $body);
}

function markdown(string $path, array $headers = [], string $method = 'GET', ?string $cookieJar = null): Response
{
    return request($path, ['Accept' => MARKDOWN] + $headers, $method, $cookieJar);
}

function browser(string $path, ?string $cookieJar = null): Response
{
    return request($path, ['Accept' => BROWSER], cookieJar: $cookieJar);
}

/** Returns a cookie jar signed in as the fixture admin created by tests/fixtures.php. */
function signIn(): string
{
    $password = getenv('ACCEPT_MARKDOWN_TEST_PASSWORD') ?: throw new RuntimeException('Set ACCEPT_MARKDOWN_TEST_PASSWORD to the value used for tests/fixtures.php');
    $jar = tempnam(sys_get_temp_dir(), 'am-cookies-');
    // wp-login.php refuses to sign in unless its test cookie is present (Netscape cookie-jar format).
    file_put_contents($jar, parse_url(baseUrl(), PHP_URL_HOST)."\tFALSE\t/\tFALSE\t0\twordpress_test_cookie\tWP%20Cookie%20check\n");
    request('/wp-login.php', [], 'POST', $jar, [
        'log' => 'am-test-admin',
        'pwd' => $password,
        'testcookie' => '1',
    ]);
    expect(file_get_contents($jar))->toContain('wordpress_logged_in_');

    return $jar;
}

/** Returns a cookie jar holding the password cookie for protected posts. */
function enterPostPassword(string $password): string
{
    $jar = tempnam(sys_get_temp_dir(), 'am-cookies-');
    request('/wp-login.php?action=postpass', [], 'POST', $jar, ['post_password' => $password]);
    expect(file_get_contents($jar))->toContain('wp-postpass_');

    return $jar;
}
