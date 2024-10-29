<?php
/*
	Plugin Name: Advatix OMS
	Description: Used for syncronization of woocommerce with Advatix OMS
	Author: Rajat Bhatia
	Author URI: https://rbhatia.vlwebsolutions.com
	Version: 1.0.0
*/

/**
 * Check if WooCommerce is active
 */
if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
	
	add_action( 'init', 'adv_init_functions', 20 );
	
	add_action( 'admin_menu', 'adv_include_admin_menus', 30 );
	
	function adv_init_functions() {
		wp_register_style( 'wpdocsPluginStylesheet', plugins_url('/includes/assets/css/style.css', __FILE__) );
		wp_enqueue_style( 'wpdocsPluginStylesheet' );
	}
	
	function adv_include_admin_menus() {
		add_menu_page( 'Advatix OMS', 'Advatix OMS', 'manage_options', 'advatix-oms', 'adv_main_menu_function','', 50 );
	}
	
	function adv_main_menu_function() {
		include(WP_PLUGIN_DIR .'/'. plugin_basename( dirname(__FILE__) ) .'/includes/template.php');
	}
	
	function adv_create_plugin_database_table()
	{
		global $table_prefix, $wpdb;

		$tblname = 'adv_sync_results';
		$wp_track_table = $table_prefix . "$tblname ";
		
		$tblname1 = 'adv_credentials';
		$wp_track_table1 = $table_prefix . "$tblname1 ";
		$charset_collate = $wpdb->get_charset_collate();

		#Check to see if the table exists already, if not, then create it

		if($wpdb->get_var( "show tables like '$wp_track_table'" ) != $wp_track_table) 
		{

			$sql = "CREATE TABLE $wp_track_table (
			  id mediumint(9) NOT NULL AUTO_INCREMENT,
			  sync_type varchar(55) DEFAULT '' NOT NULL,
			  last_sync varchar(255) DEFAULT '' NOT NULL,
			  PRIMARY KEY  (id)
			) $charset_collate;";

			require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
			dbDelta( $sql );
		}
		if($wpdb->get_var( "show tables like '$wp_track_table1'" ) != $wp_track_table1) 
		{

			$sql = "CREATE TABLE $wp_track_table1 (
			  id mediumint(9) NOT NULL AUTO_INCREMENT,
			  accountId varchar(100) DEFAULT '' NOT NULL,
			  apiKey varchar(500) DEFAULT '' NOT NULL,
			  PRIMARY KEY  (id)
			) $charset_collate;";

			require_once( ABSPATH . 'wp-admin/includes/upgrade.php' );
			dbDelta( $sql );
		}
		
		// Schedule an action if it's not already scheduled
		if ( ! wp_next_scheduled( 'adv_isa_add_every_minute' ) ) {
			wp_schedule_event( time(), 'every_minute', 'adv_isa_add_every_minute' );
		}
	}
	register_activation_hook( __FILE__, 'adv_create_plugin_database_table' );
	
	register_deactivation_hook( __FILE__, 'adv_my_deactivation' );
  	function adv_my_deactivation() {
		wp_clear_scheduled_hook( 'adv_isa_add_every_minute' );
	}
	
	add_filter( 'cron_schedules', 'adv_isa_add_every_minute' );
	function adv_isa_add_every_minute( $schedules ) {
		$schedules['every_minute'] = array(
				'interval'  => 60,
				'display'   => __( 'Every Minute', 'textdomain' )
		);
		return $schedules;
	}

	add_action( 'adv_isa_add_every_minute', 'adv_every_minute_event_func' );
	function adv_every_minute_event_func() {
		global $table_prefix, $wpdb;
		$sql = "SELECT * FROM ".$table_prefix."adv_credentials";
		$results = $wpdb->get_results($sql);
		
		if((!empty($results)) && ($results[0]->accountId != '') && ($results[0]->apiKey != '')){
			$apiKey = $results[0]->apiKey;
			$headers = array(
				'Content-Type' => 'application/json',
				'Device-Type' => 'Web',
				'Ver' => '1.0',
				'ApiKey' => $apiKey
			);
			
			$query = new WP_query(array(
						'post_type' => 'product',
						'posts_per_page' => -1)
					);
					
			while($query->have_posts()){
				$query->the_post();
				$product = wc_get_product( get_the_ID() );
				$sku = $product->get_sku();
				
				if($sku!=''){
					
					$cont = array(
								array( 'key' => 'WAREHOUSE', 'value' => '' ),
								array( 'key' => 'PRODUCT_CATEGORY', 'value' => '' ),
								array( 'key' => 'SKU', 'value' => $sku ),
							);
					$args = array(
						'headers' => $headers,
						'timeout' => 300000,
						'body' => $cont,
					);
					$res = wp_remote_post('http://developer.advatix.net/fep/api/v1/inventory/getLocationInventoryCountListing', $args );
					
					$return = json_decode($res['body']);
					$inv = $return->responseObject;
					
					if(!empty($inv->content)){
						update_post_meta( $product->get_id(), '_manage_stock', 'yes' );
						update_post_meta( $product->get_id(), '_stock', $inv->content[0]->availableToPromise );
					}else{
						
						
					}
				}
			}
			
			$query = new WC_Order_Query( array(
						'return' => 'ids',
					) );
			$orders = $query->get_orders();
			
			foreach($orders as $k=>$v){
				
				$args = array(
					'headers' => $headers,
					'timeout' => 300000
				);
				$res = wp_remote_get('http://developer.advatix.net/xpdel/api/v1/order/getOrderTracking?orderNumber=wc-'.$v, $args );
				
				$return = json_decode($res['body']);
				$inv = $return->responseObject;
				
				
				
				if(!empty($inv)){
					$order = new WC_Order($v);
					
					if($inv[0]->orderStatusDesc=='Created'){
						$order->update_status('created');
					}
					if($inv[0]->orderStatusDesc=='Assigned'){
						$order->update_status('assigned');
					}
					if($inv[0]->orderStatusDesc=='Picked'){
						$order->update_status('picked');
					}
					if($inv[0]->orderStatusDesc=='Packaging'){
						$order->update_status('packaging');
					}
					if($inv[0]->orderStatusDesc=='Cancelled'){
						$order->update_status('cancelled');
					}
					if($inv[0]->orderStatusDesc=='Shipped'){
						$order->update_status('shipped');
					}
					if($inv[0]->orderStatusDesc=='Delivered'){
						$order->update_status('delivered');
					}
				}
			}
		}
	}
	
	function adv_remove__status( $statuses ){
		if( isset( $statuses['wc-processing'] ) ){
			unset( $statuses['wc-processing'] );
		}
		if( isset( $statuses['wc-pending'] ) ){
			unset( $statuses['wc-pending'] );
		}
		if( isset( $statuses['wc-on-hold'] ) ){
			unset( $statuses['wc-on-hold'] );
		}
		if( isset( $statuses['wc-completed'] ) ){
			unset( $statuses['wc-completed'] );
		}
		if( isset( $statuses['wc-refunded'] ) ){
			unset( $statuses['wc-refunded'] );
		}
		return $statuses;
	}
	add_filter( 'wc_order_statuses', 'adv_remove__status' );
	
	// Register new status
	function adv_register_created_order_status() {
		register_post_status( 'wc-created', array(
			'label'                     => 'Created',
			'public'                    => true,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			'label_count'               => _n_noop( 'Created (%s)', 'Created (%s)' )
		) );
		
		register_post_status( 'wc-assigned', array(
			'label'                     => 'Assigned',
			'public'                    => true,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			'label_count'               => _n_noop( 'Assigned (%s)', 'Assigned (%s)' )
		) );
		
		register_post_status( 'wc-picked', array(
			'label'                     => 'Picked',
			'public'                    => true,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			'label_count'               => _n_noop( 'Picked (%s)', 'Picked (%s)' )
		) );
		
		register_post_status( 'wc-packaging', array(
			'label'                     => 'Packaging',
			'public'                    => true,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			'label_count'               => _n_noop( 'Packaging (%s)', 'Packaging (%s)' )
		) );
		
		register_post_status( 'wc-shipped', array(
			'label'                     => 'Shipped',
			'public'                    => true,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			'label_count'               => _n_noop( 'Shipped (%s)', 'Shipped (%s)' )
		) );
		
		register_post_status( 'wc-delivered', array(
			'label'                     => 'Delivered',
			'public'                    => true,
			'exclude_from_search'       => false,
			'show_in_admin_all_list'    => true,
			'show_in_admin_status_list' => true,
			'label_count'               => _n_noop( 'Delivered (%s)', 'Delivered (%s)' )
		) );
	}
	add_action( 'init', 'adv_register_created_order_status' );
	
	// Add to list of WC Order statuses
	function adv_add_created_to_order_statuses( $order_statuses ) {
	 
		$new_order_statuses = array();
	 
		// add new order status after processing
		// foreach ( $order_statuses as $key => $status ) {
	 
			// $new_order_statuses[ $key ] = $status;
	 
			$new_order_statuses['wc-created'] = 'Created';
			$new_order_statuses['wc-assigned'] = 'Assigned';
			$new_order_statuses['wc-picked'] = 'Picked';
			$new_order_statuses['wc-packaging'] = 'Packaging';
			$new_order_statuses['wc-shipped'] = 'Shipped';
			$new_order_statuses['wc-delivered'] = 'Delivered';
			$new_order_statuses['wc-cancelled'] = 'Cancelled';
			$new_order_statuses['wc-failed'] = 'Failed';
			
		// }
	 
		return $new_order_statuses;
	}
	add_filter( 'wc_order_statuses', 'adv_add_created_to_order_statuses' );
	
	add_action( 'woocommerce_checkout_order_processed', 'adv_changing_order_status_before_payment', 10, 3 );
	function adv_changing_order_status_before_payment( $order_id, $posted_data, $order ){
		$order->update_status( 'created' );
	}
	
	function adv_custom_meta_box_markup($object)
	{
		wp_nonce_field(basename(__FILE__), "meta-box-nonce");
		
		$atts = array(
					'TEXT' => 'text',
					'NUMBER' => 'number',
					'BOOLEAN' => 'checkbox',
					'DECIMAL' => 'number',
					'URL' => 'url',
				);
		
		$args = array(
					'headers' => array( 'Device-Type' => 'Web', 'Ver' => '1.0' ),
					'timeout' => 300000
				);
		$res = wp_remote_get('http://developer.advatix.net/fep/api/v1/config/getAllProductAttributes?productCategoryId=1', $args );
		$return = json_decode($res['body']);
		$response = $return->responseObject;
		
		echo '<div>';
		if(!empty($response)){
			foreach($response as $k=>$v){
				$meta_val = get_post_meta($object->ID, str_replace(' ','_',$v->attribute), true);
				echo '<p style="display: flex;">';
				if($atts[$v->inputType]=='checkbox'){
					if(!empty($meta_val)){
						echo '<label style="width: 15%;" for="'.str_replace(' ','_',$v->attribute).'">'.$v->attribute.'</label><input name="'.str_replace(' ','_',$v->attribute).'" type="'.$atts[$v->inputType].'" value="1" checked>';
					}else{
						echo '<label style="width: 15%;" for="'.str_replace(' ','_',$v->attribute).'">'.$v->attribute.'</label><input name="'.str_replace(' ','_',$v->attribute).'" type="'.$atts[$v->inputType].'" value="1">';
					}
				}else{
					echo '<label style="width: 15%;" for="'.str_replace(' ','_',$v->attribute).'">'.$v->attribute.'</label><input style="width: 75%;" required name="'.str_replace(' ','_',$v->attribute).'" type="'.$atts[$v->inputType].'" value="'.$meta_val.'">';
				}
				echo '</p>';
			}
		}
		echo '</div>';
		?>
		<?php  
	}

	function adv_add_custom_meta_box()
	{
		add_meta_box("demo-meta-box", "Attributes", "adv_custom_meta_box_markup", "product");
	}
	add_action("add_meta_boxes", "adv_add_custom_meta_box");
	
	function adv_save_custom_meta_box($post_id, $post, $update)
	{
		if (!isset($_POST["meta-box-nonce"]) || !wp_verify_nonce($_POST["meta-box-nonce"], basename(__FILE__)))
			return $post_id;

		if(!current_user_can("edit_post", $post_id))
			return $post_id;

		if(defined("DOING_AUTOSAVE") && DOING_AUTOSAVE)
			return $post_id;

		$slug = "product";
		if($slug != $post->post_type)
			return $post_id;

		$args = array(
					'headers' => array( 'Device-Type' => 'Web', 'Ver' => '1.0' ),
					'timeout' => 300000
				);
		$res = wp_remote_get('http://developer.advatix.net/fep/api/v1/config/getAllProductAttributes?productCategoryId=1', $args );
		$return = json_decode($res['body']);
		$att_response = $return->responseObject;
		
		foreach($att_response as $k=>$v){
			if(isset($_POST[str_replace(' ','_',$v->attribute)]))
			{
				$meta_box_text_value = $_POST[str_replace(' ','_',$v->attribute)];
			}   
			update_post_meta($post_id, str_replace(' ','_',$v->attribute), $meta_box_text_value);
		}

	}

	add_action("save_post", "adv_save_custom_meta_box", 10, 3);
	

	add_action('woocommerce_new_order', function ($order_id) {
		global $table_prefix, $wpdb;
		$sql = "SELECT * FROM ".$table_prefix."adv_credentials";
		$results = $wpdb->get_results($sql);
		
		if((!empty($results)) && ($results[0]->accountId != '') && ($results[0]->apiKey != '')){
		
			$order = wc_get_order( $order_id );
			
			foreach($order->get_items() as $k=>$v){
				$product = wc_get_product( $v->get_product_id() );
				$orderItems[] = array(
									'sku' => $product->get_sku(),
									'quantity' => $v->get_quantity(),
									'price' => $v->get_total(),
								);
			}
			$p_method = $order->get_payment_method();
			$p_status = $order->get_status();
			
			if($p_method=='cod'){
				$paymentMode = 2;
			}elseif($p_method=='cheque'){
				$paymentMode = 2;
			}elseif($p_method=='bacs'){
				$paymentMode = 2;
			}else{
				$paymentMode = 1;
			}
			
			if($p_status=='pending'){
				$paymentStatus = 0;
			}else{
				$paymentStatus = 1;
			}

			$ord_data = array(
							"companyName" => "Garten",
							'accountId' => $results[0]->accountId,
							"providerId" => "1",
							"cxPhone" => "516-530-9111",
							"cxEmail" => "info@cxEmail.com",
							'orderType' => 2,
							'addressType' => 'Residential',
							'referenceId' => 'wc-'.$order->get_id(),
							'shipToName' => $order->shipping_first_name.' '.$order->shipping_last_name,
							'shipToAddress' => $order->shipping_address_1,
							'shipToAddress2' => $order->shipping_address_2,
							'shipToCity' => $order->shipping_city,
							'shipToCountry' => 'USA',
							'shipToEmail' => $order->billing_email,
							'shipToMobile' => $order->billing_phone,
							'shipToState' => $order->shipping_state,
							'postalCode' => $order->shipping_postcode,
							'billToName' => $order->billing_first_name.' '.$order->billing_last_name,
							'billToAddress' => $order->billing_address_1,
							'billToAddress2' => $order->billing_address_2,
							'billToCity' => $order->billing_city,
							'billToCountry' => 'USA',
							'billToEmail' => $order->billing_email,
							'billToMobile' => $order->billing_phone,
							'billToState' => $order->billing_state,
							'billToPostal' => $order->billing_postcode,
							'addtionalCharges' => 0,
							'paymentMode' => $paymentMode,
							'paymentStatus' => $paymentStatus,
							'instructions' => "Order testing on training",
							'orderItems' => $orderItems,
						);
			
			
			$apiKey = $results[0]->apiKey; 
			$headers = array(
				'Content-Type' => 'application/json',
				'Device-Type' => 'Web',
				'Ver' => '1.0',
				'ApiKey' => $apiKey
			);
			
			$args = array(
				'headers' => $headers,
				'timeout' => 300000,
				'body' => $ord_data
			);
			$res = wp_remote_post('http://developer.advatix.net/fep/api/v1/order/createOrder', $args );
			
			$return = json_decode($res['body']);
			$response = $return->responseObject;
			
			if($response->ordersList[0]->orderId!=''){
				update_post_meta($order_id, '_adv_order_trackingNumber', $response->ordersList[0]->trackingNumber);
			}
		
		}
	}, 10, 1);
	
	// define the woocommerce_update_order callback 
	function adv_action_woocommerce_update_order( $order_get_id ) {
		global $table_prefix, $wpdb;
		$sql = "SELECT * FROM ".$table_prefix."adv_credentials";
		$results = $wpdb->get_results($sql);
		
		if((!empty($results)) && ($results[0]->accountId != '') && ($results[0]->apiKey != '')){
		
			$order = wc_get_order( $order_get_id );
			
			foreach($order->get_items() as $k=>$v){
				$product = wc_get_product( $v->get_product_id() );
				$orderItems[] = array(
									'sku' => $product->get_sku(),
									'quantity' => $v->get_quantity(),
									'price' => $v->get_total(),
								);
			}
			$p_method = $order->get_payment_method();
			$p_status = $order->get_status();
			
			if($p_method=='cod'){
				$paymentMode = 2;
			}elseif($p_method=='cheque'){
				$paymentMode = 2;
			}elseif($p_method=='bacs'){
				$paymentMode = 2;
			}else{
				$paymentMode = 1;
			}
			
			if($p_status=='pending'){
				$paymentStatus = 0;
			}else{
				$paymentStatus = 1;
			}

			$ord_data = array(
							"companyName" => "Garten",
							'accountId' => $results[0]->accountId,
							"providerId" => "1",
							"cxPhone" => "516-530-9111",
							"cxEmail" => "info@cxEmail.com",
							'orderType' => 2,
							'addressType' => 'Residential',
							'referenceId' => 'wc-'.$order->get_id(),
							"orderNumber" => 'wc-'.$order->get_id(),
							'shipToName' => $order->shipping_first_name.' '.$order->shipping_last_name,
							'shipToAddress' => $order->shipping_address_1,
							'shipToAddress2' => $order->shipping_address_2,
							'shipToCity' => $order->shipping_city,
							'shipToCountry' => 'USA',
							'shipToEmail' => $order->billing_email,
							'shipToMobile' => $order->billing_phone,
							'shipToState' => $order->shipping_state,
							'postalCode' => $order->shipping_postcode,
							'billToName' => $order->billing_first_name.' '.$order->billing_last_name,
							'billToAddress' => $order->billing_address_1,
							'billToAddress2' => $order->billing_address_2,
							'billToCity' => $order->billing_city,
							'billToCountry' => 'USA',
							'billToEmail' => $order->billing_email,
							'billToMobile' => $order->billing_phone,
							'billToState' => $order->billing_state,
							'billToPostal' => $order->billing_postcode,
							'addtionalCharges' => 0,
							'paymentMode' => $paymentMode,
							'paymentStatus' => $paymentStatus,
							'instructions' => "Order testing on training",
							'orderItems' => $orderItems,
						);
			
			
			$apiKey = $results[0]->apiKey;
			$headers = array(
				'Content-Type' => 'application/json',
				'Device-Type' => 'Web',
				'Ver' => '1.0',
				'ApiKey' => $apiKey
			);
			
			$args = array(
				'headers' => $headers,
				'timeout' => 300000,
				'body' => $ord_data
			);
			$res = wp_remote_put('http://developer.advatix.net/fep/api/v1/order/updateOrder', $args );
			
			$return = json_decode($res['body']);
			$response = $return->responseObject;
		}
	}; 
	add_action( 'woocommerce_update_order', 'adv_action_woocommerce_update_order', 10, 1 );
	
	add_action( 'added_post_meta', 'adv_sync_on_product_save', 10, 4 );
	add_action( 'updated_post_meta', 'adv_sync_on_product_save', 10, 4 );
	function adv_sync_on_product_save( $meta_id, $post_id, $meta_key, $meta_value ) {
		if ( $meta_key == '_edit_lock' ) { // we've been editing the post
			if ( get_post_type( $post_id ) == 'product' ) { // we've been editing a product
				global $table_prefix, $wpdb;
				$sql = "SELECT * FROM ".$table_prefix."adv_credentials";
				$results = $wpdb->get_results($sql);
				
				if((!empty($results)) && ($results[0]->accountId != '') && ($results[0]->apiKey != '')){
				
					$product = wc_get_product( $post_id );
					$terms = get_the_terms( $product->get_id(), 'product_cat' );
					$attributes = $product->get_attributes();
					
					$args = array(
								'headers' => array( 'Device-Type' => 'Web', 'Ver' => '1.0' ),
								'timeout' => 300000
							);
					$res = wp_remote_get('http://developer.advatix.net/fep/api/v1/config/getAllProductAttributes?productCategoryId=1', $args );
					$return = json_decode($res['body']);
					$att_response = $return->responseObject;
					
					
					$featured = wp_get_attachment_url( $product->get_image_id() );
					
					$catts = array(
						'Ambient' => 1,
						'Product' => 2,
						'Cold' => 3,
						'Frozen' => 4,
						'Hazardous' => 5,
						'Equipment' => 6,
					);
					if(empty($catts[$terms[0]->name])){
						$catt = 1;
					}else{
						$catt = $catts[$terms[0]->name];
					}
		
					// foreach($attributes as $k=>$v){
						// $att = $v->get_data();
						
						// $att_name = $att['name'];
						// $att_val = $att['options'][0];
						// break;
					// }
					
					if($product->get_sku()!=''){
						$apiKey = $results[0]->apiKey; 
						$headers = array(
							'Device-Type' => 'Web',
							'Ver' => '1.0',
							'ApiKey' => $apiKey
						);
						
						if(empty(get_post_meta($product->get_id(),'UPC',true))){
							$upc = 0;
						}else{
							$upc = get_post_meta($product->get_id(),'UPC',true);
						}
						
						$args = array(
							'headers' => $headers,
							'timeout' => 300000
						);
						$res = wp_remote_get('http://developer.advatix.net/fep/api/v1/product/findByUpc?upc='.$upc, $args );
						
						$return = json_decode($res['body']);
						$check_prod = $return->responseObject;
						
						$headers = array(
							'Content-Type' => 'application/json',
							'Device-Type' => 'Web',
							'Ver' => '1.0',
							'ApiKey' => $apiKey
						);
					
						if(empty($check_prod)){
							
							if(empty($att_response)){
								$payload = '[
												{
													"attributes": [
														{
															"attributeKey": 1,
															"attributeValue": "Grocery",
															"inputType": "TEXT",
															"mandatory": true,
															"attribute": "TYPE",
															"error": ""
														},
														{
															"attributeKey": 2,
															"attributeValue": "0012",
															"inputType": "TEXT",
															"mandatory": true,
															"attribute": "UPC",
															"error": ""
														},
														{
															"attributeKey": 3,
															"attributeValue": "oz",
															"inputType": "TEXT",
															"mandatory": true,
															"attribute": "UOM",
															"error": ""
														},
														{
															"attributeKey": 4,
															"attributeValue": "1",
															"inputType": "NUMBER",
															"mandatory": true,
															"attribute": "MIN",
															"error": ""
														},
														{
															"attributeKey": 5,
															"attributeValue": "1000",
															"inputType": "NUMBER",
															"mandatory": true,
															"attribute": "MAX",
															"error": ""
														},
														{
															"attributeKey": 6,
															"attributeValue": "0",
															"inputType": "BOOLEAN",
															"mandatory": true,
															"attribute": "EXPIRY DATE REQUIRED",
															"error": ""
														},
														{
															"attributeKey": 7,
															"attributeValue": "0",
															"inputType": "NUMBER",
															"mandatory": false,
															"attribute": "MINIMUM EXPIRATION DAYS",
															"error": ""
														},
														{
															"attributeKey": 8,
															"attributeValue": "10",
															"inputType": "DECIMAL",
															"mandatory": true,
															"attribute": "WEIGHT",
															"error": ""
														},
														{
															"attributeKey": 9,
															"attributeValue": "1",
															"inputType": "NUMBER",
															"mandatory": true,
															"attribute": "PACKAGE_TYPE",
															"error": ""
														},
														{
															"attributeKey": 10,
															"attributeValue": "'.$featured.'",
															"inputType": "URL",
															"mandatory": false,
															"attribute": "IMAGES",
															"error": ""
														},
														{
															"attributeKey": 11,
															"attributeValue": "",
															"inputType": "URL",
															"mandatory": false,
															"attribute": "VIDEOS",
															"error": ""
														},
														{
															"attributeKey": 12,
															"attributeValue": "0",
															"inputType": "DECIMAL",
															"mandatory": true,
															"attribute": "UNIT_PRICE",
															"error": ""
														}
													],
													"productDesc": "'.$product->get_description().'",
													"productName": "'.$product->get_title().'",
													"productType": "1",
													"productCategory": "'.$catt.'",
													"sku": "'.$product->get_sku().'",
													"accountId": "'.$results[0]->accountId.'"
												}
											]';

							}else{
								
								$payload = '[
												{
													"attributes": [';
													
													foreach($att_response as $k=>$v){
														if($v->inputType=='BOOLEAN'){
															$met_val = get_post_meta($product->get_id(), str_replace(' ','_',$v->attribute), true);
															if(empty($met_val)){
																$met_val = 0;
															}else{
																$met_val = 1;
															}
															$payload .='{
																			"attributeKey": '.$v->id.',
																			"attributeValue": "'.$met_val.'",
																			"inputType": "'.$v->inputType.'",
																			"mandatory": '.$v->mandatory.',
																			"attribute": "'.$v->attribute.'",
																			"error": ""
																		},';
														}else{
															$payload .='{
																			"attributeKey": '.$v->id.',
																			"attributeValue": "'.get_post_meta($product->get_id(), str_replace(' ','_',$v->attribute), true).'",
																			"inputType": "'.$v->inputType.'",
																			"mandatory": '.$v->mandatory.',
																			"attribute": "'.$v->attribute.'",
																			"error": ""
																		},';
														}
													}
														
													$payload .='],
													"productDesc": "'.$product->get_description().'",
													"productName": "'.$product->get_title().'",
													"productType": "1",
													"productCategory": "'.$catt.'",
													"sku": "'.$product->get_sku().'",
													"accountId": "'.$results[0]->accountId.'"
												}
											]';
							}
							
							$args = array(
								'headers' => $headers,
								'timeout' => 300000,
								'body' => json_decode($payload, true),
							);
							$res = wp_remote_post('http://developer.advatix.net/fep/api/v1/product/createProducts', $args );
							
							$return = json_decode($res['body']);
							$response = $return->responseObject;

							if($response->id!=''){
								update_post_meta($post_id,'_adv_product_id',$response->id);
							}
						}else{
							$payload = '{
											"attributes": [
												{
													"attributeKey": 1,
													"attributeValue": "'.$attributes['type']->get_data()['options'][0].'"
												},
												{
													"attributeKey": 2,
													"attributeValue": "'.$attributes['upc']->get_data()['options'][0].'"
												},
												{
													"attributeKey": 3,
													"attributeValue": "'.$attributes['uom']->get_data()['options'][0].'"
												},
												{
													"attributeKey": 4,
													"attributeValue": "'.$attributes['min']->get_data()['options'][0].'"
												},
												{
													"attributeKey": 5,
													"attributeValue": "'.$attributes['max']->get_data()['options'][0].'"
												},
												{
													"attributeKey": 6,
													"attributeValue": "'.$attributes['expiry-date-required']->get_data()['options'][0].'"
												},
												{
													"attributeKey": 7,
													"attributeValue": "'.$attributes['minimum-expiration-days']->get_data()['options'][0].'"
												},
												{
													"attributeKey": 8,
													"attributeValue": "'.$attributes['weight']->get_data()['options'][0].'"
												},
												{
													"attributeKey": 9,
													"attributeValue": "'.$attributes['package_type']->get_data()['options'][0].'"
												},
												{
													"attributeKey": 10,
													"attributeValue": "'.$featured.'"
												},
												{
													"attributeKey": 11,
													"attributeValue": ""
												},
												{
													"attributeKey": 12,
													"attributeValue": "'.$attributes['unit_price']->get_data()['options'][0].'"
												}
											],
											"productDesc": "'.$product->get_description().'",
											"productName": "'.$product->get_title().'",
											"productType": "1",
											"productCategory": "'.$catt.'",
											"sku": "'.$product->get_sku().'",
											"accountId": "'.$results[0]->accountId.'"
										}';
							
							$args = array(
								'headers' => $headers,
								'timeout' => 300000,
								'body' => json_decode($payload, true),
							);
							$res = wp_remote_post('http://developer.advatix.net/fep/api/v1/product/updateProduct', $args );
							
							$return = json_decode($res['body']);
							$response = $return->responseObject;

						}
					}
				}
			}
		}
	}
	
	add_action("wp_ajax_adv_sync_products", "adv_sync_products");
	add_action("wp_ajax_nopriv_adv_sync_products", "adv_sync_products");
	function adv_sync_products() {
		global $table_prefix, $wpdb;
		$sql = "SELECT * FROM ".$table_prefix."adv_credentials";
		$results = $wpdb->get_results($sql);
		
		if((!empty($results)) && ($results[0]->accountId != '') && ($results[0]->apiKey != '')){
		
			$apiKey = $results[0]->apiKey; 
			$headers = array(
				'Content-Type' => 'application/json',
				'Device-Type' => 'Web',
				'Ver' => '1.0',
				'ApiKey' => $apiKey
			);
			
			$query = new WP_query(array(
						'post_type' => 'product',
						'posts_per_page' => -1)
					);
					
			while($query->have_posts()){
				$query->the_post();
				$product = wc_get_product( get_the_ID() );
				$sku = $product->get_sku();
				
				if($sku!=''){
					$cont = array(
								array( 'key' => 'WAREHOUSE', 'value' => '' ),
								array( 'key' => 'PRODUCT_CATEGORY', 'value' => '' ),
								array( 'key' => 'SKU', 'value' => $sku ),
							);
					$args = array(
						'headers' => $headers,
						'timeout' => 300000,
						'body' => $cont,
					);
					$res = wp_remote_post('http://developer.advatix.net/fep/api/v1/inventory/getLocationInventoryCountListing', $args );
					
					$return = json_decode($res['body']);
					$inv = $return->responseObject;
					
					if(!empty($inv->content)){
						// echo "<pre>";
						// print_r($inv->content[0]->availableToPromise);
						// echo "</pre>";
						update_post_meta( $product->get_id(), '_manage_stock', 'yes' );
						update_post_meta( $product->get_id(), '_stock', $inv->content[0]->availableToPromise );
						
						echo true;
						
					}else{
						echo false;
						
					}
				}
			}
			
			echo true;
		
		}
		die();
	}

	add_action("wp_ajax_adv_validate_api_creds", "adv_validate_api_creds");
	add_action("wp_ajax_nopriv_adv_validate_api_creds", "adv_validate_api_creds");
	function adv_validate_api_creds() {
		global $table_prefix, $wpdb;
		
		$sql = "SELECT * FROM ".$table_prefix."adv_credentials";
		$results = $wpdb->get_results($sql);
		
		$accountId = sanitize_text_field($_POST['accountId']);
		$apiKey = sanitize_text_field($_POST['apiKey']);
		$headers = array(
			'Content-Type' => 'application/json',
			'Device-Type' => 'Web',
			'Ver' => '1.0',
		);

		$cont = array(
					'accountId' => $accountId,
					'apiKey' => $apiKey,
				);
		
		$args = array(
				'headers' => $headers,
				'timeout' => 300000,
				'body' => $cont
			);
		$res = wp_remote_post('http://developer.advatix.net/fep/api/v1/account/validateApiKey', $args );
		$response = json_decode($res['body']);
		
		$responseStatus = $response->responseStatus;
		$responseStatusCode = $response->responseStatusCode;
		$responseObject = $response->responseObject;
		
		if(($responseStatus) && ($responseStatusCode == '200') && ($responseObject == 'Api Key is Valid')){
			if((!empty($results)) && ($results[0]->id != '')){
				$wpdb->update($table_prefix.'adv_credentials', array(
					'accountId' => $accountId,
					'apiKey' => $apiKey,
				),array('id'=>$results[0]->id));
			}else{
				$wpdb->insert($table_prefix.'adv_credentials', array(
					'accountId' => $accountId,
					'apiKey' => $apiKey,
				));
			}
			echo true;
		}else{
			echo false;
		}
		
		die();
	}

}