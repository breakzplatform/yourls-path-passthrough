<?php
/*
Plugin Name: Path Passthrough
Plugin URI: https://github.com/breakzplatform/yourls-path-passthrough
Description: golink-style redirects: <code>sho.rt/foo/bar?x=1</code> goes to the long URL of <code>foo</code> with <code>/bar</code> appended and the query string merged.
Version: 1.0.0
Author: Joselito
Author URI: https://github.com/breakzplatform
*/

if ( !defined( 'YOURLS_ABSPATH' ) ) die();

yourls_add_action( 'plugins_loaded', 'path_passthrough_load_textdomain' );

function path_passthrough_load_textdomain() {
    yourls_load_custom_textdomain( 'path-passthrough', __DIR__ . '/languages' );
}

yourls_add_filter( 'get_request', 'path_passthrough_get_request' );
yourls_add_action( 'redirect_shorturl', 'path_passthrough_arm' );
yourls_add_filter( 'redirect_location', 'path_passthrough_redirect_location' );

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

    $keyword = substr( $request, 0, $separator );
    $path = substr( $request, $separator + 1 );
    if ( $path === '' ) {
        return $request;
    }

    if ( yourls_keyword_is_taken( $request ) || yourls_is_page( $request ) ) {
        return $request;
    }

    if ( !yourls_keyword_is_taken( $keyword ) ) {
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

/**
 * Only the redirect that yourls-go.php issues for our keyword gets rewritten.
 * YOURLS passes action arguments as a single array: [ $url, $keyword ].
 */
function path_passthrough_arm( $args ) {
    $keyword = $args[1];
    $state = path_passthrough_state();
    if ( $state !== null && $state['keyword'] === yourls_sanitize_keyword( $keyword ) ) {
        $state['armed'] = true;
        path_passthrough_state( $state );
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
 * Append $path to the path of $url and merge $query into its query string.
 *
 * Same rules as golink: the extra path is joined with a single slash, the fragment of the long
 * URL is kept. Query parameters from the request replace parameters with the same name in the
 * long URL; the others are kept in their original order.
 */
function path_passthrough_expand( string $url, string $path, string $query ): string {
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
