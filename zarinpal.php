<?php
/**
 * @package     Joomla - > Site and Administrator payment info
 * @subpackage  com_virtuemart
 * @subpackage 		zarinpal
 * @copyright   trangell team => https://trangell.com
 * @copyright   Copyright (C) 20016 Open Source Matters, Inc. All rights reserved.
 * @license     GNU General Public License version 2 or later; see LICENSE.txt
 */
defined ('_JEXEC') or die('Restricted access');

if (!class_exists ('vmPSPlugin')) {
	require(JPATH_VM_PLUGINS . '/vmpsplugin.php');
}

if (!class_exists ('checkHack')) {
	require_once( VMPATH_ROOT . '/plugins/vmpayment/zarinpal/helper/inputcheck.php');
}


class plgVmPaymentZarinpal extends vmPSPlugin {

	function __construct (& $subject, $config) {

		parent::__construct ($subject, $config);
		$this->_loggable = TRUE;
		$this->tableFields = array_keys ($this->getTableSQLFields ());
		$this->_tablepkey = 'id';
		$this->_tableId = 'id';
		$varsToPush = array('merchant_id' => array('', 'varchar'));
		$this->setConfigParameterable($this->_configTableFieldName, $varsToPush);
	}

	public function getVmPluginCreateTableSQL () {
		return $this->createTableSQL ('Payment Zarinpal Table');
	}

	function getTableSQLFields () {

		$SQLfields = array(
			'id'                          => 'int(1) UNSIGNED NOT NULL AUTO_INCREMENT',
			'virtuemart_order_id'         => 'int(1) UNSIGNED',
			'order_number'                => 'char(64)',
			'order_pass'                  => 'varchar(50)',
			'virtuemart_paymentmethod_id' => 'mediumint(1) UNSIGNED',
			'crypt_virtuemart_pid' 	      => 'varchar(255)',
			'salt'                        => 'varchar(255)',
			'payment_name'                => 'varchar(5000)',
			'amount'                      => 'decimal(15,5) NOT NULL DEFAULT \'0.00000\'',
			'payment_currency'            => 'char(3)',
			'email_currency'              => 'char(3)',
			'mobile'                      => 'varchar(12)',
			'tracking_code'               => 'varchar(50)'
		);

		return $SQLfields;
	}


