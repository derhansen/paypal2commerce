<?php
/***************************************************************
 *  Copyright notice
 *
 *  (c) 2005 - 2010 Martin Holtz typo3@martinholtz.de (et al)
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 2 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

require_once(PATH_t3lib.'class.t3lib_div.php');

/**
 * Paypal processsing class for TYPO3 extension commerce. Communication between
 * shop und Paypal via HTTP GET (Name-Value Pair NVP).
 *
 */
class tx_paypal2commerce {

	/**
	 * Keeps all currency codes accepted by PayPal's NVP.
	 *
	 * @var unknown_type
	 */
	var $acceptedCurrencies = array( 'AUD', 'CAD', 'EUR', 'GBP', 'JPY', 'USD' );

	/**
	 * Keeps extension key.
	 *
	 * @var string
	 */
	var $ext_key = 'paypal2commerce';

	/**
	 * Keeps plugin configuration.
	 *
	 * @var array
	 */
	var $paypal;

	/**
	 * Keeps commerce's checkout object.
	 *
	 * @var tx_commerce_pi3 TYPO3 extension commerce checkout object
	 */
	var $pObj;


	/**
	 * Verifies desired currencies to process and puts them into configuration.
	 *
	 * @param array $extConf       TYPO3 extension configuration
	 * @param array $arrCurrencies array with currencies that are accepted by PayPal
	 */
	function addCurrencies( &$extConf, &$arrCurrencies ) {
		reset($extConf);
		$keys = array_keys( $extConf, 1 );
		while ( $key = current( $keys ) ) {
			// matching three-digit currency code
			if (preg_match( '/^payment_currency_([a-z]){3,3}/i', $key) ) {
				$currencyCode = strtoupper( substr( $key, -3) );
				if ( in_array ( $currencyCode, $this->acceptedCurrencies ))
				array_push( $arrCurrencies, $currencyCode );
			}
			next($keys);
		}
	}

	/**
	 * Enables and sets proxy environment for cURL usage if needed.
	 *
	 * @param resource $cURLHandle initialized cURL ressource
	 */
	function checkCurlProxy( &$cURLHandle ) {
		if ($GLOBALS['TYPO3_CONF_VARS']['SYS']['curlUse']
		&& isset( $GLOBALS['TYPO3_CONF_VARS']['SYS']['curlProxyServer'] )
		&& !empty( $GLOBALS['TYPO3_CONF_VARS']['SYS']['curlProxyServer']) ) {
			$arrProxy = explode( ':', $GLOBALS['TYPO3_CONF_VARS']['SYS']['curlProxyServer'] );
			curl_setopt( $cURLHandle, CURLOPT_PROXYPORT, intval( array_pop( $arrProxy ) ) );
			curl_setopt( $cURLHandle, CURLOPT_PROXY, implode( ':', $arrProxy ) );
			if (isset( $GLOBALS['TYPO3_CONF_VARS']['SYS']['curlProxyUserPass'] )
			&& !empty( $GLOBALS['TYPO3_CONF_VARS']['SYS']['curlProxyUserPass'] ) ) {
				curl_setopt( curl_setopt ( $cURLHandle, CURLOPT_PROXYUSERPWD, $GLOBALS['TYPO3_CONF_VARS']['SYS']['curlProxyUserPass'] )  );
			}
		}
	}

