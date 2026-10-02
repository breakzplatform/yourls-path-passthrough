<?php
/**
 * Test runner for yourls-path-passthrough
 */

if ( !defined( 'YOURLS_ABSPATH' ) ) {
    define( 'YOURLS_ABSPATH', __DIR__ );
}

if ( !function_exists( 'yourls_add_action' ) ) {
    function yourls_add_action() {}
    function yourls_add_filter() {}
    function yourls_apply_filter( $hook, $value, ...$args ) { return $value; }
    function yourls_do_action( $hook, ...$args ) {}
    function yourls_load_custom_textdomain() {}
    function yourls__( $text, $domain = 'default' ) { return $text; }
    function yourls_keyword_is_taken( $keyword ) { return in_array( $keyword, $GLOBALS['test_keywords'] ?? [], true ); }
    function yourls_is_page() { return false; }
    function yourls_sanitize_keyword( $k ) { return $k; }
}

if ( !function_exists( 'yourls_esc_url' ) ) {
    function yourls_esc_url( $url, $context = 'display', $protocols = [] ) {
        $url = trim( $url );
        $url = preg_replace( '|[^a-z0-9-~+_.?#=!&;,/:%@$\|*\'()\[\]\\\\\x80-\\xff]|i', '', $url );
        return $url;
    }
}

require_once dirname( __DIR__ ) . '/plugin.php';

$tests_passed = 0;
$tests_failed = 0;
$current_group = '';

function run_test( $name, $actual, $expected ) {
    global $tests_passed, $tests_failed, $current_group;
    if ( $actual === $expected ) {
        echo "PASS - [$current_group] $name\n";
        $tests_passed++;
    } else {
        echo "FAIL - [$current_group] $name\n";
        echo "  Expected: " . var_export( $expected, true ) . "\n";
        echo "  Actual:   " . var_export( $actual, true ) . "\n";
        $tests_failed++;
    }
}

function test_group( $name ) {
    global $current_group;
    $current_group = $name;
}

function safe_expand( $url, $path, $query ) {
    if ( !function_exists( 'path_passthrough_expand' ) ) {
        return 'MISSING_FUNCTION_path_passthrough_expand';
    }
    return path_passthrough_expand( $url, $path, $query );
}

function safe_expand_no_throw( $url, $path, $query ) {
    if ( !function_exists( 'path_passthrough_expand' ) ) {
        return 'MISSING_FUNCTION_path_passthrough_expand';
    }
    try {
        $result = path_passthrough_expand( $url, $path, $query );
        if ( strpos( $result, '{{' ) !== false || strpos( $result, '}}' ) !== false ) {
            return "FAIL_CONTAINS_BRACES: $result";
        }
        return $result;
    } catch ( Throwable $e ) {
        return 'THREW_ERROR: ' . $e->getMessage();
    }
}

function safe_expand_sec( $url, $path, $query ) {
    if ( !function_exists( 'path_passthrough_expand' ) ) {
        return 'MISSING_FUNCTION_path_passthrough_expand';
    }
    try {
        $result = path_passthrough_expand( $url, $path, $query );
        if ( preg_match( '/[\r\n\t\0]/', $result ) ) {
            return "FAIL_CONTAINS_CTRL: $result";
        }
        return $result;
    } catch ( Throwable $e ) {
        return 'THREW_ERROR: ' . $e->getMessage();
    }
}

// -----------------------------------------------------------------------------
// Group: Old behavior (no templates)
// -----------------------------------------------------------------------------
test_group( 'Old Behavior' );

