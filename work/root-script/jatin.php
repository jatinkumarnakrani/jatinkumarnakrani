<?php
require_once "../app/Mage.php";
Mage::app('default');
Mage::setIsDeveloperMode(true);
// Varien_Profiler::enable();
error_reporting(E_ALL ^ E_DEPRECATED);
ini_set('display_errors', 1);
//php ryder_api.php -action create -shipment_numbers '8514657-S2,8514657-S2'
// php ryder.php -action updateEdicarrierItemQty
echo '<pre>';
$read = Mage::getSingleton('core/resource')->getConnection('core_read');
try {
    $localfolderpath = Mage::getModel('edi/edi')->getLocalDownloadFolderPath();
    $fileName = '856-896566511-1.xml';
    $filepath = $localfolderpath.$fileName;
    $xmlFileData = @simplexml_load_file($filepath);
    echo $xmlFileData->getName();
    echo "<br>";
    print_r($xmlFileData);die();
    $code = 'last_proccesed_ashley_edi_856_file_id';
    $variable = Mage::getModel('core/variable')->loadByCode($code);
    if (!$variable->getId()) {
        $variable->setPlainValue(0)
            ->setHtmlValue(0)
            ->setCode($code)
            ->setName($code)
            ->save();
        $variable = Mage::getModel('core/variable')->loadByCode($code);
    }
    $limit = 100;
    $write = Mage::getSingleton('core/resource')->getConnection('core_write');
    $readLogAction = Mage::getModel('edi/read_log')->getCollection();
    $readLogAction->getSelect()
        ->where('main_table.file_type = ?',856)
        ->where('main_table.id > ?',$variable->getPlainValue())
        ->order('main_table.id ASC')
        ->limit($limit);

    $lasstProcessId = null;
    foreach ($readLogAction as $processRow) {
        try {
            $filePath = Mage::getBaseDir() . $processRow->getShiftedTo() . $processRow->getFileName();
            if (!file_exists($filePath) || is_dir($filePath)) {
                throw new Exception("File not exist");
            }
            $xmlFile         = file_get_contents($filePath);
            $xmlStringData   = simplexml_load_string($xmlFile);
            $jsonEncodedData = json_encode($xmlStringData);
            $fileContent     = json_decode($jsonEncodedData, true);
            if ($fileContent) {
                $process = Mage::getModel('edi/process_shipping');
                $process->setFileContent($fileContent);
                $plannedDeliveryDate = $process->getPlannedDeliveryDate();
                if ($plannedDeliveryDate) {
                    $processRow->setPlannedDeliveryDate($plannedDeliveryDate)
                        ->save();
                    $read->update('edi_order',array('planned_delivery_date'=> $plannedDeliveryDate),"shipment_number = {$processRow->getAshleyPoNumber()}");
                    Mage::log($processRow,null,'jatin.log');
                }
            }
        } catch (Exception $e) {
            Mage::log(array(
                'entity_id' => $processRow->getId(),
                'file_name' => $processRow->getFileName(),
                'message' => $e->getMessage(), 
            ),null,'process856Files.log');
        }
        $variable->setPlainValue($processRow->getId())
            ->setHtmlValue($processRow->getId());
    }
    $variable->save();
    die();
    $columns = array(
        'Order  #' => 'EO.increment_id',
        'Shipment #' => 'EO.shipment_number',
        'part_number' => 'EOI.part_number',
        'shipment_asn_status' => new Zend_Db_Expr("CASE
                WHEN EO.asn_status = 1 THEN 'Pending'
                WHEN EO.asn_status = 2 THEN 'File Processing' 
                WHEN EO.asn_status = 3 THEN 'File Generated' 
                WHEN EO.asn_status = 4 THEN 'File Uploaded' 
                WHEN EO.asn_status = 5 THEN 'File Holded' 
                WHEN EO.asn_status = 6 THEN 'File Failed' 
            END"),
        'item_asn_status' => 'EOI.asn_status',
        'ASN_date' => 'EOI.asn_date',
        'processed_date_856' => 'ERL.processed_date',
        'shipment_created_date' => 'EO.created_at',
    );
    $select = $read->select()
        ->from(array('ERL'=>'edi_read_log'),array())
        ->join(array('EO'=>'edicarrier_order'),"ERL.order_id=EO.order_id AND ERL.ashley_po_number=EO.shipment_number",array())
        ->join(array('EOI'=>'edicarrier_order_item'),"EO.entity_id=EOI.parent_id",array())
        ->columns($columns)
        ->where('ERL.file_type = ?',856)
        ->where('ERL.processed_date >= ?','2021-08-10 13:28:18');
    echo $select;die();
    /*Find lettest shipment planned develiry date from email read table*/
    $emailShipmentSelect = $read->select()
        ->from(array('ESED'=>'edicarrier_shipment_email_delivery'),array('MAX(entity_id)'))
        ->group('ESED.shipment_number');
    $emailShipmentSelect = $read->select()
        ->from(array('ESED1'=>'edicarrier_shipment_email_delivery'))
        ->where("ESED1.entity_id IN({$emailShipmentSelect})");

    $columns = array(
        'Order #' => 'EO.increment_id',
        'Shipment #' => 'EO.shipment_number',
        'planned_delivery_date' => 'at_email.planned_delivery_date',
        'email_received_date' => 'at_email.email_received_date',
    );
    $select = $read->select()
        ->from(array('EO'=>'edicarrier_order'),array())
        ->join(array('EOI'=>'edicarrier_order_item'),"EO.entity_id=EOI.parent_id",array())
        ->join(array('EDF'=>'edicarrier_downloaded_files'),"EO.shipment_number=EDF.shipment_number AND EDF.action_type='AS'",array())
        ->join(array('at_email'=>$emailShipmentSelect),"EO.shipment_number=at_email.shipment_number",array())
        ->columns($columns);
    // $model = Mage::getModel('edi/edicarrier_shipment_email_delivery');
    // print_r($model->getCollection()->getMainTable());
    // die();
    echo $select;die();
    echo strtotime('2021-08-10 00:00:01');die();
    echo Mage::getModel('core/date')->date();die;
    Mage::getModel('edi/edicarrier_observer')->readAshleyEmail();die();
    echo $read->select()
        ->from(array('CSFD'=>'ccc_styline_feed_data'),array())
        ->join(array('EO'=>'edicarrier_order'),"CSFD.shipment_number=EO.shipment_number",array('shipment_number'))
        ->join(array('EOI'=>'edicarrier_order_item'),"EO.entity_id=EOI.parent_id")
        ->where('asn_status = 0');
        // ->from(array('SELECT * FROM `edicarrier_order_item` WHERE asn_status = 0 AND asn_date < NOW()'));
    die();
    $io = new Varien_Io_File();
    $io->open(array('path' => $shippingFilesDir));
    $files = $io->ls();
    usort($files, function ($a, $b){
        $datetime1 = strtotime($a['mod_date']);
        $datetime2 = strtotime($b['mod_date']);
        return $datetime1 - $datetime2;
    });
    krsort($files);
    print_r($files);
    // foreach ($io->ls() as $fileDetails) {
    //     $fileDetails = new Varien_Object($fileDetails);
    //     echo $shippingFilesDir.$fileDetails->getText();
    //     echo '<br>';
    // }
    die();
    $sql = "UPDATE `edicarrier_order_item` AS `EOI` INNER JOIN `edicarrier_order` AS `EO` ON EOI.parent_id=EO.entity_id SET EO.`asn_status` = 2, EOI.`asn_date` = '2021-07-22', EOI.`processed_with` = 'zenith' WHERE EOI.asn_status = 0 AND EO.shipment_number = '891224011'";
    echo $sql;
    die();
    $columns = array(
        'item_id' => 'EOI.item_id',
        'processed_with' => new Zend_Db_Expr("CASE
                WHEN CZFH.shipment_number IS NOT NULL THEN 'zenith'
                WHEN CSFD.shipment_number IS NOT NULL THEN 'styline' 
            END"),
    );

    echo $read->select()
        ->from(array('EO'=>'edicarrier_order'),array())
        ->join(array('EOI' => 'edicarrier_order_item'),"EO.entity_id=EOI.parent_id",array())
        ->joinLeft(array('CZFH' => 'ccc_zenith_feed_header'),'EO.shipment_number=CZFH.shipment_number',array())
        ->joinLeft(array('CSFD' => 'ccc_styline_feed_data'),'EO.shipment_number=CSFD.shipment_number',array())
        ->columns($columns)
        ->where('EOI.asn_date IS NOT NULL')
        ->where('EOI.processed_with IS NULL')
        ->where('EOI.asn_status = ?',0);

    die();
    $columns = array(
        'Order #' => new Zend_Db_Expr('EO.increment_id'),
        'Shipment #' => new Zend_Db_Expr('EO.shipment_number'),
        'Order Created At' => new Zend_Db_Expr('SFO.created_at'),
        'Shipment Created At' => new Zend_Db_Expr('EO.created_at'),
        'MFR' => new Zend_Db_Expr('CM.mfg'),
        'Ship With' => new Zend_Db_Expr('CSCO.freight'),
        'Ship To' => new Zend_Db_Expr("CONCAT('RLM #',CSC.numeric_code,' (',CSC.site_code,') ',CSC.short_name)"),
        'Items' => new Zend_Db_Expr('GROUP_CONCAT(EOI.part_number)'),
        'ASN sent?' => new Zend_Db_Expr('IF(EO.asn_status > 1,"Yes","No")'),
        'ASN Date' => new Zend_Db_Expr('EOI.asn_date'),
        'Manifest #' => new Zend_Db_Expr('EAL.manifest_number'),
        'ASN sent date' => new Zend_Db_Expr('EDF.created_at'),
    );
    $select = $read->select()
        ->from(array('EO'=>'edicarrier_order'),array())
        ->join(array('EOI'=>'edicarrier_order_item'),"EO.entity_id=EOI.parent_id",array())
        ->join(array('EOAM'=>'edicarrier_order_asn_menifest'),"EO.entity_id=EOAM.parent_id",array())
        ->join(array('SFO'=>'sales_flat_order'),"EO.order_id=SFO.entity_id",array())
        ->join(array('CM'=>'ccc_manufacturer'),"EO.mfr_id=CM.entity_id",array())
        ->join(array('EAL'=>'edicarrier_action_log'),"EO.order_id=EAL.order_id AND EO.shipment_number=EAL.shipment_number",array())
        ->join(array('EDF'=>'edicarrier_downloaded_files'),"EAL.file_id=EDF.id",array())
        ->join(array('CSC'=>'ccc_ship_carrier'),"EAL.carrier_id=CSC.id",array())
        ->join(array('CMO'=>'ccc_manufacturer_order'),"EO.order_id=CMO.order_id AND EO.shipment_number=CMO.po_number",array())
        ->join(array('CSCO'=>'ccc_ship_carrier_order'),"CMO.order_id=CSCO.order_entity_id AND CMO.mfr_id=CSCO.mfr_id AND CMO.shipment_id=CSCO.shipment_id AND CMO.ship_key_id=CSCO.ship_key_id",array())
        ->columns($columns)
        ->where('EDF.action_type = ?','AS')
        ->group('EOI.asn_date');
    echo $select;
    die();
    $columns = array(
        'entity_id' => 'EO.entity_id',
        'order_id' => 'EO.order_id',
        'shipment_number' => 'EO.shipment_number',
        'part_number' => 'SFOIPT.part_number',
        'delivery_date' => 'SFOIPT.delivery_date',
    );
    $statusSelect = $read->select()
        ->from(['CFS' => 'ccc_fedex_statuses'], ['CFS.status_code'])
        ->where('CFS.is_exception = ?', 0);
    echo $read->select()
        ->from(array('SFOIPT'=>'sales_flat_order_item_parts_tracking'),array())
        ->join(array('CMO'=>'ccc_manufacturer_order'),"SFOIPT.order_id=CMO.order_id AND SFOIPT.mfr_id=CMO.mfr_id AND SFOIPT.shipment_id=CMO.shipment_id AND SFOIPT.ship_key_id=CMO.ship_key_id",array())
        ->join(array('EO'=>'edicarrier_order'),"CMO.order_id=EO.order_id AND CMO.po_number=EO.shipment_number",array())
        ->columns($columns)
        ->where('SFOIPT.tracking_number = ?',281442791114)
        ->where("SFOIPT.status_code IN({$statusSelect})");
    // Mage::dispatchEvent('after_manifestnumber_set_update_asnflag',array('type'=>Furnique_Edi_Model_Edicarrier_Observer::ASN_SHIP_TYPE_STYLINE, 'shipment_number' => '891093211','scheduled_for_delivery' => '2021-07-17'));
    // Mage::getModel('zenith/observer')->insertStylineData();
    die();
    // Mage::dispatchEvent('after_manifestnumber_set_update_asnflag',array('type'=>Furnique_Edi_Model_Edicarrier_Observer::ASN_SHIP_TYPE_ZENITH, 'pro_numbers'=>array('2879136')));die();
    /*echo Mage::getBaseUrl().'skin/frontend/base/default/images/logo.jpg';die();
    $select = $read->select()
        ->from(array('EDF'=>'edicarrier_downloaded_files'),array('MAX(id)'))
        ->where('EDF.shipment_number IN(?)',array(892547611,893794611,893895711,893911311,893960411,893985211,893985311,893986811,893988711,893989011,893989111,893989112,893989311,893989511,893990411,893990511,893990611,893990811,893991011,893991211,893991711,893992411,893992811,893992911,893993511,893994011,893994411,893994811,893995011,893995111,893995811,893995911,894005611,894007211,894034911,894037911,894039811,894040311,894041511,894042011,894044811,894045211))
        ->group('EDF.shipment_number');
    echo $read->select()
        ->from(array('EO'=>'edicarrier_downloaded_files'))
        ->join(array('EOL'=>'edicarrier_action_log'),"EO.id=EOL.file_id",array())
        ->where("EO.id IN({$select})");
    die();
    echo Mage::getModel('core/date')->date('Ymd',strtotime('2021-07-05 00:00:00'));die();
    echo (Mage::getModel('edi/edicarrier_ryder_order')->getPendingShipments());die();
    $mpnAttribute = Mage::getSingleton('eav/config')->getCollectionAttribute('catalog_product', 'mpn');
    $upcAttribute = Mage::getSingleton('eav/config')->getCollectionAttribute('catalog_product', 'upc');
    $select = $read->select()
        ->from(array('CPE'=>'catalog_product_entity'))
        ->join(array('at_upc'=>$upcAttribute->getBackendTable()),"at_upc.entity_id = CPE.entity_id AND at_upc.attribute_id={$upcAttribute->getId()}",array('value'))
        ->join(array('at_mpn'=>$mpnAttribute->getBackendTable()),"at_mpn.entity_id = CPE.entity_id AND at_mpn.attribute_id={$mpnAttribute->getId()}")
        ->where("at_mpn.value LIKE '%U3118C - SUBARU COFFEE - RS W/ DDT%'");
    echo $select;
    die();
    $_item = Mage::getModel('edi/edicarrier_order_item')->load(168994);
    print_r($_item);
    echo Mage::getModel('core/date')->date('Y-m-d H:i:s');die();*/
    print_r(Mage::getModel('edi/edicarrier_ryder_order')->getAckOrders());die;
    Mage::getModel('zenith/observer')->insertZenithSheet();die;
    Mage::getModel('edi/edicarrier_observer')->afterMenifestNumberSet(new Varien_Object(array('type'=>'zenith','pro_numbers' => array('4877621'))));die;
    $sql = array(1,12,123,456,498,5646,324165,54,65,4564,654);
    foreach (array_chunk($sql, 2) as $key => $value) {
        echo $key;
        echo'<br>';
        print_r($value);
        echo'<br>';
    }
    die;
    $configration = Mage::getStoreConfig('ediryder/edi_actions/asn_configuration');
    print_r(unserialize($configration));
    die;
    $shipmentNumbers = array('885700911','887727611','873334581','885752881','888691811','889848811','889849211','889850211','889850811','889851211','889852111','890226611','890964911','892057711');
    $select = $read->select()
        ->from(array('EDF'=>'edicarrier_downloaded_files'),array('*'))
        ->where('EDF.status = 6')
        ->where('EDF.shipment_number IN(?)',$shipmentNumbers);
    echo $select;
    die;
    $columns = array(
        'order #' => 'EO.increment_id',
        'Shipment #' => 'EO.shipment_number',
        'MFR Status' => 'SOMS.label',
        'MFR Internal' => 'SOMIS.label',
        'Customer Status' => 'SOS.label',
        'Internal Status' => 'SOIS.label',
        'create_status' => 'EO.create_status',
        'update_status' => 'EO.update_status',
        'cancel_status' => 'EO.cancel_status',
        'file_name' => 'EDF.file_name',
        'Shipment Created Date' => 'EO.created_at',
        'Order Created Date' => 'SFO.created_at',
    );
    echo $read->select()
        ->from(array('EO'=>'edicarrier_order'),array())
        ->join(array('SFO'=>'sales_flat_order'),"EO.order_id=SFO.entity_id",array())
        ->join(array('EDF'=>'edicarrier_downloaded_files'),"EO.order_id=EDF.order_id AND EO.shipment_number=EDF.shipment_number",array())
        ->join(array('CMO'=>'ccc_manufacturer_order'),"EO.order_id=CMO.order_id AND EO.shipment_number=CMO.po_number",array())
        ->joinLeft(array('SOMS'=>'sales_order_manufacturer_status'),"CMO.manufacturer_status=SOMS.manufacturer_status",array())
        ->joinLeft(array('SOMIS'=>'sales_order_manufacturer_internal_status'),"CMO.manufacturer_internal_status=SOMIS.manufacturer_internal_status",array())
        ->joinLeft(array('SOS'=>'sales_order_status'),"CMO.customer_status=SOS.status",array())
        ->joinLeft(array('SOIS'=>'sales_order_internal_status'),"CMO.internal_status=SOIS.internal_status",array())
        ->columns($columns)
        ->where('EO.shipment_number IN(?)',array(892547611,893794611,893895711,893911311,893960411,893985211,893985311,893986811,893988711,893989011,893989111,893989112,893989311,893989511,893990411,893990511,893990611,893990811,893991011,893991211,893991711,893992411,893992811,893992911,893993511,893994011,893994411,893994811,893995011,893995111,893995811,893995911,894005611,894007211,894034911,894037911,894039811,894040311,894041511,894042011,894044811,894045211));
    die;
    $readLimit = Mage::getStoreConfig('ediryder/edi_actions/asn_configuration');
    echo $readLimit;
    echo '<br>';
    print_r(unserialize($readLimit));
    die();
    $t = microtime(true);
    $micro = sprintf("%06d",($t - floor($t)) * 1000000);
    echo $micro;die();
    $d = new DateTime( date('Y-m-d H:i:s.'.$micro, $t) );

    print $d->format("Y-m-d H:i:s.u"); // note at point on "u"
    die();
    echo microtime(true);die();
    echo date('Y-m-d_H:i:s u');die();
    // Mage::getModel('edi/edicarrier_order')->doAdvanceShipStatus(431810,Furnique_Edi_Model_Edicarrier_Order::AS_STATUS_FILEG,890068711);
    // die();
    Mage::dispatchEvent('after_manifestnumber_set_update_asnflag',array('type'=>Furnique_Edi_Model_Edicarrier_Observer::ASN_SHIP_TYPE_EXPRESS_FEDEX, 'tracking_numbers'=>array('ydga65324bbdv')));
    die();
    print_r(Mage::getModel('edi/edicarrier_ryder_observer')->processAdvanceShip());die();
    echo date("dmy", strtotime('2021-03-15'));
    $select = $read->select()
        ->from(array('CMO'=>'ccc_manufacturer_order'),array())
        ->join(array('CSCO'=>'ccc_ship_carrier_order'),"CMO.order_id=CSCO.order_entity_id AND CMO.mfr_id=CSCO.mfr_id AND CMO.shipment_id=CSCO.shipment_id AND CMO.ship_key_id=CSCO.ship_key_id",array())
        ->join(array('CSC'=>'ccc_ship_carrier'),"CSCO.wg_id=CSC.id",array('site_code'))
        ->where('CMO.order_id = ?',430941)
        ->where('CMO.po_number = ?',889909311)
        ->limit(1);
    $siteCode = $read->fetchOne($select);
    die();
    $sitecodes = array('73' => '003','20' => '007','38' => '008','164' => '010','98' => '030','131' => 'ABQ','166' => 'AMA','135' => 'ABY','107' => 'ATL','150' => 'AUT','167' => 'AVL','119' => 'BAL','133' => 'BFA','126' => 'BLS','118' => 'BOS','110' => 'BTN','128' => 'CAR','162' => 'CAS','108' => 'CDR','109' => 'CHI','134' => 'CLD','138' => 'CNN','147' => 'CSN','168' => 'CTN','151' => 'DAL','120' => 'DET','97' =>  'DVR','152' => 'ELP','99' =>  'FTL','129' => 'GBO','121' => 'GRP','144' => 'HBG','92' =>  'HOM','169' => 'HUN','170' => 'IPL','125' => 'JKO','124' => 'KAN','33' =>  'KXL','93' =>  'LBK','116' => 'LFT','141' => 'LGR','106' => 'LKL','96' =>  'LOS','114' => 'LSV','93' =>  'LTR','113' => 'LXN','155' => 'MID','122' => 'MIN','127' => 'MLZ','70' =>  'MOB','148' => 'MPH','130' => 'NEJ','132' => 'NLV','115' => 'NRL','85' =>  'NVD','140' => 'OKB','146' => 'OLY','163' => 'OMH','143' => 'PBH','137' => 'PGO','94' =>  'PHX','98' =>  'PNC','142' => 'POR','158' => 'RCH','26' =>  'RCM','160' => 'SEA','95' =>  'SFR','157' => 'SLC','56' =>  'SPF','123' => 'STL','117' => 'SVE','136' => 'SYN','149' => 'TEN','156' => 'TLR','139' => 'TSA','159' => 'VAB','112' => 'WIC','161' => 'WVR');
    foreach ($sitecodes as $numericCode => $siteCode) {
        $queries[] = "UPDATE `ccc_ship_carrier` SET site_code ='{$siteCode}' WHERE numeric_code = {$numericCode}";
    }
    $query = implode(';', $queries);
    echo $query;
    die();
    // $select =  $read->select()
    //     ->from(array('MPI' => 'mfr_part_items'),array('part_number' => new Zend_Db_Expr("TRIM(MPI.part_number)"),'weight','part_name'))
    //     ->where('part_number IN (?)',array('253136-2315HB','253136-2315FB','253135-2315RS'))
    //     ->where('brand_id = ?',13796);
    // echo $select;
    // die;
    $columns = array(
        'id',
        'numeric_code',
        'name' => new Zend_Db_Expr("TRIM(REPLACE(IF(CSC.short_name IS NOT NULL,CSC.short_name,CSC.name),'Payless/',''))"),
        'address',
        'city',
        'state',
        'zipcode',
        'phone'
    );
    $select = $read->select()
        ->from(array('CSC' => 'ccc_ship_carrier'),$columns)
        ->where("CSC.short_name LIKE '%RLM%'")
        ->orWhere("CSC.name LIKE '%RLM%'");
    echo $select;
    die();
    Mage::getModel('edi/edicarrier_ryder_order')->updateParentShipmentBasedOnReplacement(new Varien_Object());
    die();
    $brand = Mage::getSingleton('eav/config')->getCollectionAttribute('catalog_product', 'brand');
    echo $read->select()
        ->from(array('SFOIPT'=>'sales_flat_order_item_parts_tracking'))
        ->join(array('at_brand' => $brand->getBackendTable()), "at_brand.entity_id = SFOIPT.product_id AND at_brand.attribute_id={$brand->getId()}", array('value'))
        ->where('at_brand.value NOT IN(?)',array(13863));
    die;
    Mage::dispatchEvent('vendor_inventory_report_noshipdate',array());
    die();
    $block = Mage::app()->getLayout()->createBlock('mfrportal/adminhtml_dashboard_inventoryReport');
    $block->prepareBrandWiseTotalCount();
    die();
    echo $readLimit = Mage::getStoreConfig('catalog/instock_dates/inventory_cron_read_limit', Mage::app()->getStore());die();
    Mage::getModel('vendorfeed/inventory_report')->setBrandId(13796)->prepareBrandWiseSystemPartArray()->insertPreparedReportData();die();
    $columns = array(
        'product_id' => 'CPE.entity_id',
        'part_number' => new Zend_Db_Expr("IF(CPE.type_id='bundle',at_bundle.part_number,LOWER(TRIM(REPLACE(IF(at_partNumber.value IS NULL OR at_partNumber.value = '',at_mpn.value,at_partNumber.value),',',';'))))"),
        'product_type' => 'CPE.type_id',
    );
    $partNumber = Mage::getSingleton('eav/config')->getCollectionAttribute('catalog_product', 'part_number');
    $mpn = Mage::getSingleton('eav/config')->getCollectionAttribute('catalog_product', 'mpn');
    $brand = Mage::getSingleton('eav/config')->getCollectionAttribute('catalog_product', 'brand');

    $bundleProductSelect = $read->select()
        ->from(array('CPBS'=>'catalog_product_bundle_selection'),array('product_id'=>'CPBS.parent_product_id','part_number' => new Zend_Db_Expr("LOWER(REPLACE(GROUP_CONCAT(TRIM(IF(at_partNumber.value IS NULL OR at_partNumber.value = '',at_mpn.value,at_partNumber.value))),',',';'))")))
        ->joinLeft(array('at_mpn' => $mpn->getBackendTable()), "at_mpn.entity_id = CPBS.product_id AND at_mpn.attribute_id={$mpn->getId()}", array())
        ->joinLeft(array('at_partNumber' => $partNumber->getBackendTable()), "at_partNumber.entity_id = CPBS.product_id AND at_partNumber.attribute_id={$partNumber->getId()}", array())
        ->group('CPBS.parent_product_id');
    
    $productSelect = $read->select()
        ->from(array('CPE'=>'catalog_product_entity'),array())
        ->join(array('at_brand' => $brand->getBackendTable()), "at_brand.entity_id = CPE.entity_id AND at_brand.attribute_id={$brand->getId()}", array())
        ->joinLeft(array('at_mpn' => $mpn->getBackendTable()), "at_mpn.entity_id = CPE.entity_id AND at_mpn.attribute_id={$mpn->getId()}", array())
        ->joinLeft(array('at_partNumber' => $partNumber->getBackendTable()), "at_partNumber.entity_id = CPE.entity_id AND at_partNumber.attribute_id={$partNumber->getId()}", array())
        ->joinLeft(array('at_bundle'=>new Zend_Db_Expr("({$bundleProductSelect})")),"CPE.entity_id=at_bundle.product_id",array())
        ->columns($columns)
        ->where('at_brand.value = ?',13801);
    $products = $read->fetchAll($productSelect);

    $allParts = array();
    $allParts[] = array('part_number'=>'part_number','type'=>'type','product_id'=>'product_id');
    foreach ($products as $product) {
        $item = array();
        $product = new Varien_Object($product);
        foreach (explode(';', $product->getPartNumber()) as $part) {
            $item['part_number'] = $part;
            $item['type'] = $product->getProductType();
            $item['product_id'] = $product->getProductId();
            $allParts[] = $item;
        }
    }
    $csv = new Varien_File_Csv();
    $csv->saveData('brand_wise_part.csv', $allParts);
    echo ('brand_wise_part.csv file generated in root-script folder');
    die();
    $model = Mage::getModel('vendorfeed/inventory_report');
    print_r($model->getCollection()->getData());
    die();
    $mailMappingModel = Mage::getModel('vendorfeed/inventory_mail')->getCollection()->getMainTable();
    echo $mailMappingModel;die();
    echo (Mage::getModel('newsletter/subscriber')->getCollection()->getMainTable());
    die();
    $shipCarrierWgId = Mage::getModel('shipcarrier/order')->getResource()->getShipmentWhiteGloveId(381695,143,1,1);
    // print_r(get_class_methods(Mage::getModel('shipcarrier/order')->getResource()));
    var_dump($shipCarrierWgId);
    die();

    // $itemIds = array(76981,77823,77981,79012,79104,79270,80888,81187,81714,81755,81788,81916,81992,82062,82075,82079,82085,82184,82523,82817,82819,82883,82936,82942,83137,83187,83244,83275,83327,83328,83336,83338,83382,83388,83389,83391,83406,83407,83408,83413,83418,83419,83428,83449,83450,83470,83495,83540,83542,83548,83561,83573,83575,83592,83593,83613,83617,83638,83656,83691,83700,83701,83709,83717,83725,83726,83729,83730,83731,83736,83737,83741,83742,83745,83747,83766,83789,83790,83799,83837,83841,83859,83860,83861,83875,83879,83880,83881,83882,83902,83903,83925,83941,83942,83943,83944,83945,83946,83953,83959,83964,83968,83969,83980,83981,83995,83999,84003,84010,84011,84017,84018,84019,84034,84036,84037,84051,84058,84060,84061,84063,84064,84065,84066,84067,84068,84069,84070,84071,84072,84073,84074,84075,84076,84077,84078,84079,84080,84081,84082,84083,84084,84085,84086,84087,84088,84089,84090,84091,84092,84093,84094,84095,84096,84097,84098,84099,84100,84101,84102,84103,84104,84105,84106);
    // $itemIds = array(6974,73343,73986,74289,74310,74311,74335,74387,74500,74501,74508,74509,74522,74523,74524,74525,74526,74527,74528,74529,74530,74531);
    // $read->update('edicarrier_order_item',array('part_number' => 'REPLACE(part_number,"*","'."'".'")'),$read->quoteInto('item_id IN(?)',$itemIds));
    // die(111);
    $read->update('edicarrier_order_item',array('part_number' => 'REPLACE(part_number,"*","'."'".'")'),$read->quoteInto('item_id IN(?)',$itemIds));
    $columns = array(
        'Order #' => 'SFO.increment_id',
    );
    $select = $read->select()
        ->from(array('SFO'=>'sales_flat_order'),array())
        ->join(array('SFOI'=>'sales_flat_order_item'),"SFO.entity_id=SFOI.order_id",array())
        ->columns($columns)
        ->where('SFOI.sku IN(?)',array('pbM80X52-D1','pbM80X42-D1','pbM80X52-C3','pbM80X42-C3','pbM80X52-C2','pbM80X52-C1','pbM80X42-C2','pbM80X42-C1','pbM80X62','pbM80X52','pbM80X42'));
    echo $select;
    die();
    $product = Mage::getModel('catalog/product')->getCollection()
        ->addAttributeToSelect('upc')
        ->addAttributeToFilter('sku',array('in'=>array('pbM80X52-D1','pbM80X42-D1','pbM80X52-C3','pbM80X42-C3','pbM80X52-C2','pbM80X52-C1','pbM80X42-C2','pbM80X42-C1','pbM80X62','pbM80X52','pbM80X42')));
    echo ($product->getSelect());
    die();
    $fedex = Mage::getModel('fedex/fedex');
    print_r($fedex);
    die();
    print_r(Mage::getModel('admin/roles')->getResourcesTree());die();
    var_dump(Mage::getSingleton('admin/session')->isAllowed('admin/system/config/points'));die();
    $collection = Mage::getModel("admin/roles")->getCollection();
    $collection->getSelect()
        ->where('main_table.role_id NOT LKE "%Administrator%"')
        ->where('main_table.role_name NOT LIKE "%Cybercom%"');
    echo $collection->getSelect();die();
    $collection = Mage::helper('ordereditor')->getViewAsUsers(86);
    echo $collection->getSelect();die();
    $collection =  Mage::getModel("admin/roles")->getCollection();
    echo $collection->getSelect();die();
    echo (Mage::getBaseDir().'/var/export/custom/');die();
    $file = Mage::getModel('edi/edicarrier_downloaded_files')->load(25102);
    print_r($file->getPreviousFileContent());
    die();
    $file = Mage::getModel('edi/edicarrier_type_edi_processFile');
    $shipment = new Varien_Object();
    $shipment->setShipmentNumber('883158511');
    $file->setShipment($shipment);
    print_r($file->generateFile());
    die();
    echo Mage::getModel('edi/edicarrier_ryder_order')->getPendingOrderIds();
    die();
    $limit =  (int) Mage::getStoreConfig('ediryder/general/pending_order_changes_process_limit');
    $items = Mage::getModel('edi/edicarrier_order_item')->getCollection();
    $items->getSelect()
        ->where('main_table.qty > ?',1)
        ->limit($limit);

    $itemIds  = array();
    foreach ($items as $item) {
        $itemData = $item->getData();
        unset($itemData['item_id']);
        $itemData['qty'] = 1;
        for ($i=1; $i < $item->getQty(); $i++) {
            $read->insert('edicarrier_order_item',$itemData);
        }
        $itemIds[] = $item->getId();
        $item->setId($item->getId())
            ->setQty(1)
            ->save();
    }
    die();
    $select = $read->select()
        ->from(array('EAL'=>'edicarrier_action_log'),array('file_count' => 'COUNT(EAL.file_id)'))
        ->join(array('EDF'=>'edicarrier_downloaded_files'),"EAL.file_id=EDF.id")
        ->where('EDF.file_type = ?',204)
        ->group('EDF.id')
        ->having('file_count > 1')
        ;
    echo $select;
    die();
    $columns= array(
        '# Order' => 'EO.increment_id',
        '# Shipment' => 'EO.shipment_number',
        'Delivery Group' => 'EO.delivery_group_number',
        'parent_shipment_number' => 'EO1.shipment_number',
        'Parent Delivery Group' => 'EO1.delivery_group_number',
    );
    $select = $read->select()
        ->from(array('EO'=>'edicarrier_order'),$columns)
        ->join(array('CMO'=>'ccc_manufacturer_order'),"EO.order_id=CMO.order_id AND EO.shipment_number=CMO.po_number",array())
        ->join(array('EO1'=>'edicarrier_order'),"CMO.order_id=EO1.order_id AND CMO.reference_po_number=EO1.shipment_number",array())
        ->where('CMO.shipment_id = 6')
        ->where('EO.cancel_status = 1')
        ->where('EO.delivery_group_number != EO1.delivery_group_number')
        ->where('EO.shipment_number != EO.delivery_group_number')
        ;
    echo $select;
    die();
    echo Mage::getModel('edi/edicarrier_ryder_order')->getPendingOrderIds();die();
    $columns = array(
        '# Order' => 'SFO.increment_id',
        '# Shipment' => 'EO.shipment_number',
        'Order Status' => 'SFOS.label',
        'Order Internal Status' => 'SFOIS.label',
        'MFR Status' => 'SOMS.label',
        'MFR Internal' => 'SOMIS.label',
        'Customer Status' => 'SOS.label',
        'Internal Status' => 'SOIS.label',
        'Cancel Status' => 'EO.cancel_status',
    );
    $select = $read->select()
        ->from(array('EO'=>'edicarrier_order'),array())
        ->join(array('SFO'=>'sales_flat_order'),"EO.order_id=SFO.entity_id",array())
        ->join(array('CMO'=>'ccc_manufacturer_order'),"EO.order_id=CMO.order_id AND EO.shipment_number=CMO.po_number",array())
        ->joinLeft(array('SFOS'=>'sales_order_status'),"SFO.status=SFOS.status",array())
        ->joinLeft(array('SFOIS'=>'sales_order_internal_status'),"SFO.internal_status =SFOIS.internal_status",array())
        ->joinLeft(array('SOMS'=>'sales_order_manufacturer_status'),"CMO.manufacturer_status=SOMS.manufacturer_status",array())
        ->joinLeft(array('SOMIS'=>'sales_order_manufacturer_internal_status'),"CMO.manufacturer_internal_status=SOMIS.manufacturer_internal_status",array())
        ->joinLeft(array('SOS'=>'sales_order_status'),"CMO.customer_status=SOS.status",array())
        ->joinLeft(array('SOIS'=>'sales_order_internal_status'),"CMO.internal_status=SOIS.internal_status",array())
        ->columns($columns)
        ->where('SFO.internal_status IN(?)',array('chargeback'))
        ->where('EO.cancel_status = ?',2)
        ;
    echo $select;
    die();
    print_r(Mage::getModel('edi/edicarrier_ryder_observer')->processUpdateEmail());die();
    $discription = Mage::getStoreConfig('ediryder/general/note_description');
    if ($discription) {
        var_dump(sprintf($discription, null, null));
    }
    var_dump(null);
    die();
    $actionLogLastId = $read->select()
        ->from(array('EAL'=>'edicarrier_action_log'),array('last_id' => new Zend_Db_Expr("MAX(EAL.id)")))
        ->where('EAL.shipment_number IN(?)',array('866230311','868750111','8692535-S2','8703032-S2','870449612','8710837-S1','872761111','873783011','8738100-S1','874892211','8756132-S1','8757387-S2','8759176-S2','8759099-S1','8759567-S1','8760099-S1','876070912','8761142-S2','8762769-S1','8762934-S1','8769416-S2','8773218-S1','8774006-S2','8774188-S1','8773975-S1','8774921-S1','8775404-S2','8776872-S1','8776890-S1','8777569-S1','8778741-S1','8782295-S1','8782865-S1','8783252-S2','8782560-S4','878905011','8789077-S1','8789385-S2','8790970-S1','8791389-S3','8791900-S1','8793384-S1','8794967-S1','8795042-S1','8795215-S1','8795397-S1','8796011-S1','8796483-S1','8797976-S1','8798593-S1','8798760-S1','8798826-S1','8799172-S1','8799220-S2','8799965-S1','8800247-S1','8800615-S1','8800650-S1','8800884-S1','8801347-S2','8802454-S2','8802678-S3','8802760-S1','8802767-S1','8803459-S2','8804741-S1','8805555-S1','8805697-S1','880649611','8806714-S1','8806734-S2','8806794-S1','8805601-S1','8807021-S1','880820811','880835411','880972911','880985511','880994511','881044211','881050011','881091313','881099011','881213911','881269612','881285111','881363311','881425612','881562311','881588411','881614111','881831812','882240812','882273211','882503712','882822413','882867712','883049811','883052511','883166612','883165112','883405011','883521811','883626911','883641711','883659912','883699212','883877812','883878912','883884511','883914811','883919811','883986411','884118911','884210852','884257412','884400111','884461511','884504911','884635712','884660912','884725414','884743811','884798511','885060112','885080812','885086211','885106912','885109411','885162713','885407011','885492211','885609811','885669314','885692712','885734012'))
        ->where('EAL.event_code IS NOT NULL')
        ->where('EAL.comment_code IS NOT NULL')
        ->group('EAL.shipment_number')
        ->group('EAL.ref_number');

    $actionLog = $read->select()
        ->from(array('EAL'=>'edicarrier_action_log'),array('shipment_number','ref_number' => new Zend_Db_Expr("REPLACE(GROUP_CONCAT(EAL.ref_number),',','/')"),'status' => new Zend_Db_Expr("GROUP_CONCAT(CONCAT('(',EAL.event_code,'/',EAL.comment_code,')'))")))
        ->join(array('at_last' => new Zend_Db_Expr("({$actionLogLastId})")),"EAL.id=at_last.last_id",array())
        ->group('EAL.shipment_number');
    echo $actionLog;
    echo "<br>=====================================================================================================================<br>";

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
        // 'Event Code' => 'at_action_log.event_code',
        // 'Comment Code' => 'at_action_log.comment_code',
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
    die();
    $discription = 'We need to deliver this %1$s shipment with %2$s';
    echo sprintf($discription, '8828600-S1','882860081');die();
    echo vprintf($discription, array('8828600-S1','882860081'));die();
    print_r(Mage::getModel('edi/edicarrier_ryder_observer')->processStatus());die();
    $templateId = 'ediryder_cancellation_email';
    $emailTemplate = Mage::getModel('core/email_template')->loadDefault($templateId);
    print_r($emailTemplate->getProcessedTemplateSubject(array(
            'shipment_number' => '8826315-S1',
            'subject' => 'jatin',
            'email_us_support_mail' => Mage::getStoreConfig('trans_email/ident_' . Oeditor_Ordereditor_Model_Customer_Note::CUSTOMER_NOTE_EMAIL_TYPE . '/email'),
        )));die();
    print_r(get_class_methods($emailTemplate));die();
    $processedTemplate = $emailTemplate->getProcessedTemplate(array(
            'shipment_number' => '8826315-S1',
            'subject' => 'jatin',
            'email_us_support_mail' => Mage::getStoreConfig('trans_email/ident_' . Oeditor_Ordereditor_Model_Customer_Note::CUSTOMER_NOTE_EMAIL_TYPE . '/email'),
        )); 
    print_r($processedTemplate);die();
    print_r(Mage::getModel('edi/edicarrier_ryder_observer')->processUpdateEmail());die();
    $select = $read->select()
        ->from(array('EO'=>'edicarrier_order'),array('order_id','increment_id','shipment_number','delivery_group_number'))
        ->join(array('CMO'=>'ccc_manufacturer_order'),"EO.order_id=CMO.order_id AND EO.shipment_number=CMO.po_number",array())
        ->where('CMO.shipment_id = 6')
        ->where('EO.increment_id=EO.delivery_group_number');
    echo $select;
    die();
    print_r(Mage::getModel('edi/edicarrier_ryder_observer')->processCancellationEmail());die();
    $array = array('8770272-S1','8770942-S1','8771618-S1','8775417-S1','8773792-S1','8770828-S1','8777660-S1','8772321-S1','8776440-S1','8776750-S1','8774647-S1','8772123-S1','8772316-S2','8778224-S1','8779411-S1','8780295-S1','8778233-S1','8778832-S2','8776864-S1','8776887-S1','8777164-S1','8776288-S1','8776386-S1','8775855-S1','8775975-S1','8775514-S1','8775587-S1','8775713-S1','8775715-S1','8775107-S1','8775190-S1','8775279-S2','8775303-S1','8773999-S1','8774347-S1','8773295-S1','8773769-S1','8773794-S1','8773995-S3','8773058-S1','8773155-S2','8772545-S1','8772614-S1','8772767-S1','8772303-S1','8772013-S1','8771463-S1','8771539-S1','8771648-S1','8771678-S1','8771154-S1','8771179-S1','8771220-S1','8770747-S1','8770753-S1','8770049-S1','8769734-S1','8769866-S1','8769013-S1','8769079-S2','8780902-S1','8778857-S1','8781585-S1','8779662-S2','8782161-S1','8783179-S1','8783302-S1','8782930-S1','8782394-S1','8774494-S2','8783917-S1','8783128-S1','8784453-S1','8784188-S1','8784518-S5','8785745-S1','8784674-S1','8785755-S1','8785952-S1','8786650-S1','8787027-S1','8787485-S1','8787501-S1','8787598-S1','8783023-S1','8788733-S1','8787718-S1','8787778-S1','8786380-S1','8787881-S1','8788881-S2','8771432-S1','8788182-S1','8788634-S1','8788895-S1','8789281-S1','8782333-S1','8789446-S1','8790141-S1','8789402-S1','8789426-S1','8789343-S1','8789311-S1','8789489-S1','8789736-S1','8790481-S1','8790155-S2','8790055-S1','8785379-S2','8790633-S1','8789385-S2','8790714-S1','8789743-S1','8791078-S1','8790034-S1','8791036-S1','8791511-S2','8791564-S1','8791255-S1','8791340-S1','8791219-S1','8791800-S1','8791800-S2','8791032-S1','8790034-S2','8792119-S1','8791606-S1','8791364-S1','8791794-S1','8791120-S1','8791808-S1','8791918-S1','8790916-S1','8791303-S1','8792500-S1','8792555-S1','8792608-S2','8792666-S1','8792681-S1','8791087-S2','8792461-S1','8792750-S1','8792462-S1','8792462-S2','8793002-S1','8792828-S1','8791952-S1','8789485-S2','8793444-S1','8793565-S1','8793902-S1','8784606-S1','8793630-S1','8793013-S1','8793846-S1','8794248-S1','8768168-S2','8793968-S1','8794319-S2','8794827-S1','8794098-S1','8794103-S1','8795026-S2','8794918-S1','8795087-S1','8794809-S1','8794427-S1','8794822-S2','8794641-S1','8795485-S1','8794661-S1','8795255-S1','8795598-S1','8794801-S1','8794098-S2','8793669-S2','8770041-S1','8794943-S1','8795003-S1','8795661-S2','8794808-S3','8795542-S1','8793866-S1','8795232-S1','8795072-S1','8795336-S1','8795509-S1','8795572-S1','8795681-S1','8795808-S1','8795789-S1','8795868-S1','8796978-S1','8795866-S1','8795212-S1','8795029-S1','8796113-S1','8796143-S1','8795185-S1','8795713-S2','8796583-S2','8797715-S1','8795381-S2','8797733-S1','8797516-S1','8797569-S1','8797361-S1','8779656-S1','8797483-S1','8798003-S1','8789380-S1','8792590-S2','8794074-S2','8798119-S1','8798198-S1','8769277-S1','8798979-S1','8799057-S1','8798392-S1','8799219-S1','8798512-S1','8794941-S1','8798629-S2','8798652-S1','8794827-S2','8798601-S1','8798711-S1','8798799-S1','8798846-S1','8799181-S1','8799223-S1','8799376-S1','8798991-S1','8798959-S1','8799261-S1','8799261-S2','8799260-S1','8800056-S1','8799565-S1','8798818-S1','8799435-S1','8800188-S1','8799656-S1','8799313-S1','8799408-S1','8800540-S1','8800572-S1','8799258-S1','8788002-S1','8796565-S1','8799185-S1','8799201-S1','8798779-S1','8800664-S1','8798916-S2','8799443-S2','8801046-S1','8798048-S2','8800671-S1','8801312-S1','8800097-S1','8801238-S2','8801646-S1','8800406-S1','8801362-S1','8801520-S1','8801758-S1','8801525-S1','8801746-S1','8801786-S1','8800063-S3','8802046-S1','8800887-S1','8801953-S1','8802041-S1','8801965-S1','8802129-S1','8802456-S2','8802597-S3','8802225-S2','8802239-S1','8802225-S1','8802388-S1','8802393-S1','8802377-S1','8802456-S3','8802978-S1','8800801-S1','8802518-S1','8802472-S1','8792466-S2','8802475-S1','8802609-S2','8802486-S1','8799443-S3','8803341-S1','8803344-S1','8802571-S1','8802843-S1','8802292-S1','8802692-S1','8802694-S1','8803699-S1','8802006-S1','8802526-S1','8802785-S1','8803895-S1','8802802-S1','8802896-S1','8801745-S1','8802426-S1','8802970-S1','8803227-S1','8803959-S3','8798783-S1','8800162-S1','8800509-S1','8801499-S1','8803434-S1','8796938-S1','8804034-S1','8802455-S1','8803466-S1','8803541-S1','8803996-S1','8803743-S2','8803639-S1','8803876-S1','8803404-S1','8804120-S1','8803760-S1','8803147-S1','8803863-S1','8803459-S2','8804132-S1','8803502-S1','8804524-S2','8803531-S1','8804232-S1','8791856-S1','8803963-S1','8804610-S1','8804009-S1','8804038-S1','8804741-S1','8804173-S1','8804919-S1','8803788-S1','8804746-S1','8804286-S1','8804081-S1','8805072-S1','8805020-S1','8805045-S1','8805045-S2','8792253-S1','8805041-S1','8805241-S1','8805295-S1','8779719-R1','8797751-S1','8805499-S1','8805564-S2','8805635-S1','8805139-S1','8805359-S1','8797343-S1','8805408-S1','8805477-S1','8776792-S2','8799810-S2','8805518-S1','8806144-S1','8805442-S1','8805480-S1','8799357-S1','8801035-S1','8806444-S1','8805944-S1','8806068-S1','8806103-S1','8806153-S1','8805986-S1','8804116-S3','8804230-S1','8805802-S1','8806803-S1','8806183-S1','8804456-S2','8806171-S1','8804147-S1','8805955-S3','8806093-S1','8806996-S1','8806989-S1','8807100-S1','8781778-S4','8805181-S2','8806379-S1','8798150-S1','8803679-S1','8790683-S2','878623511','880752511','880754111','880761311','880766611','880801411','880741711','880821111','880834611','880813013','880811611','880783411','880788411','880787011','880835212','880816711','880403911','880761611','880798711','880825511','879007181','880861911','880808311','880814811','877066581','880824111','880834111','880924411','880814812','880935911','880831411','880937011','880842911','880843811','8773519-S5','880949411','880934311','880960411','880850511','880900712','8801363-S1','880871811','880891211','880890911','880978011','880981811','880995911','880988311','881002011','880975411','880981011','880959711','880883611','881012211','881019111','880921011','881022711','881039511','880886011','880885811','881051111','881027411','880985411','881013811','881060711','881087711','881048511','881055311','881058611','881058111','880788811','879111113','8781564-S1','8789132-S1','8772747-S1','8773172-S1','8791119-S1','8794790-S1','8795207-S1','881026011','881098811','881105011','881112411','8775590-S1','881062011','8769136-S1','8769962-S1','8771214-S2','8779975-S1','8781895-S1','880012011','8806900-S1','880990111','881071511','881091313','881073511','881082211','880948713','881089211','881072111','881074111','881089811','881091211','881075211','880809111','881075511','881077111','877477981','8770320-S3','8776861-S1','881057811','881084411','881080411','881082411','881097211','881113511','881169111','881115911','881174211','881021912','881141311','881127611','881091911','881029913','881092111','881183511','881137611','881099612','881099611','8792952-S1','881093611','881160711','881165611','880938811','881160111','881161011','881179511','881193011','881197211','881155511','881160911','881079811','881187911','881190911','881193311','881145911','8799290-S1','881189411','8801086-S1','881201011','881226311','881220812','881225711','881187811','881222411','881228911','880807612','881243011','877536511','880887711','881197111','881213911','881214911','881225411','881188611','881188614','881170011','881257711','881188211','878768211','881266111','881109213','881268811','881228111','881210311','881225211','881229911','881248511','881309711','881315111','881265711','881319411','881288011','881302611','881328111','881325511','881314211','881324211','881314711','881314712','881314713','881283011','881337111','881302411','881304512','881304511','881309811','881290611','881293711','881305811','8806529-S1','881301811','881349911','881299211','881300211','881307011','881299711','881313811','881366011','881305712','881346111','881300611','881379111','881382711','881335211','881341411','881305011','881341711','881342611','881384311','881308911','881391311','881374511','8772758-S1','881380611','881350411','881401211','881392411','881403711','881342911','881384811','881389411','881308011','881391911','881388511','881341911','881388911','881424111','880460081','881437411','881431211','881397711','881443711','881429411','8804783-S1','881364211','881455111','881389311','881435611','881456311','881466811','881372011','881162811','881432511','881471612','881391811','878776411','881450411','881480911','881483111','881478311','881496612','881314512','881465311','881474211','881494211','881462411','881514911','881432811','881514111','881506911','881527911','881517911','881507411','881523411','881537911','881544911','881511111','881452111','881469411','881498411','881510811','881514611','880048511','881561911','881585511','881588211','881521111','881522011','881343111','881345011','881592811','881524411','881596411','881599411','881601911','881605011','881561411','881562211','881541511','881616511','881569011','881555911','881562711','881330611','881513111','881646912','881547311','881578611','881634111','881293311','881643611','881535111','881645312','881585211','881627311','881654311','881445311','881498611','881561111','881654511','881562011','881572211','881291711','880989111','881596311','881560112','881578211','881663711','881664611','881390811','881573811','881668511','881670211','881266211','881288211','881298711','881300511','881311111','881319711','881325611','881336211','881336212','881564211','881385911','881392111','881423811','881473611','881489812','881581711','881512211','881366911','881377611','881546311','881546711','881553811','881515511','881556011','881573611','881574611','881574811','881582611','881598411','881615311','881569911','881647511','881592411','881674211','881576211','881684511','881627111','881607911','881621811','881328113','881689812','881546712','881614111','881627711','881597211','881621011','881620811','881623111','881623911','881629211','881629911','881644011','881648011','881603311','8784641-S2','881620611','881641311','881648311','881730911','881656411','881663311','881664011','881665311','881675511','881736711','881629711','881679811','877065911','881672411','881677411','881677711','881725711','881733311','881641011','881784811','881741811','881746611','881786311','881778611','881659911','881672911','881378713','881688111','881810711','881762811','881786111','881816811','881633811','881664211','881693811','881786011','881823711','881679111','881701312','881726011','881777511','881796211','881681411','881677811','881791712','881701311','881717111','881696211','881708411','881803211','881619011','881755811','881826111','881844411','881800711','881722811','881723611','881744111','881227111','881854511','881837811','881812811','878403581','881755711','878362381','881846611','881761111','881764511','881831611','881773811','881779111','881797811','881672311','881794011','881800811','881804111','881805711','881807811','881808511','881901511','881809211','881822011','881831511','881834111','881850311','881852411','881853711','881856311','881864311','881919811','881859811','881862611','881864111','881864112','881870711','881904811','881895411','881904711','8794003-S1','881880211','881188615','881051011','881071811','881166011','881223311','881304111','881331711','881428311','881498511','881534411','881572311','881577411','881807611','881913811','881939511','881892811','881901111','881907111','881908911','881967011','881900611','881893111','881848411','881865411','881899511','881912911','881965511','881919311','881868711','881873211','881910311','881916111','881918611','881991911','881832812','881941811','882000011','881937111','881925911','881963211','881965011','882002511','881965811','881952311','881915511','881682811','881911011','881912511','881915011','881943311','8779482-S1','8779482-S2','881918411','882026711','881311512','881956611','882010711','882022911','881977511','882001711','882012211','881852811','881906611','881990511','881974911','882009211','881841911','881951311','881956411','882048511','881707311','882052011','882017511','882017512','881964311','881878011','881893211','881983411','882001211','882082111','882024311','882082911','882027811','881936111','881942111','882015811','881972011','881972611','881903311','881853811','881894011','881979811','881986411','882028111','882032511','882064712','881995511','882103412','881459511','882000711','882003911','882010211','882013611','882016411','880789511','881917911','882126111','882127111','882029311','882030511','882063711','882063811','882087511','882092611','882103511','882128111','882139511','882140811','881732511','881699411','882037611','881999511','882047011','882050911','882051011','882051812','882046611','882062011','882148111','882148311','882149211','882150811','882157711','882157811','882163811','882164611','881654381','881932811','882077511','882081811','882083812','882088911','882083811','882086212','882098711','882054512','882177911','882111411','882112811','882115611','882194411','882117311','882123011','882123511','882199111','882200411','882127311','882130311','882203511','881727011','881727012','882146411','882151712','882151911','882151711','881849211','882155211','882156013','882156711','882167711','882183411','882160911','882174911','882160811','882195111','882172211','882173911','882178911','882182811','8778010-S1','8778843-S1','8790855-S1','8791378-S1','882185011','882190711','882236811','882243911','882255211','882088411','882096511','881954111','882181111','882189511','882258711','879566111','881413211','881964811','882005211','882070111','882098811','882104811','882112711','882126011','882128012','882144911','882157411','882159511','882179011','882179411','882182411','882183111','880266481','882147811','882163011','882187811','882190611','882192311','882192811','882192011','882193411','882196611','882196811','8792734-S1','881445312','882186711','882198311','882200211','882200911','882201111','882201211','882206311','882207311','882205811','882206811','882208111','882213511','882213512','882214611','882219311','882229511','882233711','882242511','882269211','882269411','882198011','882290711','882200711','882201711','8788858-S3','8795127-S1','8800576-S1','8801322-S1','8770597-S1','8775447-S1','8788814-S1','8797219-S1','8793532-S1','8799700-S1','877506581','882188911','882220011','882222211','882224311','882226311','881849212','882232411','881845211','882210411','882229111','882314211','882309411','881741111','882231811','882182111','882248611','882234311','882216911','881694811','881904011','882068711','882193911','882290911','882261511','882189611','882223011','882292011','882226812','8776834-S1','8782948-S1','8791616-S1','8801749-S1','881244911','882047311','8794777-S1','8781859-S1','881039513','882545311','882555311','882593311','882607411','882607711','882611011','882621411','882625811','882601711','882628511','882589011','882663811','882558211','882591111','882606611','882591112','882555611','882590812','882630411','882631411','882652611','882653311','882677511','882554711','882638311','882653912','882655911','882681211','882682811','882593111','882653511','876940911','882637411','882655611','882689611','881776812','882551512','882686011','882696411','881601281','882698411','882648011','882533511','882590311','882593511','882595711','882705911','882708711','882603311','8788861-S3','882617011','882717311','882477913','882719911','882360111','882428911','882449411','882461211','882489311','882514011','882602311','882660611','882565111','882661911','8776389-S1','882676311','882723811','882705011','882700511','882704111','882709211','882730011','882578712','882656811','882611311','882610111','882707811','882702511','882699711','882691611','882703911','882731011','882734611','882735211','882630311','882706311','882627311','882692611','882696011','882697511','882699511','882651711','882698511','882702711','882623511','882672811','882743111','882547211','882746711','882606011','882724111','882677711','880829981','882605111','882736911','882530711','882745511','882640313','882641311','882647711','882640311','882640312','882653011','882503211','882656311','8787102-S1','8795835-S2','8787245-S1','8797995-S2','880882581','882746511','882722211','882748311','882761012','882755311','882741211','882761011','882757911','882478211','882650911','882719511','882776911','881498481','882665511','882778411','882576411','877954981','882679811','882680411','881662482','882684711','882773511','882687111','882657711','882618711','882696611','882700911','882701111','882708811','882798011','882712811','882796311','882797011','882805311','882724612','882724911','882724611','882734711','882741611','882747811','882741511','882748711','882749711','882751211','882751411','882751012','882752611','882753711','882754511','882754411','882754513','882755411','882755911','882756011','882757211','882757711','882822511','882823911','882743211','882772211','882822111','882827111','882784311','882429511','882817011','882826211','882823611','882828512','882762111','882762911','882839611','882769611','882766311','882843811','882767411','882846211','882780711','882843311','882772111','882786012','882773911','882850811','882817311','882786511','882857211','882784011','882789111','882789112','882785511','882782211','882787211','882788511','882789311','882790811','882864011','882870111','882872411','882603711','882796211','882854611','882792411','882757511','882803711','882794011','882879811','882881811','882882011','882793911','882797811','882798111','882882911','882889011','882794911','882192012','882813911','879654381','882784211','882807511','882812511','882814611','882797511','882806011','882895412','882892512','881864381','882872512','882799111','882895411','882817511','882818411','882823211','882825511','882821711','882808911','882900212','882904111','882904411','880658881','882807711','882905011','882804911','882815611','882804411','882817811','882805211','882910112','882806111','882806711','882807013','882807911','882807011','882807012','882809511','882810611','882811411','882815911','882817711','882818011','882818312','882819211','882779111','882820411','882819811','882920613','882929211','882820511','882822711','882823511','882923311','882503212','882857611','882826011','882835411','882845411','882848011','881882611','882866211','882861711','882822811','882822911','882853011','882872611','882644612','882913613','882944411','881965583','882827311','882825911','882828111','882764211','882834611','882882811','882953511','882833811','882834511','882830311','882833912','882857811','881639681','882808311','882895711','882833911','882841511','882905811','882849211','882859711','882967312','882967311','882854311','882857712','882858211','882858511','882861011','882839911','882862411','882862511','880561281','882956911','879124911','882866311','882877511','882921411','882883412','882986812','882763711','882794511','882801811','882856511','882857111','882865111','882871511','882882711','882884911','882889811','882698811','882700211','882906711','882988311','882988811','882991611','882700811','882871611','882882511','882883511','882993611','882875211','882950211','882482911','882909011','882807311','882808511','882920611','882856211','882868611','882881411','882887911','882918311','882934611','882642211','882650111','882681711','882703311','882740111','882745311','881909412','882483611','882666111','882683911','882686711','882734011','882822413','882844211','882891911','882924011','882924012','882973111','882740112','882808611','882888711','882514512','882780211','882782511','882839011','882845311','882859311','882875511','882876811','882911311','882961711','882992211','882744611','882766611','882766811','882877111','883000411','882999111','883005211','883005311','883006611','883007011','883008911','882796212','882798711','882803511','882812111','882812411','882813711','882813011','882840311','882842311','882858811','882913711','882939811','882968311','882974911','882995111','883000211','882785811','882918711','882930911','882946111','882989311','882998911','882963611','882971311','882987511','882987512','882884611','882911511','882921311','882932311','882935411','883011811','882906111','882815913','883013812','882873311','882886711','882955611','882887011','882987711','882998611','883013811','882939011','882935811','882964411','882899011','882949811','882952411','882323911','882898411','882922411','882972311','882906811','882909811','882911911','882912311','882912911','882914911','882952011','882917911','882967811','882972511','882998511','883009411','883019411','883021511','883024111','883025511','883032411','883032611','883032711','883036611','883036111','883015111','883032612','883040111','882918811','883022711','883024811','882920111','882821311','883020211','879005281','882926711','882927811','883049111','882936611','882937511','882939411','883022011','882964111','882925111','883009811','882949011','882952211','882953411','882953711','882963111','883056211','883057011','882964311','882960411','882963011','882960211','882966711','882968111','882970911','882972911','882758211','883001711','882981011','882975011','883067211','883067511','881189911','883076712','882968511','882997211','883078711','883078111','883079411','882649911','882954911','882956111','883032911','883076711','882988511','883086511','882731112','882987611','883112712','883111611','882972811','883112711','882996911','883002711','883100013','883003911','883100011','882960011','882960012','883020811','883023811','883141911','883131111','883031611','883031911','883088611','882899211','882924411','883035811','883037411','883039711','883149911','883041011','883042911','883090711','883035112','883044011','883044411','883044811','883045311','883163811','883045911','883164711','883047111','883047511','883056011','883166611','883166612','883048011','883048111','883048411','883048811','883049211','883050111','883050711','883051911','883052311','883051411','883053411','883054511','883047011','883055111','883056811','883057311','883058111','883058411','883058712','883059911','883059811','883060011','883060911','883061011','883061311','883061511','883061711','883058711','883178811','883060411','883065611','883062111','883062311','883181611','883183511','883063411','883063211','883064211','883064511','883065311','883186211','883065811','883066111','883066211','883066811','883067111','883189411','883071412','883019013','883073011','883073211','883073711','883073411','883074411','883084411','883086111','883095611','883098211','883035111','883075511','883076011','883076511','883078511','883079911','883083911','883085011','882556911','882598211','882672911','882678011','882792711','882801111','882833011','882987311','883028011','883086911','883203811','883086411','883117611','883158911','883092011','883094811','883095111','883099811','883100311','883100411','883100511','883109111','883109011','883114211','883117712','883104811','883106813','883117711','883109911','883110111','883110711','883110611','883111011','883111411','883112011','883113011','883113511','883113811','883114911','883115211','883115511','883117111','883117811','883117511','883115811','883118911','883119211','883119511','883123811','883201611','883201911','883120811','883124311','883126311','883121911','883122611','883122011','883123411','883123511','883124411','883124911','883125011','883125611','883123911','883126011','883127111','883127711','883108011','883127911','883128911','883129411','883130311','883130611','883129011','883131311','883131511','883132311','883133911','883223511','882959811','883176511','883135711','882780511','882917211','882972111','883013511','883097211','879092711','882652212','883137011','883139511','883141311','883142311','883158311','883241811','883247411','883140111','883141011','883141711','883141611','883142412','883143811','883264011','883146911','883155411','883145411','883264311','883145911','883146411','883146811','882279211','882280011','882380011','882395311','882402111','882474111','882508911','882523311','882705711','882706111','882782411','882853711','883143011','883252911','882874411','882975511','883001611','883025911','883148311','883149011','883149311','883149611','883150111','883150311','883275411','883151311','883151511','883204511','883216011','883228011','883280411','883152511','883282311','883257611','883260211','883279111','883283711','883284311','883042711','883156411','883156511','883156711','883157211','883162411','883167011','883151911','883157911','883158111','883160711','883162011','883163711','883164011','883109311','883165412','883165711','883165811','883266211','883166911','883167711','883167811','883168611','883168911','883169111','883169611','883170411','883170211','883170711','883170911','883172111','883172711','883172911','881421913','883173411','883173611','883292911','883209511','883125314','883125311','883125312','883125313','883240711','883277611','883106212','883211611','883172211','883176211','883178311','883053612','883179311','883180811','883044012','883124611','883320512','883184611','883053611','883177911','883178011','883327811','883332411','881616512','883110811','883122911','883129211','883179412','883179911','883179011','883220911','883269511','883338211','882525011','883103011','883126211','883167311','883167412','883289011','883142011','883161211','883181911','883182011','883182511','883109211','883284111','883126511','883186011','883182811','883183211','883186911','883337211','883356111','883134312','883172811','883164511','883189211','883189111','883190011','883197711','883360312','883199211','883322411','883192911','883194511','883207512','883194211','883129613','883183912','883195111','883196311','883197311','883292411','881387481','883198611','883193711','883207511','883207011','883208511','883199611','883200511','883201011','883202511','883202411','883202711','883203511','883209611','883205113','883183011','883206211','883205112','883210311','883168311','883207811','883220811','883209211','883209011','883210111','883210911','883212411','883213811','883213912','883215511','883215711','883216311','883228311','882798911','883078011','883209911','883225011','883260911','883331011','883370611','883139911','883219311','883220611','882802211','883053211','883103111','883153012','883393111','883216211','883361211','883368111','882811211','882994911','883204611','883223811','883245911','883295511','882716511','882720411','882820711','883174911','883230811','882781111','883225211','883275511','883398811','882850511','883242112','882940312','883226211','883227211','883226411','883227911','880488211','883229111','883229011','883253011','883229411','882900512','883227611','883231811','883232411','883232611','883188011','883233511','883233711','883408811','883206011','883234711','883266711','883291611','883409711','883410211','883243111','883241011','883085611','883234011','883242711','883243611','883246911','883128411','883338811','883248111','881651112','882819511','883055211','883057811','883071711','883071111','883077711','883099011','883106211','883110211','883128511','883139411','883160111','883168111','883193511','883198111','883208611','883221911','883231111','883234111','883245111','883250212','883250811','883210511','883252711','883252811','883255611','883256511','883257111','883210011','883258511','883259311','883261111','883261312','883262111','883262811','883289911','883296811','883429411','883206411','883264911','883265311','883265611','883266611','883286611','883254011','883254012','883258211','883271211','883271511','883272411','883273511','883259111','883418511','883275011','883276511','883281811','883283011','883284911','883285311','883286411','883286811','883287211','883287412','883289412','883290412','883291111','883291511','883292111','883292511','883290411','883293811','883294711','883277311');
    $array = array('8770272-S1','8770942-S1','8771618-S1','8775417-S1','8773792-S1','8770828-S1','8777660-S1','8772321-S1','8776440-S1','8776750-S1','8774647-S1','8772123-S1','8772316-S2','8778224-S1','8779411-S1','8780295-S1','8778233-S1','8778832-S2','8776864-S1','8776887-S1','8777164-S1','8776288-S1','8776386-S1','8775855-S1','8775975-S1','8775514-S1','8775587-S1','8775713-S1','8775715-S1','8775107-S1','8775190-S1','8775279-S2','8775303-S1','8773999-S1','8774347-S1','8773295-S1','8773769-S1','8773794-S1','8773995-S3','8773058-S1','8773155-S2','8772545-S1','8772614-S1','8772767-S1','8772303-S1','8772013-S1','8771463-S1','8771539-S1','8771648-S1','8771678-S1','8771154-S1','8771179-S1','8771220-S1','8770747-S1','8770753-S1','8770049-S1','8769734-S1','8769866-S1','8769013-S1','8769079-S2','8780902-S1','8778857-S1','8781585-S1','8779662-S2','8782161-S1','8783179-S1','8783302-S1','8782930-S1','8782394-S1','8774494-S2','8783917-S1','8783128-S1','8784453-S1','8784188-S1','8784518-S5','8785745-S1','8784674-S1','8785755-S1','8785952-S1','8786650-S1','8787027-S1','8787485-S1','8787501-S1','8787598-S1','8783023-S1','8788733-S1','8787718-S1','8787778-S1','8786380-S1','8787881-S1','8788881-S2','8771432-S1','8788182-S1','8788634-S1','8788895-S1','8789281-S1','8782333-S1','8789446-S1','8790141-S1','8789402-S1','8789426-S1','8789343-S1','8789311-S1','8789489-S1','8789736-S1','8790481-S1','8790155-S2','8790055-S1','8785379-S2','8790633-S1','8789385-S2','8790714-S1','8789743-S1','8791078-S1','8790034-S1','8791036-S1','8791511-S2','8791564-S1','8791255-S1','8791340-S1','8791219-S1','8791800-S1','8791800-S2','8791032-S1','8790034-S2','8792119-S1','8791606-S1','8791364-S1','8791794-S1','8791120-S1','8791808-S1','8791918-S1','8790916-S1','8791303-S1','8792500-S1','8792555-S1','8792608-S2','8792666-S1','8792681-S1','8791087-S2','8792461-S1','8792750-S1','8792462-S1','8792462-S2','8793002-S1','8792828-S1','8791952-S1','8789485-S2','8793444-S1','8793565-S1','8793902-S1','8784606-S1','8793630-S1','8793013-S1','8793846-S1','8794248-S1','8768168-S2','8793968-S1','8794319-S2','8794827-S1','8794098-S1','8794103-S1','8795026-S2','8794918-S1','8795087-S1','8794809-S1','8794427-S1','8794822-S2','8794641-S1','8795485-S1','8794661-S1','8795255-S1','8795598-S1','8794801-S1','8794098-S2','8793669-S2','8770041-S1','8794943-S1','8795003-S1','8795661-S2','8794808-S3','8795542-S1','8793866-S1','8795232-S1','8795072-S1','8795336-S1','8795509-S1','8795572-S1','8795681-S1','8795808-S1','8795789-S1','8795868-S1','8796978-S1','8795866-S1','8795212-S1','8795029-S1','8796113-S1','8796143-S1','8795185-S1','8795713-S2','8796583-S2','8797715-S1','8795381-S2','8797733-S1','8797516-S1','8797569-S1','8797361-S1','8779656-S1','8797483-S1','8798003-S1','8789380-S1','8792590-S2','8794074-S2','8798119-S1','8798198-S1','8769277-S1','8798979-S1','8799057-S1','8798392-S1','8799219-S1','8798512-S1','8794941-S1','8798629-S2','8798652-S1','8794827-S2','8798601-S1','8798711-S1','8798799-S1','8798846-S1','8799181-S1','8799223-S1','8799376-S1','8798991-S1','8798959-S1','8799261-S1','8799261-S2','8799260-S1','8800056-S1','8799565-S1','8798818-S1','8799435-S1','8800188-S1','8799656-S1','8799313-S1','8799408-S1','8800540-S1','8800572-S1','8799258-S1','8788002-S1','8796565-S1','8799185-S1','8799201-S1','8798779-S1','8800664-S1','8798916-S2','8799443-S2','8801046-S1','8798048-S2','8800671-S1','8801312-S1','8800097-S1','8801238-S2','8801646-S1','8800406-S1','8801362-S1','8801520-S1','8801758-S1','8801525-S1','8801746-S1','8801786-S1','8800063-S3','8802046-S1','8800887-S1','8801953-S1','8802041-S1','8801965-S1','8802129-S1','8802456-S2','8802597-S3','8802225-S2','8802239-S1','8802225-S1','8802388-S1','8802393-S1','8802377-S1','8802456-S3','8802978-S1','8800801-S1','8802518-S1','8802472-S1','8792466-S2','8802475-S1','8802609-S2','8802486-S1','8799443-S3','8803341-S1','8803344-S1','8802571-S1','8802843-S1','8802292-S1','8802692-S1','8802694-S1','8803699-S1','8802006-S1','8802526-S1','8802785-S1','8803895-S1','8802802-S1','8802896-S1','8801745-S1','8802426-S1','8802970-S1','8803227-S1','8803959-S3','8798783-S1','8800162-S1','8800509-S1','8801499-S1','8803434-S1','8796938-S1','8804034-S1','8802455-S1','8803466-S1','8803541-S1','8803996-S1','8803743-S2','8803639-S1','8803876-S1','8803404-S1','8804120-S1','8803760-S1','8803147-S1','8803863-S1','8803459-S2','8804132-S1','8803502-S1','8804524-S2','8803531-S1','8804232-S1','8791856-S1','8803963-S1','8804610-S1','8804009-S1','8804038-S1','8804741-S1','8804173-S1','8804919-S1','8803788-S1','8804746-S1','8804286-S1','8804081-S1','8805072-S1','8805020-S1','8805045-S1','8805045-S2','8792253-S1','8805041-S1','8805241-S1','8805295-S1','8779719-R1','8797751-S1','8805499-S1','8805564-S2','8805635-S1','8805139-S1','8805359-S1','8797343-S1','8805408-S1','8805477-S1','8776792-S2','8799810-S2','8805518-S1','8806144-S1','8805442-S1','8805480-S1','8799357-S1','8801035-S1','8806444-S1','8805944-S1','8806068-S1','8806103-S1','8806153-S1','8805986-S1','8804116-S3','8804230-S1','8805802-S1','8806803-S1','8806183-S1','8804456-S2','8806171-S1','8804147-S1','8805955-S3','8806093-S1','8806996-S1','8806989-S1','8807100-S1','8781778-S4','8805181-S2','8806379-S1','8798150-S1','8803679-S1','8790683-S2','878623511','880752511','880754111','880761311','880766611','880801411','880741711','880821111','880834611','880813013','880811611','880783411','880788411','880787011','880835212','880816711','880403911','880761611','880798711','880825511','879007181','880861911','880808311','880814811','877066581','880824111','880834111','880924411','880814812','880935911','880831411','880937011','880842911','880843811','8773519-S5','880949411','880934311','880960411','880850511','880900712','8801363-S1','880871811','880891211','880890911','880978011','880981811','880995911','880988311','881002011','880975411','880981011','880959711','880883611','881012211','881019111','880921011','881022711','881039511','880886011','880885811','881051111','881027411','880985411','881013811','881060711','881087711','881048511','881055311','881058611','881058111','880788811','879111113','8781564-S1','8789132-S1','8772747-S1','8773172-S1','8791119-S1','8794790-S1','8795207-S1','881026011','881098811','881105011','881112411','8775590-S1','881062011','8769136-S1','8769962-S1','8771214-S2','8779975-S1','8781895-S1','880012011','8806900-S1','880990111','881071511','881091313','881073511','881082211','880948713','881089211','881072111','881074111','881089811','881091211','881075211','880809111','881075511','881077111','877477981','8770320-S3','8776861-S1','881057811','881084411','881080411','881082411','881097211','881113511','881169111','881115911','881174211','881021912','881141311','881127611','881091911','881029913','881092111','881183511','881137611','881099612','881099611','8792952-S1','881093611','881160711','881165611','880938811','881160111','881161011','881179511','881193011','881197211','881155511','881160911','881079811','881187911','881190911','881193311','881145911','8799290-S1','881189411','8801086-S1','881201011','881226311','881220812','881225711','881187811','881222411','881228911','880807612','881243011','877536511','880887711','881197111','881213911','881214911','881225411','881188611','881188614','881170011','881257711','881188211','878768211','881266111','881109213','881268811','881228111','881210311','881225211','881229911','881248511','881309711','881315111','881265711','881319411','881288011','881302611','881328111','881325511','881314211','881324211','881314711','881314712','881314713','881283011','881337111','881302411','881304512','881304511','881309811','881290611','881293711','881305811','8806529-S1','881301811','881349911','881299211','881300211','881307011','881299711','881313811','881366011','881305712','881346111','881300611','881379111','881382711','881335211','881341411','881305011','881341711','881342611','881384311','881308911','881391311','881374511','8772758-S1','881380611','881350411','881401211','881392411','881403711','881342911','881384811','881389411','881308011','881391911','881388511','881341911','881388911','881424111','880460081','881437411','881431211','881397711','881443711','881429411','8804783-S1','881364211','881455111','881389311','881435611','881456311','881466811','881372011','881162811','881432511','881471612','881391811','878776411','881450411','881480911','881483111','881478311','881496612','881314512','881465311','881474211','881494211','881462411','881514911','881432811','881514111','881506911','881527911','881517911','881507411','881523411','881537911','881544911','881511111','881452111','881469411','881498411','881510811','881514611','880048511','881561911','881585511','881588211','881521111','881522011','881343111','881345011','881592811','881524411','881596411','881599411','881601911','881605011','881561411','881562211','881541511','881616511','881569011','881555911','881562711','881330611','881513111','881646912','881547311','881578611','881634111','881293311','881643611','881535111','881645312','881585211','881627311','881654311','881445311','881498611','881561111','881654511','881562011','881572211','881291711','880989111','881596311','881560112','881578211','881663711','881664611','881390811','881573811','881668511','881670211','881266211','881288211','881298711','881300511','881311111','881319711','881325611','881336211','881336212','881564211','881385911','881392111','881423811','881473611','881489812','881581711','881512211','881366911','881377611','881546311','881546711','881553811','881515511','881556011','881573611','881574611','881574811','881582611','881598411','881615311','881569911','881647511','881592411','881674211','881576211','881684511','881627111','881607911','881621811','881328113','881689812','881546712','881614111','881627711','881597211','881621011','881620811','881623111','881623911','881629211','881629911','881644011','881648011','881603311','8784641-S2','881620611','881641311','881648311','881730911','881656411','881663311','881664011','881665311','881675511','881736711','881629711','881679811','877065911','881672411','881677411','881677711','881725711','881733311','881641011','881784811','881741811','881746611','881786311','881778611','881659911','881672911','881378713','881688111','881810711','881762811','881786111','881816811','881633811','881664211','881693811','881786011','881823711','881679111','881701312','881726011','881777511','881796211','881681411','881677811','881791712','881701311','881717111','881696211','881708411','881803211','881619011','881755811','881826111','881844411','881800711','881722811','881723611','881744111','881227111','881854511','881837811','881812811','878403581','881755711','878362381','881846611','881761111','881764511','881831611','881773811','881779111','881797811','881672311','881794011','881800811','881804111','881805711','881807811','881808511','881901511','881809211','881822011','881831511','881834111','881850311','881852411','881853711','881856311','881864311','881919811','881859811','881862611','881864111','881864112','881870711','881904811','881895411','881904711','8794003-S1','881880211','881188615','881051011','881071811','881166011','881223311','881304111','881331711','881428311','881498511','881534411','881572311','881577411','881807611','881913811','881939511','881892811','881901111','881907111','881908911','881967011','881900611','881893111','881848411','881865411','881899511','881912911','881965511','881919311','881868711','881873211','881910311','881916111','881918611','881991911','881832812','881941811','882000011','881937111','881925911','881963211','881965011','882002511','881965811','881952311','881915511','881682811','881911011','881912511','881915011','881943311','8779482-S1','8779482-S2','881918411','882026711','881311512','881956611','882010711','882022911','881977511','882001711','882012211','881852811','881906611','881990511','881974911','882009211','881841911','881951311','881956411','882048511','881707311','882052011','882017511','882017512','881964311','881878011','881893211','881983411','882001211','882082111','882024311','882082911','882027811','881936111','881942111','882015811','881972011','881972611','881903311','881853811','881894011','881979811','881986411','882028111','882032511','882064712','881995511','882103412','881459511','882000711','882003911','882010211','882013611','882016411','880789511','881917911','882126111','882127111','882029311','882030511','882063711','882063811','882087511','882092611','882103511','882128111','882139511','882140811','881732511','881699411','882037611','881999511','882047011','882050911','882051011','882051812','882046611','882062011','882148111','882148311','882149211','882150811','882157711','882157811','882163811','882164611','881654381','881932811','882077511','882081811','882083812','882088911','882083811','882086212','882098711','882054512','882177911','882111411','882112811','882115611','882194411','882117311','882123011','882123511','882199111','882200411','882127311','882130311','882203511','881727011','881727012','882146411','882151712','882151911','882151711','881849211','882155211','882156013','882156711','882167711','882183411','882160911','882174911','882160811','882195111','882172211','882173911','882178911','882182811','8778010-S1','8778843-S1','8790855-S1','8791378-S1','882185011','882190711','882236811','882243911','882255211','882088411','882096511','881954111','882181111','882189511','882258711','879566111','881413211','881964811','882005211','882070111','882098811','882104811','882112711','882126011','882128012','882144911','882157411','882159511','882179011','882179411','882182411','882183111','880266481','882147811','882163011','882187811','882190611','882192311','882192811','882192011','882193411','882196611','882196811','8792734-S1','881445312','882186711','882198311','882200211','882200911','882201111','882201211','882206311','882207311','882205811','882206811','882208111','882213511','882213512','882214611','882219311','882229511','882233711','882242511','882269211','882269411','882198011','882290711','882200711','882201711','8788858-S3','8795127-S1','8800576-S1','8801322-S1','8770597-S1','8775447-S1','8788814-S1','8797219-S1','8793532-S1','8799700-S1','877506581','882188911','882220011','882222211','882224311','882226311','881849212','882232411','881845211','882210411','882229111','882314211','882309411','881741111','882231811','882182111','882248611','882234311','882216911','881694811','881904011','882068711','882193911','882290911','882261511','882189611','882223011','882292011','882226812','8776834-S1','8782948-S1','8791616-S1','8801749-S1','881244911','882047311','8794777-S1','8781859-S1','881039513','882545311','882555311','882593311','882607411','882607711','882611011','882621411','882625811','882601711','882628511','882589011','882663811','882558211','882591111','882606611','882591112','882555611','882590812','882630411','882631411','882652611','882653311','882677511','882554711','882638311','882653912','882655911','882681211','882682811','882593111','882653511','876940911','882637411','882655611','882689611','881776812','882551512','882686011','882696411','881601281','882698411','882648011','882533511','882590311','882593511','882595711','882705911','882708711','882603311','8788861-S3','882617011','882717311','882477913','882719911','882360111','882428911','882449411','882461211','882489311','882514011','882602311','882660611','882565111','882661911','8776389-S1','882676311','882723811','882705011','882700511','882704111','882709211','882730011','882578712','882656811','882611311','882610111','882707811','882702511','882699711','882691611','882703911','882731011','882734611','882735211','882630311','882706311','882627311','882692611','882696011','882697511','882699511','882651711','882698511','882702711','882623511','882672811','882743111','882547211','882746711','882606011','882724111','882677711','880829981','882605111','882736911','882530711','882745511','882640313','882641311','882647711','882640311','882640312','882653011','882503211','882656311','8787102-S1','8795835-S2','8787245-S1','8797995-S2','880882581','882746511','882722211','882748311','882761012','882755311','882741211','882761011','882757911','882478211','882650911','882719511','882776911','881498481','882665511','882778411','882576411','877954981','882679811','882680411','881662482','882684711','882773511','882687111','882657711','882618711','882696611','882700911','882701111','882708811','882798011','882712811','882796311','882797011','882805311','882724612','882724911','882724611','882734711','882741611','882747811','882741511','882748711','882749711','882751211','882751411','882751012','882752611','882753711','882754511','882754411','882754513','882755411','882755911','882756011','882757211','882757711','882822511','882823911','882743211','882772211','882822111','882827111','882784311','882429511','882817011','882826211','882823611','882828512','882762111','882762911','882839611','882769611','882766311','882843811','882767411','882846211','882780711','882843311','882772111','882786012','882773911','882850811','882817311','882786511','882857211','882784011','882789111','882789112','882785511','882782211','882787211','882788511','882789311','882790811','882864011','882870111','882872411','882603711','882796211','882854611','882792411','882757511','882803711','882794011','882879811','882881811','882882011','882793911','882797811','882798111','882882911','882889011','882794911','882192012','882813911','879654381','882784211','882807511','882812511','882814611','882797511','882806011','882895412','882892512','881864381','882872512','882799111','882895411','882817511','882818411','882823211','882825511','882821711','882808911','882900212','882904111','882904411','880658881','882807711','882905011','882804911','882815611','882804411','882817811','882805211','882910112','882806111','882806711','882807013','882807911','882807011','882807012','882809511','882810611','882811411','882815911','882817711','882818011','882818312','882819211','882779111','882820411','882819811','882920613','882929211','882820511','882822711','882823511','882923311','882503212','882857611','882826011','882835411','882845411','882848011','881882611','882866211','882861711','882822811','882822911','882853011','882872611','882644612','882913613','882944411','881965583','882827311','882825911','882828111','882764211','882834611','882882811','882953511','882833811','882834511','882830311','882833912','882857811','881639681','882808311','882895711','882833911','882841511','882905811','882849211','882859711','882967312','882967311','882854311','882857712','882858211','882858511','882861011','882839911','882862411','882862511','880561281','882956911','879124911','882866311','882877511','882921411','882883412','882986812','882763711','882794511','882801811','882856511','882857111','882865111','882871511','882882711','882884911','882889811','882698811','882700211','882906711','882988311','882988811','882991611','882700811','882871611','882882511','882883511','882993611','882875211','882950211','882482911','882909011','882807311','882808511','882920611','882856211','882868611','882881411','882887911','882918311','882934611','882642211','882650111','882681711','882703311','882740111','882745311','881909412','882483611','882666111','882683911','882686711','882734011','882822413','882844211','882891911','882924011','882924012','882973111','882740112','882808611','882888711','882514512','882780211','882782511','882839011','882845311','882859311','882875511','882876811','882911311','882961711','882992211','882744611','882766611','882766811','882877111','883000411','882999111','883005211','883005311','883006611','883007011','883008911','882796212','882798711','882803511','882812111','882812411','882813711','882813011','882840311','882842311','882858811','882913711','882939811','882968311','882974911','882995111','883000211','882785811','882918711','882930911','882946111','882989311','882998911','882963611','882971311','882987511','882987512','882884611','882911511','882921311','882932311','882935411','883011811','882906111','882815913','883013812','882873311','882886711','882955611','882887011','882987711','882998611','883013811','882939011','882935811','882964411','882899011','882949811','882952411','882323911','882898411','882922411','882972311','882906811','882909811','882911911','882912311','882912911','882914911','882952011','882917911','882967811','882972511','882998511','883009411','883019411','883021511','883024111','883025511','883032411','883032611','883032711','883036611','883036111','883015111','883032612','883040111','882918811','883022711','883024811','882920111','882821311','883020211','879005281','882926711','882927811','883049111','882936611','882937511','882939411','883022011','882964111','882925111','883009811','882949011','882952211','882953411','882953711','882963111','883056211','883057011','882964311','882960411','882963011','882960211','882966711','882968111','882970911','882972911','882758211','883001711','882981011','882975011','883067211','883067511','881189911','883076712','882968511','882997211','883078711','883078111','883079411','882649911','882954911','882956111','883032911','883076711','882988511','883086511','882731112','882987611','883112712','883111611','882972811','883112711','882996911','883002711','883100013','883003911','883100011','882960011','882960012','883020811','883023811','883141911','883131111','883031611','883031911','883088611','882899211','882924411','883035811','883037411','883039711','883149911','883041011','883042911','883090711','883035112','883044011','883044411','883044811','883045311','883163811','883045911','883164711','883047111','883047511','883056011','883166611','883166612','883048011','883048111','883048411','883048811','883049211','883050111','883050711','883051911','883052311','883051411','883053411','883054511','883047011','883055111','883056811','883057311','883058111','883058411','883058712','883059911','883059811','883060011','883060911','883061011','883061311','883061511','883061711','883058711','883178811','883060411','883065611','883062111','883062311','883181611','883183511','883063411','883063211','883064211','883064511','883065311','883186211','883065811','883066111','883066211','883066811','883067111','883189411','883071412','883019013','883073011','883073211','883073711','883073411','883074411','883084411','883086111','883095611','883098211','883035111','883075511','883076011','883076511','883078511','883079911','883083911','883085011','882556911','882598211','882672911','882678011','882792711','882801111','882833011','882987311','883028011','883086911','883203811','883086411','883117611','883158911','883092011','883094811','883095111','883099811','883100311','883100411','883100511','883109111','883109011','883114211','883117712','883104811','883106813','883117711','883109911','883110111','883110711','883110611','883111011','883111411','883112011','883113011','883113511','883113811','883114911','883115211','883115511','883117111','883117811','883117511','883115811','883118911','883119211','883119511','883123811','883201611','883201911','883120811','883124311','883126311','883121911','883122611','883122011','883123411','883123511','883124411','883124911','883125011','883125611','883123911','883126011','883127111','883127711','883108011','883127911','883128911','883129411','883130311','883130611','883129011','883131311','883131511','883132311','883133911','883223511','882959811','883176511','883135711','882780511','882917211','882972111','883013511','883097211','879092711','882652212','883137011','883139511','883141311','883142311','883158311','883241811','883247411','883140111','883141011','883141711','883141611','883142412','883143811','883264011','883146911','883155411','883145411','883264311','883145911','883146411','883146811','882279211','882280011','882380011','882395311','882402111','882474111','882508911','882523311','882705711','882706111','882782411','882853711','883143011','883252911','882874411','882975511','883001611','883025911','883148311','883149011','883149311','883149611','883150111','883150311','883275411','883151311','883151511','883204511','883216011','883228011','883280411','883152511','883282311','883257611','883260211','883279111','883283711','883284311','883042711','883156411','883156511','883156711','883157211','883162411','883167011','883151911','883157911','883158111','883160711','883162011','883163711','883164011','883109311','883165412','883165711','883165811','883266211','883166911','883167711','883167811','883168611','883168911','883169111','883169611','883170411','883170211','883170711','883170911','883172111','883172711','883172911','881421913','883173411','883173611','883292911','883209511','883125314','883125311','883125312','883125313','883240711','883277611','883106212','883211611','883172211','883176211','883178311','883053612','883179311','883180811','883044012','883124611','883320512','883184611','883053611','883177911','883178011','883327811','883332411','881616512','883110811','883122911','883129211','883179412','883179911','883179011','883220911','883269511','883338211','882525011','883103011','883126211','883167311','883167412','883289011','883142011','883161211','883181911','883182011','883182511','883109211','883284111','883126511','883186011','883182811','883183211','883186911','883337211','883356111','883134312','883172811','883164511','883189211','883189111','883190011','883197711','883360312','883199211','883322411','883192911','883194511','883207512','883194211','883129613','883183912','883195111','883196311','883197311','883292411','881387481','883198611','883193711','883207511','883207011','883208511','883199611','883200511','883201011','883202511','883202411','883202711','883203511','883209611','883205113','883183011','883206211','883205112','883210311','883168311','883207811','883220811','883209211','883209011','883210111','883210911','883212411','883213811','883213912','883215511','883215711','883216311','883228311','882798911','883078011','883209911','883225011','883260911','883331011','883370611','883139911','883219311','883220611','882802211','883053211','883103111','883153012','883393111','883216211','883361211','883368111','882811211','882994911','883204611','883223811','883245911','883295511','882716511','882720411','882820711','883174911','883230811','882781111','883225211','883275511','883398811','882850511','883242112','882940312','883226211','883227211','883226411','883227911','880488211','883229111','883229011','883253011','883229411','882900512','883227611','883231811','883232411','883232611','883188011','883233511','883233711','883408811','883206011','883234711','883266711','883291611','883409711','883410211','883243111','883241011','883085611','883234011','883242711','883243611','883246911','883128411','883338811','883248111','881651112','882819511','883055211','883057811','883071711','883071111','883077711','883099011','883106211','883110211','883128511','883139411','883160111','883168111','883193511','883198111','883208611','883221911','883231111','883234111','883245111','883250212','883250811','883210511','883252711','883252811','883255611','883256511','883257111','883210011','883258511','883259311','883261111','883261312','883262111','883262811','883289911','883296811','883429411','883206411','883264911','883265311','883265611','883266611','883286611','883254011','883254012','883258211','883271211','883271511','883272411','883273511','883259111','883418511','883275011','883276511','883281811','883283011','883284911','883285311','883286411','883286811','883287211','883287412','883289412','883290412','883291111','883291511','883292111','883292511','883290411','883293811','883294711','883277311');
    $columns = array(
        'Shipment #'=>'EO.shipment_number',
        'Update Status' => new Zend_Db_Expr("(CASE
            WHEN EO.update_status = 1 THEN 'No Update'
            WHEN EO.update_status = 2 THEN 'In Process'
            WHEN EO.update_status = 3 THEN 'Updated'
            WHEN EO.update_status = 4 THEN 'Uploaded'
            WHEN EO.update_status = 5 THEN 'Completed'
            WHEN EO.update_status = 6 THEN 'Failed'
            ELSE 'No Action'
        END)")
    );
    $select = $read->select()
        ->from(array('EO'=>'edicarrier_order'),$columns)
        ->where('EO.shipment_number IN(?)',$array);
    echo $select;
    die();
    $columns = array(
        'order_id' => 'EAL.order_id',
        'increment_id' => 'EAL.po_number',
        'shipment_number' => 'EAL.shipment_number',
        'ryder_status' => new Zend_Db_Expr("GROUP_CONCAT(CONCAT(EAL.event_code,'/',EAL.comment_code))"),
    );

    $select = $read->select()
        ->from(array('EAL'=>'edicarrier_action_log'),$columns)
        // ->join(array('EDF'=>'edicarrier_downloaded_files'),"EAL.file_id=EDF.id",array())
        // ->where('EDF.created_at <= ?', '2020-12-03 12:11:00')
        ->where('EAL.completed_at <= ?', '2020-12-03 12:11:00')
        ->where('COALESCE(EAL.order_id,0) > 0')
        ->where("(EAL.event_code = 'OF' AND EAL.comment_code='NS') OR (EAL.event_code = 'DP' AND EAL.comment_code='OP') OR (EAL.event_code = 'HO' AND EAL.comment_code='ES')")
        ->group('EAL.order_id')
        ->group('EAL.shipment_number');

        echo $select;
    die();
    echo "<h4>1. Prepare a query which will indicate the Ryder has multiple shipment or not. </h4><br>";
    $columns = array(
        'order_id' => 'EO.order_id',
        'increment_id' => 'EO.increment_id',
        'shipment_number' => 'EO.shipment_number',
        'is_multiple_shipment' => new Zend_Db_Expr("IF(COUNT(DISTINCT EAL.ref_number) > 1,'Yes','No')"),
        'Manufacturer Status' => 'SOMS.label',
        'Manufacturer Internal Status' => 'SOMIS.label',
        'Customer Status' => 'SOS.label',
        'Customer Internal Status' => 'SOIS.label',
    //     'ryder_shipping_method' => "EO.shipment_method",
    //     'final_shipping_method' => new Zend_Db_Expr("(CASE
    //                                     WHEN SFOIS.shipping_method = 'free' THEN 'HL'
    //                                     WHEN SFOIS.shipping_method = 'ex_whiteglove' THEN 'HL'
    //                                     WHEN SFOIS.shipping_method = 'free_platinum' THEN 'BW'
    //                                     WHEN SFOIS.shipping_method = 'ex_platinum_whiteglove' THEN 'BW'
    //                                     WHEN SFOIS.shipping_method = 'gold' THEN 'BW'
    //                                     WHEN SFOIS.shipping_method = 'platinum' THEN 'BW'
    //                                     ELSE 'HL'
    //                                 END)"),
    );
    $select = $read->select()
        ->from(array('EO'=>'edicarrier_order'),array())
        ->join(array('CMO'=>'ccc_manufacturer_order'),"EO.order_id=CMO.order_id AND EO.shipment_number=CMO.po_number",array())
        ->joinLeft(array('SOMS'=>'sales_order_manufacturer_status'),"CMO.manufacturer_status=SOMS.manufacturer_status",array())
        ->joinLeft(array('SOMIS'=>'sales_order_manufacturer_internal_status'),"CMO.manufacturer_internal_status=SOMIS.manufacturer_internal_status",array())
        ->joinLeft(array('SOS'=>'sales_order_status'),"CMO.customer_status=SOS.status",array())
        ->joinLeft(array('SOIS'=>'sales_order_internal_status'),"CMO.internal_status=SOIS.internal_status",array())
        // ->join(array('SFOIS'=>'sales_flat_order_item_shipping'),"CMO.order_id=SFOIS.entity_order_id AND CMO.mfr_id=SFOIS.mfr_id AND CMO.shipment_id=SFOIS.shipment_id AND CMO.ship_key_id=SFOIS.ship_key_id",array())
        ->join(array('EAL'=>'edicarrier_action_log'),"EO.order_id=EAL.order_id AND EO.shipment_number=EAL.shipment_number",array())
        ->columns($columns)
        ->where('EAL.event_code IS NOT NULL')
        ->where('EAL.comment_code IS NOT NULL')
        ->group('EO.order_id')
        ->group('EO.shipment_number')
        // ->having('ryder_shipping_method != final_shipping_method')
        ;
    echo $select;
    echo "<br>--------------------------------------------------------------------------------------------------------<br>";
    echo "<br><h4>All Shipment Yes </h4><br>";
    $orderIds = array('8770272','8770942','8770686','8770665','8768846','8771224','8771104','8767988','8769196','8770564','8770675','8770993','8771618','8772036','8770720','8773273','8775360','8773526','8773845','8775417','8776979','8771036','8776894','8776678','8773792','8773609','8770828','8774263','8775173','8777660','8775583','8773925','8772321','8777435','8778153','8778722','8778746','8778868','8775756','8776440','8776688','8776750','8776834','8776908','8774117','8774647','8775065','8775282','8775380','8775404','8775437','8771465','8772123','8772316','8778244','8777530','8778316','8778224','8778074','8779411','8781850','8781946','8782056','8781340','8770769','8780796','8780870','8780455','8780380','8780295','8780124','8779941','8780031','8779806','8779865','8779893','8779719','8779773','8778106','8778233','8779166','8778832','8779525','8776864','8776887','8776950','8777159','8777164','8777361','8777499','8776180','8776230','8776288','8776275','8776394','8776386','8775855','8775962','8775975','8775413','8775416','8775514','8775587','8775662','8775713','8775715','8774857','8775107','8775190','8775279','8775285','8775303','8775312','8773999','8774347','8773295','8773769','8773794','8773813','8773995','8773998','8773058','8773118','8773155','8772545','8772614','8772739','8772764','8772767','8772303','8772774','8771966','8772013','8772341','8771404','8771461','8771463','8771494','8771539','8770997','8771648','8771678','8771715','8771154','8771179','8771220','8770747','8770753','8770821','8770893','8769973','8770049','8770104','8769734','8770552','8769784','8769866','8769888','8768753','8768920','8769013','8769079','8781559','8780590','8780902','8778857','8781585','8781664','8778591','8779533','8779662','8778327','8782637','8781135','8782161','8782467','8782460','8783179','8783252','8783302','8782930','8771519','8782394','8782332','8782771','8782948','8783790','8774494','8783015','8783917','8783007','8769836','8783512','8783128','8784758','8770507','8783367','8784452','8784453','8784709','8768090','8784035','8774006','8784188','8773635','8785887','8784363','8784518','8785745','8785975','8785697','8784674','8785095','8785326','8785755','8785201','8786147','8786853','8785614','8785952','8786955','8786233','8786650','8786924','8787971','8787027','8787566','8787553','8787485','8787999','8787581','8787519','8787501','8787598','8788719','8784683','8787606','8787626','8783023','8784321','8779206','8787711','8787723','8788733','8788123','8787718','8787778','8788114','8786380','8788533','8788846','8787881','8788881','8789617','8771432','8777970','8782708','8786904','8788182','8788369','8788634','8789641','8788895','8789281','8789160','8789204','8782333','8789956','8788970','8788792','8790053','8789446','8790141','8789402','8789426','8789914','8789343','8789355','8788857','8789311','8789489','8789736','8790481','8790074','8790155','8790055','8789485','8785379','8790633','8789385','8790972','8790714','8789743','8791078','8791234','8790034','8791036','8791511','8791111','8791564','8790984','8791193','8791255','8791340','8791219','8790995','8791260','8791800','8791008','8791032','8791701','8791539','8791148','8791765','8792119','8791606','8791616','8791364','8791794','8792150','8791120','8791808','8792320','8791918','8790916','8791303','8792480','8790655','8792500','8792555','8792608','8792666','8792515','8792681','8791087','8792461','8791887','8792549','8790827','8792750','8792462','8792845','8792955','8793002','8793015','8792759','8792828','8791952','8793017','8793053','8793406','8793444','8793338','8793396','8793565','8792771','8793518','8790422','8793329','8793902','8793985','8791373','8784606','8793630','8793013','8794115','8793846','8794248','8794319','8768168','8793968','8794827','8793892','8794098','8794074','8794103','8794232','8795026','8794918','8795087','8795141','8795241','8794809','8794427','8794822','8794641','8795485','8794616','8794657','8794661','8794727','8795255','8795598','8795711','8794801','8795764','8793669','8794769','8794871','8794890','8795944','8770041','8794943','8795003','8795661','8794808','8795937','8791813','8795542','8794592','8795577','8791874','8793008','8796111','8794429','8794888','8795039','8793866','8795232','8794081','8795072','8795343','8795215','8796194','8795336','8795509','8795517','8794139','8795572','8795649','8795681','8795808','8795789','8795868','8796978','8795701','8797213','8795770','8795866','8795930','8795212','8795029','8796113','8796143','8795185','8796166','8795713','8797564','8796583','8797715','8795381','8797733','8796354','8796281','8796803','8797516','8797276','8797569','8797361','8779656','8797944','8797483','8798003','8796841','8789380','8792590','8791439','8796597','8797265','8796556','8798119','8798198','8798759','8798212','8769277','8798097','8798908','8798979','8799057','8798339','8798902','8798392','8799219','8798512','8798344','8794941','8798629','8798652','8799548','8798801','8798881','8798601','8798711','8799220','8798799','8799834','8799060','8798846','8799181','8799223','8799376','8798991','8798959','8800125','8799261','8799945','8799260','8800056','8799565','8798048','8798818','8798916','8799435','8799083','8800188','8779227','8799255','8799656','8799313','8799408','8800540','8800572','8799258','8800143','8788002','8796565','8800682','8799185','8799201','8791578','8798779','8800664','8799356','8800574','8800408','8799452','8799443','8801046','8800593','8798503','8799747','8800063','8800671','8801312','8801347','8801390','8800097','8801550','8799617','8800191','8801238','8800842','8801646','8801691','8800406','8801362','8801520','8801749','8801758','8800296','8801737','8801525','8801746','8801859','8801786','8801962','8801633','8801963','8802057','8802124','8802046','8800887','8801953','8802041','8801965','8800413','8802241','8773442','8801251','8802129','8802456','8802351','8802597','8802520','8802445','8802225','8802239','8802279','8802310','8802357','8802388','8802393','8802377','8802408','8802412','8802425','8802430','8802548','8802497','8802596','8802698','8802888','8802978','8803086','8800801','8802177','8802992','8802518','8802472','8802642','8802877','8792466','8802475','8802432','8797536','8802609','8802484','8803063','8802421','8802486','8801970','8802664','8791785','8803341','8803344','8802571','8802843','8803472','8802591','8802292','8802772','8803467','8802394','8803456','8802692','8802694','8803244','8803699','8802869','8802006','8802526','8802785','8802720','8803895','8802802','8802896','8801745','8802426','8802701','8802970','8803227','8803959','8793096','8798783','8799193','8802773','8803812','8803464','8800162','8800509','8801499','8803434','8796938','8799238','8804034','8802455','8802813','8801664','8801763','8802859','8803466','8803541','8803743','8803996','8802405','8804152','8803639','8803876','8798169','8804024','8803545','8803476','8803885','8803843','8804046','8803404','8804120','8802870','8803760','8803135','8803147','8803863','8803459','8804456','8804309','8804132','8804322','8803502','8803505','8803528','8804524','8803531','8804614','8804232','8791856','8804600','8803569','8803963','8804610','8804009','8804038','8804719','8804741','8804173','8804919','8803756','8804913','8803788','8804746','8803956','8786235','8804522','8804286','8804081','8805072','8805020','8805045','8792253','8805033','8805041','8805269','8805241','8805295','8804422','8797751','8778480','8804096','8804419','8804629','8805018','8805559','8805378','8805499','8805503','8805271','8805120','8805434','8805564','8805646','8805635','8805139','8805633','8805466','8805316','8805324','8805332','8805359','8797343','8805631','8805408','8805477','8776792','8790823','8799810','8802678','8805504','8805514','8805518','8805526','8805626','8806144','8805442','8805480','8805575','8805588','8805612','8799856','8805663','8798173','8799357','8801035','8802866','8806062','8806444','8805787','8805856','8806569','8805383','8805944','8806048','8806068','8806494','8806620','8806103','8805931','8805909','8806153','8805986','8805932','8805936','8806748','8790636','8801070','8804116','8804230','8805802','8806152','8806803','8806086','8806136','8806183','8806462','8805525','8806171','8804147','8805955','8806093','8806037','8806996','8807014','8806989','8806851','8807100','8806864','8806127','8781778','8806140','8806142','8806151','8805181','8807150','8806302','8795505','8806379','8806693','8806588','8806622','8806679','8798150','8806666','8806760','8803679','8790683','8806800','8806815','8807039','8807111','8807112','8807161','8807198','8807217','8807525','8807541','8807566','8807613','8807666','8807678','8808014','8807280','8807308','8807417','8808211','8808130','8808041','8808346','8808352','8807784','8808116','8807834','8807884','8807897','8807870','8807898','8807911','8807932','8808167','8807878','8808419','8807978','8804039','8807341','8807568','8807616','8807976','8807987','8808064','8808265','8808058','8808212','8808255','8808132','8808242','8808217','8790071','8808526','8807544','8808431','8808076','8808619','8808083','8808148','8808150','8809066','8808955','8808090','8806205','8808241','8808529','8809155','8808341','8809244','8808233','8808538','8807197','8808227','8808521','8791125','8806047','8806206','8809340','8809352','8808978','8809359','8809361','8808314','8809370','8808429','8808438','8773519','8809494','8808628','8809343','8808795','8809578','8809604','8808728','8808505','8808996','8809302','8808965','8808944','8809007','8808426','8809730','8801363','8807681','8809729','8808718','8808806','8808825','8808912','8808909','8809780','8809948','8809818','8809959','8809021','8809883','8810020','8809754','8809810','8809068','8809597','8808836','8809885','8777525','8809874','8810122','8810191','8809185','8809210','8809994','8809879','8810227','8810395','8808860','8809768','8810306','8808858','8809412','8809889','8810511','8810274','8808299','8809685','8809744','8809825','8809930','8809854','8810138','8810328','8810346','8810353','8810607','8810877','8810436','8810485','8810553','8810586','8810587','8810581','8810597','8810604','8807888','8810633','8787666','8784105','8790090','8781564','8784820','8785708','8786993','8789132','8772747','8773172','8791119','8794790','8794966','8795207','8795990','8795996','8797108','8797161','8797798','8798633','8800675','8810260','8810630','8810988','8811050','8811124','8775590','8810620','8769136','8769962','8771214','8779975','8781895','8811204','8800120','8808323','8810671','8810686','8806900','8809901','8810680','8810715','8810913','8810219','8810653','8810735','8810689','8810822','8810713','8810696','8810698','8811377','8809487','8810892','8811354','8810721','8810741','8810716','8810821','8810898','8810912','8810752','8808091','8810755','8810771','8810759','8774779','8770320','8776861','8798617','8798695','8810578','8811574','8810844','8810804','8810824','8810957','8810972','8811066','8811135','8811325','8811691','8811159','8811742','8810851','8811456','8811488','8810897','8810928','8811413','8811276','8810902','8810919','8810299','8810921','8811505','8811550','8811835','8811376','8810996','8792952','8811959','8810936','8811930','8811164','8811607','8811656','8807010','8809388','8811601','8811610','8811795','8811972','8811522','8810798','8811555','8811609','8811497','8811862','8811001','8811092','8811906','8811879','8811112','8811625','8811181','8811909','8811933','8811459','8812208','8799290','8798623','8811894','8801086','8812010','8812263','8812134','8811490','8812257','8811878','8812224','8811960','8812289','8812384','8811911','8812190','8812191','8812430','8811606','8775365','8808877','8811971','8812139','8812149','8812254','8811886','8812449','8811700','8811711','8812577','8812342','8811882','8811853','8787682','8812661','8812677','8812461','8812312','8812315','8812598','8812688','8811370','8812281','8812647','8812648','8812298','8812450','8812545','8812645','8811902','8812103','8812655','8812189','8812252','8812258','8812299','8812485','8812878','8812500','8813097','8812595','8812638','8813151','8812832','8812901','8813145','8801280','8812657','8813194','8812813','8813042','8812870','8812999','8812704','8812880','8813026','8812982','8813281','8812970','8813255','8813142','8813242','8813105','8813147','8812830','8813371','8813024','8813045','8812885','8813098','8812906','8812938','8812937','8803738','8813058','8806529','8812799','8813018','8813499','8812631','8812992','8813580','8813002','8813070','8813115','8812979','8812997','8813138','8813660','8813057','8813461','8813278','8813172','8813006','8813309','8813791','8813827','8813314','8813352','8813414','8813839','8803425','8813050','8813417','8813426','8813843','8813089','8813888','8813913','8813745','8770384','8772758','8778162','8813690','8813806','8813869','8813504','8813898','8814012','8813988','8814008','8813924','8814037','8813429','8813848','8813894','8813080','8813909','8813919','8813128','8813286','8814186','8813885','8813419','8813889','8814256','8814241','8814295','8800450','8814299','8814267','8814374','8814312','8813977','8814437','8814291','8814294','8801266','8804783','8814300','8814339','8814529','8813642','8814200','8812601','8814551','8805887','8808943','8813893','8814356','8776497','8814563','8813724','8814668','8813720','8814433','8814579','8811628','8814325','8813809','8814716','8813918','8787764','8814322','8814504','8814547','8814706','8814809','8814831','8814640','8814776','8814898','8814662','8814783','8814966','8815001','8814653','8814846','8815045','8814742','8815075','8814942','8815101','8814223','8814624','8814268','8815149','8814328','8814331','8815141','8815069','8815279','8815287','8814355','8815068','8815301','8815179','8814365','8815074','8815234','8815385','8815379','8815110','8814431','8815442','8815449','8814482','8815111','8814521','8814540','8812840','8814694','8814803','8814830','8815630','8814947','8814984','8815000','8815108','8815146','8815046','8800485','8815619','8815855','8815882','8815211','8815220','8813431','8813450','8815466','8815486','8815928','8815222','8814192','8815244','8815964','8815968','8815994','8815312','8816019','8816037','8815996','8816050','8815339','8815614','8815518','8815622','8815678','8815415','8815632','8815468','8815440','8816165','8815531','8815690','8815559','8815569','8815627','8815602','8815769','8816219','8813046','8815724','8816450','8813306','8815131','8816469','8815473','8815786','8816341','8816441','8812933','8816436','8815351','8815424','8816453','8815852','8816273','8816543','8814453','8814986','8815611','8815781','8816545','8815620','8815722','8812917','8813134','8809891','8815963','8770748','8815601','8815782','8816624','8816637','8816646','8813908','8814182','8815404','8815618','8815738','8816685','8816702','8812662','8812882','8812987','8813005','8813111','8813197','8813256','8813362','8813696','8814733','8815642','8813859','8813921','8814017','8814238','8814317','8814734','8814736','8815817','8815122','8812445','8813168','8813436','8813669','8813776','8814717','8814913','8815029','8815036','8815209','8815463','8815467','8815538','8815155','8815558','8815560','8815736','8815746','8815748','8815743','8815884','8815677','8815707','8815826','8815843','8815848','8815984','8816012','8816153','8816267','8816279','8816285','8816396','8816566','8815699','8816475','8815924','8816804','8816742','8815762','8816845','8815767','8816271','8815906','8816079','8807854','8816218','8816144','8816117','8816898','8816141','8816277','8768746','8816241','8817135','8815972','8816210','8816208','8816231','8816239','8816289','8816292','8816299','8816365','8816440','8816480','8815937','8816033','8784641','8816206','8816960','8816413','8816110','8816483','8817309','8816564','8816633','8816640','8816074','8816653','8816084','8816755','8817367','8817403','8816209','8816274','8816297','8816798','8770659','8786145','8816328','8816724','8816774','8816777','8817115','8817141','8816832','8817040','8817257','8817333','8817608','8817618','8816410','8817779','8817848','8817026','8817346','8817418','8817466','8817468','8817479','8817863','8817786','8816523','8816570','8816599','8816509','8816641','8816729','8816759','8816747','8816824','8813787','8816881','8817702','8817737','8817671','8818107','8805859','8817745','8817874','8817936','8818158','8817628','8817861','8818168','8818193','8817937','8816338','8816642','8816938','8817371','8817860','8818237','8816791','8817013','8817260','8817266','8817775','8817942','8817962','8818004','8818056','8816814','8816864','8816778','8817917','8816684','8817884','8817807','8817171','8818404','8816721','8816962','8817084','8817941','8818032','8816190','8817932','8817316','8817458','8818191','8818367','8817201','8817344','8817413','8817558','8817615','8817818','8818011','8818261','8818307','8818394','8818444','8818007','8817228','8817236','8817340','8817426','8817430','8817441','8812271','8818545','8818634','8818633','8818378','8818448','8818128','8818739','8817519','8817557','8818507','8818546','8818735','8783623','8818466','8817611','8817645','8818316','8817738','8818830','8817970','8818892','8818894','8817791','8817823','8817978','8817858','8816723','8817931','8817940','8817990','8818008','8818041','8818057','8818078','8818085','8819015','8818092','8818220','8818300','8818315','8818341','8818345','8818437','8818478','8818498','8818503','8818524','8818537','8818563','8818643','8818723','8818785','8818906','8818789','8818869','8818917','8819198','8818924','8818598','8818626','8819387','8818641','8819438','8818707','8818518','8819048','8819050','8818721','8818936','8818954','8819047','8794003','8818802','8818915','8818883','8809391','8810510','8810718','8811660','8812233','8813041','8813317','8814283','8814865','8814985','8815250','8815344','8815723','8815774','8816158','8817656','8818076','8819138','8819395','8818916','8818923','8818928','8818953','8819011','8819071','8819089','8819670','8819006','8819575','8819598','8818931','8818484','8818805','8818161','8818654','8818995','8819129','8819655','8819810','8819193','8818687','8818732','8818303','8818320','8818520','8819103','8819161','8819172','8818328','8818990','8819007','8819186','8819919','8819139','8819418','8819434','8819555','8820000','8819371','8819259','8820009','8819178','8819032','8819535','8819543','8819632','8819650','8820025','8819658','8819523','8820101','8819155','8816828','8819110','8819125','8819150','8819607','8819433','8820150','8818571','8779482','8820133','8819184','8820267','8820210','8820406','8819227','8819566','8820107','8820332','8820435','8819502','8820229','8819823','8819353','8819775','8820017','8820122','8820449','8820516','8819427','8819801','8818528','8819066','8819905','8819400','8819749','8820092','8818419','8819755','8819474','8819513','8819547','8819564','8820496','8820646','8819687','8820200','8820280','8820391','8820485','8817073','8820520','8820175','8819643','8818780','8818932','8819834','8820012','8820821','8820087','8820243','8820829','8820278','8820833','8818211','8819361','8819421','8820158','8819720','8819726','8819033','8820268','8820738','8818538','8818555','8818940','8819114','8819256','8819274','8818611','8819636','8819798','8819864','8819958','8819738','8820124','8820281','8820325','8820469','8820647','8820678','8820748','8819822','8819855','8820772','8820320','8819911','8819946','8819955','8821034','8814595','8820007','8820039','8820089','8820102','8820136','8820164','8820159','8820560','8820569','8820547','8820794','8807895','8818907','8819179','8821261','8821271','8820231','8820293','8820305','8820637','8820638','8820875','8820901','8820921','8820926','8820937','8820953','8821035','8821040','8821111','8821281','8821395','8821408','8820982','8818106','8817325','8820376','8816994','8794705','8819995','8820470','8821262','8820509','8820510','8820518','8820057','8820466','8820442','8820620','8820673','8820855','8821350','8821423','8821445','8821481','8821483','8821492','8821508','8821564','8821577','8821578','8821615','8821638','8821646','8819328','8820775','8820813','8820818','8820838','8820889','8820894','8820914','8820862','8820946','8819401','8820987','8821027','8820545','8821112','8821779','8821114','8820048','8821128','8821129','8821156','8821944','8821956','8821173','8821188','8821230','8821235','8821991','8822004','8821273','8821303','8822035','8817270','8821330','8821464','8821517','8821519','8818492','8821552','8821560','8821567','8821671','8821677','8821696','8821658','8821834','8821609','8821749','8821608','8821614','8821625','8821812','8821816','8821951','8822271','8821938','8821722','8821739','8821789','8821784','8821828','8778010','8778843','8790855','8791378','8821850','8821887','8821907','8822368','8822439','8822552','8820884','8820965','8819541','8821811','8821895','8822112','8822587','8814132','8819648','8819680','8820052','8820566','8820701','8820756','8820988','8820990','8821048','8821127','8821209','8821260','8821280','8821287','8821449','8821574','8821595','8821790','8821794','8821824','8821831','8821478','8821630','8821878','8821905','8821906','8821908','8821919','8821923','8821928','8821920','8821934','8821950','8821966','8821968','8773005','8792734','8821867','8821941','8821427','8821983','8822002','8822009','8822011','8822012','8822063','8822073','8822058','8822068','8822081','8822092','8822096','8822134','8822135','8822146','8822193','8822295','8822337','8822390','8822425','8822478','8822608','8822692','8822694','8821980','8822907','8821986','8822007','8822017','8783267','8788858','8795127','8800576','8801322','8770597','8775447','8788814','8797219','8774555','8793532','8799700','8821889','8822151','8822200','8822222','8822243','8822263','8822297','8822324','8816487','8818452','8822104','8822291','8822955','8823142','8822895','8822468','8822401','8823094','8817411','8822318','8821821','8822477','8822486','8822343','8822169','8816948','8819040','8820687','8821939','8822793','8822909','8822615','8821896','8822230','8817759','8822920','8822935','8822268','8820129','8821987','8822285','8822489','8823410','8822314','8822352','8780176','8822369','8822382','8822408','8813445','8776833','8792261','8787897','8822471','8822475','8816801','8816816','8816827','8816853','8816865','8816916','8816978','8816870','8817060','8817163','8817341','8817362','8817374','8816771','8816316','8817399','8817427','8817439','8817444','8815982','8817650','8817697','8817766','8817802','8817806','8817867','8817540','8818243','8818259','8818290','8818319','8818325','8818360','8817119','8818519','8818534','8818566','8822409','8818599','8818659','8818712','8818744','8818790','8818875','8818897','8818898','8818926','8819074','8819072','8819109','8819126','8819134','8819169','8819173','8819182','8819342','8822526','8822572','8822652','8822663','8822671','8822753','8822630','8822890','8822940','8822944','8822931','8822953','8822973','8820536','8823061','8823124','8823131','8823137','8823153','8823205','8823242','8823413','8823435','8819359','8819435','8819539','8815247','8823444','8822841','8823685','8823675','8822589','8822668','8822788','8822804','8822805','8822829','8822836','8822837','8823002','8823003','8819117','8819896','8819917','8820070','8823102','8823415','8823792','8820204','8820260','8820302','8820404','8820444','8819397','8820472','8820473','8820493','8820508','8820590','8820600','8820763','8820897','8821132','8821136','8821306','8821347','8821443','8821486','8821629','8821546','8821672','8821738','8821745','8821832','8821891','8821901','8821914','8821927','8821977','8822025','8822024','8820769','8822116','8822173','8822241','8822356','8822965','8823029','8823089','8823363','8823371','8812868','8815725','8823513','8823737','8823742','8823325','8823384','8823734','8824037','8823249','8821298','8820228','8822780','8822951','8823122','8823603','8823770','8823498','8823515','8823546','8823608','8823471','8824102','8823451','8821936','8823191','8823491','8823535','8824155','8822433','8823417','8823475','8818586','8823043','8822803','8824160','8823018','8822871','8823200','8823300','8824054','8822943','8823966','8823057','8823457','8823650','8823656','8822923','8823080','8823118','8821150','8823347','8823427','8823857','8823931','8824171','8822743','8821534','8823040','8823087','8823354','8823715','8823637','8823306','8823496','8823893','8822102','8823678','8824352','8824349','8824169','8824369','8822364','8823837','8824082','8823186','8823198','8816512','8824118','8824220','8823777','8823821','8823485','8824415','8823865','8823875','8824107','8823768','8823443','8784630','8822300','8822307','8823921','8782651','8823869','8823938','8824047','8824063','8824285','8824399','8824457','8824519','8824606','8824033','8824649','8824296','8824673','8824664','8824693','8824180','8823529','8823528','8824373','8823570','8824275','8823630','8823648','8824237','8820896','8823752','8823841','8823844','8823884','8823852','8823432','8824933','8824041','8824055','8824053','8824186','8822822','8824164','8824292','8824304','8823272','8824333','8824428','8824340','8824416','8824473','8824458','8824467','8825084','8824475','8824479','8824489','8824509','8824510','8824915','8824592','8824686','8824869','8825138','8824518','8824551','8824734','8824794','8824832','8825314','8824286','8824546','8824558','8824881','8824851','8825106','8824583','8824648','8824766','8825415','8804179','8824819','8825421','8825439','8824707','8824723','8824739','8824811','8824763','8824787','8824802','8824805','8824816','8824821','8824826','8824834','8824840','8824844','8824862','8824859','8824874','8824876','8822039','8824959','8824888','8824945','8825031','8825046','8825056','8825525','8824843','8825064','8825667','8825653','8825692','8825738','8825716','8825727','8824450','8824896','8824966','8824856','8824910','8825780','8825772','8824958','8825053','8825062','8824920','8824921','8825803','8824918','8824923','8822008','8824922','8824973','8825076','8825090','8825111','8825210','8825326','8825351','8825356','8825363','8825873','8824974','8825141','8824216','8824976','8824969','8824993','8825011','8825085','8825022','8825068','8823403','8823970','8824831','8825072','8825074','8825086','8825088','8825097','8825100','8825103','8801459','8826096','8779017','8786440','8798635','8801170','8825194','8825449','8824796','8826099','8825164','8824561','8825425','8825019','8825922','8826047','8824465','8824616','8824764','8824573','8824994','8825077','8825311','8826260','8826252','8824902','8825129','8826291','8823357','8824738','8826337','8824801','8824849','8824887','8824900','8825444','8826327','8825154','8825191','8825232','8825339','8825642','8825654','8825791','8825843','8825037','8826344','8826393','8825073','8825458','8825506','8825530','8825685','8826217','8823718','8824956','8825193','8825222','8825767','8825707','8825745','8826439','8824486','8824818','8824979','8825033','8825081','8825118','8825229','8825248','8825065','8825345','8825348','8825437','8824789','8825997','8826028','8823946','8824391','8824470','8824476','8825474','8825475','8825487','8825537','8824886','8825052','8824925','8825039','8825366','8825374','8825769','8826482','8813121','8824492','8825387','8826504','8825276','8825402','8825275','8825443','8818413','8825423','8825431','8821973','8825484','8825489','8825492','8826049','8825515','8794777','8781859','8825453','8825553','8825933','8826074','8826077','8826082','8826110','8826214','8826258','8825751','8826017','8826285','8825890','8826638','8825582','8825911','8826571','8826664','8826066','8825556','8825908','8826021','8826304','8826314','8826323','8826526','8826533','8826775','8825547','8826383','8826539','8826559','8826812','8826828','8825931','8826535','8769409','8826374','8826556','8826896','8817768','8826240','8826547','8826860','8826964','8826984','8826480','8825335','8825888','8825903','8825935','8825957','8827059','8827087','8826033','8788861','8826170','8827173','8824779','8827199','8823601','8824289','8824494','8824612','8824893','8824978','8825140','8825621','8825801','8826023','8826606','8825563','8825651','8826031','8826619','8776389','8826763','8827238','8827050','8826900','8827005','8827041','8827092','8827300','8825787','8826568','8826113','8826101','8827078','8827332','8827025','8826997','8826916','8827039','8827310','8827346','8827352','8826303','8827063','8826273','8826926','8826960','8826975','8826995','8826517','8826985','8827027','8826235','8826728','8827431','8825472','8826026','8826511','8827467','8826060','8827051','8827241','8826777','8826051','8827369','8825307','8827455','8826348','8826403','8826413','8826477','8826530','8825032','8826563','8787102','8795835','8787245','8797995','8827465','8827222','8827483','8827610','8827553','8827412','8827579','8824782','8827750','8826509','8827195','8827769','8826655','8827784','8825764','8779549','8826798','8826804','8826847','8827735','8826871','8826577','8826187','8826917','8826966','8827009','8827011','8827074','8827088','8827980','8827128','8827129','8827963','8828016','8825480','8827970','8828053','8827246','8827249','8827345','8827347','8827416','8827478','8827415','8827434','8827487','8827489','8827497','8827512','8827514','8827510','8827526','8827532','8827537','8827545','8827544','8827554','8827559','8827560','8827572','8827577','8827652','8828225','8828239','8827432','8827722','8828221','8828271','8827843','8824295','8828170','8828262','8828236','8828285','8827621','8828324','8827629','8828396','8827696','8827764','8828416','8827663','8828438','8827674','8828462','8827807','8828433','8827721','8827746','8827860','8827871','8827888','8828522','8827739','8828508','8828173','8827865','8827776','8828520','8828572','8827840','8827891','8827855','8827822','8827872','8827885','8827893','8827903','8827908','8828640','8828676','8828701','8828712','8827798','8828724','8826037','8827962','8828546','8827924','8827575','8828037','8827928','8827940','8828798','8828818','8828820','8827939','8827978','8827981','8828829','8828890','8827949','8828139','8796543','8827842','8828075','8828125','8828146','8827975','8828060','8828954','8828925','8828725','8827991','8828175','8828184','8828232','8828245','8828255','8828217','8828089','8828158','8829002','8829041','8829044','8828015','8828077','8829050','8828049','8828156','8828044','8828178','8828052','8829101','8828061','8828067','8828070','8828079','8828095','8828106','8828114','8828159','8828177','8828180','8828183','8828187','8828191','8828192','8792191','8827791','8828204','8828198','8829206','8829037','8829292','8828601','8829320','8828205','8828227','8828235','8829233','8828576','8829356','8828260','8828354','8828454','8828480','8818826','8828662','8828216','8828617','8828223','8828228','8828229','8828530','8828726','8826446','8828671','8829136','8829458','8829444','8828273','8828259','8828281','8829144','8827642','8828346','8828828','8829535','8828338','8828345','8828303','8828339','8828578','8828083','8828957','8828406','8828415','8829058','8828492','8828597','8829673','8828543','8828577','8828582','8828585','8828610','8828399','8828624','8828625','8828647','8829569','8791249','8828658','8828663','8828775','8829214','8828834','8829868','8827637','8827945','8828018','8828565','8828571','8828651','8828715','8828827','8828849','8828898','8829757','8826988','8827002','8829067','8829883','8829888','8829919','8829916','8827008','8828716','8828825','8828835','8829904','8829872','8829936','8828752','8829502','8824829','8829090','8828073','8828085','8828562','8828686','8828814','8828879','8829183','8829346','8826422','8825029','8826501','8826817','8827033','8827401','8827453','8819094','8824836','8826661','8826839','8826867','8827340','8828224','8828442','8828919','8829240','8829731','8828086','8828887','8825145','8827802','8827825','8828390','8828453','8828593','8828755','8828768','8829113','8829617','8829922','8827446','8827666','8827668','8828771','8829671','8830004','8829991','8830052','8830053','8830066','8830070','8830089','8827987','8828035','8828121','8828124','8828137','8828130','8828403','8828423','8828588','8829137','8829296','8829398','8829683','8829749','8829951','8830002','8827858','8829187','8829309','8829461','8829893','8829989','8829636','8829713','8829875','8828846','8829115','8829128','8829213','8829323','8829354','8829404','8830118','8829061','8830138','8828733','8828867','8829556','8828870','8829877','8829986','8830146','8829238','8829390','8829358','8829644','8828990','8828922','8829274','8829498','8829524','8823239','8828967','8828984','8829224','8829723','8829068','8829098','8829119','8829123','8829129','8829149','8829520','8829179','8829678','8829725','8829985','8830094','8830194','8830212','8830215','8830241','8830255','8830324','8830326','8830327','8830366','8830361','8830151','8829186','8830401','8829188','8830227','8830248','8829201','8828213','8830202','8828736','8790052','8829267','8829278','8830491','8829366','8829375','8830489','8829394','8830220','8829347','8829641','8829251','8830098','8829490','8829522','8829534','8829537','8829631','8830562','8830570','8829643','8829604','8829630','8829602','8829667','8829681','8829709','8829729','8827582','8830017','8829810','8829750','8830672','8830675','8811899','8830767','8829685','8829972','8830787','8830781','8830794','8826499','8829549','8829561','8830329','8829885','8830865','8830967','8827311','8829876','8831127','8831116','8829728','8829969','8830027','8831000','8830039','8829600','8830208','8830230','8830238','8831419','8831311','8830313','8830316','8830319','8830886','8828992','8829244','8830358','8830374','8830397','8831499','8830410','8830429','8830907','8830351','8830440','8830444','8830448','8830453','8831638','8830459','8831647','8830471','8830475','8830560','8831666','8830480','8830481','8830484','8830488','8830492','8830501','8830507','8830519','8830523','8830514','8830528','8830534','8830545','8830470','8830551','8830568','8830573','8830577','8830581','8830580','8830584','8830587','8830599','8830598','8830600','8830609','8830610','8830613','8830615','8830617','8831788','8830604','8830656','8830770','8830619','8830621','8830623','8831816','8830630','8831835','8830634','8830632','8830642','8830645','8830653','8831862','8830658','8830661','8830662','8830668','8830671','8831894','8830714','8830190','8830730','8830732','8830737','8830734','8830744','8830844','8830861','8830956','8830982','8830755','8830760','8830764','8830772','8830765','8830785','8830791','8830799','8830823','8830839','8830850','8825569','8825982','8826729','8826780','8827927','8828011','8828330','8829873','8830280','8830869','8832038','8830864','8831176','8831589','8830920','8830948','8830951','8830978','8830998','8831003','8831004','8831005','8831008','8831091','8831090','8831142','8831165','8831177','8831048','8831068','8831099','8831101','8831107','8831106','8831110','8831114','8831120','8831130','8831135','8831138','8831146','8831149','8831151','8831152','8831155','8831173','8831171','8831178','8831175','8831158','8831189','8831192','8831195','8831164','8831238','8832016','8832019','8831208','8831243','8831263','8831273','8831219','8831226','8831220','8831234','8831235','8831244','8831249','8831250','8831256','8831239','8831260','8831271','8831277','8831080','8831279','8831286','8831289','8831296','8831294','8831303','8831306','8831290','8831313','8831315','8831318','8831323','8831339','8832235','8829598','8831765','8832007','8831357','8827805','8829172','8829721','8830135','8830677','8830972','8790927','8826522','8831366','8831370','8831395','8831413','8831423','8831583','8832418','8832474','8831401','8830999','8831410','8831417','8831416','8831424','8831438','8832640','8830997','8831469','8831554','8831454','8831457','8832643','8831459','8831464','8831468','8832694','8822792','8822800','8823800','8823953','8824021','8824741','8825089','8825233','8827057','8827061','8827824','8828537','8831430','8832529','8828744','8829755','8830016','8830259','8831483','8831490','8831493','8831496','8831501','8831503','8832754','8831513','8831515','8832045','8832160','8832280','8832804','8831525','8832823','8832536','8832576','8832602','8832791','8832837','8832843','8830427','8831564','8831565','8831567','8831572','8831624','8831670','8831519','8831579','8831581','8831607','8831620','8831637','8831640','8831093','8831654','8831657','8831658','8832662','8831669','8831671','8831677','8831678','8831686','8831689','8831691','8831696','8831704','8831702','8831707','8831709','8831720','8831721','8831724','8831727','8831729','8814219','8831734','8831736','8832929','8832095','8831253','8832407','8832776','8831062','8832116','8831722','8831762','8831783','8830536','8831793','8831808','8831246','8833205','8831846','8831779','8831780','8833278','8833324','8831108','8831229','8831292','8831794','8831799','8831790','8832209','8832695','8833382','8825250','8831030','8831262','8831673','8831674','8832890','8831420','8831612','8831819','8831820','8831825','8831827','8831092','8831797','8831842','8831852','8832841','8831265','8831861','8831860','8831828','8831832','8831869','8833372','8833561','8831343','8831728','8831645','8831892','8831891','8831900','8831977','8833603','8831976','8831992','8833224','8831929','8831932','8831938','8831945','8832075','8831942','8831839','8832080','8831951','8831963','8831973','8832924','8832699','8813874','8831986','8831987','8831937','8832070','8832085','8831996','8832005','8832010','8832025','8832024','8832027','8832035','8832044','8832096','8832051','8831830','8832062','8832103','8831683','8832078','8832208','8832092','8832090','8832101','8832109','8832124','8832138','8832139','8832142','8832155','8832157','8832163','8832207','8832283','8832307','8827989','8830780','8832099','8832250','8832609','8833310','8833706','8831399','8832193','8832206','8828022','8830532','8831031','8831530','8833931','8832162','8832945','8833612','8833681','8828112','8829949','8832046','8832238','8832459','8832955','8827165','8827204','8828207','8831749','8832308','8827811','8832252','8832755','8833988','8828505','8832421','8829403','8832262','8832272','8832264','8832279','8804882','8832291','8832290','8832530','8829607','8832294','8828743','8829005','8832276','8832318','8832324','8832326','8831880','8832335','8832337','8834088','8832060','8832347','8832667','8832916','8834097','8834102','8832431','8832410','8830856','8832340','8832427','8832436','8832449','8832469','8831284','8833388','8832481','8816511','8828195','8830552','8830578','8830717','8830711','8830777','8830943','8830990','8831102','8831285','8831394','8831601','8831681','8831935','8831981','8832086','8832219','8832311','8832341','8832451','8832496','8832502','8832508','8832105','8832527','8832528','8832535','8832556','8832565','8832571','8832100','8832585','8832593','8832611','8832613','8832621','8832628','8832899','8832968','8833240','8834294','8832064','8832649','8832653','8832656','8832666','8832677','8832866','8832540','8832582','8832708','8832712','8832715','8832724','8832735','8832591','8834185','8832750','8832765','8832800','8832818','8832830','8832826','8832836','8832846','8832849','8832853','8832856','8832860','8832864','8832868','8832872','8832874','8832884','8832894','8832904','8832911','8832915','8832921','8832925','8832936','8832938','8832947','8832954','8832773');
    $actionLogId = $read->select()
        ->from(array('at_actionLog'=>'edicarrier_action_log'),array('id' => new Zend_Db_Expr("MAX(at_actionLog.id)")))
        ->where('COALESCE(at_actionLog.parent_ref_number,0) = 0')
        ->where('at_actionLog.event_code IS NOT NULL')
        ->where('at_actionLog.comment_code IS NOT NULL')
        ->where('COALESCE(at_actionLog.order_id,0) > 0')
        // ->where('at_actionLog.po_number IN(?)',$orderIds)
        ->group('at_actionLog.order_id')
        ->group('at_actionLog.shipment_number');

    $columns = array(
        'increment_id' => 'EAL.po_number',
        'shipment_number' => 'EAL.shipment_number',
        'event_code' => 'EAL.event_code',
        'comment_code' => 'EAL.comment_code',
        'view_all_shipment_yes' => new Zend_Db_Expr("CONCAT(EAL.event_code,'-',EAL.comment_code)"),
        'ry_code_definition' => 'ESE.ry_code_definition',
        'ry_comment_definition' => 'ESE.ry_comment_definition',
        'defination' => 'ESE.defination',
    );
    $actionLog = $read->select()
        ->from(array('EAL'=>'edicarrier_action_log'),$columns)
        ->join(array('ESE'=>'edicarrier_shipment_events'),"EAL.event_code=ESE.dl_code AND EAL.comment_code=ESE.dl_comment",array())
        ->where("EAL.id IN({$actionLogId})");
    echo $actionLog;
    echo "<br>--------------------------------------------------------------------------------------------------------<br>";
    echo "<br><h4>All Shipment No</h4><br>";
    $actionLogId1 = $read->select()
        ->from(array('at_actionLog1'=>'edicarrier_action_log'),array('id' => new Zend_Db_Expr("MAX(at_actionLog1.id)")))
        ->where('at_actionLog1.can_show = ?',1)
        ->where('at_actionLog1.shipment_type != ?',Furnique_Edi_Model_Edicarrier_Action_Log::SHIPMENT_TYPE_HPIK)
        ->where('COALESCE(at_actionLog1.display_ref_number,0) = 0')
        ->where('at_actionLog1.event_code IS NOT NULL')
        ->where('at_actionLog1.comment_code IS NOT NULL')
        ->where('COALESCE(at_actionLog1.order_id,0) > 0')
        // ->where('at_actionLog1.po_number IN(?)',$orderIds)
        ->group('at_actionLog1.order_id')
        ->group('at_actionLog1.shipment_number');

    $columns = array(
        'increment_id' => 'EAL.po_number',
        'shipment_number' => 'EAL.shipment_number',
        'event_code' => 'EAL.event_code',
        'comment_code' => 'EAL.comment_code',
        'view_all_shipment_no' => new Zend_Db_Expr("CONCAT(EAL.event_code,'-',EAL.comment_code)"),
        'ry_code_definition' => 'ESE.ry_code_definition',
        'ry_comment_definition' => 'ESE.ry_comment_definition',
        'defination' => 'ESE.defination',
    );
    $actionLog1 = $read->select()
        ->from(array('EAL'=>'edicarrier_action_log'),$columns)
        ->join(array('ESE'=>'edicarrier_shipment_events'),"EAL.event_code=ESE.dl_code AND EAL.comment_code=ESE.dl_comment",array())
        ->where("EAL.id IN({$actionLogId1})");
    echo $actionLog1;
    echo "<br>--------------------------------------------------------------------------------------------------------<br>";
    echo "<h4>5. Add flag if we have already sent the Update request recently after 2nd Dec </h4>";
    $select = $read->select()
        ->from(array('EDF'=>'edicarrier_downloaded_files'),array('order_id','po_number','shipment_number','created_at'))
        ->where('EDF.action_type = ?','04')
        // ->where('EDF.created_at >= ?',Mage::getModel('core/date')->gmtdate('Y-m-d H:i:s','2020-12-03 17:41:00'))
        ->where('EDF.created_at >= ?', '2020-12-03 12:11:00') //online gmt converted
        ;
    echo $select;
    echo "<br>--------------------------------------------------------------------------------------------------------<br>";
    echo "<h4>6. Ryder Status </h4>";
    $columns = array(
        'order_id' => 'EAL.order_id',
        'increment_id' => 'EAL.po_number',
        'shipment_number' => 'EAL.shipment_number',
        'ryder_status' => new Zend_Db_Expr("GROUP_CONCAT(CONCAT(EAL.event_code,'\','EAL.comment_code'))"),
    );
    $select = $read->select()
        ->from(array('EAL'=>'edicarrier_action_log'),$columns)
        ->where('COALESCE(EAL.order_id,0) > 0')
        ->where('EAL.event_code IS NOT NULL')
        ->where('EAL.comment_code IS NOT NULL')
        ->group('EAL.order_id')
        ->group('EAL.shipment_number');
    echo $select;
    die();
    $columns = array(
        'increment_id' => 'EO.increment_id',
        'order_id' => 'EO.order_id',
        'shipment_number' => 'EO.shipment_number',
        'Manufacturer Status' => 'SOMS.label',
        'Manufacturer Internal Status' => 'SOMIS.label',
        'Customer Status' => 'SOS.label',
        'Customer Internal Status' => 'SOIS.label',
        'Shipping Description' => 'SFOIS.shipping_description',
        'Is Cancelled' => new Zend_Db_Expr("IF(EO.cancel_status >= 3,'Yes','No')"),
        'ryder_shipping_method' => new Zend_Db_Expr("IF(EO.shipment_method = 'WD','BW',EO.shipment_method)"),
        'final_shipping_method' => new Zend_Db_Expr("(CASE
                                        WHEN SFOIS.shipping_method = 'free' THEN 'HL'
                                        WHEN SFOIS.shipping_method = 'ex_whiteglove' THEN 'HL'
                                        WHEN SFOIS.shipping_method = 'free_platinum' THEN 'BW'
                                        WHEN SFOIS.shipping_method = 'ex_platinum_whiteglove' THEN 'BW'
                                        WHEN SFOIS.shipping_method = 'gold' THEN 'BW'
                                        WHEN SFOIS.shipping_method = 'platinum' THEN 'BW'
                                        ELSE 'HL'
                                    END)"),
        // 'View All Shipment Yes' => new Zend_Db_Expr("CONCAT(at_action1.event_code,'-',at_action1.comment_code)"),
        // 'View All Shipment No' => new Zend_Db_Expr("CONCAT(at_action.event_code,'-',at_action.comment_code)"),
    );
    $select = $read->select()
        ->from(array('EO'=>'edicarrier_order'),$columns)
        ->join(array('SFO'=>'sales_flat_order'),"EO.order_id=SFO.entity_id",array())
        ->join(array('CMO'=>'ccc_manufacturer_order'),"EO.order_id=CMO.order_id AND EO.shipment_number=CMO.po_number",array())
        ->join(array('SFOIS'=>'sales_flat_order_item_shipping'),"CMO.order_id=SFOIS.entity_order_id AND CMO.mfr_id=SFOIS.mfr_id AND CMO.shipment_id=SFOIS.shipment_id AND CMO.ship_key_id=SFOIS.ship_key_id",array())
        ->joinLeft(array('SOMS'=>'sales_order_manufacturer_status'),"CMO.manufacturer_status=SOMS.manufacturer_status",array())
        ->joinLeft(array('SOMIS'=>'sales_order_manufacturer_internal_status'),"CMO.manufacturer_internal_status=SOMIS.manufacturer_internal_status",array())
        ->joinLeft(array('SOS'=>'sales_order_status'),"CMO.customer_status=SOS.status",array())
        ->joinLeft(array('SOIS'=>'sales_order_internal_status'),"CMO.internal_status=SOIS.internal_status",array())
        // ->joinLeft(array('at_action'=>new Zend_Db_Expr("({$actionLog})")),"EO.order_id=at_action.order_id AND EO.shipment_number=at_action.shipment_number",array())
        // ->joinLeft(array('at_action1'=>new Zend_Db_Expr("({$actionLog1})")),"EO.order_id=at_action1.order_id AND EO.shipment_number=at_action1.shipment_number",array())
        ->where('EO.shipment_method IS NOT NULL')
        ->where('EO.increment_id IN(?)',$orderIds)
        ->where("DATE(SFO.created_at) >= ?",'2020-07-22')
        ->having('ryder_shipping_method != final_shipping_method')
        ;
    echo $select;
    die();
    $ediOrderCollection = Mage::getModel('edi/edicarrier_order')->getCollection();
    $ediOrderCollection->getSelect()
        ->where('main_table.shipment_method IS NULL')
        ->where('main_table.shipment_number = ?','8754222-S1')
        // ->limit(500)
        ;
    foreach ($ediOrderCollection as $ediOrder) {
        $downloadCollection = Mage::getModel('edi/edicarrier_downloaded_files')->getCollection();
        $downloadCollection->getSelect()
            ->where('main_table.file_type =?',204)
            ->where('main_table.shipment_number =?',$ediOrder->getShipmentNumber())
            ->where('main_table.order_id =?',$ediOrder->getOrderId())
            ->order('main_table.id DESC');
        $download = $downloadCollection->getFirstItem();
        if ($download->getId()) {
            $io = new Varien_Io_File();
            if ($io->fileExists(Mage::getBaseDir().$download->getFilePath().$download->getFileName())) {
                $io->open(array('path' => Mage::getBaseDir().$download->getFilePath()));
                $fileContent = $io->read($download->getFileName());
                $fileContent = explode(PHP_EOL, $fileContent);
                if (isset($fileContent[7]) && $fileContent[7]) {
                    $row = explode('*', $fileContent[7]);
                    if (isset($row[1]) && $row[1] && ($row[1] == 'BW' || $row[1] == 'HL')) {
                        $ediOrder->setId($ediOrder->getId())
                            ->setShipmentMethod($row[1])
                            ->save();
                    }
                }

            }
        }
    }
    die();
    echo Mage::getModel('multishipping/order_item_shipping')->getCollection()->getSelect();die();
    // ->where("main_table.internal_status NOT IN(?)", $this->getNotAllowInternalStatus())
    $wgIds = explode(',', Mage::getStoreConfig('ediryder/general/ship_carrier'));
    $columns = array(
        'Order #' => 'CMO.increment_id',
        'manufacturer_status' => 'CMO.manufacturer_status',
        'manufacturer_internal_status' => 'CMO.manufacturer_internal_status',
        'customer_status' => 'CMO.customer_status',
        'internal_status' => 'CMO.internal_status',
        'Order Status' => 'SFO.status',
        'Order internal Status' => 'SFO.internal_status',
        'IS Express' => 'CMO.is_express',
        'Created At' => 'SFO.created_at',
    );
    $select = $read->select()
        ->from(array('CMO'=>'ccc_manufacturer_order'),array())
        ->join(array('SFO' => 'sales_flat_order'), "CMO.order_id=SFO.entity_id", array())
        ->join(array('SCO' => 'ccc_ship_carrier_order'), "CMO.order_id = SCO.order_entity_id AND CMO.mfr_id = SCO.mfr_id AND CMO.shipment_id = SCO.shipment_id AND CMO.ship_key_id = SCO.ship_key_id", array())
        ->joinLeft(array('ECO' => 'edicarrier_order'), "CMO.order_id = ECO.order_id AND CMO.po_number=ECO.shipment_number", array())
        ->columns($columns)
        ->where("ECO.shipment_number IS NULL")
        ->where("SCO.wg_id IN(?)", $wgIds)
        ->where("CMO.customer_status NOT IN(?)", array('complete','arrived'))
        ;
    echo $select;
    die;
    echo Mage::getModel('trackingapi/item')->getCollection()->getMainTable();die();
    $replacement = $read->select()
        ->from(array('SFOIPR'=>'sales_flat_order_item_parts_replacement'),array('order_id','mfr_id','shipment_id','ship_key_id'))
        ->where('SFOIPR.carrier_selection = 1')
        ->group('SFOIPR.order_id')
        ->group('SFOIPR.mfr_id')
        ->group('SFOIPR.ship_key_id')
        ->group('SFOIPR.shipment_id');

    $columns = array(
        'order_id' => 'CMO.order_id',
        'increment_id' => 'CMO.increment_id',
        'mfr_id' => 'CMO.mfr_id',
        'shipment_id' => 'CMO.shipment_id',
        'ship_key_id' => 'CMO.ship_key_id',
        'shipment_number' => 'CMO.po_number',
        'parent_shipment_number' => 'CMO.reference_po_number',
        'delivery_group_number' => 'EO.delivery_group_number',
        'parent_delivery_group_number' => 'REO.delivery_group_number',
    );
    $select = $read->select()
        ->from(array('at_replacement'=> new Zend_Db_Expr("({$replacement})")),array())
        ->join(array('CMO' => 'ccc_manufacturer_order'),"at_replacement.order_id=CMO.order_id AND at_replacement.mfr_id=CMO.mfr_id AND at_replacement.shipment_id=CMO.shipment_id AND at_replacement.ship_key_id=CMO.ship_key_id",array())
        ->join(array('EO'=>'edicarrier_order'),"CMO.order_id=EO.order_id AND CMO.po_number=EO.shipment_number",array())
        ->join(array('REO'=>'edicarrier_order'),"CMO.order_id=REO.order_id AND CMO.reference_po_number=REO.shipment_number",array())
        ->columns($columns)
        ->where('CMO.reference_po_number IS NOT NULL')
        ->where('EO.delivery_group_number != REO.delivery_group_number');
    echo $select;
    die();
    $shipmentNumbers = array('8703726-S1','8778720-S2','8794980-S3','8806950-S1','872097911','8742190-S1','8717308-S1','8738784-S1','8761949-S4','8765172-S1','874474311','880753911','8695802-S1','8633663-S5','8715746-S1','8775136-S3','874730511','8794980-S3','8738817-S1','8788470-S4','8716676-S1','8756260-S1','8794980-S3','8673975-S1','8794980-S3','874305411','8717108-R1','874323811','874009611','8790152-S2','8798718-S1','8794980-S3','8689268-S1','8797053-S1','880755311','874307811','8700966-S2','880730511','874423311','8794980-S3','8771467-S1','8749862-S1','8806792-S1','8693119-S1','8764527-S4','8714321-S1','870023011','8794980-S3','8736296-S1','8759044-S2','8806080-S1','870688611','8732807-S1','874409911','8790152-S1','8779989-S1','8768121-S3','8794980-S3','874004411','874398311','8782314-S1','8769830-S1','8711844-S1','8794980-S3','880739411','8715412-S1','8693228-S1','8673803-S1','8772316-S6','8737014-S1','8714952-S1','8794980-S3','8794980-S3','880694011','8760276-S1','8805599-S1','8730832-S2','8794980-S3','8772400-S5','8794980-S3','8748651-S1','8747191-S2','8749723-S1','875438411','8795381-S1','8653681-S1','8713546-S1','8743117-S1','875343011','8711409-S1','8733853-S1','8794980-S3','8807176-S1','8777677-S1','8784467-S1','8735485-S1','874051211','8806218-S1','8775401-S2','8803990-S1','872031111','8761949-S4','8724259-S2','8757672-S2','880724411','875394411','8807144-S1','8738591-S1','873656911','875307111','8685463-S1','8772316-S3','8709143-S1','8794980-S3','8798446-S1','868990811','8806041-S1','8766370-S1','8740689-S1','8806931-S1','8781376-S1','8783348-S1','8807152-S1','880720311','8730095-S1','8805782-S1','880743011','8703288-S1','880720411','8794980-S3','8799707-S1','8712542-S1','8707999-S1','8721421-S1','8775308-S2','8794626-S1','8794980-S3','8656349-S1','8806707-S1','8748526-S1','8794980-S3','8703623-S1','8734533-S1','8805188-S1','880720611','8711411-S1','8806779-S1','8761949-S4','8794980-S3','8716045-S1','8780406-S1','8713226-S1','8789502-S1','8776294-S1','875555011','8629672-S1','874109211','875123111','8715544-S1','871152511','874258911','8765164-S1','8775136-S3','8716723-S1','8717337-S1','8711750-S1','8807096-S1','8742084-S1','8775401-S2','8794980-S3','8710160-S1','8794980-S3','8733040-S1','8767319-S1','880754911','8765029-S1','8743623-S1','8648221-S1','8794980-S3','8801862-S1','879111113','8778800-S1','8726123-S6','8794980-S3','8711443-S1','869025611','8668963-S2','8749640-S1','873481611','8713907-S2','8765202-S2','880747011','875638411','8748322-S1','880739811','8746188-S1','8717577-S1','8716050-S1','8740212-S2','8794980-S3','8806973-S1','880720211','869317211','8708673-S1','8733730-S1','8794980-S3','8806028-S1','8760352-S1','8709650-S1','8740208-S1','8738652-S1','875322511','8750449-S1','8794980-S3','8767978-S1','874118811','8710783-S1','8711525-S1','8806325-S1','8748825-S1','8775401-S2','8705370-S1','8749664-S2','880759811','8735340-S1','8793622-S1','8794980-S3','874396211','877688882','8806972-S1','8807165-S1','8739101-S1','8710495-S1','8705370-S1','8775418-S1','8738141-S1','8727174-S2','8739921-S1','8802655-S1','8794980-S3','8782314-S2','880757411','8775615-S1','8749714-S1','8764947-S2','8705070-S1','8807160-S1','8759970-S1','874106711','8801280-S1','8775136-S1','8753809-S2','872842311','8759988-S1','880746511','8748102-S1','8709468-S1','8784604-S3','880720811','8649316-S1','8675777-R1','874398111','872044381','8714855-S1','8693256-S1','8772400-S1','874027011','8734181-S1','874079911','8806960-S1','874730511','873069911','8698364-S4','8707079-S1','8675795-S1','8794980-S3','8769562-S5','8763993-S2','8794980-S3','8807166-S1','8788470-S2','8710642-S1','8772316-S3','8786094-S2','8740091-S1','8772316-S6','880723111','8765703-S1','875409911','8750449-S1','875473911','8781198-S1','8749847-S1','8772400-S3','8708258-S1','880727211','874438111','881354411','8710433-S1','8794980-S3','8737830-S1','8806496-S1','8708232-S1','8736920-S1','8776760-S1','8703580-S1','8767984-S5','874846411','8794980-S3','8753087-S1','8794980-S3','8797362-S2','8689642-S1','875380911','8794980-S3','880747611','8697515-S2','8794980-S3','8776869-S1','8794980-S3','8695999-S1','8737310-S1','8711564-S1','8749664-S1','874190411','8800916-S1','8794980-S3','874892211','8806872-S1','880474881','8794980-S3','8708156-S1','8754252-S1','8794980-S3','8794980-S3','8794980-S3','873372311','8803376-S1','8790849-S1','8806154-S1','8794980-S3','8794980-S3','881010011','874507111','8706936-S1','8775136-S1','875515011','8794043-S1','8795941-S1','8690385-S1','8773836-S2','8800082-S1','8772400-S2','874412511','8728049-S1','8794980-S3','8711764-S1','8771611-S3','8794980-S3','8794980-S3','875218811','8786897-S2','8794980-S3','876984451','867819611','874172811','8806487-S1','8709038-S1','8794980-S3','874107511','8734500-S1','8794980-S3','8794980-S3','8794980-S3','8794980-S3','880718911','8794980-S3','880752411','8716474-S1','8794980-S3','874326511','8773836-S2','8682061-S1','875140011','8735488-S1','8806000-S1','8794980-S3','8794980-S3','873789082','8690140-S1','8703153-S1','8716435-S1','8789077-S1','880747211','8710728-S1','8677170-S1','8742954-S1','8794759-S1','8717333-S1','8716146-S1','8781148-S1','8794980-S3','868212011','878768211','867639011','8794980-S3','874085211','8800513-S1','875380911','879237611','8793978-S1','879111111','8805293-S1','8725162-R1','872804911','874972011');
    $select = $read->select()
        ->from(array('EAL'=>'edicarrier_action_log'),array('shipment_number','event_code' => new Zend_Db_Expr("IF(FIND_IN_SET('CA',GROUP_CONCAT(EAL.event_code)),1,0)")))
        ->where('EAL.shipment_number IN(?)',$shipmentNumbers)
        // ->where('EAL.event_code = ?','CA')
        // ->where('EAL.comment_code IN(?)',array('TF','CL','CC','CB','CA','C9','C8','C7','C6','DO','D6','D5','LI','RE','TR'))
        ->group('EAL.shipment_number');
    echo $select;
    die();
    $columns =array(
        'order_id' => 'EO.order_id',
        'increment_id' => 'EO.increment_id',
        'shipment_number' => 'EO.shipment_number',
        'manufacturer_status' => 'CMO.manufacturer_status',
        'manufacturer_internal_status' => 'CMO.manufacturer_internal_status',
        'internal_status' => 'CMO.internal_status',
        'customer_status' => 'CMO.customer_status',
        'is_cancelled' => "IF(EO.cancel_status >= 3,'Yes','No')",
        'is_shipment_missing' => "IF(CMO.po_number IS NULL,'Yes','No')",
        'status' => "IF(CMO.manufacturer_status = 'cancelled' OR CMO.manufacturer_internal_status = 'cancelled' OR CMO.internal_status = 'cancelled' OR CMO.customer_status = 'cancelled','cancelled',CMO.customer_status)",
    );
    $shipmentNumbers = array('8703726-S1','8778720-S2','8794980-S3','8806950-S1','872097911','8742190-S1','8717308-S1','8738784-S1','8761949-S4','8765172-S1','874474311','880753911','8695802-S1','8633663-S5','8715746-S1','8775136-S3','874730511','8794980-S3','8738817-S1','8788470-S4','8716676-S1','8756260-S1','8794980-S3','8673975-S1','8794980-S3','874305411','8717108-R1','874323811','874009611','8790152-S2','8798718-S1','8794980-S3','8689268-S1','8797053-S1','880755311','874307811','8700966-S2','880730511','874423311','8794980-S3','8771467-S1','8749862-S1','8806792-S1','8693119-S1','8764527-S4','8714321-S1','870023011','8794980-S3','8736296-S1','8759044-S2','8806080-S1','870688611','8732807-S1','874409911','8790152-S1','8779989-S1','8768121-S3','8794980-S3','874004411','874398311','8782314-S1','8769830-S1','8711844-S1','8794980-S3','880739411','8715412-S1','8693228-S1','8673803-S1','8772316-S6','8737014-S1','8714952-S1','8794980-S3','8794980-S3','880694011','8760276-S1','8805599-S1','8730832-S2','8794980-S3','8772400-S5','8794980-S3','8748651-S1','8747191-S2','8749723-S1','875438411','8795381-S1','8653681-S1','8713546-S1','8743117-S1','875343011','8711409-S1','8733853-S1','8794980-S3','8807176-S1','8777677-S1','8784467-S1','8735485-S1','874051211','8806218-S1','8775401-S2','8803990-S1','872031111','8761949-S4','8724259-S2','8757672-S2','880724411','875394411','8807144-S1','8738591-S1','873656911','875307111','8685463-S1','8772316-S3','8709143-S1','8794980-S3','8798446-S1','868990811','8806041-S1','8766370-S1','8740689-S1','8806931-S1','8781376-S1','8783348-S1','8807152-S1','880720311','8730095-S1','8805782-S1','880743011','8703288-S1','880720411','8794980-S3','8799707-S1','8712542-S1','8707999-S1','8721421-S1','8775308-S2','8794626-S1','8794980-S3','8656349-S1','8806707-S1','8748526-S1','8794980-S3','8703623-S1','8734533-S1','8805188-S1','880720611','8711411-S1','8806779-S1','8761949-S4','8794980-S3','8716045-S1','8780406-S1','8713226-S1','8789502-S1','8776294-S1','875555011','8629672-S1','874109211','875123111','8715544-S1','871152511','874258911','8765164-S1','8775136-S3','8716723-S1','8717337-S1','8711750-S1','8807096-S1','8742084-S1','8775401-S2','8794980-S3','8710160-S1','8794980-S3','8733040-S1','8767319-S1','880754911','8765029-S1','8743623-S1','8648221-S1','8794980-S3','8801862-S1','879111113','8778800-S1','8726123-S6','8794980-S3','8711443-S1','869025611','8668963-S2','8749640-S1','873481611','8713907-S2','8765202-S2','880747011','875638411','8748322-S1','880739811','8746188-S1','8717577-S1','8716050-S1','8740212-S2','8794980-S3','8806973-S1','880720211','869317211','8708673-S1','8733730-S1','8794980-S3','8806028-S1','8760352-S1','8709650-S1','8740208-S1','8738652-S1','875322511','8750449-S1','8794980-S3','8767978-S1','874118811','8710783-S1','8711525-S1','8806325-S1','8748825-S1','8775401-S2','8705370-S1','8749664-S2','880759811','8735340-S1','8793622-S1','8794980-S3','874396211','877688882','8806972-S1','8807165-S1','8739101-S1','8710495-S1','8705370-S1','8775418-S1','8738141-S1','8727174-S2','8739921-S1','8802655-S1','8794980-S3','8782314-S2','880757411','8775615-S1','8749714-S1','8764947-S2','8705070-S1','8807160-S1','8759970-S1','874106711','8801280-S1','8775136-S1','8753809-S2','872842311','8759988-S1','880746511','8748102-S1','8709468-S1','8784604-S3','880720811','8649316-S1','8675777-R1','874398111','872044381','8714855-S1','8693256-S1','8772400-S1','874027011','8734181-S1','874079911','8806960-S1','874730511','873069911','8698364-S4','8707079-S1','8675795-S1','8794980-S3','8769562-S5','8763993-S2','8794980-S3','8807166-S1','8788470-S2','8710642-S1','8772316-S3','8786094-S2','8740091-S1','8772316-S6','880723111','8765703-S1','875409911','8750449-S1','875473911','8781198-S1','8749847-S1','8772400-S3','8708258-S1','880727211','874438111','881354411','8710433-S1','8794980-S3','8737830-S1','8806496-S1','8708232-S1','8736920-S1','8776760-S1','8703580-S1','8767984-S5','874846411','8794980-S3','8753087-S1','8794980-S3','8797362-S2','8689642-S1','875380911','8794980-S3','880747611','8697515-S2','8794980-S3','8776869-S1','8794980-S3','8695999-S1','8737310-S1','8711564-S1','8749664-S1','874190411','8800916-S1','8794980-S3','874892211','8806872-S1','880474881','8794980-S3','8708156-S1','8754252-S1','8794980-S3','8794980-S3','8794980-S3','873372311','8803376-S1','8790849-S1','8806154-S1','8794980-S3','8794980-S3','881010011','874507111','8706936-S1','8775136-S1','875515011','8794043-S1','8795941-S1','8690385-S1','8773836-S2','8800082-S1','8772400-S2','874412511','8728049-S1','8794980-S3','8711764-S1','8771611-S3','8794980-S3','8794980-S3','875218811','8786897-S2','8794980-S3','876984451','867819611','874172811','8806487-S1','8709038-S1','8794980-S3','874107511','8734500-S1','8794980-S3','8794980-S3','8794980-S3','8794980-S3','880718911','8794980-S3','880752411','8716474-S1','8794980-S3','874326511','8773836-S2','8682061-S1','875140011','8735488-S1','8806000-S1','8794980-S3','8794980-S3','873789082','8690140-S1','8703153-S1','8716435-S1','8789077-S1','880747211','8710728-S1','8677170-S1','8742954-S1','8794759-S1','8717333-S1','8716146-S1','8781148-S1','8794980-S3','868212011','878768211','867639011','8794980-S3','874085211','8800513-S1','875380911','879237611','8793978-S1','879111111','8805293-S1','8725162-R1','872804911','874972011');
    $select = $read->select()
        ->from(array('EO'=>'edicarrier_order'),$columns)
        ->joinLeft(array('CMO'=>'ccc_manufacturer_order'),"EO.order_id=CMO.order_id AND EO.shipment_number=CMO.po_number",array())
        ->where('EO.shipment_number IN(?)',$shipmentNumbers)
        ;
    echo $select;
    die();
    print_r(get_class_methods(Mage::getModel('edi/order_accept_change_request')));
    die();
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
    );
    $edicarrier = $read->select()
        ->from(array('EO' => 'edicarrier_order'),$ryderColumns)
        ->join(array('EOI' => 'edicarrier_order_item'),"EO.entity_id=EOI.parent_id",array())
        ->group('EO.order_id')
        ->group('EO.shipment_number');

    $columns = array(
        'increment_id' => 'CMO.increment_id',
        'order_id' => 'CMO.order_id',
        'shipment_number' => 'CMO.po_number',
        'is_replacement' => "IF(SFOIA.shipment_id = 6,'Yes','No')",
        'order_part_number' => new Zend_Db_Expr("IF(SFOIA.shipment_id = 6,at_replacement.part_number,REPLACE(GROUP_CONCAT(SFOIA.part_number),',',';'))"),
        'order_part_count' => new Zend_Db_Expr("IF(SFOIA.shipment_id = 6,at_replacement.part_number_count,(LENGTH(REPLACE(GROUP_CONCAT(SFOIA.part_number),',',';')) - LENGTH(REPLACE(REPLACE(GROUP_CONCAT(SFOIA.part_number),',',';'),';',''))) + 1)"),
        'order_qty' => new Zend_Db_Expr("IF(SFOIA.shipment_id = 6,at_replacement.part_number_count,SUM(IF(LOCATE(';',SFOIA.part_number),((LENGTH(SFOIA.part_number) - LENGTH(REPLACE(SFOIA.part_number,';',''))) + 1) * COALESCE(SFOI.qty_ordered,1),(COALESCE(SFOI.qty_ordered,1)*COALESCE(CPF.package_quantity,1)))))"),
        // 'order_qty' => new Zend_Db_Expr("IF(LOCATE(';',SFOIA.part_number),SFOI.qty_ordered,COALESCE(SFOI.qty_ordered,1) * COALESCE(CPF.package_quantity,1))"),
        'ryder_part_number' => 'at_carrier.ryder_part_number',
        'ryder_part_count' => 'at_carrier.ryder_part_count',
        'ryder_qty' => 'at_carrier.qty',
        'manufacturer_status' => 'CMO.manufacturer_status',
        'manufacturer_internal_status' => 'CMO.manufacturer_internal_status',
        'customer_status' => 'CMO.customer_status',
        'internal_status' => 'CMO.internal_status',
        'is_create_request_send' => "IF(at_carrier.create_status >= 3,'Yes','No')",
        'is_update_request_send' => "IF(at_carrier.update_status >= 3,'Yes','No')",
        'is_cancel_request_send' => "IF(at_carrier.cancel_status >= 3,'Yes','No')",
    );
    $select = $read->select()
        ->from(array('SFOI'=>'sales_flat_order_item'),array())
        ->columns($columns)
        ->joinLeft(array('CPF'=>'catalog_product_feed'),"SFOI.product_id = CPF.entity_id",array())
        ->join(array('SFOIA'=>'sales_flat_order_item_additional'),"SFOI.item_id = SFOIA.item_id",array())
        ->join(array('CMO' => 'ccc_manufacturer_order'),"SFOI.order_id=CMO.order_id AND SFOIA.mfg_id=CMO.mfr_id AND SFOIA.shipment_id=CMO.shipment_id AND SFOIA.ship_key_id=CMO.ship_key_id",array())
        ->join(array('at_carrier' => new Zend_Db_Expr("({$edicarrier})")),"CMO.order_id=at_carrier.order_id AND CMO.po_number=at_carrier.shipment_number",array())
        ->joinLeft(array('at_replacement' => new Zend_Db_Expr("({$replacementCount})")),"CMO.order_id=at_replacement.order_id AND CMO.mfr_id=at_replacement.mfr_id AND CMO.shipment_id=at_replacement.shipment_id AND CMO.ship_key_id=at_replacement.ship_key_id",array())
        // ->join(array('EO' => 'edicarrier_order'),"CMO.order_id=EO.order_id AND CMO.po_number=EO.shipment_number",array())
        ->where('SFOI.product_type = ?','simple')
        ->where('CMO.po_number IS NOT NULL')
        ->where('CMO.internal_status NOT IN (?)',array('complete'))
        ->group('CMO.order_id')
        ->group('CMO.po_number')
        // ->having('order_part_count != ryder_part_count OR order_qty != ryder_qty')
        ->having('order_qty != ryder_qty')
        ;
    echo $select;
    die();
    $select = $read->select()
        ->from(array('EO'=>'edicarrier_order'))
        ->where('EO.delivery_group_number IS NOT NULL')
        ->where("EO.update_status > 1 OR EO.cancel_status > 1")
        ->where('EO.update_status > ?','1')
        ->where('EO.created_at <= ?','2020-10-31')
        ->where('EO.updated_at <= ?','2020-10-31');
    echo $select;
    die();
    $columns = array(
        'increment_id' => 'SFO.increment_id',
        'order_id' => 'SFO.entity_id',
        'mfr_id' => 'SFOSH.mfr_id',
        'shipment_id' => 'SFOSH.shipment_id',
        'ship_key_id' => 'SFOSH.ship_key_id',
        'po_number' => 'CMO.po_number',
        'manufacturer_status' => 'CMO.manufacturer_status',
        'manufacturer_internal_status' => 'CMO.manufacturer_internal_status',
        'customer_status' => 'CMO.customer_status',
        'internal_status' => 'CMO.internal_status',
    );

    $ediOrder = $read->select()
        ->from(array('EO'=>'edicarrier_order'),array('order_id'))
        ->group('order_id');
    $select = $read->select()
        ->from(array('SFOSH'=>'sales_flat_order_status_history'),$columns)
        ->join(array('SFO'=>'sales_flat_order'),"SFOSH.parent_id=SFO.entity_id",array())
        ->joinLeft(array('CMO'=>'ccc_manufacturer_order'),"SFOSH.parent_id=CMO.order_id AND SFOSH.mfr_id=CMO.mfr_id AND SFOSH.shipment_id=CMO.shipment_id AND SFOSH.ship_key_id=CMO.ship_key_id",array())
        ->where('SFOSH.comment LIKE ?','%Cancel the backordered product(s) for a refund and have the avilable products(s) ship out right away.%')
        ->where("SFOSH.parent_id IN({$ediOrder})")
        ;
    echo $select;
    die();
    $columns = array(
        'increment_id' => 'EO.increment_id',
        'order_id' => 'EO.order_id',
        'shipment_number' => 'EO.shipment_number',
        'manufacturer_status' => 'CMO.manufacturer_status',
        'manufacturer_internal_status' => 'CMO.manufacturer_internal_status',
        'customer_status' => 'CMO.customer_status',
        'internal_status' => 'CMO.internal_status',
        'mfr_id' => 'EO.mfr_id',
        'shipment_id' => 'EO.shipment_id',
        'create_status' => 'EO.create_status',
        'asn_status' => 'EO.asn_status',
        'update_status' => 'EO.update_status',
        'cancel_status' => 'EO.cancel_status',
        'reallocatation_status' => 'EO.reallocatation_status',
        'status' => 'EO.status',
        'created_at' => 'EO.created_at',
        'updated_at' => 'EO.updated_at',
        
    );
    $select = $read->select()
        ->from(array('EO'=>'edicarrier_order'),$columns)
        ->join(array('CMO'=>'ccc_manufacturer_order'),"EO.order_id=CMO.order_id AND EO.shipment_number=CMO.po_number")
        ->where('EO.shipment_id = ?',6)
        // ->where("IF(LENGTH(EO.shipment_number) = 9 AND (RIGHT(EO.shipment_number,2) LIKE '8%' OR RIGHT(EO.shipment_number,2) LIKE '9%'),EO.shipment_number,LOCATE('-R',EO.shipment_number))")
        ;
    echo $select;
    die();
    var_dump(Mage::getModel('directory/region')->getCollection()->getMainTable());
    var_dump(Mage::getModel('edi/edicarrier_ryder_order')->isConfigDelayTime());
    die();
    $mpn = Mage::getSingleton('eav/config')->getCollectionAttribute('catalog_product', 'mpn');
    $upc = Mage::getSingleton('eav/config')->getCollectionAttribute('catalog_product', 'upc');
    $brand = Mage::getSingleton('eav/config')->getCollectionAttribute('catalog_product', 'brand');
    echo $read->select()
        ->from(array('at_product' => 'catalog_product_entity'),array())
        ->join(array('at_upc' => $upc->getBackendTable()), "at_upc.entity_id = at_product.entity_id AND at_upc.attribute_id={$upc->getId()}",array('value'))
        ->join(array('at_mpn' => $mpn->getBackendTable()), "at_mpn.entity_id = at_product.entity_id AND at_mpn.attribute_id={$mpn->getId()}")
        ->join(array('at_brand' => $brand->getBackendTable()), "at_brand.entity_id = at_product.entity_id AND at_brand.attribute_id={$brand->getId()}",array('value'))
        // ->join(array('at_part'=>'mfr_part_items'),"at_brand.value=at_part.brand_id AND at_mpn.value LIKE %at_part.part_number%")
        ->where('type_id  = ?','simple')
        ->where("at_mpn.value LIKE '%;%'")
        ->where("at_brand.value = ?",13863)
        ;
    die();
    $processAllShipment = Mage::getModel('edi/edicarrier_ryder_observer')->processAllShipment();
    print_r($processAllShipment);
    die();
    $orderIds = array(8806149,8697881,8750350,8799105,8808314,8794720,8804922,8779864,8795808,8785952,8768018,8803351,8772182,8791887,8804389,8810850,8733545,8809754,8795185,8797132,8783957,8735743,8800593,8808438,8782667,8807911,8794965,8779916,8805388,8796043,8805072,8757614,8794237,8789819,8779280,8772392,8803721,8753031,8758247,8807539,8763326,8761181,8806731,8808883,8791177,8798334,8770095,8765573,8798097,8800675,8786372,8754248,8756714,8795254,8798204,8801691,8806425,8772904,8787475,8792498,8806505,8787532,8790152,8798232,8801491,8782394,8810721,8808943,8763100,8773115,8804424,8768059,8807825,8802264,8804077,8760595,8807132,8708273,8806973,8798320,8787355,8787718,8764587,8806761,8710989,8787449,8805635,8800098,8756624,8736569,8807566,8672656,8785196,8804338,8795962,8806747,8799579,8800782,8810617,8801796,8781190,8802896,8802943,8780382,8774121,8791987,8800884,8786385,8785740,8805905,8808333,8798161,8807231,8803809,8803959,8694522,8797516,8809550,8778397,8773295,8771535,8800236,8788816,8766172,8779719,8776080,8772825,8779495,8765883,8786902,8774825,8799193,8675565,8794988,8796071,8755217,8784170,8807533,8787629,8702533,8799766,8759412,8798760,8797055,8804852,8792799,8778479,8791752,8795888,8778687,8786447,8798868,8760450,8810645,8780013,8771213,8791729,8791304,8770533,8778832,8777686,8786339,8808537,8803344,8779112,8809350,8778741,8803459,8794043,8802510,8775475,8795092,8786834,8811413,8805549,8767160,8808217,8808075,8800025,8709762,8796181,8764897,8755464,8705814,8806914,8808034,8810528,8780681,8772123,8692872,8798182,8772815,8777132,8773999,8800900,8759213,8774268,8791120,8687485,8769161,8794232,8785312,8805042,8787984,8791036,8783691,8802372,8807205,8795858,8801306,8765201,8790655,8796318,8799279,8811325,8753809,8804741,8806103,8798842,8769971,8760445,8717119,8766064,8760637,8783565,8779681,8807462,8801392,8810978,8802305,8790714,8772432,8731096,8767084,8802888,8810821,8808227,8800801,8776834,8808379,8745566,8763595,8804230,8795255,8730157,8775421,8810752,8808385,8800791,8798775,8811277,8809117,8777778,8794795,8784449,8806734,8763757,8795629,8805627,8810871,8808841,8790927,8784030,8787881,8787875,8794788,8794808,8699561,8795959,8808007,8796275,8767113,8775930,8807182,8809021,8764573,8789988,8799349,8787765,8802333,8789940,8802472,8808548,8807189,8805644,8795961,8778412,8802719,8759206,8771651,8806581,8805932,8703905,8791683,8802591,8783996,8762216,8759808,8810908,8796790,8799140,8710918,8779687,8805160,8748677,8791369,8783100,8799444,8802703,8794530,8807902,8794790,8794427,8783450,8808739,8765382,8792590,8795711,8807541,8790916,8808836,8810680,8791078,8805641,8798095,8808024,8810659,8807277,8803491,8794904,8793933,8787566,8776098,8810114,8810777,8765612,8763892,8803715,8798746,8791561,8794977,8777221,8800957,8776872,8784970,8789282,8798220,8786299,8770618,8795996,8779128,8805893,8805316,8794623,8802623,8808242,8801388,8778513,8803885,8765645,8799382,8772316,8796020,8796265,8806494,8784683,8799361,8771028,8765267,8803044,8725273,8755765,8769224,8805217,8809316,8799271,8784820,8765575,8763857,8733730,8804563,8775794,8770829,8782682,8809900,8790043,8808208,8760167,8780523,8810337,8804039,8801475,8796626,8668879,8785708,8737738,8756180,8808894,8690256,8785660,8803376,8804992,8803208,8736413,8760833,8798809,8809133,8786897,8792750,8810696,8772507,8763893,8811123,8806878,8806404,8798171,8805477,8805498,8744058,8763195,8805698,8790293,8738407,8771101,8770064,8800413,8718764,8795504,8807188,8778221,8806622,8737140,8808014,8791767,8798926,8772651,8803330,8780264,8809301,8810646,8801298,8793662,8799003,8788903,8809524,8745259,8800548,8810701,8801342,8795029,8806152,8810538,8778511,8802122,8807982,8801448,8775451,8765370,8761294,8806093,8802694,8787806,8797345,8798689,8743464,8789190,8750917,8806723,8810293,8730832,8796925,8800472,8809993,8810084,8793827,8810273,8767020,8769724,8791906,8766781,8795451,8772357,8807569,8762479,8808568,8780143,8810391,8798652,8786297,8802262,8765302,8798690,8789445,8796821,8798751,8808797,8754373,8789004,8765899,8769058,8807005,8775099,8731082,8784965,8805830,8807634,8797733,8800728,8805004,8629672,8809425,8757682,8794930,8806192,8804689,8798228,8802463,8810079,8794661,8802715,8804152,8787788,8763204,8777525,8796538,8777231,8753395,8808064,8802606,8754098,8801856,8785436,8721421,8808806,8775303,8809922,8758654,8787931,8742802,8716300,8795211,8802769,8737033,8763644,8791032,8802859,8806125,8793396,8809422,8717534,8774779,8801502,8757730,8777408,8794139,8810703,8723784,8795484,8799200,8798412,8792466,8797193,8795404,8795533,8784209,8807197,8795496,8798110,8795292,8801399,8789419,8728049,8803365,8807417,8784478,8806779,8805845,8795751,8763881,8810312,8763568,8793096,8802311,8759827,8767120,8774342,8801692,8802279,8736624,8782932,8798695,8808205,8771326,8799005,8783202,8778609,8808150,8786737,8773140,8801378,8798629,8737209,8794216,8808518,8808241,8771843,8766129,8794871,8805547,8798633,8768071,8797444,8809467,8810465,8774052,8753291,8766564,8804173,8779546,8770686,8809370,8719063,8809343,8786844,8793454,8807975,8810197,8791967,8808685,8806080,8770204,8766413,8810899,8756613,8802293,8808042,8759973,8785838,8806698,8788104,8770720,8794666,8733255,8808944,8775827,8781095,8798731,8791252,8778189,8810440,8807784,8799130,8769266,8776065,8810257,8804543,8784364,8808453,8716664,8801451,8785017,8792575,8784640,8805787,8737472,8771607,8800682,8795820,8765959,8778875,8808262,8786644,8779577,8758638,8793013,8780560,8798093,8780593,8784604,8809263,8761856,8766991,8764432,8804234,8810346,8809468,8810274,8742828,8766782,8810190,8729472,8803545,8799030,8799654,8796210,8773498,8766103,8773794,8803387,8750794,8798503,8747191,8799538,8769372,8794827,8806278,8795372,8795335,8801098,8771121,8790335,8694177,8757002,8758191,8794420,8792457,8767711,8801362,8708507,8705266,8792775,8799180,8799332,8798905,8791606,8779130,8793583,8725433,8810806,8798737,8787752,8806824,8794967,8789890,8810356,8776048,8799945,8801598,8771220,8804379,8809310,8765164,8795756,8798574,8766080,8797615,8798919,8808917,8788755,8757800,8763839,8749664,8808269,8804872,8793383,8767900,8782988,8775403,8784464,8810748,8807395,8780175,8795704,8806589,8798573,8809685,8781908,8807544,8800831,8807920,8800664,8772814,8766250,8773526,8778227,8795028,8811050,8799425,8745778,8810636,8763574,8810474,8787387,8782885,8785364,8766194,8755485,8789911,8801510,8805895,8784024,8793892,8779020,8790616,8791329,8786473,8740020,8810719,8786468,8775490,8735603,8806086,8809136,8799144,8712548,8776298,8766548,8794210,8778881,8805907,8809319,8802879,8769672,8808257,8804182,8787123,8798085,8808473,8762728,8775514,8772284,8803358,8794071,8807014,8805898,8807779,8773504,8705433,8808719,8760601,8776688,8793384,8807208,8808942,8794885,8803412,8806218,8796699,8779532,8721755,8782314,8766326,8774380,8810260,8785773,8784730,8802654,8794815,8767393,8807111,8803482,8802041,8773347,8784269,8766430,8810988,8808341,8786519,8732957,8805757,8795383,8796231,8797156,8797891,8799363,8800081,8802817,8788911,8793379,8746419,8803956,8805254,8811538,8804937,8805102,8771368,8810804,8807404,8799220,8804998,8768118,8804419,8796087,8794739,8786562,8807341,8761931,8803064,8786930,8808167,8698010,8808027,8779689,8786900,8802561,8806047,8810951,8776542,8796597,8788756,8796491,8798284,8754221,8802393,8808176,8802985,8798846,8810860,8787766,8810338,8798771,8808909,8776054,8809596,8772069,8806784,8751913,8809795,8804522,8783138,8807069,8806972,8759108,8808076,8789733,8658432,8776288,8798344,8800269,8713907,8771149,8784340,8764579,8784984,8780095,8810305,8806183,8808825,8768735,8810692,8805909,8762849,8749405,8731799,8801969,8792549,8793073,8810653,8769880,8810722,8766451,8772875,8743769,8810669,8763894,8789447,8791564,8803212,8803556,8810031,8791746,8796292,8793045,8787476,8804009,8807312,8780796,8798048,8761305,8792828,8701263,8777188,8795398,8781643,8788167,8810694,8761101,8783179,8783572,8810925,8803486,8808728,8806864,8790447,8791022,8763288,8793861,8807843,8756989,8808016,8783599,8807493,8764287,8800785,8807523,8783211,8793021,8779509,8765024,8808255,8798195,8787486,8788864,8792534,8796176,8809879,8747008,8796274,8770839,8733483,8807366,8793511,8769897,8727174,8769256,8810823,8794490,8783161,8803993,8808116,8807176,8779063,8765975,8792480,8793598,8764765,8810233,8754463,8802684,8801974,8774862,8756966,8787338,8765862,8739969,8771418,8802802,8798621,8702786,8807400,8795666,8762853,8782196,8771461,8805408,8810952,8793406,8806153,8802520,8803147,8795204,8804719,8810098,8787923,8724508,8797397,8787598,8788049,8763691,8748164,8794334,8767846,8791212,8805645,8800481,8808090,8799295,8810551,8787364,8801255,8806260,8781606,8787027,8799707,8801499,8787359,8808619,8798772,8743623,8785102,8802419,8769335,8786915,8760465,8781135,8682831,8789914,8800540,8795705,8747938,8786927,8798169,8809578,8796841,8748878,8753057,8798810,8785432,8798586,8755304,8792846,8792985,8804748,8810735,8777435,8810219,8791650,8810581,8773236,8786250,8796096,8798593,8652841,8760310,8807065,8808996,8803996,8780849,8753233,8756449,8801696,8809730,8805534,8746188,8791345,8776400,8801533,8808033,8773721,8792318,8800640,8810579,8810400,8808529,8787048,8804116,8796885,8763269,8803747,8789093,8804710,8749479,8749516,8790459,8774347,8769811,8769047,8761822,8768395,8806792,8754116,8717272,8678488,8791656,8796803,8761439,8783375,8780672,8807402,8804899,8796484,8811124,8802812,8758839,8796684,8805626,8759187,8802241,8770343,8772317,8772734,8646744,8792725,8772759,8784468,8807109,8770825,8801787,8766134,8679292,8767449,8792598,8805599,8770865,8786981,8773437,8803991,8771830,8794761,8793187,8807204,8810817,8810877,8809818,8725287,8808780,8809780,8807283,8796840,8810676,8798800,8788209,8795363,8793948,8761303,8800297,8808463,8792068,8651345,8711525,8789125,8800977,8802720,8661050,8761194,8809394,8810262,8808569,8810138,8767973,8746103,8767783,8776346,8810822,8776440,8805583,8773983,8784910,8791800,8741689,8682335,8802394,8795789,8742127,8810706,8795869,8770350,8804185,8805514,8784184,8776861,8794250,8779640,8798119,8786245,8806126,8765664,8775680,8794696,8792491,8784577,8784606,8798406,8788666,8756637,8808990,8809871,8810384,8809169,8805425,8780220,8800842,8752938,8811575,8767260,8803707,8770488,8779662,8750890,8805377,8757467,8760779,8784669,8811066,8810979,8796261,8808949,8796025,8798708,8774921,8799724,8742829,8810898,8808490,8766184,8810574,8765943,8807021,8773758,8734830,8808319,8784612,8806348,8769011,8808041,8805900,8798601,8801294,8792493,8805612,8810874,8776105,8747009,8728788,8802137,8795636,8775207,8792119,8793470,8811574,8763293,8756851,8768135,8784055,8737571,8788852,8807646,8795483,8791952,8772013,8751407,8778255,8805442,8806127,8807516,8765474,8794867,8749910,8806062,8787616,8804038,8783474,8791794,8763481,8791951,8795412,8783310,8758718,8785141,8803997,8791079,8807332,8765561,8701633,8793097,8806610,8797210,8754249,8809399,8797265,8743460,8735514,8793414,8778184,8672328,8774397,8809012,8776118,8801408,8743382,8791510,8772306,8766071,8785123,8808104,8799057,8785018,8769894,8805946,8776300,8800650,8753282,8780178,8807666,8780293,8778178,8764590,8772834,8796965,8782966,8792104,8773006,8779975,8783417,8752276,8732995,8780109,8808091,8810575,8775107,8772891,8795423,8783986,8808419,8790632,8766190,8793637,8764602,8808430,8726654,8653650,8799975,8805295,8795781,8786499,8802145,8805881,8790619,8793985,8809102,8733476,8796264,8795542,8805033,8783779,8796269,8775190,8731395,8758806,8776833,8791193,8791959,8798908,8809901,8808552,8788372,8751814,8797040,8766375,8787485,8769973,8802709,8804813,8807003,8777433,8708451,8808083,8811505,8810891,8791082,8788440,8802456,8763693,8798003,8804248,8804127,8784607,8744475,8760704,8774870,8794197,8776141,8777919,8782161,8778254,8803015,8809302,8789471,8763325,8783275,8805518,8783646,8799048,8795940,8806041,8804128,8797729,8803341,8773762,8765497,8770973,8802845,8808538,8808527,8792314,8803512,8773164,8781532,8805717,8716149,8791740,8810034,8810830,8798475,8760763,8807160,8764104,8784105,8747013,8807624,8798782,8795288,8808392,8780413,8777928,8805409,8796111,8677721,8719411,8777506,8781146,8759504,8774541,8803135,8794098,8791943,8776677,8756195,8807870,8810442,8787992,8793178,8771416,8781598,8802766,8782764,8780866,8762029,8766030,8801347,8796830,8809827,8810688,8794000,8808860,8766090,8782003,8762834,8811456,8805480,8783988,8787354,8690932,8806996,8809810,8725456,8799774,8737531,8806379,8806900,8803475,8687788,8795760,8776144,8795774,8800572,8795608,8801985,8803160,8780721,8806838,8784175,8768036,8802046,8810630,8808354,8806746,8791652,8766859,8775411,8735319,8789690,8802889,8809070,8781564,8791772,8775495,8775552,8811135,8786641,8780859,8771090,8779346,8807476,8756132,8779378,8810892,8791114,8782295,8798411,8783680,8762448,8810741,8772024,8771119,8797212,8807545,8796234,8641863,8791774,8789984,8747567,8802930,8806287,8810824,8774117,8757672,8799261,8779539,8800062,8804105,8802402,8795409,8805663,8805493,8798999,8810710,8803418,8804496,8744845,8795688,8798801,8802670,8802388,8789834,8699799,8735596,8737252,8794749,8778984,8766753,8789620,8759127,8797783,8806226,8805129,8765197,8758810,8807196,8809297,8809441,8775958,8762930,8779259,8767666,8767997,8760807,8803547,8808388,8715081,8758316,8803502,8809066,8810759,8810167,8780295,8668523,8780458,8804913,8785857,8775312,8785655,8792213,8759540,8795072,8790483,8791511,8808426,8708985,8693228,8764893,8805605,8800097,8803520,8779034,8802655,8764031,8805139,8775071,8808186,8778876,8803953,8776180,8800186,8802524,8769032,8770047,8810990,8794836,8791029,8803042,8788014,8758462,8792376,8781556,8809568,8759907,8769071,8789789,8790055,8795218,8799856,8800671,8761642,8801976,8796694,8775911,8809155,8810776,8785722,8810331,8808140,8798446,8794173,8773885,8792450,8794690,8787218,8790074,8805398,8705186,8808791,8783655,8795381,8771996,8811276,8807525,8803110,8790947,8768136,8807678,8703304,8797869,8721792,8806693,8692535,8767158,8774658,8805025,8810436,8807106,8785938,8799752,8795868,8803493,8766037,8656807,8689192,8804978,8775320,8795397,8807422,8759605,8805859,8772139,8715457,8768625,8773292,8777398,8764203,8776730,8779286,8798916,8761689,8798894,8793527,8773996,8798688,8811159,8784850,8762807,8795554,8756508,8805973,8802377,8780121,8775874,8800160,8796443,8806334,8668684,8764866,8803086,8802421,8774346,8795987,8802854,8798392,8775460,8775834,8782186,8794993,8753292,8695020,8784319,8764482,8770059,8809920,8806318,8806321,8746694,8782771,8765665,8774188,8790683,8786697,8794878,8806037,8793453,8799965,8777553,8729105,8730526,8799593,8790481,8793305,8686912,8754010,8785694,8754747,8799294,8790820,8789492,8803173,8796175,8785835,8790854,8780273,8808416,8808554,8800916,8790121,8809744,8810942,8793575,8760944,8808346,8794684,8793429,8806302,8775878,8771333,8807430,8804120,8802678,8780297,8765148,8779576,8807524,8767029,8769893,8791899,8702362,8788881,8809768,8791803,8805671,8763799,8805944,8798758,8795407,8761819,8801454,8803841,8805013,8780146,8791734,8787291,8804132,8802357,8778720,8758096,8811264,8742481,8803987,8704569,8793216,8791483,8768032,8740676,8803781,8810618,8673036,8772486,8809379,8806151,8798132,8798100,8784202,8806705,8749126,8792536,8763731,8784832,8810020,8779615,8806569,8806760,8800751,8807976,8809854,8761882,8801081,8798108,8801646,8805938,8781737,8808282,8795831,8784847,8782211,8711002,8783146,8806144,8790054,8778838,8770552,8800120,8680459,8783367,8801742,8769829,8766007,8810879,8775408,8767622,8807099,8765897,8783155,8798346,8809820,8810827,8777240,8779117,8773149,8738433,8802734,8809181,8805588,8800674,8808245,8797436,8793083,8769230,8729029,8810921,8797744,8793093,8796508,8808132,8805324,8797988,8800069,8808128,8773349,8765416,8769412,8771881,8773341,8810077,8805015,8787947,8770019,8783007,8783651,8785541,8793596,8769844,8757461,8770281,8805802,8737522,8796876,8640448,8805658,8766868,8804629,8807940,8807568,8750617,8771549,8804466,8794439,8807394,8787325,8779085,8810497,8770207,8805265,8765818,8784709,8772356,8782948,8762966,8782836,8770821,8808911,8787712,8799435,8794822,8769269,8796915,8810686,8782930,8803813,8803911,8783233,8768145,8798010,8711348,8798729,8808332,8788090,8779669,8805512,8796143,8786235,8805848,8785548,8794866,8707796,8799765,8802131,8791542,8809275,8798776,8801442,8769874,8769881,8772231,8772400,8790005,8810530,8785379,8803609,8804186,8773945,8790853,8797208,8772614,8791234,8796092,8803935,8786916,8807696,8790141,8808315,8733473,8799066,8771353,8753537,8803143,8798994,8779656,8785183,8750899,8766976,8778991,8773070,8791590,8795509,8774370,8766632,8785200,8805782,8808858,8783703,8756384,8794096,8786165,8805499,8795006,8798632,8810832,8805606,8780702,8799788,8792975,8807925,8796917,8808526,8806164,8805954,8684216,8785611,8806206,8807175,8789128,8806714,8784550,8791111,8800890,8802219,8794598,8766847,8766022,8791376,8772341,8808130,8793565,8811496,8756542,8801089,8715092,8756632,8778775,8808431,8792320,8770244,8783302,8808352,8760859,8810306,8785799,8755166,8777906,8758275,8806931,8783984,8789103,8788859,8796800,8729830,8720272,8796139,8799376,8803425,8805021,8786400,8795560,8805738,8669924,8779646,8808839,8809194,8795599,8809287,8761396,8808029,8808764,8787912,8766780,8795003,8699692,8784246,8782333,8796090,8807465,8765411,8804315,8810962,8764330,8766713,8797435,8790784,8805646,8762933,8787228,8801745,8764606,8794160,8808201,8763648,8705370,8795032,8809902,8794803,8764567,8794307,8810271,8801819,8807477,8777929,8802475,8779955,8788581,8808284,8804516,8760723,8792586,8697951,8776871,8803555,8795190,8794848,8712141,8767038,8769339,8780587,8779461,8734425,8804262,8776997,8792340,8805120,8808169,8801251,8766537,8796155,8784518,8788952,8807305,8695584,8787509,8782917,8716685,8791855,8790836,8784600,8753412,8777491,8811708,8806939,8798174,8790415,8784420,8800887,8775238,8789496,8785507,8765570,8783672,8805359,8797976,8645896,8672894,8778233,8795334,8797471,8808843,8786898,8787719,8779523,8675777,8773248,8784999,8784133,8765990,8789502,8806800,8807392,8807876,8809358,8785887,8789863,8784915,8769271,8776876,8796556,8799146,8797259,8806332,8737238,8769562,8783298,8798707,8772237,8810698,8728392,8799082,8781585,8738424,8810494,8800706,8726504,8796801,8806098,8776386,8765150,8785458,8784188,8791988,8798212,8794679,8785910,8795146,8728181,8774692,8799151,8785657,8794319,8779500,8809361,8760154,8769042,8721899,8795037,8806384,8805592,8779156,8800817,8779525,8761757,8770728,8774557,8699439,8799909,8798862,8763747,8694484,8792500,8787702,8800063,8799260,8798230,8794165,8789830,8775455,8764533,8809616,8689669,8794471,8782949,8759708,8795701,8802706,8810648,8666360,8787397,8795215,8810065,8788141,8807681,8787728,8734317,8770765,8791364,8795656,8783188,8764309,8810913,8763748,8781484,8772715,8797922,8772545,8770042,8765596,8808440,8799507,8788017,8786928,8805633,8809359,8808978,8808323,8810100,8807150,8773450,8731789,8788102,8798959,8748088,8796126,8807379,8810851,8798414,8750069,8793521,8774922,8791813,8798617,8711391,8797595,8803474,8779765,8810046,8804241,8806171,8626651,8746446,8760581,8783597,8777362,8796938,8775715,8782493,8806413,8802668,8737656,8771477,8804562,8806197,8778153,8773218,8802869,8787610,8785315,8806059,8745958,8778890,8763278,8809154,8788578,8806950,8803760,8778722,8800175,8762963,8810689,8788527,8788147,8800994,8724811,8782527,8758849,8800210,8752188,8785004,8786941,8759834,8810682,8768084,8802773,8784240,8757259,8777558,8769940,8762850,8775384,8800253,8785016,8794943,8779422,8781895,8804981,8752099,8795290,8706548,8778460,8779458,8792930,8802699,8757402,8794081,8794980,8805045,8791856,8788148,8779242,8802439,8753684,8695722,8807874,8785894,8766936,8791337,8807333,8762057,8728339,8784015,8799307,8795937,8764831,8808243,8790615,8786263,8783720,8796796,8808160,8791596,8804646,8802663,8809994,8810972,8783015,8806136,8807923,8705070,8734943,8803537,8773155,8793641,8780902,8793806,8795366,8794941,8774387,8775360,8796870,8803687,8790856,8798244,8738104,8810959,8766787,8786932,8798086,8809390,8782783,8765611,8810341,8801626,8768168,8766518,8794891,8798368,8797614,8806757,8803963,8802469,8766282,8786442,8784007,8793186,8736703,8779473,8771258,8798473,8707949,8810134,8810227,8799329,8785317,8770049,8802405,8777400,8776694,8810685,8808248,8782868,8775660,8790541,8802123,8710378,8803613,8796032,8754550,8790994,8762897,8796270,8792124,8791038,8769196,8711745,8801379,8785877,8804961,8798577,8807626,8754538,8810755,8803822,8792461,8804060,8805453,8778639,8805941,8737634,8800717,8764572,8801046,8808730,8777617,8807987,8777530,8778846,8784982,8791432,8798869,8808093,8811742,8808289,8810708,8803679,8808648,8780369,8798748,8806928,8797761,8788844,8794779,8681071,8807361,8779803,8794495,8780949,8803639,8802518,8792151,8764869,8791843,8775279,8768531,8720832,8801159,8782460,8784258,8802609,8800162,8802580,8737890,8758701,8807553,8793353,8714415,8770453,8808955,8736731,8771436,8811275,8794902,8769734,8788013,8792462,8688540,8766052,8799448,8803492,8799357,8796901,8808052,8763916,8760588,8783748,8807564,8806672,8795836,8803843,8803788,8769962,8719029,8809007,8769136,8807505,8749822,8802866,8768215,8779668,8771937,8783607,8809487,8783128,8791087,8786005,8759097,8785212,8794011,8809729,8706510,8804963,8775551,8766872,8779079,8731903,8809983,8805241,8769872,8773795,8790949,8739672,8769597,8790052,8770651,8727003,8792008,8788429,8802379,8795685,8805030,8772321,8765283,8778261,8795505,8806684,8774034,8794677,8799803,8808936,8652952,8780364,8805383,8795573,8701730,8771821,8674028,8788887,8798198,8809290,8761244,8795018,8780673,8808317,8767010,8779791,8792490,8793586,8793410,8805689,8783698,8762663,8807907,8705403,8796166,8790653,8801960,8802590,8755155,8803951,8765704,8810586,8800504,8805851,8791393,8806142,8799181,8801932,8791245,8786394,8810754,8805018,8805522,8775456,8788105,8788871,8784059,8809838,8794754,8788056,8755669,8762953,8794973,8808015,8811009,8788048,8758245,8784461,8804637,8810895,8778536,8773442,8805252,8805521,8807217,8793622,8783797,8810106,8753490,8768951,8781852,8799776,8811488,8792405,8779381,8751806,8808058,8810489,8775377,8768031,8752429,8802384,8805856,8750228,8778128,8804272,8769278,8775065,8787472,8770753,8809005,8808012,8809597,8776710,8752967,8779452,8794718,8766276,8786241,8787764,8771355,8786370,8807100,8743002,8775635,8805575,8787526,8809014,8791366,8778582,8773975,8763364,8787832,8806615,8798589,8810737,8809026,8783814,8805915,8803155,8798663,8778107,8797224,8802669,8775396,8807133,8796685,8762977,8797295,8792253,8767351,8811138,8799172,8780985,8775105,8796062,8783640,8764911,8791588,8755171,8783689,8801312,8742920,8775724,8709717,8798697,8765403,8804096,8783798,8762441,8789694,8768739,8801433,8793902,8726707,8810732,8779306,8786818,8762281,8768161,8715213,8802426,8801235,8806370,8795800,8773708,8785898,8781256,8741643,8757065,8729658,8804081,8805510,8670428,8775814,8807242,8761429,8798184,8761865,8810258,8791539,8802430,8785077,8730227,8792419,8711285,8809325,8807850,8798644,8804104,8779739,8755027,8795944,8786650,8741147,8763976,8790823,8793500,8766327,8757047,8791303,8747056,8749882,8782198,8789308,8792026,8769416,8795336,8804286,8773989,8777183,8765127,8797140,8754764,8805436,8807319,8801951,8791657,8807272,8801746,8681633,8809244,8759763,8792473,8808378,8770886,8790694,8760880,8789736,8768792,8744116,8762507,8809439,8789603,8787388,8780605,8782866,8794198,8792869,8801550,8772303,8763400,8804717,8807244,8757387,8805020,8808301,8796768,8789159,8778693,8778732,8775616,8795485,8810920,8801230,8785151,8778207,8791874,8779644,8770665,8803784,8781380,8789997,8793217,8807352,8775135,8791186,8774938,8790018,8804056,8766571,8810820,8766029,8766079,8802412,8809930,8808465,8792593,8807152,8810674,8798710,8771613,8807978,8785020,8701454,8804746,8760157,8761681,8678852,8795026,8806905,8788120,8791839,8808979,8758611,8798986,8799088,8757962,8796908,8790849,8798793,8796047,8810855,8766935,8749242,8785000,8806205,8806778,8807834,8762215,8755007,8811483,8802795,8744559,8757301,8811691,8753452,8752464,8795270,8810791,8791422,8756723,8810929,8785093,8803895,8811576,8713733,8805678,8785368,8808799,8808801,8798655,8761170,8752090,8810912,8784310,8805528,8796277,8784811,8810775,8800065,8810766,8775519,8775678,8799609,8808299,8807891,8792529,8797960,8768282,8804855,8791722,8809185,8799268,8807075,8798354,8810842,8805525,8808965,8803560,8784529,8767052,8717346,8796440,8802799,8809163,8792372,8764080,8805089,8806175,8775417,8806666,8793444,8787956,8738970,8794971,8798711,8794966,8773172,8798206,8809340,8795764,8765724,8785029,8793630,8810299,8805420,8759044,8771179,8807797,8807124,8789617,8774046,8643103,8791808,8806390,8771346,8802596,8801520,8789713,8758647,8804456,8803921,8810712,8803528,8624029,8798671,8740864,8787971,8810086,8810578,8782909,8780019,8671815,8803847,8785889,8721542,8804838,8780283,8782309,8795811,8791639,8794700,8789839,8755723,8796583,8804680,8795957,8737035,8809673,8781792,8761079,8803756,8792396,8774043,8773069,8802057,8780781,8802767,8780031,8810906,8798645,8795649,8807574,8803443,8788872,8797390,8802809,8799033,8810039,8731122,8803227,8807540,8781862,8784664,8773635,8770636,8792555,8808115,8757663,8788354,8765669,8808072,8773919,8773482,8802847,8787543,8677400,8795541,8796722,8804778,8769013,8794897,8795212,8795540,8748113,8712674,8770864,8770438,8788156,8789485,8801936,8785148,8794228,8803802,8796280,8783856,8792840,8762365,8765294,8800184,8794964,8811828,8809738,8779549,8787993,8767308,8782637,8807947,8800468,8778549,8801413,8736653,8805378,8759904,8700367,8807550,8700922,8770180,8786331,8786837,8804926,8800485,8788940,8797467,8774247,8802486,8785811,8776979,8754384,8699967,8785286,8755517,8769717,8810548,8787572,8808300,8752520,8805466,8785192,8805041,8810716,8705954,8618411,8782283,8760955,8796145,8794657,8780083,8752637,8807235,8805919,8681812,8803699,8700045,8779844,8797795,8807536,8803355,8763621,8798786,8764863,8742997,8796906,8787307,8783577,8778831,8791276,8766704,8796399,8784036,8794611,8796085,8797269,8795713,8805513,8765936,8811835,8808907,8760203,8774797,8808010,8781473,8808760,8810511,8769010,8775429,8809973,8787303,8740144,8799185,8769289,8810093,8800615,8786353,8699751,8806756,8798179,8783733,8767097,8787545,8803543,8795681,8787662,8801363,8780191,8792759,8787501,8771292,8776903,8791340,8777571,8750388,8808367,8778214,8783511,8716937,8795552,8794502,8801633,8789446,8773113,8787492,8810352,8796522,8802523,8789298,8804469,8797569,8794280,8808360,8766040,8751352,8793560,8801699,8669402,8710383,8806621,8803404,8808265,8795328,8798811,8811204,8797532,8793932,8810607,8736026,8795517,8793273,8795207,8810006,8779513,8760865,8809752,8805980,8791785,8796112,8773156,8809210,8769085,8795486,8780427,8756900,8778413,8808148,8703849,8797898,8796658,8760099,8788758,8802177,8809003,8805967,8807472,8777566,8764465,8799313,8694451,8801740,8773190,8755752,8795232,8755425,8675200,8759193,8769950,8790422,8806839,8795565,8773644,8768853,8793476,8801536,8806906,8789336,8785755,8784525,8754896,8692920,8775437,8771678,8776908,8790682,8788634,8802772,8778906,8774213,8791249,8764993,8804989,8811453,8775913,8803151,8738643,8682206,8802629,8790531,8759774,8806620,8800340,8803531,8768592,8764693,8789767,8757884,8794921,8810857,8808752,8775782,8772408,8794727,8810897,8774647,8776895,8798610,8792504,8799356,8793017,8810800,8763267,8765406,8775082,8779726,8633141,8779692,8793585,8800406,8799301,8807836,8771648,8773546,8796847,8796015,8810485,8784955,8779898,8799763,8783464,8785712,8805555,8795885,8765307,8794767,8795141,8811195,8757606,8770104,8805174,8793968,8770619,8787547,8787666,8770963,8792414,8810902,8773118,8772301,8775166,8761947,8786090,8785067,8810982,8750679,8795707,8793790,8783409,8755595,8770594,8808567,8801410,8806154,8806635,8810654,8806989,8765582,8775599,8790115,8790986,8802805,8798170,8805509,8810263,8793090,8795990,8808211,8793060,8803503,8693754,8783348,8799445,8791898,8744065,8756686,8793061,8747305,8776896,8791868,8747450,8802009,8807165,8808986,8793745,8757193,8792954,8657874,8758720,8801725,8794616,8807166,8794896,8808292,8759067,8803883,8804007,8783449,8792354,8796483,8810587,8718740,8792410,8808398,8804083,8791439,8805503,8798222,8795720,8785503,8647745,8807898,8763112,8798979,8746981,8796458,8794801,8800056,8806695,8761918,8809580,8762394,8771445,8809807,8806032,8801965,8809719,8732686,8793669,8798626,8768971,8787879,8798783,8775365,8780420,8709652,8805638,8793866,8770659,8786993,8792363,8802692,8808606,8776701,8776727,8797342,8808013,8802292,8804318,8791429,8729798,8799201,8794248,8807219,8789956,8765476,8792501,8803167,8794027,8799430,8754531,8735444,8787925,8797751,8810919,8791389,8659115,8796308,8811354,8781360,8810715,8797483,8769406,8765168,8808268,8794501,8729626,8805820,8800047,8785447,8795257,8796333,8807144,8787626,8779967,8802983,8806048,8778306,8804886,8763709,8787952,8778850,8805434,8782178,8770102,8792203,8802785,8760805,8798676,8784931,8790155,8797014,8755074,8804075,8803481,8772225,8794592,8810779,8798932,8810183,8700141,8778131,8804568,8763702,8795277,8762806,8794940,8726291,8778316,8803863,8801863,8794229,8797343,8797475,8803466,8806462,8789343,8810057,8770041,8695491,8800715,8689855,8802188,8736721,8763783,8786016,8797354,8775404,8792150,8788356,8778857,8727943,8805064,8801375,8810611,8791321,8805960,8799232,8799258,8709167,8782110,8805492,8800188,8752628,8785326,8742849,8785131,8806328,8773815,8781148,8797338,8763461,8757125,8793944,8770830,8810846,8808850,8757619,8809713,8775192,8759378,8770652,8783812,8791175,8768772,8775363,8782734,8753696,8772335,8809412,8688827,8793227,8800362,8785689,8791918,8778845,8786910,8649603,8807942,8789888,8788173,8802833,8781277,8785563,8795661,8792012,8806444,8807308,8795903,8725610,8799062,8798512,8806872,8787709,8809889,8758503,8808628,8792608,8801114,8801749,8774703,8802124,8772786,8763895,8807299,8749051,8810924,8786176,8787946,8809254,8760325,8783621,8804024,8737712,8795802,8725261,8807304,8808069,8805181,8775855,8777159,8805168,8808696,8776732,8810890,8794898,8749049,8793233,8792508,8796180,8784311,8798691,8796408,8805601,8794692,8769760,8753342,8810562,8763777,8788095,8807884,8760196,8803758,8808423,8731780,8733294,8810597,8796351,8809022,8805561,8792441,8795818,8796255,8802494,8768049,8807202,8807096,8743335,8751279,8765541,8669969,8774263,8781561,8791336,8801397,8805559,8810275,8766400,8797337,8806940,8811113,8807151,8805608,8776864,8776294,8802408,8779487,8731183,8771214,8808614,8782224,8794736,8790664,8801213,8797243,8788123,8716732,8802843,8785714,8775571,8762561,8774212,8807549,8789281,8791278,8805931,8800776,8801903,8798143,8810025,8793853,8765405,8788002,8807897,8783371,8808747,8633663,8787682,8803479,8809488,8798799,8806140,8774848,8805001,8757546,8799223,8771463,8780911,8809273,8776009,8809883,8802631,8767271,8804957,8776175,8770544,8797161,8783650,8770872,8810620,8802043,8793691,8753008,8808421,8766363,8760225,8771393,8792666,8802398,8806177,8805269,8739378,8796228,8777927,8749094,8766471,8776604,8661380,8699701,8731941,8764568,8806941,8800247,8686938,8798991,8809043,8770985,8808073,8807912,8732422,8807552,8760634,8767667,8763398,8752601,8788145,8780634,8774494,8673778,8800100,8803239,8805271,8796530,8778415,8788174,8798203,8802101,8773176,8790376,8804301,8798759,8789489,8795932,8807141,8808260,8802870,8787426,8760249,8772774,8800912,8793620,8760476,8797316,8810111,8656040,8768218,8782577,8795617,8783362,8793450,8770979,8806679,8760178,8709862,8800522,8781610,8802571,8778560,8793002,8775067,8768834,8798804,8766841,8749961,8811377,8784817,8753213,8804497,8784467,8798202,8809686,8795750,8806883,8779430,8805432,8806471,8805412,8805766,8769866,8793126,8801700,8793796,8805847,8809415,8807768,8791900,8779417,8805747,8759891,8784199,8810289,8799717,8798944,8794809,8798150,8785600,8788982,8792927,8766818,8794147,8787778,8792186,8766539,8807206,8762219,8802310,8770168,8789238,8780968,8809732,8765594,8796007,8802239,8790311,8809792,8756630,8773058,8764705,8802425,8756761,8772345,8799177,8807307,8794641,8785030,8778357,8806322,8726005,8766624,8808429,8810876,8801970,8805986,8715495,8801238,8790984,8807939,8791236,8799741,8772767,8765308,8780869,8809624,8809494,8810945,8779092,8798797,8786321,8772827,8780916,8800738,8807470,8782055,8786698,8810994,8807039,8740952,8765840,8801035,8780633,8796019,8775590,8655135,8806000,8800996,8800862,8761463,8808851,8811255,8781778,8792469,8773192,8771684,8796741,8792770,8801758,8797456,8762755,8805158,8798339,8767314,8763128,8788895,8804502,8788733,8789338,8791219,8764532,8783534,8802383,8807616,8791392,8789431,8782560,8802532,8799565,8808233,8810604,8779474,8800687,8797798,8802332,8808165,8810270,8687501,8697178,8806314,8661257,8799706,8797135,8802225,8763564,8809286,8802351,8802996,8793559,8764781,8771493,8794968,8730095,8809082,8796758,8802664,8764881,8660464,8794336,8802928,8777164,8803743,8784039,8808572,8734102,8696451,8766792,8809604,8771543,8810672,8804882,8800618,8769988,8658670,8792705,8771871,8764136,8799476,8782884,8791540,8808697,8779357,8802642,8809857,8797213,8808981,8803434,8777515,8778269,8686071,8786147,8808789,8799083,8779451,8772443,8789311,8774985,8801737,8787632,8807398,8768781,8789743,8775929,8775378,8785468,8796720,8804034,8810955,8763695,8710164,8767253,8760681,8803593,8783463,8806744,8772295,8808240,8794129,8669444,8763689,8798515,8804464,8809945,8797746,8806960,8789500,8795605,8769157,8800112,8758865,8787241,8805955,8810345,8787593,8806203,8810818,8782989,8800476,8805860,8787479,8794223,8765233,8801987,8809885,8788846,8808432,8787301,8810810,8761892,8791232,8795301,8804883,8765374,8761747,8802006,8793594,8806707,8775994,8718426,8767232,8796011,8800220,8801250,8809093,8696742,8795856,8647898,8776888,8801428,8740212,8783891,8781477,8790087,8793639,8765413,8807888,8808464,8787489,8729451,8789117,8810365,8804107,8804147,8787502,8805211,8757485,8789569,8773995,8810327,8807904,8795087,8804087,8793026,8778868,8799244,8797108,8765423,8769141,8806851,8776208,8809130,8783389,8779265,8780115,8791231,8810664,8791255,8802813,8786233,8780962,8752488,8803834,8781995,8809397,8787416,8684598,8806367,8802484,8776878,8803310,8807280,8784563,8767357,8805428,8757106,8790728,8769549,8779883,8794769,8798694,8810553,8790633,8765540,8799257,8784121,8797715,8804524,8803771,8765345,8735058,8762934,8782059,8783917,8801899,8735517,8783723,8793552,8711622,8797560,8803759,8802989,8777094,8778363,8786411,8754697,8770546,8777854,8685578,8802874,8802160,8775285,8775567,8784327,8799238,8810813,8798114,8789050,8805949,8765202,8749860,8750879,8808950,8804422,8797914,8779793,8810668,8793402,8810638,8795073,8809676,8749989,8725943,8777065,8806180,8764593,8807203,8808264,8807161,8760966,8802922,8809825,8762140,8758025,8778000,8797944,8790119,8784088,8765510,8767988,8757841,8762496,8809762,8793603,8803464,8787052,8757764,8713402,8788025,8779872,8804678,8753087,8794918,8784520,8784035,8786274,8798214,8798030,8770896,8790870,8782164,8802878,8771407,8794478,8799298,8734090,8764807,8810104,8768638,8792856,8703661,8803456,8791208,8768753,8746666,8742632,8788616,8804848,8806561,8753883,8770650,8765615,8759539,8789257,8780124,8770900,8794834,8788151,8802455,8799398,8806998,8789920,8765050,8794074,8797410,8771286,8803460,8757918,8795939,8762442,8768090,8784758,8772216,8810713,8808087,8792970,8808795,8807878,8802841,8805652,8796962,8810419,8771154,8804529,8759873,8791187,8806101,8770376,8771877,8793846,8810202,8779411,8808232,8799219,8807230,8807406,8809859,8760828,8808017,8792298,8783505,8770320,8793161,8805332,8775682,8791373,8784146,8771570,8809541,8796978,8748685,8774823,8786972,8708871,8809607,8795030,8798717,8809631,8810080,8800460,8795598,8786509,8742084,8785390,8798820,8754588,8795852,8793370,8680391,8794828,8717967,8760380,8798453,8761381,8776862,8804432,8771539,8808521,8797276,8805188,8809954,8796753,8775772,8806496,8809749,8787833,8790251,8794600,8760655,8761915,8799142,8696422,8784053,8810598,8805538,8796157,8793729,8810500,8805516,8806186,8767123,8799077,8778404,8799347,8802932,8804869,8791902,8762034,8794283,8795368,8806794,8788078,8801799,8810671,8781349,8799747,8769786,8791270,8752353,8808131,8805564,8765239,8810844,8765668,8811127,8795264,8761739,8742815,8810542,8791362,8769868,8799327,8810742,8779574,8802526,8780398,8768070,8764071,8779671,8805307,8772747,8810191,8809855,8747955,8773747,8793327,8767511,8798867,8783453,8785752,8783386,8789804,8779390,8806803,8793643,8776792,8802259,8807613,8773519,8803814,8754394,8776198,8781629,8778929,8786229,8772208,8792088,8802698,8794551,8810355,8783274,8806214,8807855,8754633,8789126,8789132,8770786,8791119,8759548,8755769,8794317,8767143,8782498,8778998,8779002,8809939,8810862,8798818,8715417,8810841,8791077,8802238,8791405,8800953,8793031,8801370,8788139,8810162,8733189,8797200,8791093,8713400,8804919,8806508,8766047,8799046,8810353,8769627,8790071,8793808,8802358,8791008,8799267,8787587,8795717,8810395,8795575,8787470,8806588,8797188,8671679,8788749,8799408,8803467,8785903,8790972,8794629,8778617,8784674,8807449,8797495,8737678,8802042,8809948,8646877,8807396,8791206,8795930,8782555,8772327,8771646,8780928,8803318,8745864,8795815,8811550,8778389,8799037,8780765,8806815,8779786,8792681,8806487,8810909,8774267,8783694,8808923,8797101,8809280,8806208,8801953,8799713,8794052,8799656,8794789,8763110,8649758,8716817,8806039,8787717,8807296,8762674,8808461,8808275,8805117,8756225,8804021,8773136,8810894,8805683,8751429,8810928,8720003,8802061,8746160,8776887,8797906,8777258,8792235,8766163,8775443,8772517,8790034,8789204,8798696,8767004,8761465,8787459,8794448,8788732,8798824,8745272,8780153,8800826,8806251,8798520,8759113,8801105,8763241,8804614,8732218,8752112,8802454,8794524,8787999,8785631,8779446,8749507,8810328,8799810,8800800,8802643,8783806,8730461,8776697,8787436,8806909,8803541,8769079,8797463,8809039,8808212,8773202,8757063,8757635,8724263,8759414,8793065,8778480,8797361,8778275,8783589,8773406,8776340,8768603,8746688,8796182,8791365,8745220,8789842,8805657,8807656,8795375,8761507,8809068,8805936,8807391,8802970,8777671,8777689,8806842,8784988,8801862,8807112,8778244,8747273,8765490,8794058,8793153,8807615,8804232,8799064,8805054,8797555,8810633,8779992,8791552,8790090,8806028,8809671,8711736,8772253,8789090,8804004,8762597,8801162,8788818,8808718,8811410,8807990,8729001,8774491,8791834,8809240,8798485,8785827,8758340,8788249,8765074,8775975,8809934,8764819,8767217,8797536,8803569,8802119,8791406,8758412,8676890,8762172,8808988,8709229,8799418,8807198,8802392,8806325,8783852,8752883,8808505,8769277,8788715,8792295,8780946,8792955,8799633,8809778,8768016,8776750,8798722,8808566,8804862,8780680,8799915,8771242,8779994,8795718,8770747,8785783,8807598,8759807,8787438,8770675,8809315,8796113,8798791,8795719,8796565,8731499,8810286,8802992,8809352,8804900,8783480,8803990,8805237,8799443,8758146,8804053,8804765,8809326,8780922,8789972,8803476,8781092,8784943,8804169,8775271,8787742,8784435,8803812,8799242,8797506,8807190,8793792,8797053,8778762,8776052,8770893,8806804,8799334,8802504,8796215,8810627,8806001,8790645,8775472,8771465,8789273,8758797,8807755,8791576,8793329,8791125,8802999,8782107,8803505,8764349,8779691,8761158,8754294,8802500,8801579,8801664,8737407,8810771,8754590,8803876,8753280,8806141,8786773,8784067,8794647,8775483,8791616,8773769,8778230,8799516,8788182,8770719,8797926,8782118,8789385,8775713,8780053,8776394,8795572,8765171,8703194,8771494,8801786,8800619,8769533,8720443,8804988,8770024,8746258,8810122,8807084,8791609,8800509,8766019,8811494,8802978,8779928,8809959,8743886,8799409,8798902,8801220,8801525,8806134,8786627,8791765,8810957,8801390,8755735,8794890,8804312,8809874,8810186,8745953,8767178,8768076,8808579,8782687,8685588,8797362,8794190,8782943,8786082,8800194,8792465,8802597,8758483,8757169,8810939,8791627,8784908,8788564,8787848,8800513,8777568,8806068,8716885,8654507,8789395,8789380,8760756,8798773,8808912,8788338,8777476,8753580,8803576);
    $ryderColumns = array(
        'increment_id' => 'EO.increment_id',
        'order_id' => 'EO.order_id',
        'shipment_number' => 'EO.shipment_number',
        'ryder_part_number' => new Zend_Db_Expr("REPLACE(GROUP_CONCAT(EOI.part_number),',',';')"),
        'ryder_part_count' => new Zend_Db_Expr("(LENGTH(REPLACE(GROUP_CONCAT(EOI.part_number),',',';')) - LENGTH(REPLACE(REPLACE(GROUP_CONCAT(EOI.part_number),',',';'),';',''))) + 1"),
    );
    $edicarrier = $read->select()
        ->from(array('EO' => 'edicarrier_order'),$ryderColumns)
        ->join(array('EOI' => 'edicarrier_order_item'),"EO.entity_id=EOI.parent_id",array())
        ->group('EO.order_id')
        ->group('EO.shipment_number');

    $columns = array(
        'increment_id' => 'CMO.increment_id',
        'order_id' => 'CMO.order_id',
        'shipment_number' => 'CMO.po_number',
        'order_part_number' => new Zend_Db_Expr("REPLACE(GROUP_CONCAT(SFOIA.part_number),',',';')"),
        'order_part_count' => new Zend_Db_Expr("(LENGTH(REPLACE(GROUP_CONCAT(SFOIA.part_number),',',';')) - LENGTH(REPLACE(REPLACE(GROUP_CONCAT(SFOIA.part_number),',',';'),';',''))) + 1"),
        'ryder_part_number' => 'at_carrier.ryder_part_number',
        'ryder_part_count' => 'at_carrier.ryder_part_count',
    );
    $select = $read->select()
        ->from(array('SFOI'=>'sales_flat_order_item'),array())
        ->columns($columns)
        ->join(array('SFOIA'=>'sales_flat_order_item_additional'),"SFOI.item_id = SFOIA.item_id",array())
        ->join(array('CMO' => 'ccc_manufacturer_order'),"SFOI.order_id=CMO.order_id AND SFOIA.mfg_id=CMO.mfr_id AND SFOIA.shipment_id=CMO.shipment_id AND SFOIA.ship_key_id=CMO.ship_key_id",array())
        ->join(array('at_carrier' => new Zend_Db_Expr("({$edicarrier})")),"CMO.order_id=at_carrier.order_id AND CMO.po_number=at_carrier.shipment_number",array())
        // ->join(array('EO' => 'edicarrier_order'),"CMO.order_id=EO.order_id AND CMO.po_number=EO.shipment_number",array())
        ->where('SFOI.product_type = ?','simple')
        ->where('CMO.po_number IS NOT NULL')
        ->group('CMO.order_id')
        ->group('CMO.po_number')
        ->having('order_part_count != ryder_part_count');
    echo $select;
    die();
    $updateOrders = Mage::getModel('edi/edicarrier_ryder_observer')->processUpdateFiles();
    print_r($updateOrders);
    die();
    echo Mage::getSingleton('edi/edicarrier_ryder_order')->getOrderProcessAfterDate();die();
    print_r(get_class_methods($read));die();
    $shipmentNumber = array('8790683-S2','8807039-S1','874067681','877689681','8805577-S1','871037881','880514711','8806779-S1','880750511','880755311','880564411','8806371-S1','8806746-S1','8807151-S1','880765613','879489111','8775962-S1','8806922-S1','8806487-S1','8807096-S1','8807143-S1','8807161-S1','880719811','878623511','8758541-S1','8732957-R1','878815611','880299911','8807165-S1','880767811','8806225-S1','8807111-S2','880720311','880765611','8806410-S1','878962081','8806390-S1','880721711','880731911','8786235-S1','8806810-S1','880721911','880723111','880729911','8788156-S1','8802999-S1','8806800-S1','8806815-S1','880709611','8807111-S1','880740211','8773519-S4','8773519-S2','873665311','8736653-S2','8799016-S1','8805483-S1','880724411','8773857-S1','880746211','8705954-S1','880718911','8759630-S1','8805715-S1','8806937-S1','880742311','8757918-S1','8792997-S1','8806406-S1','8807152-S1','8806224-S1','8784387-S1','8806872-S1','8807160-S1','8772276-S1','8807074-S1','8767307-S1','8798953-S1','876581811','8765959-S3','8771657-S1','8784316-S1','8806906-S1','879208881','8807176-S1','8765818-S1','8765959-S1','8806058-S1','880710311','8750864-S2','8806058-S2','8758738-S1','8806015-S1','8773758-S2','8807065-S1','8807084-S1','8807112-S2','8807075-S1','8730095-S1','8769139-S1','8803425-S2','8803425-S3','8805021-S1','880835211','8805359-S1');
    $select = $read->select()
        ->from(array('EO'=>'edicarrier_order'))
        ->join(array('EDF'=>'edicarrier_downloaded_files'),"EO.order_id=EDF.order_id AND EO.shipment_number=EDF.shipment_number AND file_type='204'",array('action_type'=> new Zend_Db_Expr("GROUP_CONCAT(EDF.action_type)")))
        ->where('EO.shipment_number IN(?)',$shipmentNumber)
        ->group('EO.order_id')
        ->group('EO.shipment_number');
    echo $select;
    die();
    $createOrders = Mage::getModel('edi/edicarrier_ryder_order')->getPendingShipments();
    // $createOrders = Mage::getModel('edi/edicarrier_ryder_order')->getPendingShipments();
    print_r($createOrders);
    die();
    Mage::getModel('edi/order_delivery')->setOrderIds(array(244513,244987))->recalculateDeliveryStatus();
    die();
    print_r(get_class_methods($read));die();
    $cancelOrders = Mage::getModel('edi/edicarrier_ryder_order')->getCancelOrders();
    die();
    $order = Mage::getModel('sales/order')->load(249177);
    $shipments = Mage::getModel('edi/edicarrier_ryder_order')
        ->setOrder($order)
        ->getShipments();
    print_r($shipments);
    die;
    print_r(Mage::getModel('edi/edicarrier_ryder_observer')->processCancelFiles());
    die();
    Mage::getModel('edi/order_delivery')->setOrderIds(array(249194))->recalculateDeliveryStatus();
    die();
    $eventCode = 'RP';
    $commentCode = 'NS';
    $maxSelect = Mage::getSingleton('core/resource')->getConnection('core_read')->select()
    ->from(array('eal' => 'edicarrier_action_log'), array('max_id' => new Zend_Db_Expr('MAX(eal.id)')))
    ->join(['edf' => 'edicarrier_downloaded_files'], "edf.id = `eal`.file_id", [])
    ->where("edf.file_type = ?",214)
    ->group("eal.shipment_number");
     $everyLastOrderSelect =  Mage::getSingleton('core/resource')->getConnection('core_read')->select()
            ->from(array('eal' => 'edicarrier_action_log'), array('*'))
            ->where("id IN ({$maxSelect})")
            ->where("(delivery_status = 'not_mapped' OR delivery_status IS NULL OR delivery_status = '-')")
            ->where("event_code = '{$eventCode}'")
            ->where("comment_code = '{$commentCode}'");
    echo $everyLastOrderSelect;
    die();
    Mage::getModel('edi/edicarrier_ryder_order')->getPendingShipments();
    die();
    $select = $read->select()
        ->from(array('EAL'=>'edicarrier_action_log'),array())
        ->join(array('EAL1'=>'edicarrier_action_log'),"EAL.order_id=EAL1.order_id AND EAL.shipment_number=EAL1.shipment_number AND EAL1.delivery_status='cancelled'")
        ->where('EAL.event_code IN(?)',array('T2','OF','DA'))
        ->where('EAL.comment_code IN(?)',array('NS','SB'))
        ->group('EAL.order_id');
    echo $select;
    die();
    $select = $read->select()
        ->from(array('at_log'=>'edicarrier_action_log'),array('event_code','comment_code','order_count'=>"COUNT(DISTINCT at_log.order_id)"))
        ->where('at_log.event_code IS NOT NULL')
        ->where('at_log.comment_code IS NOT NULL')
        ->group('at_log.event_code')
        ->group('at_log.comment_code');
    echo $select;
    die();
    $model = Mage::getModel('manufacturer/order_deliverystatus')->load('asn_sent','delivery_status');
    print_r($model);
    die();
    $columns = array(
        'order_id' => 'CMO.order_id',
        'order_shipments' => new Zend_Db_Expr("GROUP_CONCAT(CMO.po_number)"),
        'shipments_manufacturer_status' => new Zend_Db_Expr("GROUP_CONCAT(CMO.manufacturer_status)"),
        'shipments_manufacturer_internal_status' => new Zend_Db_Expr("GROUP_CONCAT(CMO.manufacturer_internal_status)"),
        'shipments_customer_status' => new Zend_Db_Expr("GROUP_CONCAT(CMO.customer_status)"),
        'shipments_internal_status' => new Zend_Db_Expr("GROUP_CONCAT(CMO.internal_status)"),
    );

    $manufactureOrder = $read->select()
        ->from(array('CMO'=>'ccc_manufacturer_order'),$columns)
        ->where('CMO.is_express = ?',0)
        ->group('CMO.order_id');

    $columns = array(
        'api_order_id' => 'DOR.order_id',
        'increment_id' => 'DOR.ref_order_number',
        'order_id' => 'DOR.magento_id',
        'created_at' => 'DOR.created_at',
        'updated_at' => 'DOR.updated_at',
        'api_type' => 'DOR.api_type',
        'group_status' => new Zend_Db_Expr("GROUP_CONCAT(DOR.status)"),
        'order_customer_status' => 'SFO.status',
        'order_internal_status' => 'SFO.internal_status',
        'order_shipments' => 'MO.order_shipments',
        'shipments_manufacturer_status' => 'MO.shipments_manufacturer_status',
        'shipments_manufacturer_internal_status' => 'MO.shipments_manufacturer_internal_status',
        'shipments_customer_status' => 'MO.shipments_customer_status',
        'shipments_internal_status' => 'MO.shipments_internal_status',
    );
    $select = $read->select()
        ->from(array('DOR'=>'deliveright_order_response'),array())
        ->join(array('SFO'=>'sales_flat_order'),"DOR.magento_id=SFO.entity_id",array())
        ->join(array('MO'=>new Zend_Db_Expr("({$manufactureOrder})")),"DOR.magento_id=MO.order_id",array())
        ->columns($columns)
        ->where('SFO.status NOT IN(?)',array('complete','canceled'))
        ->group('DOR.magento_id')
        ->order('DOR.created_at ASC')
        ->having("group_status = 5");

    $columns = array(
        'increment_id' => 'at_orders.increment_id',
        'order_id' => 'ELE.order_id',
        'user_id' => 'ELE.user_id',
        'username' => 'ELE.username',
        'user_type' => 'ELE.user_type',
        'remote_ip' => 'ELE.remote_ip',
        'created_at' => 'DATE(ELE.created_at)',
        'deliveright_statuses' => 'at_orders.group_status',
        'order_customer_status' => 'at_orders.order_customer_status',
        'order_internal_status' => 'at_orders.order_internal_status',
        'final_decision_status' => new Zend_Db_Expr("(CASE
                                        WHEN at_orders.order_customer_status = 'arrived' AND DATEDIFF(DATE(NOW()),DATE(ELE.created_at)) >= 30 THEN 'deliver'
                                        WHEN DATEDIFF(DATE(NOW()),DATE(ELE.created_at)) < 30 THEN 'arrived'
                                    END)"),
    );
    $eventLog = $read->select()
        ->from(array('ELE'=>'eventlog_events'),array())
        ->join(array('at_orders'=> new Zend_Db_Expr("({$select})")),"ELE.order_id=at_orders.order_id",array())
        ->columns($columns)
        ->where('ELE.event = ?','orderinvoice_copyorder')
        ->group('order_id')
        ->order('created_at ASC');
    echo $select;
    echo "<br>";
    echo "<hr>";
    echo "<br>";
    echo $eventLog;
    die();
    $ftp = Mage::helper('core')->decrypt(Mage::getStoreConfig('ediryder/ftp/password'));
    var_dump($ftp);die; 
    die();
    $order = Mage::getModel('sales/order')->load(248575);
    $groupMfrs = Mage::getModel('manufacturer/order')->getDeliveryGroupMfrsCollection($order);
    print_r($groupMfrs);
    die();
    $select = $read->select()
        ->from(array('EO'=>'edicarrier_order'))
        ->join(array('CMO'=>'ccc_manufacturer_order'),"EO.order_id=CMO.order_id AND EO.mfr_id=CMO.mfr_id AND EO.shipment_id=CMO.shipment_id",array())
        ->join(array('SFOSH'=>'sales_flat_order_status_history'),"EO.order_id=SFOSH.parent_id",array())
        ->where("CMO.manufacturer_status IN('cancellation_request','cancelled')")
        ->where("SFOSH.comment LIKE ?",'%arrived%')
        ->group("EO.entity_id");

    echo $select;
    die;
    $date = Mage::getModel('core/date');
    print_r(get_class_methods($date));
    // $currentDate = Mage::getModel('core/date')->gmtdate('Y-m-d');
    die();

    var_dump(Mage::getStoreConfig('editorder/shipto_location/manufacturer_config'));
    die();
    $orderItemSelect = $read->select()
        ->from(array('SFOI'=>'sales_flat_order_item'))
        ->join(array('SFOIA'=>'sales_flat_order_item_additional'),"SFOI.item_id = SFOIA.item_id",array('SFOIA.part_number','part_number_count'=>new Zend_Db_Expr("(ROUND((LENGTH(SFOIA.part_number) - LENGTH(REPLACE(SFOIA.part_number, ';', ''))) / LENGTH(';')) + 1)")));

    $columns = array(
        'Order #' => 'ECO.increment_id',
        'Shipment #' => 'ECO.shipment_number',
        'Created Date' => 'SFO.created_at',
        'MFR Name' => 'CM.mfg',
        'RLM #' => new Zend_Db_Expr("CONCAT(CSC.numeric_code,' - ',IF(CSC.short_name IS NULL OR CSC.short_name = '',CSC.name,CSC.short_name))"),
        'MFR Status' => 'CMO.manufacturer_status',
        'Customer Status' => 'CMO.customer_status',
        'Order Part Items' => 'SFOI.part_number',
        'total_part_count' => 'SFOI.part_number_count',
        'Ryder Part Items' => 'ECOI.part_number',
        'Package Quantity' => new Zend_Db_Expr("COALESCE(CPF.package_quantity,1)"),
        'Ordered Qty' => 'SFOI.qty_ordered',
        'Ryder Qty' => 'ECOI.qty',
        'Final Qty' => new Zend_Db_Expr("(CASE
                                            WHEN SFOI.part_number_count = 1 THEN COALESCE(CPF.package_quantity,1) * SFOI.qty_ordered
                                            WHEN SFOI.part_number_count > 1 THEN SFOI.qty_ordered
                                        END)"), 
        'Product Type' => 'CPEI.value',
        'is Multi Part' => new Zend_Db_Expr("IF(SFOI.part_number_count = 1 OR SFOI.part_number_count = '1',0,1)"),
        'Final Status' => new Zend_Db_Expr("(CASE
                                                WHEN CMO.manufacturer_status IN('cancellation_request','cancelled') THEN 'cancel'
                                                WHEN CMO.customer_status IN('complete') THEN 'no action'
                                                ELSE 'update'
                                            END)"),
    );

    $type     = Mage::getSingleton('eav/config')->getCollectionAttribute('catalog_product', 'product_type');

    $select = $read->select()
        ->from(array('ECO'=>'edicarrier_order'),array())
        ->join(array('ECOI'=>'edicarrier_order_item'),"ECO.entity_id = ECOI.parent_id AND ECO.mfr_id = ECOI.mfr_id",array())
        ->join(array('SFO'=>'sales_flat_order'),"ECO.order_id = SFO.entity_id",array())
        ->join(array('SFOI'=> new Zend_Db_Expr("({$orderItemSelect})")),"ECO.order_id = SFOI.order_id AND ECOI.item_number = SFOI.sku AND product_type = 'simple'",array())
        ->join(array('CMO' => 'ccc_manufacturer_order'), "ECO.order_id=CMO.order_id AND ECO.shipment_number=CMO.po_number AND ECO.mfr_id=CMO.mfr_id AND ECO.shipment_id=CMO.shipment_id", array())
        ->join(array('CM' => 'ccc_manufacturer'), "ECO.mfr_id=CM.entity_id", array())
        ->join(array('CSCO' => 'ccc_ship_carrier_order'), "ECO.order_id=CSCO.order_entity_id AND ECO.mfr_id=CSCO.mfr_id AND ECO.shipment_id=CSCO.shipment_id",array())
        ->join(array('CSC' => 'ccc_ship_carrier'), "CSCO.wg_id=CSC.id", array())
        ->joinLeft(array('CPF'=>'catalog_product_feed'),"SFOI.product_id = CPF.entity_id",array())
        ->joinLeft(array('at_type' => $type->getBackendTable()), "at_type.entity_id = SFOI.product_id AND at_type.attribute_id={$type->getId()}", array())
        ->joinLeft(array('CPEI' => 'eav_attribute_option_value'), "at_type.value=CPEI.option_id", array())
        ->columns($columns)
        ->group('ECO.entity_id')
        ->group('ECOI.item_id')
        ->having("`Ryder Qty` != `Final Qty`")
        ;
        
    echo $select;
    die();
    $createOrders = Mage::getStoreConfig('ediryder/send_in_ryder/update_order');
    $createOrders = str_replace(',', PHP_EOL, $createOrders);
    $createOrders = str_replace(';', PHP_EOL, $createOrders);
    $createOrders = str_replace(' ', PHP_EOL, $createOrders);
    $createOrders = explode(PHP_EOL, $createOrders);
    $where = str_replace('\r', '', $read->quoteInto('shipment_number IN(?)', $createOrders));
    var_dump($where);
    die();

    $actionLog = Mage::getModel('edi/edicarrier_action_log')->getCollection();
    $select = $actionLog->getSelect()
        ->reset(Zend_Db_Select::COLUMNS)
        ->columns(array('order_id','ryder_count' => 'COUNT(shipment_number)'))
        ->group('main_table.order_id')
        ->group('main_table.shipment_number');
    $ryderOrderIds = $read->fetchCol($select);
    $ryderOrderIds = array_unique($ryderOrderIds);




    die();
    var_dump(Mage::getModel('shipcarrier/carriers') ->getCollection()->getMainTable());
    die();
    $productDetails = array(
        'name' => 'Testing product -11',
        'part_number' => 'testing-122',
        'brand' => '13799',
        'price' => '200',
        'weight' => '10',
        'shipping_type' => '29641',
    );
    $product = Mage::getModel('ccc_product/product');
    $product = $product->saveCustomProduct($productDetails);
    var_dump($product->getId());

    die();
    $upcAttribute = Mage::getSingleton('eav/config')->getCollectionAttribute('catalog_product', 'upc');
    // print_r($upcAttribute->getBackendTable());
    echo $read->select()
        ->from(array('at_upc'=>$upcAttribute->getBackendTable()))
        ->where('attribute_id = ?',$upcAttribute->getId());
    die();
    $file = Mage::getModel('edi/edicarrier_downloaded_files')->load(25);
    print_r($file->getFileContent('8636361','8636361-S1','RP',"NS"));
    die();

    $partNumber     = Mage::getSingleton('eav/config')->getCollectionAttribute('catalog_product', 'part_number');
    echo $read->select()
        ->from(array('CPE'=>'catalog_product_entity'),array('CPE.entity_id','at_partNumber.value'))
        ->join(array('at_partNumber'=>$partNumber->getBackendTable()),"at_partNumber.entity_id = CPE.entity_id AND at_partNumber.attribute_id={$partNumber->getId()}",array())
        ->where("at_partNumber.value LIKE ?","%.%")
        ->orWhere('at_partNumber.value LIKE ?',"%'%");
    die;
    $file = Mage::getModel('edi/edicarrier_downloaded_files')->load(25);
    print_r($file->getFileContent());
    die();
    $row = new Varien_Object();
    $io = new Varien_Io_File();
    $io->open(array('path' => Mage::getBaseDir()));
    $data = $io->read('SB-1944-Origin.csv');
    var_dump($data);
    die;
    $read = Mage::getSingleton('core/resource')->getConnection('core_read');
    $columns = array(
        'Order #' => "EO.order_id",
        'Shipment #' => "EO.shipment_number",
        'Item Part Number' => new Zend_Db_Expr("GROUP_CONCAT(EOI.item_number,';')"),
        'total_part_count' => "COUNT(EOI.item_number)",
    );

    $select = $read->select()
        ->from(array('EO' => 'edicarrier_order'),array())
        ->join(array('EOI' => 'edicarrier_order_item'),"EO.entity_id = EOI.parent_id AND EO.mfr_id = EOI.mfr_id",array())
        ->columns($columns)
        ->where('EO.status = ?',Furnique_Edi_Model_Edicarrier_Order::STATUS_UPDATED)
        ->group('EO.entity_id');
    
    // echo $select;die();

    $columns = array(
        // 'Order #' => "EO.increment_id",
        // 'Shipment #' => "CMO.po_number",
        // 'Item Part Number' => "SFOIA.part_number",
        // 'total_part_count' => "COUNT(SFOIA.part_number)",
        'order_id' => 'EO.order_id',
        'shipment #' => 'CMO.po_number',
        'part' => 'COUNT(SFOIA.part_number)',

    );

    $select = $read->select()
        ->from(array('EO' => 'edicarrier_order'),array())
        ->join(array('SFOI' => 'sales_flat_order_item'),"EO.order_id = SFOI.order_id AND SFOI.parent_item_id IS NOT NULL",array())
        ->join(array('SFOIA' => 'sales_flat_order_item_additional'),"SFOI.item_id=SFOIA.item_id",array())
        ->join(array('CMO' => 'ccc_manufacturer_order'),"EO.order_id=CMO.order_id AND SFOIA.mfg_id = CMO.mfr_id AND SFOIA.shipment_id = CMO.shipment_id AND SFOIA.ship_key_id = CMO.ship_key_id",array())
        ->columns($columns)
        ->where('EO.status = ?',Furnique_Edi_Model_Edicarrier_Order::STATUS_UPDATED)
        ->where('CMO.order_id = ?',245458)
        // ->group('EO.order_id')
        // ->group('EO.shipment_number')
        ;

    echo $select;   

    die();
    $file = Mage::getModel('edi/edicarrier_downloaded_files')->load(25);
    // print_r($file);
    print_r($file->getFileContent('8636364','8636364-S2','RP','NS'));
    die;
    $read = Mage::getSingleton('core/resource')->getConnection('core_read');
    print_r(get_class_methods($read));
    $ftp = Mage::helper('core')->decrypt(Mage::getStoreConfig('ediryder/ftp/password'));
    var_dump($ftp);die; 
    var_dump(Mage::getStoreConfig('ediryder/general/shipment_tracking_url'));die();
    print_r(Mage::helper('edi/status')->getMapDeliveryStatuses());
    die();
    $read = Mage::getSingleton('core/resource')->getConnection('core_read');
    $columns = array(
        'Order #'            => 'EAL.po_number',
        'Shipment #'         => 'EAL.shipment_number',
        'File Path'          => 'EDF.file_path',
        'File Name'          => 'EDF.file_name',
        'Internal Status'    => 'CMO.internal_status',
        'Mfr Status'         => 'CMO.manufacturer_status',
        'Ryder Event Code'   => 'EAL.event_code',
        'Ryder Comment Code' => 'EAL.comment_code',
        'Delivery Status'    => 'EAL.delivery_status',
        'Order Created Date' => 'SFO.created_at',
        'Ryder Created Date' => 'EO.created_at',
    );

    $select = $read->select()
        ->from(array('EAL'=>'edicarrier_action_log'),array())
        ->join(array('EDF' => 'edicarrier_downloaded_files'),'EAL.file_id = EDF.id',array())
        ->join(array('SFO' => 'sales_flat_order'),'EAL.order_id = SFO.entity_id',array())
        ->joinLeft(array('EO' => 'edicarrier_order'),'EAL.order_id = EO.order_id AND EAL.shipment_number = EO.shipment_number',array())
        ->joinLeft(array('CMO' => 'ccc_manufacturer_order'),'EO.mfr_order_id = CMO.entity_id',array())
        ->columns($columns)
        ->order('EAL.order_id')
        ->order('EAL.completed_at');

    echo $select;

    die();
    Mage::getModel('edi/order_delivery')->setOrderIds(245624)->recalculateDeliveryStatus();
    die();
    $file = Mage::getModel('edi/edicarrier_downloaded_files')->load(35);
    print_r($file->getFileContent('8636492','8636492-S1'));
    die();
    $ftp = Mage::helper('core')->decrypt(Mage::getStoreConfig('ediryder/ftp/password'));
    var_dump($ftp);die; 
    die();
    $collection = Mage::getModel('edi/edicarrier_action_log')->getCollection();

    foreach ($collection as $log) {
        if ($log->getMessage()) {
            $array = json_decode($log->getMessage(),true);
            if (isset($array['event_code']) && $array['event_code']) {
                $log->setEventCode($array['event_code']);
            }

            if (isset($array['comment_code']) && $array['comment_code']) {
                $log->setCommentCode($array['comment_code']);
            }

            if (isset($array['delivery_status']) && $array['delivery_status']) {
                $log->setDeliveryStatus($array['delivery_status']);
            }
            $log->save();
        }
    }

    die();
    var_dump(Mage::getStoreConfig('ediryder/general/only_visible_mapping_status'));die();
    $ftp = Mage::helper('core')->decrypt(Mage::getStoreConfig('ediryder/ftp/password'));
    var_dump($ftp);die;
    echo Mage::getModel('edi/edicarrier_type_edi_neworder')->getLocalPath();
    die;
    echo Mage::getModel('shipcarrier/order')->getCollection()->getMainTable();die();
    echo json_encode(array('event_code'=>'SC','comment_code'=>'','delivery_status'=>'refused'));die();
    Mage::getModel('edi/edicarrier_action_log')->addActionLog();
    // echo Mage::getModel('shipcarrier/shipment')->getCollection()->getMainTable();
    die();
    Mage::getModel('edi/edicarrier_ryder_observer')->UpdateAdiitionalRefusedPart(1162709,'2304');
    
    $additional = Mage::getModel('ordereditor/additional_item')->load(5596);
    print_r($additional);
    die();
    print_r(Mage::helper('edi/status')->getStatusColorByCode('multistatus'));
    die;
    $orderItems = Mage::getModel('sales/order_item')->getCollection();
    echo $orderItems->getSelect();die();

    $types = Mage::getSingleton('edi/edicarrier_config')->getLocalServerPath();
    print_r($types);die();
    // var_dump(Mage::getSingleton('admin/session')->isAllowed('fsalesman/staff/staff_default_dashbord'));
    // die;
    $ftp = Mage::helper('core')->decrypt(Mage::getStoreConfig('ediryder/ftp/password'));
    var_dump($ftp);die;

    $observer = Mage::getModel('edi/edicarrier_ryder_observer');
    print_r($observer->getFtpConfig());
    die();
    $ftp = Mage::getStoreConfig('ediryder/ftp/password');
    $ftp = Mage::helper('core')->decrypt($ftp);
    print_r($ftp);

    die();
    $helper = Mage::helper('edi/status');

    foreach ($helper->getMapDeliveryStatuses() as $key => $value) {
        if (isset($value['client_comment_event'])) {
            foreach ($value['client_comment_event'] as $key1 => $value1) {
                echo $key.' && '.$key1.' => '.$value1['our_status'];
                echo "<br>";
            }
        }
    }

    print_r($helper->getMapCustmerStatuses());

    die();
    // echo $collection = Mage::getModel('reviewemail/reviewemail')->getCollection()->getMainTable();die();
    echo Mage::getSingleton('core/resource')->getTablename('deliveright/response');die();
    $bolModel = Mage::getModel('manufacturer/bol');
    echo $bolModel->getCollection()->getMainTable();

    die();
    $_order = Mage::getModel('sales/order')->load(244516);
    $totalMfrs              = Mage::getModel('manufacturer/order')->getOrderMfrsCollection($_order);
    $totalMfrsShipmentWiseCount = Mage::helper('manufacturer/order')->getTotalMfrShipmentCount($_order->getId());
    die();

    Mage::getModel('repricer/observer')->updateRefSelectedColumn();
    die();

    $read = Mage::getSingleton('core/resource')->getConnection('core_read');

    $columns = array(
        'WG name' => 'CSC.name',
        'Numeric Code' => 'CSC.numeric_code',
        'Priority' => 'CSC.priority',
        'Zipcode' => 'CSCZ.zipcode',
        'City' => 'CSCZ.city',
        'State' => 'CSCZ.state',
    );

    $select = $read->select()
        ->from(array('CSCZ'=>'ccc_ship_carrier_zipcode'),array())
        ->join(array('CSC' => 'ccc_ship_carrier'),"CSCZ.wg_id = CSC.id",array())
        ->columns($columns);






    echo $select;
    die();

    $banner = Mage::getModel('bannerslider/banner')->load(96);
    print_r($banner->save());

    die();
    $brandIds = array(13863,13813,13831,32353,13871,13796);
    Mage::getModel('repricer/report_price_compare')->getBrandIndexes($brandIds);

    die();

    $read = Mage::getSingleton('core/resource')->getConnection('core_read');
    $incrementIds = array(40623,60486,74437,74581,75034,76982,78115,78226,78430,81241,81789,81807,82489,8303502,8305096,8306130,8307080,8307461,8308238,8308454,8308845,8308895,8309425,8309548,8311091,8311463,8312541,8313087,8314089,8314398,8315000,8315460,8316766,8316928,8318104,8318919,8319028,8321304,8322137,8323009,8324333,8328546,8328843,8329287,8330624,8331072,8331081,8337958,8338318,8339490,8340538,8342750,8343488,8344826,8345058,8345309,8345868,8346556,8353266,8353267,8353327,8354966,8356835,8356919,8358998,8361588,8361624,8362503,8363536,8366628,8366806,8369522,8370724,8372419,8373051,8374274,8375067,8376448,8378603,8379554,8380285,8383373,8385641,8386855,8386989,8389688,8389992,8392243,8393411,8395937,8403908,8404259,8406427,8407554,8412024,8412032,8414035,8418156,8419403,8419697,8419751,8421208,8421311,8421362,8421719,8422461,8423215,8423406,8423511,8423938,8424014,8425986,8427876,8428288,8428855,8430995,8431626,8434543,8435134,8435754,8436871,8438434,8440140,8440307,8442571,8443062,8447078,8450145,8450413,8452021,8453404,8454764,8454862,8455139,8455498,8455987,8458185,8459764,8460251,8461028,8461671,8462245,8464196,8465542,8468238,8468310,8468856,8469456,8472396,8472926,8475786,8477065,8477637,8478670,8478789,8478801,8479323,8479633,8480187,8481367,8481793,8482652,8482785,8483748,8483866,8484535,8486211,8486618,8486919,8487095,8488722,8488816,8488822,8489167,8489721,8489797,8490197,8492023,8492290,8492889,8493073,8493199,8494672,8495646,8496021,8496157,8496178,8496507,8496888,8497241,8497321,8497706,8497829,8498010,8498439,8498474,8498780,8499036,8499061,8499403,8499572,8499711,8499902,8499940,8500490,8500619,8501733,8501795,8501941,8502029,8502693,8502877,8502890,8504516,8504540,8504574,8504783,8504998,8505224,8505262,8505377,8506479,8507733,8507947,8508116,8508527,8508529,8509725,8510122,8510959,8511037,8511275,8511573,8511861,8512164,8512178,8512179,8512470,8512569,8512779,8512833,8513041,8513152,8513431,8513868,8513928,8514132,8514366,8514441,8514841,8514984,8515638,8516338,8516425,8516527,8517014,8517146,8517516,8517674,8517685,8517762,8518407,8518667,8519025,8519525,8519544,8519627,8519673,8520614,8521178,8521220,8521392,8521429,8521477,8523122,8523289,8524099,8524245,8524375,8524389,8524416,8524696,8524700,8524720,8524730,8524831,8524883,8524975,8524986,8525025,8525128,8525234,8525267,8525293,8525393,8526058,8526062,8526413,8526731,8527578,8527653,8527791,8527836,8527892,8527929,8528493,8528553,8528597,8528630,8528666,8528698,8528949,8528997,8529113,8529116,8529191,8529445,8529521,8529538,8529694,8529695,8529756,8529757,8529778,8530082,8530300,8530364,8530373,8530497,8530561,8530608,8530620,8530873,8531330,8531391,8531530,8531730,8531832,8531833,8531863,8531903,8532120,8532140,8532511,8532689,8532906,8533011,8533307,8533331,8533435,8533464,8533604,8534254,8534365,8534401,8534491,8534546,8534572,8534651,8534666,8534776,8534894,8534901,8534947,8535053,8535055,8535058,8535114,8535118,8535231,8535315,8535446,8535729,8535739,8535759,8535866,8536049,8536052,8536165,8536182,8536194,8536234,8536270,8536366,8536625,8536927,8537135,8537136,8537163,8537173,8537241,8537431,8537611,8537884,8537885,8538041,8538101,8538145,8538283,8538289,8538295,8538341,8538427,8538455,8538458,8538475,8538484,8538584,8538596,8538641,8538655,8538664,8538782,8538792,8538880,8538919,8538978,8538999,8539006,8539029,8539153,8539160,8539164,8539211,8539300,8539325,8539337,8539455,8539543,8539609,8539619,8539690,8539853,8539892,8539893,8539933,8540021,8540541,8540609,8540636,8540651,8540674,8540704,8540747,8540905,8541001,8541058,8541460,8541679,8541798,8541862,8541908,8541911,8541913,8541941,8542026,8542047,8542091,8542110,8542325,8542349,8542514,8542524,8542593,8542619,8542675,8542793,8542883,8542978,8543022,8543051,8543146,8543293,8543302,8543467,8543473,8543475,8543504,8543530,8543907,8544073,8544202,8544252,8544316,8544601,8544654,8544696,8544733,8544758,8544790,8544865,8545138,8545197,8545202,8545269,8545359,8545501,8545535,8545662,8545752,8545819,8545820,8545878,8545888,8545927,8545951,8545971,8546000,8546007,8546018,8546034,8546047,8546117,8546152,8546205,8546289,8546460,8546478,8546502,8546634,8546638,8546656,8546696,8546740,8546745,8546817,8546890,8546903,8546987,8547143,8547197,8547332,8547398,8547399,8547403,8547412,8547458,8547498,8547500,8547506,8547563,8547756,8547770,8547798,8547860,8547871,8547932,8547945,8547967,8547976,8548045,8548051,8548091,8548110,8548157,8548378,8548414,8548449,8548528,8548653,8548687,8548702,8548746,8548748,8548807,8548853,8548858,8549145,8549212,8549286,8549349,8549372,8549406,8549408,8549464,8549492,8549515,8549532,8549537,8549562,8549569,8549590,8549693,8549700,8549713,8549730,8549759,8549821,8549880,8549884,8549888,8549895,8549972,8549996,8549997,8550004,8550008,8550029,8550030,8550032,8550078,8550084,8550087,8550098,8550102,8550147,8550152,8550173,8550186,8550220,8550232,8550237,8550321,8550334,8550420,8550436,8550446,8550477,8550513,8550619,8550628,8550671,8550688,8550697,8550720,8550747,8550759,8550793,8550799,8550801,8550843,8550898,8550913,8550928,8550942,8550969,8550978,8551021,8551065,8551075,8551100,8551103,8551115,8551161,8551238,8551361,8551381,8551446,8551485,8551499,8551504,8551509,8551529,8551538,8551578,8551602,8551608,8551610,8551634,8551646,8551648,8551667,8551690,8551717,8551741,8551778,8551787,8551875,8551923,8551925,8552038,8552045,8552123,8552135,8552141,8552142,8552281,8552329,8552332,8552352,8552370,8552388,8552403,8552432,8552443,8552511,8552579,8552601,8552659,8552718,8552726,8552742,8552781,8552806,8552841,8552854,8552909,8552964,8552993,8553016,8553028,8553082,8553094,8553100,8553106,8553127,8553139,8553145,8553158,8553160,8553162,8553215,8553217,8553248,8553262,8553348,8553440,8553466,8553525,8553550,8553583,8553597,8553625,8553681,8553719,8553797,8553851,8553916,8553947,8553984,8554022,8554177,8554270,8554277,8554306,8554307,8554339,8554380,8554396,8554433,8554435,8554440,8554460,8554463,8554476,8554478,8554481,8554489,8554501,8554545,8554552,8554562,8554613,8554617,8554648,8554661,8554663,8554667,8554678,8554698,8554742,8554764,8554800,8554841,8554960,8554989,8555026,8555098,8555126,8555136,8555143,8555164,8555178,8555238,8555291,8555304,8555305,8555309,8555310,8555332,8555339,8555345,8555398,8555415,8555417,8555433,8555476,8555529,8555552,8555560,8555565,8555567,8555574,8555601,8555623,8555624,8555660,8555665,8555832,8555861,8555937,8555988,8556009,8556010,8556014,8556035,8556047,8556062,8556067,8556070,8556072,8556077,8556086,8556108,8556111,8556112,8556114,8556169,8556199,8556230,8556239,8556261,8556335,8556337,8556396,8556441,8556505,8556557,8556558,8556561,8556570,8556586,8556592,8556623,8556632,8556639,8556653,8556664,8556699,8556706,8556713,8556731,8556846,8556852,8556863,8556889,8556923,8556945,8556947,8556948,8556963,8556995,8557003,8557044,8557052,8557074,8557095,8557134,8557169,8557223,8557228,8557234,8557236,8557341,8557395,8557434,8557440,8557449,8557491,8557528,8557610,8557614,8557627,8557628,8557636,8557650,8557662,8557724,8557851,8557855,8557899,8557922,8557942,8558030,8558053,8558068,8558125,8558143,8558159,8558204,8558211,8558228,8558231,8558253,8558255,8558287,8558291,8558312,8558321,8558335,8558336,8558344,8558346,8558384,8558468,8558490,8558499,8558503,8558511,8558515,8558524,8558556,8558561,8558610,8558621,8558647,8558648,8558674,8558679,8558688,8558695,8558720,8558722,8558723,8558734,8558744,8558748,8558764,8558778,8558782,8558793,8558810,8558846,8558867,8558871,8558875,8558882,8558885,8558887,8558919,8558927,8558933,8558935,8558947,8558951,8558955,8558965,8558974,8558976,8558978,8558982,8559001,8559002,8559009,8559016,8559021,8559034,8559035,8559051,8559065,8559095,8559103,8559108,8559119,8559127,8559133,8559137,8559139,8559149,8559156,8559162,8559170,8559171,8559176,8559178,8559183,8559200,8559204,8559217,8559287,8559305,8559306,8559319,8559340,8559361,8559375,8559378,8559420,8559462,8559488,8559518,8559519,8559569,8559574,8559583,8559590,8559612,8559624,8559625,8559653,8559666,8559674,8559681,8559689,8559691,8559701,8559709,8559713,8559727,8559729,8559751,8559766,8559781,8559817,8559860,8559867,8559872,8559893,8559918,8559937,8559945,8559985,8559995,8560022,8560032,8560050,8560071,8560072,8560129,8560153,8560166,8560173,8560232,8560241,8560257,8560259,8560277,8560307,8560309,8560314,8560340,8560341,8560357,8560363,8560365,8560411,8560442,8560457,8560494,8560543,8560571,8560600,8560655,8560802,8560874,8560918,8560940,8560941,8560964,8560971,8560986,8561026,8561066,8561129,8561131,8561141,8561143,8561154,8561155,8561157,8561172,8561183,8561201,8561203,8561216,8561222,8561225,8561226,8561245,8561251,8561294,8561299,8561310,8561329,8561346,8561375,8561401,8561405,8561436,8561617,8561636,8561644,8561668,8561673,8561688,8561726,8561790,8561794,8561809,8561823,8561826,8561839,8561868,8561870,8561880,8561883,8561889,8561890,8561928,8561929,8561939,8561943,8561947,8561953,8561958,8561959,8561967,8561975,8561996,8562000,8562015,8562020,8562022,8562053,8562065,8562090,8562105,8562119,8562166,8562185,8562189,8562195,8562206,8562219,8562222,8562239,8562259,8562273,8562276,8562278,8562290,8562310,8562312,8562323,8562354,8562361,8562374,8562391,8562410,8562412,8562415,8562419,8562421,8562425,8562428,8562437,8562446,8562456,8562458,8562471,8562491,8562494,8562499,8562501,8562514,8562524,8562531,8562541,8562543,8562544,8562553,8562554,8562557,8562560,8562561,8562568,8562582,8562596,8562597,8562599,8562602,8562614,8562616,8562620,8562626,8562627,8562629,8562630,8562636,8562649,8562657,8562673,8562674,8562678,8562684,8562697,8562698,8562705,8562713,8562728,8562729,8562730,8562746,8562748,8562752,8562776,8562777,8562784,8562797,8562804,8562808,8562821,8562840,8562844,8562854,8562856,8562868,8562894,8562911,8562913,8562927,8562939,8562950,8562958,8562965,8562968,8562978,8562996,8562997,8562998,8563011,8563021,8563043,8563049,8563057,8563064,8563067,8563068,8563076,8563081,8563087,8563091,8563107,8563115,8563121,8563122,8563127,8563142,8563144,8563147,8563150,8563154,8563163,8563170,8563186,8563198,8563199,8563214,8563227,8563245,8563261,8563262,8563264,8563266,8563267,8563282,8563301,8563307,8563316,8563333,8563341,8563353,8563354,8563358,8563359,8563360,8563362,8563374,8563377,8563382,8563397,8563406,8563411,8563413,8563428,8563434,8563442,8563452,8563454,8563466,8563471,8563472,8563478,8563479,8563495,8563498,8563502,8563509,8563513,8563520,8563527,8563533,8563549,8563551,8563569,8563584,8563591,8563594,8563595,8563608,8563612,8563613,8563614,8563623,8563625,8563646,8563652,8563656,8563678,8563680,8563682,8563691,8563694,8563697,8563705,8563715,8563724,8563735,8563742,8563749,8563752,8563757,8563760,8563772,8563783,8563791,8563794,8563796,8563800,8563801,8563807,8563826,8563832,8563835,8563838,8563845,8563846,8563847,8563861,8563875,8563886,8563888,8563893,8563901,8563911,8563913,8563917,8563918,8563919,8563930,8563932,8563934,8563942,8563945,8563948,8563952,8563970,8563972,8563977,8563985,8563991,8563995,8563997,8563998,8563999,8564017,8564020,8564039,8564041,8564045,8564055,8564056,8564060,8564067,8564068,8564078,8564092,8564096,8564098,8564119,8564122,8564136,8564141,8564156,8564175,8564178,8564180,8564203,8564209,8564212,8564219,8564227,8564228,8564232,8564234,8564238,8564249,8564252,8564253,8564269,8564270,8564271,8564274,8564282,8564287,8564290,8564291,8564295,8564298,8564307,8564316,8564320,8564325,8564327,8564332,8564348,8564350,8564351,8564358,8564362,8564367,8564370,8564372,8564373,8564383,8564388,8564389,8564393,8564402,8564403,8564404,8564412,8564422,8564428,8564432,8564433,8564461,8564463,8564467,8564476,8564493,8564503,8564509,8564520,8564525,8564537,8564543,8564555,8564556,8564558,8564562,8564576,8564581,8564585,8564598,8564600,8564602,8564603,8564623,8564624,8564626,8564643,8564647,8564648,8564651,8564656,8564669,8564689,8564692,8564700,8564712,8564728,8564733,8564735,8564737,8564742,8564743,8564747,8564748,8564752,8564771,8564772,8564773,8564774,8564785,8564793,8564794,8564799,8564817,8564846,8564850,8564887,8564889,8564894,8564901,8564910,8564925,8564943,8564945,8564962,8564975,8564984,8564989,8564991,8565021,8565022,8565054,8565064,8565066,8565068,8565071,8565073,8565084,8565090,8565097,8565099,8565109,8565115,8565119,8565122,8565127,8565134,8565146,8565150,8565158,8565170,8565183,8565184,8565193,8565217,8565221,8565224,8565244,8565251,8565261,8565288,8565292,8565297,8565304,8565306,8565322,8565333,8565350,8565358,8565362,8565363,8565365,8565369,8565370,8565382,8565385,8565396,8565407,8565418,8565426,8565436,8565448,8565456,8565458,8565459,8565461,8565465,8565475,8565476,8565479,8565481,8565482,8565496,8565499,8565504,8565516,8565523,8565534,8565535,8565536,8565537,8565538,8565543,8565547,8565550,8565553,8565555,8565559,8565560,8565567,8565571,8565581,8565589,8565590,8565598,8565602,8565609,8565614,8565623,8565628,8565633,8565637,8565641,8565656,8565667,8565672,8565673,8565683,8565688,8565690,8565691,8565695,8565757,8565767,8565773,8565776,8565781,8565791,8565793,8565810,8565824,8565826,8565837,8565846,8565852,8565869,8565884,8565886,8565925,8565927,8565928,8565929,8565933,8565939,8565948,8565966,8565982,8565990,8566032,8566049,8566066,8566072,8566084,8566091,8566130,8566141,8566155,8566159,8566195,8566198,8566203,8566222,8566240,8566243,8566252,8566258,8566269,8566300,8566302,8566313,8566335,8566360,8566362,8566364,8566366,8566374,8566382,8566386,8566395,8566406,8566408,8566416,8566430,8566441,8566449,8566464,8566468,8566470,8566475,8566517,8566525,8566527,8566535,8566537,8566539,8566542,8566543,8566562,8566564,8566565,8566569,8566571,8566581,8566591,8566593,8566596,8566598,8566604,8566609,8566612,8566613,8566620,8566621,8566626,8566629,8566640,8566642,8566644,8566649,8566653,8566656,8566658,8566662,8566667,8566683,8566687,8566713,8566716,8566718,8566748,8566753,8566762,8566772,8566780,8566794,8566814,8566819,8566825,8566827,8566833,8566837,8566839,8566841,8566852,8566853,8566870,8566874,8566875,8566886,8566889,8566894,8566910,8566917,8566932,8566937,8566948,8566949,8566956,8566959,8566960,8566963,8566969,8566978,8566979,8566988,8566991,8567002,8567006,8567007,8567013,8567017,8567018,8567019,8567039,8567043,8567048,8567050,8567059,8567068,8567074,8567082,8567083,8567090,8567091,8567092,8567094,8567096,8567097,8567098,8567100,8567105,8567114,8567116,8567137,8567147,8567157,8567158,8567161,8567165,8567168,8567169,8567173,8567180,8567184,8567187,8567192,8567221,8567226,8567230,8567238,8567248,8567252,8567264,8567273,8567301,8567319,8567335,8567354,8567389,8567409,8567416,8567422,8567423,8567425,8567487,8567533,8567544,8567570,8567612,8567688,8567692,8567709,8567736,8567740,8567741,8567869,8567881,8567889,8567925,8567938,8567973,8568051,8568083,8568165,8568195,8568201,8568210,8568218,8568228,8568236,8568237,8568243,8568253,8568255,8568257,8568273,8568274,8568278,8568280,8568281,8568282,8568284,8568293,8568305,8568315,8568319,8568320,8568322,8568325,8568351,8568376,8568380,8568395,8568426,8568456,8568490,8568498,8568552,8568555,8568588,8568594,8568618,8568657,8568678,8568686,8568695,8568701,8568705,8568739,8568800,8568806,8568816,8568835,8568899,8568913,8568932,8568971,8569030,8569044,8569102,8569106,8569107,8569120,8569126,8569131,8569156,8569170,8569206,8569226,8569240,8569250,8569253,8569265,8569269,8569273,8569279,8569285,8569295,8569299,8569334,8569336,8569342,8569354,8569355,8569363,8569382,8569387,8569388,8569393,8569399,8569418,8569432,8569439,8569457,8569460,8569493,8569498,8569500,8569507,8569534,8569559,8569589,8569617,8569628,8569650,8569655,8569732,8569807,8569813,8569816,8569839,8569882,8569941,8569947,8569948,8569956,8569979,8570006,8570019,8570038,8570062,8570098,8570107,8570116,8570144,8570154,8570162,8570163,8570187,8570205,8570240,8570254,8570262,8570266,8570293,8570324,8570346,8570354,8570356,8570398,8570422,8570440,8570442,8570463,8570467,8570469,8570471,8570494,8570499,8570513,8570522,8570536,8570553,8570556,8570568,8570575,8570581,8570603,8570609,8570622,8570627,8570629,8570647,8570657,8570672,8570701,8570767,8570768,8570776,8570777,8570791,8570801,8570824,8570827,8570852,8570885,8570887,8570930,8570956,8570959,8570964,8570981,8570986,8570992,8571004,8571022,8571030,8571054,8571071,8571088,8571098,8571100,8571101,8571109,8571110,8571111,8571120,8571122,8571141,8571152,8571153,8571158,8571167,8571174,8571182,8571185,8571196,8571198,8571199,8571202,8571203,8571212,8571213,8571214,8571219,8571229,8571231,8571248,8571256,8571258,8571259,8571261,8571289,8571303,8571326,8571332,8571333,8571363,8571365,8571366,8571371,8571374,8571382,8571390,8571401,8571403,8571409,8571410,8571412,8571421,8571422,8571436,8571442,8571444,8571453,8571456,8571466,8571471,8571473,8571475,8571482,8571488,8571490,8571498,8571506,8571513,8571516,8571526,8571529,8571538,8571539,8571543,8571547,8571553,8571557,8571561,8571568,8571573,8571605,8571609,8571613,8571651,8571657,8571659,8571677,8571683,8571688,8571722,8571754,8571768,8571772,8571778,8571790,8571833,8571857,8571858,8571863,8571867,8571871,8571915,8571918,8571926,8571934,8571947,8571970,8571977,8571983,8571987,8571989,8571992,8572040,8572051,8572060,8572062,8572066,8572077,8572086,8572099,8572102,8572109,8572124,8572140,8572141,8572163,8572165,8572176,8572187,8572205,8572216,8572220,8572234,8572236,8572239,8572240,8572261,8572270,8572276,8572286,8572296,8572300,8572301,8572302,8572304,8572312,8572319,8572342,8572358,8572364,8572398,8572407,8572429,8572459,8572470,8572473,8572487,8572489,8572490,8572503,8572528,8572536,8572554,8572562,8572566,8572575,8572652,8572672,8572686,8572690,8572732,8572747,8572759,8572776,8572830,8572866,8572905,8572931,8572961,8572962,8572979,8572983,8573016,8573017,8573046,8573063,8573068,8573085,8573102,8573103,8573124,8573136,8573141,8573143,8573146,8573147,8573160,8573171,8573172,8573174,8573208,8573218,8573226,8573247,8573282,8573288,8573296,8573330,8573339,8573362,8573380,8573410,8573422,8573458,8573467,8573478,8573497,8573517,8573520,8573559,8573572,8573586,8573597,8573605,8573606,8573654,8573685,8573700,8573711,8573714,8573717,8573723,8573738,8573739,8573752,8573773,8573777,8573782,8573804,8573814,8573834,8573838,8573846,8573860,8573865,8573868,8573876,8573877,8573885,8573889,8573892,8573899,8573920,8573922,8573931,8573942,8573954,8573956,8573959,8573974,8573994,8574005,8574008,8574022,8574040,8574044,8574071,8574081,8574133,8574143,8574146,8574155,8574157,8574158,8574173,8574200,8574223,8574240,8574277,8574302,8574307,8574332,8574401,8574427,8574431,8574433,8574442,8574450,8574459,8574488,8574528,8574538,8574539,8574542,8574543,8574548,8574561,8574563,8574564,8574565,8574566,8574574,8574575,8574580,8574581,8574597,8574608,8574611,8574617,8574618,8574620,8574627,8574641,8574642,8574645,8574656,8574659,8574663,8574665,8574670,8574679,8574685,8574700,8574727,8574733,8574737,8574739,8574745,8574758,8574775,8574786,8574796,8574802,8574807,8574818,8574840,8574847,8574848,8574858,8574876,8574886,8574900,8574974,8574980,8574984,8574989,8575001,8575003,8575015,8575040,8575061,8575084,8575087,8575088,8575093,8575102,8575119,8575139,8575161,8575179,8575198,8575210,8575214,8575219,8575231,8575235,8575239,8575245,8575247,8575255,8575261,8575290,8575298,8575323,8575326,8575332,8575349,8575359,8575366,8575377,8575408,8575420,8575421,8575432,8575443,8575458,8575461,8575463,8575465,8575469,8575470,8575473,8575474,8575490,8575505,8575525,8575530,8575556,8575563,8575587,8575593,8575599,8575606,8575622,8575624,8575627,8575628,8575636,8575640,8575643,8575644,8575647,8575648,8575653,8575654,8575663,8575669,8575674,8575677,8575684,8575685,8575690,8575693,8575699,8575703,8575714,8575715,8575723,8575731,8575733,8575742,8575748,8575751,8575755,8575757,8575759,8575760,8575761,8575764,8575765,8575766,8575769,8575776,8575780,8575781,8575783,8575793,8575794,8575805,8575814,8575815,8575819,8575820,8575822,8575824,8575826,8575830,8575839,8575848,8575852,8575854,8575857,8575858,8575862,8575865,8575866,8575871,8575877,8575892,8575896,8575910,8575913,8575929,8575946,8575951,8575964,8575968,8575970,8575984,8575986,8575988,8575994,8575997,8575999,8576007,8576013,8576022,8576028,8576034,8576036,8576037,8576072,8576084,8576085,8576086,8576087,8576092,8576098,8576100,8576101,8576112,8576116,8576117,8576122,8576125,8576127,8576128,8576138,8576143,8576155,8576172,8576177,8576193,8576194,8576198,8576206,8576214,8576217,8576219,8576224,8576227,8576229,8576230,8576231,8576233,8576238,8576247,8576249,8576251,8576257,8576258,8576287,8576295,8576299,8576301,8576303,8576321,8576326,8576327,8576332,8576340,8576346,8576354,8576361,8576365,8576366,8576369,8576373,8576377,8576386,8576395,8576398,8576404,8576426,8576436,8576455,8576461,8576489,8576534,8576535,8576613,8576617,8576618,8576623,8576630,8576637,8576649,8576676,8576682,8576683,8576708,8576744,8576760,8576764,8576788,8576790,8576794,8576802,8576812,8576814,8576822,8576832,8576835,8576842,8576858,8576899,8576909,8576926,8576927,8576931,8576935,8576938,8576941,8576946,8576956,8576960,8576966,8576968,8576969,8576980,8576981,8576993,8577002,8577004,8577005,8577006,8577008,8577009,8577017,8577018,8577022,8577023,8577025,8577028,8577033,8577036,8577037,8577047,8577050,8577058,8577068,8577094,8577114,8577117,8577124,8577134,8577168,8577173,8577192,8577194,8577236,8577247,8577250,8577261,8577268,8577281,8577309,8577324,8577379,8577412,8577441,8577457,8577471,8577472,8577474,8577483,8577492,8577494,8577495,8577507,8577510,8577511,8577512,8577514,8577517,8577530,8577537,8577538,8577541,8577568,8577569,8577585,8577591,8577592,8577594,8577595,8577596,8577601,8577617,8577621,8577628,8577630,8577646,8577648,8577655,8577676,8577683,8577695,8577780,8577792,8577794,8577795,8577802,8577816,8577850,8577854,8577880,8577898,8577911,8577945,8577958,8578007,8578008,8578009,8578049,8578057,8578060,8578062,8578077,8578080,8578083,8578093,8578117,8578119,8578132,8578140,8578142,8578144,8578150,8578153,8578157,8578159,8578162,8578166,8578171,8578186,8578191,8578199,8578202,8578203,8578206,8578220,8578249,8578260,8578274,8578276,8578291,8578294,8578307,8578326,8578415,8578431,8578450,8578452,8578454,8578456,8578479,8578485,8578508,8578528,8578538,8578541,8578544,8578546,8578548,8578570,8578577,8578590,8578596,8578597,8578605,8578620,8578623,8578624,8578625,8578628,8578633,8578635,8578656,8578657,8578660,8578687,8578704,8578710,8578711,8578716,8578720,8578729,8578730,8578742,8578746,8578749,8578750,8578753,8578761,8578764,8578770,8578771,8578773,8578789,8578803,8578810,8578812,8578824,8578834,8578846,8578896,8578898,8578909,8578916,8578921,8578937,8578954,8578955,8578976,8578978,8578984,8579005,8579018,8579019,8579026,8579053,8579078,8579087,8579093,8579096,8579102,8579108,8579110,8579125,8579128,8579131,8579133,8579146,8579153,8579162,8579166,8579167,8579185,8579198,8579211,8579224,8579228,8579229,8579232,8579243,8579247,8579248,8579249,8579251,8579252,8579258,8579268,8579270,8579274,8579276,8579277,8579288,8579289,8579294,8579299,8579302,8579307,8579316,8579341,8579353,8579354,8579355,8579372,8579376,8579378,8579380,8579381,8579389,8579391,8579393,8579395,8579400,8579422,8579426,8579432,8579441,8579442,8579444,8579446,8579447,8579452,8579453,8579460,8579461,8579463,8579474,8579475,8579478,8579483,8579484,8579487,8579489,8579499,8579500,8579505,8579509,8579511,8579512,8579531,8579532,8579541,8579547,8579548,8579555,8579559,8579562,8579565,8579568,8579571,8579575,8579577,8579584,8579593,8579595,8579601,8579607,8579612,8579624,8579626,8579629,8579631,8579644,8579650,8579653,8579655,8579659,8579662,8579671,8579687,8579689,8579699,8579705,8579708,8579723,8579725,8579742,8579748,8579750,8579754,8579761,8579762,8579766,8579784,8579785,8579786,8579790,8579792,8579793,8579794,8579797,8579798,8579811,8579812,8579814,8579816,8579822,8579833,8579835,8579838,8579840,8579850,8579856,8579860,8579880,8579892,8579916,8579920,8579923,8579945,8579946,8579958,8579985,8579987,8580004,8580007,8580014,8580045,8580080,8580087,8580095,8580112,8580132,8580139,8580160,8580182,8580218,8580250,8580255,8580256,8580264,8580271,8580281,8580310,8580312,8580329,8580331,8580338,8580339,8580341,8580343,8580350,8580356,8580359,8580361,8580364,8580366,8580370,8580373,8580374,8580377,8580383,8580392,8580393,8580394,8580398,8580399,8580402,8580406,8580407,8580410,8580412,8580422,8580428,8580430,8580431,8580447,8580449,8580455,8580456,8580457,8580460,8580464,8580485,8580486,8580491,8580502,8580504,8580515,8580520,8580524,8580542,8580547,8580561,8580562,8580570,8580602,8580606,8580612,8580615,8580621,8580641,8580673,8580693,8580716,8580738,8580754,8580768,8580773,8580774,8580795,8580798,8580802,8580814,8580825,8580827,8580828,8580832,8580834,8580838,8580841,8580843,8580847,8580851,8580852,8580854,8580857,8580860,8580861,8580865,8580868,8580882,8580887,8580888,8580899,8580900,8580904,8580908,8580911,8580912,8580924,8580939,8580940,8580945,8580947,8580953,8580970,8580975,8580976,8580978,8580982,8580985,8580997,8581002,8581003,8581004,8581009,8581010,8581013,8581019,8581021,8581022,8581028,8581030,8581037,8581045,8581046,8581051,8581053,8581059,8581061,8581064,8581068,8581070,8581075,8581081,8581084,8581089,8581094,8581104,8581111,8581112,8581113,8581144,8581151,8581165,8581178,8581185,8581194,8581213,8581228,8581232,8581249,8581254,8581258,8581263,8581295,8581312,8581317,8581327,8581349,8581376,8581386,8581389,8581399,8581409,8581414,8581415,8581416,8581419,8581441,8581446,8581455,8581470,8581474,8581483,8581494,8581506,8581509,8581519,8581523,8581525,8581529,8581531,8581534,8581537,8581540,8581541,8581552,8581561,8581562,8581569,8581584,8581602,8581603,8581604,8581618,8581620,8581624,8581625,8581626,8581633,8581634,8581635,8581637,8581640,8581648,8581653,8581658,8581659,8581661,8581673,8581680,8581685,8581690,8581691,8581699,8581701,8581704,8581708,8581716,8581721,8581723,8581726,8581729,8581731,8581732,8581736,8581737,8581740,8581744,8581747,8581751,8581752,8581756,8581760,8581763,8581764,8581783,8581795,8581802,8581807,8581809,8581818,8581824,8581829,8581843,8581858,8581878,8581884,8581892,8581898,8581905,8581916,8581918,8581926,8581932,8581935,8581939,8581940,8581952,8581956,8581963,8581964,8581966,8581967,8581968,8581969,8581971,8581973,8581978,8582022,8582027,8582041,8582047,8582064,8582071,8582074,8582075,8582078,8582096,8582104,8582105,8582108,8582109,8582111,8582112,8582119,8582123,8582137,8582147,8582153,8582154,8582157,8582158,8582160,8582161,8582170,8582174,8582176,8582181,8582187,8582190,8582196,8582200,8582201,8582202,8582204,8582208,8582210,8582214,8582215,8582219,8582221,8582225,8582241,8582252,8582256,8582258,8582261,8582267,8582268,8582270,8582273,8582274,8582280,8582286,8582291,8582296,8582297,8582302,8582305,8582307,8582310,8582311,8582319,8582331,8582338,8582342,8582344,8582350,8582352,8582354,8582366,8582369,8582370,8582372,8582383,8582391,8582404,8582405,8582425,8582428,8582429,8582431,8582433,8582438,8582441,8582442,8582445,8582456,8582469,8582471,8582473,8582477,8582478,8582484,8582485,8582489,8582496,8582502,8582510,8582512,8582514,8582515,8582516,8582520,8582522,8582527,8582537,8582540,8582550,8582551,8582552,8582553,8582559,8582570,8582580,8582583,8582586,8582587,8582588,8582591,8582606,8582608,8582619,8582628,8582635,8582636,8582637,8582642,8582644,8582650,8582655,8582658,8582667,8582670,8582679,8582688,8582692,8582694,8582696,8582700,8582714,8582723,8582732,8582734,8582736,8582746,8582750,8582768,8582777,8582784,8582786,8582787,8582790,8582795,8582797,8582799,8582802,8582808,8582811,8582820,8582824,8582830,8582836,8582839,8582848,8582861,8582862,8582864,8582865,8582866,8582871,8582875,8582890,8582891,8582894,8582908,8582913,8582921,8582923,8582925,8582928,8582930,8582951,8582952,8582953,8582961,8582963,8582966,8582968,8582969,8582978,8582981,8582983,8582986,8582991,8582994,8583003,8583016,8583017,8583018,8583020,8583022,8583025,8583032,8583033,8583037,8583040,8583041,8583045,8583048,8583058,8583062,8583063,8583066,8583067,8583070,8583077,8583078,8583085,8583091,8583100,8583101,8583103,8583122,8583123,8583127,8583136,8583141,8583148,8583153,8583158,8583174,8583175,8583176,8583187,8583207,8583214,8583224,8583226,8583227,8583237,8583251,8583255,8583300,8583305,8583319,8583333,8583338,8583339,8583345,8583353,8583371,8583375,8583377,8583390,8583392,8583402,8583408,8583409,8583417,8583418,8583423,8583428,8583436,8583444,8583445,8583453,8583486,8583487,8583504,8583505,8583509,8583514,8583523,8583524,8583525,8583526,8583528,8583532,8583543,8583545,8583546,8583547,8583551,8583559,8583564,8583567,8583579,8583580,8583584,8583588,8583595,8583597,8583603,8583607,8583610,8583614,8583626,8583631,8583633,8583642,8583645,8583646,8583647,8583648,8583657,8583660,8583667,8583671,8583673,8583678,8583685,8583686,8583687,8583689,8583691,8583705,8583709,8583710,8583713,8583715,8583719,8583731,8583739,8583743,8583746,8583748,8583754,8583755,8583756,8583765,8583767,8583770,8583773,8583776,8583784,8583797,8583799,8583806,8583807,8583852,8583857,8583865,8583874,8583876,8583936,8583954,8583956,8583968,8584013,8584017,8584020,8584051,8584064,8584066,8584084,8584085,8584087,8584090,8584093,8584103,8584109,8584112,8584114,8584153,8584161,8584181,8584185,8584196,8584200,8584206,8584207,8584217,8584232,8584246,8584270,8584272,8584296,8584302,8584306,8584307,8584312,8584325,8584327,8584329,8584333,8584348,8584349,8584351,8584352,8584355,8584359,8584364,8584370,8584371,8584380,8584381,8584384,8584388,8584391,8584394,8584395,8584399,8584400,8584401,8584404,8584406,8584407,8584410,8584414,8584423,8584425,8584432,8584437,8584438,8584442,8584451,8584453,8584457,8584460,8584462,8584470,8584476,8584482,8584483,8584484,8584485,8584489,8584490,8584504,8584508,8584510,8584513,8584525,8584526,8584528,8584533,8584538,8584544,8584546,8584547,8584549,8584555,8584557,8584561,8584562,8584565,8584568,8584572,8584578,8584617,8584621,8584622,8584623,8584628,8584630,8584632,8584635,8584636,8584643,8584648,8584651,8584652,8584658,8584664,8584680,8584689,8584694,8584704,8584715,8584717,8584721,8584723,8584726,8584730,8584731,8584732,8584736,8584737,8584738,8584742,8584753,8584756,8584779,8584783,8584784,8584785,8584795,8584800,8584807,8584812,8584820,8584821,8584832,8584833,8584836,8584837,8584840,8584848,8584851,8584852,8584860,8584861,8584864,8584873,8584875,8584880,8584891,8584893,8584894,8584898,8584899,8584903,8584910,8584913,8584929,8584933,8584941,8584943,8584945,8584951,8584957,8584976,8584988,8584992,8585009,8585040,8585059,8585073,8585107,8585126,8585133,8585144,8585176,8585208,8585210,8585212,8585220,8585229,8585244,8585280,8585297,8585325,8585355,8585387,8585388,8585393,8585397,8585400,8585409,8585410,8585414,8585415,8585446,8585453,8585458,8585471,8585503,8585529,8585531,8585544,8585547,8585553,8585555,8585586,8585599,8585604,8585617,8585623,8585626,8585629,8585637,8585642,8585645,8585664,8585671,8585674,8585678,8585679,8585689,8585695,8585696,8585707,8585709,8585717,8585719,8585755,8585758,8585772,8585788,8585803,8585828,8585829,8585836,8585847,8585856,8585859,8585861,8585869,8585872,8585874,8585883,8585959,8585981,8586015,8586017,8586020,8586026,8586027,8586028,8586045,8586049,8586054,8586057,8586066,8586069,8586080,8586095,8586105,8586108,8586125,8586128,8586129,8586130,8586133,8586134,8586138,8586140,8586142,8586146,8586148,8586149,8586152,8586164,8586167,8586179,8586180,8586185,8586190,8586193,8586196,8586198,8586208,8586210,8586211,8586218,8586226,8586231,8586233,8586236,8586241,8586248,8586259,8586267,8586271,8586273,8586280,8586294,8586299,8586303,8586306,8586310,8586312,8586313,8586314,8586316,8586320,8586322,8586324,8586326,8586328,8586335,8586338,8586341,8586345,8586349,8586350,8586351,8586356,8586358,8586362,8586364,8586368,8586373,8586376,8586385,8586393,8586399,8586402,8586404,8586405,8586409,8586416,8586418,8586422,8586426,8586434,8586439,8586442,8586445,8586446,8586447,8586449,8586450,8586457,8586461,8586465,8586473,8586478,8586479,8586489,8586491,8586495,8586498,8586501,8586511,8586515,8586519,8586524,8586530,8586531,8586533,8586536,8586538,8586543,8586551,8586554,8586555,8586556,8586559,8586560,8586561,8586571,8586572,8586579,8586581,8586583,8586584,8586586,8586590,8586591,8586615,8586617,8586619,8586620,8586622,8586623,8586625,8586627,8586629,8586632,8586640,8586645,8586646,8586657,8586661,8586665,8586666,8586667,8586669,8586690,8586701,8586720,8586723,8586731,8586732,8586737,8586749,8586750,8586755,8586764,8586783,8586784,8586790,8586793,8586794,8586799,8586800,8586810,8586813,8586815,8586819,8586821,8586830,8586831,8586834,8586835,8586842,8586852,8586854,8586855,8586858,8586868,8586870,8586872,8586875,8586894,8586896,8586902,8586910,8586911,8586915,8586918,8586924,8586925,8586934,8586941,8586942,8586945,8586952,8586955,8586957,8586962,8586963,8586969,8586973,8586979,8586986,8586987,8586993,8586995,8587000,8587007,8587009,8587013,8587014,8587026,8587028,8587031,8587033,8587037,8587040,8587047,8587048,8587049,8587052,8587053,8587055,8587065,8587068,8587070,8587072,8587077,8587079,8587080,8587081,8587083,8587086,8587096,8587098,8587103,8587106,8587108,8587113,8587116,8587117,8587122,8587125,8587135,8587137,8587143,8587145,8587150,8587151,8587154,8587155,8587156,8587157,8587175,8587181,8587182,8587191,8587194,8587196,8587203,8587208,8587251,8587265,8587292,8587301,8587317,8587330,8587336,8587351,8587355,8587377,8587390,8587400,8587413,8587419,8587442,8587473,8587499,8587503,8587521,8587547,8587551,8587585,8587586,8587617,8587628,8587631,8587650,8587659,8587683,8587703,8587723,8587734,8587768,8587772,8587781,8587784,8587787,8587811,8587836,8587842,8587865,8587893,8587894,8587900,8587907,8587908,8587909,8587920,8587946,8587949,8587951,8587952,8587955,8587957,8587958,8587984,8587989,8587999,8588002,8588015,8588019,8588031,8588035,8588038,8588039,8588044,8588049,8588052,8588060,8588066,8588070,8588075,8588076,8588078,8588081,8588087,8588089,8588090,8588091,8588097,8588100,8588103,8588107,8588108,8588110,8588152,8588160,8588165,8588194,8588207,8588208,8588211,8588215,8588224,8588227,8588273,8588287,8588291,8588316,8588352,8588365,8588405,8588456,8588472,8588484,8588492,8588493,8588559,8588569,8588594,8588625,8588633,8588642,8588643,8588662,8588674,8588687,8588699,8588718,8588801,8588808,8588817,8588825,8588847,8588849,8588883,8588885,8588896,8588907,8588916,8588929,8588940,8588944,8588952,8588956,8588960,8588961,8588967,8588970,8588973,8588978,8588984,8588988,8588989,8588990,8588993,8588994,8589000,8589008,8589024,8589025,8589032,8589035,8589044,8589063,8589076,8589106,8589110,8589113,8589150,8589171,8589178,8589196,8589212,8589221,8589229,8589246,8589257,8589283,8589293,8589358,8589378,8589399,8589401,8589409,8589412,8589419,8589439,8589448,8589457,8589458,8589459,8589488,8589489,8589491,8589508,8589515,8589533,8589541,8589547,8589550,8589575,8589608,8589609,8589624,8589648,8589661,8589667,8589673,8589682,8589686,8589689,8589691,8589694,8589704,8589727,8589737,8589743,8589747,8589749,8589752,8589768,8589781,8589784,8589789,8589791,8589796,8589797,8589799,8589806,8589810,8589811,8589812,8589818,8589820,8589833,8589841,8589848,8589860,8589865,8589886,8589891,8589896,8589903,8589908,8589910,8589924,8589971,8590013,8590023,8590072,8590076,8590082,8590121,8590127,8590147,8590149,8590150,8590183,8590205,8590252,8590255,8590263,8590271,8590282,8590294,8590324,8590376,8590383,8590388,8590391,8590395,8590402,8590427,8590434,8590435,8590437,8590440,8590454,8590459,8590461,8590465,8590468,8590479,8590482,8590488,8590503,8590513,8590515,8590520,8590525,8590528,8590540,8590555,8590558,8590570,8590585,8590627,8590632,8590644,8590649,8590650,8590654,8590656,8590659,8590664,8590671,8590741,8590791,8590797,8590812,8590818,8590820,8590824,8590833,8590837,8590850,8590852,8590858,8590873,8590876,8590879,8590886,8590893,8590895,8590896,8590903,8590904,8590906,8590913,8590918,8590928,8590931,8590937,8590939,8590947,8590948,8590954,8590955,8590956,8590960,8590966,8590982,8590986,8590993,8590997,8591001,8591005,8591006,8591010,8591011,8591015,8591016,8591019,8591020,8591027,8591033,8591037,8591043,8591044,8591048,8591055,8591083,8591094,8591095,8591100,8591101,8591102,8591117,8591119,8591121,8591128,8591129,8591130,8591132,8591137,8591143,8591148,8591150,8591151,8591153,8591156,8591161,8591165,8591170,8591172,8591173,8591182,8591198,8591200,8591203,8591209,8591210,8591215,8591218,8591223,8591228,8591231,8591237,8591238,8591240,8591242,8591246,8591249,8591252,8591263,8591264,8591270,8591272,8591273,8591275,8591276,8591281,8591282,8591285,8591307,8591310,8591311,8591313,8591318,8591322,8591329,8591330,8591333,8591348,8591349,8591350,8591358,8591367,8591402,8591403,8591406,8591413,8591415,8591440,8591450,8591451,8591456,8591472,8591487,8591490,8591494,8591496,8591504,8591507,8591508,8591512,8591521,8591523,8591525,8591526,8591528,8591534,8591536,8591538,8591545,8591547,8591555,8591566,8591568,8591585,8591591,8591595,8591630,8591634,8591640,8591644,8591647,8591662,8591663,8591664,8591671,8591678,8591679,8591691,8591712,8591722,8591725,8591733,8591735,8591745,8591764,8591766,8591769,8591783,8591801,8591802,8591803,8591810,8591814,8591816,8591819,8591822,8591823,8591825,8591827,8591831,8591835,8591841,8591859,8591860,8591861,8591862,8591870,8591871,8591874,8591886,8591897,8591899,8591901,8591910,8591913,8591951,8591970,8591979,8591986,8591995,8592008,8592013,8592045,8592069,8592096,8592097,8592111,8592118,8592137,8592155,8592159,8592167,8592173,8592174,8592178,8592180,8592191,8592225,8592227,8592232,8592246,8592255,8592256,8592260,8592261,8592268,8592270,8592272,8592286,8592299,8592302,8592338,8592343,8592352,8592366,8592382,8592404,8592413,8592416,8592418,8592420,8592425,8592492,8592502,8592523,8592526,8592544,8592574,8592575,8592576,8592577,8592581,8592583,8592607,8592608,8592616,8592620,8592631,8592655,8592658,8592668,8592677,8592679,8592691,8592694,8592700,8592704,8592709,8592712,8592715,8592719,8592723,8592728,8592729,8592730,8592740,8592745,8592751,8592752,8592787,8592796,8592798,8592799,8592805,8592815,8592816,8592819,8592830,8592885,8592899,8592915,8592916,8592918,8592919,8592936,8592948,8592972,8592997,8593003,8593004,8593015,8593020,8593023,8593094,8593098,8593113,8593155,8593186,8593195,8593196,8593221,8593233,8593236,8593243,8593247,8593255,8593256,8593270,8593273,8593278,8593283,8593302,8593312,8593314,8593315,8593318,8593324,8593326,8593327,8593329,8593330,8593331,8593338,8593356,8593357,8593365,8593367,8593372,8593383,8593384,8593386,8593390,8593412,8593420,8593422,8593428,8593430,8593431,8593432,8593437,8593443,8593449,8593511,8593513,8593525,8593534,8593567,8593569,8593575,8593576,8593582,8593587,8593601,8593605,8593613,8593627,8593629,8593651,8593652,8593654,8593667,8593671,8593700,8593709,8593713,8593718,8593723,8593732,8593740,8593748,8593768,8593776,8593781,8593792,8593800,8593823,8593846,8593858,8593895,8593896,8593923,8593943,8593997,8594000,8594005,8594011,8594012,8594019,8594025,8594046,8594071,8594072,8594073,8594079,8594081,8594100,8594107,8594120,8594124,8594127,8594129,8594133,8594139,8594140,8594142,8594160,8594164,8594169,8594174,8594177,8594182,8594213,8594218,8594223,8594232,8594238,8594239,8594240,8594242,8594244,8594264,8594266,8594274,8594289,8594293,8594309,8594323,8594336,8594340,8594352,8594375,8594401,8594409,8594420,8594422,8594430,8594463,8594467,8594475,8594501,8594509,8594523,8594533,8594547,8594562,8594563,8594577,8594578,8594580,8594584,8594602,8594619,8594637,8594649,8594654,8594657,8594664,8594674,8594675,8594676,8594693,8594696,8594725,8594730,8594736,8594746,8594751,8594753,8594761,8594770,8594787,8594790,8594792,8594809,8594818,8594823,8594827,8594833,8594836,8594842,8594846,8594860,8594863,8594884,8594901,8594907,8594916,8594917,8594930,8594948,8594956,8594958,8594965,8594970,8594983,8594984,8594986,8594987,8595000,8595003,8595008,8595014,8595019,8595021,8595032,8595037,8595040,8595047,8595048,8595050,8595057,8595060,8595069,8595070,8595078,8595086,8595087,8595091,8595092,8595093,8595098,8595099,8595106,8595108,8595109,8595110,8595116,8595119,8595126,8595131,8595135,8595136,8595140,8595142,8595156,8595158,8595166,8595167,8595172,8595189,8595194,8595199,8595200,8595209,8595211,8595214,8595218,8595222,8595223,8595224,8595236,8595245,8595265,8595270,8595271,8595273,8595280,8595281,8595282,8595283,8595287,8595291,8595296,8595297,8595299,8595301,8595303,8595310,8595312,8595322,8595323,8595324,8595325,8595327,8595329,8595337,8595338,8595342,8595344,8595355,8595356,8595358,8595359,8595363,8595369,8595374,8595375,8595378,8595380,8595393,8595398,8595403,8595414,8595423,8595428,8595431,8595432,8595434,8595438,8595443,8595445,8595451,8595452,8595453,8595457,8595459,8595463,8595464,8595465,8595467,8595477,8595478,8595489,8595493,8595494,8595504,8595506,8595524,8595534,8595542,8595543,8595545,8595550,8595560,8595562,8595563,8595566,8595569,8595571,8595572,8595575,8595580,8595589,8595592,8595594,8595614,8595616,8595622,8595627,8595631,8595642,8595644,8595647,8595648,8595655,8595672,8595673,8595676,8595678,8595685,8595690,8595694,8595696,8595697,8595698,8595704,8595712,8595728,8595730,8595733,8595735,8595738,8595744,8595755,8595759,8595760,8595767,8595768,8595785,8595786,8595790,8595813,8595817,8595820,8595826,8595839,8595840,8595843,8595849,8595864,8595868,8595877,8595878,8595893,8595901,8595926,8595937,8595939,8595943,8595945,8595947,8595951,8595967,8595974,8595982,8595994,8595996,8596006,8596007,8596009,8596020,8596027,8596028,8596030,8596032,8596039,8596042,8596045,8596046,8596053,8596055,8596060,8596063,8596065,8596066,8596067,8596070,8596077,8596082,8596084,8596085,8596087,8596090,8596098,8596101,8596119,8596125,8596135,8596168,8596179,8596191,8596195,8596217,8596220,8596224,8596229,8596233,8596243,8596258,8596264,8596269,8596270,8596277,8596288,8596292,8596312,8596313,8596314,8596315,8596326,8596336,8596343,8596352,8596353,8596355,8596357,8596365,8596367,8596370,8596377,8596392,8596414,8596417,8596418,8596421,8596423,8596425,8596431,8596443,8596444,8596452,8596462,8596467,8596470,8596473,8596476,8596477,8596478,8596488,8596494,8596499,8596503,8596508,8596511,8596518,8596521,8596533,8596545,8596550,8596554,8596565,8596569,8596572,8596579,8596584,8596586,8596591,8596600,8596615,8596619,8596620,8596621,8596622,8596635,8596644,8596649,8596660,8596662,8596674,8596681,8596685,8596688,8596699,8596701,8596702,8596704,8596717,8596721,8596724,8596729,8596735,8596738,8596741,8596744,8596747,8596755,8596756,8596763,8596764,8596772,8596773,8596780,8596783,8596799,8596811,8596814,8596819,8596822,8596829,8596841,8596850,8596852,8596865,8596869,8596870,8596872,8596890,8596893,8596894,8596895,8596898,8596900,8596905,8596906,8596907,8596908,8596909,8596915,8596931,8596932,8596934,8596937,8596940,8596953,8596964,8596985,8596989,8596991,8597002,8597022,8597031,8597036,8597055,8597066,8597068,8597078,8597079,8597099,8597139,8597156,8597162,8597163,8597164,8597166,8597170,8597213,8597248,8597261,8597265,8597287,8597302,8597320,8597328,8597336,8597357,8597365,8597371,8597380,8597382,8597416,8597426,8597434,8597442,8597467,8597480,8597486,8597488,8597493,8597505,8597514,8597515,8597516,8597517,8597519,8597525,8597527,8597534,8597542,8597545,8597546,8597547,8597548,8597555,8597563,8597574,8597580,8597585,8597589,8597598,8597599,8597605,8597606,8597613,8597615,8597626,8597632,8597638,8597641,8597653,8597676,8597678,8597679,8597681,8597682,8597689,8597691,8597715,8597719,8597722,8597726,8597729,8597748,8597757,8597761,8597772,8597785,8597790,8597799,8597803,8597805,8597813,8597825,8597836,8597842,8597850,8597865,8597866,8597886,8597890,8597904,8597921,8597926,8597947,8597952,8597968,8597969,8597972,8598009,8598033,8598057,8598115,8598153,8598168,8598170,8598185,8598221,8598222,8598225,8598237,8598249,8598262,8598269,8598289,8598296,8598300,8598307,8598308,8598338,8598340,8598347,8598362,8598369,8598370,8598386,8598394,8598404,8598410,8598417,8598421,8598422,8598425,8598426,8598433,8598435,8598442,8598456,8598461,8598465,8598474,8598480,8598493,8598497,8598498,8598501,8598511,8598512,8598514,8598519,8598535,8598552,8598570,8598611,8598616,8598633,8598658,8598684,8598709,8598725,8598754,8598832,8598833,8598837,8598852,8598860,8598882,8598893,8598899,8598918,8598920,8598922,8598929,8598938,8598952,8598972,8598981,8598984,8598987,8598995,8599027,8599040,8599041,8599047,8599070,8599105,8599113,8599120,8599121,8599126,8599127,8599128,8599132,8599152,8599156,8599191,8599216,8599219,8599220,8599237,8599315,8599320,8599322,8599325,8599329,8599350,8599351,8599355,8599395,8599409,8599418,8599424,8599429,8599433,8599443,8599455,8599468,8599474,8599480,8599492,8599500,8599507,8599511,8599513,8599514,8599518,8599529,8599534,8599543,8599544,8599546,8599547,8599549,8599552,8599561,8599570,8599571,8599575,8599581,8599582,8599587,8599588,8599592,8599599,8599605,8599611,8599612,8599616,8599624,8599627,8599638,8599640,8599654,8599664,8599714,8599715,8599720,8599722,8599730,8599732,8599734,8599735,8599737,8599738,8599755,8599759,8599760,8599764,8599772,8599776,8599778,8599787,8599790,8599793,8599794,8599797,8599802,8599813,8599814,8599819,8599825,8599826,8599835,8599837,8599838,8599840,8599843,8599848,8599853,8599856,8599870,8599882,8599886,8599889,8599891,8599892,8599900,8599905,8599906,8599916,8599922,8599923,8599930,8599931,8599933,8599943,8599947,8599948,8599951,8599968,8599970,8599976,8599977,8599978,8599983,8599990,8599994,8599998,8599999,8600001,8600003,8600004,8600006,8600012,8600015,8600023,8600036,8600037,8600044,8600047,8600051,8600072,8600078,8600086,8600088,8600094,8600103,8600107,8600113,8600120,8600130,8600144,8600156,8600157,8600158,8600172,8600174,8600182,8600185,8600193,8600194,8600196,8600200,8600201,8600233,8600237,8600239,8600244,8600248,8600254,8600262,8600264,8600265,8600269,8600283,8600290,8600307,8600309,8600313,8600317,8600318,8600321,8600322,8600327,8600335,8600372,8600385,8600388,8600391,8600394,8600401,8600413,8600419,8600421,8600426,8600427,8600428,8600430,8600434,8600437,8600438,8600447,8600449,8600456,8600457,8600459,8600460,8600462,8600468,8600470,8600473,8600476,8600477,8600480,8600482,8600486,8600492,8600508,8600513,8600519,8600523,8600535,8600550,8600553,8600556,8600558,8600562,8600569,8600580,8600604,8600609,8600623,8600626,8600639,8600641,8600644,8600657,8600683,8600690,8600695,8600714,8600727,8600731,8600735,8600741,8600748,8600779,8600781,8600801,8600805,8600821,8600824,8600912,8600922,8600930,8600940,8600941,8600943,8600951,8600959,8600962,8600977,8600980,8600994,8600998,8601039,8601063,8601066,8601068,8601071,8601075,8601076,8601092,8601094,8601104,8601105,8601110,8601116,8601119,8601123,8601124,8601134,8601136,8601137,8601138,8601142,8601144,8601146,8601154,8601157,8601163,8601198,8601205,8601216,8601233,8601248,8601254,8601261,8601274,8601282,8601293,8601295,8601329,8601331,8601364,8601373,8601395,8601401,8601406,8601431,8601435,8601472,8601486,8601491,8601495,8601507,8601518,8601546,8601553,8601580,8601604,8601605,8601625,8601642,8601647,8601652,8601689,8601696,8601697,8601703,8601709,8601724,8601725,8601740,8601743,8601745,8601752,8601761,8601765,8601772,8601773,8601783,8601791,8601794,8601805,8601807,8601810,8601814,8601816,8601817,8601818,8601827,8601831,8601836,8601839,8601849,8601857,8601859,8601866,8601874,8601878,8601880,8601919,8601946,8601998,8602001,8602023,8602030,8602038,8602073,8602108,8602171,8602220,8602227,8602279,8602292,8602294,8602310,8602317,8602327,8602328,8602350,8602363,8602367,8602372,8602375,8602393,8602399,8602410,8602413,8602416,8602421,8602425,8602429,8602443,8602445,8602448,8602451,8602454,8602457,8602458,8602459,8602465,8602468,8602474,8602490,8602493,8602501,8602504,8602507,8602509,8602518,8602541,8602545,8602547,8602551,8602557,8602559,8602570,8602582,8602588,8602589,8602591,8602593,8602594,8602599,8602604,8602611,8602617,8602631,8602647,8602660,8602673,8602680,8602712,8602715,8602729,8602733,8602738,8602742,8602750,8602759,8602761,8602762,8602770,8602788,8602790,8602792,8602796,8602797,8602808,8602810,8602811,8602815,8602823,8602825,8602838,8602861,8602865,8602866,8602875,8602880,8602886,8602896,8602913,8602918,8602933,8602934,8602945,8602947,8602948,8602953,8602965,8602967,8602972,8602974,8602976,8602981,8602983,8602985,8602989,8602993,8602994,8603000,8603001,8603002,8603004,8603007,8603009,8603027,8603029,8603031,8603034,8603038,8603039,8603040,8603041,8603042,8603049,8603050,8603055,8603057,8603059,8603061,8603063,8603066,8603070,8603077,8603084,8603088,8603092,8603096,8603102,8603111,8603132,8603136,8603162,8603169,8603173,8603174,8603203,8603207,8603211,8603212,8603215,8603219,8603224,8603226,8603247,8603254,8603261,8603266,8603274,8603275,8603280,8603285,8603286,8603297,8603328,8603335,8603338,8603350,8603375,8603376,8603391,8603392,8603398,8603402,8603409,8603422,8603424,8603425,8603426,8603429,8603431,8603441,8603445,8603450,8603454,8603485,8603499,8603503,8603508,8603527,8603531,8603536,8603538,8603540,8603545,8603558,8603560,8603561,8603565,8603567,8603581,8603597,8603602,8603604,8603609,8603610,8603624,8603632,8603641,8603644,8603645,8603648,8603656,8603658,8603662,8603663,8603672,8603674,8603675,8603684,8603691,8603693,8603696,8603702,8603712,8603713,8603717,8603719,8603725,8603733,8603737,8603747,8603754,8603755,8603763,8603768,8603770,8603776,8603792,8603794,8603796,8603797,8603802,8603803,8603805,8603811,8603816,8603818,8603819,8603825,8603827,8603834,8603841,8603846,8603848,8603854,8603865,8603871,8603872,8603873,8603874,8603875,8603876,8603877,8603878,8603895,8603904,8603910,8603919,8603921,8603926,8603936,8603940,8603951,8603956,8603964,8603965,8603973,8603982,8603984,8604001,8604008,8604011,8604014,8604027,8604029,8604034,8604035,8604036,8604040,8604049,8604056,8604060,8604071,8604072,8604074,8604093,8604096,8604097,8604098,8604100,8604113,8604117,8604119,8604121,8604126,8604132,8604136,8604139,8604141,8604142,8604144,8604148,8604156,8604161,8604163,8604167,8604171,8604179,8604180,8604181,8604182,8604187,8604189,8604192,8604197,8604202,8604206,8604208,8604211,8604213,8604217,8604219,8604236,8604238,8604240,8604242,8604248,8604252,8604253,8604263,8604267,8604269,8604274,8604277,8604282,8604291,8604292,8604294,8604295,8604303,8604306,8604308,8604309,8604320,8604334,8604335,8604340,8604352,8604379,8604387,8604394,8604396,8604410,8604416,8604418,8604429,8604444,8604457,8604463,8604464,8604489,8604497,8604501,8604517,8604542,8604543,8604555,8604556,8604559,8604576,8604578,8604587,8604593,8604594,8604600,8604610,8604621,8604629,8604646,8604647,8604649,8604659,8604672,8604676,8604677,8604680,8604698,8604702,8604705,8604715,8604716,8604723,8604734,8604736,8604737,8604739,8604755,8604767,8604773,8604779,8604780,8604787,8604791,8604798,8604804,8604808,8604813,8604816,8604818,8604823,8604828,8604830,8604838,8604848,8604853,8604854,8604855,8604857,8604858,8604859,8604871,8604879,8604881,8604899,8604925,8604930,8604931,8604971,8604976,8604980,8604989,8604991,8605000,8605008,8605035,8605041,8605057,8605060,8605063,8605098,8605102,8605108,8605109,8605119,8605137,8605153,8605157,8605173,8605179,8605185,8605188,8605206,8605230,8605269,8605293,8605325,8605332,8605341,8605358,8605381,8605389,8605403,8605418,8605423,8605424,8605426,8605427,8605432,8605461,8605462,8605470,8605472,8605473,8605476,8605490,8605491,8605493,8605507,8605509,8605511,8605514,8605523,8605528,8605532,8605537,8605548,8605555,8605558,8605560,8605561,8605568,8605569,8605570,8605579,8605582,8605588,8605616,8605618,8605628,8605633,8605638,8605644,8605663,8605667,8605672,8605678,8605683,8605718,8605720,8605721,8605725,8605727,8605729,8605738,8605743,8605766,8605770,8605775,8605776,8605781,8605784,8605787,8605796,8605800,8605814,8605815,8605862,8605873,8605877,8605891,8605902,8605904,8605913,8605954,8605961,8605972,8605980,8605990,8606002,8606003,8606006,8606012,8606016,8606028,8606030,8606033,8606041,8606046,8606088,8606091,8606092,8606094,8606103,8606111,8606113,8606114,8606120,8606124,8606151,8606155,8606157,8606173,8606185,8606187,8606191,8606192,8606193,8606195,8606216,8606280,8606283,8606289,8606294,8606302,8606305,8606307,8606316,8606317,8606332,8606336,8606343,8606360,8606364,8606369,8606373,8606375,8606391,8606417,8606419,8606430,8606443,8606457,8606467,8606470,8606471,8606474,8606486,8606494,8606514,8606516,8606518,8606528,8606530,8606536,8606539,8606540,8606547,8606550,8606553,8606557,8606564,8606565,8606573,8606574,8606587,8606601,8606605,8606615,8606619,8606636,8606639,8606648,8606654,8606655,8606671,8606676,8606680,8606683,8606692,8606703,8606706,8606707,8606721,8606731,8606738,8606763,8606768,8606769,8606775,8606784,8606789,8606810,8606812,8606821,8606825,8606827,8606834,8606835,8606837,8606838,8606844,8606847,8606848,8606859,8606861,8606865,8606870,8606871,8606874,8606880,8606892,8606894,8606896,8606897,8606899,8606900,8606904,8606916,8606917,8606918,8606920,8606922,8606925,8606941,8606965,8606967,8606970,8606977,8606987,8606988,8606991,8606993,8606996,8607004,8607009,8607010,8607012,8607014,8607023,8607024,8607027,8607029,8607033,8607037,8607038,8607043,8607049,8607059,8607061,8607066,8607071,8607079,8607080,8607084,8607085,8607091,8607092,8607094,8607098,8607100,8607110,8607114,8607116,8607125,8607134,8607146,8607149,8607151,8607167,8607168,8607171,8607177,8607181,8607182,8607190,8607192,8607199,8607200,8607210,8607211,8607212,8607223,8607225,8607228,8607234,8607238,8607248,8607269,8607277,8607280,8607288,8607300,8607306,8607312,8607331,8607333,8607338,8607346,8607354,8607365,8607376,8607378,8607381,8607407,8607410,8607415,8607423,8607430,8607435,8607451,8607452,8607454,8607457,8607463,8607474,8607484,8607490,8607492,8607493,8607498,8607501,8607505,8607512,8607523,8607529,8607534,8607540,8607543,8607544,8607547,8607550,8607552,8607553,8607555,8607560,8607561,8607564,8607568,8607570,8607572,8607573,8607578,8607588,8607589,8607590,8607602,8607606,8607607,8607613,8607615,8607625,8607630,8607631,8607639,8607640,8607651,8607659,8607660,8607662,8607664,8607670,8607683,8607699,8607710,8607712,8607713,8607721,8607725,8607735,8607737,8607744,8607745,8607746,8607751,8607752,8607767,8607778,8607782,8607783,8607785,8607788,8607805,8607808,8607813,8607818,8607826,8607829,8607831,8607840,8607844,8607850,8607858,8607864,8607881,8607906,8607948,8607967,8607968,8607970,8607974,8607985,8607989,8607990,8608033,8608038,8608062,8608087,8608105,8608114,8608119,8608127,8608132,8608162,8608195,8608205,8608233,8608238,8608239,8608243,8608252,8608253,8608260,8608268,8608272,8608273,8608309,8608317,8608320,8608325,8608328,8608339,8608341,8608342,8608356,8608365,8608366,8608369,8608371,8608378,8608385,8608390,8608391,8608392,8608394,8608400,8608411,8608414,8608418,8608421,8608427,8608428,8608430,8608432,8608446,8608451,8608470,8608494,8608498,8608503,8608511,8608512,8608536,8608541,8608544,8608554,8608575,8608581,8608584,8608601,8608604,8608634,8608649,8608663,8608677,8608688,8608712,8608740,8608741,8608744,8608750,8608752,8608788,8608803,8608805,8608826,8608828,8608838,8608845,8608852,8608853,8608866,8608876,8608880,8608881,8608882,8608901,8608902,8608913,8608919,8608922,8608924,8608938,8608943,8608948,8608955,8608969,8608975,8608986,8608987,8608997,8608999,8609015,8609018,8609026,8609039,8609041,8609047,8609050,8609052,8609066,8609067,8609072,8609089,8609100,8609102,8609111,8609115,8609123,8609125,8609129,8609133,8609146,8609147,8609151,8609169,8609172,8609179,8609183,8609186,8609207,8609256,8609258,8609259,8609263,8609282,8609283,8609288,8609299,8609305,8609317,8609318,8609333,8609344,8609380,8609381,8609403,8609405,8609408,8609418,8609423,8609424,8609432,8609443,8609452,8609457,8609458,8609460,8609463,8609467,8609468,8609470,8609477,8609491,8609506,8609510,8609512,8609515,8609517,8609519,8609523,8609550,8609567,8609575,8609580,8609590,8609591,8609596,8609616,8609618,8609622,8609629,8609681,8609685,8609702,8609707,8609711,8609723,8609736,8609763,8609781,8609803,8609818,8609824,8609825,8609864,8609869,8609876,8609884,8609896,8609905,8609925,8609932,8609952,8609953,8609957,8609970,8609985,8609990,8610002,8610014,8610031,8610034,8610040,8610060,8610072,8610073,8610077,8610082,8610088,8610097,8610105,8610109,8610110,8610112,8610117,8610118,8610125,8610137,8610145,8610163,8610170,8610171,8610178,8610181,8610188,8610202,8610224,8610237,8610243,8610244,8610245,8610247,8610248,8610252,8610256,8610266,8610278,8610281,8610288,8610292,8610296,8610325,8610335,8610351,8610353,8610355,8610366,8610375,8610380,8610389,8610393,8610408,8610409,8610424,8610431,8610433,8610434,8610444,8610452,8610453,8610465,8610471,8610477,8610478,8610479,8610484,8610487,8610488,8610497,8610504,8610511,8610518,8610520,8610521,8610530,8610533,8610537,8610554,8610560,8610562,8610570,8610572,8610575,8610578,8610580,8610590,8610595,8610598,8610605,8610609,8610611,8610613,8610616,8610618,8610631,8610635,8610642,8610648,8610651,8610654,8610670,8610672,8610675,8610676,8610677,8610680,8610690,8610692,8610715,8610720,8610728,8610739,8610747,8610755,8610757,8610760,8610765,8610770,8610776,8610789,8610797,8610805,8610812,8610817,8610823,8610826,8610831,8610839,8610840,8610842,8610854,8610855,8610857,8610858,8610860,8610864,8610869,8610876,8610882,8610883,8610884,8610888,8610890,8610891,8610896,8610897,8610913,8610917,8610924,8610931,8610934,8610937,8610938,8610939,8610944,8610949,8610951,8610961,8610963,8610977,8610991,8610999,8611008,8611016,8611036,8611038,8611052,8611062,8611069,8611073,8611074,8611075,8611077,8611094,8611110,8611119,8611127,8611131,8611145,8611150,8611153,8611165,8611169,8611192,8611195,8611221,8611227,8611236,8611243,8611244,8611257,8611258,8611269,8611276,8611288,8611290,8611298,8611303,8611304,8611305,8611308,8611310,8611314,8611317,8611336,8611339,8611344,8611345,8611351,8611356,8611361,8611363,8611364,8611374,8611382,8611387,8611416,8611421,8611422,8611424,8611436,8611437,8611444,8611445,8611450,8611469,8611472,8611473,8611483,8611484,8611491,8611493,8611504,8611509,8611510,8611520,8611525,8611527,8611529,8611542,8611543,8611551,8611552,8611556,8611571,8611574,8611575,8611583,8611597,8611599,8611606,8611616,8611624,8611627,8611630,8611646,8611660,8611663,8611677,8611689,8611714,8611720,8611732,8611733,8611741,8611746,8611755,8611759,8611770,8611800,8611810,8611820,8611839,8611845,8611858,8611859,8611867,8611876,8611879,8611883,8611885,8611905,8611910,8611929,8611933,8611936,8611943,8611951,8611952,8611968,8611972,8611986,8611990,8611994,8612000,8612007,8612010,8612013,8612021,8612027,8612029,8612031,8612041,8612051,8612052,8612057,8612058,8612062,8612064,8612071,8612079,8612089,8612097,8612100,8612109,8612128,8612129,8612130,8612136,8612138,8612143,8612144,8612155,8612156,8612161,8612172,8612177,8612178,8612185,8612209,8612219,8612220,8612230,8612231,8612243,8612250,8612252,8612254,8612266,8612269,8612282,8612283,8612285,8612288,8612291,8612293,8612303,8612305,8612314,8612318,8612332,8612336,8612342,8612360,8612363,8612371,8612372,8612377,8612378,8612386,8612388,8612389,8612394,8612397,8612407,8612409,8612410,8612415,8612417,8612421,8612429,8612431,8612439,8612443,8612444,8612457,8612459,8612460,8612462,8612471,8612473,8612479,8612481,8612484,8612485,8612494,8612511,8612512,8612521,8612548,8612561,8612564,8612592,8612600,8612605,8612606,8612615,8612619,8612621,8612653,8612673,8612690,8612720,8612727,8612735,8612736,8612741,8612751,8612755,8612802,8612805,8612814,8612831,8612870,8612876,8612880,8612898,8612906,8612936,8612974,8612986,8612995,8613010,8613016,8613031,8613037,8613038,8613039,8613044,8613053,8613054,8613057,8613059,8613061,8613066,8613076,8613077,8613078,8613095,8613103,8613110,8613114,8613143,8613155,8613164,8613165,8613173,8613175,8613177,8613182,8613197,8613221,8613236,8613249,8613271,8613281,8613288,8613323,8613345,8613361,8613373,8613393,8613406,8613420,8613424,8613432,8613433,8613443,8613483,8613484,8613501,8613542,8613546,8613558,8613583,8613603,8613647,8613665,8613671,8613675,8613678,8613687,8613689,8613701,8613703,8613709,8613713,8613718,8613723,8613726,8613728,8613729,8613736,8613748,8613749,8613752,8613781,8613806,8613856,8613859,8613863,8613868,8613869,8613870,8613905,8613912,8613923,8613928,8613932,8613968,8613982,8613986,8613992,8613995,8614008,8614049,8614072,8614102,8614113,8614151,8614155,8614183,8614185,8614200,8614217,8614225,8614241,8614301,8614304,8614316,8614332,8614334,8614360,8614361,8614365,8614376,8614387,8614392,8614393,8614396,8614412,8614416,8614419,8614420,8614428,8614443,8614447,8614448,8614485,8614488,8614515,8614517,8614521,8614524,8614535,8614544,8614546,8614551,8614553,8614571,8614611,8614615,8614617,8614625,8614642,8614701,8614761,8614764,8614765,8614768,8614780,8614788,8614804,8614805,8614808,8614813,8614854,8614856,8614858,8614859,8614874,8614889,8614910,8614925,8614943,8614953,8614956,8614960,8614964,8614965,8614970,8614976,8614980,8614993,8614995,8615001,8615007,8615014,8615028,8615035,8615038,8615042,8615051,8615054,8615065,8615068,8615084,8615099,8615104,8615108,8615113,8615119,8615127,8615139,8615145,8615148,8615151,8615152,8615162,8615165,8615173,8615181,8615185,8615196,8615201,8615206,8615226,8615231,8615233,8615238,8615241,8615265,8615272,8615305,8615314,8615373,8615387,8615392,8615393,8615412,8615424,8615427,8615433,8615451,8615453,8615456,8615457,8615469,8615473,8615478,8615480,8615484,8615495,8615497,8615502,8615513,8615515,8615523,8615526,8615527,8615540,8615543,8615568,8615587,8615603,8615632,8615637,8615662,8615666,8615679,8615691,8615692,8615705,8615708,8615713,8615726,8615735,8615738,8615755,8615759,8615769,8615785,8615794,8615819,8615822,8615829,8615832,8615838,8615850,8615893,8615904,8615968,8615986,8616001,8616020,8616042,8616048,8616049,8616054,8616058,8616091,8616139,8616166,8616188,8616190,8616208,8616222,8616248,8616259,8616273,8616297,8616318,8616345,8616364,8616377,8616391,8616406,8616420,8616427,8616435,8616437,8616452,8616478,8616486,8616514,8616515,8616565,8616571,8616642,8616722,8616799,8616805,8616861,8616890,8616892,8616893,8616901,8616907,8616949,8616972,8616991,8616999,8617008,8617021,8617060,8617069,8617086,8617128,8617164,8617167,8617170,8617217,8617225,8617226,8617227,8617239,8617250,8617252,8617254,8617270,8617272,8617275,8617279,8617290,8617298,8617323,8617340,8617354,8617363,8617378,8617393,8617395,8617398,8617409,8617422,8617425,8617436,8617439,8617448,8617468,8617496,8617530,8617532,8617536,8617566,8617590,8617679,8617804,8617843,8617853,8617854,8617861,8617864,8617865,8617869,8617897,8617916,8617922,8617930,8617964,8617971,8617975,8617983,8617998,8618037,8618052,8618075,8618099,8618112,8618128,8618142,8618160,8618192,8618199,8618225,8618229,8618242,8618252,8618273,8618285,8618286,8618288,8618301,8618315,8618349,8618383,8618402,8618412,8618424,8618443,8618486,8618505,8618523,8618540,8618555,8618561,8618615,8618632,8618704,8618707,8618730,8618784,8618785,8618905,8618920,8618944,8618955,8618958,8618975,8618986,8618994,8618996,8618999,8619009,8619015,8619043,8619073,8619075,8619096,8619173,8619214,8619399,8619407,8619418,8619420,8619484,8619490,8619493,8619498,8619506,8619538,8619567,8619571,8619572,8619578,8619593,8619629,8619633,8619650,8619684,8619708,8619726,8619752,8619757,8619765,8619769,8619812,8619817,8619823,8619839,8619866,8619868,8619872,8619877,8619888,8619893,8619906,8619936,8619952,8619965,8620006,8620014,8620023,8620061,8620105,8620140,8620154,8620177,8620178,8620202,8620219,8620234,8620239,8620292,8620297,8620332,8620353,8620381,8620447,8620488,8620489,8620510,8620528,8620543,8620544,8620572,8620588,8620594,8620604,8620612,8620619,8620632,8620672,8620678,8620699,8620711,8620719,8620771,8620829,8620831,8620846,8620856,8620893,8620908,8620933,8620956,8620977,8621002,8621030,8621165,8621188,8621267,8621331,8621360,8621371,8621422,8621448,8621459,8621499,8621577,8621609,8621636,8621639,8621649,8621676,8621705,8621726,8621789,8621851,8621891,8621912,8621954,8621992,8622083,8622091,8622141,8622214,8622306,8622389,8622429,8622451,8622522,8622524,8622570,8622674,8622696,8622922,8623041,8623155,8623201,8623342,8623376,8623383,8623399,8623424,8623632,8623829,8623852,8624079,8624087,8624127,8624428,8624435,8624681,8624775,8625240,8625352,8625413,8625418,8625666,8626611,8627448,8627972,8628574,9294446273,77700099693,77700106522,77700110936,77700112012,77700113561,77700114823,77700116642,77700116697,77700118518,77700119035,77700122202,77700124146,77700125059,77700125291,77700134184,77700134463,77700134696,8317898,8346100,8379123,8402809,8409538,8426836,8427505,8430662,8431690,8466714,8468287,8469390,8473502,8475048,8475401,8485547,8499714,8507042,8513509,8513776,8514341,8517281,8522271,8522634,8524432,8525878,8525888,8526223,8527406,8527807,8528535,8529286,8530299,8531102,8533170,8535054,8535293,8535337,8535604,8537451,8537964,8538443,8539225,8539577,8540060,8540333,8540471,8540673,8540805,8541883,8542613,8542653,8543052,8543523,8543534,8543597,8544070,8546186,8546651,8547011,8547489,8547587,8547856,8548721,8548745,8549583,8549630,8549702,8549961,8550254,8550298,8550377,8551207,8551678,8552857,8553163,8553181,8553228,8554056,8554059,8554090,8554357,8554525,8554532,8554762,8554914,8555497,8555515,8555591,8555720,8556577,8556630,8556835,8556879,8557006,8557037,8557085,8557572,8558431,8558500,8558509,8558741,8558921,8559101,8559393,8559478,8559626,8559748,8559875,8560036,8560424,8560492,8560559,8561820,8562028,8562034,8562059,8562288,8562380,8562507,8562529,8562555,8562572,8562676,8562815,8563054,8563128,8563141,8563310,8563383,8563526,8563537,8563572,8563593,8563596,8563600,8563637,8563638,8563676,8563726,8563767,8563833,8563877,8563988,8564157,8564386,8564425,8564531,8564571,8564782,8564790,8565156,8565412,8565510,8565517,8565685,8565717,8565947,8566085,8566127,8566185,8566235,8566275,8566276,8566397,8566450,8566616,8566755,8566804,8566809,8566904,8566952,8566968,8567037,8567128,8567143,8567186,8567854,8567986,8568529,8568638,8569000,8569469,8570621,8570677,8570727,8570748,8570872,8570973,8571029,8571041,8571081,8571104,8571130,8571155,8571324,8571364,8571399,8571400,8571760,8572425,8572860,8573072,8573186,8573189,8573825,8574015,8574033,8574169,8574550,8574562,8574692,8575276,8575301,8575566,8575623,8575678,8575696,8575725,8575796,8575917,8576175,8576203,8576280,8576318,8576384,8576663,8576718,8576992,8577029,8577034,8577064,8577080,8577104,8577211,8577575,8577593,8577841,8577883,8578161,8578438,8578972,8579036,8579253,8579279,8579285,8579297,8579339,8579361,8579375,8579496,8579521,8579544,8579672,8579690,8580074,8580375,8580453,8580875,8580972,8580980,8581159,8581318,8581450,8581471,8581521,8581623,8581663,8581722,8581987,8582351,8582353,8582359,8582373,8582643,8582676,8582774,8583039,8583151,8583311,8583403,8583604,8583690,8583724,8583750,8584092,8584330,8584531,8584763,8584924,8585233,8585236,8585451,8585824,8586132,8586355,8586403,8586417,8586562,8587021,8587423,8587604,8587980,8587998,8588890,8589078,8590222,8590411,8590981,8591113,8591352,8591363,8591972,8592032,8592157,8592217,8592275,8593112,8593130,8593244,8593308,8593405,8593410,8593415,8593870,8594339,8594644,8594748,8594756,8594780,8594822,8595268,8595399,8595474,8595483,8595630,8596672,8596767,8598528,8598766,8599307,8599801,8599881,8599975,8600209,8600540,8600747,8600950,8601045,8601072,8601355,8601622,8601995,8602333,8602724,8602835,8603369,8603715,8603869,8604166,8604564,8604822,8604836,8605598,8605657,8606011,8606162,8606296,8606464,8606526,8606951,8607323,8607513,8607647,8607648,8607971,8608158,8608324,8608384,8608998,8609341,8609445,8609502,8610231,8610439,8610447,8610673,8610802,8611252,8611373,8612150,8612712,8612996,8614769,8369515,8512795,8513263,8528115,8536596,8541337,8542421,8548749,8548872,8553833,8558827,8560320,8561125,8563493,8563598,8563879,8563993,8564222,8565462,8566383,8567085,8568402,8569525,8573883,8575273,8576305,8577146,8577709,8580944,8582995,8587321,8590501,8591574,8596957,8600019,8602819,8605477,8609621,8541673,8564802,8573159,8576131,8584294);
    $array = array();
    $columns = array(
        'order_id' => 'SFO.entity_id',
        'increment_id' => 'SFO.increment_id',
        // 'status' => 'SFO.status',
        // 'created_at' => 'SFO.created_at',
        // 'comment' => 'IF(SFOSH.comment IS NOT NULL,SFOSH.comment,SFOISH.comment)',
        // 'comment_date' => 'IF(SFOSH.comment IS NOT NULL,SFOSH.created_at,SFOISH.created_at)',
    );
    $select = $read->select()
        ->from(array('SFO' => 'sales_flat_order'),array())
        ->join(array('SFOISH'=>'sales_flat_order_internal_status_history'),"SFO.entity_id = SFOISH.parent_id",array())
        ->columns($columns)
        // ->where('SFOSH.user_name LIKE "%Deliveright%"')
        ->orWhere('SFOISH.comment_by LIKE "%Deliveright%"')
        ->where('SFO.increment_id IN(?)',$incrementIds)
        ->group('SFO.entity_id');

    echo $select;
    die;

    $select = $read->select()
        ->from(array('SFO' => 'sales_flat_order'),array())
        ->join(array('SFOSH' => 'sales_flat_order_status_history'),"SFO.entity_id = SFOSH.parent_id",array())
        ->join(array('SFOISH'=>'sales_flat_order_internal_status_history'),"SFO.entity_id = SFOISH.parent_id",array())
        ->columns($columns)
        ->where('SFOSH.user_name LIKE "%Deliveright%"')
        ->orWhere('SFOISH.comment_by LIKE "%Deliveright%"')
        ->where('SFO.increment_id IN(?)',$incrementIds)
        ->group('SFO.entity_id');

    $attribute   = Mage::getSingleton('eav/config')->getAttribute(Mage_Catalog_Model_Product::ENTITY, 'product_type');
    $mattressId  = $attribute->getSource()->getOptionId('Mattress');
    $productType = Mage::getSingleton('eav/config')->getCollectionAttribute('catalog_product', 'product_type');


    $productIds = array(129813,129878,129971,88900,97565,97802,88893,129834,129914,97926,97699,131082,131083,98066,88897,130114,97891,130119,97892,97994,97587,129831,129832,129912,129802,129865,97548,89261,89262,89263,89264,129837,129969,101803,97430,97431,129821,129857,129919,129786,129937,129823,129804,129871,129895,89245,89246,89247,89248,129771,129818,129856,129946,129838,129805,129809,129898,129820,129859,129953,129891,129770,130143,129827,129874,129884,129945,130200,129819,129885,129897,129785,129781,129958,97947,109971,109970,109969,97683,97632,89249,97432,101390,129929,109950,123132,139191,129800,129851,129939,139212,139211,139209,139197,129796,147463,147462,147461,132952,147915,154306,154307,132953,167820,167821,165918,165919,165920,167449,167450,97433,97434,97435,166986,166987,154044,170472,129863,165606,165607,165608,170476,170477,110244,147443,170473,170471,171132,171133,171131,146453,151351,151352,165293,165294,170738,170739,165724,165723,139208,139207,154210,154211,139200,139203,139201,139202,129849,129934,129976,165478,165477,175018,175019,175299,177939,177940,136328,174954,174955,175145,175147,175146,195127,195122,177976,177977,177978,101714,171780,171779,171781,195006,184832,184833,194906,184771,194905,195131,184998,184999,195133,194888,195076,184916,184915,205747,205602,205601,205509,205511,205514,205755,205756,205555,205556,205557,208096,208097,175210,175107,269706,269705,269528,269372,269431,269298);
    $columns = array(
        'order Increment Id'         => "SFO.increment_id",
        'order Date'                 => "SFO.created_at",
        'Bundle Product Name'        => "bundle_item.name",
        'Bundle Product WebId'       => "bundle_item.upc",
        'Bundle Product SKU'         => "bundle_product.sku",
        'Bundle Product Brand'       => "bundle_item_additional.brand",
        'Bundle Product Collection'  => "bundle_item_additional.collection_type",
        'Related Product Name'       => "SFOI.name",
        'Related Product WebId'      => "SFOI.upc",
        'Related Product SKU'        => "SFOI_product.sku",
        'Related Product Brand'      => "SFOIA.brand",
        'Related Product Collection' => "SFOIA.collection_type",            
        'Related Product QTY'        => "SFOI.qty_ordered",         
    );
    $name = Mage::getSingleton('eav/config')->getCollectionAttribute('catalog_product', 'name');
    $brand = Mage::getSingleton('eav/config')->getCollectionAttribute('catalog_product', 'brand');
    $collection = Mage::getSingleton('eav/config')->getCollectionAttribute('catalog_product', 'collection_type');
    $first = $read->select()
        ->from(array("SFO" => "sales_flat_order"),array())
        ->join(array("SFOI" => "sales_flat_order_item"),"SFO.entity_id = SFOI.order_id",array())
        ->join(array("SFOIA" => "sales_flat_order_item_additional"),"SFOI.item_id = SFOIA.item_id",array())
        ->join(array('SFOI_product' => 'catalog_product_entity'),"SFOI.product_id = SFOI_product.entity_id",array())
        ->join(array('bundle_item' => 'sales_flat_order_item'),"SFOI.order_id = bundle_item.order_id AND SFOI.parent_item_id = bundle_item.item_id",array())
        ->join(array("bundle_item_additional" => "sales_flat_order_item_additional"),"bundle_item.item_id = bundle_item_additional.item_id",array())
        ->join(array('bundle_product' => 'catalog_product_entity'),"bundle_item.product_id = bundle_product.entity_id",array())
        ->joinLeft(array('at_product_type_bundle_product' => $productType->getBackendTable()), "at_product_type_bundle_product.entity_id = bundle_product.entity_id AND at_product_type_bundle_product.attribute_id = {$productType->getId()}", array())
        ->columns($columns)
        ->where("at_product_type_bundle_product.value != ?",$mattressId)
        ->where("SFOI.parent_product_id IS NOT NULL")
        ->where("SFOI.product_type = ?",'simple')
        ->where("DATE(SFO.created_at) >= ?",'2020-02-13')
        ->where("DATE(SFO.created_at) <= ?",'2020-03-13')
        ->where("SFOI.product_id IN (?) ",$productIds);
    
    $columns = array(
        'order Increment Id'         => "SFO.increment_id",
        'order Date'                 => "SFO.created_at",
        'Bundle Product Name'        => "bundle_item.name",
        'Bundle Product WebId'       => "bundle_item.upc",
        'Bundle Product SKU'         => "bundle_product.sku",
        'Bundle Product Brand'       => "bundle_item_additional.brand",
        'Bundle Product Collection'  => "bundle_item_additional.collection_type",
        'Related Product Name'       => "SFOI.name",
        'Related Product WebId'      => "SFOI.upc",
        'Related Product SKU'        => "SFOI_product.sku",
        'Related Product Brand'      => "SFOIA.brand",
        'Related Product Collection' => "SFOIA.collection_type",            
        'Related Product QTY'        => "SFOI.qty_ordered",
    );
    $second = $read->select()
        ->from(array('SFO' => 'sales_flat_order'),array())
        ->join(array('SFOI' => 'sales_flat_order_item'),"SFO.entity_id = SFOI.order_id",array())
        ->join(array('SFOIA' => 'sales_flat_order_item_additional'),"SFOI.item_id = SFOIA.item_id",array())
        ->join(array('SFOI_product' => 'catalog_product_entity'),"SFOI.product_id = SFOI_product.entity_id",array())
        ->join(array('bundle_item' => 'sales_flat_order_item'),"SFOI.order_id = bundle_item.order_id AND SFOI.related_parent_item_id = bundle_item.product_id AND bundle_item.product_type = 'bundle'",array())
        ->join(array('bundle_item_additional' => 'sales_flat_order_item_additional'),"bundle_item.item_id = bundle_item_additional.item_id",array())
        ->join(array('bundle_product' => 'catalog_product_entity'),"bundle_item.product_id = bundle_product.entity_id",array())
        ->joinLeft(array('at_product_type_bundle_product' => $productType->getBackendTable()), "at_product_type_bundle_product.entity_id = bundle_product.entity_id AND at_product_type_bundle_product.attribute_id = {$productType->getId()}", array())
        ->columns($columns)
        ->where("at_product_type_bundle_product.value != ?",$mattressId)
        ->where("SFOI.related_parent_item_id IS NOT NULL")
        ->where("DATE(SFO.created_at) >= ?",'2020-03-13')
        ->where("DATE(SFO.created_at) <= ?",'2020-04-13');

    echo $first;
    echo "<hr>";
    echo $second;

} catch (Exception $e) {
    echo $e->getMessage();
    die;
}