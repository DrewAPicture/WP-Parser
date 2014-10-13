<?php

namespace WP_Parser;

use phpDocumentor\Reflection\DocBlock\Tag;
use phpDocumentor\Reflection\DocBlock\Tag\ParamTag;

Tag::registerTagHandler( 'type', 'phpDocumentor\Reflection\DocBlock\Tag\ParamTag' );

function get_wp_files( $directory ) {
	$iterableFiles = new \RecursiveIteratorIterator(
		new \RecursiveDirectoryIterator( $directory )
	);
	$files         = array();

	try {
		foreach ( $iterableFiles as $file ) {
			if ( $file->getExtension() !== 'php' ) {
				continue;
			}

			$files[] = $file->getPathname();
		}
	} catch ( \UnexpectedValueException $e ) {
		printf( 'Directory [%s] contained a directory we can not recurse into', $directory );
	}

	return $files;
}

function parse_files( $files, $root ) {
	$output = array();

	foreach ( $files as $filename ) {
		$file = new File_Reflector( $filename );

		$path = ltrim( substr( $filename, strlen( $root ) ), DIRECTORY_SEPARATOR );
		$file->setFilename( $path );

		$file->process();

		// TODO proper exporter
		$out = array(
			'file' => export_docblock( $file ),
			'path' => str_replace( DIRECTORY_SEPARATOR, '/', $file->getFilename() ),
			'root' => $root,
		);

		if ( ! empty( $file->uses ) ) {
			$out['uses'] = export_uses( $file->uses );
		}

		foreach ( $file->getIncludes() as $include ) {
			$out['includes'][] = array(
				'name' => $include->getName(),
				'line' => $include->getLineNumber(),
				'type' => $include->getType(),
			);
		}

		foreach ( $file->getConstants() as $constant ) {
			$out['constants'][] = array(
				'name'  => $constant->getShortName(),
				'line'  => $constant->getLineNumber(),
				'value' => $constant->getValue(),
			);
		}

		if ( ! empty( $file->uses['hooks'] ) ) {
			$out['hooks'] = export_hooks( $file->uses['hooks'] );
		}

		foreach ( $file->getFunctions() as $function ) {
			$func = array(
				'name'      => $function->getShortName(),
				'line'      => $function->getLineNumber(),
				'end_line'  => $function->getNode()->getAttribute( 'endLine' ),
				'arguments' => export_arguments( $function->getArguments() ),
				'doc'       => export_docblock( $function ),
				'hooks'     => array(),
			);

			if ( ! empty( $function->uses ) ) {
				$func['uses'] = export_uses( $function->uses );

				if ( ! empty( $function->uses['hooks'] ) ) {
					$func['hooks'] = export_hooks( $function->uses['hooks'] );
				}
			}

			$out['functions'][] = $func;
		}

		foreach ( $file->getClasses() as $class ) {
			$cl = array(
				'name'       => $class->getShortName(),
				'line'       => $class->getLineNumber(),
				'end_line'   => $class->getNode()->getAttribute( 'endLine' ),
				'final'      => $class->isFinal(),
				'abstract'   => $class->isAbstract(),
				'extends'    => $class->getParentClass(),
				'implements' => $class->getInterfaces(),
				'properties' => export_properties( $class->getProperties() ),
				'methods'    => export_methods( $class->getMethods() ),
				'doc'        => export_docblock( $class ),
			);

			$out['classes'][] = $cl;
		}

		$output[] = $out;
	}

	return $output;
}