	/**
	 * Implements 3rd step of payment processing; it returns information about the customer,
	 * including name and address stored on PayPal.
	 *
	 * @return boolean true if step has been successful, otherwise false
	 */
	function checkFromPaypal() {
		$token = urlencode(t3lib_div::_GP('token'));
		$paymentAmount =urlencode(t3lib_div::_GP('paymentAmount'));
		$paymentType = urlencode(t3lib_div::_GP('paymentType'));
		$currCodeType = urlencode(t3lib_div::_GP('currencyCodeType'));
		$payerID = urlencode(t3lib_div::_GP('PayerID'));
		$serverName = urlencode($_SERVER['SERVER_NAME']);
		$nvpstr='&TOKEN='.$token.'&PAYERID='.$payerID.'&PAYMENTACTION='.$paymentType.'&AMT='.$paymentAmount.'&CURRENCYCODE='.$currCodeType.'&IPADDRESS='.$serverName ;
		$resArray=$this->hash_call("GetExpressCheckoutDetails",$nvpstr);
		$this->resArray = $resArray;
		$ack = strtoupper($resArray["ACK"]);
		$returnResult = false;
		//TODO address validation
		try {
			if($ack!="SUCCESS") {
				throw new PaymentException( 'A paypal service3 error has occurred: ' . $resArray['L_SHORTMESSAGE0'],
				PAYERR_PAYPAL_SV,
				array( 'error_no'  => intval( $resArray['L_ERRORCODE0'] ),
						   'error_msg' => $resArray['L_LONGMESSAGE0']));
			} else {
				$returnResult = true;
			}
		} catch (PaymentException $e) {
			$this->debugAndLog($e);
			header('Location: ' . $this->getPaymentErrorPageURL());
			exit();
		}
		return $returnResult;
	}

