<?php 

set_include_path(get_include_path() . PATH_SEPARATOR . 
                 __DIR__ . '/../simpletest' . PATH_SEPARATOR . 
                 __DIR__ . '/..');
require_once('autorun.php'); 
require_once('openxid_class.php');

/**
 * The class under test
 * Is extended for enabling Unit Test:
 *  - Exposing protected information to the unit test
 *  - Enabling simpler handling of WS object data (NS not needed)
 */
class utOpenXId extends openXId {
  function __construct($inifile, $silent = false) {
    parent::__construct($inifile, $silent);
    $this->aaa = new utAaa();
  }
  
  /**
   * Determines whether the aaa class returns failure
   * @param boolean $authentication Determines whether the aaa class returns failure
   */
  function _setAuthenticationResult($authentication) {
    $this->aaa->authentication = $authentication;
  }
  
  /**
   * Add namespace to object structure
   * @param string $namespace Namespace to be added
   * @param object $obj The object to add namespace
   * @return object The namespace enriched object
   */
  private function _addNS($namespace, $obj) {
    switch (gettype($obj)) {
      case 'object':
        $res = (object)null;
        foreach ($obj as $i => $o) {
          if (is_array($o)) {
            $res->$i = $this->_addNS($namespace, $o);
          } else {
            $res->$i->_namespace = $namespace;
            $res->$i->_value = $this->_addNS($namespace, $o);
          }
        }
        return $res;
      case 'array':
        $res = array();
        foreach ($obj as $o) {
          $res[] = (object) array('_namespace'=>$namespace, '_value'=>$this->_addNS($namespace, $o));
        }
        return $res;
      default:
        return $obj;
    }
  }

  /**
   * Remove namespace from object structure
   * @param object $obj The object from which namespaces are removed
   * @return object The simplified object without namespaces
   */
  private function _removeNS($obj) {
    switch (gettype($obj)) {
      case 'object':
        if (property_exists($obj, '_value')) {
          return $this->_removeNS($obj->_value);
        } else {
          $res = (object)null;
          foreach ($obj as $i => $o) {
            if ($i != '_namespace') {
              $res->$i = $this->_removeNS($o);
            }
          }
          return $res;
        }
      case 'array':
        $res = array();
        foreach ($obj as $o) {
          $res[] = $this->_removeNS($o);
        }
        return $res;
      case null:
        return null;
      default:
        return $obj;
    }
  }

  function utRemoveRecordsByRecordId($recordId) {return $this->_removeRecordsByRecordId($recordId);}
  function utNormalize($idType, $idValue) {return $this->_normalize($idType, $idValue);}
  function utRemoveDuplicates($data) {return $this->_removeDuplicates($data);}

  /**
   * This method enriches the $param data with namespaces and calls the getIdsRequest method
   * @param object $param Simplified param block without namespaces
   * @return object Simplified return data block without namespaces
   */
  function utGetIdsRequest($param) {
    return $this->_removeNS($this->getIdsRequest($this->_addNS('http://oss.dbc.dk/ns/openxid', $param)));
  }
  
  /**
   * This method enriches the $param data with namespaces and calls the updateIdRequest method
   * @param object $param Simplified param block without namespaces
   * @return object Simplified return data block without namespaces
   */
  function utUpdateIdRequest($param) {
    return $this->_removeNS($this->updateIdRequest($this->_addNS('http://oss.dbc.dk/ns/openxid', $param)));
  } 
}

//------------------------------------------------------------------------------

/**
 * Necessary stub class
 */
class utAaa {
  public $authentication = true;
  function has_right($p1, $p2) {
    return $this->authentication;
  }
}

//------------------------------------------------------------------------------

/**
 * Test class for testing openXId Class
 */
class Test_OpenXid extends UnitTestCase {
  private $temp_files;
  private $ini_success = array("[setup]", "oxid_credentials = host=pgtest dbname=openxidtest user=openxidtest password=ogemudf");

  function __construct($label = false) {
    parent::__construct($label);
    $this->temp_files = array();
  }
  
  function __destruct() {
    foreach($this->temp_files as $file) {
      unlink($file);
    }
  }

