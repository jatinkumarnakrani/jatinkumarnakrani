<?php

require_once 'abstract.php';

class Aoe_Scheduler_Shell_Ryder extends Mage_Shell_Abstract
{

    /**
     * Run script
     *
     * @return void
     */
    public function run()
    {
        $action = $this->getArg('action');
        if (empty($action)) {
            echo $this->usageHelp();
        } else {
            $actionMethodName = $action . 'Action';
            if (method_exists($this, $actionMethodName)) {
                $this->$actionMethodName();
            } else {
                echo "Action $action not found!\n";
                echo $this->usageHelp();
                exit(1);
            }
        }
    }

    /**
     * Retrieve Usage Help Message
     *
     * @return string
     */
    public function usageHelp()
    {
        $help    = 'Available actions: ' . "\n";
        $methods = get_class_methods($this);
        foreach ($methods as $method) {
            if (substr($method, -6) == 'Action') {
                $help .= '    -action ' . substr($method, 0, -6);
                $helpMethod = $method . 'Help';
                if (method_exists($this, $helpMethod)) {
                    $help .= $this->$helpMethod();
                }
                $help .= "\n";
            }
        }
        return $help;
    }

    /**
     * Returns the timestamp of the last run of a given job
     *
     * @return void
     */
    public function updateParentAction()
    {
        $readConnection  = Mage::getSingleton('core/resource')->getConnection('core_read');
        $writeConnection = Mage::getSingleton('core/resource')->getConnection('core_write');

        $select = $readConnection->select()
            ->from(['main_table' => 'edicarrier_action_log'], ['id', 'carrier_id', 'shipment_number','ref_number'])
            ->where('order_id > 0')
            ->where('shipment_number != ""')
            ->where('parent_log_id IS NULL')
            ->where('event_code IS NOT NULL')
            ->where('comment_code IS NOT NULL')
            ->limit(40000);

        $result = $readConnection->fetchAll($select);
        foreach ($result as $_log) {
            $select = $readConnection->select()
                ->from(['main_table' => 'edicarrier_action_log'], ['id'])
                ->where('ref_number = ?', $_log['ref_number'])
                ->where('carrier_id = ?', $_log['carrier_id'])
                ->where('shipment_number = ?', $_log['shipment_number'])
                ->where('id < ?', $_log['id'])
                ->where('event_code IS NOT NULL')
                ->where('comment_code IS NOT NULL')
                ->order('id DESC')
                ->limit(1);

            $previous = $readConnection->fetchOne($select);
            $previous = (is_numeric($previous)) ? $previous : 0;

            $sqlQuery = "UPDATE `edicarrier_action_log` SET parent_log_id = $previous WHERE id = {$_log['id']}";
            $writeConnection->exec($sqlQuery);
        }
    }

    public function updateRefNumberAction()
    {
        $collection = Mage::getModel('edi/edicarrier_action_log')->getCollection()
            ->addFieldToFilter('download.file_type','214')
            ->addFieldToFilter('temp_ref_number',['null' => true])
            ->join(['download' => 'edicarrier_downloaded_files'],
                'main_table.file_id = download.id',
                ['file_type','file_path','file_name'],'left')
            ;
        $collection->getSelect()->group('main_table.file_id')->limit(500);

        try {
            if ($collection->count()) {
                foreach ($collection as $file) {
                    $io = new Varien_Io_File();
                    if (!file_exists(Mage::getBaseDir() . DS . $file->getFilePath() . DS . $file->getFileName())) {
                        continue;
                    }
                    $io->open(array('path' => Mage::getBaseDir() . DS . $file->getFilePath()));
                    $data = $io->read($file->getFileName());
                    $data = explode('ST^214^', $data);
                    unset($data[0]);

                    foreach ($data as $key => $string) {
                        $orderId     = null;
                        $shipmentId  = null;
                        $eventCode   = null;
                        $commentCode = null;
                        $ryderRefId  = null;

                        foreach (explode('~', $string) as $stringKey => $str) {

                            if (substr($str, 0, 4) == 'B10^') {
                                $strArray = explode('^', $str);
                                if ($strArray[2] && isset($strArray[2])) {
                                    $ryderRefId = $strArray[2];
                                }
                            }

                            if (substr($str, 0, 4) == 'L11^') {
                                $str        = str_replace('L11^', '', $str);
                                $shipmentId = substr($str, 0, strpos($str, "^"));
                                if (strpos($shipmentId, "-")) {
                                    $orderId = substr($shipmentId, 0, strpos($shipmentId, "-"));
                                } else {
                                    $orderId = substr($shipmentId, 0, 7);
                                }
                            }

                            if (substr($str, 0, 4) == 'AT7^') {
                                $strArray    = explode('^', $str);
                                $eventCode   = $strArray[1];
                                $commentCode = $strArray[2];
                            }
                        }
                        if ($eventCode && $commentCode && $orderId && $shipmentId && $ryderRefId) {
                            $logCollection = Mage::getModel('edi/edicarrier_action_log')->getCollection();
                            $logCollection
                                ->getSelect()
                                ->where('main_table.file_id = ?', $file->getFileId())
                                ->where('main_table.event_code = ?', $eventCode)
                                ->where('main_table.comment_code = ?', $commentCode)
                                ->where('main_table.po_number = ?', $orderId)
                                ->where('main_table.shipment_number = ?', $shipmentId)
                                ->where('main_table.temp_ref_number IS NULL')
                                ->order('id asc')
                                ->limit(1);

                            $log = $logCollection->getFirstItem();

                            if ($log->getId()) {
                                $log->setTempRefNumber($ryderRefId);
                                $log->save();
                            }
                        }
                    }
                }
            }

        } catch (Exception $e) {
            Mage::log($e->getMessage(),null,'edirefnumber.log');
        }
    }


