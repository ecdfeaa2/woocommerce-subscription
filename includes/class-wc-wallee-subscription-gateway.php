<?php

if (!defined('ABSPATH')) {
	exit();
}
/**
 * wallee WooCommerce
 *
 * This WooCommerce plugin enables to process payments with wallee (https://www.wallee.com).
 *
 * @author customweb GmbH (http://www.customweb.com/)
 * @license http://www.apache.org/licenses/LICENSE-2.0 Apache Software License (ASL 2.0)
 */
/**
 * This class implements the wallee subscription gateways
 */
class WC_Wallee_Subscription_Gateway {
	
   /**
    * 
    * @var WC_Wallee_Gateway
    */
    private $gateway;
    
    public function __construct(WC_Wallee_Gateway $gateway){
        $this->gateway = $gateway;
        
        add_action('woocommerce_scheduled_subscription_payment_' . $gateway->id, array(
            $this,
            'process_scheduled_subscription_payment'
        ), 10, 2);
        //Handle Admin Token Setting
        add_filter('woocommerce_subscription_payment_meta', array(
            $this,
            'add_subscription_payment_meta'
        ),10, 2);
        add_action('woocommerce_subscription_validate_payment_meta', array(
            $this,
            'validate_subscription_payment_meta'
        ),10, 2);
        //Handle customer payment method change
        add_filter( 'woocommerce_subscriptions_update_payment_via_pay_shortcode',  array(
            $this,
            'maybe_dont_update_payment_method'
        ), 10, 3 );
        //Handle Pay Failed Renewal
        add_action('woocommerce_subscription_failing_payment_method_updated_' . $gateway->id, array(
            $this,
            'process_subscription_failing_payment_method_updated'
        ), 10, 2);
    }
    
    public function process_scheduled_subscription_payment($amount_to_charge, WC_Order $order){
        try{
        $token_space_id =  get_post_meta( $order->get_id(), '_wallee_subscription_space_id', true );
        $token_id =  get_post_meta( $order->get_id(), '_wallee_subscription_token_id', true );
        if(empty($token_space_id) || $token_space_id != get_option(WooCommerce_Wallee::CK_SPACE_ID)){
            $order->update_status('failed', __('The token space and the configured space are not equal.','woo-wallee-subscription'));
            return;
        }
        if(empty($token_id)){
            $order->update_status('failed', __('There is no token associated with this subscription.','woo-wallee-subscription'));
            return;
        }
        $transaction_service = WC_Wallee_Subscription_Service_Transaction::instance();
        
        $transaction_info = WC_Wallee_Entity_Transaction_Info::load_by_order_id($order->get_id());
        if($transaction_info->get_id() > 0){
            $existing_transaction = $transaction_service->get_transaction($transaction_info->get_space_id(), $transaction_info->get_id());
            if($existing_transaction->getState() != \Wallee\Sdk\Model\TransactionState::PENDING){
                return;
            }
            $transaction_service->update_transaction_by_renewal_order($order, $amount_to_charge, $token_id, $existing_transaction);
            $transaction_service->process_transaction_without_user_interaction($existing_transaction->getLinkedSpaceId(), $existing_transaction->getId());
        }
        else{
            $create_transaction = $transaction_service->create_transaction_by_renewal_order($order, $amount_to_charge, $token_id);
            $transaction_service->update_transaction_info($create_transaction, $order);
            $transaction_service->process_transaction_without_user_interaction($token_space_id, $create_transaction->getId());
        }
        
        $order->add_meta_data('_wallee_gateway_id', $this->gateway->id, true);
        $order->delete_meta_data('_wc_wallee_restocked');
        }
        catch(Exception $e){
            $order->update_status('failed', $e->getMessage() ,'woo-wallee-subscription');
            WooCommerce_Wallee_Subscription::instance()->log($e->getMessage().$e->getTraceAsString());
            return;
        }
    }
    
    
    public function maybe_dont_update_payment_method( $update, $new_payment_method, $subscription ) {
        if ( $this->gateway->id == $new_payment_method ) {
            $update = false;
            
            add_filter('wc_wallee_gateway_result_send_json', array(
                $this, 'gateway_result_send_json'
            ), 10, 2);
        }        
        return $update;
    }
    
    public function gateway_result_send_json($send, $order_id){
        
        add_filter( 'woocommerce_subscriptions_process_payment_for_change_method_via_pay_shortcode',  array(
            $this,
            'store_gateway_result_in_globals'
        ), -10, 2 );
        add_filter( 'wp_redirect',  array(
            $this,
            'create_json_response'
        ), -10, 2 );
        return false;
    }
    
    public function store_gateway_result_in_globals($result, $subscription){
        if(isset($result['wallee'])){
            $GLOBALS['_wc_wallee_subscription_gateway_result'] = $result;
            return array('result' => $result['result'], 'redirect' => 'wc_wallee_subscription_redirect');
        }
        return $result;
    }
    
    public function create_json_response($location, $status){
        if($location == 'wc_wallee_subscription_redirect' && isset($GLOBALS['_wc_wallee_subscription_gateway_result'])){
            wp_send_json( $GLOBALS['_wc_wallee_subscription_gateway_result'] );
            exit;
        }
        return $location;
    }
    
    
    public function add_subscription_payment_meta($payment_meta, $subscription){
        $payment_meta[ $this->gateway->id ] = array(
            'post_meta' => array(
                '_wallee_subscription_space_id' => array(
                    'value' => get_post_meta( $subscription->get_id(), '_wallee_subscription_space_id', true ),
                    'label' => 'wallee Space Id'
                ),
                '_wallee_subscription_token_id' => array(
                    'value' => get_post_meta( $subscription->get_id(), '_wallee_subscription_token_id', true ),
                    'label' => 'wallee Token Id'
                ),
            ),
        );
        return $payment_meta;
    }
    
    public function validate_subscription_payment_meta( $payment_method_id, $payment_meta ) {
        
        if ( $this->gateway->id === $payment_method_id ) {            
            if ( ! isset( $payment_meta['post_meta']['_wallee_subscription_space_id']['value'] ) || empty( $payment_meta['post_meta']['_wallee_subscription_space_id']['value'] ) ) {
                throw new Exception( __('The wallee Space Id value is required.', 'woo-wallee-subscription'));
            }
            elseif ( $payment_meta['post_meta']['_wallee_subscription_space_id']['value'] !=  get_option(WooCommerce_Wallee::CK_SPACE_ID)) {
                throw new Exception( __('The wallee Space Id needs to be in the same space as configured in the main configuration.', 'woo-wallee-subscription'));
            }
            if ( ! isset( $payment_meta['post_meta']['_wallee_subscription_token_id']['value'] ) || empty( $payment_meta['post_meta']['_wallee_subscription_token_id']['value'] ) ) {
                throw new Exception( __('The wallee Token Id value is required.', 'woo-wallee-subscription'));
            }
        }        
    }
    
    public function process_subscription_failing_payment_method_updated($subscription, $renewal_order ){
        update_post_meta( $subscription->get_id(), '_wallee_subscription_space_id', $renewal_order->get_meta('_wallee_subscription_space_id',true));
        update_post_meta( $subscription->get_id(), '_wallee_subscription_token_id', $renewal_order->get_meta('_wallee_subscription_token_id',true));
    }    

}