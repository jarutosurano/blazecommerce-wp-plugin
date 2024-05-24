<?php

namespace BlazeWooless;

class Revalidate {
	private static $instance = null;

	public static function get_instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	public function __construct() {
		add_action( 'ts_product_update', array( $this, 'revalidate_frontend_path' ), 10, 1 );
		add_action( 'next_js_revalidation_event', array( $this, 'do_next_js_revalidation_event' ), 10, 1 );
	}

	public function get_object_permalink( $id ) {
		list( $permalink, $post_name ) = get_sample_permalink( $id );
		$view_link                     = str_replace( array( '%pagename%', '%postname%' ), $post_name, $permalink );

		return $view_link;
	}

	public function revalidate_product_page( $product_id ) {
		$product_url = array(
			wp_make_link_relative( $this->get_object_permalink( $product_id ) )
		);

		$event_time = WC()->call_function( 'time' ) + 1;
		as_schedule_single_action( $event_time, 'next_js_revalidation_event', array( $product_url ), 'blaze-wooless', true, 1 );
	}

	public function revalidate_frontend_path( $product_id ) {
		if ( wp_is_post_revision( $product_id ) || wp_is_post_autosave( $product_id ) ) {
			return;
		}

		$this->revalidate_product_page( $product_id );
	}

	public function get_frontend_url() {
		return rtrim( str_replace( '/cart.', '/', site_url() ), '/' );
	}

	/**
	 * This function helps us update the next.js pages to show the updates stock and updated information of the product
	 * @params $urls array of string url endpoints. e.g ["/shop/", "/"]
	 */
	public function request_frontend_page_revalidation( $urls ) {
		$logger  = wc_get_logger();
		$context = array( 'source' => 'frontend-revalidation' );

		$logger->debug( '======= START REVALIDATION =======', $context );


		$wooless_frontend_url  = $this->get_frontend_url();
		$typesense_private_key = get_option( 'typesense_api_key' );

		$logger->debug( print_r( array(
			'wooless_frontend_url' => $wooless_frontend_url,
			'typesense_private_key' => $typesense_private_key
		), 1 ), $context );


		if ( empty( $wooless_frontend_url ) || empty( $typesense_private_key ) ) {
			// Dont revalidate because there is no secret token and frontend url for the request. 
			return null;
		}

		$curl         = curl_init();
		$curl_options = array(
			CURLOPT_URL => $wooless_frontend_url . '/api/revalidate',
			CURLOPT_RETURNTRANSFER => true,
			CURLOPT_ENCODING => '',
			CURLOPT_MAXREDIRS => 10,
			CURLOPT_TIMEOUT => 0,
			CURLOPT_FOLLOWLOCATION => true,
			CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
			CURLOPT_CUSTOMREQUEST => 'POST',
			CURLOPT_POSTFIELDS => '["' . implode( '","', $urls ) . '"]',
			CURLOPT_HTTPHEADER => array(
				'api-secret-token: ' . $typesense_private_key,
				'Content-Type: application/json'
			),
		);
		curl_setopt_array(
			$curl,
			$curl_options
		);

		$response = curl_exec( $curl );
		$err      = curl_error( $curl );

		curl_close( $curl );



		$logger->debug( 'Curl Options : ' . print_r( $curl_options, 1 ), $context );

		if ( $err ) {
			$logger->debug( 'Curl Error : ' . print_r( $err, 1 ), $context );

			throw new Exception( "cURL Error #:" . $err, 400 );
		}

		$response = json_decode( $response, true );
		$logger->debug( 'Curl Response : ' . print_r( $response, 1 ), $context );
		$logger->debug( '======= END REVALIDATION =======', $context );
		return $response;
	}

	/**
	 * @pararms $urls array of string url endpoints. e.g ["/shop/", "/"]
	 * @params $time we just use this so that the event will not be ignored by wp https://developer.wordpress.org/reference/functions/wp_schedule_single_event/#description
	 */
	public function do_next_js_revalidation_event( $urls ) {
		$this->request_frontend_page_revalidation( $urls );
	}

}

