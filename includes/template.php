<?php
	global $table_prefix, $wpdb;
	$sql = "SELECT * FROM ".$table_prefix."adv_credentials";
	$results = $wpdb->get_results($sql);
?>
<?php if(empty($results)){ ?>
	<style>
		.normal-data{ display: none; }
	</style>
<?php }else{ ?>
	<style>
		.cred-data{ display: none; }
	</style>
<?php } ?>

	<!--<h1>APP WORKS</h1>-->
	<div class="section">
		
		<div class="cred-data">
			<div class="section-summary">
				<h4>Generate your Account ID and API key in order to use the functionalities of this plugin.</h4>
				<p><a href="https://developer.advatix.net" target="_blank">Click Here</a> to get your credentials.</p>
			</div>
			<hr/>
			<div class="section-content cat-secx">
				<div class="section-row">
					<div class="section-cell all-cats">
						<form class="api_form">
							<div class="row">
								<div class="form-group">
									<label class="col-md-4">Account ID: </label>
									<div class="col-md-8">
										<input type="text" class="form-control" name="accountId" id="accountId" placeholder="Account ID" value="<?php echo $results[0]->accountId; ?>">
									</div>
								</div>
							</div>
							<div class="row">
								<div class="form-group">
									<label class="col-md-4">API Key: </label>
									<div class="col-md-8">
										<input type="text" class="form-control" name="apiKey" id="apiKey" placeholder="API Key" value="<?php echo $results[0]->apiKey; ?>">
									</div>
								</div>
							</div>
							<div class="row">
								<input type="submit" class="btn" name="submit" value="Submit">
							</div>
						</form>
					</div>
				</div>
			</div>
		</div>
		
		<div class="normal-data">
			<div class="section-summary">
				<a href="javascript:UpdateAPI()">Update API Keys</a>
				<h3>Keep your woocommerce store updated with Advatix OMS</h3>
			</div>
			<hr/>
			<div class="section-content cat-secx">
				<div class="section-row">
					<div class="section-cell all-cats">
						<!--<a href="javascript:sync_orders()">Sync Orders</a>-->
						<a href="javascript:sync_products()">Sync Products Inventory</a>
					</div>
				</div>
			</div>
		</div>
		
		<div class="section-content">
			<div class="section-row">
				<div class="section-cell loading-sec" style="text-align: center;">
					<img class="importing" src="<?php echo plugins_url('/assets/img/importing.gif', __FILE__); ?>" ><br/>
					<img class="loading" src="<?php echo plugins_url('/assets/img/loading.gif', __FILE__); ?>" ><br/>
					<!--<p>Huge data This may take up to 20 minutes. Hold tight and do not reload or navigate away.</p>-->
				</div>
			</div>
		</div>
	</div>

<script>
	jQuery(document).ready(function () {
		
		jQuery('.api_form').submit(function(e){
			e.preventDefault();
		});
		
		jQuery('.api_form .btn').click(function(){
			var accountId = jQuery('#accountId').val();
			var apiKey = jQuery('#apiKey').val();
			
			var error = "";
			
			if(accountId==''){
				error += 'Account ID is required\r\n';
			}
			if(apiKey==''){
				error += 'API Key is required';
			}
			
			if(error==''){
				jQuery('.loading-sec').show();
				jQuery('.loading').show();
				jQuery.ajax({
					 type : "post",
					 url : "<?php echo admin_url( 'admin-ajax.php' ); ?>",
					 timeout: 100000000000000,
					 data : { action: "adv_validate_api_creds", accountId: accountId, apiKey: apiKey },
					 success: function(res) {
							alert(res);
							jQuery('.loading-sec').hide();
							jQuery('.loading').hide();
							if(res){
								alert('Your credentials are verified.');
								location.reload();
							}else{
								alert('Failed! Try again later.');
							}
					 },
					 error: function ( xhr, status, error) {
						 jQuery('.loading-sec').hide();
						jQuery('.loading').hide();
						 alert('Failed! Try again later.');
						 console.log( " xhr.responseText: " + xhr.responseText + " //status: " + status + " //Error: "+error );
					}
				});
			}else{
				alert(error);
			}
		});
	});
	
	function UpdateAPI(){
		jQuery('.cred-data').toggle();
	}
	
	function sync_products(){
		jQuery('.loading-sec').show();
		jQuery('.loading').show();
		jQuery.ajax({
			 type : "post",
			 url : "<?php echo admin_url( 'admin-ajax.php' ); ?>",
			 timeout: 100000000000000,
			 data : { action: "adv_sync_products" },
			 success: function(res) {
				 jQuery('.loading-sec').hide();
				jQuery('.loading').hide();
				alert(res);
			 },
			 error: function ( xhr, status, error) {
				 jQuery('.loading-sec').hide();
				jQuery('.loading').hide();
				 alert('Try again later.');
				 console.log( " xhr.responseText: " + xhr.responseText + " //status: " + status + " //Error: "+error );
			}
		});
		
	}
</script>
