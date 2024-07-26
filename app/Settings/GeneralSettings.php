<?php

namespace BlazeWooless\Settings;

use BlazeWooless\TypesenseClient;

class GeneralSettings extends BaseSettings {
	private static $instance = null;
	public $tab_key = 'general';
	public $page_label = 'General';

	public static function get_instance() {
		if ( self::$instance === null ) {
			self::$instance = new self( 'wooless_general_settings_options' );
		}

		return self::$instance;
	}

	public function register_hooks() {
		add_filter( 'blaze_wooless_additional_site_info', array( $this, 'register_additional_site_info' ), 10, 1 );
		add_action( 'template_redirect', array( $this, 'redirect_non_admin_user' ), -1 );
		add_filter( 'rest_url', array( $this, 'overwrite_rest_url' ), 10 );

		add_filter( 'option_home', array( $this, 'maybe_remove_cart_from_site_address_url' ), 10, 2 );

		add_filter( 'post_link', array( $this, 'remove_cart_from_url' ), 10, 1 );
		add_filter( 'post_type_link', array( $this, 'remove_cart_from_url' ), 10, 1 );
		add_filter( 'page_link', array( $this, 'remove_cart_from_url' ), 10, 1 );
		add_filter( 'term_link', array( $this, 'remove_cart_from_url' ), 10, 1 );
	}

	/**
	 * Helper method to remove "cart." in url 
	 * @param mixed $url
	 * @return string
	 */
	public function remove_cart_from_url( $url ) {
		return rtrim( str_replace( '/cart.', '/', $url ), '/' );
	}

	/**
	 * Removes all "cart." in all links when the user is not on admin pages. 
	 * This function dynamically changes the wordpress site address url in general settings via option_home filter
	 * 
	 * @param mixed $value
	 * @param mixed $option
	 * @return mixed
	 */
	public function maybe_remove_cart_from_site_address_url( $value, $option ) {

		// skip the filter if the user is on admin pages
		if ( is_admin() ) {
			return $value;
		}

		return $this->remove_cart_from_url( $value );
	}

	/**
	 * Redirect non admin user to non cart.* url
	 * Hooked into template_redirect, priority -1
	 * 
	 * @since   1.5.0
	 * @return  void
	 */
	public function redirect_non_admin_user() {

		// skip redirect for administrator
		if ( is_user_logged_in() && current_user_can( 'manage_options' ) )
			return;

		// skip redirect for ajax request
		if ( is_ajax() ) {
			return;
		}


		// Redirect to home page if the user is not logged in and the page is cart 
		$restricted_pages = apply_filters( 'blaze_wooless_restricted_pages', is_cart() );
		if ( $restricted_pages ) {
			wp_redirect( $this->remove_cart_from_url( home_url() ) );
			exit;
		}

		$is_my_account_page                = strpos( $_SERVER['REQUEST_URI'], 'my-account' ) !== false;
		$exclude_page_redirect_to_frontend = apply_filters( 'blaze_wooless_exclude_page_redirect_to_frontend', is_checkout() );
		if ( $exclude_page_redirect_to_frontend || $is_my_account_page ) {
			//Since the page is excluded from redirecting to frontend then we just end the function here
			return;
		}

		$has_cart_in_url           = strpos( $_SERVER['SERVER_NAME'], 'cart.' ) !== false;
		$from_vercel_proxy_request = isset( $_SERVER['HTTP_X_VERCEL_PROXY_SIGNATURE'] ) ? true : false;

		// if the url has cart. on it and the request is not from vercel then we redirect it to frontend page without cart in the url
		if ( $has_cart_in_url && ! $from_vercel_proxy_request ) {
			wp_redirect( $this->remove_cart_from_url( home_url( $_SERVER['REQUEST_URI'] ) ) );
			exit;
		}

	}

	/**
	 * Overwrite rest url, so we can use guttenberg editor when the site url is different
	 * Hooked into rest_url, priority 10
	 * @param string $url
	 * @return string
	 */
	public function overwrite_rest_url( $url ) {
		$new_url = trailingslashit( get_option( 'siteurl' ) ) . 'wp-json';

		$url = str_replace( home_url( '/wp-json' ), $new_url, $url );

		return $url;
	}

