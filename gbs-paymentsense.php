<?php
/*
Plugin Name: Group Buying Payment Processor - BluePay
Version: Beta 2
Plugin URI: http://sproutventure.com/wordpress/group-buying
Description: BluePay Add-on.
Author: Sprout Venture
Author URI: http://sproutventure.com/wordpress
Plugin Author: Dan Cameron and Paul Kerin
Contributors: Dan Cameron
Text Domain: group-buying
Domain Path: /lang
*/

add_action('gb_register_processors', 'gb_load_bp');

function gb_load_bp() {
	require_once('groupBuyingBluePay.class.php');
}