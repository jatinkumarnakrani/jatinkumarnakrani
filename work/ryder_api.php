<?php
require_once 'abstract.php';
class Ryder_Api extends Mage_Shell_Abstract
{
    protected $_action = NULL;
    protected $_shipmentNumbers = array();

    public function __construct()
    {
        parent::__construct();
        ignore_user_abort(true);
        set_time_limit(0);
        ini_set('dispay_errors', 'On');
        error_reporting(1);
    }

    public function setShipmentNumbers($shipmentNumbers)
    {
        $this->_shipmentNumbers = $shipmentNumbers;
        return $this;
    }

    public function setAction($action)
    {
        $this->_action = $action;
        return $this;
    }

    public function getAction()
    {
        return $this->_action;
    }

    public function getShipmentNumbers()
    {
        return $this->_shipmentNumbers;
    }


    // Shell script point of entry
    public function run()
    {
        try {
            if ($this->getArg('action')) {
                $this->setAction($this->getArg('action'));
            }

            if(!$this->getAction()){
                throw new Exception("action is required parameter");
            }

            if ($this->getArg('shipment_numbers')) {
                $this->setShipmentNumbers(explode(",",$this->getArg('shipment_numbers')));
            }
            if(!$this->getShipmentNumbers()){
                throw new Exception("shipment_numbers is required parameter.");
            }

            $action = $this->getArg('action');
            switch (strtolower($action)) {
                case 'create':
                    $this->_processToCreateShipment();
                    break;
                case 'update':
                    $this->_processToUpdateShipment();
                    break;
                case 'cancel':
                    $this->_processToCancelShipment();
                    break;
                default:
                    throw new Exception('Action Not Found', 404);
            }
        } catch (Exception $e) {
            echo $e->getMessage();
        }
    }