function export_docblock( $element ) {
	$docblock = $element->getDocBlock();
	if ( ! $docblock ) {
		return array(
			'description'      => '',
			'long_description' => '',
			'tags'             => array(),
		);
	}

	$output = array(
		'description'      => preg_replace( '/[\n\r]+/', ' ', $docblock->getShortDescription() ),
		'long_description' => preg_replace( '/[\n\r]+/', ' ', $docblock->getLongDescription()->getFormattedContents() ),
		'tags'             => array(),
	);

	foreach ( $docblock->getTags() as $tag ) {
		$content = preg_replace( '/[\n\r]+/', ' ', $tag->getDescription() );

		if ( 'param' == $tag->getName() && '{' == $content[0] ) {
			$content = parse_hashes( $content, $tag, $docblock );
		}

		$t = array(
			'name'    => $tag->getName(),
			'content' => $content,
		);

		if ( method_exists( $tag, 'getTypes' ) ) {
			$t['types'] = $tag->getTypes();
		}

		if ( method_exists( $tag, 'getVariableName' ) ) {
			$t['variable'] = $tag->getVariableName();
		}

		if ( method_exists( $tag, 'getReference' ) ) {
			$t['refers'] = $tag->getReference();
		}

		if ( 'since' == $tag->getName() && method_exists( $tag, 'getVersion' ) ) {
			$version = $tag->getVersion();
			if ( !empty( $version ) ) {
				$t['content'] = $version;
			}
		}
		$output['tags'][] = $t;
	}

	return $output;
}

function export_hooks( array $hooks ) {
	$out = array();

	foreach ( $hooks as $hook ) {
		$out[] = array(
			'name'      => $hook->getName(),
			'line'      => $hook->getLineNumber(),
			'end_line'  => $hook->getNode()->getAttribute( 'endLine' ),
			'type'      => $hook->getType(),
			'arguments' => $hook->getArgs(),
			'doc'       => export_docblock( $hook ),
		);
	}

	return $out;
}

function export_arguments( array $arguments ) {
	$output = array();

	foreach ( $arguments as $argument ) {
		$output[] = array(
			'name'    => $argument->getName(),
			'default' => $argument->getDefault(),
			'type'    => $argument->getType(),
		);
	}

	return $output;
}

function export_properties( array $properties ) {
	$out = array();

	foreach ( $properties as $property ) {
		$prop = array(
			'name'        => $property->getName(),
			'line'        => $property->getLineNumber(),
			'end_line'    => $property->getNode()->getAttribute( 'endLine' ),
			'default'     => $property->getDefault(),
//			'final' => $property->isFinal(),
			'static'      => $property->isStatic(),
			'visibililty' => $property->getVisibility(),
		);

		$docblock = export_docblock( $property );
		if ( $docblock ) {
			$prop['doc'] = $docblock;
		}

		$out[] = $prop;

	}

	return $out;
}

function export_methods( array $methods ) {
	$out = array();

	foreach ( $methods as $method ) {
		$meth = array(
			'name'       => $method->getShortName(),
			'line'       => $method->getLineNumber(),
			'end_line'   => $method->getNode()->getAttribute( 'endLine' ),
			'final'      => $method->isFinal(),
			'abstract'   => $method->isAbstract(),
			'static'     => $method->isStatic(),
			'visibility' => $method->getVisibility(),
			'arguments'  => export_arguments( $method->getArguments() ),
			'doc'        => export_docblock( $method ),
		);

		if ( ! empty( $method->uses ) ) {
			$meth['uses'] = export_uses( $method->uses );

			if ( ! empty( $method->uses['hooks'] ) ) {
				$meth['hooks'] = export_hooks( $method->uses['hooks'] );
			}
		}

		$out[] = $meth;
	}

	return $out;
}

/**
 * Export the list of elements used by a file or structure.
 *
 * @param array $uses {
 *        @type Function_Call_Reflector[] $functions The functions called.
 * }
 *
 * @return array
 */
