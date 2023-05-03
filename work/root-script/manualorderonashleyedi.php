<?php
require_once "../app/Mage.php";
Mage::app();
error_reporting(E_ALL | E_STRICT);
ini_set('display_errors', 1);
?>

<?php if ($_POST):

    $fileName = $_POST["file_name"];
    $logModel = Mage::getModel('edi/read_log');
    $logModel->setFolderPath("/CUSTEDI/3061700_01-/TESTS")
        ->setReadDate(date("Y-m-d H:i:s"))
        ->setActionType(Furnique_Edi_Model_Read_Log::ACTION_TYPE_DOWNLOAD)
        ->setFileDate(date("Y-m-d H:i:s"))
        ->setFileName($fileName)
        ->save();
    Mage::getModel('edi/shiftingprocess')->readAndShiftFileToTargetLocation();
        ?>
    <a href="http://osb.cybercom.in/developer/jatin/root-script/manualorderonashleyedi.php">Go Back</a>

    <?php else: ?>

<form name="frmonevtory" method="post" action="http://osb.cybercom.in/developer/jatin/root-script/manualorderonashleyedi.php">
<table>
    <tr>
        <td>file_name</td>
        <td><input type="text" name="file_name" value=""></td>
    </tr>
    <tr>
        <td><input type="submit" name="submit" value="submit"></td>
    </tr>
</table>
</form>
<?php endif;?>