<?php

namespace Classifai\Admin;

/**
 * Classifies Posts based on the current ClassifAI configuration.
 */
class SavePostHandler {

	/**
	 * @var $classifier \Classifai\PostClassifier Lazy loaded classifier object
	 */
	public $classifier;

	/**
	 * Enables the classification on save post behaviour.
	 */
	public function register() {
		add_action( 'save_post', [ $this, 'did_save_post' ] );
		add_action( 'admin_notices', [ $this, 'show_error_if' ] );
	}

	/**
	 * Save Post handler only runs on admin or REST requests
	 */
	public function can_register() {
		if ( ! get_option( 'classifai_configured', false ) ) {
			return false;
		} elseif ( empty( get_option( 'classifai_watson_nlu' ) ) ) {
			return false;
		} elseif ( empty( get_option( 'classifai_watson_nlu' )['credentials']['watson_url'] ) ) {
			return false;
		} elseif ( is_admin() ) {
			return true;
		} elseif ( $this->is_rest_route() ) {
			return true;
		} elseif ( defined( 'PHPUNIT_RUNNER' ) && PHPUNIT_RUNNER ) {
			return false;
		} elseif ( defined( 'WP_CLI' ) && WP_CLI ) {
			return false;
		} else {
			return false;
		}
	}

	/**
	 * If current post type support is enabled in ClassifAI settings, it
	 * is tagged using the IBM Watson classification result.
	 *
	 * Skips classification if running under the Gutenberg Metabox
	 * compatibility request. The classification is performed during the REST
	 * lifecyle when using Gutenberg.
	 *
	 * @param int $post_id The post that was saved
	 */
	public function did_save_post( $post_id ) {
		if ( ! empty( $_GET['classic-editor'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
			return;
		}

		$supported     = \Classifai\get_supported_post_types();
		$post_type     = get_post_type( $post_id );
		$post_status   = get_post_status( $post_id );
		$post_statuses = \Classifai\get_supported_post_statuses();


		/**
		 * Filter post statuses for post type or ID.
		 *
		 * @since 1.0.0
		 * @hook classifai_post_statuses_for_post_type_or_id
		 *
		 * @param {array} $post_statuses Array of post statuses to be classified with language processing.
		 * @param {string} $post_type The post type.
		 * @param {int} $post_id The post ID.
		 *
		 * @return {array} Array of post statuses.
		 */
		$post_statuses = apply_filters( 'classifai_post_statuses_for_post_type_or_id', $post_statuses, $post_type, $post_id );

		// Process posts in allowed post statuses, supported items and only if features are enabled
		if ( in_array( $post_status, $post_statuses, true ) && in_array( $post_type, $supported, true ) && \Classifai\language_processing_features_enabled() ) {
			$this->classify( $post_id );
		}
	}

	/**
	 * Classifies the post specified with the PostClassifier object.
	 * Existing terms relationships are removed before classification.
	 *
	 * @param int $post_id the post to classify & link
	 *
	 * @return array
	 */
	public function classify( $post_id ) {
		/**
		 * Filter whether ClassifAI should classify a post.
		 *
		 * Default is true, return false to skip classifying a post.
		 *
		 * @since 1.2.0
		 * @hook classifai_should_classify_post
		 *
		 * @param {bool} $should_classify Whether the post should be classified. Default `true`, return `false` to skip
		 *                              classification for this post.
		 * @param {int}  $post_id         The ID of the post to be considered for classification.
		 *
		 * @return {bool} Whether the post should be classified.
		 */
		$classifai_should_classify_post = apply_filters( 'classifai_should_classify_post', true, $post_id );
		if ( ! $classifai_should_classify_post ) {
			return false;
		}

		$classifier = $this->get_classifier();

		if ( \Classifai\get_feature_enabled( 'category' ) ) {
			wp_delete_object_term_relationships( $post_id, \Classifai\get_feature_taxonomy( 'category' ) );
		}

		if ( \Classifai\get_feature_enabled( 'keyword' ) ) {
			wp_delete_object_term_relationships( $post_id, \Classifai\get_feature_taxonomy( 'keyword' ) );
		}

		if ( \Classifai\get_feature_enabled( 'concept' ) ) {
			wp_delete_object_term_relationships( $post_id, \Classifai\get_feature_taxonomy( 'concept' ) );
		}

		if ( \Classifai\get_feature_enabled( 'entity' ) ) {
			wp_delete_object_term_relationships( $post_id, \Classifai\get_feature_taxonomy( 'entity' ) );
		}

		$output = $classifier->classify_and_link( $post_id );

		if ( is_wp_error( $output ) ) {
			update_post_meta(
				$post_id,
				'_classifai_error',
				wp_json_encode(
					[
						'code'    => $output->get_error_code(),
						'message' => $output->get_error_message(),
					]
				)
			);
		} else {
			// If there is no error, clear any existing error states.
			delete_post_meta( $post_id, '_classifai_error' );
		}

		return $output;
	}

	/**
	 * Lazy initializes the Post Classifier object
	 */
	public function get_classifier() {
		if ( is_null( $this->classifier ) ) {
			$this->classifier = new \Classifai\PostClassifier();
		}

		return $this->classifier;
	}

	/**
	 * Outputs an Admin Notice with the error message if NLU
	 * classification had failed earlier.
	 */
	public function show_error_if() {
		global $post;

		if ( empty( $post ) ) {
			return;
		}

		$post_id = $post->ID;

		if ( empty( $post_id ) ) {
			return;
		}

		$error = get_post_meta( $post_id, '_classifai_error', true );

		if ( ! empty( $error ) ) {
			delete_post_meta( $post_id, '_classifai_error' );
			$error   = (array) json_decode( $error );
			$code    = ! empty( $error['code'] ) ? $error['code'] : 500;
			$message = ! empty( $error['message'] ) ? $error['message'] : 'Unknown NLU API error';

			?>
			<div class="notice notice-error is-dismissible">
				<p>
					Error: Failed to classify content with the IBM Watson NLU API.
				</p>
				<p>
					<?php echo esc_html( $code ); ?>
					-
					<?php echo esc_html( $message ); ?>
				</p>
			</div>
			<?php
		}
	}

	/**
	 * We need to determine if we're doing a REST call.
	 *
	 * @return bool
	 */
	public function is_rest_route() {

		if ( ! isset( $_SERVER['REQUEST_URI'] ) ) {
			return false;
		}

		/**
		 * Filter the REST bases. Supports custom post types with a custom REST base.
		 *
		 * @since 1.5.0
		 * @hook classifai_rest_bases
		 *
		 * @param {array} rest_bases Array of REST bases.
		 *
		 * @return {array} The filtered array of REST bases.
		 */
		$rest_bases = apply_filters( 'classifai_rest_bases', array( 'posts', 'pages' ) );

		foreach ( $rest_bases as $rest_base ) {
			if ( false !== strpos( sanitize_text_field( $_SERVER['REQUEST_URI'] ), 'wp-json/wp/v2/' . $rest_base ) ) {
				return true;
			}
		}

		return false;
	}

}
