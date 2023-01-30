<?php
/**
 * Storefront engine room
 *
 * @package storefront
 */

/**
 * Assign the Storefront version to a var
 */
$theme              = wp_get_theme( 'storefront' );
$storefront_version = $theme['Version'];

add_filter('use_block_editor_for_post', '__return_false', 10);
add_filter( 'jetpack_offline_mode', '__return_true' );

/**
 * Set the content width based on the theme's design and stylesheet.
 */
if ( ! isset( $content_width ) ) {
	$content_width = 980; /* pixels */
}

$storefront = (object) array(
	'version'    => $storefront_version,

	/**
	 * Initialize all the things.
	 */
	'main'       => require 'inc/class-storefront.php',
	'customizer' => require 'inc/customizer/class-storefront-customizer.php',
);

require 'inc/storefront-functions.php';
require 'inc/storefront-template-hooks.php';
require 'inc/storefront-template-functions.php';
require 'inc/wordpress-shims.php';

if ( class_exists( 'Jetpack' ) ) {
	$storefront->jetpack = require 'inc/jetpack/class-storefront-jetpack.php';
}

if ( storefront_is_woocommerce_activated() ) {
	$storefront->woocommerce            = require 'inc/woocommerce/class-storefront-woocommerce.php';
	$storefront->woocommerce_customizer = require 'inc/woocommerce/class-storefront-woocommerce-customizer.php';

	require 'inc/woocommerce/class-storefront-woocommerce-adjacent-products.php';

	require 'inc/woocommerce/storefront-woocommerce-template-hooks.php';
	require 'inc/woocommerce/storefront-woocommerce-template-functions.php';
	require 'inc/woocommerce/storefront-woocommerce-functions.php';
}

if ( is_admin() ) {
	$storefront->admin = require 'inc/admin/class-storefront-admin.php';

	require 'inc/admin/class-storefront-plugin-install.php';
}

/**
 * NUX
 * Only load if wp version is 4.7.3 or above because of this issue;
 * https://core.trac.wordpress.org/ticket/39610?cversion=1&cnum_hist=2
 */
if ( version_compare( get_bloginfo( 'version' ), '4.7.3', '>=' ) && ( is_admin() || is_customize_preview() ) ) {
	require 'inc/nux/class-storefront-nux-admin.php';
	require 'inc/nux/class-storefront-nux-guided-tour.php';
	require 'inc/nux/class-storefront-nux-starter-content.php';
}

/**
 * Function to retrieve the data required in the test from the csv 
 */
function get_postalcode($cap_input) {
  $file = fopen('comuni-localita-cap-italia.csv', 'r');
while (($line = fgetcsv($file)) !== FALSE) {
	if ($line[2] == $cap_input && !empty($cap_input)) { 
		$result = "found";
		$city = $line[0];
		$state = $line[1];
		$cap = $line[2];
		break;
	 } 
}
fclose($file);
return array($result,$city,$state,$cap);
}

/**
 * Function to retrieve user info when logged ex. country and cap
 */
function get_user_info_quisto($customer_id){
	$country = get_user_meta( $customer_id, 'billing_country', true );
	$cap = get_user_meta( $customer_id, 'billing_postcode', true );
	return array($country,$cap);
}

/**
 * Add Postal Code Field to the user registration form for Quisto test
 */

add_action( 'register_form', 'quisto_registration_form' );
function quisto_registration_form() {
	$postal_code = ! empty( $_POST['billing_postcode'] ) ? $_POST['billing_postcode'] : '';
	?>
	<p>
		<label for="Postal code"><?php esc_html_e( 'Postal code', 'quisto' ) ?><br/>
			<input type="text" name="billing_postcode" value="<?php echo esc_attr( $postal_code ); ?>" class="input" />
		</label>
	</p>
	<?php
}

/**
 * Return an ERROR if postal code insered is not in the csv file or the field is empty
 */
add_filter( 'registration_errors', 'quisto_registration_errors', 10, 3 );
function quisto_registration_errors( $errors, $sanitized_user_login, $user_email ) {
	        $cap_input = $_POST['billing_postcode'];
            $result = get_postalcode($cap_input);
			if($result[0] != "found" || empty($cap_input) ){ 
			$errors->add( 'zip_code_error', __( '<strong>POSTAL CODE ERROR</strong>: This field is required, please enter a valid italian zip code.','quisto' ) );
		} 
	return $errors;
}

/**
 * Updating usermeta so will be displayed also on the user page on the backend 
 */
add_action( 'user_register', 'quisto_user_register' );
function quisto_user_register( $user_id ) {
	$cap_input = $_POST['billing_postcode'];
    $result = get_postalcode($cap_input);
	if ( !empty($cap_input) ) {
		//If the user arrived here means that the CAP is italian so I set IT as country
		$country = "IT"; 
		update_user_meta( $user_id, 'billing_postcode', $cap_input);
		update_user_meta( $user_id, 'billing_city', $result[1] );
		update_user_meta( $user_id, 'billing_country', $country );
		update_user_meta( $user_id, 'billing_state', $result[2] );
	}
}

/**
 * For users who are not in a postal code in Italy beginning with “20”, they should receive an
error message at the time of checkout (using the default error UI of WooCommerce) saying
that purchases are not supported in your region yet
 */
add_action('woocommerce_checkout_process', 'postalcode_checkout_field_process');
function postalcode_checkout_field_process() {
    $result_info = get_user_info_quisto(get_current_user_id());
	$result_cap = substr($result_info[1], 0, 2);
    if ( $result_info[0] != 'IT' && $result_cap == '20'){
        wc_add_notice( 'Purchases are not supported in your region yet'  ,'error' );
    }
}

/**
 * For users who are in a postal code in Italy beginning with “00”, they should receive an
automatic 10% discount on all products.
 */
add_filter('woocommerce_product_get_price', 'assign_discount', 90, 2 );
add_filter('woocommerce_product_get_regular_price', 'assign_discount', 90, 2 );
function assign_discount( $price, $product ) {
	$result_info = get_user_info_quisto(get_current_user_id());
	$result_cap = substr($result_info[1], 0, 2);
    if ( is_user_logged_in() && $result_info[0] != 'IT' && $result_cap == '20' ) { 
        $price *= 0.9; // 10% OFF  
    }   
    return $price;   
}

/**
 * Show discount everywhere the product appear
 */
add_filter( 'woocommerce_get_price_html', 'change_displayed_sale_price_html', 10, 2 );
function change_displayed_sale_price_html( $price, $product ) {
	echo "10% OFF<br />" . $price;
}