	/**
	 * Function retrieves extension configuration and puts it into class variables.
	 *
	 * Authorize Payment URL:<br>
	 * - Sandbox: https://www.sandbox.paypal.com/webscr&cmd=_express-checkout&token=<br>
	 * - Live: https://www.paypal.com/webscr&cmd=_express-checkout&token=
	 *
	 */
	function constants() {
		$ext_conf = unserialize($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf'][$this->ext_key]);
		$this->paypal['API_Endpoint'] = $ext_conf['api_endpoint'];
		$this->paypal['version'] = '2.3';
		$this->paypal['API_UserName'] = $ext_conf['api_username'];
		$this->paypal['API_Password'] = $ext_conf['api_password'];
		$this->paypal['API_Signature'] = $ext_conf['api_signature'];
		$this->paypal['nvp_Header'] = '';
		$this->paypal['PAYPAL_URL'] = $ext_conf['paypal_url'];
		$this->paypal['currencies'] = array();
		$this->addCurrencies( $ext_conf, $this->paypal['currencies'] );
		$this->paypal['qualified_check'] = ( isset( $ext_conf['payment_qualified_check'] )? intval( $ext_conf['payment_qualified_check'] ) : 0 );

		// Setting Curl-Options
		$this->paypal['curl_timeout'] = ( isset( $ext_conf['curl_timeout'] )? intval( $ext_conf['curl_timeout'] ) : 20 );
		$this->paypal['curl_verifypeer'] = ( isset( $ext_conf['curl_verifypeer'] ) ? (boolean)$ext_conf['curl_verifypeer'] : false );
		$this->paypal['curl_verifyhost'] = ( isset( $ext_conf['curl_verifyhost'] ) ? (boolean)$ext_conf['curl_verifyhost'] : false );
	}

	/**
	 * Debugs and logs payment processing errors.
	 *
	 * @param PaymentException $exception Exception object
	 */
	function debugAndLog( $exception ) {
		if ($GLOBALS['TYPO3_CONF_VARS']['SYS']['enable_DLOG'] ) {
			t3lib_div::devLog(
			$exception->getMessage(),
			$this->ext_key,
			3,
			array( $exception->getErrorNumber(), $exception->getDetails())
			);
			if ( $GLOBALS['TYPO3_CONF_VARS']['FE']['debug'] ) {
				debug ( $exception->getMessage() );
			}
		}
	}

	/**
	 * Transforms PayPal's Name-Value Pair string from URL into a (decoded) associative array.
	 *
	 * @param  string $nvpstr PayPal's Name-Value Pair string
	 * @return array
	 * @see                   https://www.paypal.com/en_US/ebook/PP_NVPAPI_DeveloperGuide/index.html
	 */
	function deformatNVP($nvpstr)
	{
		$intial=0;
		$nvpArray = array();
		while(strlen($nvpstr)){
			//postion of Key
			$keypos= strpos($nvpstr,'=');
			//position of value
			$valuepos = strpos($nvpstr,'&') ? strpos($nvpstr,'&'): strlen($nvpstr);

			/*getting the Key and Value values and storing in a Associative Array*/
			$keyval=substr($nvpstr,$intial,$keypos);
			$valval=substr($nvpstr,$keypos+1,$valuepos-$keypos-1);
			//decoding the respose
			$nvpArray[urldecode($keyval)] =urldecode( $valval);
			$nvpstr=substr($nvpstr,$valuepos+1,strlen($nvpstr));
		}
		return $nvpArray;
	}

	/**
	 * This method is called in the last step. Here can be made some final checks or whatever is
	 * needed to be done before saving some data in the database.
	 * Write any errors into $this->errorMessages!
	 * To save some additonal data in the database use the method updateOrder().
	 *
	 * @param  array	       $config  the configuration from the TYPO3_CONF_VARS
	 * @param  array	       $session the session object
	 * @param  array	       $basket  the basket object
	 * @param  tx_commerce_pi3 $pObj    TYPO3 extension commerce checkout object
	 * @return boolean	                true or false
	 */
	function finishingFunction($config,$session, $basket, $pObj ) {
		if(!is_object($this->pObj)) {
			$this->pObj = $pObj;
		}
		// ok, we get the form
		if ('' != t3lib_div::_POST('tx_commerce_pi3')) {
			$GLOBALS['TSFE']->fe_user->setKey('ses', 'comment', t3lib_div::removeXSS(strip_tags($pObj->piVars['comment'])));
			$GLOBALS["TSFE"]->storeSessionData();
			$this->sendToPaypal( $basket->basket_sum_gross, $pObj->conf['currency'] );
		}
		if (!$this->checkFromPaypal()) {
			return false;
		} else {
			// do not forget the comment;!!!
			$this->comment = $GLOBALS['TSFE']->fe_user->getKey('ses', 'comment');
			// in saveOrder() the comment is expected in the piVars
			$this->pObj->piVars['comment'] = $this->comment;
			$this->paymentRefId = 'CORRELATIONID'.$this->resArray['CORRELATIONID'].'PAYERID'.$this->resArray['PAYERID'];
			return true;
		}
	}

	/**
	 * This function returns field configuration for further data needed to fulfill payment process.
	 *
	 * @param  tx_commerce_pi3 $pObj TYPO3 extension commerce checkout object
	 * @return array                 array with configuration of further (optional) field; currently always empty
	 * @see needAdditionalData()
	 */
	function getAdditonalFieldsConfig($pObj) {
		if(!is_object($this->pObj)) {
			$this->pObj = $pObj;
		}
		return array();
	}

	/**
	 * checks if two values are equal
	 *
	 * @param float $a
	 * @param float $b
	 * @return boolean
	 */
	function amountEqual($a, $b) {
		return round($a) == round($b);
	}

	/**
	 * Implements 4th and last step of payment processing, which means a request to obtain the payment.
	 *
	 * @return boolean true if payment processing was successful, otherwise false
	 */
	function getFromPaypal() {
		$token = urlencode(t3lib_div::_GP('token'));
		$paymentAmount =urlencode(t3lib_div::_GP('paymentAmount'));
		$paymentType = urlencode(t3lib_div::_GP('paymentType'));
		$currCodeType = urlencode(t3lib_div::_GP('currencyCodeType'));
		$payerID = urlencode(t3lib_div::_GP('PayerID'));
		$serverName = urlencode($_SERVER['SERVER_NAME']);
		$nvpstr='&TOKEN='.$token.'&PAYERID='.$payerID.'&PAYMENTACTION='.$paymentType.'&AMT='.$paymentAmount.'&CURRENCYCODE='.$currCodeType.'&IPADDRESS='.$serverName ;

		try {
			$basket = $GLOBALS['TSFE']->fe_user->tx_commerce_basket;
			// check if amount has changed
			if (!$this->amountEqual($basket->basket_sum_gross, $_REQUEST['paymentAmount']*100)) {
				// wrong sum
				throw new PaymentException( 'A paypal service error has occurred: Amount mismatch',
				PAYERR_AMOUNT_MISMATCH,
				array( 'error_no'  => PAYERR_AMOUNT_MISMATCH,
						   'error_msg' => 'PAYPAL sum does not match basket sum (1)'));
			}
			$resArray=$this->hash_call("DoExpressCheckoutPayment",$nvpstr);
			$ack = strtoupper($resArray["ACK"]);

			$returnResult = false;

			if( $ack == "SUCCESS" ) {

				if ($this->amountEqual($basket->basket_sum_gross, $resArray['AMT']*100)) {
					$GLOBALS['TSFE']->fe_user->setKey('ses', 'paypal2commerce_token', NULL );
					$GLOBALS["TSFE"]->storeSessionData();
					$returnResult = true;
				} else {
					// should not happen, has been checked before
					// @TODO: cancel payment
					// wrong sum
					throw new PaymentException(
						'A paypal service error has occurred: Amount mismatch',
					PAYERR_AMOUNT_MISMATCH,
					array(  'error_no'  => PAYERR_AMOUNT_MISMATCH,
								'error_msg' => 'PAYPAL sum does not match basket sum (2)'.$basket->basket_sum_gross.' vs. '.$resArray['AMT']*100
					)
					);
				}
			} else {
				throw new PaymentException( 'A paypal service error has occurred: ' . $resArray['L_SHORTMESSAGE0'],
				PAYERR_PAYPAL_SV,
				array( 'error_no'  => intval( $resArray['L_ERRORCODE0'] ),
						   'error_msg' => $resArray['L_LONGMESSAGE0']));
			}
		} catch (PaymentException $e) {
			$this->debugAndLog($e);
			header('Location: ' . $this->getPaymentErrorPageURL());
			exit();
		}
		return $returnResult;
	}

	/**
	 * Returns the last error message.
	 *
	 * @param  integer         $finish
	 * @param  tx_commerce_pi3 $pObj   TYPO3 extension commerce checkout object
	 * @return mixed                   last error as array if not yet finished, otherwise joined string of errors
	 * @see
	 */
	function getLastError( $finish = 0, $pObj ) {
		if ( !is_object( $this->pObj ) ) {
			$this->pObj = $pObj;
		}
		if ( $finish ){
			return $this->getReadableError();
		} else {
			return $this->errorMessages[(count( $this->errorMessages ) - 1 )];
		}
	}

	/**
	 * Returns URL of configurable error page in case of payment service faults.
	 *
	 * @return string URL of error page
	 */
	function getPaymentErrorPageURL() {
		$url = $GLOBALS["TSFE"]->config['config']['baseURL'];
		$ext_conf = unserialize($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf'][$this->ext_key]);
		$url .= $GLOBALS['TSFE']->cObj->typoLink_URL( array( 'parameter' => $ext_conf['payment_error_pid'] ) );
		return $url;
	}

	/**
	 * Returns all captured error messages.
	 *
	 * @return string joined string of single errors
	 */
	function getReadableError() {
		$back = '';
		reset( $this->errorMessages );
		while( list( $k, $message ) = each( $this->errorMessages ) ){
			$back .= $message;
		}
		return $back;
	}

	/**
	 * Function usable to display a last message after processing a payment.
	 *
	 * @param	array	       $config  the configuration from the TYPO3_CONF_VARS
	 * @param	array	       $session the session object
	 * @param	array	       $basket  the basket object
	 * @param  tx_commerce_pi3 $pObj    TYPO3 extension commerce checkout object
	 * @return string                   text that is displayed after last processing step
	 * @see                             hasSpecialFinishingForm()
	 */
	function getSpecialFinishingForm($config, $session, $basket,$pObj) {
		$content = 'Error - there is something wrong... sorry, cannot finish your order.';;
		return $content;
	}

	/**
	 * Makes a call via cURL to interact with paypal.
	 *
	 * @param string $methodName PayPal's method name identifying a step of payment processing
	 * @param string $nvpStr PayPal Name-Value Pair string part
	 * @return array associative array derived from PayPal's Name-Value Pair
	 * @see  https://www.paypal.com/en_US/ebook/PP_NVPAPI_DeveloperGuide/index.html
	 */
	function hash_call($methodName, $nvpStr)
	{
		// declaring of global variables
		$this->constants();
		$API_Endpoint = $this->paypal['API_Endpoint'];
		$version = $this->paypal['version'];
		$API_UserName = $this->paypal['API_UserName'];
		$API_Password = $this->paypal['API_Password'];
		$API_Signature = $this->paypal['API_Signature'];

		// setting the curl parameters.
		$ch = curl_init();
		if (false === $ch) {
			die( 'curl_init - Error #' . __LINE__ );
		}
		curl_setopt( $ch, CURLOPT_URL, $API_Endpoint );
		curl_setopt( $ch, CURLOPT_VERBOSE, 1 );

		// turning off the server and peer verification(TrustManager Concept).
		curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, $this->paypal['curl_verifypeer'] );
		curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, $this->paypal['curl_verifyhost'] );

		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
		curl_setopt( $ch, CURLOPT_POST, 1 );
		curl_setopt( $ch, CURLOPT_TIMEOUT, $this->paypal['curl_timeout'] );
			
		// configures cURL proxy if needed
		$this->checkCurlProxy( $ch );

		// NVPRequest for submitting to server
		$nvpreq="METHOD=".urlencode($methodName)."&VERSION=".urlencode($version)."&PWD=".urlencode($API_Password)."&USER=".urlencode($API_UserName)."&SIGNATURE=".urlencode($API_Signature).$nvpStr;

		// setting the nvpreq as POST FIELD to curl
		curl_setopt($ch,CURLOPT_POSTFIELDS,$nvpreq);
		// getting response from server
		$response = curl_exec($ch);
		$nvpResArray = array();
		try {
			if (!curl_errno($ch)) {
				curl_close($ch);
				// converting NVPResponse to an Associative Array
				$nvpResArray=$this->deformatNVP($response);
			} else {
				throw new PaymentException( 'A connection to paypal could not be established!',
				PAYERR_CURL_CONN,
				array( 'error_no'  => intval( curl_errno($ch) ),
												   'error_msg' => curl_error($ch)));
			}
		} catch (PaymentException $e) {
			$this->debugAndLog($e);
			header('Location: ' . $this->getPaymentErrorPageURL());
			exit();
		}
		return $nvpResArray;
	}

