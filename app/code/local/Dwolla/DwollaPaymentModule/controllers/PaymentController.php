<?php
/**
 * Dwolla Payment Module for Magento > 1.6
 *
 * @category    Dwolla
 * @package     Dwolla_DwollaPaymentModule
 * @copyright   Copyright (c) 2012 Dwolla Inc. (http://www.dwolla.com)
 * @autor       Gordon Zheng <gordon@dwolla.com>, Michael Schonfeld <michael@dwolla.com>
 * @version   	3.0.1
 * @license     http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */

class Dwolla_DwollaPaymentModule_PaymentController extends Mage_Core_Controller_Front_Action {
	public function redirectAction() {
		$this->loadLayout();
		$block = $this->getLayout()->createBlock('Mage_Core_Block_Template','dwollaPaymentModule',array('template' => 'dwollaPaymentModule/redirect.phtml')); 
		$this->getLayout()->getBlock('content')->append($block);
		$this->renderLayout();
	}
	
	public function webhookAction() {
		/*
		Receives transactions status change notifications and updates order statuses
		*/
				
		// retrieve user configuration:
		$apiSecret = Mage::getStoreConfig('payment/dwollaPaymentModule/dwollaApiSecret');

		// parse raw json-encoded POST data
		$json_data = $this->getRequest()->getRawBody();
		$data = json_decode($json_data);
		
		$type = $data->Type;
		$subtype = $data->Subtype;
		$id = $data->Id;
		$created = $data->Created;
		$triggered = $data->Triggered;
		$value = $data->Value;		
		
		// verify signature:
		$signature = $this->getRequest()->getHeader('X-Dwolla-Signature');
		
		$hash = hash_hmac('sha1', $json_data, $apiSecret);
		$validated = ($hash == $signature);

		// retrieve order based on transaction ID:
		$orders = Mage::getModel('sales/order')->getCollection()->addFieldToFilter('dwolla_transaction_id', $id);

		// If no orders were found, wait 5 seconds, then try again
		// This might happen when users pay from their Dwolla balance
		// and the Webhook hits before the redirect registers
		if(!$orders || sizeof($orders) < 1) {
			sleep(5);
			$orders = Mage::getModel('sales/order')->getCollection()->addFieldToFilter('dwolla_transaction_id', $id);
		}

		// update order state and status based on notification of new status:
		$status_to_order_state = array(
			"completed" => Mage_Sales_Model_Order::STATE_PROCESSING,
			"pending"   => Mage_Sales_Model_Order::STATE_PROCESSING,
			"failed"    => Mage_Sales_Model_Order::STATE_CANCELED,
			"cancelled" => Mage_Sales_Model_Order::STATE_CANCELED,
			"reclaimed" => Mage_Sales_Model_Order::STATE_CANCELED,
			"processed" => "payment_complete"
		);

		foreach($orders as $order) {
			$message = '';

			if($validated) {
				$message = "Current Dwolla transaction status: {$value}, as of {$triggered}. Dwolla's signature was successfully verified.";
			} else {
				$value = 'failed';
				$message = "Dwolla's signature failed to verify. Terminating order. Contact support@dwolla.com with the following information: Proposed signature: {$signature} ... Expected Signature: {$hash} ... Body: {$json_data}";
			}

			$order->setState($status_to_order_state[$value], true, $message);
			$order->setStatus($status_to_order_state[$value]);
			$order->save();

			echo "Magento order ID #{$order['increment_id']} / Dwolla Transaction ID #{$id} is now set to {$status_to_order_state[$value]}.";
		}

		// always respond to Dwolla with 200/OK, else Dwolla will try sending a second time:
		$this->getResponse()->setHttpResponseCode(200);
	}
	
	public function responseAction() {
		// Get User Configuration
		$dwollaId = Mage::getStoreConfig('payment/dwollaPaymentModule/dwollaId');
		$apiKey = Mage::getStoreConfig('payment/dwollaPaymentModule/dwollaApiKey');
		$apiSecret = Mage::getStoreConfig('payment/dwollaPaymentModule/dwollaApiSecret');

		// Grab Dwolla's Response
		$orderId = $this->getRequest()->orderId;
		$checkoutId = $this->getRequest()->checkoutId;
		$transactionId = $this->getRequest()->transaction;
		$amount = number_format($this->getRequest()->amount, 2);
		$signature = $this->getRequest()->signature;

		// Check if the user cancelled, or
		// if there was any other error
		$isError = $this->getRequest()->error;
		$errorDescription = $this->getRequest()->error_description;

		if($isError) {
			return $this->cancelAction($orderId, $errorDescription);
		}

		// Validate Dwolla's Signature
		$string = "{$checkoutId}&{$amount}";
		$hash = hash_hmac('sha1', $string, $apiSecret);
		$validated = ($hash == $signature);

		if($validated) {
			$order = Mage::getModel('sales/order');
			$order->loadByIncrementId($orderId);
			$order->setDwollaTransactionId($transactionId);
			$order->setState(Mage_Sales_Model_Order::STATE_PROCESSING, true, "Dwolla Gateway informed us: the payment's Transaction ID is {$transactionId} / Checkout ID {$checkoutId}");

			$order->sendNewOrderEmail();
			$order->setEmailSent(true);
			$order->save();

			Mage::getSingleton('checkout/session')->unsQuoteId();

			Mage_Core_Controller_Varien_Action::_redirect('checkout/onepage/success', array('_secure'=>true));
		}
		else {
			$this->cancelAction(FALSE, 'Dwolla signature did not validate');
			Mage_Core_Controller_Varien_Action::_redirect('checkout/onepage/failure', array('_secure'=>true));
		}
	}

	public function cancelAction($orderId = FALSE, $errorDescription = "Unknown reason") {
		if(!$orderId) {
		  $orderId = Mage::getSingleton('checkout/session')->getLastRealOrderId();
		}

	    if ($orderId) {
	      $order = Mage::getModel('sales/order')->loadByIncrementId($orderId);
	      if($order->getId()) {
	        $order->cancel()->setState(Mage_Sales_Model_Order::STATE_CANCELED, true, "Gateway has declined the payment: {$errorDescription}.")->save();
	        Mage_Core_Controller_Varien_Action::_redirect('checkout/onepage/failure', array('_secure'=>true));
	      }
	    }
	}
}