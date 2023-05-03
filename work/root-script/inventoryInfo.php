<?php
require_once "../app/Mage.php";
Mage::app();
error_reporting(E_ALL | E_STRICT);
ini_set('display_errors', 1);
?>

<?php if ($_POST):

    if ((int) $_POST["next_number_of_days"] > 60) {
        echo "Please add/delete data for next 45 days only.";die;
    }

    $brandId = (int)$_POST["brand"];

    $itemids = array();
    $items   = explode(",", (string) trim($_POST["item_number"]));
    if (count($items)) {
        foreach ($items as $_item) {
            $query  = "SELECT edi_item_id FROM edi_item WHERE item_number like '" . (string) $_item . "' AND brand_id = ".$brandId;
            $itemId = Mage::getSingleton('core/resource')->getConnection('core_read')->fetchOne($query);
            if (!$itemId) {
                echo $itemId . "This Edi Item is not exist.<br/>";
                continue;
            }

            $itemids[$itemId] = $_item;

            $avail_date = date("Y-m-d", strtotime($_POST["avail_date"]));
            $qty        = (int) $_POST["avail_qty"];

            if (isset($_POST["submit"])) {

                $query = "DELETE FROM edi_item_inventory WHERE item_id = {$itemId} AND avail_date = '{$avail_date}'";
                Mage::getSingleton('core/resource')->getConnection('core_write')->query($query);

                if ($brandId == 13863) {
                    $wareHouseIds = array(6, 7, 11, 34, 61, 84);
                } else {
                    $wareHouseIds = array(1);
                }

                foreach ($wareHouseIds as $wh) {
                    if ((int) $_POST["next_number_of_days"] > 0) {
                        for ($i = 0; $i <= (int) $_POST["next_number_of_days"]; $i++) {
                            $date  = date("Y-m-d", strtotime($avail_date . " +" . $i . " days"));
                            $query = "INSERT INTO `edi_item_inventory`(`warehouse_id`, `item_id`, `brand_id`,`avail_date`, `avail_qty`) VALUES ('" . $wh . "','" . $itemId . "','" . $brandId . "', '" . $date . "', '" . $qty . "')";

                            Mage::getSingleton('core/resource')->getConnection('core_write')->query($query);
                        }
                    } else {
                        $query = "INSERT INTO `edi_item_inventory`(`warehouse_id`, `item_id`, `brand_id`,`avail_date`, `avail_qty`) VALUES ('" . $wh . "','" . $itemId . "','" . $brandId . "', '" . $avail_date . "', '" . $qty . "')";
                        Mage::getSingleton('core/resource')->getConnection('core_write')->query($query);
                    }
                }
            } elseif (isset($_POST["delete"])) {
            if ((int) $_POST["next_number_of_days"] > 0) {
                $date = date("Y-m-d", strtotime($avail_date . " +" . (int) $_POST["next_number_of_days"] . " days"));
                var_dump($date);
                $query = "DELETE FROM edi_item_inventory WHERE item_id = {$itemId} AND avail_date <= '{$date}'";
                Mage::getSingleton('core/resource')->getConnection('core_write')->query($query);
            } else {
                $query = "DELETE FROM edi_item_inventory WHERE item_id = {$itemId} AND avail_date = '{$avail_date}'";
                Mage::getSingleton('core/resource')->getConnection('core_write')->query($query);
            }
        }
    }
}
?>
<a href="http://osb.cybercom.in/developer/jatin/root-script/inventoryInfo.php">Go Back</a>
<?php
if ($itemids) {
    $query = "SELECT * FROM edi_item_inventory WHERE item_id IN (" . implode(",", array_keys($itemids)) . ") AND DATE(`avail_date`) >= DATE('" . date('Y-m-d') . "')";

    $result = Mage::getSingleton('core/resource')->getConnection('core_read')->fetchAll($query);
    if (count($result)) {
        ?>

                <table border="1">
                    <tr>
                        <td>Item Number</td>
                        <td>WAREHOUSE</td>
                        <td>Date</td>
                        <td>QTY</td>
                    </tr>
                    <?php foreach ($result as $_item): ?>
                        <tr>
                            <td><?php echo (string) trim($itemids[$_item["item_id"]]) . "(" . $_item["item_id"] . ")"; ?></td>
                            <td><?php echo $_item["warehouse_id"]; ?></td>
                            <td><?php echo $_item["avail_date"]; ?></td>
                            <td><?php echo $_item["avail_qty"]; ?></td>
                        </tr>
                    <?php endforeach;?>
            </table>
            <?php
} else {
        echo "No Inventory Records found for this Item";
    }
}
?>


<?php else: ?>

<form name="frmonevtory" method="post" action="http://osb.cybercom.in/developer/jatin/root-script/inventoryInfo.php">
<table>
    <tr>
        <td>Item Number</td>
        <td><input type="text" name="item_number" value="B473-88"></td>
    </tr>
    <tr>
        <td>Brand</td>
        <td>
            <select name="brand">
                <option value="13863">Ashley</option>
                <option value="95">Warehouse</option>
            </select>
        </td>
    </tr>
    <tr>
        <td>Date</td>
        <td><input type="date" name="avail_date" date-format="DD-MMMM-YYYY" value=""></td>
    </tr>
    <tr>
        <td>Qty</td>
        <td><input type="text" name="avail_qty" value="10"></td>
    </tr>
    <tr>
        <td>From Selected Date to Next Number Of days</td>
        <td><input type="text" name="next_number_of_days" value="10"></td>
    </tr>
    <tr>
        <td><input type="submit" name="submit" value="submit"></td>
        <td><input type="submit" name="delete" value="Delete"></td>
    </tr>
</table>
</form>
<?php endif;?>