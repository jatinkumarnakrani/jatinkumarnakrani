<?php
require_once "../app/Mage.php";
Mage::app('default');
Mage::setIsDeveloperMode(true);
// Varien_Profiler::enable();
error_reporting(E_ALL ^ E_DEPRECATED);
ini_set('display_errors', 1);
// php ryder.php -action updateEdicarrierItemQty
echo '<pre>';
$read = Mage::getSingleton('core/resource')->getConnection('core_read');
try {
    $columns = array(
        'Order #' => 'EO.increment_id',
        'Shipment #' => 'EO.shipment_number',
        'Order Created Date' => 'SFO.created_at',
        'Shipment Created Date' => 'EO.created_at',
        'Brand' => 'CPEI.value',
        'MFR Status' => 'SOMS.label',
        'MFR Internal' => 'SOMIS.label',
        'Customer Status' => 'SOS.label',
        'Internal Status' => 'SOIS.label',
        'Shipping Name' => 'CSC.name'
    );

    $select = $read->select()
        ->from(array('EO'=>'edicarrier_order'),array())
        ->join(array('SFO'=>'sales_flat_order'),"EO.order_id=SFO.entity_id",array())
        ->join(array('CMO'=>'ccc_manufacturer_order'),"EO.order_id=CMO.order_id AND EO.shipment_number=CMO.po_number",array())
        ->join(array('CSCO'=>'ccc_ship_carrier_order'),"CMO.order_id=CSCO.order_entity_id AND CMO.mfr_id=CSCO.mfr_id AND CMO.shipment_id=CSCO.shipment_id AND CMO.ship_key_id=CSCO.ship_key_id",array())
        ->joinLeft(array('CSC'=>'ccc_ship_carrier'),"CSCO.wg_id=CSC.id",array())
        ->joinLeft(array('SOMS'=>'sales_order_manufacturer_status'),"CMO.manufacturer_status=SOMS.manufacturer_status",array())
        ->joinLeft(array('SOMIS'=>'sales_order_manufacturer_internal_status'),"CMO.manufacturer_internal_status=SOMIS.manufacturer_internal_status",array())
        ->joinLeft(array('SOS'=>'sales_order_status'),"CMO.customer_status=SOS.status",array())
        ->joinLeft(array('SOIS'=>'sales_order_internal_status'),"CMO.internal_status=SOIS.internal_status",array())
        ->joinLeft(array('CPEI' => 'eav_attribute_option_value'), "CMO.brand_id=CPEI.option_id", array())
        ->columns($columns)
        ->where('EO.cancel_status = 1')
        ->where('CSCO.wg_id NOT IN(?)',explode(',', Mage::getStoreConfig('ediryder/general/ship_carrier')))
        ;

    echo $select;
    die();
    $columns = array(
        'order_id',
        'mfr_id',
        'shipment_id',
        'ship_key_id',
        'part_number'=>new Zend_Db_Expr("REPLACE(GROUP_CONCAT(DISTINCT SFOIPR.part_number),';',',')"),
    );
    $replacement = $read->select()
        ->from(array('SFOIPR'=>'sales_flat_order_item_parts_replacement'),$columns)
        ->group('order_id')
        ->group('mfr_id')
        ->group('shipment_id')
        ->group('ship_key_id');
    
    $columns = array(
        'increment_id' => 'SFO.increment_id',
        'order_id' => 'SFO.entity_id',
        'shipment_number' => 'CMO.po_number',
        'brand_id' => 'SFOIA.brand_id',
        // 'part_number' => new Zend_Db_Expr("REPLACE(GROUP_CONCAT(DISTINCT SFOIA.part_number),';',',')"),
        'part_number' => new Zend_Db_Expr("IF(CMO.shipment_id=6,SFOIPR.part_number,REPLACE(GROUP_CONCAT(SFOIA.part_number),';',','))"),
        'created_at' => 'SFO.created_at',
    );
    $orderLevelPart = $read->select()
        ->from(array('SFO'=>'sales_flat_order'),array())
        ->join(array('SFOI'=>'sales_flat_order_item'),"SFO.entity_id=SFOI.order_id",array())
        ->join(array('SFOIA'=>'sales_flat_order_item_additional'),"SFOI.item_id=SFOIA.item_id",array())
        ->join(array('CMO' => 'ccc_manufacturer_order'),"SFOI.order_id=CMO.order_id AND SFOIA.mfg_id=CMO.mfr_id AND SFOIA.shipment_id=CMO.shipment_id AND SFOIA.ship_key_id=CMO.ship_key_id",array())
        ->joinLeft(array('SFOIPR' => new Zend_Db_Expr("({$replacement})")),"CMO.order_id=SFOIPR.order_id AND CMO.mfr_id=SFOIPR.mfr_id AND CMO.shipment_id=SFOIPR.shipment_id AND CMO.ship_key_id=SFOIPR.ship_key_id",array())
        ->columns($columns)
        ->where('SFOI.product_type = ?','simple')
        ->where('CMO.po_number IS NOT NULL')
        ->where('CMO.internal_status NOT IN (?)',array('complete','canceled'))
        ->where('SFOIA.brand_id NOT IN(?)',array(13847))
        ->group('SFO.entity_id')
        ->group('CMO.po_number');

    $isMissMatchPart = '(CASE 
            WHEN LOCATE(";",EOI.part_number) OR LOCATE("...",EOI.part_number) THEN LOCATE(REPLACE(EOI.part_number,"...",""),REPLACE(REPLACE(at_order.part_number,"*","'."'".'"),",",";"))
            WHEN LOCATE(",",EOI.part_number) THEN LOCATE(EOI.part_number,REPLACE(at_order.part_number,"*","'."'".'"))
            ELSE FIND_IN_SET(SUBSTRING_INDEX(EOI.part_number,"-Box",1),REPLACE(at_order.part_number,"*","'."'".'"))
        END)';
    $columns = array(
        'Order #' => 'EO.increment_id',
        'Shipment #' => 'EO.shipment_number',
        'Order Created Date' => 'at_order.created_at',
        'Shipment Created Date' => 'EO.created_at',
        'Brand' => 'CPEI.value',
        'order_part_number' => 'at_order.part_number',
        'ryder_part_number' => 'EOI.part_number',
        'MFR Status' => 'SOMS.label',
        'MFR Internal' => 'SOMIS.label',
        'Customer Status' => 'SOS.label',
        'Internal Status' => 'SOIS.label',
        'Is Cancel Send' => new Zend_Db_Expr("IF(EO.cancel_status > 2,'Yes','No')")
        // 'is_find_in_set' => new Zend_Db_Expr("FIND_IN_SET(EOI.part_number,at_order.part_number)")
    );
    $select = $read->select()
        ->from(array('EO'=>'edicarrier_order'),array())
        ->join(array('EOI'=>'edicarrier_order_item'),"EO.entity_id=EOI.parent_id",array())
        ->join(array('at_order' => new Zend_Db_Expr("({$orderLevelPart})")),"EO.order_id=at_order.order_id AND EO.shipment_number=at_order.shipment_number",array())
        ->join(array('CMO'=>'ccc_manufacturer_order'),"EO.order_id=CMO.order_id AND EO.shipment_number=CMO.po_number",array())
        ->joinLeft(array('SOMS'=>'sales_order_manufacturer_status'),"CMO.manufacturer_status=SOMS.manufacturer_status",array())
        ->joinLeft(array('SOMIS'=>'sales_order_manufacturer_internal_status'),"CMO.manufacturer_internal_status=SOMIS.manufacturer_internal_status",array())
        ->joinLeft(array('SOS'=>'sales_order_status'),"CMO.customer_status=SOS.status",array())
        ->joinLeft(array('SOIS'=>'sales_order_internal_status'),"CMO.internal_status=SOIS.internal_status",array())
        ->joinLeft(array('CPEI' => 'eav_attribute_option_value'), "at_order.brand_id=CPEI.option_id", array())
        ->columns($columns)
        ->where('EOI.part_number IS NOT NULL')
        ->where("{$isMissMatchPart} = 0");
    echo $select;
    die();
    $columns = array(
        'order_id',
        'mfr_id',
        'shipment_id',
        'ship_key_id',
        'part_number'=>new Zend_Db_Expr("REPLACE(GROUP_CONCAT(DISTINCT SFOIPR.part_number),',',';')"),
        'part_number_count' => new Zend_Db_Expr("(LENGTH(REPLACE(GROUP_CONCAT(SFOIPR.part_number),',',';')) - LENGTH(REPLACE(REPLACE(GROUP_CONCAT(SFOIPR.part_number),',',';'),';',''))) + 1"),
        'qty' => new Zend_Db_Expr("SUM(COALESCE(SFOIPR.qty,1))"),
    );
    $replacementCount = $read->select()
        ->from(array('SFOIPR'=>'sales_flat_order_item_parts_replacement'),$columns)
        ->group('order_id')
        ->group('mfr_id')
        ->group('shipment_id')
        ->group('ship_key_id');

    $ryderColumns = array(
        'increment_id' => 'EO.increment_id',
        'order_id' => 'EO.order_id',
        'shipment_number' => 'EO.shipment_number',
        'create_status' => 'EO.create_status',
        'update_status' => 'EO.update_status',
        'cancel_status' => 'EO.cancel_status',
        'ryder_part_number' => new Zend_Db_Expr("REPLACE(GROUP_CONCAT(DISTINCT EOI.part_number),',',';')"),
        'ryder_part_count' => new Zend_Db_Expr("(LENGTH(REPLACE(GROUP_CONCAT(EOI.part_number),',',';')) - LENGTH(REPLACE(REPLACE(GROUP_CONCAT(EOI.part_number),',',';'),';',''))) + 1"),
        'qty' => new Zend_Db_Expr("SUM(COALESCE(EOI.qty,0))"),
        'created_at',
    );
    $edicarrier = $read->select()
        ->from(array('EO' => 'edicarrier_order'),$ryderColumns)
        ->join(array('EOI' => 'edicarrier_order_item'),"EO.entity_id=EOI.parent_id",array())
        ->group('EO.order_id')
        ->group('EO.shipment_number');

    $columns = array(
        'order #' => 'CMO.increment_id',
        'Shipment #' => 'CMO.po_number',
        'Order Created Date' => 'SFO.created_at',
        // 'Shipment Created Date' => 'at_carrier.created_at',
        'internal_status' => 'CMO.internal_status',
        'MFR Status' => 'SOMS.label',
        'MFR Internal' => 'SOMS.label',
        'Customer Status' => 'SOS.label',
        'Internal Status' => 'SOIS.label',
        'is_replacement' => "IF(SFOIA.shipment_id = 6,'Yes','No')",
        'order_part_number' => new Zend_Db_Expr("IF(SFOIA.shipment_id = 6,at_replacement.part_number,REPLACE(GROUP_CONCAT(DISTINCT SFOIA.part_number),',',';'))"),
        'order_part_char_count' => new Zend_Db_Expr("CHAR_LENGTH(IF(SFOIA.shipment_id = 6,at_replacement.part_number,REPLACE(GROUP_CONCAT(DISTINCT SFOIA.part_number),',',';')))"),
        'ryder_part_number' => 'at_carrier.ryder_part_number',
        'ryder_part_char_count' => 'CHAR_LENGTH(at_carrier.ryder_part_number)',
    );
    $select = $read->select()
        ->from(array('SFO'=>'sales_flat_order'),array())
        ->join(array('SFOI'=>'sales_flat_order_item'),"SFO.entity_id=SFOI.order_id",array())
        ->joinLeft(array('CPF'=>'catalog_product_feed'),"SFOI.product_id = CPF.entity_id",array())
        ->join(array('SFOIA'=>'sales_flat_order_item_additional'),"SFOI.item_id = SFOIA.item_id",array())
        ->join(array('CMO' => 'ccc_manufacturer_order'),"SFOI.order_id=CMO.order_id AND SFOIA.mfg_id=CMO.mfr_id AND SFOIA.shipment_id=CMO.shipment_id AND SFOIA.ship_key_id=CMO.ship_key_id",array())
        ->join(array('at_carrier' => new Zend_Db_Expr("({$edicarrier})")),"CMO.order_id=at_carrier.order_id AND CMO.po_number=at_carrier.shipment_number",array())
        ->joinLeft(array('at_replacement' => new Zend_Db_Expr("({$replacementCount})")),"CMO.order_id=at_replacement.order_id AND CMO.mfr_id=at_replacement.mfr_id AND CMO.shipment_id=at_replacement.shipment_id AND CMO.ship_key_id=at_replacement.ship_key_id",array())
        ->joinLeft(array('SOMS'=>'sales_order_manufacturer_status'),"CMO.manufacturer_status=SOMS.manufacturer_status",array())
        ->joinLeft(array('SOMIS'=>'sales_order_manufacturer_internal_status'),"CMO.manufacturer_internal_status=SOMIS.manufacturer_internal_status",array())
        ->joinLeft(array('SOS'=>'sales_order_status'),"CMO.customer_status=SOS.status",array())
        ->joinLeft(array('SOIS'=>'sales_order_internal_status'),"CMO.internal_status=SOIS.internal_status",array())
        ->columns($columns)
        ->where('SFOI.product_type = ?','simple')
        ->where('CMO.po_number IS NOT NULL')
        ->where('CMO.internal_status NOT IN (?)',array('complete','canceled'))
        ->group('CMO.order_id')
        ->group('CMO.po_number')
        ->having('order_part_char_count != ryder_part_char_count')
        ;

    echo $select;

    die();

    // qty miss match
    $columns = array(
        'order_id',
        'mfr_id',
        'shipment_id',
        'ship_key_id',
        'part_number'=>new Zend_Db_Expr("REPLACE(GROUP_CONCAT(SFOIPR.part_number),',',';')"),
        'part_number_count' => new Zend_Db_Expr("(LENGTH(REPLACE(GROUP_CONCAT(SFOIPR.part_number),',',';')) - LENGTH(REPLACE(REPLACE(GROUP_CONCAT(SFOIPR.part_number),',',';'),';',''))) + 1"),
        'qty' => new Zend_Db_Expr("SUM(COALESCE(SFOIPR.qty,1))"),
    );
    $replacementCount = $read->select()
        ->from(array('SFOIPR'=>'sales_flat_order_item_parts_replacement'),$columns)
        ->group('order_id')
        ->group('mfr_id')
        ->group('shipment_id')
        ->group('ship_key_id');

    $ryderColumns = array(
        'increment_id' => 'EO.increment_id',
        'order_id' => 'EO.order_id',
        'shipment_number' => 'EO.shipment_number',
        'create_status' => 'EO.create_status',
        'update_status' => 'EO.update_status',
        'cancel_status' => 'EO.cancel_status',
        'ryder_part_number' => new Zend_Db_Expr("REPLACE(GROUP_CONCAT(EOI.part_number),',',';')"),
        'ryder_part_count' => new Zend_Db_Expr("(LENGTH(REPLACE(GROUP_CONCAT(EOI.part_number),',',';')) - LENGTH(REPLACE(REPLACE(GROUP_CONCAT(EOI.part_number),',',';'),';',''))) + 1"),
        'qty' => new Zend_Db_Expr("SUM(COALESCE(EOI.qty,0))"),
        'created_at',
    );
    $edicarrier = $read->select()
        ->from(array('EO' => 'edicarrier_order'),$ryderColumns)
        ->join(array('EOI' => 'edicarrier_order_item'),"EO.entity_id=EOI.parent_id",array())
        ->group('EO.order_id')
        ->group('EO.shipment_number');

    $columns = array(
        'order #' => 'CMO.increment_id',
        'Shipment #' => 'CMO.po_number',
        'Order Created Date' => 'SFO.created_at',
        'Shipment Created Date' => 'at_carrier.created_at',
        'MFR Status' => 'SOMS.label',
        'MFR Internal' => 'SOMS.label',
        'Customer Status' => 'SOS.label',
        'Internal Status' => 'SOIS.label',
        'is_replacement' => "IF(SFOIA.shipment_id = 6,'Yes','No')",
        'order_part_number' => new Zend_Db_Expr("IF(SFOIA.shipment_id = 6,at_replacement.part_number,REPLACE(GROUP_CONCAT(SFOIA.part_number),',',';'))"),
        'order_part_count' => new Zend_Db_Expr("IF(SFOIA.shipment_id = 6,at_replacement.part_number_count,(LENGTH(REPLACE(GROUP_CONCAT(SFOIA.part_number),',',';')) - LENGTH(REPLACE(REPLACE(GROUP_CONCAT(SFOIA.part_number),',',';'),';',''))) + 1)"),
        'order_qty' => new Zend_Db_Expr("IF(SFOIA.shipment_id = 6,at_replacement.part_number_count,SUM(IF(LOCATE(';',SFOIA.part_number),((LENGTH(SFOIA.part_number) - LENGTH(REPLACE(SFOIA.part_number,';',''))) + 1) * COALESCE(SFOI.qty_ordered,1),(COALESCE(SFOI.qty_ordered,1)*COALESCE(CPF.package_quantity,1)))))"),
        'ryder_part_number' => 'at_carrier.ryder_part_number',
        'ryder_part_count' => 'at_carrier.ryder_part_count',
        'ryder_qty' => 'at_carrier.qty',
        'is_create_request_send' => "IF(at_carrier.create_status >= 3,'Yes','No')",
        'is_update_request_send' => "IF(at_carrier.update_status >= 3,'Yes','No')",
        'is_cancel_request_send' => "IF(at_carrier.cancel_status >= 3,'Yes','No')",
    );
    $select = $read->select()
        ->from(array('SFO'=>'sales_flat_order'),array())
        ->join(array('SFOI'=>'sales_flat_order_item'),"SFO.entity_id=SFOI.order_id",array())
        ->joinLeft(array('CPF'=>'catalog_product_feed'),"SFOI.product_id = CPF.entity_id",array())
        ->join(array('SFOIA'=>'sales_flat_order_item_additional'),"SFOI.item_id = SFOIA.item_id",array())
        ->join(array('CMO' => 'ccc_manufacturer_order'),"SFOI.order_id=CMO.order_id AND SFOIA.mfg_id=CMO.mfr_id AND SFOIA.shipment_id=CMO.shipment_id AND SFOIA.ship_key_id=CMO.ship_key_id",array())
        ->join(array('at_carrier' => new Zend_Db_Expr("({$edicarrier})")),"CMO.order_id=at_carrier.order_id AND CMO.po_number=at_carrier.shipment_number",array())
        ->joinLeft(array('at_replacement' => new Zend_Db_Expr("({$replacementCount})")),"CMO.order_id=at_replacement.order_id AND CMO.mfr_id=at_replacement.mfr_id AND CMO.shipment_id=at_replacement.shipment_id AND CMO.ship_key_id=at_replacement.ship_key_id",array())
        ->joinLeft(array('SOMS'=>'sales_order_manufacturer_status'),"CMO.manufacturer_status=SOMS.manufacturer_status",array())
        ->joinLeft(array('SOMIS'=>'sales_order_manufacturer_internal_status'),"CMO.manufacturer_internal_status=SOMIS.manufacturer_internal_status",array())
        ->joinLeft(array('SOS'=>'sales_order_status'),"CMO.customer_status=SOS.status",array())
        ->joinLeft(array('SOIS'=>'sales_order_internal_status'),"CMO.internal_status=SOIS.internal_status",array())
        ->columns($columns)
        ->where('SFOI.product_type = ?','simple')
        ->where('CMO.po_number IS NOT NULL')
        ->where('CMO.internal_status NOT IN (?)',array('complete'))
        ->group('CMO.order_id')
        ->group('CMO.po_number')
        ->having('order_qty != ryder_qty')
        ;
    echo $select;
} catch (Exception $e) {
    echo $e->getMessage();
    die;
}
