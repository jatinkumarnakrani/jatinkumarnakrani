<?php
class Ccc_Repricer_Model_Cmonitor_Generate extends Mage_Core_Model_Abstract
{
    protected $_Categories = array();
    protected $_parent     = array();
    protected $_fromCron   = false;
    protected $_fileName   = null;
    protected $_file   = null;
    protected $_competitorId = null;
    protected $_queryOffset  = 0;

    public function _construct()
    {
        parent::_construct();
    }

    public function setFileName($fileName, $competitorId)
    {
        if ($this->_fromCron && $fileName && $competitorId) {
            //$this->_file = "$fileName.csv";
            $this->_file = "$fileName.csv";
            $this->_competitorId = $competitorId;
        }

        return $this;
    }

    public function getFileName()
    {
        // If Runs From Cron
        if ($this->_fromCron && $this->_file) {
            return $this->_file;
        }

        if (!$this->_fileName) {
            $this->_fileName = $this->_file.date('YmdHis') . '.csv';
        }
        return $this->_fileName;
    }

    public function getHeaders()
    {
        $header = array(
            'UPC'               => array('case' => 'value', 'getter' => 'upc'),
            'product_id'        => array('case' => 'value', 'getter' => 'product_id'),
            'option_product_id' => array('case' => 'value', 'getter' => 'option_product_id'),
            'parent_sku'        => array('case' => 'value', 'getter' => 'parent_sku'),
            'option_sku'        => array('case' => 'value', 'getter' => 'option_sku'),
            'Product Name'      => array('case' => 'value', 'getter' => 'product_name'),
            'Price'             => array('case' => 'price', 'getter' => 'price'),
            'Shipping'          => array('case' => 'model', 'getter' => 'getShipCharges', 'type' => 'self'),
            'Type'              => array('case' => 'value', 'getter' => 'type'),
            'Category Name'     => array('case' => 'model', 'getter' => 'category', 'type' => 'self'),
            'Brand Name'        => array('case' => 'value', 'getter' => 'brand_name'),
            'Product Page URL'  => array('case' => 'value', 'getter' => 'product_page_url'),
            'Product Image URL' => array('case' => 'value', 'getter' => 'product_image_url'),
            'Collection Type'   => array('case' => 'value', 'getter' => 'collection_type'),
        );

        if(strpos(strtolower($this->_file),'apc') !== FALSE){      //APC
            $header += array(
                'APC SKU'       => array('case' => 'value', 'getter' => 'coleman_sku'),
                'APC Name'       => array('case' => 'value', 'getter' => 'coleman_name'),
                'APC URL'       => array('case' => 'value', 'getter' => 'coleman_url')
            );
        }elseif(strpos(strtolower($this->_file),'bfd') !== FALSE){      //BFD
            $header += array(
                'BFD SKU'       => array('case' => 'value', 'getter' => 'coleman_sku'),
                'BFD Name'       => array('case' => 'value', 'getter' => 'coleman_name'),
                'BFD URL'       => array('case' => 'value', 'getter' => 'coleman_url')
            );
        }elseif(strpos(strtolower($this->_file),'fc') !== FALSE){       //FC
            $header += array(
                'FC SKU'       => array('case' => 'value', 'getter' => 'coleman_sku'),
                'FC Name'      => array('case' => 'value', 'getter' => 'coleman_name'),
                'FC URL'       => array('case' => 'value', 'getter' => 'coleman_url')
            );
        }elseif(strpos(strtolower($this->_file),'gdk') !== FALSE){      //GDK
            $header += array(
                'GDK SKU'       => array('case' => 'value', 'getter' => 'coleman_sku'),
                'GDK Name'      => array('case' => 'value', 'getter' => 'coleman_name'),
                'GDK URL'       => array('case' => 'value', 'getter' => 'coleman_url')
            );
        }elseif(strpos(strtolower($this->_file),'tch') !== FALSE){      //TCH
            $header += array(
                'TCH SKU'       => array('case' => 'value', 'getter' => 'coleman_sku'),
                'TCH Name'      => array('case' => 'value', 'getter' => 'coleman_name'),
                'TCH URL'       => array('case' => 'value', 'getter' => 'coleman_url')
            );
        }elseif(strpos(strtolower($this->_file),'fetc') !== FALSE){      //FETC
            $header += array(
                'FETC SKU'  => array('case' => 'value', 'getter' => 'coleman_sku'),
                'FETC Name' => array('case' => 'value', 'getter' => 'coleman_name'),
                'FETC URL'  => array('case' => 'value', 'getter' => 'coleman_url')
            );
        }elseif(strpos(strtolower($this->_file),'hgs') !== FALSE){    //HGS
            $header += array(
                'Hgs SKU'       => array('case' => 'value', 'getter' => 'coleman_sku'),
                'Hgs Name'      => array('case' => 'value', 'getter' => 'coleman_name'),
                'Hgs URL'       => array('case' => 'value', 'getter' => 'coleman_url')
            );
        }elseif(strpos(strtolower($this->_file),'ovs') !== FALSE){    //OVS
            $header += array(
                'OVS SKU'       => array('case' => 'value', 'getter' => 'coleman_sku'),
                'OVS Name'      => array('case' => 'value', 'getter' => 'coleman_name'),
                'OVS URL'       => array('case' => 'value', 'getter' => 'coleman_url')
            );
        }elseif(strpos(strtolower($this->_file),'fp') !== FALSE){       //FC
            $header += array(
                'FP SKU'       => array('case' => 'value', 'getter' => 'coleman_sku'),
                'FP URL'       => array('case' => 'value', 'getter' => 'coleman_url')
            );
        }elseif(strpos(strtolower($this->_file),'hnl') !== FALSE){       //FC
            $header += array(
                'HNL SKU'       => array('case' => 'value', 'getter' => 'coleman_sku'),
                'HNL Name'      => array('case' => 'value', 'getter' => 'coleman_name'),
                'HNL URL'       => array('case' => 'value', 'getter' => 'coleman_url')
            );
        } elseif(strpos(strtolower($this->_file),'competitor') !== FALSE){    //colaman
            $header += array(
                'Coleman SKU'       => array('case' => 'value', 'getter' => 'coleman_sku'),
                'Coleman Name'      => array('case' => 'value', 'getter' => 'coleman_name'),
                'Coleman URL'       => array('case' => 'value', 'getter' => 'coleman_url'),
                'Priority'          => array('case' => 'value', 'getter' => 'priority')
            );
        }

        return $header;
    }