	function plgVmConfirmedOrder ($cart, $order) {
		if (!($method = $this->getVmPluginMethod ($order['details']['BT']->virtuemart_paymentmethod_id))) {
			return NULL; 
		}

		$session = JFactory::getSession();
		$salt = JUserHelper::genRandomPassword(32);
		$crypt_virtuemartPID = JUserHelper::getCryptedPassword($order['details']['BT']->virtuemart_order_id, $salt);
		if ($session->isActive('uniq')) {
			$session->clear('uniq');
		}
		$session->set('uniq', $crypt_virtuemartPID);

		$payment_currency = $this->getPaymentCurrency($method,$order['details']['BT']->payment_currency_id);
		$totalInPaymentCurrency = vmPSPlugin::getAmountInCurrency($order['details']['BT']->order_total,$payment_currency);
		$currency_code_3 = shopFunctions::getCurrencyByID($payment_currency, 'currency_code_3');
		$email_currency = $this->getEmailCurrency($method);
		$dbValues['payment_name'] = $this->renderPluginName ($method) . '<br />';
		$dbValues['order_number'] = $order['details']['BT']->order_number;
		$dbValues['order_pass'] = $order['details']['BT']->order_pass;
		$dbValues['virtuemart_paymentmethod_id'] = $order['details']['BT']->virtuemart_paymentmethod_id;
		$dbValues['crypt_virtuemart_pid'] = $crypt_virtuemartPID;
		$dbValues['salt'] = $salt;
		$dbValues['payment_currency'] = $order['details']['BT']->order_currency;
		$dbValues['email_currency'] = $email_currency;
		$dbValues['amount'] = $totalInPaymentCurrency['value'];
		$dbValues['mobile'] = $order['details']['BT']->phone_2;
		$this->storePSPluginInternalData ($dbValues);
		$id = JUserHelper::getCryptedPassword($order['details']['BT']->virtuemart_order_id);
		$app	= JFactory::getApplication();
		$Amount = $totalInPaymentCurrency['value']/10; // Toman 
		$Description = 'خرید محصول از فروشگاه   '. $cart->vendor->vendor_store_name; 
		$MerchantID = $method->merchant_id;
		$CallbackURL = JURI::root().'index.php?option=com_virtuemart&view=pluginresponse&task=pluginresponsereceived'; 
		
		try {
			 $client = new SoapClient('https://www.zarinpal.com/pg/services/WebGate/wsdl', ['encoding' => 'UTF-8']); 	
			// $client = new SoapClient('https://sandbox.zarinpal.com/pg/services/WebGate/wsdl', ['encoding' => 'UTF-8']); // for local

			$result = $client->PaymentRequest(
				[
					'MerchantID' => $MerchantID,
					'Amount' => $Amount,
					'Description' => $Description,
					'Email' => '',
					'Mobile' => '',
					'CallbackURL' => $CallbackURL,
				]
			);
			
			$resultStatus = abs($result->Status); 
			if ($resultStatus == 100) {
			
			Header('Location: https://www.zarinpal.com/pg/StartPay/'.$result->Authority); 
			// Header('Location: https://sandbox.zarinpal.com/pg/StartPay/'.$result->Authority); // for local/
			} else {
				$msg= $this->getGateMsg('error'); 
				$this->updateStatus ('U',0,$msg,$id); 
				$app	= JFactory::getApplication();
				$link = JRoute::_(JUri::root().'index.php/component/virtuemart/cart',false);
				$app->redirect($link, '<h2>'.$msg.'</h2>', $msgType='Error'); 
			}
		}
		catch(\SoapFault $e) {
			$msg= $this->getGateMsg('error'); 
			$this->updateStatus ('U',0,$msg,$id); 
			$app	= JFactory::getApplication();
			$link = JRoute::_(JUri::root().'index.php/component/virtuemart/cart',false);
			$app->redirect($link, '<h2>'.$msg.'</h2>', $msgType='Error'); 
		}
		
	}

public function plgVmOnPaymentResponseReceived(&$html) {
		$app = JFactory::getApplication();
		$jinput = $app->input;
		$Authority = $jinput->get->get('Authority', '0', 'INT');
		$status = $jinput->get->get('Status', '', 'STRING');
		
		$session = JFactory::getSession();
		if ($session->isActive('uniq')) {
			$cryptID = $session->get('uniq'); 
			$session->clear('uniq'); 
		}
		else {
			$app	= JFactory::getApplication();
			$msg= $this->getGateMsg('notff'); 
			$link = JRoute::_(JUri::root().'index.php/component/virtuemart/cart',false);
			$app->redirect($link, '<h2>'.$msg.'</h2>'.$virtuemart_order_id, $msgType='Error'); 
		}
		
		$orderInfo = $this->getOrderInfo ($cryptID);
		$salt = $orderInfo->salt;
		$id = $orderInfo->virtuemart_order_id;
		$uId = $cryptID.':'.$salt;
		
		$order_id = $orderInfo->order_number; 
		//$mobile = $orderInfo->mobile; 
		$payment_id = $orderInfo->virtuemart_paymentmethod_id; 
		$pass_id = $orderInfo->order_pass;
		$price = round($orderInfo->amount,5);
		$method = $this->getVmPluginMethod ($payment_id);
		
		if (checkHack::checkString($status) != true){
			$app	= JFactory::getApplication();
			$msg= $this->getGateMsg('hck2'); 
			$this->updateStatus ('U',0,$msg,$id); 
			$link = JRoute::_(JUri::root().'index.php/component/virtuemart/cart',false);
			$app->redirect($link, '<h2>'.$msg.'</h2>'.$virtuemart_order_id, $msgType='Error'); 
		}
		
		if (JUserHelper::verifyPassword($id , $uId)) {
			if ($status == 'OK') {
				try {
				    $client = new SoapClient('https://www.zarinpal.com/pg/services/WebGate/wsdl', ['encoding' => 'UTF-8']); 
					// $client = new SoapClient('https://sandbox.zarinpal.com/pg/services/WebGate/wsdl', ['encoding' => 'UTF-8']); // for local
					$result = $client->PaymentVerification(
						[
							'MerchantID' => $method->merchant_id,
							'Authority' => $Authority,
							'Amount' => $price/10,
						]
					);
					$resultStatus = abs($result->Status); 
					if ($resultStatus == 100) {
						$msg= $this->getGateMsg($resultStatus);
						$html = $this->renderByLayout('zarinpal_payment', array(
							'order_number' =>$order_id,
							'order_pass' =>$pass_id,
							'tracking_code' => $result->RefID,
							'status' => $msg
						));
						$this->updateStatus ('C',1,$msg,$id);
						$this->updateOrderInfo ($id,$result->RefID);
						vRequest::setVar ('html', $html);
						$cart = VirtueMartCart::getCart();
						$cart->emptyCart();
					} 
					else {
						$msg= $this->getGateMsg($resultStatus);
						$this->updateStatus ('U',0,$msg,$id); 
						$link = JRoute::_(JUri::root().'index.php/component/virtuemart/cart',false);
						$app->redirect($link, '<h2>'.$msg.'</h2>', $msgType='Error'); 
					}
				}
				catch(\SoapFault $e) {
					$msg= $this->getGateMsg('error'); 
					$this->updateStatus ('U',0,$msg,$id); 
					$app	= JFactory::getApplication();
					$link = JRoute::_(JUri::root().'index.php/component/virtuemart/cart',false);
					$app->redirect($link, '<h2>'.$msg.'</h2>', $msgType='Error'); 
				}
			
			}
			else {
				$msg= $this->getGateMsg(intval(17)); 
				$this->updateStatus ('X',0,$msg,$id);
				$app	= JFactory::getApplication();
				$link = JRoute::_(JUri::root().'index.php/component/virtuemart/cart',false);
				$app->redirect($link, '<h2>'.$msg.'</h2>', $msgType='Error'); 
			}
		}
		else {	
			$msg= $this->getGateMsg('notff');
			$this->updateStatus ('U',0,$msg,$id); 
			$app	= JFactory::getApplication();
			$link = JRoute::_(JUri::root().'index.php/component/virtuemart/cart',false);
			$app->redirect($link, '<h2>'.$msg.'</h2>', $msgType='Error'); 
		}
	}


