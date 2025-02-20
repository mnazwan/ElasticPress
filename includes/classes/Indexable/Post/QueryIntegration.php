<?php
/**
 * Integrate with WP_Query
 *
 * @since  1.0
 * @package elasticpress
 */

namespace ElasticPress\Indexable\Post;

use ElasticPress\Indexables as Indexables;
use \WP_Query as WP_Query;
use ElasticPress\Utils as Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Query integration class
 */
class QueryIntegration {

	/**
	 * Is set only when we switch_to_blog in MS context
	 *
	 * @var  boolean
	 */
	private $switched = false;

	/**
	 * Checks to see if we should be integrating and if so, sets up the appropriate actions and filters.
	 *
	 * @since 0.9
	 */
	public function __construct() {
		// Ensure that we are currently allowing ElasticPress to override the normal WP_Query
		if ( Utils\is_indexing() ) {
			return;
		}

		// Add header
		add_action( 'pre_get_posts', array( $this, 'add_es_header' ), 5 );

		// Query ES for posts
		add_filter( 'posts_pre_query', array( $this, 'get_es_posts' ), 10, 2 );

		// Properly restore blog if necessary
		add_action( 'loop_end', array( $this, 'maybe_restore_blog' ), 10, 1 );

		// Properly switch to blog if necessary
		add_action( 'the_post', array( $this, 'maybe_switch_to_blog' ), 10, 1 );

		// Sets the correct value for found_posts
		add_filter( 'found_posts', array( $this, 'found_posts' ), 10, 2 );
	}

	/**
	 * Set the found_posts variable on WP_Query.
	 *
	 * @param int      $found_posts Number of found posts
	 * @param WP_Query $query Query object
	 * @since 2.8.2
	 * @return int
	 */
	public function found_posts( $found_posts, $query ) {
		if ( ( isset( $query->elasticsearch_success ) && false === $query->elasticsearch_success ) || ( ! Indexables::factory()->get( 'post' )->elasticpress_enabled( $query ) || apply_filters( 'ep_skip_query_integration', false, $query ) ) ) {
			return $found_posts;
		}

		return $query->num_posts;
	}

	/**
	 * Disables cache_results, adds header.
	 *
	 * @param WP_Query $query WP_Query instance
	 * @since 0.9
	 */
	public function add_es_header( $query ) {
		if ( ! Indexables::factory()->get( 'post' )->elasticpress_enabled( $query ) || apply_filters( 'ep_skip_query_integration', false, $query ) ) {
			return;
		}

		/**
		 * `cache_results` defaults to false but can be enabled.
		 *
		 * @since 1.5
		 */
		$query->set( 'cache_results', false );
		if ( ! empty( $query->query['cache_results'] ) ) {
			$query->set( 'cache_results', true );
		}

		if ( ! headers_sent() ) {
			/**
			 * Manually setting a header as $wp_query isn't yet initialized when we
			 * call: add_filter('wp_headers', 'filter_wp_headers');
			 */
			header( 'X-ElasticPress-Query: true' );
		}
	}

	/**
	 * Switch to the correct site if the post site id is different than the actual one
	 *
	 * @param WP_Post $post Post object
	 * @since 0.9
	 */
	public function maybe_switch_to_blog( $post ) {
		if ( ! is_multisite() ) {
			return;
		}

		if ( ! empty( $post->site_id ) && get_current_blog_id() !== $post->site_id ) {
			if ( $this->switched ) {
				restore_current_blog();

				$this->switched = false;
			}

			switch_to_blog( $post->site_id );

			$this->switched = $post->site_id;

			remove_action( 'the_post', array( $this, 'maybe_switch_to_blog' ), 10, 1 );
			setup_postdata( $post );
			add_action( 'the_post', array( $this, 'maybe_switch_to_blog' ), 10, 1 );
		}

	}

	/**
	 * Make sure the correct blog is restored
	 *
	 * @param  WP_Query $query WP_Query instance
	 * @since 0.9
	 */
	public function maybe_restore_blog( $query ) {
		if ( ! is_multisite() ) {
			return;
		}

		if ( $this->switched ) {
			restore_current_blog();

			$this->switched = false;
		}
	}

