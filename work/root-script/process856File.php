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
        function readXmlFile($filePath)
        {
            if (!file_exists($filePath) || is_dir($filePath)) {
                throw new Exception("File not exist", 1);
            }
            $xmlFile         = file_get_contents($filePath);
            $xmlStringData   = simplexml_load_string($xmlFile);
            $jsonEncodedData = json_encode($xmlStringData);
            $fileContent     = json_decode($jsonEncodedData, true);
            return $fileContent;
        }

        $from = "";
        $to = "";
        $read = Mage::getSingleton('core/resource')->getConnection('core_read');
        $carrierSelect = $read->select()
            ->from(array('CSC'=>'ccc_ship_carrier'),array('numeric_code'))
            ->where('CSC.id IN(?)',explode(',', Mage::getStoreConfig('ediryder/general/ship_carrier')));
        $numericCodes = $read->fetchCol($carrierSelect);
        $shippingFilesDir = Mage::getBaseDir().'/media/edi/ashley/shipping/';
        $io = new Varien_Io_File();
        $io->open(array('path' => $shippingFilesDir));
        $files = $io->ls();
        usort($files, function ($a, $b){
            $datetime1 = strtotime($a['mod_date']);
            $datetime2 = strtotime($b['mod_date']);
            return $datetime1 - $datetime2;
        });
        krsort($files);
        $csvDetailsArray = array(
            array(
                'Shipment' => 'Shipment',
                'Creation Date' => 'Creation Date',
                'ShipDate' => 'ShipDate',
                'TransitTime' => 'TransitTime',
                'Ryder Arrived Date' => 'Ryder Arrived Date',
                'Diff' => 'Diff',
                'file_name' => 'file_name',
                'shipment_created_at' => 'shipment_created_at',
            )
        );
        foreach ($files as $fileDetails) {
            $fileDetails = new Varien_Object($fileDetails);
            try {
                $creationDate = null;
                $shipDate = null;
                $transitTime = null;
                $numericCode = null;
                $plannedDeliveryDate = null;
                $diff = null;
                $shipments = array();
                $fileContent = readXmlFile($shippingFilesDir.$fileDetails->getText());
                if (!$fileContent) {
                    continue;
                }
                if (isset($fileContent['shipment']['shipmentSystemReference']['systemReferenceValue'])) {
                    $plannedDeliveryDate = $fileContent['shipment']['shipmentSystemReference']['systemReferenceValue'];
                    $plannedDeliveryDate = strtolower($plannedDeliveryDate);
                    if ($plannedDeliveryDate != 'pending') {
                        continue;
                    }
                }
                if (isset($fileContent['shipment']['shipDate']['@attributes']['shipDate']) && $fileContent['shipment']['shipDate']['@attributes']['shipDate']) {
                    $date = $fileContent['shipment']['shipDate']['@attributes']['shipDate'];
                    $date = new DateTime($date);
                    if ($from && $from > $date->format('Y-m-d')) {
                        continue;
                    }
                    if ($to && $to < $date->format('Y-m-d')) {
                        continue;
                    }
                    $shipDate = $date->format('Y-m-d');
                }
                if (isset($fileContent['shipment']['document']['creationDate']) && isset($fileContent['shipment']['document']['creationTime'])) {
                    $date = $fileContent['shipment']['document']['creationDate'];
                    $time = $fileContent['shipment']['document']['creationTime'];
                    $date = new DateTime($date);
                    $creationDate = $date->format('Y-m-d') .' ' .str_replace('-', ':', $time);
                }
                if (isset($fileContent['shipment']['carrier']['transitTime']['@attributes']['value']) && $fileContent['shipment']['carrier']['transitTime']['@attributes']['value']) {
                    $transitTime = $fileContent['shipment']['carrier']['transitTime']['@attributes']['value'];
                }

                if (isset($fileContent['shipment']['shipTo']['@attributes']['id']) && $fileContent['shipment']['shipTo']['@attributes']['id']) {
                    $numericCode = $fileContent['shipment']['shipTo']['@attributes']['id'];
                }

                if (isset($fileContent['shipment'])) {
                    foreach ($fileContent['shipment'] as $key => $details) {
                        if ($key == 'order') {
                            if (isset($details['orderReferenceNumber'][0]['@attributes']['referenceNumberValue']) && in_array($numericCode, $numericCodes)) {
                                $shipments[] = $details['orderReferenceNumber'][0]['@attributes']['referenceNumberValue'];
                            }
                        }
                    }
                }

                if (!empty($shipments)) {
                    foreach ($shipments as $shipment) {
                        $mfrOrderCollection = Mage::getModel('manufacturer/order')->getCollection();
                        $mfrOrderCollection->getSelect()
                            ->joinLeft(array('EO'=>'edicarrier_order'),"main_table.order_id=EO.order_id AND main_table.po_number=EO.shipment_number",array())
                            ->joinLeft(array('EAL'=>'edicarrier_action_log'),"main_table.order_id=EAL.order_id AND main_table.po_number=EAL.shipment_number AND EAL.event_code = 'X5' AND EAL.comment_code='NS'",array('event_date'))
                            ->where('main_table.po_number = ?',$shipment)
                            ->order('EAL.id ASC');
                        $ediOrder = $mfrOrderCollection->getFirstItem();
                        if (count($csvDetailsArray) == 101) {
                            break;
                        }

                        if ($ediOrder->getEventDate() && $shipDate) {
                            $eventDate = new DateTime($ediOrder->getEventDate());
                            $date1 = date_create($eventDate->format('Y-m-d'));
                            $date2 = date_create($shipDate);
                            $dateDiff = date_diff($date1,$date2);
                            $diff = $dateDiff->format("%R%a");
                        }

                        $csvDetailsArray[] = array(
                            'Shipment' => $shipment,
                            'Creation Date' => $creationDate,
                            'ShipDate' => $shipDate,
                            'TransitTime' => $transitTime,
                            'Ryder Arrived Date' => $ediOrder->getEventDate(),
                            'Diff' => $diff,
                            'file_name' => $fileDetails->getText(),
                            'shipment_created_at' => $ediOrder->getCreatedAt(),
                        );
                    }
                }
            } catch (Exception $e) {
                Mage::log(array('file_name'=>$shippingFilesDir.$fileDetails->getText(),'message'=>$e->getMessage()),null,'Process856File.log');
            }
        }
        $fileName = Mage::getBaseDir()."/example.csv";
        $var_csv = new Varien_File_Csv();
        $var_csv->saveData($fileName, $csvDetailsArray);

} catch (Exception $e) {
    echo $e->getMessage();
    die;
}