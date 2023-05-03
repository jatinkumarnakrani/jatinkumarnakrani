<?php
require_once "../app/Mage.php";
Mage::app('default');
Mage::setIsDeveloperMode(true);
// Varien_Profiler::enable();
error_reporting(E_ALL ^ E_DEPRECATED);
ini_set('display_errors', 1);
$read = Mage::getSingleton('core/resource')->getConnection('core_read');
echo '<pre>';
try {
    function getSurcharge($distunce)
    {
        if ($distunce >= 151 && $distunce <= 200) {
            return 99;
        }elseif ($distunce > 200 && $distunce <= 300) {
            return 125;
        }elseif ($distunce > 300 && $distunce <= 400) {
            return 199;
        }elseif ($distunce > 400) {
            return 299;
        }else{
            return 0;
        }
    }
    $csvObject = new Varien_File_Csv();
    $fileContent = $csvObject->getData(Mage::getBaseDir().DS.'root-script'.DS.'shipcarrier_2021-05-14.csv');
    $carrier = array();
    $header = array();
    $isHeader = true;
    $finalItems = array();
    $finalItemsHeader= array('wg_id','name','surcharge','zipcode','carrier_type','distance','operation');
    $finalItems[] = array_combine($finalItemsHeader, $finalItemsHeader);

    $select = $read->select()
        ->from(array('CSC' => 'ccc_ship_carrier'),array('name','id'));
    $carrierIdByName = $read->fetchPairs($select);

    $select = $read->select()
        ->from(array('CSC' => 'ccc_ship_carrier'),array('short_name','id'));
    $carrierIdByShortName = $read->fetchPairs($select);

    $select = $read->select()
        ->from(array('CSC' => 'ccc_ship_carrier'),array('zipcode','id'));
    $carrierIdByZipcode = $read->fetchPairs($select);

    $select = $read->select()
        ->from(array('CSC' => 'ccc_ship_carrier'),array('phone' => new Zend_db_Expr("TRIM(REPLACE(REPLACE(REPLACE(CSC.phone,'-',''),'(',''),')',''))"),'id'));
    $carrierIdByPhone = $read->fetchPairs($select);

    foreach ($fileContent as $row) {
        if (!$isHeader) {
            $carrierId = 0;
            $newArray = array();
            $row = array_combine($header, $row);
            $name = (isset($row['Site Name'])) ? trim($row['Site Name']) : null;
            $zipcode = (isset($row['Zip Code'])) ? trim($row['Zip Code']) : null;
            $phone = (isset($row['Phone'])) ? trim($row['Phone']) : null;
            $phone = preg_replace('/[^0-9\-]/', '', $phone);
            $phone = str_replace('-', '', $phone);
            $mile = (isset($row['Miles from Site'])) ? trim($row['Miles from Site']): null;
            $destinationZipcode = (isset($row['Destination Zip'])) ? trim($row['Destination Zip']) : null;

            if (isset($carrierIdByZipcode[$zipcode])) {
                $carrierId = $carrierIdByZipcode[$zipcode];
            }

            if (!$carrierId && isset($carrierIdByPhone[$phone])) {
                $carrierId = $carrierIdByPhone[$phone];
            }

            if (!$carrierId && isset($carrierIdByName[$name])) {
                $carrierId = $carrierIdByName[$name];
            }
            if (!$carrierId && isset($carrierIdByShortName[$name])) {
                $carrierId = $carrierIdByShortName[$name];
            }

            $newArray['wg_id'] = $carrierId;
            $newArray['name'] = $name;
            $newArray['surcharge'] = getSurcharge($mile);
            $newArray['zipcode'] = $destinationZipcode;
            $newArray['carrier_type'] = 1;
            $newArray['distance'] = $mile;
            $newArray['operation'] = 'add';
            $finalItems[] = $newArray;
        }else{
            $header = $row;
            $isHeader = false;
        }
    }
    $file_path = Mage::getBaseDir().DS.'root-script'.DS.'shipcarrier_zipcode.csv';
    $mage_csv = new Varien_File_Csv();
    $mage_csv->saveData($file_path, $finalItems);
    echo "File Generated in this path: ".$file_path;
} catch (Exception $e) {
    echo $e->getMessage();
    die;
}