	/**
	 * Function used to call the processing method. This request was generated by
	 * PayPal's returnurl functionality after customer chooses to pay.
	 *
	 * @param  array $_REQUEST       HTTP request variables
	 * @param  tx_commerce_pi3 $pObj TYPO3 extension commerce checkout object
	 * @return boolean               true if Paypal processing is finished otherwise falses
	 * @see                          getSpecialFinishingForm()
	 */
	function hasSpecialFinishingForm( $_REQUEST, $pObj ) {
		if ( !is_object($this->pObj) ) {
			$this->pObj = $pObj;
		}
		$token=t3lib_div::_GP('token');
		// returning boolean true shows configurable page content, false finishes payment processing
		if ( !empty( $token ) )
		return !$this->getFromPaypal();
		else
		return false;
	}

	/**
	 * Checks if shop owner accepts given currency type.
	 *
	 * @param  string  $currencyCodeType three-digit currency code type
	 * @return boolean                   true if currency type is accepted, otherwise false
	 */
	function isAllowedCurrency( $currencyCodeType ) {
		if ( empty( $this->paypal ) )
		$this->constants();
		return in_array( $currencyCodeType, $this->paypal['currencies'] );
	}

	/**
	 * Checks if further user supplied data is needed to process payment.
	 *
	 * Returns always false (in case of PayPal).
	 *
	 * @param  tx_commerce_pi3 $pObj      TYPO3 extension commerce checkout object
	 * @see    getAdditonalFieldsConfig()
	 */
	function needAdditionalData( $pObj ) {
		if ( !is_object($this->pObj) ) {
			$this->pObj = $pObj;
		}
		return false;
	}

