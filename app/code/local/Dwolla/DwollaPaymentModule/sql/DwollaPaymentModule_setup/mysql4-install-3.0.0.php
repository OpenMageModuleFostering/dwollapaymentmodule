<?php
$installer = new Mage_Sales_Model_Mysql4_Setup;

$installer->startSetup();

$attribute  = array(
  'type'          => 'text',
  'backend_type'  => 'text',
  'frontend_input' => 'text',
  'is_user_defined' => true,
  'label'         => 'Dwolla Transaction ID',
  'visible'       => true,
  'required'      => false,
  'user_defined'  => false,  
  'searchable'    => true,
  'filterable'    => true,
  'comparable'    => false,
  'default'       => ''
);
$installer->addAttribute('order', 'dwolla_transaction_id', $attribute);

$status = Mage::getModel('sales/order_status');

$status->setStatus('payment_complete')->setLabel('Payment Complete')
    ->assignState('payment_complete') //for example, use any available existing state
    ->save();

$installer->endSetup();