    public function getExportDir()
    {
        return Mage::getBaseDir('var'). DS. 'export' . DS . 'ftp' . DS . 'cmonitor' . DS . 'upload' . DS;
    }

    public function setFromCron($value)
    {
        $this->_fromCron = (bool) $value;
        return $this;
    }

    protected function prepareRow($product, $selection = null)
    {
        $row = array();
        foreach ($this->getHeaders() as $key => $_attribute) {
            $rowValue = '';
            switch ($_attribute['case']) {
                case 'value':
                    if (isset($product[$_attribute['getter']])) {
                        $rowValue = $product[$_attribute['getter']];
                    }
                    break;
                case 'price':
                    if (isset($product[$_attribute['getter']])) {
                        $rowValue = number_format($product[$_attribute['getter']], 2);
                    }
                    break;
                case 'model':
                    if ($_attribute['type'] == 'self') {
                        $rowValue = call_user_func(array($this, $_attribute['getter']), array($product, &$row));
                    }
                    break;
            }
            $row[$key] = Mage::helper('core/string')->cleanString(
                Mage::helper('repricer')->cleanString($rowValue)
            );
        }
        unset($rowValue);
        return $row;
    }

    public function category($args)
    {
        $product = $args[0];

        if (!$this->_Categories) {
            $this->_initProductCategory();
        }
        $categories = array();
        if (isset($product['category_name'])) {
            if (isset($this->_Categories[$product['category_name']])) {
                if (isset($this->_parent[$product['category_name']])) {
                    if (isset($this->_Categories[$this->_parent[$product['category_name']]])) {
                        $categories[] = $this->_Categories[$this->_parent[$product['category_name']]];
                    }
                }
                $categories[] = $this->_Categories[$product['category_name']];
            }
            return implode('/', array_unique($categories));
        }
        return '';
    }

    protected function _initProductCategory()
    {
        $categories = Mage::getSingleton('catalog/category')
            ->getTreeModel()
            ->getCollection()
            ->addFieldToFilter('level', array('gt' => 1));

        foreach ($categories as $_category) {
            $this->_Categories[$_category->getId()] = $_category->getName();
            $this->_parent[$_category->getId()]     = $_category->getParentId();
        }
        unset($categories);
        return $this;
    }

    public function getShipCharges($args)
    {
        $product = $args[0];
        $row     = $args[1];

        $shipMethods = $product['shipping'];
        if (!is_array($shipMethods)) {
            $shipMethods = array($shipMethods);
        }

        if (!Mage::helper('cccsales')->isEligibleForFreeWhiteGlove($row['Price']) || (in_array(17086, $shipMethods) || in_array(17085, $shipMethods))) {
            return 0;
        } else {
            return 99.99;
        }
    }