    protected function _processToCreateShipment() {
        $success = [];
        $error = [];
        $messages = [];

        try {

            $shipments = $this->getCreateShipments();
            foreach ($shipments as $key => $shipment) {
               try {
                   Mage::getModel('edi/edicarrier_ryder_order')
                       ->setOrder($shipment->getOrder())
                       ->setShipment($shipment)
                       ->create();
                   $success[] = $shipment->getShipmentNumber();
               } catch (Exception $e) {
                   $error[] = $shipment->getShipmentNumber()."-".$e->getMessage();
               }
            }
            if(!empty($success)) {
                $messages[] =  Mage::helper('edi')->__("Total %s processed order's %s ", count($success), implode(' | ', $success));
            }
            if(!empty($error)) {
                $messages[] =  Mage::helper('edi')->__("Total %s failed order's %s ", count($error), implode(' | ', $error));
            }
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
        echo  implode("\n", $messages) . "\n";
    }

    public function getCreateShipments()
    {        
        $mfrCollection = Mage::getModel('manufacturer/order')->getCollection();
        $mfrCollection->getSelect()
            ->join(['SFO' => 'sales_flat_order'], "SFO.entity_id = `main_table`.order_id", [])
            ->join(array('SCO' => 'ccc_ship_carrier_order'), "`main_table`.`order_id` = `SCO`.`order_entity_id` AND `main_table`.`mfr_id` = `SCO`.`mfr_id` AND `main_table`.`shipment_id` = `SCO`.`shipment_id` AND main_table.ship_key_id = SCO.ship_key_id", array('wg_id' => 'SCO.wg_id', 'mfr_address_id' => 'SCO.mfr_address_id'))
            ->joinLeft(array('ECO' => 'edicarrier_order'), "main_table.order_id = ECO.order_id AND main_table.po_number=ECO.shipment_number", [])
            ->where('main_table.po_number IN (?)',$this->getShipmentNumbers())
        ;
                                
        $shipments = [];
        if(count($mfrCollection)) {
            foreach($mfrCollection as $mfrOrder) {
                $order = Mage::getModel('sales/order')
                        ->load($mfrOrder->getOrderId());
                $shippingMethod = Mage::getModel('edi/edicarrier_ryder_order')
                    ->getDeliveryGroupType($order, $mfrOrder->getDeliveryId());
                $shipment = new Varien_Object();
                $shipment->setMfrOrderId($mfrOrder->getId());
                $shipment->setOrder($order);
                $shipment->setShipmentNumber($mfrOrder->getPoNumber());
                $shipment->setOrderNumber($order->getIncrementId());
                $shipment->setOrderId($order->getId());
                $shipment->setMfrId($mfrOrder->getMfrId());
                $shipment->setShipmentId($mfrOrder->getShipmentId());
                $shipment->setBillingAddress(new Varien_Object($order->getBillingAddress()->getData()));
                $shipment->setShippingAddress(new Varien_Object($order->getShippingAddress()->getData()));
                $shipment->setMfrAddress(Mage::getModel('edi/edicarrier_ryder_order')->getMfrAddress($mfrOrder->getMfrAddressId()));
                $shipment->setPackageItems(Mage::getModel('edi/edicarrier_ryder_order')->getPackageItems($mfrOrder));

                if (empty($shipment->getPackageItems())) {
                   Mage::log(
                    array(
                          'order_id' => $order->getId(),
                          'increment_id' => $order->getIncrementId(),
                          'shipment_number' => $mfrOrder->getPoNumber(),
                          'shipment_id' => $mfrOrder->getShipmentId(),
                          'ship_key_id' => $mfrOrder->getShipKeyId(),
                          'mfr_id' => $mfrOrder->getMfrId(),
                          'mfr_entity_id' => $mfrOrder->getId(),
                          'wg_id' => $mfrOrder->getWgId(),
                         'customer_status' =>  $mfrOrder->getCustomerStatus(),
                         'internal_status' =>  $mfrOrder->getInternalStatus(),
                         'delivery_status' =>  $mfrOrder->getDeliveryStatus(),
                         'manufacturer_status' =>  $mfrOrder->getManufacturerStatus(),
                         'manufacturer_internal_status' =>  $mfrOrder->getManufacturerInternalStatus(),
                          'action_type' => 'new_order'
                    ),null,'ryder-no-item.log');
                   continue;
                }
                $shipment->setShippingMethod($shippingMethod);
                $shipment->setInternalStatus($mfrOrder->getInternalStatus());
                $shipment->setWgId($mfrOrder->getWgId());
                $shipments[] = $shipment;
            }
        }

        return $shipments;
    }

    protected function _processToUpdateShipment() {
        $success = [];
        $error = [];
        $messages = [];
        try {
            $updatedOrders = $this->getUpdatedOrders();
            foreach ($updatedOrders as $updatedOrder) {
                try {
                    Mage::getModel('edi/edicarrier_ryder_order')
                        ->setEdiOrder($updatedOrder)
                        ->update();
                    $success[] = $updatedOrder->getShipmentNumber();
                } catch (Exception $e) {
                    $error[] = $updatedOrder->getShipmentNumber()."-".$e->getMessage();
                }
            }
            if(!empty($success)) {
                $messages[] =  Mage::helper('edi')->__("Total %s processed order's %s ", count($success), implode(' | ', $success));
            }
            if(!empty($error)) {
                $messages[] =  Mage::helper('edi')->__("Total %s failed order's %s ", count($error), implode(' | ', $error));
            }
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
        echo  implode("\n", $messages) . "\n";
    }


    public function getUpdatedOrders()
    {

        $columns = array(
            'mfr_order_id' => 'CMO.entity_id',
            'order_id' => 'CMO.order_id',
            'mfr_id' => 'CMO.mfr_id',
            'shipment_id' => 'CMO.shipment_id',
            'ship_key_id' => 'CMO.ship_key_id',
            'brand_id' => 'CMO.brand_id',
            'mfr_name' => 'CM.mfg',
            'wg_id' => 'SCO.wg_id',
            'mfr_address_id' => 'SCO.mfr_address_id',
            'customer_status' => 'CMO.customer_status',
            'internal_status' => 'CMO.internal_status',
            'delivery_status' => 'CMO.delivery_status',
            'manufacturer_status' => 'CMO.manufacturer_status',
            'manufacturer_internal_status' => 'CMO.manufacturer_internal_status',
        );

        $updateCollection = Mage::getModel('edi/edicarrier_order')->getCollection();
        $updateCollection->getSelect()
            ->columns($columns)
            ->join(array('CMO' => 'ccc_manufacturer_order'), "main_table.order_id=CMO.order_id AND main_table.shipment_number=CMO.po_number", array())
            ->join(array('CM' => 'ccc_manufacturer'), 'main_table.mfr_id=CM.entity_id', array())
            ->joinLeft(array('SCO' => 'ccc_ship_carrier_order'), "CMO.order_id = SCO.order_entity_id AND CMO.mfr_id = SCO.mfr_id AND CMO.shipment_id = SCO.shipment_id AND CMO.ship_key_id = SCO.ship_key_id", array())
            ->join(array('SFOP' => 'sales_flat_order_payment'), "SFOP.parent_id = main_table.order_id", array())
            ->where('main_table.shipment_number IN (?)',$this->getShipmentNumbers())
            ->group('main_table.shipment_number');
        return Mage::getModel('edi/edicarrier_ryder_order')->setProcessType(Furnique_Edi_Model_Edicarrier_Ryder_Order::PROCESS_TYPE_UPDATE_TEXT)->prepareOrderList($updateCollection);
    }

    protected function _processToCancelShipment() {
        $success = [];
        $error = [];
        $messages = [];
        try {
            $cancelOrders = $this->getCancelOrders();
            foreach ($cancelOrders as $cancelOrder) {
                try {
                    Mage::getModel('edi/edicarrier_ryder_order')
                        ->setEdiOrder($cancelOrder)
                        ->cancelation();
                    $success[] = $cancelOrder->getShipmentNumber();
                } catch (Exception $e) {
                    $error[] = $cancelOrder->getShipmentNumber()."-".$e->getMessage();
                }
            }
            if(!empty($success)) {
                $messages[] =  Mage::helper('edi')->__("Total %s processed order's %s ", count($success), implode(' | ', $success));
            }
            if(!empty($error)) {
                $messages[] =  Mage::helper('edi')->__("Total %s failed order's %s ", count($error), implode(' | ', $error));
            }
        } catch (Exception $e) {
            throw new Exception($e->getMessage());
        }
        echo  implode("\n", $messages) . "\n";

    }

    public function getCancelOrders()
    {

        $columns = array(
            'mfr_order_id' => 'CMO.entity_id',
            'order_id' => 'CMO.order_id',
            'mfr_id' => 'CMO.mfr_id',
            'shipment_id' => 'CMO.shipment_id',
            'ship_key_id' => 'CMO.ship_key_id',
            'brand_id' => 'CMO.brand_id',
            'mfr_name' => 'CM.mfg',
            'wg_id' => 'SCO.wg_id',
            'mfr_address_id' => 'SCO.mfr_address_id',
            'customer_status' => 'CMO.customer_status',
            'internal_status' => 'CMO.internal_status',
            'delivery_status' => 'CMO.delivery_status',
            'manufacturer_status' => 'CMO.manufacturer_status',
            'manufacturer_internal_status' => 'CMO.manufacturer_internal_status',
        );
        $cancelCollection = Mage::getModel('edi/edicarrier_order')->getCollection();
        $cancelCollection->getSelect()
            ->columns($columns)
            ->join(array('CMO' => 'ccc_manufacturer_order'), "main_table.order_id=CMO.order_id AND main_table.shipment_number=CMO.po_number", array())
            ->join(array('CM' => 'ccc_manufacturer'), 'main_table.mfr_id=CM.entity_id', array())
            ->joinLeft(array('SCO' => 'ccc_ship_carrier_order'), "CMO.order_id = SCO.order_entity_id AND CMO.mfr_id = SCO.mfr_id AND CMO.shipment_id = SCO.shipment_id AND CMO.ship_key_id = SCO.ship_key_id", array())
            ->join(array('SFOP' => 'sales_flat_order_payment'), "SFOP.parent_id = main_table.order_id", array())
            ->where('main_table.shipment_number IN (?)',$this->getShipmentNumbers())
            ->group('main_table.shipment_number');

        return Mage::getModel('edi/edicarrier_ryder_order')->setProcessType(Furnique_Edi_Model_Edicarrier_Ryder_Order::PROCESS_TYPE_CANCELATION_TEXT)->prepareOrderList($cancelCollection);
    }
    // Usage instructions
    public function usageHelp()
    {
        return <<<USAGE
Usage:  php review_generate.php  -[options]
    options        |       arg.
  ---------------------------------------------------------------------------
    action          create : Create request for the shipment
                    update : Update request for the shipment
                    cancel : Cancel request for the shipment
                    realloction : Reallocation request for the shipment
                    asn : ASN request for the shipment only for ashley
                    ex.  -action create
  ---------------------------------------------------------------------------
    shipment_numbers Shipment Number which are stored in ccc_manufacturer_order table in po_number fiels
                    Required field
                    ex.  -shipment_numbers 8514657-S2

USAGE;
    }
}





// Instantiate
$shell = new Ryder_Api();
// Initiate script
$shell->run();
//php ryder_api.php -action create -shipment_numbers '8514657-S2,8514657-S2'