	protected function getOrderInfo ($id){
		$db = JFactory::getDbo();
		$query = $db->getQuery(true);
		$query->select('*')
			->from($db->qn('#__virtuemart_payment_plg_zarinpal'));
		$query->where($db->qn('crypt_virtuemart_pid') . ' = ' . $db->q($id));
		$db->setQuery((string)$query); 
		$result = $db->loadObject();
		return $result;
	}

	protected function updateOrderInfo ($id,$trackingCode){
		$db = JFactory::getDbo();
		$query = $db->getQuery(true);
		$fields = array($db->qn('tracking_code') . ' = ' . $db->q($trackingCode));
		$conditions = array($db->qn('virtuemart_order_id') . ' = ' . $db->q($id));
		$query->update($db->qn('#__virtuemart_payment_plg_zarinpal'));
		$query->set($fields);
		$query->where($conditions);
		
		$db->setQuery($query);
		$result = $db->execute();
	}

	
	protected function checkConditions ($cart, $method, $cart_prices) {
		$amount = $this->getCartAmount($cart_prices);
		$address = (($cart->ST == 0) ? $cart->BT : $cart->ST);

		if($this->_toConvert){
			$this->convertToVendorCurrency($method);
		}
		
		$countries = array();
		if (!empty($method->countries)) {
			if (!is_array ($method->countries)) {
				$countries[0] = $method->countries;
			} else {
				$countries = $method->countries;
			}
		}

		if (!is_array ($address)) {
			$address = array();
			$address['virtuemart_country_id'] = 0;
		}

		if (!isset($address['virtuemart_country_id'])) {
			$address['virtuemart_country_id'] = 0;
		}
		if (count ($countries) == 0 || in_array ($address['virtuemart_country_id'], $countries) ) {
			return TRUE;
		}

		return FALSE;
	}
	