run_test( 'single-slash join: long URL without trailing slash + path',
    safe_expand( 'https://example.com/foo', 'bar', '' ),
    'https://example.com/foo/bar'
);
run_test( 'single-slash join: long URL with trailing slash + path',
    safe_expand( 'https://example.com/foo/', 'bar', '' ),
    'https://example.com/foo/bar'
);
run_test( 'single-slash join: path with leading slash (long URL without trailing slash)',
    safe_expand( 'https://example.com/foo', '/bar', '' ),
    'https://example.com/foo/bar'
);
run_test( 'single-slash join: path with leading slash (long URL with trailing slash)',
    safe_expand( 'https://example.com/foo/', '/bar', '' ),
    'https://example.com/foo/bar'
);
run_test( 'long URL with existing query + request query: same-name param replaced, original order kept, new params appended',
    safe_expand( 'https://example.com/foo?a=1&b=2&c=3', 'bar', 'b=99&d=4' ),
    'https://example.com/foo/bar?a=1&c=3&b=99&d=4'
);
run_test( 'long URL with fragment: fragment kept after query merge',
    safe_expand( 'https://example.com/foo?a=1#frag', 'bar', 'b=2' ),
    'https://example.com/foo/bar?a=1&b=2#frag'
);
run_test( 'Fragment is kept',
    safe_expand( 'https://example.com/foo#frag', 'bar', '' ),
    'https://example.com/foo/bar#frag'
);
run_test( 'empty request query',
    safe_expand( 'https://example.com/foo?a=1', 'bar', '' ),
    'https://example.com/foo/bar?a=1'
);
run_test( 'path with percent-encoding passed through untouched',
    safe_expand( 'https://example.com/foo', 'a%20b/c', '' ),
    'https://example.com/foo/a%20b/c'
);
run_test( 'path with unicode',
    safe_expand( 'https://example.com/foo', 'a/é/c', '' ),
    'https://example.com/foo/a/é/c'
);
run_test( 'empty path and query',
    safe_expand( 'https://example.com/foo', '', '' ),
    'https://example.com/foo'
);
run_test( 'root path only',
    safe_expand( 'https://example.com/', 'bar', '' ),
    'https://example.com/bar'
);

// -----------------------------------------------------------------------------
// Group: Templates
// -----------------------------------------------------------------------------
test_group( 'Templates' );

run_test( '{{.Path}} exact injection',
    safe_expand( 'https://example.com/dir/{{.Path}}/file', 'x', '' ),
    'https://example.com/dir/x/file'
);
run_test( '{{ .Path }} with spaces',
    safe_expand( 'https://example.com/dir/{{ .Path }}/file', 'x', '' ),
    'https://example.com/dir/x/file'
);
run_test( '{{if .Path}} empty path',
    safe_expand( 'https://example.com/{{if .Path}}sub/{{.Path}}{{end}}', '', '' ),
    'https://example.com/'
);
run_test( '{{if .Path}} non-empty path',
    safe_expand( 'https://example.com/{{if .Path}}sub/{{.Path}}{{end}}', 'test', '' ),
    'https://example.com/sub/test'
);
run_test( '{{else}} branch with empty path',
    safe_expand( 'https://example.com/{{if .Path}}yes{{else}}no{{end}}', '', '' ),
    'https://example.com/no'
);
run_test( '{{else}} branch with non-empty path',
    safe_expand( 'https://example.com/{{if .Path}}yes{{else}}no{{end}}', 'test', '' ),
    'https://example.com/yes'
);
run_test( 'nested if: inner and outer with empty path',
    safe_expand( 'https://example.com/{{if .Path}}A{{if .Path}}B{{end}}C{{end}}', '', '' ),
    'https://example.com/'
);
run_test( 'nested if: inner and outer with non-empty path',
    safe_expand( 'https://example.com/{{if .Path}}A{{if .Path}}B{{end}}C{{end}}', 'test', '' ),
    'https://example.com/ABC'
);
run_test( 'template in host',
    safe_expand( 'http://{{if .Path}}{{.Path}}{{else}}host{{end}}.example/x', 'test', '' ),
    'http://test.example/x'
);
run_test( 'template in host (empty path)',
    safe_expand( 'http://{{if .Path}}{{.Path}}{{else}}host{{end}}.example/x', '', '' ),
    'http://host.example/x'
);
run_test( 'template in path',
    safe_expand( 'https://example.com/dir/{{.Path}}/file', 'test', '' ),
    'https://example.com/dir/test/file'
);
run_test( 'template in query',
    safe_expand( 'https://example.com/?q={{.Path}}', 'test', '' ),
    'https://example.com/?q=test'
);
run_test( 'multiple {{.Path}} occurrences',
    safe_expand( 'https://example.com/{{.Path}}/file?q={{.Path}}', 'test', '' ),
    'https://example.com/test/file?q=test'
);
run_test( 'template link + request query merge',
    safe_expand( 'https://example.com/{{.Path}}?a=1', 'test', 'b=2' ),
    'https://example.com/test?a=1&b=2'
);
run_test( 'template present => path NOT appended at end',
    safe_expand( 'https://example.com/{{if .Path}}has_path{{end}}', 'test', '' ),
    'https://example.com/has_path'
);
run_test( 'the spec\'s barsa example EXACTLY as in the spec, with empty path',
    safe_expand( 'https://barsa.example/{{if .Path}}?link=/{{.Path}}{{end}}', '', '' ),
    'https://barsa.example/'
);
run_test( 'the spec\'s barsa example EXACTLY as in the spec, with path \'50-ref/winston-bot-avisos\'',
    safe_expand( 'https://barsa.example/{{if .Path}}?link=/{{.Path}}{{end}}', '50-ref/winston-bot-avisos', '' ),
    'https://barsa.example/?link=/50-ref/winston-bot-avisos'
);
run_test( 'template with complex URL and fragments',
    safe_expand( 'https://example.com/{{.Path}}#hash', 'x', '' ),
    'https://example.com/x#hash'
);
run_test( 'template with complex query and request query replacing existing',
    safe_expand( 'https://example.com/{{.Path}}?a=1&b=2', 'test', 'b=99' ),
    'https://example.com/test?a=1&b=99'
);

