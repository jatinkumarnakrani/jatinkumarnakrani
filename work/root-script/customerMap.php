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
	$helper = Mage::helper('edi/status');
	print_r($helper->getMapCustmerStatuses());
} catch (Exception $e) {
    echo $e->getMessage();
    die;
}
