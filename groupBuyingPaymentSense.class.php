<?php

class Group_Buying_PaymentSense extends Group_Buying_Credit_Card_Processors {

	const API_ENDPOINT = 'paymentsensegateway.com';
	const API_ENDPOINT_PORT = '4430';
	const MODE_TEST = 'sandbox';
	const MODE_LIVE = 'live';
	const API_MERCHANTID_OPTION = 'gb_paymentsense_username';
	const API_PASSWORD_OPTION = 'gb_paymentsense_password';
	const API_SECRETKEY_OPTION = 'gb_paymentsense_secretkey';
	const API_MODE_OPTION = 'gb_paymentsense_mode';
	const API_CC_OPTION = 'gb_paymentsense_currency_code';
	const PAYMENT_METHOD = 'Credit (PaymentSense)';
	protected static $instance;
	private $api_mode = self::MODE_TEST;
	private $api_merchantid = '';
	private $api_password = '';
	private $api_secretkey = '';
	private $currency_code = '';

	protected static function get_instance() {
		if ( !( isset( self::$instance ) && is_a( self::$instance, __CLASS__ ) ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function get_api_url() {
		if ( self::API_ENDPOINT_PORT == '443' ) {
			return trailingslashit( self::API_ENDPOINT );
		} else {
			return trailingslashit( self::API_ENDPOINT . ':' . self::API_ENDPOINT_PORT );
		}
	}

	public function get_payment_method() {
		return self::PAYMENT_METHOD;
	}

	protected function __construct() {
		parent::__construct();
		$this->api_merchantid = get_option( self::API_MERCHANTID_OPTION, '' );
		$this->api_password = get_option( self::API_PASSWORD_OPTION, '' );
		$this->api_secretkey = get_option( self::API_SECRETKEY_OPTION, time() );
		$this->currency_code = get_option( self::API_CC_OPTION, '826' );
		$this->api_mode = get_option( self::API_MODE_OPTION, self::MODE_TEST );

		add_action( 'admin_init', array( $this, 'register_settings' ), 10, 0 );
		add_action( 'purchase_completed', array( $this, 'complete_purchase' ), 10, 1 );

		// Limitations
		add_filter( 'group_buying_template_meta_boxes/deal-expiration.php', array( $this, 'display_exp_meta_box' ), 10 );
		add_filter( 'group_buying_template_meta_boxes/deal-price.php', array( $this, 'display_price_meta_box' ), 10 );
		add_filter( 'group_buying_template_meta_boxes/deal-limits.php', array( $this, 'display_limits_meta_box' ), 10 );
	}

	public static function register() {
		self::add_payment_processor( __CLASS__, self::__( 'PaymentSense' ) );
	}

	/**
	 * Process a payment
	 *
	 * @param Group_Buying_Checkouts $checkout
	 * @param Group_Buying_Purchase $purchase
	 * @return Group_Buying_Payment|bool FALSE if the payment failed, otherwise a Payment object
	 */
	public function process_payment( Group_Buying_Checkouts $checkout, Group_Buying_Purchase $purchase ) {
		if ( $purchase->get_total( $this->get_payment_method() ) < 0.01 ) {
			// Nothing to do here, another payment handler intercepted and took care of everything
			// See if we can get that payment and just return it
			$payments = Group_Buying_Payment::get_payments_for_purchase( $purchase->get_id() );
			foreach ( $payments as $payment_id ) {
				$payment = Group_Buying_Payment::get_instance( $payment_id );
				return $payment;
			}
		}

		$transaction_response = $this->process_sale( $checkout, $purchase );
		if ( !is_object($transaction_response) ) {
			return;
		}


		// Purchase since payment was successful above.
		
		// Create deals array.
		$deal_info = array(); // creating purchased products array for payment below
		foreach ( $purchase->get_products() as $item ) {
			if ( isset( $item['payment_method'][$this->get_payment_method()] ) ) {
				if ( !isset( $deal_info[$item['deal_id']] ) ) {
					$deal_info[$item['deal_id']] = array();
				}
				$deal_info[$item['deal_id']][] = $item;
			}
		}
		// Include shipping in payment object
		if ( isset( $checkout->cache['shipping'] ) ) {
			$shipping_address = array();
			$shipping_address['first_name'] = $checkout->cache['shipping']['first_name'];
			$shipping_address['last_name'] = $checkout->cache['shipping']['last_name'];
			$shipping_address['street'] = $checkout->cache['shipping']['street'];
			$shipping_address['city'] = $checkout->cache['shipping']['city'];
			$shipping_address['zone'] = $checkout->cache['shipping']['zone'];
			$shipping_address['postal_code'] = $checkout->cache['shipping']['postal_code'];
			$shipping_address['country'] = $checkout->cache['shipping']['country'];
		}
		// New payment
		$payment_id = Group_Buying_Payment::new_payment( array(
				'payment_method' => $this->get_payment_method(),
				'purchase' => $purchase->get_id(),
				'amount' => gb_get_number_format( $purchase->get_total( $this->get_payment_method() ) ), // TODO CHANGE to NVP_DATA Match
				'data' => array(
					'api_response' => $transaction_response,
					'masked_cc_number' => $this->mask_card_number( $this->cc_cache['cc_number'] ), // save for possible credits later
				),
				'deals' => $deal_info,
				'shipping_address' => $shipping_address,
			), Group_Buying_Payment::STATUS_AUTHORIZED );
		if ( !$payment_id ) {
			return FALSE;
		}
		$payment = Group_Buying_Payment::get_instance( $payment_id );
		do_action( 'payment_authorized', $payment );
		$payment->set_data( $response );
		return $payment;
	}

	/**
	 * Complete the purchase after the process_payment action, otherwise vouchers will not be activated.
	 *
	 * @param Group_Buying_Purchase $purchase
	 * @return void
	 */
	public function complete_purchase( Group_Buying_Purchase $purchase ) {
		$items_captured = array(); // Creating simple array of items that are captured
		foreach ( $purchase->get_products() as $item ) {
			$items_captured[] = $item['deal_id'];
		}
		$payments = Group_Buying_Payment::get_payments_for_purchase( $purchase->get_id() );
		foreach ( $payments as $payment_id ) {
			$payment = Group_Buying_Payment::get_instance( $payment_id );
			do_action( 'payment_captured', $payment, $items_captured );
			do_action( 'payment_complete', $payment );
			$payment->set_status( Group_Buying_Payment::STATUS_COMPLETE );
		}
	}


	/**
	 * Grabs error messages from a Authorize response and displays them to the user
	 *
	 * @param array   $response
	 * @param bool    $display
	 * @return void
	 */
	private function set_error_messages( $response, $display = TRUE ) {
		if ( $display ) {
			self::set_message( $response, self::MESSAGE_STATUS_ERROR );
			error_log( $response );
		} else {
			error_log( $response );
		}
	}

	/**
	 * Build the NVP data array for submitting the current checkout to Authorize as an Authorization request
	 *
	 * @param Group_Buying_Checkouts $checkout
	 * @param Group_Buying_Purchase $purchase
	 * @return array
	 */
	private function process_sale( Group_Buying_Checkouts $checkout, Group_Buying_Purchase $purchase ) {
		$user = get_userdata( $purchase->get_user() );
		$amount = gb_get_number_format( $purchase->get_total( $this->get_payment_method() ) );
		$description = '';
		foreach ( $purchase->get_products() as $item ) {
			if ( isset( $item['payment_method'][$this->get_payment_method()] ) ) {
				$deal = Group_Buying_Deal::get_instance( $item['deal_id'] );
				$description .= $deal->get_slug().', ';
			}
		}

		require_once 'lib/PaymentSystem.php';
		$rgeplRequestGatewayEntryPointList = new RequestGatewayEntryPointList();
		// you need to put the correct gateway entry point urls in here
		// contact support to get the correct urls

		// The actual values to use for the entry points can be established in a number of ways
		// 1) By periodically issuing a call to GetGatewayEntryPoints
		// 2) By storing the values for the entry points returned with each transaction
		// 3) Speculatively firing transactions at https://gw1.xxx followed by gw2, gw3, gw4....
		// The lower the metric (2nd parameter) means that entry point will be attempted first,
		// EXCEPT if it is -1 - in this case that entry point will be skipped
		// NOTE: You do NOT have to add the entry points in any particular order - the list is sorted
		// by metric value before the transaction sumbitting process begins
		// The 3rd parameter is a retry attempt, so it is possible to try that entry point that number of times
		// before failing over onto the next entry point in the list
		$rgeplRequestGatewayEntryPointList->add( "https://gw1.".self::get_api_url(), 100, 1 );
		$rgeplRequestGatewayEntryPointList->add( "https://gw2.".self::get_api_url(), 200, 1 );
		$rgeplRequestGatewayEntryPointList->add( "https://gw3.".self::get_api_url(), 300, 1 );

		$cdtCardDetailsTransaction = new CardDetailsTransaction( $rgeplRequestGatewayEntryPointList );

		$cdtCardDetailsTransaction->getMerchantAuthentication()->setMerchantID( $this->api_merchantid );
		$cdtCardDetailsTransaction->getMerchantAuthentication()->setPassword( $this->api_password );

		$cdtCardDetailsTransaction->getTransactionDetails()->getMessageDetails()->setTransactionType( "SALE" );

		$cdtCardDetailsTransaction->getTransactionDetails()->getAmount()->setValue( $amount );

		$cdtCardDetailsTransaction->getTransactionDetails()->getCurrencyCode()->setValue( self::$currency_code );


		$cdtCardDetailsTransaction->getTransactionDetails()->setOrderID( $purchase->get_id() );
		$cdtCardDetailsTransaction->getTransactionDetails()->setOrderDescription( $description );

		$cdtCardDetailsTransaction->getTransactionDetails()->getTransactionControl()->getEchoCardType()->setValue( true );
		$cdtCardDetailsTransaction->getTransactionDetails()->getTransactionControl()->getEchoAmountReceived()->setValue( true );
		$cdtCardDetailsTransaction->getTransactionDetails()->getTransactionControl()->getEchoAVSCheckResult()->setValue( true );
		$cdtCardDetailsTransaction->getTransactionDetails()->getTransactionControl()->getEchoCV2CheckResult()->setValue( true );
		$cdtCardDetailsTransaction->getTransactionDetails()->getTransactionControl()->getThreeDSecureOverridePolicy()->setValue( true );
		$cdtCardDetailsTransaction->getTransactionDetails()->getTransactionControl()->getDuplicateDelay()->setValue( 60 );

		$cdtCardDetailsTransaction->getTransactionDetails()->getThreeDSecureBrowserDetails()->getDeviceCategory()->setValue( 0 );
		$cdtCardDetailsTransaction->getTransactionDetails()->getThreeDSecureBrowserDetails()->setAcceptHeaders( "*/*" );
		$cdtCardDetailsTransaction->getTransactionDetails()->getThreeDSecureBrowserDetails()->setUserAgent( $_SERVER["HTTP_USER_AGENT"] );

		$cdtCardDetailsTransaction->getCardDetails()->setCardName( $checkout->cache['billing']['first_name'] . ' ' . $checkout->cache['billing']['last_name'] );
		$cdtCardDetailsTransaction->getCardDetails()->setCardNumber( $this->cc_cache['cc_number'] );

		if ( $this->cc_cache['cc_expiration_month'] != '' ) {
			$cdtCardDetailsTransaction->getCardDetails()->getExpiryDate()->getMonth()->setValue( substr( '0' . $this->cc_cache['cc_expiration_month'], -2 ) );
		}
		if ( $this->cc_cache['cc_expiration_year'] != '' ) {
			$cdtCardDetailsTransaction->getCardDetails()->getExpiryDate()->getYear()->setValue( substr( $this->cc_cache['cc_expiration_year'], -2 ) );
		}
		if ( $this->cc_cache['cc_start_expiration_month'] != '' ) {
			$cdtCardDetailsTransaction->getCardDetails()->getStartDate()->getMonth()->setValue( substr( '0' . $this->cc_cache['cc_start_expiration_month'], -2 ) );
		}
		if ( $this->cc_cache['cc_start_expiration_year'] != '' ) {
			$cdtCardDetailsTransaction->getCardDetails()->getStartDate()->getYear()->setValue( substr( $this->cc_cache['cc_start_expiration_year'], -2 ) );
		}

		// $cdtCardDetailsTransaction->getCardDetails()->setIssueNumber( $IssueNumber );
		$cdtCardDetailsTransaction->getCardDetails()->setCV2( $this->cc_cache['cc_cvv'] );

		$cdtCardDetailsTransaction->getCustomerDetails()->getBillingAddress()->setAddress1( $checkout->cache['billing']['first_name'] . ' ' . $checkout->cache['billing']['last_name'] );
		$cdtCardDetailsTransaction->getCustomerDetails()->getBillingAddress()->setAddress2( $checkout->cache['billing']['street'] );
		$cdtCardDetailsTransaction->getCustomerDetails()->getBillingAddress()->setCity( $checkout->cache['billing']['city'] );
		$cdtCardDetailsTransaction->getCustomerDetails()->getBillingAddress()->setState( $checkout->cache['billing']['zone'] );
		$cdtCardDetailsTransaction->getCustomerDetails()->getBillingAddress()->setPostCode( $checkout->cache['billing']['phone'] );

		if ( $checkout->cache['billing']['country'] != '' &&
			$checkout->cache['billing']['country'] != '-1' &&
			$iclISOCountryList->getISOCountry( $checkout->cache['billing']['country'], $icISOCountry ) ) {
			$cdtCardDetailsTransaction->getCustomerDetails()->getBillingAddress()->getCountryCode()->setValue( $icISOCountry->getISOCode() );
		}

		$cdtCardDetailsTransaction->getCustomerDetails()->setEmailAddress( $user->user_email );
		$cdtCardDetailsTransaction->getCustomerDetails()->setPhoneNumber( $checkout->cache['billing']['phone'] );
		$cdtCardDetailsTransaction->getCustomerDetails()->setCustomerIPAddress( $_SERVER['REMOTE_ADDR'] );

		$boTransactionProcessed = $cdtCardDetailsTransaction->processTransaction( $cdtrCardDetailsTransactionResult, $todTransactionOutputData );

		error_log( "PROCESSED TRANSACTION +++++++++++++++++++: " . print_r( $boTransactionProcessed, true ) );

		if ( $boTransactionProcessed == false ) {
			// could not communicate with the payment gateway 
			$message = "Couldn't communicate with payment gateway". $cdtCardDetailsTransaction->getLastException()->getMessage();
			self::set_error_messages( $message );
			$TransactionSuccessful = FALSE;
		} else {
			switch ( $cdtrCardDetailsTransactionResult->getStatusCode() ) {
				case 0:
					// status code of 0 - means transaction successful
					$Message = $cdtrCardDetailsTransactionResult->getMessage();
					self::set_error_messages( $Message );
					$TransactionSuccessful = $cdtrCardDetailsTransactionResult;
					break;
				case 3:
					// status code of 3 - means 3D Secure authentication required
					$PaREQ = $todTransactionOutputData->getThreeDSecureOutputData()->getPaREQ();
					$CrossReference = $todTransactionOutputData->getCrossReference();
					// Process 3D secure
					return self::process_3d_secure( $OrderID, $cdtrCardDetailsTransactionResult->getStatusCode(), $cdtrCardDetailsTransactionResult->getMessage(), $CrossReference, $PaREQ );
					break;
				case 5:
					// status code of 5 - means transaction declined
					$Message = $cdtrCardDetailsTransactionResult->getMessage();
					self::set_error_messages( $Message );
					$TransactionSuccessful = FALSE;
					break;
				case 20:
					// status code of 20 - means duplicate transaction
					if ( $cdtrCardDetailsTransactionResult->getPreviousTransactionResult()->getStatusCode()->getValue() == 0 ) {
						$TransactionSuccessful = TRUE;
					}
					else {
						$PreviousTransactionMessage = $cdtrCardDetailsTransactionResult->getPreviousTransactionResult()->getMessage();
						self::set_error_messages( $PreviousTransactionMessage );
						$TransactionSuccessful = FALSE;
					}
					break;
				case 30:
					// status code of 30 - means an error occurred
					$Message = $cdtrCardDetailsTransactionResult->getMessage();
					if ( $cdtrCardDetailsTransactionResult->getErrorMessages()->getCount() > 0 ) {
						$Message = $Message."<br /><ul>";

						for ( $LoopIndex = 0; $LoopIndex < $cdtrCardDetailsTransactionResult->getErrorMessages()->getCount(); $LoopIndex++ ) {
							$Message = $Message."<li>".$cdtrCardDetailsTransactionResult->getErrorMessages()->getAt( $LoopIndex )."</li>";
						}
						$Message = $Message."</ul>";
						$TransactionSuccessful = FALSE;
					}
					if ( $todTransactionOutputData == null ) {
						$szResponseCrossReference = "";
					}
					else {
						$szResponseCrossReference = $todTransactionOutputData->getCrossReference();
					}
					self::set_error_messages( $Message );
					break;
				default:
					// unhandled status code
					$Message = $cdtrCardDetailsTransactionResult->getMessage();
					if ( $todTransactionOutputData == null ) {
						$szResponseCrossReference = "";
					}
					else {
						$szResponseCrossReference = $todTransactionOutputData->getCrossReference();
					}
					$TransactionSuccessful = FALSE;
					self::set_error_messages( $Message );
					break;
			}
		}
		return $TransactionSuccessful;
	}


	public static function process_3d_secure( $OrderID, $StatusCode, $Message, $CrossReference, $PaREQ ) {

		require_once 'lib/PaymentSystem.php';
		$rgeplRequestGatewayEntryPointList = new RequestGatewayEntryPointList();
		$rgeplRequestGatewayEntryPointList->add( "https://gw1.".self::get_api_url(), 100, 1 );
		$rgeplRequestGatewayEntryPointList->add( "https://gw2.".self::get_api_url(), 200, 1 );
		$rgeplRequestGatewayEntryPointList->add( "https://gw3.".self::get_api_url(), 300, 1 );

		$tdsaThreeDSecureAuthentication = new CardDetailsTransaction( $rgeplRequestGatewayEntryPointList );

		$tdsaThreeDSecureAuthentication->getMerchantAuthentication()->setMerchantID( $this->api_merchantid );
		$tdsaThreeDSecureAuthentication->getMerchantAuthentication()->setPassword( $this->api_password );

		$tdsaThreeDSecureAuthentication->getThreeDSecureInputData()->setCrossReference( $CrossReference );
		$tdsaThreeDSecureAuthentication->getThreeDSecureInputData()->setPaRES( $PaREQ );

		$boTransactionProcessed = $tdsaThreeDSecureAuthentication->processTransaction( $tdsarThreeDSecureAuthenticationResult, $todTransactionOutputData );

		if ( $boTransactionProcessed == false ) {
			// could not communicate with the payment gateway
			$NextFormMode = "RESULTS";
			$Message = "Couldn't communicate with payment gateway";
			$TransactionSuccessful = false;
			PaymentFormHelper::reportTransactionResults( $CrossReference, 30, $Message, null );
		}
		else {
			switch ( $tdsarThreeDSecureAuthenticationResult->getStatusCode() ) {
			case 0:
				// status code of 0 - means transaction successful
				$TransactionSuccessful = TRUE;
				break;
			case 5:
				// status code of 5 - means transaction declined
				$Message = $tdsarThreeDSecureAuthenticationResult->getMessage();
				self::set_error_messages( $Message );
				$TransactionSuccessful = FALSE;
				break;
			case 20:
				// status code of 20 - means duplicate transaction
				if ( $tdsarThreeDSecureAuthenticationResult->getPreviousTransactionResult()->getStatusCode()->getValue() == 0 ) {
					$TransactionSuccessful = TRUE;
				}
				else {
					$PreviousTransactionMessage = $tdsarThreeDSecureAuthenticationResult->getPreviousTransactionResult()->getMessage();
					self::set_error_messages( $PreviousTransactionMessage );
					$TransactionSuccessful = FALSE;
				}
				break;
			case 30:
				// status code of 30 - means an error occurred
				$Message = $tdsarThreeDSecureAuthenticationResult->getMessage();
				if ( $tdsarThreeDSecureAuthenticationResult->getErrorMessages()->getCount() > 0 ) {
					$Message = $Message."<br /><ul>";

					for ( $LoopIndex = 0; $LoopIndex < $tdsarThreeDSecureAuthenticationResult->getErrorMessages()->getCount(); $LoopIndex++ ) {
						$Message = $Message."<li>".$tdsarThreeDSecureAuthenticationResult->getErrorMessages()->getAt( $LoopIndex )."</li>";
					}
					$Message = $Message."</ul>";
					$TransactionSuccessful = false;
				}
				if ( $todTransactionOutputData == null ) {
					$szResponseCrossReference = "";
				}
				else {
					$szResponseCrossReference = $todTransactionOutputData->getCrossReference();
				}
				$TransactionSuccessful = FALSE;
				self::set_error_messages( $Message );
				break;
			default:
				// unhandled status code
				$Message = $tdsarThreeDSecureAuthenticationResult->getMessage();
				if ( $todTransactionOutputData == null ) {
					$szResponseCrossReference = "";
				}
				else {
					$szResponseCrossReference = $todTransactionOutputData->getCrossReference();
				}
				$TransactionSuccessful = FALSE;
				self::set_error_messages( $Message );
				break;
			}
		}

	}

	public function register_settings() {
		$page = Group_Buying_Payment_Processors::get_settings_page();
		$section = 'gb_authorizenet_settings';
		add_settings_section( $section, self::__( 'PaymentSense' ), array( $this, 'display_settings_section' ), $page );
		//register_setting( $page, self::API_MODE_OPTION );
		register_setting( $page, self::API_USERNAME_OPTION );
		register_setting( $page, self::API_PASSWORD_OPTION );
		//add_settings_field( self::API_MODE_OPTION, self::__( 'Mode' ), array( $this, 'display_api_mode_field' ), $page, $section );
		add_settings_field( self::API_USERNAME_OPTION, self::__( 'API Login (Username)' ), array( $this, 'display_api_username_field' ), $page, $section );
		add_settings_field( self::API_PASSWORD_OPTION, self::__( 'Transaction Key (Password)' ), array( $this, 'display_api_password_field' ), $page, $section );
		add_settings_field(null, self::__('Currency'), array($this, 'display_currency_code_field'), $page, $section);
	}

	public function display_api_username_field() {
		echo '<input type="text" name="'.self::API_USERNAME_OPTION.'" value="'.$this->api_username.'" size="80" />';
	}

	public function display_api_password_field() {
		echo '<input type="text" name="'.self::API_PASSWORD_OPTION.'" value="'.$this->api_password.'" size="80" />';
	}

	public function display_api_mode_field() {
		echo '<label><input type="radio" name="'.self::API_MODE_OPTION.'" value="'.self::MODE_LIVE.'" '.checked( self::MODE_LIVE, $this->api_mode, FALSE ).'/> '.self::__( 'Live' ).'</label><br />';
		echo '<label><input type="radio" name="'.self::API_MODE_OPTION.'" value="'.self::MODE_TEST.'" '.checked( self::MODE_TEST, $this->api_mode, FALSE ).'/> '.self::__( 'Sandbox' ).'</label>';
	}

	public function display_currency_code_field() {
		echo '<input type="text" name="'.self::API_CC_OPTION.'" value="'.$this->currency_code.'" size="80" />';
		echo '<br/>'.gb__('ISO 4217 e.g. 826');
	}

	public function display_exp_meta_box() {
		return GB_PATH . '/controllers/payment_processors/meta-boxes/exp-only.php';
	}

	public function display_price_meta_box() {
		return GB_PATH . '/controllers/payment_processors/meta-boxes/no-dyn-price.php';
	}

	public function display_limits_meta_box() {
		return GB_PATH . '/controllers/payment_processors/meta-boxes/no-tipping.php';
	}
}
Group_Buying_PaymentSense::register();
