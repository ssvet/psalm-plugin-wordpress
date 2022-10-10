<?php

namespace PsalmWordpress;

use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\Echo_;
use PhpParser;
use PhpParser\Node\Scalar\String_;
use Psalm\Codebase;
use Psalm\Internal\Analyzer\FileAnalyzer;
use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\IssueBuffer;
use Psalm\Plugin\PluginEntryPointInterface;
use Psalm\Plugin\RegistrationInterface;
use Psalm\Plugin\Hook\AfterEveryFunctionCallAnalysisInterface;
use Psalm\Plugin\Hook\FunctionParamsProviderInterface;
use Psalm\Plugin\Hook\BeforeFileAnalysisInterface;
use Psalm\StatementsSource;
use Psalm\Storage\FileStorage;
use Psalm\Storage\FunctionLikeParameter;
use SimpleXMLElement;
use Psalm\Type\Union;
use Psalm\Type;
use Psalm;
use Psalm\Type\TypeNode;
use Psalm\Type\Atomic\TLiteralString;
use Psalm\Type\Atomic\TCallable;
use PhpParser\Node\Arg;
use PhpParser\Node\Name;
use Psalm\Type\Atomic;
use Exception;

class Plugin implements PluginEntryPointInterface, AfterEveryFunctionCallAnalysisInterface, FunctionParamsProviderInterface, BeforeFileAnalysisInterface {

	/**
	 * @var array<string, array{types: list<Union>}>
	 */
	public static $hooks = [];

	public function __invoke( RegistrationInterface $registration, ?SimpleXMLElement $config = null ) : void {
		$registration->registerHooksFromClass( static::class );

		// if useDefaultStubs is not set or set to anything except false, we want to load the stubs included in this plugin
		if ( !isset( $config->useDefaultStubs['value'] ) || (string) $config->useDefaultStubs['value'] !== 'false' ) {
			array_map( [ $registration, 'addStubFile' ], $this->getStubFiles() );
		}

		static::loadStubbedHooks();
	}

	/**
	 * resolve a vendor-relative directory-path to the absolute package directory
	 *
	 * the plugin must run both from the source file in the repository (current working directory)
	 * as well as when required as a composer package when the current working directory may not
	 * have a vendor/ folder and the package directory is detected relative to this file.
	 *
	 * @param string $path of a folder, relative, inside vendor/ (composer), must start with 'vendor/' marker
	 * @return string
	 */
	private static function getVendorDir(string $path) : string {
		$vendor = 'vendor/';
		$self = 'humanmade/psalm-plugin-wordpress';

		if (0 !== strpos($path, $vendor)) {
			throw new \BadMethodCallException(
				sprintf('$path must start with "%s", "%s" given', $vendor, $path)
			);
		}

		$cwd = getcwd();

		// prefer path relative to current working directory (original default)
		$cwdPath = $cwd . '/' . $path;
		echo "$cwdPath\n";


		if (is_dir($cwdPath)) {
			echo "Loading $cwdPath\n";
			return $cwdPath;
		}

		$pkgSelfDir = __DIR__;
		// vendor folder 2 folders upper.
		$vendorDir = dirname($pkgSelfDir, 4) . '/vendor';
		echo "$vendorDir/$self - $pkgSelfDir\n";

		if ( is_dir( $vendorDir . '/' . $self ) ) {
			// likely plugin is running as composer package, let's try for the path
			$pkgPath = substr($path, strlen($vendor));
			$vendorPath = $vendorDir . '/' . $pkgPath;
			if (is_dir($vendorPath)) {
				echo "Loading $vendorPath\n";
				return $vendorPath;
			}
		}
		// check running as composer package inside a vendor folder
		$pkgSelfDir = __DIR__;
		$vendorDir = dirname($pkgSelfDir, 2);
		if (is_dir( $vendorDir . '/' . $self )) {
			// likely plugin is running as composer package, let's try for the path
			$pkgPath = substr($path, strlen($vendor));
			$vendorPath = $vendorDir . '/' . $pkgPath;
			if (is_dir($vendorPath)) {
				echo "Loading $vendorPath\n";
				return $vendorPath;
			}
		}


		// original default behaviour
		echo "Loading $cwdPath\n";
		return $cwdPath;
	}

