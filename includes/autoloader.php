<?php
namespace MailHawk;

if ( ! defined( 'ABSPATH' ) ) {
    exit; // Exit if accessed directly.
}

/**
 * MailHawk autoloader.
 *
 * MailHawk autoloader handler class is responsible for loading the different
 * classes needed to run the plugin.
 *
 * Borrowed from Elementor, thanks guys...
 *
 * @since 2.0
 */
class Autoloader {

    /**
     * Classes map.
     *
     * Maps MailHawk classes to file names.
     *
     * @since 1.6.0
     * @access private
     * @static
     *
     * @var array Classes used by MailHawk.
     */
    private static $classes_map = [

    ];

    /**
     * Run autoloader.
     *
     * Register a function as `__autoload()` implementation.
     *
     * @since 1.6.0
     * @access public
     * @static
     */
    public static function run() {
        spl_autoload_register( [ __CLASS__, 'autoload' ] );
    }

    /**
     * Load class.
     *
     * For a given class name, require the class file.
     *
     * @since 1.6.0
     * @access private
     * @static
     *
     * @param string $relative_class_name Class name.
     */
    private static function load_class( $relative_class_name ) {

        if ( isset( self::$classes_map[ $relative_class_name ] ) ) {
            $filename = MAILHAWK_PATH . '/' . self::$classes_map[ $relative_class_name ];
        } else {
            $filename = strtolower(
                preg_replace(
                    [ '/([a-z])([A-Z])/', '/_/', '/\\\/' ],
                    [ '$1-$2', '-', DIRECTORY_SEPARATOR ],
                    $relative_class_name
                )
            );

            $is_filename = MAILHAWK_PATH . $filename . '.php';

            if ( ! file_exists( $is_filename ) ){
                $filename = MAILHAWK_PATH . 'includes/' . $filename . '.php';
            } else {
                $filename = $is_filename;
            }
        }

//        var_dump( wp_normalize_path( $filename ) );

        if ( is_readable( $filename ) ) {
            require $filename;
        }
    }

    /**
     * Autoload.
     *
     * For a given class, check if it exist and load it.
     *
     * @since 1.6.0
     * @access private
     * @static
     *
     * @param string $class Class name.
     */
    private static function autoload( $class ) {
        if ( 0 !== strpos( $class, __NAMESPACE__ . '\\' ) ) {
            return;
        }

        $relative_class_name = preg_replace( '/^' . __NAMESPACE__ . '\\\/', '', $class );

        $final_class_name = __NAMESPACE__ . '\\' . $relative_class_name;

        if ( ! class_exists( $final_class_name ) ) {
            self::load_class( $relative_class_name );
        }
    }
}
