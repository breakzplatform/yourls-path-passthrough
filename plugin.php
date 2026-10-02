<?php
/*
Plugin Name: Path Passthrough
Plugin URI: https://github.com/breakzplatform/yourls-path-passthrough
Description: golink-style redirects: <code>sho.rt/foo/bar?x=1</code> goes to the long URL of <code>foo</code> with <code>/bar</code> appended and the query string merged. Long URLs can also use golink-style <code>{{.Path}}</code> destination templates.
Version: 1.1.0
Requires YOURLS: 1.10.5
Requires PHP: 8.1
Author: Joselito
Author URI: https://github.com/breakzplatform
*/

if ( !defined( 'YOURLS_ABSPATH' ) ) die();

require_once __DIR__ . '/template.php';

yourls_add_action( 'plugins_loaded', 'path_passthrough_load_textdomain' );

function path_passthrough_load_textdomain() {
    yourls_load_custom_textdomain( 'path-passthrough', __DIR__ . '/languages' );
}

yourls_add_filter( 'get_request', 'path_passthrough_get_request' );
yourls_add_action( 'redirect_shorturl', 'path_passthrough_arm' );
yourls_add_filter( 'redirect_location', 'path_passthrough_redirect_location' );

// Restore well-formed `{{ ... }}` template actions after YOURLS strips '{' and '}' on save.
// See the "Sanitizer" section of README.md for why this is safe.
yourls_add_filter( 'sanitize_url', 'path_passthrough_restore_templates', 10, 2 );

// Same cap as yourls_sanitize_keyword() puts on custom keywords
const PATH_PASSTHROUGH_MAX_KEYWORD_LENGTH = 199;

/**
 * Pending passthrough for this request: ['keyword', 'path', 'query', 'armed'], or null.
 */
function path_passthrough_state( ?array $state = null, bool $reset = false ): ?array {
    static $current = null;
    if ( $reset ) {
        $current = null;
    } elseif ( $state !== null ) {
        $current = $state;
    }
    return $current;
}

/**
 * Turn "foo/bar/baz" into "foo" when "foo" is a short URL, remembering "bar/baz" for later.
 *
 * The longest prefix that is a keyword wins, so with both "foo" and "foo/bar" saved (keywords
 * that contain slashes), "foo/bar/baz" goes to "foo/bar" with "baz" passed through.
 *
 * Requests the loader already understands (an existing keyword or page, including keywords
 * that contain slashes, "prefix-n-shorten" URLs, plain keywords) are returned untouched.
 */
function path_passthrough_get_request( $request ) {
    $request = (string) $request;

    if ( preg_match( '@^[a-zA-Z]+://@', $request ) ) {
        return $request;
    }

    $separator = strpos( $request, '/' );
    if ( $separator === false || $separator === 0 ) {
        return $request;
    }

    if ( substr( $request, $separator + 1 ) === '' ) {
        return $request;
    }

    if ( yourls_keyword_is_taken( $request ) || yourls_is_page( $request ) ) {
        return $request;
    }

    $segments = explode( '/', $request );
    for ( $count = count( $segments ) - 1; $count >= 1; $count-- ) {
        $keyword = implode( '/', array_slice( $segments, 0, $count ) );
        // Keywords are capped at 199 characters: longer prefixes would only cost a query each
        if ( strlen( $keyword ) > PATH_PASSTHROUGH_MAX_KEYWORD_LENGTH || !yourls_keyword_is_taken( $keyword ) ) {
            continue;
        }

        $path = implode( '/', array_slice( $segments, $count ) );
        // "foo/bar/" with "foo/bar" saved: a plain request with a trailing slash, the loader's job
        if ( $path === '' ) {
            return $request;
        }

        path_passthrough_state( [
            'keyword' => yourls_sanitize_keyword( $keyword ),
            'path'    => $path,
            'query'   => isset( $_SERVER['QUERY_STRING'] ) ? (string) $_SERVER['QUERY_STRING'] : '',
            'armed'   => false,
        ] );

        return $keyword;
    }

    return $request;
}

/**
 * Only the redirect that yourls-go.php issues for our keyword gets rewritten.
 * YOURLS passes action arguments as a single array: [ $url, $keyword ].
 *
 * A plain keyword request (no extra path segment) never touches path_passthrough_get_request(),
 * so it never sets up a state above. But its long URL can still contain a template (rule 3 of
 * the spec: "sho.rt/barsa" alone must also be evaluated), which this action is the only hook
 * that sees before the Location header is built. So: no state yet + a template in $url is armed
 * here too, with an empty path.
 */
