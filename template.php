<?php

if ( !defined( 'YOURLS_ABSPATH' ) ) die();

class Path_Passthrough_Template_Error extends RuntimeException {}

function path_passthrough_template_has_actions( string $s ): bool {
    return strpos( $s, '{{' ) !== false;
}

/**
 * Parse a template into an AST (Abstract Syntax Tree).
 *
 * Supported nodes:
 * - text:  ['type' => 'text', 'value' => string]
 * - field: ['type' => 'field', 'field' => string]
 * - if:    ['type' => 'if', 'condition' => string, 'then' => array, 'else' => array]
 */
function path_passthrough_template_parse( string $template ): array {
    $offset = 0;
    $length = strlen( $template );
    
    $stack = [ [] ];
    $block_types = [ 'root' ];
    
    while ( $offset < $length ) {
        $start = strpos( $template, '{{', $offset );
        if ( $start === false ) {
            $text = substr( $template, $offset );
            if ( $text !== '' ) {
                $stack[ count( $stack ) - 1 ][] = [ 'type' => 'text', 'value' => $text ];
            }
            break;
        }
        
        if ( $start > $offset ) {
            $stack[ count( $stack ) - 1 ][] = [ 'type' => 'text', 'value' => substr( $template, $offset, $start - $offset ) ];
        }
        
        $end = strpos( $template, '}}', $start + 2 );
        if ( $end === false ) {
            throw new Path_Passthrough_Template_Error( 'Unterminated action' );
        }
        
        $action = substr( $template, $start + 2, $end - $start - 2 );
        $offset = $end + 2;
        
        $tokens = path_passthrough_template_tokenize( $action );
        if ( empty( $tokens ) ) {
            throw new Path_Passthrough_Template_Error( 'Empty action' );
        }
        
        $cmd = $tokens[0];
        
        if ( $cmd === 'if' ) {
            if ( count( $tokens ) !== 2 || $tokens[1] !== '.Path' ) {
                throw new Path_Passthrough_Template_Error( 'Unsupported action' );
            }
            $stack[] = [];
            $block_types[] = 'if-then';
        } elseif ( $cmd === 'else' ) {
            if ( count( $tokens ) !== 1 ) {
                throw new Path_Passthrough_Template_Error( 'Unsupported action' );
            }
            $type = end( $block_types );
            if ( $type !== 'if-then' ) {
                throw new Path_Passthrough_Template_Error( 'Stray else' );
            }
            
            $then_nodes = array_pop( $stack );
            $stack[] = $then_nodes; 
            $block_types[ count( $block_types ) - 1 ] = 'if-else';
            $stack[] = [];
            $block_types[] = 'else-body';
        } elseif ( $cmd === 'end' ) {
            if ( count( $tokens ) !== 1 ) {
                throw new Path_Passthrough_Template_Error( 'Unsupported action' );
            }
            $type = end( $block_types );
            if ( $type === 'root' ) {
                throw new Path_Passthrough_Template_Error( 'Stray end' );
            }
            
            if ( $type === 'if-then' ) {
                $then_nodes = array_pop( $stack );
                array_pop( $block_types );
                
                $node = [
                    'type' => 'if',
                    'condition' => 'Path',
                    'then' => $then_nodes,
                    'else' => []
                ];
                $stack[ count( $stack ) - 1 ][] = $node;
            } elseif ( $type === 'else-body' ) {
                $else_nodes = array_pop( $stack );
                array_pop( $block_types );
                
                $then_nodes = array_pop( $stack );
                array_pop( $block_types );
                
                $node = [
                    'type' => 'if',
                    'condition' => 'Path',
                    'then' => $then_nodes,
                    'else' => $else_nodes
                ];
                $stack[ count( $stack ) - 1 ][] = $node;
            } else {
                throw new Path_Passthrough_Template_Error( 'Invalid state for end' );
            }
        } elseif ( $cmd === '.Path' ) {
            if ( count( $tokens ) !== 1 ) {
                throw new Path_Passthrough_Template_Error( 'Unsupported action' );
            }
            $stack[ count( $stack ) - 1 ][] = [ 'type' => 'field', 'field' => 'Path' ];
        } else {
            throw new Path_Passthrough_Template_Error( 'Unsupported action' );
        }
    }
    
    if ( count( $block_types ) !== 1 || $block_types[0] !== 'root' ) {
        throw new Path_Passthrough_Template_Error( 'Unbalanced if/else/end' );
    }
    
    return $stack[0];
}

function path_passthrough_template_tokenize( string $action ): array {
    $tokens = [];
    $length = strlen( $action );
    $i = 0;
    while ( $i < $length ) {
        while ( $i < $length && ctype_space( $action[$i] ) ) {
            $i++;
        }
        if ( $i >= $length ) {
            break;
        }
        
        if ( $action[$i] === '"' ) {
            $str = '"';
            $i++;
            while ( $i < $length ) {
                $c = $action[$i];
                $str .= $c;
                $i++;
                if ( $c === '\\' && $i < $length ) {
                    $str .= $action[$i];
                    $i++;
                } elseif ( $c === '"' ) {
                    break;
                }
            }
            $tokens[] = $str;
        } else {
            $start = $i;
            while ( $i < $length && !ctype_space( $action[$i] ) && $action[$i] !== '"' ) {
                $i++;
            }
            $tokens[] = substr( $action, $start, $i - $start );
        }
    }
    return $tokens;
}

function path_passthrough_template_render( array $ast, array $data ): string {
    $result = '';
    foreach ( $ast as $node ) {
        if ( $node['type'] === 'text' ) {
            $result .= $node['value'];
        } elseif ( $node['type'] === 'field' ) {
            $field = $node['field'];
            if ( isset( $data[ $field ] ) ) {
                $result .= $data[ $field ];
            }
        } elseif ( $node['type'] === 'if' ) {
            $field = $node['condition'];
            $is_truthy = isset( $data[ $field ] ) && (string) $data[ $field ] !== '';
            if ( $is_truthy ) {
                $result .= path_passthrough_template_render( $node['then'], $data );
            } else {
                $result .= path_passthrough_template_render( $node['else'], $data );
            }
        }
    }
    return $result;
}

function path_passthrough_template_functions(): array {
    return [];
}
