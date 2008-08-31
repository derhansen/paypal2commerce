<?php
/***************************************************************
*  Copyright notice
*
*  (c) 2005 - 2007 Martin Holtz typo3@martinholtz.de
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

class tx_paypal2commerce {
	
	var $paypal;

	/**
	 *
	 */
	function needAdditionalData($pObj) {
		return false;
	}
	
	/**
	 * makes an call via curl to interact with paypal
	 * code ist from 
	 * https://www.paypal.com/en_US/ebook/PP_NVPAPI_DeveloperGuide/index.html
	 * Paypal Examples
	 */
	function hash_call($methodName,$nvpStr)
	{
		//declaring of global variables
		$this->constants();
		$API_Endpoint = $this->paypal['API_Endpoint'];
		$version = $this->paypal['version'];
		$API_UserName = $this->paypal['API_UserName'];
		$API_Password = $this->paypal['API_Password'];
		$API_Signature = $this->paypal['API_Signature'];
	
		//setting the curl parameters.
		$ch = curl_init();
		if (false === $ch) {
			die('curl_init - Error #'.__LINE__);
		}
		curl_setopt($ch, CURLOPT_URL,$API_Endpoint);
		curl_setopt($ch, CURLOPT_VERBOSE, 1);
	
		//turning off the server and peer verification(TrustManager Concept).
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, $this->paypal['curl_verifypeer']);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, $this->paypal['curl_verifyhost']);
	
		curl_setopt($ch, CURLOPT_RETURNTRANSFER,1);
		curl_setopt($ch, CURLOPT_POST, 1);
 		curl_setopt($ch, CURLOPT_TIMEOUT, $this->paypal['curl_timeout']); 
	
		//NVPRequest for submitting to server
		$nvpreq="METHOD=".urlencode($methodName)."&VERSION=".urlencode($version)."&PWD=".urlencode($API_Password)."&USER=".urlencode($API_UserName)."&SIGNATURE=".urlencode($API_Signature).$nvpStr;
	
		//setting the nvpreq as POST FIELD to curl
		curl_setopt($ch,CURLOPT_POSTFIELDS,$nvpreq);
		//getting response from server
		$response = curl_exec($ch);
		//converting NVPResponse to an Associative Array
		$nvpResArray=$this->deformatNVP($response);
		$nvpReqArray=$this->deformatNVP($nvpreq);
		$_SESSION['nvpReqArray']=$nvpReqArray;
	
		if (curl_errno($ch)) {
			// moving to display page to display curl errors
			  $_SESSION['curl_error_no']=curl_errno($ch) ;
			  $_SESSION['curl_error_msg']=curl_error($ch);
// TODO: error Handling!
			die('There is no Connection to Paypal possible? CURL is not enabled?');
			  // header("Location: $location");
		 } else {
			 //closing the curl
			curl_close($ch);
		  }
	
		return $nvpResArray;
	}

	/** This function will take NVPString and convert it to an Associative Array and it will decode the response.
	  * It is usefull to search for a particular key and displaying arrays.
	  * code ist from 
	  * https://www.paypal.com/en_US/ebook/PP_NVPAPI_DeveloperGuide/index.html
	  * Paypal Examples
	  * @nvpstr is NVPString.
	  * @nvpArray is Associative Array.
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
	
	
	function constants() {
/* Define the PayPal URL. This is the URL that the buyer is
   first sent to to authorize payment with their paypal account
   change the URL depending if you are testing on the sandbox
   or going to the live PayPal site
   For the sandbox, the URL is
   https://www.sandbox.paypal.com/webscr&cmd=_express-checkout&token=
   For the live site, the URL is
   https://www.paypal.com/webscr&cmd=_express-checkout&token=
   */
		$ext_conf = unserialize($GLOBALS['TYPO3_CONF_VARS']['EXT']['extConf']['paypal2commerce']);
		$this->paypal['API_Endpoint'] = $ext_conf['api_endpoint'];
		$this->paypal['version'] = '2.3';
		$this->paypal['API_UserName'] = $ext_conf['api_username'];
		$this->paypal['API_Password'] = $ext_conf['api_password'];
		$this->paypal['API_Signature'] = $ext_conf['api_signature'];
		$this->paypal['nvp_Header'] = '';
		$this->paypal['PAYPAL_URL'] = $ext_conf['paypal_url'];
		// Seitting Curl-Options
		$this->paypal['curl_timeout'] = (isset($ext_conf['curl_timeout'])?intval($ext_conf['curl_timeout']):20);
		$this->paypal['curl_verifypeer'] = (isset($ext_conf['curl_verifypeer'])?(boolean)$ext_conf['curl_verifypeer']:false);
		$this->paypal['curl_verifyhost'] = (isset($ext_conf['curl_verifyhost'])?(boolean)$ext_conf['curl_verifyhost']:false);

		// Debugging Options
		$this->paypal['debug'] = intval($ext_conf['debug']);
	}

	/**
	 * Sends the customer via header() to paypal.
	 */
	function sendToPaypal($amount,$currencyCodeType = 'EUR') {
		$paymentAmount=sprintf("%01.2f",($amount/100));
		$currencyCodeType='EUR';
		$paymentType='Sale'; 
		/* The returnURL is the location where buyers return when a
			payment has been succesfully authorized.
			The cancelURL is the location buyers are sent to when they hit the
			cancel button during authorization of payment during the PayPal flow
		*/
// todo: korrigieren!
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
		// URL where the costomer will be send to, if the payment has been
		// successfully authorized
		$returnURL = urlencode($baseurl.$GLOBALS['TSFE']->cObj->typoLink_URL($conf));
		$conf['additionalParams'] = '&paymentType='.$paymentType;
		// URL where the costomer will be send to, if he canceld the payment
		$cancelURL = urlencode($baseurl.$GLOBALS['TSFE']->cObj->typoLink_URL($conf));
		
		// Papyal Call - will be send via CURL
		$nvpstr="&Amt=".$paymentAmount."&PAYMENTACTION=".$paymentType."&ReturnUrl=".$returnURL."&CANCELURL=".$cancelURL ."&CURRENCYCODE=".$currencyCodeType.'&address_override=1'.'&ADDRESSOVERRIDE=1';

		 /* Make the call to PayPal to set the Express Checkout token
			If the API call succeded, then redirect the buyer to PayPal
			to begin to authorize payment.  If an error occured, show the
			resulting errors
			*/
		$resArray=$this->hash_call("SetExpressCheckout",$nvpstr);
		$_SESSION['reshash']=$resArray;

		$ack = strtoupper($resArray["ACK"]);

		if($ack=="SUCCESS"){
			// Redirect to paypal.com here
			$token = urldecode($resArray["TOKEN"]);
			$payPalURL = $this->paypal['PAYPAL_URL'].$token;
			header("Location: ".t3lib_div::locationHeaderUrl($payPalURL));
			exit();
		} else  {
			// Debugging? Print Errors end with an die
			if (1 == $this->paypal['debug']) {
				t3lib_div::debug('An Error occured - Paypal does not send ACK=SUCCESS');
				t3lib_div::debug($resArray);
				t3lib_div::debug('Hash-Call: '.$nvpstr);
				die('NOACK');
			} else {
				// Jump to the cancelurl - via
				// Typoscript and conditions it is possible to manage error
				// message
				$conf['additionalParams'] = '&paymentType=sale&paypal=noack';
				$cancelURL = $baseurl.$GLOBALS['TSFE']->cObj->typoLink_URL($conf);
				Header('Location: '.t3lib_div::locationHeaderUrl($cancelURL));
				exit;
			}
		}
	}
	
	/**
	 * gibt bei success true zurück, sonst false
	 */

	function getFromPaypal() {
		$token =urlencode( $_REQUEST['token']);
		$paymentAmount =urlencode ($_REQUEST['paymentAmount']);
		$paymentType = urlencode($_REQUEST['paymentType']);
		$currCodeType = urlencode($_REQUEST['currencyCodeType']);
		$payerID = urlencode($_REQUEST['PayerID']);
		$serverName = urlencode($_SERVER['SERVER_NAME']);
		$nvpstr='&TOKEN='.$token.'&PAYERID='.$payerID.'&PAYMENTACTION='.$paymentType.'&AMT='.$paymentAmount.'&CURRENCYCODE='.$currCodeType.'&IPADDRESS='.$serverName ;

		$resArray=$this->hash_call("DoExpressCheckoutPayment",$nvpstr);

		$ack = strtoupper($resArray["ACK"]);

		if($ack!="SUCCESS") return false;
		// BUGFIX
		// check if the amount payed via paypal is the same, as the
		// amount in the basket (otherwise the customer could pay less
		// then he has ordered - if the merchant does not check the
		// billing he would lose money
		$basket = $GLOBALS['TSFE']->fe_user->tx_commerce_basket;
		if ($basket->basket_sum_gross == $resArray['AMT']*100) {
			return true;
		}
// TODO: Error Handling, maybe canceling the paypal transaction?
/*
		die('Bei Paypal wurde '. $resArray['AMT'].' überwiesen das sind '.($resArray['AMT']*100).' Cent und das ist nicht '.$basket->basket_sum_gross.'<br>');
*/
    		return false;
	}
	
	function checkFromPaypal() {
		$token =urlencode( $_REQUEST['token']);
		$paymentAmount =urlencode ($_REQUEST['paymentAmount']);
		$paymentType = urlencode($_REQUEST['paymentType']);
		$currCodeType = urlencode($_REQUEST['currencyCodeType']);
		$payerID = urlencode($_REQUEST['PayerID']);
		$serverName = urlencode($_SERVER['SERVER_NAME']);
		$nvpstr='&TOKEN='.$token.'&PAYERID='.$payerID.'&PAYMENTACTION='.$paymentType.'&AMT='.$paymentAmount.'&CURRENCYCODE='.$currCodeType.'&IPADDRESS='.$serverName ;
		$resArray=$this->hash_call("GetExpressCheckoutDetails",$nvpstr);
		$this->resArray = $resArray;
		$ack = strtoupper($resArray["ACK"]);
		if($ack!="SUCCESS") return false;
    		return true;		
	}

	function getAdditonalFieldsConfig($pObj) {
		if(!is_object($this->pObj)) {
			$this->pObj = $pObj;
		}
		return array();
	}


	function getSpecialFinishingForm($config, $session, $basket,$pObj) {
		$content = 'Error - there is something wrong... sorry, cannot finish your order.';;
		return $content;
	}
	
	function hasSpecialFinishingForm($_REQUEST,$pObj) {
		if (!empty($_REQUEST['token'])) {
			// redirect from paypal
			if ($this->getFromPaypal()) {
				// Erfolgreich
				$result = true;
				return false; // Abschließen
			} else {
				// FEHLER
// TODO: Error handling
				$this->errorMessages['startPaypal'] = 'Error...';
				$result = false;
				return true; // Formular anzeigen!
			}
		} 
	}


	function proofData($formData,$pObj) {
		if(!is_object($this->pObj)) {
			$this->pObj = $pObj;
		}
		return true;
	}
	
	/**
	 * This method is called in the last step. Here can be made some final checks or whatever is
	 * needed to be done before saving some data in the database.
	 * Write any errors into $this->errorMessages!
	 * To save some additonal data in the database use the method updateOrder().
	 *
	 * @param	array	$config: The configuration from the TYPO3_CONF_VARS
	 * @param	array	$basket: The basket object
	 *
	 * @return boolean	True or false
	 */
	function finishingFunction($config,$session, $basket,$pObj) {
		if(!is_object($this->pObj)) {
			$this->pObj = $pObj;
		}
		// ok, we get the form
		if ('' != t3lib_div::_POST('tx_commerce_pi3')) {
			$GLOBALS['TSFE']->fe_user->setKey('ses', 'comment', $pObj->piVars['comment']);
			$GLOBALS["TSFE"]->storeSessionData();
			$this->sendToPaypal($basket->basket_sum_gross);
			exit();
		}
		if (!$this->checkFromPaypal()) {
			return false;			
		} else {
			// do not forget the comment;)
			$this->comment = $GLOBALS['TSFE']->fe_user->getKey('ses', 'comment');
			$this->paymentRefId = 'CORRELATIONID'.$this->resArray['CORRELATIONID'].'PAYERID'.$this->resArray['PAYERID'];
			return true;
		}
	}
	
	/**
	 * 
	 * Saveing the Order - Storing Paypal payment_ref_id and the comment
	 */
	function updateOrder($orderUid, $session,$pObj) {
		if(!is_object($this->pObj)) {
			$this->pObj = $pObj;
		}
		$GLOBALS['TYPO3_DB']->exec_UPDATEquery(
			'tx_commerce_orders','uid = '.$orderUid,
			array('payment_ref_id' => $this->paymentRefId, 'comment' => $this->comment)
		);
	}
	
	/**
	 * Returns the last error message
	 */
	function getLastError($finish = 0,$pObj) {
		if(!is_object($this->pObj)) {
			$this->pObj = $pObj;
		}
		if($finish){
		    return $this->getReadableError();
		}else{
			return $this->errorMessages[(count($this->errorMessages) -1)];
		}
	}
	
	// creditcard Error Code Handling
	function getReadableError(){
		$back = '';
		reset($this->errorMessages);
	    	while(list($k,$v) =each($this->errorMessages)){
			$back .= $v;
		}
		return $back;
	
	}
	
	
}

if (defined('TYPO3_MODE') && $TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']["ext/paypal2commerce/class.tx_paypal2commerce.php"])	{
	include_once($TYPO3_CONF_VARS[TYPO3_MODE]['XCLASS']["ext/paypal2commerce/class.tx_paypal2commerce.php"]);
}

?>