	/**
	 * Function usable for validation of user-supplied date.
	 *
	 * @param  array           $formData user-supplied form data
	 * @param  tx_commerce_pi3 $pObj     TYPO3 extension commerce checkout object
	 * @return boolean                   true if data validation succeeds, otherwise false
	 */
	function proofData($formData,$pObj) {
		if(!is_object($this->pObj)) {
			$this->pObj = $pObj;
		}
		return true;
	}

	/**
	 * Function initiates payment processing and redirects customer to PayPal website.
	 *
	 * @param integer $amount           total costs of basket in smallest payable unit (cents)
	 * @param string  $currencyCodeType three-digit currency code
	 */
	function sendToPaypal($amount,$currencyCodeType = 'EUR') {
		try {
			$paymentAmount = sprintf( "%01.2f",( $amount/100 ) );
			$paymentType = 'Sale';
			$currencyCodeType = strtoupper($currencyCodeType);
			if ( !$this->isAllowedCurrency( $currencyCodeType ) )
			throw new PaymentException( 'Paypal does not support this currency',
			PAYERR_WRONG_CURRENCY,
			array( 'error_no'  => intval( PAYERR_WRONG_CURRENCY ),
					'error_msg' => 'Desired checkout currency type is not supported by PayPal' ));
		} catch (PaymentException $e) {
			$this->debugAndLog($e);
			header('Location: ' . $this->getPaymentErrorPageURL());
			exit();
		}
		$step = 'payment';
		$step = 'finish';
		$conf = array();
		$conf['additionalParams'] = '&currencyCodeType='.$currencyCodeType.'&paymentType='.$paymentType.'&paymentAmount='.$paymentAmount.'&tx_commerce_pi3[terms]=termschecked&tx_commerce_pi3[step]='.$step.'&tx_commerce_pi3[paypal]=success';
		$conf['parameter'] = $GLOBALS['TSFE']->id;

		$baseurl = $GLOBALS["TSFE"]->config['config']['baseURL'];
		if (strlen($baseurl) < 2) {
			$baseurl = t3lib_div::getIndpEnv('TYPO3_SITE_URL');
		}
		if ('/' != substr($baseurl,-1,1)) {
			$baseurl .= '/';
		}
		// URL where the costomer will be send to, if the payment has been successfully authorized
		$returnURL = urlencode($baseurl.$GLOBALS['TSFE']->cObj->typoLink_URL($conf));
		$conf['additionalParams'] = '&paymentType='.$paymentType;
		// URL where the costomer will be send to, if he cancels the payment
		$cancelURL = urlencode($baseurl.$GLOBALS['TSFE']->cObj->typoLink_URL($conf));

		// Paypal Call - will be send via CURL
		$nvpstr="&Amt=".$paymentAmount."&PAYMENTACTION=".$paymentType."&ReturnUrl=".$returnURL."&CANCELURL=".$cancelURL ."&CURRENCYCODE=".$currencyCodeType;

		// Language-Settings
		// @TODO: does not work? Why not?
		$localcode = (isset($GLOBALS['TSFE']->tmpl->setup['config.']['paypal_language'])?$GLOBALS['TSFE']->tmpl->setup['config.']['paypal_language']:$GLOBALS['TSFE']->tmpl->setup['config.']['language']);
		$nvpstr.= "&LOCALECODE=".strtoupper($localcode);

		// SET Address
		$addresstyp = 'billing';
		if (is_array($this->pObj->MYSESSION['delivery']) && sizeof($this->pObj->MYSESSION['delivery']) > 0) {
			$addresstyp = 'delivery';
		}
		$addr = $this->pObj->MYSESSION[$addresstyp];

		$nvpstr.= '&ADDROVERRIDE=1'; // do not override the address via paypal
		$nvpstr.= '&SHIPTONAME='.urlencode($addr['name'].' '.$addr['surname']);

		$nvpstr.= '&SHIPTOSTREET='.urlencode($addr['address']);
		$nvpstr.= '&SHIPTOCITY='.urlencode($addr['city']);
		//Added by Martin-Pierre Frenette
		if (isset($addr['region'])) {
			$nvpstr.= '&SHIPTOSTATE='.urlencode($addr['region']);
		}

		try {
			// get Countrycode, Paypal needs 'DE' etc.
			$res = $GLOBALS['TYPO3_DB']->exec_SELECTquery(
				'cn_iso_2',
				'static_countries',
				'cn_iso_3=\''.$GLOBALS['TYPO3_DB']->quoteStr($addr['country'], 'static_countries').'\'',
				'',
				'',
			1);
			if ($GLOBALS['TYPO3_DB']->sql_num_rows($res) != 1) {
				throw new PaymentException('Selected Countrycode not available: "'.htmlspecialchars($addr['country']).'"',
				PAYERR_WRONG_COUNTRY);
			}
			$row = $GLOBALS['TYPO3_DB']->sql_fetch_assoc($res);
			$nvpstr.= '&SHIPTOCOUNTRYCODE='.$row['cn_iso_2'];
			$nvpstr.= '&SHIPTOZIP='.urlencode($addr['zip']);
			$nvpstr.= '&PHONENUM='.urlencode($addr['phone']);
			$nvpstr.= '&BUSINESS='.urlencode($addr['company']);

			// call to PayPal to get the Express Checkout token
			$resArray = $this->hash_call("SetExpressCheckout",$nvpstr);
			$_SESSION['reshash']=$resArray;

			$ack = strtoupper($resArray["ACK"]);

			if($ack=="SUCCESS"){
				// Redirect to paypal.com here
				$token = urldecode($resArray["TOKEN"]);
				$GLOBALS['TSFE']->fe_user->setKey('ses', 'paypal2commerce_token', $token );
				$GLOBALS['TSFE']->storeSessionData();
				$payPalURL = $this->paypal['PAYPAL_URL'].$token;
				header("Location: ".$payPalURL);
				exit();
			} else  {
				throw new PaymentException( 'A paypal service error has occurred: ' . $resArray['L_SHORTMESSAGE0'],
				PAYERR_PAYPAL_SV,
				array( 'error_no'  => intval( $resArray['L_ERRORCODE0'] ),
						   'error_msg' => $resArray['L_LONGMESSAGE0']));
					
			}
		} catch (PaymentException $e) {
			$this->debugAndLog($e);
			header('Location: ' . $this->getPaymentErrorPageURL());
			exit();
		}
	}