	/**
	 * @return string[]
	 */
	private function getStubFiles(): array {

		return [
			self::getVendorDir('vendor/php-stubs/wordpress-stubs') . '/wordpress-stubs.php',
			self::getVendorDir('vendor/php-stubs/wordpress-globals') . '/wordpress-globals.php',
			self::getVendorDir('vendor/php-stubs/wp-cli-stubs') . '/wp-cli-stubs.php',
			self::getVendorDir('vendor/php-stubs/wp-cli-stubs') . '/wp-cli-commands-stubs.php',
			self::getVendorDir('vendor/php-stubs/wp-cli-stubs') . '/wp-cli-i18n-stubs.php',
			__DIR__ . '/stubs/overrides.php',
		];
	}

	protected static function loadStubbedHooks() : void {
		if ( static::$hooks ) {
			return;
		}

		$wpHooksDataDir = self::getVendorDir('vendor/johnbillion/wp-hooks/hooks');

		static::loadHooksFromFile( $wpHooksDataDir . '/actions.json' );
		static::loadHooksFromFile( $wpHooksDataDir . '/filters.json' );
	}

	/**
	 *
	 * @param string $filepath
	 * @return array<string, array{ types: list<Union> }>
	 */
	protected static function loadHooksFromFile( string $filepath ) : void {
		/** @var list<array{ name: string, file: string, type: 'action'|'filter', doc: array{ description: string, long_description: string, long_description_html: string, tags: list<array{ name: string, content: string, types?: list<string>}> } }> */
		$hooks = json_decode( file_get_contents( $filepath ), true );
		$hook_map = [];
		foreach ( $hooks['hooks'] as $hook ) {
			$params = array_filter( $hook['doc']['tags'], function ( $tag ) {
				return $tag['name'] === 'param';
			} );

			$params = array_map( function ( array $param ) : array {
				if ( isset( $param['types'] ) && $param['types'] !== [ 'array' ] ) {
					return $param;
				}
				if ( substr_count( $param['content'], '{' ) !== 1 ) {
					// Unable to parse nested array style phpdoc.
					return $param;
				}

				$found = preg_match_all( '/\@type[\s]+([^ ]+)\s+\$(\w+)/', $param['content'], $matches, PREG_SET_ORDER );
				if ( ! $found ) {
					return $param;
				}
				$array_properties = [];
				foreach ( $matches as $match ) {
					$array_properties[] = $match[2] . ': ' . $match[1];
				}
				$array_string = 'array{ ' . implode( ', ', $array_properties ) . ' }';
				$param['types'] = [ $array_string ];
				return $param;

			}, $params );

			$types = array_column( $params, 'types' );

			$types = array_map( function ( $type ) : string {
				return implode( '|', $type );
			}, $types );

			// remove empty elements which can happen with invalid phpdoc - must be done before parseString to avoid notice there
			$types = array_filter( $types );

			static::registerHook( $hook['name'], array_map( [ Type::class, 'parseString' ], $types ), $hook['type'] );
		}
	}

	public static function beforeAnalyzeFile( StatementsSource $statements_source, Context $file_context, FileStorage $file_storage, Codebase $codebase ) : void {
		$statements = $codebase->getStatementsForFile( $statements_source->getFilePath() );
		$traverser = new PhpParser\NodeTraverser;
		$hook_visitor = new HookNodeVisitor();
		$traverser->addVisitor( $hook_visitor );
		try {
			$traverser->traverse( $statements );
		} catch ( Exception $e ) {

		}

		foreach ( $hook_visitor->hooks as $hook_name => $hook ) {
			static::registerHook( $hook_name, $hook['types'], $hook['hook_type'] );
		}
	}

