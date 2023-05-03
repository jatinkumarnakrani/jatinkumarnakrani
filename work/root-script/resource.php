<?php
require_once "../app/Mage.php";
Mage::app('default');
Mage::setIsDeveloperMode(true);
// Varien_Profiler::enable();
error_reporting(E_ALL ^ E_DEPRECATED);
ini_set('display_errors', 1);
class Resource
{
    protected $_nodes = array();
    protected $_rowStructure = array();

    public function getChild($nodes,$parentKey = null)
    {
        foreach ($nodes as $key => $value) {
            $title = (isset($value['title'])) ? $value['title'] : null;
            if ($parentKey) {
                $this->_nodes[$parentKey.'/'.$key] = $title;
            }else{
                $this->_nodes[$key] = $title;
            }
            if (isset($value['children'])) {
                if ($parentKey) {
                    $key = $parentKey.'/'.$key;
                }
                $this->getChild($value['children'],$key);
            }
        }
    }

    public function prepareNodes()
    {
        // $configNode = Mage::getConfig()->getNode()->asArray();
        $configNode = Mage::getModel('admin/roles')->getResourcesTree()->asArray();
        $this->getChild($configNode);
        /*if (isset($configNode['adminhtml']['acl']['resources'])) {
            $nodes = $configNode['adminhtml']['acl']['resources'];
            $this->getChild($nodes);
        }*/
    }

    public function getRowStructure()
    {
        if (!$this->_rowStructure) {
            $read = Mage::getSingleton('core/resource')->getConnection('core_read');
            $select = $read->select()
                ->from(array('AR'=>'admin_role'),array('role_name','value'=>new Zend_Db_Expr("'deny'")))
                ->where('AR.role_type = ?','G');
            $header = $read->fetchPairs($select);
            $row = array('Resource Code'=>null,'Resource Name'=>null);
            $this->_rowStructure = $row + $header;
        }
        return $this->_rowStructure;
    }

    public function generateFile()
    {
        $finalArray = array();
        $row = $this->getRowStructure();
        $row = array_keys($row);
        $finalArray[] = array_combine($row, $row);
        $read = Mage::getSingleton('core/resource')->getConnection('core_read');
        foreach ($this->_nodes as $key => $value) {
            $select = $read->select()
                ->from(array('AR'=>'admin_rule'))
                ->join(array('AR1'=>'admin_role'),"AR.role_id=AR1.role_id",array('role_name'))
                ->where('AR.resource_id = ?',$key);
            $data = $read->fetchAll($select);
            $row = $this->getRowStructure();
            foreach ($data as $rule) {
                $rule = new Varien_Object($rule);
                $row[$rule->getRoleName()] = $rule->getPermission();
                $row['Resource Code'] = $key; 
                $row['Resource Name'] = $value;
            }
            if ($row['Resource Code']) {
                $finalArray[] = $row;
            }
        }
        $fileName = "permissions.csv";
        $var_csv = new Varien_File_Csv();
        $var_csv->saveData($fileName, $finalArray);
        
        $url = Mage::getBaseDir().DS.'root-script'.DS.$fileName;
        if(file_exists($url)) {
            header('Content-Description: File Transfer');
            header('Content-Type: application/octet-stream');
            header('Content-Disposition: attachment; filename="'.basename($url).'"');
            header('Content-Length: ' . filesize($url));
            header('Pragma: public');
            flush();
            readfile($url,true);
        }
    }
}
$resource = new Resource();
$resource->prepareNodes();
$resource->generateFile();