function export_uses( array $uses ) {
	$out = array();

	// Ignore hooks here, they are exported separately.
	unset( $uses['hooks'] );

	foreach ( $uses as $type => $used_elements ) {
		foreach ( $used_elements as $element ) {
			$name = $element->getName();

			$out[ $type ][] = array(
				'name'       => $name,
				'line'       => $element->getLineNumber(),
				'end_line'   => $element->getNode()->getAttribute( 'endLine' ),
			);

			if ( '_deprecated_file' === $name || '_deprecated_function' === $name || '_deprecated_argument' === $name ) {
				$arguments = $element->getNode()->args;

				$out[ $type ][0]['deprecation_version'] = $arguments[1]->value->value;
			}
		}
	}

	return $out;
}

/**
 * Parse the given hash notation string into an associative array.
 *
 * @todo Make this not suck so bad.
 *
 * @param string   $content  Parameter tag content.
 * @param Tag      $tag      Tag object.
 * @param DocBlock $docblock DocBlock object.
 *
 * @return array Hash notation information as an associative array.
 */
function parse_hashes( $content, $tag, $docblock ) {
	$text = trim( substr( $content, 1, -1 ) );

	// Temporarily make the closing braces parsable.
	$text = str_replace( '    }', '@type }', $text );

	// New lines (not really necessary, makes debugging easier).
	$text = str_replace( '@type', "\n@type", $text );
	$parts = explode( "\n", $text );

	$hash = array();

	$index = 0;
	$first = $second = $third = false;

	foreach ( $parts as $part ) {
		$has_open_brace  = strpos( $part, '{' ) && ! strpos( $part, '{@' );
		$has_close_brace = strpos( $part, ' }' );
		$has_var         = preg_match( '/\$\w+/', $part, $var_matches );

		$part = trim( preg_replace( '/\s+/', ' ', $part ) );

		$has_var_and_match = ( $has_var && ! empty( $var_matches[0] ) );

		// Descriptions.
		if ( ! $has_open_brace && ! $has_var && ! $first ) {
			// Top-level description.
			$hash['content'] = $part;
			continue;
		}

		if ( $has_close_brace ) {
			if ( $first && ! $second ) {
				$first = false;
			} elseif ( $first && $second && ! $third ) {
				$second = false;
			} elseif ( $first && $second && $third ) {
				$third = false;
			}
			continue;
		}

		// Setup levels.
		if ( $has_open_brace ) {
			if ( $has_var_and_match ) {
				$type = ParamTag::createInstance( trim( $part ), $docblock );
			} else {
				$type = false;
			}

			$start_level_meta = array(
				'content' => $type ? $type->getDescription() : '',
				'types'   => $type ? $type->getTypes() : array( 'array' ),
			);

			if ( ! $first ) {
				$first = true;
				$first_level_key = $has_var_and_match ? $var_matches[0] : $index;

				$hash[ $first_level_key ] = $start_level_meta;
			} elseif ( $first && ! $second ) {
				$second = true;
				$second_level_key = $has_var_and_match ? $var_matches[0] : $index;

				$hash[ $first_level_key ][ $second_level_key ] = $start_level_meta;
			} elseif ( $second && ! $third ) {
				$third = true;
				$third_level_key = $has_var_and_match ? $var_matches[0] : $index;

				$hash[ $first_level_key ][ $second_level_key ][ $third_level_key ] = $start_level_meta;
			}
			$index++;
			continue;
		}

		$type = ParamTag::createInstance( trim( $part ), $docblock );

		$var_key = $type->getVariableName();

		$mid_level_meta = array(
			'content' => $type->getDescription(),
			'types'   => $type->getTypes(),
		);

		if ( ! $first ) {
			$hash[ $var_key ] = $mid_level_meta;
		} elseif ( $first && ! $second ) {
			$hash[ $first_level_key ][ $var_key ] = $mid_level_meta;
		} elseif ( $first && $second && ! $third ) {
			$hash[ $first_level_key ][ $second_level_key ][ $var_key ] = $mid_level_meta;
		} elseif ( $first && $second && $third ) {
			$hash[ $first_level_key ][ $second_level_key ][ $third_level_key ][ $var_key ] = $mid_level_meta;
		}
	}
	return $hash;
}
