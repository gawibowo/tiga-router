<?php

/**
 * The Processor is in charge of the interaction between the routing system and
 * the rest of WordPress.
 *
 * @author Carl Alexander <contact@carlalexander.ca>
 */
class Processor {

	/**
	 * The matched route found by the router.
	 *
	 * @var Route
	 */
	private $matched_route;

	/**
	 * The router.
	 *
	 * @var Router
	 */
	private $router;

	/**
	 * The routes we want to register with WordPress.
	 *
	 * @var Route[]
	 */
	private $routes;

	/**
	 * Constructor.
	 *
	 * @param Router $router Router object.
	 * @param array  $routes Routes.
	 */
	public function __construct( Router $router, array $routes = array() ) {
		$this->router = $router;
		$this->routes = $routes;
	}

	/**
	 * Initialize processor with WordPress.
	 *
	 * @param Router $router Router object.
	 * @param array  $routes Routes.
	 */
	public static function init( Router $router, array $routes = array() ) {
		$self = new self( $router, $routes );

		add_action( 'init', array( $self, 'register_routes' ) );
		add_action( 'parse_request', array( $self, 'match_request' ) );

		add_action( 'template_redirect', array( $self, 'call_route_hook' ) );
	}

	/**
	 * Checks to see if a route was found. If there's one, it calls the route hook.
	 */
	public function call_route_hook() {
		$method = strtolower( $_SERVER['REQUEST_METHOD'] );
		if ( ! $this->matched_route instanceof Route || ! $this->matched_route->has_hook( $method ) ) {
			return;
		}
		if ( is_callable( $this->matched_route->get_hook( $method ) ) ) {
			add_filter(
				'body_class', function( $classes ) {
					array_push( $classes, 'tiga-router' );
					return $classes;
				}
			);
			call_user_func( $this->matched_route->get_hook( $method ), new Tiga\Request() );
		}
	}


	/**
	 * Attempts to match the current request to a route.
	 *
	 * @param WP $environment WordPress environment object.
	 */
	public function match_request( WP $environment ) {
		$method = strtolower( $_SERVER['REQUEST_METHOD'] );
		$matched_route = $this->router->match( $environment->query_vars );

		if ( $matched_route instanceof Route ) {
			if ( in_array( $method, $matched_route->get_methods(), true ) ) {
				global $current_route;
				$current_route = $matched_route->get_path();
				$this->matched_route = $matched_route;
			} else {
				global $wp_query;
				$wp_query->set_404();
				status_header( 404 );
				get_template_part( 404 );
				exit();
			}
		}

		if ( $matched_route instanceof \WP_Error && in_array( 'route_not_found', $matched_route->get_error_codes(), true ) ) {
			wp_die(
				esc_html( $matched_route ), 'Route Not Found', array(
					'response' => 404,
				)
			);
		}
	}

	/**
	 * Register all our routes into WordPress.
	 */
	public function register_routes() {

		$routes = apply_filters( 'my_plugin_routes', $this->routes );

		$route_list =  array();

		foreach ( $routes as $name => $route ) {
			$this->router->add_route( $name, $route );
			$route_list[] = $name;
		}

		$this->router->compile();

		// skip if admin
		if( is_admin() )
			return;

		$route_list_hash = md5( serialize( $route_list ) );

		if ( strval($route_list_hash) != strval( get_option( 'tiga_route_md5_hash' ) ) ) {
			flush_rewrite_rules();
			update_option( 'tiga_route_md5_hash', $route_list_hash, 'no' );
		}
	}
}