	public function plgVmOnSelectCheckPayment (VirtueMartCart $cart, &$msg) {
		return $this->OnSelectCheck ($cart);
	}
 
	function plgVmOnCheckAutomaticSelectedPayment (VirtueMartCart $cart, array $cart_prices = array(), &$paymentCounter) { 
		return $this->onCheckAutomaticSelected ($cart, $cart_prices, $paymentCounter);
	}

	public function plgVmonSelectedCalculatePricePayment (VirtueMartCart $cart, array &$cart_prices, &$cart_prices_name) { 
		return $this->onSelectedCalculatePrice ($cart, $cart_prices, $cart_prices_name);
	}

	public function plgVmOnCheckoutCheckDataPayment(  VirtueMartCart $cart) { 
			return true;
	}

	function plgVmOnStoreInstallPaymentPluginTable ($jplugin_id) {

		return $this->onStoreInstallPluginTable ($jplugin_id);
	}
	 
	
	function plgVmonShowOrderPrintPayment ($order_number, $method_id) {
		return $this->onShowOrderPrint ($order_number, $method_id);
	}

	function plgVmDeclarePluginParamsPaymentVM3( &$data) {
		return $this->declarePluginParams('payment', $data);
	}
	function plgVmSetOnTablePluginParamsPayment ($name, $id, &$table) {

		return $this->setOnTablePluginParams ($name, $id, $table);
	}

	static function getPaymentCurrency (&$method, $selectedUserCurrency = false) {

		if (empty($method->payment_currency)) {
			$vendor_model = VmModel::getModel('vendor');
			$vendor = $vendor_model->getVendor($method->virtuemart_vendor_id);
			$method->payment_currency = $vendor->vendor_currency;
			return $method->payment_currency;
		} else {

			$vendor_model = VmModel::getModel( 'vendor' );
			$vendor_currencies = $vendor_model->getVendorAndAcceptedCurrencies( $method->virtuemart_vendor_id );

			if(!$selectedUserCurrency) {
				if($method->payment_currency == -1) {
					$mainframe = JFactory::getApplication();
					$selectedUserCurrency = $mainframe->getUserStateFromRequest( "virtuemart_currency_id", 'virtuemart_currency_id', vRequest::getInt( 'virtuemart_currency_id', $vendor_currencies['vendor_currency'] ) );
				} else {
					$selectedUserCurrency = $method->payment_currency;
				}
			}

			$vendor_currencies['all_currencies'] = explode(',', $vendor_currencies['all_currencies']);
			if(in_array($selectedUserCurrency,$vendor_currencies['all_currencies'])){
				$method->payment_currency = $selectedUserCurrency;
			} else {
				$method->payment_currency = $vendor_currencies['vendor_currency'];
			}

			return $method->payment_currency;
		}

	}