	public function settings_callback( $options ) {
		if ( isset( $options['api_key'] ) ) {
			$encoded_api_key   = sanitize_text_field( $options['api_key'] );
			$decoded_api_key   = base64_decode( $encoded_api_key );
			$trimmed_api_key   = explode( ':', $decoded_api_key );
			$typesense_api_key = $trimmed_api_key[0];
			$store_id          = $trimmed_api_key[1];

			$typesense_client = TypesenseClient::get_instance();
			$connection       = $typesense_client->test_connection( $typesense_api_key, $store_id, $options['environment'] );

			if ( 'success' === $connection['status'] ) {
				// TODO: remove private_key_master eventually
				update_option( 'private_key_master', $options['api_key'] );
				update_option( 'typesense_api_key', $typesense_api_key );
				update_option( 'store_id', $store_id );

			} else {
				add_settings_error(
					'blaze_settings_error',
					esc_attr( 'settings_updated' ),
					$connection['message'],
					'error'
				);
			}
		}

		return $options;
	}

	public function settings() {
		$fields = array(
			'wooless_general_settings_section' => array(
				'label' => 'General Settings',
				'options' => array(
					array(
						'id' => 'environment',
						'label' => 'Environment',
						'type' => 'select',
						'args' => array(
							'description' => 'Select which environment to use.',
							'options' => array(
								'test' => 'Test',
								'live' => 'Live',
							),
						),
					),
					array(
						'id' => 'api_key',
						'label' => 'API Key',
						'type' => 'password',
						'args' => array(
							'description' => 'API Key generated from the Blaze Commerce Admin Portal.'
						),
					),
					array(
						'id' => 'shop_domain',
						'label' => 'Shop Domain',
						'type' => 'text',
						'args' => array(
							'description' => 'Live site domain. (e.g. website.com.au)'
						),
					),
				)
			),
		);

		if ( $this->connected() ) {
			$fields['wooless_general_settings_section']['options'][] = array(
				'id' => 'show_free_shipping_banner',
				'label' => 'Show free shipping banner',
				'type' => 'checkbox',
				'args' => array(
					'description' => 'Check this to show shipping banner dynamically based on nearest free shipping rate.'
				),
			);

			$fields['wooless_general_settings_section']['options'][] = array(
				'id' => 'show_free_shipping_minicart_component',
				'label' => 'Show free shipping minicart component',
				'type' => 'checkbox',
				'args' => array(
					'description' => 'Check this to show shipping minicart component dynamically based on nearest free shipping rate.'
				),
			);

			$fields['wooless_general_settings_section']['options'][] = array(
				'id' => 'show_variant_as_separate_product_cards',
				'label' => 'Display separate variant product cards',
				'type' => 'checkbox',
				'args' => array(
					'description' => 'Check this to show variant as product cards in catalog pages or in any product list.'
				),
			);

		}



		return $fields;
	}

	public function connected() {
		$typesense_api_key = get_option( 'typesense_api_key' );
		$store_id          = get_option( 'store_id' );
		$environment       = bw_get_general_settings( 'environment' );

		if ( empty( $typesense_api_key ) || empty( $store_id ) || empty( $environment ) ) {
			return false;
		}

		try {
			$connection = TypesenseClient::get_instance()->test_connection( $typesense_api_key, $store_id, $environment );
			return 'success' === $connection['status'];
		} catch (\Throwable $th) {
			return false;
		}
	}

	public function section_callback() {
		echo '<p>Select which areas of content you wish to display.</p>';
	}

	public function footer_callback() {
		$api_key = bw_get_general_settings( 'api_key' );
		if ( null !== $api_key && ! empty( $api_key ) ) :
			?>
			<a href="#" id="sync-product-link">Sync Products</a><br />
			<a href="#" id="sync-taxonomies-link">Sync Taxonomies</a><br />
			<a href="#" id="sync-menus-link">Sync Menus</a><br />
			<a href="#" id="sync-pages-link">Sync Pages</a><br />
			<a href="#" id="sync-site-info-link">Sync Site Info</a><br />
			<a href="#" id="sync-all-link">Sync All</a>
			<div id="sync-results-container"></div>

			<button id="redeploy" class="button button-primary">Redeploy Store Front</button>
			<?php
		endif;
	}

	public function register_additional_site_info( $additional_data ) {
		$additional_data['show_free_shipping_banner']              = json_encode( $this->get_option( 'show_free_shipping_banner' ) == 1 ?: false );
		$additional_data['show_free_shipping_minicart_component']  = json_encode( $this->get_option( 'show_free_shipping_minicart_component' ) == 1 ?: false );
		$additional_data['show_variant_as_separate_product_cards'] = json_encode( $this->get_option( 'show_variant_as_separate_product_cards' ) == 1 ?: false );

		return $additional_data;
	}
}

GeneralSettings::get_instance();
