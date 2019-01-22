<?php

/*
Plugin Name: WooCommerce Payment Gateway - BurstPay
Description: Accept Burstcoin via BurstPay in your WooCommerce store
Version: 1.0.0
Author: BurstPay
License: MIT License
License URI: https://github.com/burstpay/woocommerce-plugin/blob/master/LICENSE
Github Plugin URI: https://github.com/burstpay/woocommerce-plugin
*/

add_action('plugins_loaded', 'burstpay_init');

define('BURSTPAY_WOOCOMMERCE_VERSION', '1.0.0');

function burstpay_init()
{
  if (!class_exists('WC_Payment_Gateway')) {
    return;
  };

  define('PLUGIN_DIR', plugins_url(basename(plugin_dir_path(__FILE__)), basename(__FILE__)).'/');



  class WC_Gateway_Burstpay extends WC_Payment_Gateway
  {
    public function __construct()
    {
      global $woocommerce;

      $this->id = 'burstpay';
      $this->has_fields = false;
      $this->method_title = 'BurstPay';
      $this->icon = apply_filters('woocommerce_paypal_icon', PLUGIN_DIR.'assets/burstcoin.png');

      $this->init_form_fields();
      $this->init_settings();

      $this->title = $this->get_option('title');
      $this->description = $this->get_option('description');
      $this->burstcoin_address = $this->get_option('burstcoin_address');
      $this->receive_currency = $this->get_option('receive_currency');
      $this->order_statuses = $this->get_option('order_statuses');

      add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
      add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'save_order_statuses'));
      add_action('woocommerce_thankyou_burstpay', array($this, 'thankyou'));
      add_action('woocommerce_api_wc_gateway_burstpay', array($this, 'payment_callback'));
    }

    public function admin_options()
    {
      ?>
      <h3><?php _e('BurstPay', 'woothemes'); ?></h3>
      <p><?php _e('Accept BurstCoin through the burstpay and receive payments in HK dollars.<br>
       Support &middot; <a href="mailto:support@burstpay.io">support@burstpay.io</a>', 'woothemes'); ?></p>
      <table class="form-table">
        <?php $this->generate_settings_html(); ?>
      </table>
      <?php

    }

    public function init_form_fields()
    {
      $this->form_fields = array(
        'enabled' => array(
          'title' => __('Enable BurstPay', 'woocommerce'),
          'label' => __('Enable Burstcoin payment via BurstPay', 'woocommerce'),
          'type' => 'checkbox',
          'description' => '',
          'default' => 'no',
        ),
        'description' => array(
          'title' => __('Description', 'woocommerce'),
          'type' => 'textarea',
          'description' => __('This controls the description which the user sees during checkout.', 'woocommerce'),
          'default' => __('Pay with Burstcoin via BurstPay'),
        ),
        'title' => array(
          'title' => __('Title', 'woocommerce'),
          'type' => 'text',
          'description' => __('Payment method title that the customer will see on your website.', 'woocommerce'),
          'default' => __('Burstcoin', 'woocommerce'),
        ),
        'burstcoin_address' => array(
          'title' => __('Burstcoin wallet ID, ex: BURST-EMPD-KN81-BZP7-7DM6Z', 'woocommerce'),
          'type' => 'text',
          'description' => __('BurstCoin Account', 'woocommerce'),
          'default' => '',
        ),
          'receive_currency' => array(
          'title' => __('Receive Currency', 'woocommerce'),
          'type' => 'select',
          'options' => array(
            'BURST' => __('Burstcoin (฿)', 'woocommerce'),
            'USD' => __('US Dollars ($)', 'woocommerce'),
            'HKD' => __('HK Dollars ($)', 'woocommerce')
          ),
          'description' => __('The currency you use for your products. The Price is automaticaly converted to BURST', 'woocomerce'),
          'default' => 'HKD',
        ),

      );
    }

    public function thankyou()
    {
      if ($description = $this->get_description()) {
        echo wpautop(wptexturize($description));
      }
    }

    public function process_payment($order_id)
    {
      global $woocommerce, $page, $paged;
      $order = new WC_Order($order_id);

      $this->init_burstpay();

      $token = get_post_meta($order->id, 'burstpay_order_token', true);

      if ($token == '') {
        $bytes = openssl_random_pseudo_bytes(32);
        $token=rtrim(strtr(base64_encode($bytes), '+/', '0a'), '=');

        update_post_meta($order_id, 'burstpay_order_token', $token);
      }

    
     
        $address=$this->burstcoin_address;
        $amount=number_format($order->get_total(), 2, '.', '');
        $currency=$this->receive_currency;
        $return=add_query_arg('order', $order->id, add_query_arg('key', $order->order_key, get_permalink(woocommerce_get_page_id('thanks'))));
        $notify=trailingslashit(get_bloginfo('wpurl')).'?wc-api=wc_gateway_burstpay';
        $info=get_bloginfo('name', 'raw').' Order #'.$order->id;
        $custom=$order->id;

         $bind=array("amount"=>$amount, "currency"=>$currency, "secret"=>$token, "address"=>$address, "notify_url"=>$notify, "return_url"=>$return, "custom"=>$custom, "info" => $info, "rfid"=>"woocommerce");


      $args = array(
          'body' => $bind,
          'timeout' => '5',
          'redirection' => '5',
          'httpversion' => '1.0',
          'blocking' => true,
          'headers' => array(),
          'cookies' => array()
      );
      
      $response = wp_remote_post( 'https://burstpay.io/?api=1', $args );
      $result=json_decode($response['body'],true);

      if ($result['status']==1) {
        return array(
          'result' => 'success',
          'redirect' => $result[url],
        );
      } else {
        return array(
          'result' => 'fail',
        );
      }
    }

    public function payment_callback()
    {
      $request = $_REQUEST;

      global $woocommerce;

      $order = new WC_Order($request['custom']);


      try {
        if (!$order || !$order->id) {
          throw new Exception('Order #'.$request['custom'].' does not exists');
        }

        $token = get_post_meta($order->id, 'burstpay_order_token', true);



$id=$_POST['id'];
$amount=number_format($_POST['amount'],2,'.','');
$currency=$_POST['currency'];
$burst=$_POST['burst'];
$payment_id=$_POST['payment_id'];
$address=$_POST['address'];
$key=$_POST['key'];
$custom=$_POST['custom']; $burst_final=$_POST['burst_final']; 
$confirm=hash('sha256',$id.$amount.$currency.$burst.$address.$payment_id.$token);
if($confirm!=$key) {
    throw new Exception('Secret key does not match');
}

        $this->init_burstpay();


  
            $statusWas = "wc-paid";

            $order->update_status($wcOrderStatus);
            $order->add_order_note(__('The payment has been received and confirmed.', 'burstpay'));
            $order->payment_complete();

            if ($order->status == 'processing' && ($statusWas == $wcExpiredStatus || $statusWas == $wcCanceledStatus)) {
                WC()->mailer()->emails['WC_Email_Customer_Processing_Order']->trigger($order->id);
            }
            if (($order->status == 'processing' || $order->status == 'completed') && ($statusWas == $wcExpiredStatus || $statusWas == $wcCanceledStatus)) {
                WC()->mailer()->emails['WC_Email_New_Order']->trigger($order->id);
            }
       
       die("*ok*");
        
      } catch (Exception $e) {
        die(get_class($e).': '.$e->getMessage());
      }
    }

    

    private function init_burstpay()
    {
     $this->burst=1;
    }
  }

  function add_burstpay_gateway($methods)
  {
    $methods[] = 'WC_Gateway_BurstPay';

    return $methods;
  }

  add_filter('woocommerce_payment_gateways', 'add_burstpay_gateway');
}