	public function getGateMsg ($msgId) {
		switch($msgId){
			case	11: $out =  'شماره کارت نامعتبر است';break;
			case	12: $out =  'موجودي کافي نيست';break;
			case	13: $out =  'رمز نادرست است';break;
			case	14: $out =  'تعداد دفعات وارد کردن رمز بيش از حد مجاز است';break;
			case	15: $out =   'کارت نامعتبر است';break;
			case	17: $out =   'کاربر از انجام تراکنش منصرف شده است';break;
			case	18: $out =   'تاريخ انقضاي کارت گذشته است';break;
			case	21: $out =   'پذيرنده نامعتبر است';break;
			case	22: $out =   'ترمينال مجوز ارايه سرويس درخواستي را ندارد';break;
			case	23: $out =   'خطاي امنيتي رخ داده است';break;
			case	24: $out =   'اطلاعات کاربري پذيرنده نامعتبر است';break;
			case	25: $out =   'مبلغ نامعتبر است';break;
			case	31: $out =  'پاسخ نامعتبر است';break;
			case	32: $out =   'فرمت اطلاعات وارد شده صحيح نمي باشد';break;
			case	33: $out =   'حساب نامعتبر است';break;
			case	34: $out =   'خطاي سيستمي';break;
			case	35: $out =   'تاريخ نامعتبر است';break;
			case	41: $out =   'شماره درخواست تکراري است';break;
			case	42: $out =   'تراکنش Sale يافت نشد';break;
			case	43: $out =   'قبلا درخواست Verify داده شده است';break;
			case	44: $out =   'درخواست Verify يافت نشد';break;
			case	45: $out =   'تراکنش Settle شده است';break;
			case	46: $out =   'تراکنش Settle نشده است';break;
			case	47: $out =   'تراکنش Settle يافت نشد';break;
			case	48: $out =   'تراکنش Reverse شده است';break;
			case	49: $out =   'تراکنش Refund يافت نشد';break;
			case	51: $out =   'تراکنش تکراري است';break;
			case	52: $out =   'سرويس درخواستي موجود نمي باشد';break;
			case	54: $out =   'تراکنش مرجع موجود نيست';break;
			case	55: $out =   'تراکنش نامعتبر است';break;
			case	61: $out =   'خطا در واريز';break;
			case	100: $out =   'تراکنش با موفقيت انجام شد.';break;
			case	111: $out =   'صادر کننده کارت نامعتبر است';break;
			case	112: $out =   'خطاي سوئيچ صادر کننده کارت';break;
			case	113: $out =   'پاسخي از صادر کننده کارت دريافت نشد';break;
			case	114: $out =   'دارنده کارت مجاز به انجام اين تراکنش نيست';break;
			case	412: $out =   'شناسه قبض نادرست است';break;
			case	413: $out =   'شناسه پرداخت نادرست است';break;
			case	414: $out =   'سازمان صادر کننده قبض نامعتبر است';break;
			case	415: $out =   'زمان جلسه کاري به پايان رسيده است';break;
			case	416: $out =   'خطا در ثبت اطلاعات';break;
			case	417: $out =   'شناسه پرداخت کننده نامعتبر است';break;
			case	418: $out =   'اشکال در تعريف اطلاعات مشتري';break;
			case	419: $out =   'تعداد دفعات ورود اطلاعات از حد مجاز گذشته است';break;
			case	421: $out =   'IP نامعتبر است';break;
			case	500: $out =   'کاربر به صفحه زرین پال رفته ولي هنوز بر نگشته است';break;
			case	'error': $out ='خطا غیر منتظره رخ داده است';break;
			case	'hck2': $out = 'لطفا از کاراکترهای مجاز استفاده کنید';break;
			case	'notff': $out = 'سفارش پیدا نشد';break;
		}
		return $out;
	}

	protected function updateStatus ($status,$notified,$comments='',$id) {
		$modelOrder = VmModel::getModel ('orders');	
		$order['order_status'] = $status;
		$order['customer_notified'] = $notified;
		$order['comments'] = $comments;
		$modelOrder->updateStatusForOneOrder ($id, $order, TRUE);
	}

}
