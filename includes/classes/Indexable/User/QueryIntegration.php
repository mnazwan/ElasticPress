<?php
/**
 * Integrate with WP_User_Query
 *
 * @since  1.0
 * @package elasticpress
 */

namespace ElasticPress\Indexable\User;

use ElasticPress\Indexables as Indexables;
use \WP_User_Query as WP_User_Query;
use ElasticPress\Utils as Utils;

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Exit if accessed directly.
}

/**
 * Query integration class
 */
class QueryIntegration {

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

		add_filter( 'users_pre_query', [ $this, 'maybe_filter_query' ], 10, 2 );

		// Add header
		add_action( 'pre_get_users', array( $this, 'action_pre_get_users' ), 5 );
	}

	/**
	 * If WP_User_Query meets certain conditions, query results from ES
	 *
	 * @param  array         $results Users array.
	 * @param  WP_User_Query $query   Current query.
	 * @since  3.0
	 * @return array
	 */
	public function maybe_filter_query( $results, WP_User_Query $query ) {
		$user_indexable = Indexables::factory()->get( 'user' );

		if ( ! $user_indexable->elasticpress_enabled( $query ) || apply_filters( 'ep_skip_user_query_integration', false, $query ) ) {
			return $results;
		}

		$new_users = apply_filters( 'ep_wp_query_search_cached_posts', null, $query );

		if ( null === $new_users ) {
			$formatted_args = $user_indexable->format_args( $query->query_vars );

			$ep_query = $user_indexable->query_es( $formatted_args, $query->query_vars );

			if ( false === $ep_query ) {
				return $results;
			}

			/**
			 * WP_User_Query does not let us set this property:
			 *
			 * $query->elasticsearch_success = true;
			 */

			if ( 'all_with_meta' === $query->get( 'fields' ) ) {
				$new_users = [];

				foreach ( $ep_query['documents'] as $document ) {
					$new_users[] = $document['ID'];
				}
			} else {
				$new_users = $this->format_hits_as_users( $ep_query['documents'] );
			}
		}

		$query->total_users = $ep_query['found_documents'];

		return $new_users;
	}

	/**
	 * Format the ES hits/results as WP_User objects.
	 *
	 * @param array $users The users that should be formatted.
	 * @since  3.0
	 * @return array
	 */
	protected function format_hits_as_users( $users ) {
		$new_users = [];

		foreach ( $users as $user_array ) {
			$user = new \stdClass();

			$user_return_args = apply_filters(
				'ep_search_user_return_args',
				[
					'ID',
					'user_login',
					'user_nicename',
					'user_email',
					'user_url',
					'user_registered',
					'user_status',
					'display_name',
					'spam',
					'deleted',
					'terms',
					'meta',
				]
			);

			foreach ( $user_return_args as $key ) {
				if ( isset( $user_array[ $key ] ) ) {
					$user->$key = $user_array[ $key ];
				}
			}

			$user->elasticsearch = true; // Super useful for debugging.

			$new_users[] = $user;
		}

		return $new_users;
	}

	/**
	 * Disables cache_results, adds header.
	 *
	 * @param WP_User_Query $query User query
	 * @since 3.0
	 */
	public function action_pre_get_users( $query ) {
		if ( ! Indexables::factory()->get( 'user' )->elasticpress_enabled( $query ) || apply_filters( 'ep_skip_user_query_integration', false, $query ) ) {
			return;
		}

		if ( ! headers_sent() ) {
			/**
			 * Manually setting a header as $wp_query isn't yet initialized
			 * when we call: add_filter('wp_headers', 'filter_wp_headers');
			 */
			header( 'X-ElasticPress-Search: true' );
		}
	}
}