	public static function afterEveryFunctionCallAnalysis(
		FuncCall $expr,
		string $function_id,
		Context $context,
		StatementsSource $statements_source,
		Codebase $codebase
	): void {
		$apply_filter_functions = [
			'apply_filters',
			'apply_filters_ref_array',
			'apply_filters_deprecated',
		];

		$do_action_functions = [
			'do_action',
			'do_action_ref_array',
			'do_action_deprecated',
		];

		if ( in_array( $function_id, $apply_filter_functions, true ) ) {
			$hook_type = 'filter';
		} elseif ( in_array( $function_id, $do_action_functions, true) ) {
			$hook_type = 'action';
		} else {
			return;
		}

		if ( ! $expr->args[0]->value instanceof String_ ) {
			return;
		}

		$name = $expr->args[0]->value->value;
		// Check if this hook is already documented.
		if ( isset( static::$hooks[ $name ] ) ) {
			return;
		}

		$types = array_map( function ( Arg $arg ) use ( $statements_source ) {
			$type = $statements_source->getNodeTypeProvider()->getType( $arg->value );
			if ( ! $type ) {
				$type = Type::parseString( 'mixed' );
			} else {
				$sub_types = array_values( $type->getAtomicTypes() );
				$sub_types = array_map( function ( Atomic $type ) : Atomic {
					if ( $type instanceof Atomic\TTrue || $type instanceof Atomic\TFalse ) {
						return new Atomic\TBool;
					} elseif ( $type instanceof Atomic\TLiteralString ) {
						return new Atomic\TString;
					} elseif ( $type instanceof Atomic\TLiteralInt ) {
						return new Atomic\TInt;
					} elseif ( $type instanceof Atomic\TLiteralFloat ) {
						return new Atomic\TFloat;
					}

					return $type;
				}, $sub_types );
				$type = new Union( $sub_types );
			}

			return $type;
		}, array_slice( $expr->args, 1 ) );

		static::registerHook( $name, $types, $hook_type );
	}

	public static function getFunctionIds() : array {
		return [
			'add_action',
			'add_filter',
		];
	}

	/**
	 * @param  list<PhpParser\Node\Arg>    $call_args
	 *
	 * @return ?array<int, \Psalm\Storage\FunctionLikeParameter>
	 */
	public static function getFunctionParams(
		StatementsSource $statements_source,
		string $function_id,
		array $call_args,
		Context $context = null,
		CodeLocation $code_location = null
	) : ?array {
		static::loadStubbedHooks();

		// Currently we only support detecting the hook name if it's a string.
		if ( ! $call_args[0]->value instanceof String_ ) {
			return null;
		}

		$hook_name = $call_args[0]->value->value;
		$hook = static::$hooks[ $hook_name ] ?? null;
		$is_action = $function_id === 'add_action';

		if ( ! $hook ) {
			if ( $code_location ) {
				IssueBuffer::accepts(
					new HookNotFound(
						'Hook ' . $hook_name . ' not found',
						$code_location
					),
					$statements_source->getSuppressedIssues()
				);
			}
			return [];
		}

		// action_reference for do_action_ref_array
		if ( $is_action && !in_array( $hook['hook_type'], array( 'action', 'action_reference' ), true ) ) {
			if ( $code_location ) {
				IssueBuffer::accepts(
					new HookNotFound(
						'Hook ' . $hook_name . ' is a filter not an action',
						$code_location
					),
					$statements_source->getSuppressedIssues()
				);
			}
			return [];
		}

		// filter_reference for apply_filters_ref_array
		if ( ! $is_action && !in_array( $hook['hook_type'], array( 'filter', 'filter_reference' ), true ) ) {
			if ( $code_location ) {
				IssueBuffer::accepts(
					new HookNotFound(
						'Hook ' . $hook_name . ' is an action not a filter',
						$code_location
					),
					$statements_source->getSuppressedIssues()
				);
			}
			return [];
		}

		// Check how many args the filter is registered with.
		/** @var int */
		$num_args = $call_args[ 3 ]->value->value ?? 1;
		// Limit the required type params on the hook to match the registered number.
		$hook_types = array_slice( $hook['types'], 0, $num_args );

		$hook_params = array_map( function ( Union $type ) : FunctionLikeParameter {
			return new FunctionLikeParameter( 'param', false, $type, null, null, false );
		}, $hook_types );

		// Actions must return null/void. Filters must return the same type as the first param.
		if ( $is_action ) {
			$return_type = Type::parseString( 'void|null' );
		} elseif ( isset( $hook['types'][0] ) ) {
			$return_type = $hook['types'][0];
		} else {
			// unknown due to lack of PHPDoc - but a filter must always return something - mixed is the most generic case
			$return_type = Type::parseString( 'mixed' );
		}

		$return = [
			new FunctionLikeParameter( 'Hook', false, Type::parseString( 'string' ), null, null, false ),
			new FunctionLikeParameter( 'Callback', false, new Union( [
				new TCallable(
					'callable',
					$hook_params,
					$return_type
				),
			] ), null, null, false ),
			new FunctionLikeParameter( 'Priority', false, Type::parseString( 'int|null' ) ),
			new FunctionLikeParameter( 'Args', false, Type::parseString( 'int|null' ) ),
		];
		return $return;
	}