	/**
	 * Function usable for storing additional data (payment_ref_id, comment) of an order into database.
	 *
	 * @param integer         $orderUid order unique identified
	 * @param object          $session  session object
	 * @param tx_commerce_pi3 $pObj     TYPO3 extension commerce checkout object
	 */
	function updateOrder($orderUid, $session,$pObj) {
		if(!is_object($this->pObj)) {
			$this->pObj = $pObj;
		}
		$GLOBALS['TYPO3_DB']->exec_UPDATEquery(
			'tx_commerce_orders','uid = '. intval( $orderUid ),
		array('payment_ref_id' => $this->paymentRefId, 'comment' => $this->comment)
		);
	}
}


@define(PAYERR_CURL_CONN, 0x66661);
@define(PAYERR_PAYPAL_SV, 0x66662);
@define(PAYERR_WRONG_CURRENCY, 0x66663);
@define(PAYERR_AMOUNT_MISMATCH, 0x66664);
@define(PAYERR_WRONG_COUNTRY, 0x66665);

/**
 * Class for Payment Exceptions.
 *
 */
class PaymentException extends Exception
{

	/**
	 * Keeps exception details.
	 *
	 * @var array
	 */
	protected $details;


	/**
	 * Calling class constructor.
	 *
	 * @param string $message    exception message
	 * @param integer $code      exception identifier represented as hexadecimal number
	 * @param mixed $details     array of exception details
	 */
	public function __construct($message, $code = 0, $details='') {
		parent::__construct($message, $code);
		$this->details = $details;
	}

	/**
	 * Returns string representation of the exception
	 *
	 * @return string serialized exception object
	 */
	public function __toString() {
		return __CLASS__ . ": [{$this->code}]: {$this->message}\n";
	}

	/**
	 * Returns error number of exception.
	 *
	 * @return integer error number of exception
	 */
	public function getErrorNumber() {
		return $this->details['error_no'];
	}

	/**
	 * Returns exception details.
	 *
	 * @return string exception details
	 */
	public function getDetails() {
		return $this->details['error_msg'];
	}
}


if (defined('TYPO3_MODE') && $TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']["ext/paypal2commerce/class.tx_paypal2commerce.php"])	{
	include_once($TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']["ext/paypal2commerce/class.tx_paypal2commerce.php"]);
}
?>