	/**
	 * Get posts from Elasticsearch
	 *
	 * @param array    $posts Array of posts
	 * @param WP_Query $query WP_Query instance
	 * @since 3.0
	 * @return string
	 */
	public function get_es_posts( $posts, $query ) {
		global $wpdb;

		if ( ! Indexables::factory()->get( 'post' )->elasticpress_enabled( $query ) || apply_filters( 'ep_skip_query_integration', false, $query ) ) {
			return $posts;
		}

		$query_vars = $query->query_vars;

		/**
		 * Allows us to filter in searchable post types if needed
		 *
		 * @since  2.1
		 */
		$query_vars['post_type'] = apply_filters( 'ep_query_post_type', $query_vars['post_type'], $query );

		if ( 'any' === $query_vars['post_type'] ) {
			unset( $query_vars['post_type'] );
		}

		/**
		 * If not search and not set default to post. If not set and is search, use searchable post types
		 */
		if ( empty( $query_vars['post_type'] ) ) {
			if ( empty( $query_vars['s'] ) ) {
				$query_vars['post_type'] = 'post';
			} else {
				$query_vars['post_type'] = array_values( get_post_types( array( 'exclude_from_search' => false ) ) );
			}
		}

		/**
		 * No post types so bail
		 */
		if ( empty( $query_vars['post_type'] ) ) {
			$this->posts_by_query[ spl_object_hash( $query ) ] = [];

			return [];
		}

		$new_posts = apply_filters( 'ep_wp_query_cached_posts', [], $query );

		$ep_query = null;

		if ( count( $new_posts ) < 1 ) {

			$scope = 'current';
			if ( ! empty( $query_vars['sites'] ) ) {
				$scope = $query_vars['sites'];
			}

			$formatted_args = Indexables::factory()->get( 'post' )->format_args( $query_vars );

			/**
			 * Filter search scope
			 *
			 * @since 2.1
			 *
			 * @param mixed $scope The search scope. Accepts `all` (string), a single
			 *                     site id (int or string), or an array of site ids (array).
			 */
			$scope = apply_filters( 'ep_search_scope', $scope );

			if ( ! defined( 'EP_IS_NETWORK' ) || ! EP_IS_NETWORK ) {
				$scope = 'current';
			}

			$index = null;

			if ( 'all' === $scope ) {
				$index = Indexables::factory()->get( 'post' )->get_network_alias();
			} elseif ( is_numeric( $scope ) ) {
				$index = Indexables::factory()->get( 'post' )->get_index_name( (int) $scope );
			} elseif ( is_array( $scope ) ) {
				$index = [];

				foreach ( $scope as $site_id ) {
					$index[] = Indexables::factory()->get( 'post' )->get_index_name( $site_id );
				}

				$index = implode( ',', $index );
			}

			$ep_query = Indexables::factory()->get( 'post' )->query_es( $formatted_args, $query->query_vars, $index );

			/**
			 * ES failed. Go back to MySQL.
			 */
			if ( false === $ep_query ) {
				$query->elasticsearch_success = false;
				return null;
			}

			$query->found_posts           = $ep_query['found_documents'];
			$query->num_posts             = $query->found_posts;
			$query->max_num_pages         = ceil( $ep_query['found_documents'] / $query->get( 'posts_per_page' ) );
			$query->elasticsearch_success = true;

			// Determine how we should format the results from ES based on the fields parameter.
			$fields = $query->get( 'fields', '' );

			switch ( $fields ) {
				case 'ids':
					$new_posts = $this->format_hits_as_ids( $ep_query['documents'], $new_posts );
					break;

				case 'id=>parent':
					$new_posts = $this->format_hits_as_id_parents( $ep_query['documents'], $new_posts );
					break;

				default:
					$new_posts = $this->format_hits_as_posts( $ep_query['documents'], $new_posts );
					break;
			}

			do_action( 'ep_wp_query_non_cached_search', $new_posts, $ep_query, $query );
		}

		$this->posts_by_query[ spl_object_hash( $query ) ] = $new_posts;

		do_action( 'ep_wp_query', $new_posts, $ep_query, $query );

		/**
		 * Pre-3.0 backwards compat
		 */
		do_action( 'ep_wp_query_search', $new_posts, $ep_query, $query );

		return $new_posts;
	}

	/**
	 * Format the ES hits/results as post objects.
	 *
	 * @since 2.4.0
	 *
	 * @param array $posts The posts that should be formatted.
	 * @param array $new_posts Array of posts from cache.
	 *
	 * @return array
	 */
	protected function format_hits_as_posts( $posts, $new_posts ) {
		foreach ( $posts as $post_array ) {
			$post = new \stdClass();

			$post->ID      = $post_array['post_id'];
			$post->site_id = get_current_blog_id();

			if ( ! empty( $post_array['site_id'] ) ) {
				$post->site_id = $post_array['site_id'];
			}
			// ep_search_request_args
			$post_return_args = apply_filters(
				'ep_search_post_return_args',
				array(
					'post_type',
					'post_author',
					'post_name',
					'post_status',
					'post_title',
					'post_parent',
					'post_content',
					'post_excerpt',
					'post_date',
					'post_date_gmt',
					'post_modified',
					'post_modified_gmt',
					'post_mime_type',
					'comment_count',
					'comment_status',
					'ping_status',
					'menu_order',
					'permalink',
					'terms',
					'post_meta',
					'meta',
				)
			);

			foreach ( $post_return_args as $key ) {
				if ( 'post_author' === $key ) {
					$post->$key = $post_array[ $key ]['id'];
				} elseif ( isset( $post_array[ $key ] ) ) {
					$post->$key = $post_array[ $key ];
				}
			}

			$post->elasticsearch = true; // Super useful for debugging

			if ( $post ) {
				$new_posts[] = $post;
			}
		}

		return $new_posts;
	}

	/**
	 * Format the ES hits/results as an array of ids.
	 *
	 * @since 2.4.0
	 *
	 * @param array $posts The posts that should be formatted.
	 * @param array $new_posts Array of posts from cache.
	 *
	 * @return array
	 */
	protected function format_hits_as_ids( $posts, $new_posts ) {
		foreach ( $posts as $post_array ) {
			$new_posts[] = $post_array['post_id'];
		}

		return $new_posts;
	}

	/**
	 * Format the ES hits/results as objects containing id and parent id.
	 *
	 * @since 2.4.0
	 *
	 * @param array $posts The posts that should be formatted.
	 * @param array $new_posts Array of posts from cache.
	 *
	 * @return array
	 */
	protected function format_hits_as_id_parents( $posts, $new_posts ) {
		foreach ( $posts as $post_array ) {
			$post                = new \stdClass();
			$post->ID            = $post_array['post_id'];
			$post->post_parent   = $post_array['post_parent'];
			$post->elasticsearch = true; // Super useful for debugging
			$new_posts[]         = $post;
		}
		return $new_posts;
	}
}
