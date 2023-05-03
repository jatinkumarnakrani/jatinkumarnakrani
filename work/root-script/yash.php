<?php
require_once "../app/Mage.php";
Mage::app();
error_reporting(E_ALL | E_STRICT);
ini_set('display_errors', 1);
ini_set('memory_limit', "2096M");
echo "<pre>";
$readConnection  = Mage::getSingleton('core/resource')->getConnection('core_read');
$writeConnection = Mage::getSingleton('core/resource')->getConnection('core_write');
$read            = $readConnection;

/*$createdAt = date('Y-m-d H:i:s',strtotime("2021-06-30 10:22:16"));
echo $createdAt;
echo "<br>";
echo Mage::getSingleton('core/date')->gmtdate('Y-m-d H:i:s');
echo "<br>";
$start_date = new DateTime(Mage::getSingleton('core/date')->gmtdate('Y-m-d H:i:s'));
$since_start = $start_date->diff(new DateTime($createdAt));
print_r($since_start);
exit;*/
// ------------------------------------------------------------------------------------------------
$shipmentNumber = "893332811";
$orderId        = "452858";

$model = Mage::getModel('Furnique_Edi_Model_Edicarrier_Ryder_Observer');
echo $model->processAdvanceShip();die;
// checkZenithOrderWithFile("%ZenithFreightShipmentStatus-2021-04-05-0800PM.xlsx%", "893347111", yes);
die;

// create204FileAndItemCheck($shipmentNumber, $orderId);
die;

function create204FileAndItemCheck($shipmentNumber, $orderId)
{
    /*Create 204 Request*/
    Mage::getModel('edi/edicarrier_ryder_order')->processCreateFileForSingleShipment($shipmentNumber, $orderId);
}

function checkZenithOrderWithFile($fileName = "%ZenithFreightShipmentStatus-2021-04-05-0800PM.xlsx%", $checkingShipmentNumber = "893332811,893332812", $isRecheck = false)
{
    $readConnection = Mage::getSingleton('core/resource')->getConnection('core_read');
    $model          = Mage::getModel('Ccc_Zenith_Model_Observer');

    if ($isRecheck) {
        $rowAffected = $readConnection->exec("DELETE FROM `ccc_zenith_feed_details` WHERE `ccc_zenith_feed_details`.`purchase_order` = '{$checkingShipmentNumber}';");
        echo $rowAffected . " Rows Affected";
        echo "<br>";
        $rowAffected = $readConnection->exec("DELETE FROM `ccc_zenith_feed_header` WHERE `ccc_zenith_feed_header`.`purchase_order` = '{$checkingShipmentNumber}';");
        echo $rowAffected . " Rows Affected";
        echo "<br>";

        $rowAffected = $readConnection->exec("UPDATE `edicarrier_order_item` SET `asn_date` = NULL,`asn_status` = '0' WHERE parent_id IN (SELECT entity_id FROM `edicarrier_order` WHERE `shipment_number` = '{$checkingShipmentNumber}')");
        echo $rowAffected . " Rows Affected";
        echo "<br>";
    }
    $records = $readConnection->fetchAll("SELECT *  FROM `ccc_zenith_feed_header` WHERE `shipment_number` IN ($checkingShipmentNumber)");
    if ($records) {
        echo "<br>";
        echo "----------------------------------Header Data-------------------------------";
        echo "<br>";
        print_r($records);
        echo "<hr>";

        echo "----------------------------------Item Data-------------------------------";
        $itemData = $readConnection->fetchAll("SELECT *  FROM `ccc_zenith_feed_details` WHERE `header_entity_id` IN (SELECT entity_id  FROM `ccc_zenith_feed_header` WHERE `shipment_number` IN ($checkingShipmentNumber))");
        echo "<br>";
        print_r($itemData);
        echo "<hr>";

        echo "----------------------------------EDI Item Data-------------------------------";
        $ediItemData = $readConnection->fetchAll("SELECT * FROM `edicarrier_order_item` WHERE parent_id IN (SELECT entity_id FROM `edicarrier_order` WHERE `shipment_number` IN ('{$checkingShipmentNumber}'))");
        echo "<br>";
        print_r($ediItemData);
        echo "<hr>";
        return;
    }

    /*var/export/zenith/download/20210407/ZenithFreightShipmentStatus-2021-04-05-0800PM.xlsx*/
    $updateQuery = "UPDATE `ccc_zenith_mail_attachment` SET `processed_date` = NULL WHERE `filename` LIKE '%ZenithFreightShipmentStatus-2021-04-05-0800PM.xlsx%';";

    $readConnection->exec($updateQuery);
    echo $model->updateZenithOrderStatus();

    $records = $readConnection->fetchAll("SELECT *  FROM `ccc_zenith_feed_header` WHERE `shipment_number` IN ($checkingShipmentNumber)");
    if ($records) {
        echo "<br>";
        echo "----------------------------------Header Data-------------------------------";
        echo "<br>";
        print_r($records);
        echo "<hr>";

        echo "----------------------------------Item Data-------------------------------";
        $itemData = $readConnection->fetchAll("SELECT *  FROM `ccc_zenith_feed_details` WHERE `header_entity_id` IN (SELECT entity_id  FROM `ccc_zenith_feed_header` WHERE `shipment_number` IN ($checkingShipmentNumber))");
        echo "<br>";
        print_r($itemData);
        echo "<hr>";

        echo "----------------------------------EDI Item Data-------------------------------";
        $ediItemData = $readConnection->fetchAll("SELECT * FROM `edicarrier_order_item` WHERE parent_id IN (SELECT entity_id FROM `edicarrier_order` WHERE `shipment_number` IN ('{$checkingShipmentNumber}'))");
        echo "<br>";
        print_r($ediItemData);
        echo "<hr>";
        return;
    }
}

//-----------------------------------------
//$partNumber = "G0521A-S";
$partNumber = substr($partNumber, 0, 21);
$productId  = "360692";

$partNumberattributeId = Mage::getSingleton('eav/config')
    ->getAttribute(Mage_Catalog_Model_Product::ENTITY, 'part_number')->getId();
$packageQty = $read->select()
    ->from(array('CPF' => 'catalog_product_feed'),
        ['package_qty' => new Zend_Db_Expr("IF(LOCATE(';', 'PART.value'),1,COALESCE(CPF.package_quantity,1))")])
    ->joinLeft(['PART' => 'catalog_product_entity_varchar'], "CPF.entity_id = PART.entity_id AND PART.attribute_id = {$partNumberattributeId} AND store_id = 0", [])
    ->where("CPF.entity_id = $productId");
// ->where("PART.value LIKE '{$partNumber}%'");
echo $read->fetchOne($packageQty);
echo "<hr>";
echo $packageQty;die;
