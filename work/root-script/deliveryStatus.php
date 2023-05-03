<?php
require_once "../app/Mage.php";
Mage::app('default');
Mage::setIsDeveloperMode(true);
// Varien_Profiler::enable();
error_reporting(E_ALL ^ E_DEPRECATED);
ini_set('display_errors', 1);
// echo "<marquee><h4><u>Hello</u> <u>J@tin</u></h4></marquee><br>";
echo '<pre>';
try {
    $lastModified = array();
    $lastModified[] = date('Y-m-d h:i:s A', '1595526949');
    $lastModified[] = date('Y-m-d h:i:s A', '1595961760');
    $lastModified[] = date('Y-m-d h:i:s A', '1596796197');
    $lastModified[] = date('Y-m-d h:i:s A', '1597046306');
    $lastModified[] = date('Y-m-d h:i:s A', '1608552159');
    $lastModified[] = date('Y-m-d h:i:s A', '1608552168');
    print_r($lastModified);die();
	$helper = Mage::helper('edi/status');
	$header = array('event_code','comment_code','delivery_status');
	
	/*$io = new Varien_Io_File();
    $path = Mage::getBaseDir('var') . DS . 'export' . DS;
    $name = md5(microtime());
    $name = 'deliveryStatusMapping';
    $file = $path . DS . $name . '.csv';
    $io->setAllowCreateFolders(true);
    $io->open(array('path' => $path));
    $io->streamOpen($file, 'w+');
    $io->streamLock(true);
    $io->streamWriteCsv($header);*/

	foreach ($helper->getMapDeliveryStatuses() as $key => $value) {
		if (isset($value['client_comment_event'])) {
			foreach ($value['client_comment_event'] as $key1 => $value1) {
				echo $key.' && '.$key1.' => '.$value1['our_status'];
				echo "<br>";
				// $io->streamWriteCsv(array($key,$key1,$value1['our_status']));
			}
		}
	}

	print_r($helper->getMapCustmerStatuses());
} catch (Exception $e) {
    echo $e->getMessage();
    die;
}