// -----------------------------------------------------------------------------
// Group: Invalid Templates (fallback to old behavior)
// -----------------------------------------------------------------------------
test_group( 'Invalid Templates' );

run_test( 'unterminated \'{{\'',
    safe_expand_no_throw( 'https://example.com/{{', 'x', '' ),
    'https://example.com/x'
);
run_test( 'missing {{end}}',
    safe_expand_no_throw( 'https://example.com/{{if .Path}}', 'x', '' ),
    'https://example.com/x'
);
run_test( 'stray {{end}}',
    safe_expand_no_throw( 'https://example.com/{{end}}', 'x', '' ),
    'https://example.com/x'
);
run_test( 'stray {{else}}',
    safe_expand_no_throw( 'https://example.com/{{else}}', 'x', '' ),
    'https://example.com/x'
);
run_test( 'empty action \'{{}}\'',
    safe_expand_no_throw( 'https://example.com/{{}}', 'x', '' ),
    'https://example.com/x'
);
run_test( 'unknown action {{ToLower .Path}}',
    safe_expand_no_throw( 'https://example.com/{{ToLower .Path}}', 'x', '' ),
    'https://example.com/x'
);
run_test( 'unknown field {{.Foo}}',
    safe_expand_no_throw( 'https://example.com/{{.Foo}}', 'x', '' ),
    'https://example.com/x'
);

// -----------------------------------------------------------------------------
// Group: Security
// -----------------------------------------------------------------------------
test_group( 'Security' );

run_test( 'CR in the path',
    safe_expand_sec( 'https://example.com/{{.Path}}', "a\rb", '' ),
    'https://example.com/ab'
);
run_test( 'LF in the path',
    safe_expand_sec( 'https://example.com/{{.Path}}', "a\nb", '' ),
    'https://example.com/ab'
);
run_test( 'CRLF in the path',
    safe_expand_sec( 'https://example.com/{{.Path}}', "a\r\nb", '' ),
    'https://example.com/ab'
);
run_test( 'TAB in the path',
    safe_expand_sec( 'https://example.com/{{.Path}}', "a\tb", '' ),
    'https://example.com/ab'
);
run_test( 'NUL in the path',
    safe_expand_sec( 'https://example.com/{{.Path}}', "a\0b", '' ),
    'https://example.com/ab'
);

// -----------------------------------------------------------------------------
// Group: has_template
// -----------------------------------------------------------------------------
test_group( 'has_template' );

function safe_has_template( $url ) {
    if ( !function_exists( 'path_passthrough_has_template' ) ) {
        return 'MISSING_FUNCTION_path_passthrough_has_template';
    }
    return path_passthrough_has_template( $url );
}

run_test( 'plain URL (false)', safe_has_template( 'https://example.com/' ), false );
run_test( 'single \'{\' alone (false)', safe_has_template( 'https://example.com/{' ), false );
run_test( '\'{{\' present (true)', safe_has_template( 'https://example.com/{{' ), true );


// -----------------------------------------------------------------------------
// Group: get_request (which prefix is the keyword)
// -----------------------------------------------------------------------------
test_group( 'Get Request' );

function request_result( array $keywords, string $request ): array {
    $GLOBALS['test_keywords'] = $keywords;
    path_passthrough_state( null, true );
    $returned = path_passthrough_get_request( $request );
    $state = path_passthrough_state();
    path_passthrough_state( null, true );
    $GLOBALS['test_keywords'] = [];
    return [ $returned, $state['path'] ?? null ];
}