function path_passthrough_arm( $args ) {
    $url = (string) $args[0];
    $keyword = yourls_sanitize_keyword( (string) $args[1] );

    $state = path_passthrough_state();
    if ( $state !== null && $state['keyword'] === $keyword ) {
        $state['armed'] = true;
        path_passthrough_state( $state );
        return;
    }

    if ( $state === null && path_passthrough_has_template( $url ) ) {
        path_passthrough_state( [
            'keyword' => $keyword,
            'path'    => '',
            'query'   => isset( $_SERVER['QUERY_STRING'] ) ? (string) $_SERVER['QUERY_STRING'] : '',
            'armed'   => true,
        ] );
    }
}

function path_passthrough_redirect_location( $location, $code = 301 ) {
    $state = path_passthrough_state();
    if ( $state === null || !$state['armed'] ) {
        return $location;
    }

    path_passthrough_state( null, true );

    $expanded = path_passthrough_expand( (string) $location, $state['path'], $state['query'] );
    return yourls_apply_filter( 'path_passthrough_location', $expanded, $location, $state['path'], $state['query'] );
}

/**
 * True when $url contains at least one `{{ ... }}` template action.
 *
 * Non-template URLs (the common case) never leave the fast path below, which keeps v1.0
 * behavior byte-for-byte unchanged.
 */
function path_passthrough_has_template( string $url ): bool {
    return path_passthrough_template_has_actions( $url );
}

/**
 * Append $path to the path of $url and merge $query into its query string, OR, if $url
 * contains a `{{ ... }}` destination template, render the template with $path instead.
 *
 * Same rules as golink: the extra path is joined with a single slash (or inserted wherever
 * the template puts it), the fragment of the long URL is kept. Query parameters from the
 * request replace parameters with the same name in the long URL; the others are kept in
 * their original order.
 */
function path_passthrough_expand( string $url, string $path, string $query ): string {
    $path = path_passthrough_strip_control_chars( $path );
    $query = path_passthrough_strip_control_chars( $query );

    if ( !path_passthrough_has_template( $url ) ) {
        return path_passthrough_expand_plain( $url, $path, $query );
    }

    try {
        $ast = path_passthrough_template_parse( $url );
        $rendered = path_passthrough_template_render( $ast, [ 'Path' => $path ] );
    } catch ( Path_Passthrough_Template_Error $error ) {
        yourls_do_action( 'path_passthrough_invalid_template', [ $url, $error, $path, $query ] );

        $fallback_url = yourls_apply_filter(
            'path_passthrough_invalid_template_url',
            path_passthrough_strip_template_actions( $url ),
            $url,
            $error
        );

        // The template is gone: fall back to plain append behavior, same as a non-template URL.
        return path_passthrough_expand_plain( $fallback_url, $path, $query );
    }

    // The template already consumed $path (or chose not to use it), so it must not be
    // appended again -- only the query merge still applies.
    return path_passthrough_expand_plain( path_passthrough_strip_control_chars( $rendered ), '', $query );
}

/**
 * The pre-template v1.0 behavior: append $path (if any) and merge $query. Used both for
 * plain (non-template) URLs and, with an empty $path, for the result of rendering a template
 * or for a template that failed to parse (with its actions already stripped by the caller).
 */
function path_passthrough_expand_plain( string $url, string $path, string $query ): string {
    $fragment = '';
    $hash = strpos( $url, '#' );
    if ( $hash !== false ) {
        $fragment = substr( $url, $hash );
        $url = substr( $url, 0, $hash );
    }

    $long_query = '';
    $question = strpos( $url, '?' );
    if ( $question !== false ) {
        $long_query = substr( $url, $question + 1 );
        $url = substr( $url, 0, $question );
    }

    if ( $path !== '' ) {
        $url = rtrim( $url, '/' ) . '/' . ltrim( $path, '/' );
    }

    $merged = path_passthrough_merge_query( $long_query, $query );

    return $url . ( $merged === '' ? '' : '?' . $merged ) . $fragment;
}

function path_passthrough_merge_query( string $long_query, string $request_query ): string {
    $request_pairs = path_passthrough_query_pairs( $request_query );
    $request_names = array_flip( array_column( $request_pairs, 0 ) );

    $kept = array_filter(
        path_passthrough_query_pairs( $long_query ),
        function ( $pair ) use ( $request_names ) {
            return !isset( $request_names[ $pair[0] ] );
        }
    );

    return implode( '&', array_column( array_merge( $kept, $request_pairs ), 1 ) );
}

