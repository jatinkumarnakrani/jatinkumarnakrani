    <?php
    require_once "../app/Mage.php";
    Mage::app('default');
    Mage::setIsDeveloperMode(true);
    // Varien_Profiler::enable();
    error_reporting(E_ALL ^ E_DEPRECATED);
    ini_set('display_errors', 1);
    $cityMapping = [
        //'Rate Sheet'    => 'Zipcode',
        'Baton Rouge'        => 'Baton Rouge Special',
        'DFW (0-50)'         => 'DFW Metro',
        'Jacksonville'       => 'Jacksonville METRO',
        'Manchester [NH]'    => 'Manchester - NH',
        'Cincinnati'         => 'Metro-Cincinnati',
        'Columbus'           => 'Metro-Columbus',
        'Detroit'            => 'Metro-Detroit',
        'Indy'               => 'Metro-Indy',
        'Pittsburgh'         => 'Metro-Pittsburgh',
        'Naples'             => 'Naples Metro',
        'Pensacola'          => 'Pensacola METRO',
        'Portland'           => 'Portland Metro',
        'Richmond'           => 'Richmond-METRO',
        'Salem, OR'          => 'Salem/Eugene OR',
        'Eugene, OR'         => 'Salem/Eugene OR',
        'Vero Beach'         => 'Vero Beach METRO',
        'DC METRO'           => 'DC-METRO',
        'San Antonio (0-50)' => 'San Antonio Metro',
        'Austin (0-50)'      => 'Austin Metro',
    ];

    /*
     * https://1stopbedrooms.atlassian.net/browse/SB-1944
     */

    $mapping = [];
    $file    = fopen("SB-1944-Mapping.csv", "r");
    while (!feof($file)) {
        $row              = fgetcsv($file);
        $mapping[$row[0]] = $row[1];
    }
    $mapping = array_filter($mapping);
    // print_r($mapping);
    fclose($file);

    $file     = fopen("SB-1944-Rate.csv", "r");
    $cnt      = 0;
    $rates    = [];
    $location = '';
    function getRange($range)
    {
        $range = str_replace(["–"], "-", $range);
        $range = str_replace(["+"], "- 10000", $range);
        $range = explode("-", $range);
        $range = array_map('trim', $range);
        return $range;
    }
    while (!feof($file)) {
        $row = fgetcsv($file);
        if ($cnt) {
            if ($row[1] == "") {
                $location = $row[0];
            } else {
                $range = getRange($row[0]);
                if (count($range) == 2) {
                    $rates[$location]['range'][] = [
                        'from' => $range[0],
                        'to'   => $range[1],
                        'poi'  => $row[1],
                        'rate' => $row[2],
                    ];
                } else {
                    if (strpos(strtolower($range[0]), 'remote') !== false) {
                        $rates[$location]['remote'][] = [
                            //'else' => $range[0],
                            'poi'  => $row[1],
                            'rate' => $row[2],
                        ];
                    } else {
                        $city = $range[0];
                        if (isset($cityMapping[$city])) {
                            $city = $cityMapping[$city];
                        }
                        switch ($city) {
                            case 'Austin (0-50)':
                                $city = 'Austin Metro';

                                $rates[$location]['city'][$city][] = [
                                    'from' => 0,
                                    'to'   => 50,
                                    'poi'  => $row[1],
                                    'rate' => $row[2],
                                ];
                                break;
                            case 'San Antonio (0-50)':
                                $city = 'San Antonio Metro';

                                $rates[$location]['city'][$city][] = [
                                    'from' => 0,
                                    'to'   => 50,
                                    'poi'  => $row[1],
                                    'rate' => $row[2],
                                ];
                                break;
                            case 'DFW (0-50)':
                                $city = 'DFW Metro';

                                $rates[$location]['city'][$city][] = [
                                    'from' => 0,
                                    'to'   => 50,
                                    'poi'  => $row[1],
                                    'rate' => $row[2],
                                ];
                                break;

                            default:
                                $rates[$location]['city'][$city][] = [
                                    'poi'  => $row[1],
                                    'rate' => $row[2],
                                ];
                                break;
                        }
                    }
                }
            }
        }
        $cnt++;
    }
    // print_r($rates);

    function getRate($rates, $location)
    {
        $rate = [];
        if (isset($location['distance_to_hub']) && !is_numeric($location['distance_to_hub'])) {
            return ['poi' => '', 'rate' => '', 'note' => "Distance to Hub not available."];
        }

        if (isset($rates['city']) && isset($rates['city'][$location['sub_region']])) {
            $cityRate = $rates['city'][$location['sub_region']][0];
            if (isset($cityRate['from']) && $cityRate['from'] <= $location['distance_to_hub'] && $cityRate['to'] >= $location['distance_to_hub']) {
                return ['poi' => $cityRate['poi'], 'rate' => $cityRate['rate']];
            } else {
                return $rates['city'][$location['sub_region']][0];
            }
        }

        if (isset($rates['range']) && isset($location['distance_to_hub'])) {
            $max = [];
            foreach ($rates['range'] as $range) {
                $max[] = (int) $range['to'];
                if ($range['from'] <= $location['distance_to_hub'] && $range['to'] >= $location['distance_to_hub']) {
                    return ['poi' => $range['poi'], 'rate' => $range['rate']];
                }
            }
            $max = max(array_filter($max));
            if (isset($rates['remote']) && isset($location['distance_to_hub'])) {
                return ['poi' => $rates['remote'][0]['poi'], 'rate' => $rates['remote'][0]['rate']];
            }
            if ($max) {
                return ['poi' => '', 'rate' => '', 'note' => "Max Range is {$max}."];
            }
        }
        return ['poi' => '', 'rate' => '', 'note' => "Rate Not available."];
    }

    $allZipCode = array();
    $file   = fopen("SB-1944-Zipcode-all.csv", "r");
    $isHeader = true;
    $header = array("zip code","city","state","surcharge","local_hub","sub_region","distance_to_hub");
    while (!feof($file)) {
        $row = fgetcsv($file);
        if (!$row) continue;
        if ($isHeader) {
            $isHeader = false;
        }else{
            $key = $row[0];
            $row = array_combine($header, $row);
            $allZipCode[$key] = $row;
        }
    }

    $zipcodeFiles = ["Deliveright-zipcodes-coverage-Client.csv"];
    $zipcodeFiles = ["jatin.csv"];
    foreach ($zipcodeFiles as $fname) {
        $header = ["Order ID","PO #","Region","Partner","Delivery Date","Customer Name","Score"];
        $additionalField = array("Invoice Total","zip code","Distance to Hub","poi", "rate", "note","POI Rate");
        $file   = fopen($fname, "r");
        $cnt    = 0;
        $tmp    = [];
        try{
        while (!feof($file)) {
            $row = fgetcsv($file);
            if(!$row) continue;
            $row = array_combine($header, $row);
            if ($cnt == 0) {
                $tmp[] = array_merge($row, $additionalField);
            } else {
                $row['Invoice Total'] = 0;
                $row['zip code'] = '';
                $row['distance_to_hub'] = '';
                $row['sub_region'] = '';

                // $read = Mage::getSingleton('core/resource')->getConnection('core_read');
                // $wgIds = Mage::getStoreConfig('deliveright_review_email/order_api/ship_carrier');
                // $select = $read->select()
                //     ->from(array('at_order' => 'sales_flat_order'),array())
                //     ->join(array('SFOI'=>'sales_flat_order_item'),"at_order.entity_id = SFOI.order_id AND SFOI.parent_item_id IS NULL",array())
                //     ->join(array('SFOIA'=>'sales_flat_order_item_additional'),"SFOI.item_id = SFOIA.item_id",array())
                //     ->join(array('CSCO'=>'ccc_ship_carrier_order'),"at_order.entity_id = CSCO.order_entity_id",array())
                //     ->columns(array(
                //         'invoice_total' => new Zend_db_Expr("IF(SUM(COALESCE(SFOIA.additional_row_total,0)) > 0, SUM(COALESCE(SFOIA.additional_row_total,0)),0)"),
                //     ))
                //     ->where('LOWER(SFOI.sku) NOT LIKE ?', "%warranty%")
                //     ->where('LOWER(SFOI.sku) NOT LIKE ?', "%customized%")
                //     ->where('LOWER(SFOI.sku) NOT LIKE ?', "cp-%")
                //     ->where('LOWER(SFOI.sku) NOT LIKE ?', "%admin_deal_package%")
                //     ->where('CSCO.wg_id IN (?)', explode(',', $wgIds))
                //     ->where('at_order.increment_id = ?', $row['PO #'])
                //     ->group('at_order.entity_id');
                // $invoiceTotal = $read->fetchOne($select);

                $order = Mage::getModel('sales/order')->loadByIncrementId($row['PO #']);
                $additionalRow = array();

                if ($order->getId()) {
                    $items = Mage::getModel('deliveright/order')->setOrderId($order->getId())->getOrderItems();

                    foreach ($items as $item) {
                        $row['Invoice Total'] += (isset($item['retail_value']) && $item['retail_value']) ? $item['retail_value'] : 0;
                    }

                    $zipCode = (int)$order->getShippingAddress()->getPostcode();

                    if ($zipCode) {
                        $row['zip code'] = $zipCode;
                    }

                    if ($zipCode && isset($allZipCode[$zipCode]['sub_region'])) {
                        $row['sub_region'] = $allZipCode[$zipCode]['sub_region'];
                    }

                    if ($zipCode && isset($allZipCode[$zipCode]['distance_to_hub'])) {
                        $row['distance_to_hub'] = $allZipCode[$zipCode]['distance_to_hub'];
                    }
                   
                    if (isset($row['Region']) && $row['Region'] && isset($mapping[$row['Region']]) && isset($rates[$mapping[$row['Region']]]) ) {
                        $additionalRow = getRate($rates[$mapping[$row['Region']]], $row);
                        if (!isset($additionalRow['note'])) {
                            $additionalRow['note'] = '';
                        }
                    } else {
                        $additionalRow = array('poi'=>'', 'rate'=>'','note' => "Rate missing for {$row['Region']}");
                    }
                }else{
                    $additionalRow = array('poi'=>'', 'rate'=>'', 'note' =>"Order not found");
                }

                unset($row['sub_region']);
                $finalRow = array_merge($row,$additionalRow);
                
                $poiRate = '';
                if (isset($finalRow['poi']) && $finalRow['poi'] && isset($finalRow['Invoice Total']) && $finalRow['Invoice Total']) {
                    $poiRate = ($finalRow['Invoice Total'] * $finalRow['poi']) / 100;
                }
                $finalRow['POI Rate'] = $poiRate;

                $tmp[] = $finalRow;
            }
            $cnt++;
        }
        $date = date("Ymd-His");
        $fp = fopen(str_replace(".csv", "-", $fname) . "report-$date.csv", 'w');
        foreach ($tmp as $fields) {
            fputcsv($fp, $fields);
        }
        fclose($fp);
        }catch(Exception $e){
            echo "<pre>";
            echo $e->getMessage();die;
        }
    }