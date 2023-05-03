<?php
class Ccc_Repricer_Model_Cmonitor_Fetc_Upload extends Ccc_Repricer_Model_Cmonitor_Abstract
{
    protected $_competitorId = 12;
    protected $_fileName     = 'competitor_monitor_feed_FETC';

    public function run()
    {
        try {
            $message = "No data prepared to upload.";
            // Prepare ccc_repricer_cmonitor_feed table
            $isTablePrepared = $this->_prepareFeedTable();

            $isCsvPrepared = false;
            if ($isTablePrepared) {
                // Prepare Cmonitor Upload Feed
                $isCsvPrepared = $this->_prepareCsvForUpload();
            }

            if ($isCsvPrepared) {
                $message = "Fetc CSV prepared successfully.";
            } elseif ($isTablePrepared) {
                $message = "Fetc Table prepared successfully.";
            }

        } catch (Exception $e) {
            return $e->getMessage();
        }

        return $message;
    }

    protected function _prepareFeedTable()
    {
        try {
            $resource     = Mage::getSingleton('core/resource');
            $read         = $resource->getConnection('core_read');
            $write        = $resource->getConnection('core_write');
            $lastDelistedDateLimit = Ccc_Repricer_Helper_Data::LAST_DELISTED_DATE_LIMIT;
            $currentDate           = Mage::getModel('core/date')->gmtDate('Y-m-d H:i:s');
            $write->query("DELETE FROM `ccc_repricer_cmonitor_feed` WHERE `competitor_id` = {$this->_competitorId}");

            $select = $read->select()
                ->from(array('q' => $resource->getTableName('catalog/product_flat') . '_1'),
                    array(
                        'product_id'    => 'q.entity_id',
                        'competitor_id' => 'r.competitor_id',
                        'upc'           => 'q.upc',
                        'parent_sku'    => 'q.sku',
                        'type'          => 'q.type_id',
                        'coleman_sku'   => 'r.ref_sku',
                        'coleman_name'  => 'r.ref_name',
                        'coleman_url'   => 'r.ref_url',
                    )
                )
                ->join(array('r' => $resource->getTableName('repricer/repricer')), 'r.product_id = q.entity_id', array())
                ->where('q.visibility = ?', 4) //Catalog, Search
            //->where('IF(q.type_id = "bundle",(r.ref_url IS NOT NULL AND r.ref_url != ""),TRUE)')
                ->where('r.ref_url IS NOT NULL AND r.ref_url != ""')
                ->where('r.competitor_id = ?', $this->_competitorId) // Only Get fetc url data
                ->where('r.ref_sku IS NOT NULL')
                ->where('r.ref_sku != ""')
            //->where('r.ref_product_exists =?',0)
                ->where('r.send_in_feed =?', 1) //We have added new fields to only upload which have flag 1. before that we are using NA(ref_product_exists) flag.
                // ->where("IF(r.last_delisted_date IS NOT NULL, DATEDIFF('$currentDate', r.last_delisted_date) > '$lastDelistedDateLimit', 1)") OLD LOGIC
                ->where("IF((DATEDIFF('$currentDate',r.first_delisted_date) <= $lastDelistedDateLimit) OR r.last_delisted_date IS NULL,true,IF(DATEDIFF('$lastDelistedDateLimit',r.first_delisted_date) % $lastDelistedDateLimit = 0,true,false))")
                ->insertFromSelect('ccc_repricer_cmonitor_feed',
                    array(
                        'product_id',
                        'competitor_id',
                        'upc',
                        'parent_sku',
                        'type',
                        'coleman_sku',
                        'coleman_name',
                        'coleman_url',
                    ), true);
            $write->query($select);

            $select = $read->select()
                ->from(array('s' => 'catalog_product_bundle_selection'),
                    array(
                        'product_id'        => 's.parent_product_id',
                        'competitor_id'     => 'parent_r.competitor_id',
                        'option_product_id' => 'flat.entity_id',
                        'upc'               => 'parent_flat.upc',
                        // 'parent_sku'        => 'parent_map.coleman_sku',
                        'parent_sku'        => 'parent_r.ref_sku',
                        'option_sku'        => 'flat.sku',
                        'coleman_sku'       => 'parent_r2.ref_sku',
                        'coleman_url'       => 'parent_r.ref_url',
                        'coleman_name'      => 'parent_r2.ref_name',
                        new Zend_Db_Expr("'set-simple'"),
                    )
                )
                ->join(array('q' => 'ccc_repricer_cmonitor_feed'), 's.parent_product_id = q.product_id', array())
            // ->join(array('parent_map' => $resource->getTableName('repricer/cmonitordata')), 'parent_map.product_id = q.product_id', array())
                ->join(array('parent_r' => $resource->getTableName('repricer/repricer')), 'parent_r.product_id = q.product_id', array())
                ->join(array('parent_r2' => $resource->getTableName('repricer/repricer')), 'parent_r2.product_id = s.product_id', array())
                ->join(array('flat' => 'catalog_product_flat_1'), 'flat.entity_id = s.product_id', array())
                ->join(array('parent_flat' => 'catalog_product_flat_1'), 'parent_flat.entity_id = q.product_id', array())
                ->join(array('bundle_sel_opt' => 'catalog_product_bundle_option'), 'bundle_sel_opt.option_id = s.option_id', array())
                ->where('q.type = ?', 'bundle')
                ->where('q.competitor_id = ?', $this->_competitorId)
                ->where('parent_r2.product_id > 0')
                ->where('parent_r.competitor_id = ?', $this->_competitorId) // Only Get fetc url data
                // ->where('parent_r2.send_in_feed =?', 1)
                ->where('parent_r2.competitor_id = ?', $this->_competitorId) // Only Get fetc url data
                ->where('parent_r2.ref_sku IS NOT NULL') //Set-simple not coming if ref_sku is not available
                ->where('parent_r2.ref_sku != ""') //Set-simple not coming if ref_sku is not available
            // Exculded these items for additional items option.
                // ->where("IF(parent_r.last_delisted_date IS NOT NULL, DATEDIFF('$currentDate', parent_r.last_delisted_date) > '$lastDelistedDateLimit', 1)") OLD LOGIC
                // ->where("IF(parent_r2.last_delisted_date IS NOT NULL, DATEDIFF('$currentDate', parent_r2.last_delisted_date) > '$lastDelistedDateLimit', 1)") OLD LOGIC
                ->where("IF((DATEDIFF('$currentDate',parent_r.first_delisted_date) <= $lastDelistedDateLimit) OR parent_r.last_delisted_date IS NULL,true,IF(DATEDIFF('$currentDate',parent_r.first_delisted_date) % $lastDelistedDateLimit = 0,true,false))")
                ->where("IF((DATEDIFF('$currentDate',parent_r2.first_delisted_date) <= $lastDelistedDateLimit) OR parent_r2.last_delisted_date IS NULL,true,IF(DATEDIFF('$currentDate',parent_r2.first_delisted_date) % $lastDelistedDateLimit = 0,true,false))")
                ->where("IF(bundle_sel_opt.required = 0,
                        flat.name NOT LIKE '%Throw%' AND
                        flat.name NOT LIKE '%Photo Frame%' AND
                        flat.name NOT LIKE '%lamp%' AND
                        flat.name NOT LIKE '%Vase%' AND
                        flat.name NOT LIKE '%Rug%' AND
                        flat.name NOT LIKE '%wall art%'
                        , 1)"
                )
                ->group('s.product_id') // Unique Set Simple in feed, If set simple assigned in multiple bundle products then it will send only in single bundle product
                ->insertFromSelect('ccc_repricer_cmonitor_feed',
                    array(
                        'product_id',
                        'competitor_id',
                        'option_product_id',
                        'upc',
                        'parent_sku',
                        'option_sku',
                        'coleman_sku',
                        'coleman_url',
                        'coleman_name',
                        'type',
                    ), true);

            $write->query($select);

            $write->query("
                UPDATE ccc_repricer_cmonitor_feed
                SET price = (
                    SELECT `final_price` FROM `catalog_product_index_price` as CPIP
                    WHERE CPIP.`customer_group_id` = 0 AND CPIP.`website_id` = 1 AND CPIP.final_price IS NOT NULL AND CPIP.final_price > 0
                    AND CPIP.`entity_id` = ccc_repricer_cmonitor_feed.product_id
                )
                WHERE type = 'simple' AND competitor_id = {$this->_competitorId};

                UPDATE ccc_repricer_cmonitor_feed
                SET price = (
                    SELECT `bundle_calculated_price` FROM `catalog_product_index_price_calculated` as CPIPC
                    WHERE CPIPC.`customer_group_id` = 0 AND CPIPC.`website_id` = 0 AND CPIPC.`bundle_calculated_price` IS NOT NULL AND CPIPC.`bundle_calculated_price` > 0
                    AND CPIPC.`entity_id` = ccc_repricer_cmonitor_feed.product_id
                )
                WHERE type = 'bundle' AND competitor_id = {$this->_competitorId};

                UPDATE ccc_repricer_cmonitor_feed
                SET price = (
                    SELECT IF(flat.special_price IS NOT NULL,(CPIPC.`selection_price_value` - (CPIPC.`selection_price_value` * (((100 - flat.special_price) / 100)))),CPIPC.`selection_price_value`) FROM `catalog_product_bundle_selection` as CPIPC
                    LEFT JOIN `catalog_product_flat_1` as flat ON flat.entity_id = CPIPC.parent_product_id
                    WHERE CPIPC.`selection_price_value` > 0
                    AND CPIPC.`parent_product_id` = ccc_repricer_cmonitor_feed.product_id
                    AND CPIPC.`product_id` = ccc_repricer_cmonitor_feed.option_product_id
                    GROUP by ccc_repricer_cmonitor_feed.option_product_id
                )
                WHERE type = 'set-simple' AND competitor_id = {$this->_competitorId};

                UPDATE ccc_repricer_cmonitor_feed
                SET category_name = (
                    SELECT GROUP_CONCAT(category_id) FROM catalog_category_product WHERE product_id = ccc_repricer_cmonitor_feed.product_id GROUP BY ccc_repricer_cmonitor_feed.product_id
                )
                WHERE product_id > 0 AND competitor_id = {$this->_competitorId};
            ");

        } catch (Exception $e) {
            Mage::log($e->getMessage(), null, "competitor_monitor_feed_error.log");
            return false;
        }

        return true;
    }

    protected function _prepareCsvForUpload()
    {
        $collection = Mage::getModel('repricer/cmonitor_generate')->setFromCron(true)->setFileName($this->_fileName, $this->_competitorId)->getFilteredCollection(true);
        if ($collection && $collection->count()) {
            Mage::getModel('repricer/cmonitor_generate')->setFromCron(true)->setFileName($this->_fileName, $this->_competitorId)->exportCsv();
        } else {
            return false;
        }
        return true;
    }
}