    public function getFilteredCollection($allData = false)
    {
        try {
            $collection = Mage::getModel('repricer/cmonitor_feed')->getCollection();

            $collection->getSelect()->joinLeft(array('flat' => 'catalog_product_flat_1'), 'flat.entity_id = main_table.product_id',
                    array(
                        'product_name'      => 'IF(flat2.name IS NOT NULL,flat2.name,flat.name)',
                        'shipping'          => 'flat.ship_method',
                        'brand_name'        => 'flat.brand_value',
                        'product_image_url' => 'IF(flat2.small_image IS NOT NULL, flat2.small_image, flat.small_image)',
                        'collection_type'   => 'IF(main_table.type = "set-simple",flat2.collection_type_value,flat.collection_type_value)',
                        'product_page_url'  => 'IF(cur2.request_path IS NOT NULL, cur2.request_path, cur.request_path)',
                    )
                )
                ->joinLeft(array('flat2' => 'catalog_product_flat_1'), 'flat2.entity_id = main_table.option_product_id', array())
                ->joinLeft(array('cur' => 'core_url_rewrite'), 'main_table.product_id = cur.product_id AND `cur`.`is_system` = 1 AND `cur`.`category_id` IS NULL', array())
                ->joinLeft(array('cur2' => 'core_url_rewrite'), 'main_table.option_product_id = cur2.product_id AND `cur2`.`is_system` = 1 AND `cur2`.`category_id` IS NULL', array())
                ->order('main_table.product_id ASC')
                ->order('type ASC')
                ->group('main_table.feed_item_id');

            if ($this->_competitorId == 1) {
                $collection->getSelect()
                    ->joinLeft(array('repricer' => 'ccc_repricer'), 'main_table.product_id = repricer.product_id AND repricer.competitor_id = 1', array('repricer.priority', 'repricer.use_in_rule'))
                    ->where('main_table.competitor_id = ?', $this->_competitorId);
                    // ->joinLeft(array('repricer' => 'ccc_repricer'), 'main_table.product_id = repricer.product_id AND repricer.competitor_id = 1', array('priority' => 'IF(repricer.priority = 1,"Yes","No")'));
            } elseif($this->_competitorId == 3) {
                $collection->getSelect()->where('main_table.competitor_id IN (?)', array(3, 4, 5, 6));
                $collection->getSelect()
                    ->joinLeft(array('repricer' => 'ccc_repricer'), "main_table.product_id = repricer.product_id AND repricer.competitor_id IN (3,4,5,6)", array('repricer.use_in_rule'));
            } else {
                $collection->getSelect()->where('main_table.competitor_id = ?', $this->_competitorId);
                $collection->getSelect()
                    ->joinLeft(array('repricer' => 'ccc_repricer'), "main_table.product_id = repricer.product_id AND repricer.competitor_id = $this->_competitorId", array('repricer.use_in_rule'));
            }

            if ($allData) {
                $collection->getSelect()->where('repricer.use_in_rule = ?', 1);
            } else {
                $collection->getSelect()->limit(10000,$this->_queryOffset);
            }

            return $collection;

        } catch (Exception $e) {
            Mage::log($e->getMessage(), null, 'feed.log');
        }
        return;
    }

    public function exportCsv()
    {
        try {
            $this->_initProductCategory();

            $fh = new Varien_Io_File();
            $fh->setAllowCreateFolders(true);
            $fh->open(array('path' => $this->getExportDir()));
            $fh->streamOpen($this->getFileName(), 'w');
            $fh->streamWriteCsv(array_keys($this->getHeaders()));
            $defaultStoreId = Mage::app()->getWebsite(true)->getDefaultGroup()->getDefaultStoreId();

            $baseUrl = Mage::getUrl('', array('_secure' => true,'_store' => $defaultStoreId));
            $baseUrlMedia = Mage::getUrl('media', array('_secure' => true,'_store' => $defaultStoreId));
            /*
            $baseUrl      = str_replace('index.php/', '', Mage::getBaseUrl());
            $baseUrlMedia = Mage::getBaseUrl('media');
            */

            $dataExists = 0;
            $randomProduct = null;
            for($i=0;$i<=7;$i++) {
                $collection = $this->getFilteredCollection();

                $this->_queryOffset = $this->_queryOffset + 10000;
                $collectionCount = $collectionCount + $collection->count();
                if ($collection && $collection->count()) {
                    try {
                        foreach ($collection->getData() as $product) {
                            $randomProduct = $product;
                            if ($product['use_in_rule'] == 1) {
                                unset($product['use_in_rule']);
                                $product['product_page_url']  = $baseUrl . $product['product_page_url'];
                                $product['product_image_url'] = $baseUrlMedia . DS . 'catalog/product' . $product['product_image_url'];
                                $fh->streamWriteCsv($this->prepareRow($product));
                                unset($product);
                                $dataExists = 1;
                            }
                        }
                    } catch (Exception $e) {
                        Mage::log($e->getMessage(), null, 'feed.log');
                    }
                }
            }

            if (!$dataExists && $randomProduct) {
                try {
                    unset($randomProduct['use_in_rule']);
                    $randomProduct['product_page_url']  = $baseUrl . $randomProduct['product_page_url'];
                    $randomProduct['product_image_url'] = $baseUrlMedia . DS . 'catalog/product' . $randomProduct['product_image_url'];
                    $fh->streamWriteCsv($this->prepareRow($randomProduct));
                    unset($randomProduct);
                } catch (Exception $e) {
                    Mage::log($e->getMessage(), null, 'feed.log');
                }
            }
            $fh->streamClose();
            return ($this->_fromCron) ? $this->getFileName() : $fh->read($this->getFileName());
        } catch (Exception $ex) {
            Mage::log($ex->getMessage(), null, 'csv_generate.log');
        }
        return;
    }

}
