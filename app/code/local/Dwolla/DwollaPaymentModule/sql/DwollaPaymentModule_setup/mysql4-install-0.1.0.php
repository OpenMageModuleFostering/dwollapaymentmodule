$installer = new Mage_Sales_Model_Mysql4_Setup;
$installer->startSetup();
$attribute  = array(
        'type'          => 'text',
        'backend_type'  => 'text',
        'frontend_input' => 'text',
        'is_user_defined' => true,
        'label'         => 'Transaction Id',
        'visible'       => true,
        'required'      => false,
        'user_defined'  => false,  
        'searchable'    => false,
        'filterable'    => false,
        'comparable'    => false,
        'default'       => ''
);
$installer->addAttribute('order', 'transaction_id', $attribute);
$installer->endSetup();