	/**
	 * @param string $hook
	 * @param list<Union> $types
	 * @return void
	 */
	public static function registerHook( string $hook, array $types, string $hook_type = 'filter' ) {
		// remove empty elements which can happen with invalid phpdoc
		$types = array_filter( $types );

		// do not assign empty types if we already have this hook registered
		if ( isset( static::$hooks[ $hook ] ) && empty( $types ) ) {
			return;
		}

		// if this hook is registered already
		if ( isset( static::$hooks[ $hook ] ) ) {
			// if we have more types than already registered we overwrite existing ones, but keep the additional ones (array_merge would combine them which is wrong)
			$types = $types + static::$hooks[ $hook ]['types'];
		}

		static::$hooks[ $hook ] = [
			'hook_type' => $hook_type,
			'types' => $types,
		];
	}
}

class HookNodeVisitor extends PhpParser\NodeVisitorAbstract {
	/** @var ?PhpParser\Comment\Doc */
	protected $last_doc = null;

	/** @var array<string, list<Union>> */
	public $hooks = [];

	public function enterNode( PhpParser\Node $origNode ) {
		$apply_filter_functions = [
			'apply_filters',
			'apply_filters_ref_array',
			'apply_filters_deprecated',
		];

		$do_action_functions = [
			'do_action',
			'do_action_ref_array',
			'do_action_deprecated',
		];

		// "return apply_filters" will assign the phpdoc to the return instead of the apply_filters, so we need to store it
		// "$var = apply_filters" directly after a function declaration
		// "echo apply_filters"
		// cannot do this for all cases, as often it will assign completely wrong stuff otherwise
		if ( $origNode->getDocComment() && ( $origNode instanceof FuncCall || $origNode instanceof Return_ || $origNode instanceof Variable || $origNode instanceof Echo_ ) ) {
			$this->last_doc = $origNode->getDocComment();
		} elseif ( isset( $this->last_doc ) && ! $origNode instanceof FuncCall ) {
			// if it's set already and this is not a FuncCall, reset it to null, since there's something else and it would be used incorrectly
			$this->last_doc = null;
		}

		if ( $this->last_doc && $origNode instanceof FuncCall && $origNode->name instanceof Name ) {
			if ( in_array( (string) $origNode->name, $apply_filter_functions, true ) ) {
				$hook_type = 'filter';
			} elseif ( in_array( (string) $origNode->name, $do_action_functions, true) ) {
				$hook_type = 'action';
			} else {
				return null;
			}
			if ( ! $origNode->args[0]->value instanceof String_ ) {
				$this->last_doc = null;
				return null;
			}

			$hook_name = $origNode->args[0]->value->value;

			$doc_comment = $this->last_doc->getText();

			$doc_factory = \phpDocumentor\Reflection\DocBlockFactory::createInstance();
			try {
				$doc_block = $doc_factory->create( $doc_comment );
			} catch ( \RuntimeException $e ) {
				return null;
			} catch ( \InvalidArgumentException $e ) {
				return null;
			}

			/** @var \phpDocumentor\Reflection\DocBlock\Tags\Param[] */
			$params = $doc_block->getTagsByName( 'param' );

			$types = [];
			foreach ( $params as $param ) {
				// might be instanceof \phpDocumentor\Reflection\DocBlock\Tags\invalidTag if the param is invalid
				if( ! ( $param instanceof \phpDocumentor\Reflection\DocBlock\Tags\Param ) ) {
					// set to mixed - if we skip it, it will mess up all subsequent args
					$types[] = 'mixed';
					continue;
				}
				$param_type = $param->getType();
				if ( is_null( $param_type ) ) {
					// set to mixed - if we skip it, it will mess up all subsequent args
					$types[] = 'mixed';
					continue;
				}

				$types[] = $param_type->__toString();
			}

			if ( empty( $types ) ) {
				return null;
			}

			$types = array_map( [ Type::class, 'parseString' ], $types );

			$this->hooks[ $hook_name ] = [
				'hook_type' => $hook_type,
				'types' => $types,
			];
			$this->last_doc = null;
		}

		return null;
	}
}

class HookNotFound extends \Psalm\Issue\PluginIssue {}