/**
 * Split a query string into [decoded name, raw "name=value"] pairs. parse_str() is avoided on
 * purpose: it mangles names ("a.b" becomes "a_b") and collapses repeated keys.
 */
function path_passthrough_query_pairs( string $query ): array {
    $pairs = [];
    foreach ( explode( '&', $query ) as $part ) {
        if ( $part === '' ) {
            continue;
        }
        $name = urldecode( explode( '=', $part, 2 )[0] );
        $pairs[] = [ $name, $part ];
    }
    return $pairs;
}

/**
 * Remove every control character (CR, LF, and friends) so neither the request path/query nor
 * a rendered template can inject headers into the Location response.
 */
function path_passthrough_strip_control_chars( string $value ): string {
    return preg_replace( '/[\x00-\x1F\x7F]/', '', $value );
}

/**
 * Drop every `{{ ... }}` occurrence from $url, including an unterminated `{{` with no closing
 * `}}`. Used for the invalid-template fallback (rule 5): the long URL is redirected to as-is,
 * minus the broken template, rather than crashing or leaking literal braces into the Location
 * header.
 */
function path_passthrough_strip_template_actions( string $url ): string {
    $url = preg_replace( '/\{\{.*?\}\}/s', '', $url );
    return preg_replace( '/\{\{.*$/s', '', $url );
}

/**
 * The character shape of a single `{{ ... }}` action, generic enough to match the tokenizer's
 * `{{ name arg arg… }}` form (identifiers and `.Field` references) without hardcoding the v1.1
 * vocabulary (.Path / if / else / end). v1.2 adding functions to the registry needs no change
 * here -- only unsupported *content* inside a well-formed action shape, which is rejected later
 * by path_passthrough_template_parse(), not by this regexp.
 */
const PATH_PASSTHROUGH_ACTION_TOKEN = '(?:\.[A-Za-z_][A-Za-z0-9_]*|[A-Za-z_][A-Za-z0-9_]*)';
const PATH_PASSTHROUGH_ACTION_PATTERN = '/\{\{\s*(' . PATH_PASSTHROUGH_ACTION_TOKEN . '(?:\s+' . PATH_PASSTHROUGH_ACTION_TOKEN . ')*)\s*\}\}/';

/**
 * Restore template actions that yourls_sanitize_url() stripped when the link was saved.
 *
 * yourls_esc_url() removes '{', '}' and whitespace from every URL (see README.md for why),
 * which destroys `{{if .Path}}` on save. This filter reinstates ONLY well-formed action
 * tokens matching PATH_PASSTHROUGH_ACTION_PATTERN -- never a bare '{' or '}' -- by re-running
 * the exact same sanitization YOURLS just did, but on a copy of $original where each action
 * has been swapped for a placeholder made of characters the sanitizer already allows. That
 * placeholder rides along through every other transformation (protocol lowercasing, dedup of
 * "http://http://", etc.) and comes out in the right place, so there is no need to diff
 * $sanitized against $original to find where each action belongs.
 */
function path_passthrough_restore_templates( string $sanitized, string $original ): string {
    if ( !path_passthrough_template_has_actions( $original ) ) {
        return $sanitized;
    }

    $actions = [];
    $placeholder_source = preg_replace_callback(
        PATH_PASSTHROUGH_ACTION_PATTERN,
        function ( $match ) use ( &$actions ) {
            $actions[] = $match[1];
            return '~pptpl' . ( count( $actions ) - 1 ) . '~';
        },
        $original
    );

    if ( $actions === [] ) {
        return $sanitized;
    }

    // Same sanitization yourls_sanitize_url() itself performs, minus the recursion into this
    // filter that calling yourls_sanitize_url() again would cause.
    $restored = yourls_esc_url( $placeholder_source, 'redirection' );

    foreach ( $actions as $i => $action ) {
        // Whitespace inside "{{ }}" was stripped by esc_url() too (it's not in the allowed
        // character set), so tokens are rejoined with a single space rather than reusing
        // whatever spacing the user originally typed.
        $normalized = '{{' . implode( ' ', preg_split( '/\s+/', trim( $action ) ) ) . '}}';
        $restored = str_replace( '~pptpl' . $i . '~', $normalized, $restored );
    }

    return $restored;
}