run_test( 'first segment is the keyword',
    request_result( [ 'gh' ], 'gh/tailscale/golink' ), [ 'gh', 'tailscale/golink' ] );
run_test( 'longest keyword wins over a shorter one',
    request_result( [ 'collie', 'collie/bulma' ], 'collie/bulma/foo' ), [ 'collie/bulma', 'foo' ] );
run_test( 'longest keyword wins regardless of save order',
    request_result( [ 'collie/bulma', 'collie' ], 'collie/bulma/foo/bar' ), [ 'collie/bulma', 'foo/bar' ] );
run_test( 'shorter keyword still used when the longer one does not match',
    request_result( [ 'collie', 'collie/bulma' ], 'collie/other/foo' ), [ 'collie', 'other/foo' ] );
run_test( 'deep slash keyword',
    request_result( [ 'a', 'a/b/c' ], 'a/b/c/d' ), [ 'a/b/c', 'd' ] );
run_test( 'exact slash keyword is left to the loader',
    request_result( [ 'collie', 'collie/bulma' ], 'collie/bulma' ), [ 'collie/bulma', null ] );
run_test( 'slash keyword with trailing slash is left to the loader',
    request_result( [ 'collie', 'collie/bulma' ], 'collie/bulma/' ), [ 'collie/bulma/', null ] );
run_test( 'plain keyword with trailing slash is left to the loader',
    request_result( [ 'gh' ], 'gh/' ), [ 'gh/', null ] );
run_test( 'unknown keyword is untouched',
    request_result( [ 'gh' ], 'nope/foo' ), [ 'nope/foo', null ] );
run_test( 'prefix-n-shorten URL is untouched',
    request_result( [ 'https:' ], 'https://example.com/x' ), [ 'https://example.com/x', null ] );
run_test( 'prefixes longer than a keyword can be are skipped',
    request_result( [ str_repeat( 'a', 200 ) ], str_repeat( 'a', 200 ) . '/x' ), [ str_repeat( 'a', 200 ) . '/x', null ] );

// -----------------------------------------------------------------------------
// Group: Option A (restore_templates)
// -----------------------------------------------------------------------------
test_group( 'Restore Templates' );