  /**
   * Create a temp file, and put name in $this->temp_files (is deleted by destructor)
   * @param string $content Data to put into temp file
   * @return string File name
   */
  private function _temp_inifile($content) {
    $inifilename = tempnam('/tmp', 'openxid_unittest_');
    $this->temp_files[] = $inifilename;
    file_put_contents($inifilename, implode("\n", $content) . "\n");
    return $inifilename;
  }
  
  /**
   * Instantiate the openXId class with a successful ini file
   * @return object openXId object
   */
  private function _instantiate_oxid() {
    $inifilename = $this->_temp_inifile($this->ini_success);
    return new utOpenXId($inifilename);
  }

  //============================================================================

  /**
   * Test the normalize method in the openXId class
   */
  function test_normalize() {
    $test_data = array(
      //     idType             idValue         expected
      // Key      0                   1                2
      array('dummy',                  0 ,              0 ),
      array('local',                  0 ,              0 ),
      array('local',                123 ,            123 ),
      array('local',               '123',           '123'),
      array('faust',                  0 ,          false ),
      array('faust',                123 ,          false ),
      array('faust',               '123',          false ),
      array('faust',           '1234567',     '01234567' ), // Wrong checksum
      array('faust',           '1234560',     '01234560' ), // Correct checksum
      array('faust',         '12 345-60',     '01234560' ), // Correct checksum, with whitespace and dash
      array('faust',          '12345678',     '12345678' ), // Correct checksum
      array('faust',        '1-23456 74',     '12345674' ), // Correct checksum, with whitespace and dash
      array( 'issn',                  0 ,            '0' ),
      array( 'issn',                123 ,          '123' ),
      array( 'issn',               '123',          '123' ),
      array( 'issn',           '1234567',      '1234567' ), // Incorrect count of digits
      array( 'issn',          '12345678',     '12345678' ), // Wrong checksum
      array( 'issn',          '12345679',      '12345679'), // Correct checksum
      array(  'ean',                  0 ,            '0' ),
      array(  'ean',                123 ,          '123' ),
      array(  'ean',               '123',          '123' ),
      array(  'ean',           '1234567',      '1234567' ), // Incorrect count of digits
      array(  'ean',        '1234567890',   '1234567890' ), // Wrong checksum
      array(  'ean',        '1933988274',   '1933988274' ), // Correct checksum ISBN10
      array(  'ean',     '1-933988-27-4',   '1933988274' ), // Correct checksum ISBN10 with dashes
      array(  'ean',     '9781933988276','9781933988276' ), // Correct checksum ISBN13
      array(  'ean', '978-1-933988-27-6','9781933988276' ), // Correct checksum ISBN13 with dashes
    );
    
    $oxid = $this->_instantiate_oxid();
    foreach ($test_data as $d) {
      $actual_result = $oxid->utNormalize($d[0], $d[1]);
      $expected_result = $d[2];
      $this->assertIdentical($expected_result, $actual_result);
    }
    unset($oxid);
  }
  
  //============================================================================
  
  /**
   * Test the removeDuplicates method in the openXId class
   */
  function test_removeDuplicates() {
    $test_data = array(
      array(
        'in' => null,
        'out' => null,
      ),
      array(
        'in' => array(
          array('idtype'=>'ean', 'idvalue'=>'1234'),
          array('idtype'=>'ean', 'idvalue'=>'1234'),
        ),
        'out' => array(
          array('idtype'=>'ean', 'idvalue'=>'1234'),
        ),
      ),
      array(
        'in' => array(
          array('idtype'=>'ean', 'idvalue'=>'1234'),
          array('idtype'=>'local', 'idvalue'=>'1234'),
          array('idtype'=>'ean', 'idvalue'=>'1234'),
        ),
        'out' => array(
          array('idtype'=>'ean', 'idvalue'=>'1234'),
          array('idtype'=>'local', 'idvalue'=>'1234'),
        ),
      ),
    );
    
    $oxid = $this->_instantiate_oxid();

    foreach ($test_data as $data) {
      $actual_result = $oxid->utRemoveDuplicates($data['in']);
      $this->assertEqual($actual_result, $data['out']);
    }
    
    unset($oxid);
  }
  
  //============================================================================

  /**
   * Test the public updateIdRequest method in the openXId class
   */

}

?>