    public function updateSubeventAction()
    {
        try {
            $readConnection  = Mage::getSingleton('core/resource')->getConnection('core_read');
            $writeConnection = Mage::getSingleton('core/resource')->getConnection('core_write');

            $select = $readConnection->select()
                ->from(['edi_parent'=>'edicarrier_action_log'],
                    ['parent_event_code'=>'edi_parent.event_code'
                    ,'parent_event_comment'=>'edi_parent.comment_code']
                    )
                ->join(['edi_child'=>'edicarrier_action_log'],
                    'edi_parent.parent_log_id = edi_child.id AND edi_parent.ref_number = edi_child.ref_number
                    ',[])
                ->join(['edi_shipment'=>'edicarrier_shipment_events'],
                    'edi_shipment.dl_code = edi_child.event_code AND edi_shipment.dl_comment = edi_child.comment_code',
                    [
                        'ry_code'=>'edi_shipment.ry_code',
                        'ry_comment'=>'edi_shipment.ry_comment',
                        'dl_code'=>'edi_shipment.dl_code',
                        'dl_comment'=>'edi_shipment.dl_comment',
                        'rt_code'=>'edi_shipment.rt_code',
                        'rt_comment'=>'edi_shipment.rt_comment',
                        'ry_code_definition'=>'edi_shipment.ry_code_definition',
                        'ry_comment_definition'=>'edi_shipment.ry_comment_definition',
                        'defination'=>'edi_shipment.defination',
                        'instruction'=>'edi_shipment.instruction',
                        'subject'=>'edi_shipment.subject',
                        'group'=>'edi_shipment.group'
                    ]
                )
                ->group('edi_parent.event_code')
                ->group('edi_parent.comment_code')
                ->group('edi_child.event_code')
                ->group('edi_child.comment_code');

            $results = $readConnection->fetchAll($select);

            $SubeventSelect = $readConnection->select()
               ->from(['main_table' => 'edicarrier_shipment_subevents'],
                   ['parent_event_code','parent_event_comment','dl_code','dl_comment']
               );
            $subevents = $readConnection->fetchAll($SubeventSelect);

            foreach ($results as $rkey => $result) {
                foreach ($subevents as $skey => $subevent) {
                    if ($subevent['parent_event_code'] == $result['parent_event_code'] && $subevent['parent_event_comment'] == $result['parent_event_comment'] && $subevent['dl_code'] == $result['dl_code'] && $subevent['dl_comment'] == $result['dl_comment'])
                    {
                       unset($results[$rkey]);
                    }
                }
            }
            $count = 0;

            foreach ($results as $subShipmentData) {
                $parentIdselect = $readConnection->select()
                        ->from(['main_table' => 'edicarrier_shipment_events'], ['event_id'])
                        ->where('main_table.dl_code = ?', $subShipmentData['parent_event_code'])
                        ->where('main_table.dl_comment = ?', $subShipmentData['parent_event_comment'])
                        ->limit(1);
                $parentId = $readConnection->fetchOne($parentIdselect);
                if ($parentId) {
                    $subShipment = Mage::getModel('edi/edicarrier_shipment_subevent');
                    $subShipment->setData($subShipmentData);
                    $subShipment->setParentId($parentId);
                    $subShipment->setCreatedAt(Mage::getSingleton('core/date')->gmtdate('Y-m-d H:i:s'));
                    $subShipment->setUpdatedAt(Mage::getSingleton('core/date')->gmtdate('Y-m-d H:i:s'));
                    $subShipment->save();
                    $count++;
                }
            }
            echo ("$count Subevent imported successfully.");
        } catch (Exception $e) {
            Mage::log($e->getMessage(),null,'edi_old_subevent_import.log');
        }

    }

    public function updateItemDetailsAction()
    {
   
        $collection = Mage::getModel('edi/edicarrier_action_log')->getCollection()
                ->addFieldToFilter('download.file_type','214')
                ->addFieldToFilter('item_details',['null' => true])
                ->addFieldToFilter('item_count',['null' => true])
                ->join(['download' => 'edicarrier_downloaded_files'],
                    'main_table.file_id = download.id',
                    ['file_type','file_path','file_name'],'left');
        $limit =  (int) Mage::getStoreConfig('ediryder/general/pending_order_changes_process_limit') ?? 10 ;

        $collection->getSelect()->group('main_table.file_id')->limit($limit);

        if ($collection->count()) {
            foreach ($collection as $file) {
                $io = new Varien_Io_File();
                if (!file_exists(Mage::getBaseDir() . DS . $file->getFilePath() . DS . $file->getFileName())) {
                    continue;
                }
                $io->open(array('path' => Mage::getBaseDir() . DS . $file->getFilePath()));
                $data = $io->read($file->getFileName());
                $data = explode('ST^214^', $data);
                unset($data[0]);
                
                foreach ($data as $key => $string) {
                    try {  
                        $orderId     = null;
                        $shipmentId  = null;
                        $eventCode   = null;
                        $commentCode = null;
                        $ryderRefId  = null;
                        $partNumbers = null;
                        $itemDetails = null;
                        $itemCount = null;
                        $itemDetail  = [
                            'part_number' => null,
                            'sku_line_number' => null,
                            'carton_number' => null,
                        ];

                        foreach (explode('~', $string) as $stringKey => $str) {

                            if (substr($str, 0, 4) == 'B10^') {
                                $strArray = explode('^', $str);
                                if ($strArray[2] && isset($strArray[2])) {
                                    $ryderRefId = $strArray[2];
                                }
                            }

                            if (substr($str, 0, 4) == 'L11^') {
                                $str        = str_replace('L11^', '', $str);
                                $shipmentId = substr($str, 0, strpos($str, "^"));
                                if (strpos($shipmentId, "-")) {
                                    $orderId = substr($shipmentId, 0, strpos($shipmentId, "-"));
                                } else {
                                    $orderId = substr($shipmentId, 0, 7);
                                }
                            }

                            if (substr($str, 0, 4) == 'AT7^') {
                                $strArray    = explode('^', $str);
                                $eventCode   = $strArray[1];
                                $commentCode = $strArray[2];
                            }

                            if (substr($str, 0, 4) == 'MAN^') {
                                $strArray = explode('^',$str);
                                if (isset($strArray[2]) && $strArray[2]) {
                                    $itemDetail['sku_line_number'] = $strArray[2];  
                                }
                                if (isset($strArray[4]) && $strArray[4] == "MC" && isset($strArray[5]) &&  $strArray[5]) {
                                    $itemDetail['carton_number'] = $strArray[5];
                                }
                                if (isset($strArray[3]) && $strArray[3]) {
                                    $partNumbers[] = str_replace("'", '*', $strArray[3]);
                                    $itemDetail['part_number'] = str_replace("'", '*', $strArray[3]);
                                    $itemDetails[] = $itemDetail;
                                }
                            }

                        }
                        if ($eventCode && $commentCode && $orderId && $shipmentId && $ryderRefId) {

                            $logCollection = Mage::getModel('edi/edicarrier_action_log')->getCollection();
                            $logCollection
                                ->getSelect()
                                ->where('main_table.file_id = ?', $file->getFileId())
                                ->where('main_table.event_code = ?', $eventCode)
                                ->where('main_table.comment_code = ?', $commentCode)
                                ->where('main_table.po_number = ?', $orderId)
                                ->where('main_table.shipment_number = ?', $shipmentId)
                                ->where('main_table.ref_number = ?',$ryderRefId)
                                ->where('main_table.item_details IS NULL')
                                ->where('main_table.item_count IS NULL')
                                ->order('id asc')
                                ->limit(1);

                            

                            $itemCount = count($itemDetails);
                            $itemDetails = ($itemDetails) ? serialize($itemDetails) : NULL;
                            $log = $logCollection->getFirstItem();
                            if ($log->getId()) {                    
                                $log->setItemDetails($itemDetails)
                                    ->setItemCount($itemCount);
                                $log->save();
                                $logData = [
                                    'id' => $log->getId(),
                                ];
                                Mage::log($logData,null,'edi_old_item_details_import_new.log');
                            }
                        }
                    } catch (Exception $e) {
                        Mage::log($e->getMessage(),null,'edi_old_item_details_import_new.log');    
                    }
                }
              
            }
            echo "file item_details and item_count reocrd inserted.";
        }else{
            echo 'No ItemDetails records found.';
        }
    }

    public function updateParentRefNumberFor214Action()
    {
        $success= [];
        $readConnection  = Mage::getSingleton('core/resource')->getConnection('core_read');
        $writeConnection = Mage::getSingleton('core/resource')->getConnection('core_write');
        $limit =  (int) Mage::getStoreConfig('ediryder/general/pending_order_changes_process_limit') ?? 10 ;
        $minSelect = $readConnection
                ->select()
                ->from(array('eal' => 'edicarrier_action_log'), 
                    array('min_id'=> '(MIN(id))'))
                ->where('order_id > 0')
                ->where('shipment_number != ""')
                ->where('parent_ref_number IS NULL')
                ->where('ref_number IS NOT NULL')
                ->where('event_code IS NOT NULL')
                ->where('eal.comment_code IS NOT NULL')
                ->group("eal.shipment_number");

        $select = $readConnection->select()
            ->from(['main_table' => 'edicarrier_action_log'], 
                ['id', 'carrier_id', 'shipment_number','ref_number']
            )
            ->where('order_id > 0')
            ->where('shipment_number != ""')
            ->where('parent_ref_number IS NULL')
            ->where('ref_number IS NOT NULL')
            ->where('event_code IS NOT NULL')
            ->where('comment_code IS NOT NULL')
            ->where('id NOT IN (?)',$minSelect)
            ->limit($limit);

        $result = $readConnection->fetchAll($select);
        if (count($result)) {
            foreach ($result as $_log) {
                try {
                    $subSelect = $readConnection
                            ->select()
                            ->from(array('eal' => 'edicarrier_action_log'), 
                                array('ref_number'))
                            ->where('eal.event_code IS NOT NULL')
                            ->where('carrier_id = ?', $_log['carrier_id'])
                            ->where('shipment_number = ?', $_log['shipment_number'])
                            ->where('eal.comment_code IS NOT NULL')
                             ->where('parent_ref_number IS NULL')
                            ->where('ref_number IS NOT NULL')
                            ->order('id ASC')
                            ->limit(1);
                  
                    $parentRefNumber = $readConnection->fetchOne($subSelect);
                    $parentRefNumber = (is_numeric($parentRefNumber)) ? $parentRefNumber : 0;
                    

                    if ($_log['ref_number']  ==  $parentRefNumber) {
                        $parentRefNumber = 0;
                    }

                    $sqlQuery = "UPDATE `edicarrier_action_log` SET parent_ref_number = $parentRefNumber WHERE id = {$_log['id']}";
                    $writeConnection->exec($sqlQuery);
                    $logData = [
                        'id'=>$_log['id'],
                        'parent_ref_number' => $parentRefNumber,
                        'shipment_number' => $_log['shipment_number']
                    ];
                    Mage::log($logData,null,'edi_old_parent_ref_number_import.log');  
                } catch (Exception $e) {
                     Mage::log($e->getMessage(),null,'edi_old_parent_ref_number_import.log');  
                }
            }
            if(!empty($success)) {
                echo  Mage::helper('edi')->__("Total %s processed for parent_ref_number in ryder action log", count($success));
            }
           
        }else{
            echo 'No records found for update parent ref number for 214 file.';
        }
    }

    public function updateRefNumberForCreateAction()
    {
        $success= [];

        $readConnection  = Mage::getSingleton('core/resource')->getConnection('core_read');
        $writeConnection = Mage::getSingleton('core/resource')->getConnection('core_write');
        $limit =  (int) Mage::getStoreConfig('ediryder/general/pending_order_changes_process_limit') ?? 10 ;

        $select = $readConnection->select()
            ->from(['main_table' => 'edicarrier_action_log'], 
                ['id', 'carrier_id', 'shipment_number','ref_number']
            )
            ->where('order_id > 0')
            ->where('shipment_number != ""')
            ->where('ref_number IS NULL')
            ->where('event_code IS  NULL')
            ->where('comment_code IS  NULL')
            ->where('action = ?','file_generated')
            ->group('shipment_number')
            ->limit($limit);

        $result = $readConnection->fetchAll($select);
        if (count($result)) {
            foreach ($result as $_log) {
                try{
                    $subSelect = $readConnection
                            ->select()
                            ->from(array('eal' => 'edicarrier_action_log'), 
                                array('ref_number'))
                            ->where('eal.event_code IS NOT NULL')
                            ->where('eal.comment_code IS NOT NULL')
                             ->where('ref_number IS NOT NULL')
                            ->where('carrier_id = ?', $_log['carrier_id'])
                            ->where('shipment_number = ?', $_log['shipment_number'])
                            ->order('id ASC')
                            ->limit(1);
                  
                    $refNumber = $readConnection->fetchOne($subSelect);
                    $refNumber = (is_numeric($refNumber)) ? $refNumber : 0;
                    $sqlQuery = "UPDATE `edicarrier_action_log` SET ref_number = $refNumber WHERE id = {$_log['id']}";
                  
                    $writeConnection->exec($sqlQuery);
                    $success[] = $_log['id'];
                } catch (Exception $e) {
                    Mage::log($e->getMessage(),null,'edi_old_ref_number_create_import.log');  
                }
            }
            if(!empty($success)) {
                echo  Mage::helper('edi')->__("Total %s processed for ref_number in ryder action log", count($success));
            }
        }else{
            echo 'No records found for update parent ref number for 214 file.';
        }

    }

    public function updateCanShowAction()
    {
        $variable = Mage::getModel('core/variable')
            ->loadByCode('last_proccesed_can_show_ryder_file_id');
        $lastProcessedFileId = $variable->getPlainValue();
        if (!$variable->getId()) {
            $lastProcessedFileId = 0;
        }
        
        $collection = Mage::getModel('edi/edicarrier_action_log')->getCollection()
                ->addFieldToFilter('download.file_type','214')
                ->addFieldToFilter('main_table.can_show',1)
                ->addFieldToFilter('main_table.file_id',array('gt' => $lastProcessedFileId))
                ->addFieldToFilter('main_table.order_id',array('gt'=> 0 ))
                ->join(['download' => 'edicarrier_downloaded_files'],
                    'main_table.file_id = download.id',
                    ['file_type','file_path','file_name'],'left');

        $limit =  (int) Mage::getStoreConfig('ediryder/general/pending_order_changes_process_limit');
        $limit = ($limit) ? $limit : 10;

        $collection->getSelect()
            ->order('main_table.file_id ASC')
            ->group('main_table.file_id')
            ->limit($limit);

        if ($collection->count()) {
            foreach ($collection as $file) {
                $io = new Varien_Io_File();
                if (!file_exists(Mage::getBaseDir() . DS . $file->getFilePath() . DS . $file->getFileName())) {
                    continue;
                }
                $io->open(array('path' => Mage::getBaseDir() . DS . $file->getFilePath()));
                $data = $io->read($file->getFileName());
                $data = explode('ST^214^', $data);
                unset($data[0]);
                
                foreach ($data as $key => $string) {
                    try {  
                        $orderId     = null;
                        $shipmentId  = null;
                        $eventCode   = null;
                        $commentCode = null;
                        $ryderRefId  = null;
                        $canShow = 1;
                        $SFAddressName = NULL;
                        $DAAddressName = NULL;

                        foreach (explode('~', $string) as $stringKey => $str) {

                            if (substr($str, 0, 4) == 'B10^') {
                                $strArray = explode('^', $str);
                                if ($strArray[2] && isset($strArray[2])) {
                                    $ryderRefId = $strArray[2];
                                }
                            }

                            if (substr($str, 0, 4) == 'L11^') {
                                $str        = str_replace('L11^', '', $str);
                                $shipmentId = substr($str, 0, strpos($str, "^"));
                                if (strpos($shipmentId, "-")) {
                                    $orderId = substr($shipmentId, 0, strpos($shipmentId, "-"));
                                } else {
                                    $orderId = substr($shipmentId, 0, 7);
                                }
                            }

                            if (substr($str, 0, 6) == 'N1^SF^') {
                                $strArray = explode('^',$str);
                                if (isset($strArray[2]) && $strArray[2]) {
                                    $SFAddressName = $strArray[2];
                                }
                            }

                            if (substr($str, 0, 4) == 'AT7^') {
                                $strArray = explode('^', $str);
                                $eventCode = $strArray[1];
                                $commentCode = $strArray[2];
                            }


                            if (substr($str, 0, 6) == 'N1^DA^') {
                                $strArray = explode('^',$str);
                                if (isset($strArray[2]) && $strArray[2]) {
                                    $DAAddressName = $strArray[2];
                                }
                            }

                        }
                        if ($eventCode && $commentCode && $orderId && $shipmentId && $ryderRefId) {
                            $manufacturerOrderCollection = Mage::getModel('manufacturer/order')
                                ->getCollection()
                                ->addFieldToFilter('main_table.increment_id',$orderId)
                                ->addFieldToFilter('main_table.po_number',$shipmentId);
                            $manufacturerOrderCollection
                                ->getSelect()
                                ->reset(Zend_Db_Select::COLUMNS)
                                ->joinLeft(array('SFOA' => 'sales_flat_order_address'),"main_table.order_id = SFOA.parent_id AND SFOA.address_type = 'shipping'",array('customer_name'=>"CONCAT(SFOA.firstname,' ',SFOA.lastname)"));
                            $customerName = $manufacturerOrderCollection->getFirstItem()->getCustomerName();
                            
                            if (strtolower($customerName) != strtolower($DAAddressName) && strtolower($customerName) == strtolower($SFAddressName)) {
                                $canShow = 0;
                            }   

                            $logCollection = Mage::getModel('edi/edicarrier_action_log')->getCollection();
                            $logCollection
                                ->getSelect()
                                ->where('main_table.file_id = ?', $file->getFileId())
                                ->where('main_table.event_code = ?', $eventCode)
                                ->where('main_table.comment_code = ?', $commentCode)
                                ->where('main_table.po_number = ?', $orderId)
                                ->where('main_table.can_show != ?', $canShow)
                                ->where('main_table.shipment_number = ?', $shipmentId)
                                ->where('main_table.ref_number = ?',$ryderRefId)
                                ->order('id asc')
                                ->limit(1);
                            
                            $log = $logCollection->getFirstItem();
                            if ($log->getId()) {                
                                $log->setCanShow($canShow);
                                $log->save();
                                $logData = [
                                    'id' => $log->getId(),
                                    'file_id' => $log->getFileId()
                                ];
                                Mage::log($logData,null,'edi_old_can_show_import.log');
                            }
                        }
                    } catch (Exception $e) {
                        Mage::log($e->getMessage(),null,'edi_old_can_show_import.log');    
                    }
                }
            
            }
            if (isset($file) && $file && $file->getFileId()) {  
                if ($variable->getId())
                {
                   $variable
                        ->setPlainValue($file->getFileId())
                        ->setHtmlValue($file->getFileId())
                        ->save();
                }else{
                    $variable
                        ->setPlainValue($file->getFileId())
                        ->setCode('last_proccesed_can_show_ryder_file_id')
                        ->setName('last_proccesed_can_show_ryder_file_id')
                        ->setHtmlValue($file->getFileId())
                        ->save();
                }          
            }
        }else{
            echo 'No records found.';
        }
    }

    public function updateDisplayRefNumberAction()
    {
        $code = "last_proccesed_shipment_id_for_update_display_ref_number";
        $variable = Mage::getModel('core/variable')
            ->loadByCode($code);
        $lastProcessedShipmentId = $variable->getPlainValue();
        if (!$variable->getId()) {
            $lastProcessedShipmentId = 0;
        }

        $limit =  (int) Mage::getStoreConfig('ediryder/general/pending_order_changes_process_limit');
        $limit = ($limit) ? $limit : 10;
        $readConnection  = Mage::getSingleton('core/resource')->getConnection('core_read');
        $select = $readConnection->select()
            ->from(['main_table' => 'edicarrier_action_log'], ['shipment_number'])
            ->where('order_id > 0')
            ->where("shipment_number > '{$lastProcessedShipmentId}'")
            ->where('event_code IS NOT NULL')
            ->where('comment_code IS NOT NULL')
            ->where('item_count IS NOT NULL')
            ->group('main_table.shipment_number')
            ->order('main_table.shipment_number ASC')
            ->limit($limit);
        $shipmentIds = $readConnection->fetchCol($select);

        if (count($shipmentIds)) {
            Mage::getModel('edi/edicarrier_action_log')->updateDisplayRefNumberAndCanShow($shipmentIds);

            $lastProcessedShipmentId = end($shipmentIds);

            if ($variable->getId())
            {
               $variable
                    ->setPlainValue($lastProcessedShipmentId)
                    ->setHtmlValue($lastProcessedShipmentId)
                    ->save();
            }else{
                $variable
                    ->setPlainValue($lastProcessedShipmentId)
                    ->setCode($code)
                    ->setName($code)
                    ->setHtmlValue($lastProcessedShipmentId)
                    ->save();
            }
            echo count($shipmentIds) ."shipment Proccesed successfully.";
        }else{
            echo "No record found";
        }

    }

    public function RyderShippingMethodAction()
    {
        try {
            $code = "last_proccesed_edicarrier_order_shipment_method";
            $variable = Mage::getModel('core/variable')->loadByCode($code);
            if (!$variable->getId()) {
                $variable->setPlainValue(0)
                    ->setHtmlValue(0)
                    ->setCode($code)
                    ->setName($code)
                    ->save();
                $variable = Mage::getModel('core/variable')->loadByCode($code);
            }
            $processedShipmentCount = 0;
            $limit =  (int) Mage::getStoreConfig('ediryder/general/pending_order_changes_process_limit');
            $limit = ($limit) ? $limit : 100;
            $ediOrderCollection = Mage::getModel('edi/edicarrier_order')->getCollection();
            $ediOrderCollection->getSelect()
                ->where('main_table.shipment_method IS NULL')
                ->order('main_table.entity_id DESC')
                ->limit($limit);
            if ($variable->getId() && $variable->getPlainValue()) {
                $ediOrderCollection->getSelect()
                    ->where('main_table.entity_id < ?',$variable->getPlainValue());
            }
            foreach ($ediOrderCollection as $ediOrder) {
                $downloadCollection = Mage::getModel('edi/edicarrier_downloaded_files')->getCollection();
                $downloadCollection->getSelect()
                    ->where('main_table.file_type =?',204)
                    ->where('main_table.action_type IN(?)',array(00,04))
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
                            if (isset($row[1]) && $row[1] && in_array($row[1], array('BW','HL','RC','WD','CW'))) {
                                $ediOrder->setId($ediOrder->getId())
                                    ->setShipmentMethod($row[1])
                                    ->save();
                                $processedShipmentCount++;
                            }
                        }
                    }
                }
                if ($variable->getId()) {
                    $variable->setPlainValue($ediOrder->getId())
                        ->setHtmlValue($ediOrder->getId())
                        ->save();
                }
            }
            echo "Total {$processedShipmentCount} Shipment Processed";
        } catch (Exception $e) {
            echo $e->getMessage();
        }
    }

    public function updateShipmentTypeForExcludedAction()
    {
        try {
            $readConnection  = Mage::getSingleton('core/resource')->getConnection('core_read');
            $writeConnection = Mage::getSingleton('core/resource')->getConnection('core_write');
            $limit =  (int) Mage::getStoreConfig('ediryder/general/pending_order_changes_process_limit');
            $limit = ($limit) ? $limit : 10;
            $processedCount = 0;

            if (Mage::getStoreConfig('ediryder/ryder_split/exclude_part_ryder')) {
                $excludePartNumbers = Mage::getStoreConfig('ediryder/ryder_split/exclude_part_ryder');
                $excludePartNumbers = str_replace(',',PHP_EOL, $excludePartNumbers);
                $excludePartNumbers = str_replace(';',PHP_EOL, $excludePartNumbers);
                $excludePartNumbers = explode(PHP_EOL, $excludePartNumbers);
                if (count($excludePartNumbers)) {
                    foreach ($excludePartNumbers as $key => $excludePartNumber) {
                        $select = $readConnection->select()
                            ->from(['main_table' => 'edicarrier_action_log'], 
                                ['id', 'shipment_number','item_details']
                            )
                            ->where('main_table.order_id > 0')
                            ->where('main_table.shipment_number != ""')
                            ->where('main_table.ref_number IS NOT NULL')
                            ->where('main_table.event_code IS NOT NULL')
                            ->where('main_table.comment_code IS  NOT NULL')
                            ->where('main_table.can_show != ?',0)
                            ->where('main_table.shipment_type != ?',3)
                            ->where('main_table.item_details LIKE ?','%'.$excludePartNumber.'%')
                            ->order('main_table.id')
                            ->limit($limit);

                        $records = $readConnection->fetchAll($select);
                        $updateIds = [];
                        $shipments = [];
                        foreach ($records as $key => $record) {
                            $itemDetails = $record['item_details'];
                            $itemDetails = unserialize($itemDetails);
                            $partNumbers = array_column($itemDetails,'part_number');
                            if (in_array($excludePartNumber,$partNumbers)) {
                                $updateIds[] = $record['id'];
                                $shipments[] = $record['shipment_number'];
                            }
                        }
                        $shipments = array_unique($shipments);
                            
                        if (!empty($updateIds)) {
                            $processedCount = $processedCount + count($updateIds);
                            $updateIds = implode(",",$updateIds);
                            $writeConnection->query("UPDATE `edicarrier_action_log` SET `can_show` = '0',`shipment_type` = '3' WHERE `edicarrier_action_log`.`id` IN ({$updateIds});");
                            Mage::getModel('edi/edicarrier_action_log')->updateDisplayRefNumberAndCanShow($shipments);
                            $log = [
                                'ids' => $updateIds,
                                'shipments' => $shipments,
                                'exluded_part' => $excludePartNumber
                            ];
                            Mage::log($log,null,'ryder_can_show_exclude_part_number.log');
                        }
                    }
                }
            }
            echo "Total {$processedCount} Action log Processed";
        } catch (Exception $e) {
            echo $e->getMessage();
        }
    }

    public function updateEdicarrierItemQtyAction()
    {
        try {
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
                    Mage::getSingleton('core/resource')->getConnection('core_write')->insert('edicarrier_order_item',$itemData);
                }
                $itemIds[] = $item->getId();
                $item->setId($item->getId())
                    ->setQty(1)
                    ->save();
            }
            if (count($itemIds)) {
                $msg = "Total ".count($itemIds). ' Item Ids updated. '.implode(' | ', $itemIds);
                Mage::log($msg,null,'edicarrier_item_qty.log');
            }
        } catch (Exception $e) {
            Mage::log($e->getMessage(),null,'edicarrier_item_qty.log');
        }
    }

    public function updateSPLTCanShowAction()
    {
        $limit =  (int) Mage::getStoreConfig('ediryder/general/pending_order_changes_process_limit');
        $limit = ($limit) ? $limit : 20;

        $actionCollection  = Mage::getModel('edi/edicarrier_action_log')
            ->getCollection()
            ->addFieldToFilter('main_table.ref_number',array('notnull'=>true))
            ->addFieldToFilter('main_table.event_code','SP')
            ->addFieldToFilter('main_table.comment_code','LT')
            ->addFieldToFilter('main_table.can_show',1);

        $actionCollection->getSelect()
            ->group('main_table.shipment_number')
            ->group('main_table.ref_number')
            ->limit($limit);
        

        if ($actionCollection->count()) {
            foreach ($actionCollection as $key => $_actionLog) {
                Mage::getModel('edi/edicarrier_action_log')
                    ->updateCancalledSPLTShowFlag($_actionLog->getShipmentNumber(),$_actionLog->getRefNumber());
                $shipements[] = $_actionLog->getShipmentNumber();
            }
            Mage::getModel('edi/edicarrier_action_log')->updateDisplayRefNumberAndCanShow($shipements);
        }
        echo $actionCollection->count() . "Shipment Updated.";
    }

    public function updateEdiCarrierItemProductIdsAction()
    {
        try {
            $limit =  (int) Mage::getStoreConfig('ediryder/general/pending_order_changes_process_limit');
            $readConnection  = Mage::getSingleton('core/resource')->getConnection('core_read');
            $writeConnection = Mage::getSingleton('core/resource')->getConnection('core_write');

            $ediItems = $readConnection->select()
                ->from(['EDII'=>'edicarrier_order_item'],['EDII.item_id','EDII.item_number'])
                ->where('EDII.product_id IS NULL')
                ->where('EDII.item_number IS NOT NULL AND EDII.item_number != ""')
                ->limit($limit);
            $ediItems = $readConnection->fetchPairs($ediItems);

            $ediItems = array_chunk($ediItems,1000,true);
            $updateQuery = '';
            $productIdUpdateItemId = [];
            foreach ($ediItems as $itemNumbers) {
                $catalogProductIdSelect = $readConnection->select()
                    ->from(['CPF'=>'catalog_product_entity'],['CPF.sku','CPF.entity_id'])
                    ->where('CPF.sku IN (?)',$itemNumbers);
                $catalogProductIds = $readConnection->fetchPairs($catalogProductIdSelect);

                $productIdsSelect = $readConnection->select()
                    ->from(['SFOI'=>'sales_flat_order_item'],['EDII.item_id','SFOI.product_id'])
                    ->join(['EDII'=>'edicarrier_order_item'],'EDII.order_item_id = SFOI.item_id',[])
                    ->where('EDII.item_id IN (?)',array_keys($itemNumbers));
                $productIds = $readConnection->fetchPairs($productIdsSelect);

                foreach ($itemNumbers as $itemId => $sku) {
                    if (isset($catalogProductIds[$sku])) {
                        $updateQuery .= "UPDATE `edicarrier_order_item` SET `product_id` = $catalogProductIds[$sku] WHERE `edicarrier_order_item`.`item_id` = $itemId;";
                        $productIdUpdateItemId[] = $itemId;
                    }else{
                        if (isset($productIds[$itemId])) {
                            $updateQuery .= "UPDATE `edicarrier_order_item` SET `product_id` = $productIds[$itemId] WHERE `edicarrier_order_item`.`item_id` = $itemId;";
                            $productIdUpdateItemId[] = $itemId;
                        }else{
                            $repSku = preg_replace('/\.{3}/', '', $sku);
                            $productIdSelect = $readConnection->select()
                                ->from(['CMOIA'=>'ccc_manufacturer_order_item_additional'],
                                    ['CMOIA.product_id']
                                )
                                ->join(['CMO'=>'ccc_manufacturer_order'],'CMO.order_id = CMOIA.order_id AND CMO.mfr_id = CMOIA.mfr_id AND CMO.ship_key_id = CMOIA.ship_key_id AND CMO.shipment_id = CMOIA.shipment_id',[])
                                ->join(['EO'=>'edicarrier_order'],'EO.shipment_number = CMO.po_number',[])
                                ->join(['EDII'=>'edicarrier_order_item'],'EO.entity_id = EDII.parent_id',[])
                                ->where('EDII.item_id = ?',$itemId)
                                ->where("CMOIA.sku LIKE '%$repSku%'");

                            if ($productId = $readConnection->fetchOne($productIdSelect)) {
                                $updateQuery .= "UPDATE `edicarrier_order_item` SET `product_id` = $productId WHERE `edicarrier_order_item`.`item_id` = $itemId;";
                                $productIdUpdateItemId[] = $itemId;
                            }
                        }
                    }
                }
            }
            if ($updateQuery) {
                $writeConnection->query($updateQuery);
            }
            if (count($productIdUpdateItemId)) {
                $msg = "Total ".count($productIdUpdateItemId). ' Item Ids updated for Product Id. '.implode(' | ', $productIdUpdateItemId);
                Mage::log($msg,null,'edicarrier_item_product_id.log');
            }
        } catch (Exception $e) {
            Mage::log($e->getMessage(),null,'edicarrier_item_product_id.log');
        }
    }

    public function updateEdiCarrierItemOriPartNumberAction()
    {
        try {
            $limit =  (int) Mage::getStoreConfig('ediryder/general/pending_order_changes_process_limit');
            $readConnection  = Mage::getSingleton('core/resource')->getConnection('core_read');
            $writeConnection = Mage::getSingleton('core/resource')->getConnection('core_write');
            $partNumberAttributeId = Mage::getModel('eav/config')->getAttribute('catalog_product', 'part_number')->getId();

            $ediItems = $readConnection->select()
                ->from(['EDII'=>'edicarrier_order_item'],['EDII.item_id','EDII.product_id'])
                ->where('EDII.original_part_number IS NULL')
                ->where('EDII.product_id IS NOT NULL')
                ->limit($limit);
            $ediItems = $readConnection->fetchPairs($ediItems);

            $ediParts = $readConnection->select()
                ->from(['EDII'=>'edicarrier_order_item'],['EDII.item_id','EDII.part_number'])
                ->where('EDII.product_id IS NOT NULL');
            $ediParts = $readConnection->fetchPairs($ediParts);

            $ediItems = array_chunk($ediItems,1000,true);
            $updateQuery = '';
            $partNumberUpdate = [];

            foreach ($ediItems as $productIds) {
                $catalogProductIdSelect = $readConnection->select()
                    ->from(['e'=>'catalog_product_entity'],['e.entity_id','at_part_number.value'])
                    ->joinLeft(['at_part_number'=>'catalog_product_entity_varchar'],"at_part_number.entity_id = e.entity_id AND at_part_number.attribute_id = $partNumberAttributeId AND at_part_number.store_id = 0",[])
                    ->where('e.entity_id IN (?)',$productIds);
                $catalogProductIds = $readConnection->fetchPairs($catalogProductIdSelect);
                foreach ($productIds as $itemId => $productId) {
                    if (isset($catalogProductIds[$productId])) {
                        $partNumber = $catalogProductIds[$productId];
                        if (strpos($partNumber, ';') !== false) {
                            $partNumbers = explode(';', $partNumber);
                            $itemPart = $ediParts[$itemId];
                            $itemPart = preg_replace('/-Box\d+/', '', $itemPart);
                            $itemPart = str_replace("'",'*', $itemPart);
                            if ($itemPart) {
                                $matchedPart = array_filter($partNumbers, function ($part) use ($itemPart) {
                                    if (strtolower(Mage::helper('core/string')->truncate($part, 30)) == strtolower($itemPart)) {
                                        return true;
                                    }
                                    return false;
                                });
                                if($partNumber = array_pop(array_reverse($matchedPart))){
                                    $updateQuery .= "UPDATE `edicarrier_order_item` SET `original_part_number` = '$partNumber' WHERE `edicarrier_order_item`.`item_id` = $itemId;";
                                    $partNumberUpdate[] = $itemId;
                                }
                            }
                        }else{
                            $updateQuery .= "UPDATE `edicarrier_order_item` SET `original_part_number` = '$partNumber' WHERE `edicarrier_order_item`.`item_id` = $itemId;";
                            $partNumberUpdate[] = $itemId;
                        }
                    }
                }
            }
            if ($updateQuery) {
                $writeConnection->query($updateQuery);
            }
            if (count($partNumberUpdate)) {
                $msg = "Total ".count($partNumberUpdate). ' Item Ids updated for Product Id. '.implode(' | ', $partNumberUpdate);
                Mage::log($msg,null,'edicarrier_item_part_number.log');
            }
        } catch (Exception $e) {
            Mage::log($e->getMessage(),null,'edicarrier_item_part_number.log');
        }
    }

    public function updateEdiCarrierItemParentProductIdAction()
    {
        try {
            $limit =  (int) Mage::getStoreConfig('ediryder/general/pending_order_changes_process_limit');
            $readConnection  = Mage::getSingleton('core/resource')->getConnection('core_read');
            $writeConnection = Mage::getSingleton('core/resource')->getConnection('core_write');

            $variable = Mage::getModel('core/variable')
                ->loadByCode('last_proccesed_ediorder_item_id');
            $lastProcessedItemId = $variable->getPlainValue();
            if (!$variable->getId()) {
                $lastProcessedItemId = 0;
            }

            $ediItems = $readConnection->select()
                ->from(['EDII'=>'edicarrier_order_item'],['EDII.item_id','EDII.order_item_id'])
                ->where('parent_product_id IS NULL')
                ->where('EDII.product_id IS NOT NULL')
                ->where('EDII.item_id > ?',$lastProcessedItemId)
                ->order('EDII.item_id ASC')
                ->limit($limit);
            $ediItems = $readConnection->fetchPairs($ediItems);

            $ediItems = array_chunk($ediItems,1000,true);
            $updateQuery = '';
            $parentProductIdUpdate = [];

            foreach ($ediItems as $orderItemIds) {
                $parentProductIdSelect =  $readConnection->select()
                    ->from(['SFOI'=>'sales_flat_order_item'],['SFOI.item_id','SFOI.parent_product_id'])
                    ->where('SFOI.item_id IN (?)',$orderItemIds);
                $parentProductIds = $readConnection->fetchPairs($parentProductIdSelect);

                $MfrParentProductIdSelect = $readConnection->select()
                    ->from(['CMOIA'=>'ccc_manufacturer_order_item_additional'],
                        ['EDII.item_id','CMOIA.parent_product_id']
                    )
                    ->join(['CMO'=>'ccc_manufacturer_order'],'CMO.order_id = CMOIA.order_id AND CMO.mfr_id = CMOIA.mfr_id AND CMO.ship_key_id = CMOIA.ship_key_id AND CMO.shipment_id = CMOIA.shipment_id',[])
                    ->join(['EO'=>'edicarrier_order'],'EO.shipment_number = CMO.po_number',[])
                    ->join(['EDII'=>'edicarrier_order_item'],'EO.entity_id = EDII.parent_id AND EDII.product_id = CMOIA.product_id',[])
                    ->where('EDII.item_id IN (?)',array_keys($orderItemIds));
                $MfrParentProductIds = $readConnection->fetchPairs($MfrParentProductIdSelect);

                foreach ($orderItemIds as $itemId => $orderItemId) {
                    if (isset($parentProductIds[$orderItemId]) && $parentProductIds[$orderItemId]) {
                        $updateQuery .= "UPDATE `edicarrier_order_item` SET `parent_product_id` = '$parentProductIds[$orderItemId]' WHERE `edicarrier_order_item`.`item_id` = $itemId;";
                        $parentProductIdUpdate[] = $itemId;
                    }else if(isset($MfrParentProductIds[$itemId]) && $MfrParentProductIds[$itemId]){
                        $updateQuery .= "UPDATE `edicarrier_order_item` SET `parent_product_id` = '$MfrParentProductIds[$itemId]' WHERE `edicarrier_order_item`.`item_id` = $itemId;";
                        $parentProductIdUpdate[] = $itemId;
                    }
                }
            }
            if ($updateQuery) {
                $writeConnection->query($updateQuery);
            }
            if (end($parentProductIdUpdate)) {
                $variable
                    ->setPlainValue(end($parentProductIdUpdate))
                    ->setCode('last_proccesed_ediorder_item_id')
                    ->setName('last_proccesed_ediorder_item_id')
                    ->setHtmlValue(end($parentProductIdUpdate))
                    ->save();
            }
            if (count($parentProductIdUpdate)) {
                $msg = "Total ".count($parentProductIdUpdate). ' Item Ids updated for parent product Id. '.implode(' | ', $parentProductIdUpdate);
                Mage::log($msg,null,'edicarrier_item_parent_productId.log');
            }
        } catch (Exception $e) {
            Mage::log($e->getMessage(),null,'edicarrier_item_parent_productId.log');
        }
    }


    public function updateStylineProductIdAction()
    {
        try {
            $limit =  (int) Mage::getStoreConfig('ediryder/general/pending_order_changes_process_limit');
            $readConnection  = Mage::getSingleton('core/resource')->getConnection('core_read');
            $writeConnection = Mage::getSingleton('core/resource')->getConnection('core_write');

            $variable = Mage::getModel('core/variable')
                ->loadByCode('last_proccesed_styline_item_id');
            $lastProcessedItemId = $variable->getPlainValue();
            if (!$variable->getId()) {
                $lastProcessedItemId = 0;
            }
            $productIdUpdateItemId = [];
            $parentIdUpdateItemId = [];
            $updateQuery = '';
            $stylineItems = $readConnection->select()
                ->from(['CSFD'=>'ccc_styline_feed_detail'],['CSFD.entity_id','CSFD.sku'])
                ->where('CSFD.parent_product_id IS NULL')
                ->where('CSFD.product_id IS NULL')
                ->where('CSFD.entity_id > ?',$lastProcessedItemId)
                ->order('CSFD.entity_id ASC')
                ->limit($limit);

            $stylineItems = $readConnection->fetchPairs($stylineItems);
            $stylineItems = array_chunk($stylineItems,200,true);
            foreach ($stylineItems as $itemNumbers) {

                $catalogProductIdSelect = $readConnection->select()
                    ->from(['CPF'=>'catalog_product_entity'],['CPF.sku','CPF.entity_id'])
                    ->where('CPF.sku IN (?)',$itemNumbers);
                $catalogProductIds = $readConnection->fetchPairs($catalogProductIdSelect);

                $MfrProductIdSelect = $readConnection->select()
                    ->from(['CMOIA'=>'ccc_manufacturer_order_item_additional'],
                        ['CMOIA.sku','CMOIA.product_id']
                    )
                    ->join(['CMO'=>'ccc_manufacturer_order'],'CMO.order_id = CMOIA.order_id AND CMO.mfr_id = CMOIA.mfr_id AND CMO.ship_key_id = CMOIA.ship_key_id AND CMO.shipment_id = CMOIA.shipment_id',[])
                    ->join(['CSFD'=>'ccc_styline_feed_detail'],'CSFD.bol = CMO.po_number',[])
                    ->where('CSFD.entity_id IN (?)',array_keys($itemNumbers))
                    ->where('CMOIA.sku IN (?)',$itemNumbers);
                $MfrProductIds = $readConnection->fetchPairs($MfrProductIdSelect);

                $MfrParentProductIdSelect = $readConnection->select()
                    ->from(['CMOIA'=>'ccc_manufacturer_order_item_additional'],
                        ['CMOIA.sku','CMOIA.parent_product_id']
                    )
                    ->join(['CMO'=>'ccc_manufacturer_order'],'CMO.order_id = CMOIA.order_id AND CMO.mfr_id = CMOIA.mfr_id AND CMO.ship_key_id = CMOIA.ship_key_id AND CMO.shipment_id = CMOIA.shipment_id',[])
                    ->join(['CSFD'=>'ccc_styline_feed_detail'],'CSFD.bol = CMO.po_number',[])
                    ->where('CSFD.entity_id IN (?)',array_keys($itemNumbers))
                    ->where('CMOIA.sku IN (?)',$itemNumbers);
                $MfrParentProductIds = $readConnection->fetchPairs($MfrParentProductIdSelect);

                foreach ($itemNumbers as $itemId => $sku) {
                    if (isset($catalogProductIds[$sku]) && $catalogProductIds[$sku]) {
                        $updateQuery .= "UPDATE `ccc_styline_feed_detail` SET `product_id` = $catalogProductIds[$sku] WHERE `ccc_styline_feed_detail`.`entity_id` = $itemId;";
                        $productIdUpdateItemId[] = $itemId;
                    }else if (isset($MfrProductIds[$sku]) && $MfrProductIds[$sku]) {
                        $updateQuery .= "UPDATE `ccc_styline_feed_detail` SET `product_id` = $MfrProductIds[$sku] WHERE `ccc_styline_feed_detail`.`entity_id` = $itemId;";
                        $productIdUpdateItemId[] = $itemId;
                    }

                    if (isset($MfrParentProductIds[$sku]) && $MfrParentProductIds[$sku]) {
                        $updateQuery .= "UPDATE `ccc_styline_feed_detail` SET `parent_product_id` = $MfrParentProductIds[$sku] WHERE `ccc_styline_feed_detail`.`entity_id` =$itemId;";
                        $parentProductIdUpdateItemId[] = $itemId;
                    }
                }
            }

            if ($updateQuery) {
                $writeConnection->query($updateQuery);
            }

            if (end($productIdUpdateItemId)) {
                $variable
                    ->setPlainValue(end($productIdUpdateItemId))
                    ->setCode('last_proccesed_styline_item_id')
                    ->setName('last_proccesed_styline_item_id')
                    ->setHtmlValue(end($productIdUpdateItemId))
                    ->save();
            }
            if (count($productIdUpdateItemId)) {
                $msg = "Total ".count($productIdUpdateItemId). ' Item Ids updated for Product Id. '.implode(' | ', $productIdUpdateItemId);
                Mage::log($msg,null,'styline_item_product_id.log');
            }
        } catch (Exception $e) {
            Mage::log($e->getMessage(),null,'styline_item_product_id.log');
        }
    }


    public function updateZenithProductIdAction()
    {
        try {
            $limit =  (int) Mage::getStoreConfig('ediryder/general/pending_order_changes_process_limit');
            $readConnection  = Mage::getSingleton('core/resource')->getConnection('core_read');
            $writeConnection = Mage::getSingleton('core/resource')->getConnection('core_write');

            $variable = Mage::getModel('core/variable')
                ->loadByCode('last_proccesed_zenith_item_id');
            $lastProcessedItemId = $variable->getPlainValue();
            if (!$variable->getId()) {
                $lastProcessedItemId = 0;
            }
            $productIdUpdateItemId = [];
            $parentIdUpdateItemId = [];
            $updateQuery = '';
            $zenithItems = $readConnection->select()
                ->from(['CZFD'=>'ccc_zenith_feed_details'],['CZFD.entity_id','CZFD.style'])
                ->where('CZFD.parent_product_id IS NULL')
                ->where('CZFD.product_id IS NULL')
                ->where('CZFD.entity_id > ?',$lastProcessedItemId)
                ->order('CZFD.entity_id ASC')
                ->limit($limit);

            $zenithItems = $readConnection->fetchPairs($zenithItems);
            $zenithItems = array_chunk($zenithItems,200,true);
            foreach ($zenithItems as $itemNumbers) {
                $partNumberAttributeId = Mage::getModel('eav/config')->getAttribute('catalog_product', 'part_number')->getId();
                $catalogProductIdSelect = $readConnection->select()
                    ->from(['e'=>'catalog_product_entity'],['e.entity_id','at_part_number.value'])
                    ->joinLeft(['at_part_number'=>'catalog_product_entity_varchar'],"at_part_number.entity_id = e.entity_id AND at_part_number.attribute_id = $partNumberAttributeId AND at_part_number.store_id = 0",[])
                    ->where('e.entity_id IN (?)',$productIds);


            }

            if ($updateQuery) {
                $writeConnection->query($updateQuery);
            }

            if (end($productIdUpdateItemId)) {
                $variable
                    ->setPlainValue(end($productIdUpdateItemId))
                    ->setCode('last_proccesed_zenith_item_id')
                    ->setName('last_proccesed_zenith_item_id')
                    ->setHtmlValue(end($productIdUpdateItemId))
                    ->save();
            }
            if (count($productIdUpdateItemId)) {
                $msg = "Total ".count($productIdUpdateItemId). ' Item Ids updated for Product Id. '.implode(' | ', $productIdUpdateItemId);
                Mage::log($msg,null,'styline_item_product_id.log');
            }
        } catch (Exception $e) {
            Mage::log($e->getMessage(),null,'styline_item_product_id.log');
        }
    }

    // php ryder.php -action updateTrackingHistory -tracking_numbers 1ZE176770322502101,501725802730
    public function updateTrackingHistoryAction()
    {
        try {
            $trackingNumbers = $this->getArg('tracking_numbers');
            if (!$trackingNumbers) {
                throw new Exception("Please add Tracking Numbers.");
            }

            $read = Mage::getSingleton('core/resource')->getConnection('core_read');
            $createdDate =  Mage::getModel('core/date')->gmtdate('Y-m-d H:i:s');
            $limit = 50;
            $sql = array();

            $trackingItems = Mage::getModel('trackingapi/item')->getCollection();
            $trackingItems->getSelect()
                ->joinLeft(array('SFOIPTH'=>'sales_flat_order_item_parts_tracking_history'),"main_table.part_item_id = SFOIPTH.part_item_id",array())
                ->where('SFOIPTH.entity_id IS NULL')
                ->where('main_table.tracking_number IN(?)',explode(',',$trackingNumbers))
                ->limit($limit);

            $select = $read->select()
                ->from(array('CFS'=>'ccc_fedex_statuses'),array('label','status_code'));
            $fedexStatuses = $read->fetchPairs($select);

            $select = $read->select()
                ->from(array('CUS'=>'ccc_ups_statuses'),array('label','status_code'));
            $upsStatuses = $read->fetchPairs($select);

            if ($trackingItems->count()) {
                foreach ($trackingItems as $_item) {
                    $trackingResponse = null;
                    if (strtolower($_item->getShipWith()) == 'fedex') {
                        $trackingResponse = Mage::getModel('usa/shipping_carrier_fedex')->getTracking($_item->getTrackingNumber());
                        $trackingResponse = $trackingResponse->getAllTrackings();
                    }
                    if (strtolower($_item->getShipWith()) == 'ups') {
                        $trackingResponse = Mage::getModel('usa/shipping_carrier_ups')->getTracking($_item->getTrackingNumber());
                        $trackingResponse = $trackingResponse->getAllTrackings();
                    }

                    if ($trackingResponse) {
                        foreach ($trackingResponse as $_tracking) {
                            if ($_tracking->getProgressdetail()) {
                                $progressdetail = $_tracking->getProgressdetail();
                                krsort($progressdetail);
                                $previousDetail = new Varien_Object();
                                foreach ($progressdetail as $detail) {
                                    $detail = new Varien_Object($detail);
                                    $newStatusCode = null;
                                    $oldStatusCode = null;
                                    if (strtolower($_item->getShipWith()) == 'fedex' && isset($fedexStatuses[$detail->getActivity()])) {
                                        $newStatusCode = $fedexStatuses[$detail->getActivity()];
                                        $oldStatusCode = $fedexStatuses[$previousDetail->getActivity()];
                                    }
                                    if (strtolower($_item->getShipWith()) == 'ups' && isset($upsStatuses[$detail->getActivity()])) {
                                        $newStatusCode = $upsStatuses[$detail->getActivity()];
                                        $oldStatusCode = $upsStatuses[$previousDetail->getActivity()];
                                    }
                                    if ($detail->getActivity() != $previousDetail->getActivity()) {
                                        $sql[] = 'INSERT INTO `sales_flat_order_item_parts_tracking_history`(`part_item_id`,`new_status`,`new_status_code`,`old_status`,`old_status_code`,`created_at`) VALUES ('.$_item->getPartItemId().',"'.$detail->getActivity().'","'.$newStatusCode.'","'.$previousDetail->getActivity().'","'.$oldStatusCode.'","'.$createdDate.'")';
                                    }
                                    $previousDetail = $detail;
                                }
                            }
                        }
                    }
                }
                if (!empty($sql)) {
                    foreach (array_chunk($sql, 50) as $value) {
                        $read->exec(implode(';', $value));
                    }
                }
            }
        } catch (Exception $e) {
            echo $e->getMessage()."\n";
        }
    }

    public function getAsheleyProcessFileReportAction()
    {
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
                $fileContent = $this->readXmlFile($shippingFilesDir.$fileDetails->getText());
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
    }

    public function readXmlFile($filePath)
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
}

$shell = new Aoe_Scheduler_Shell_Ryder();
$shell->run();