if ( !function_exists( 'path_passthrough_restore_templates' ) ) {
    run_test( 'Function path_passthrough_restore_templates exists', false, true );
} else {
    run_test( 'Restore valid template',
        path_passthrough_restore_templates( 'https://example.com/if.Pathxend', 'https://example.com/{{if .Path}}x{{end}}' ),
        'https://example.com/{{if .Path}}x{{end}}'
    );
    run_test( 'Restore template with spaces',
        path_passthrough_restore_templates( 'https://example.com/if.Pathxend', 'https://example.com/{{ if .Path }}x{{ end }}' ),
        'https://example.com/{{if .Path}}x{{end}}'
    );
    run_test( 'restores structurally valid action even if template is invalid (unknown action)',
        path_passthrough_restore_templates( 'https://example.com/ToLower.Path', 'https://example.com/{{ToLower .Path}}' ),
        'https://example.com/{{ToLower .Path}}'
    );
    run_test( 'restores structurally valid action even if template is invalid (missing end)',
        path_passthrough_restore_templates( 'https://example.com/if.Path', 'https://example.com/{{if .Path}}' ),
        'https://example.com/{{if .Path}}'
    );
    run_test( 'restores structurally valid action even if template is invalid (stray end)',
        path_passthrough_restore_templates( 'https://example.com/end', 'https://example.com/{{end}}' ),
        'https://example.com/{{end}}'
    );
    run_test( 'expansion of structurally restored invalid template strips braces (unknown action)',
        safe_expand_no_throw( path_passthrough_restore_templates( 'https://example.com/ToLower.Path', 'https://example.com/{{ToLower .Path}}' ), 'test', '' ),
        'https://example.com/test'
    );
    run_test( 'expansion of structurally restored invalid template strips braces (missing end)',
        safe_expand_no_throw( path_passthrough_restore_templates( 'https://example.com/if.Path', 'https://example.com/{{if .Path}}' ), 'test', '' ),
        'https://example.com/test'
    );
    run_test( 'expansion of structurally restored invalid template strips braces (stray end)',
        safe_expand_no_throw( path_passthrough_restore_templates( 'https://example.com/end', 'https://example.com/{{end}}' ), 'test', '' ),
        'https://example.com/test'
    );
    run_test( 'Do not restore malicious script',
        path_passthrough_restore_templates( 'https://example.com/script', 'https://example.com/{{<script>}}' ),
        'https://example.com/script'
    );
    run_test( 'Do not restore bare brace text',
        path_passthrough_restore_templates( 'https://example.com/weird', 'https://example.com/{weird}' ),
        'https://example.com/weird'
    );
    run_test( 'Do not restore lone opening brace',
        path_passthrough_restore_templates( 'https://example.com/', 'https://example.com/{' ),
        'https://example.com/'
    );
    run_test( 'Do not restore lone closing brace',
        path_passthrough_restore_templates( 'https://example.com/', 'https://example.com/}' ),
        'https://example.com/'
    );
    run_test( 'Restore quoted string argument',
        path_passthrough_restore_templates( 'https://example.com/TrimPrefix/.Path', 'https://example.com/{{TrimPrefix "/" .Path}}' ),
        'https://example.com/{{TrimPrefix "/" .Path}}'
    );
    run_test( 'Restore quoted string argument, spacing normalized outside the string',
        path_passthrough_restore_templates( 'https://example.com/TrimPrefix/.Path', 'https://example.com/{{  TrimPrefix   "a b"  .Path }}' ),
        'https://example.com/{{TrimPrefix "a b" .Path}}'
    );
    run_test( 'Restore several actions with strings',
        path_passthrough_restore_templates( 'x', 'https://example.com/{{if .Path}}{{TrimSuffix "/" .Path}}{{else}}home{{end}}' ),
        'https://example.com/{{if .Path}}{{TrimSuffix "/" .Path}}{{else}}home{{end}}'
    );
    run_test( 'Do not restore string with HTML characters',
        path_passthrough_restore_templates( 'https://example.com/script', 'https://example.com/{{"<script>"}}' ),
        'https://example.com/script'
    );
    run_test( 'Do not restore string with an escaped quote',
        path_passthrough_restore_templates( 'https://example.com/ab', 'https://example.com/{{TrimPrefix "a\"b" .Path}}' ),
        'https://example.com/ab'
    );
    run_test( 'Do not restore string with closing braces',
        path_passthrough_restore_templates( 'https://example.com/x', 'https://example.com/{{x "}}"}}' ),
        'https://example.com/x'
    );
    run_test( 'Do not restore unterminated string',
        path_passthrough_restore_templates( 'https://example.com/x', 'https://example.com/{{TrimPrefix "/ .Path}}' ),
        'https://example.com/x'
    );
    run_test( 'Do not restore string glued to a name',
        path_passthrough_restore_templates( 'https://example.com/x', 'https://example.com/{{TrimPrefix"/" .Path}}' ),
        'https://example.com/x'
    );
    run_test( 'restored string action falls back to append until functions exist',
        safe_expand_no_throw( path_passthrough_restore_templates( 'x', 'https://example.com/{{TrimPrefix "/" .Path}}' ), 'test', '' ),
        'https://example.com/test'
    );
}

test_group( 'Display Templates' );

run_test( 'display context shows the saved template',
    path_passthrough_display_templates( 'https://example.com/if.Pathxend', 'https://example.com/{{if .Path}}x{{end}}', 'display' ),
    'https://example.com/{{if .Path}}x{{end}}'
);
run_test( 'display context escapes quotes in string arguments',
    path_passthrough_display_templates( 'x', 'https://example.com/{{TrimPrefix "/" .Path}}', 'display' ),
    'https://example.com/{{TrimPrefix &quot;/&quot; .Path}}'
);
run_test( 'other contexts are untouched',
    path_passthrough_display_templates( 'https://example.com/if.Path', 'https://example.com/{{if .Path}}', 'safe' ),
    'https://example.com/if.Path'
);
run_test( 'URLs without templates are untouched',
    path_passthrough_display_templates( 'https://example.com/a&#038;b', 'https://example.com/a&b', 'display' ),
    'https://example.com/a&#038;b'
);
run_test( 'bare braces are not shown',
    path_passthrough_display_templates( 'https://example.com/script', 'https://example.com/{{<script>}}', 'display' ),
    'https://example.com/script'
);

echo "\nSummary: $tests_passed passed, $tests_failed failed.\n";
if ( $tests_failed > 0 ) {
    exit( 1 );
}
exit( 0 );
