<?php

define( 'YOURLS_ABSPATH', __DIR__ );

require __DIR__ . '/../template.php';

$passes = 0;
$fails = 0;

function assert_test( $condition, $message ) {
    global $passes, $fails;
    if ( $condition ) {
        $passes++;
    } else {
        $fails++;
        echo "FAIL: $message\n";
    }
}

function test_render( $name, $template, $data, $expected ) {
    try {
        $ast = path_passthrough_template_parse( $template );
        $result = path_passthrough_template_render( $ast, $data );
        assert_test( $result === $expected, "$name - expected '$expected', got '$result'" );
    } catch ( Exception $e ) {
        assert_test( false, "$name - threw exception: " . $e->getMessage() );
    }
}

function test_error( $name, $template, $expected_exception_message = '' ) {
    try {
        path_passthrough_template_parse( $template );
        assert_test( false, "$name - expected Path_Passthrough_Template_Error, but parsing succeeded" );
    } catch ( Path_Passthrough_Template_Error $e ) {
        if ( $expected_exception_message !== '' && strpos( $e->getMessage(), $expected_exception_message ) === false ) {
            assert_test( false, "$name - expected error containing '$expected_exception_message', got '" . $e->getMessage() . "'" );
        } else {
            assert_test( true, $name );
        }
    } catch ( Exception $e ) {
        assert_test( false, "$name - expected Path_Passthrough_Template_Error, got " . get_class($e) . ': ' . $e->getMessage() );
    }
}

// has_actions
assert_test( path_passthrough_template_has_actions( 'no actions' ) === false, 'has_actions false' );
assert_test( path_passthrough_template_has_actions( 'with {{ actions' ) === true, 'has_actions true' );

// Valid cases (existing)
test_render( 'render field', 'hello {{.Path}} world', ['Path' => '123'], 'hello 123 world' );
test_render( 'render if-else true', 'hello {{ if .Path }}path is {{ .Path }}{{ else }}no path{{ end }}!', ['Path' => '123'], 'hello path is 123!' );
test_render( 'render if-else false', 'hello {{ if .Path }}path is {{ .Path }}{{ else }}no path{{ end }}!', ['Path' => ''], 'hello no path!' );

// Valid cases (new / required)
test_render( 'if-nesting 3 levels deep (non-empty)', '{{if .Path}}a{{if .Path}}b{{if .Path}}c{{end}}{{end}}{{end}}', ['Path' => '1'], 'abc' );
test_render( 'if-nesting 3 levels deep (empty)', '{{if .Path}}a{{if .Path}}b{{if .Path}}c{{end}}{{end}}{{end}}', ['Path' => ''], '' );
test_render( 'whitespace variants', '{{  if   .Path  }} {{ .Path }} {{end}}', ['Path' => 'x'], ' x ' );
test_render( 'text-only template', 'just some plain text', ['Path' => '123'], 'just some plain text' );
test_render( 'adjacent actions', '{{.Path}}{{.Path}}', ['Path' => 'x'], 'xx' );
test_render( '}} appearing in plain text', 'this is }} literal', ['Path' => '1'], 'this is }} literal' );

// Truthiness bug fixes
test_render( 'truthy Path "0"', '{{if .Path}}yes{{else}}no{{end}}', ['Path' => '0'], 'yes' );
test_render( 'truthy Path "00"', '{{if .Path}}yes{{else}}no{{end}}', ['Path' => '00'], 'yes' );
test_render( 'truthy Path " "', '{{if .Path}}yes{{else}}no{{end}}', ['Path' => ' '], 'yes' );
test_render( 'falsy Path ""', '{{if .Path}}yes{{else}}no{{end}}', ['Path' => ''], 'no' );

// Error cases
test_error( 'unterminated {{', 'hello {{', 'Unterminated action' );
test_error( 'missing {{end}}', '{{if .Path}} hello', 'Unbalanced if/else/end' );
test_error( 'stray {{end}}', 'hello {{end}}', 'Stray end' );
test_error( 'stray {{else}}', 'hello {{else}}', 'Stray else' );
test_error( 'double {{else}} in one if', '{{if .Path}} a {{else}} b {{else}} c {{end}}', 'Stray else' );
test_error( 'empty action {{}}', 'hello {{}}', 'Empty action' );
test_error( 'empty action {{ }}', 'hello {{ }}', 'Empty action' );
test_error( 'unsupported function {{ToLower .Path}}', 'hello {{ ToLower .Path }}', 'Unsupported action' );
test_error( 'unknown field {{.Foo}}', 'hello {{.Foo}}', 'Unsupported action' );
test_error( '{{if}} with no condition', '{{ if }}', 'Unsupported action' );

echo "Tests: $passes passed, $fails failed.\n";
if ( $fails > 0 ) {
    exit(1);
}
