<?php
/*
Plugin Name: Group Buying Payment Processor - PaymentSense (Custom: Cart Disabler and Purchase Limit Enabler)
Version: 1
Plugin URI: http://sproutventure.com/wordpress/group-buying
Description: PaymentSense Add-on.
Author: Sprout Venture
Author URI: http://sproutventure.com/wordpress
Plugin Author: Dan Cameron
Contributors: Dan Cameron
Text Domain: group-buying
Domain Path: /lang
*/

add_action('gb_register_processors', 'gb_load_ps');

function gb_load_ps() {
	require_once('groupBuyingPaymentSense.class.php');
}