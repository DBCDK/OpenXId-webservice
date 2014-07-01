<?php
/**
 *
 * This file is part of openLibrary.
 * Copyright © 2009, Dansk Bibliotekscenter a/s,
 * Tempovej 7-11, DK-2750 Ballerup, Denmark. CVR: 15149043
 *
 * openLibrary is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * openLibrary is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with openLibrary.  If not, see <http://www.gnu.org/licenses/>.
*/


require_once("OLS_class_lib/webServiceServer_class.php");
require_once("OLS_class_lib/material_id_class.php");
require_once("OLS_class_lib/ini_extend_class.php");

class openXId extends webServiceServer {

  protected $curl;
  protected $solr_server = "";
  protected $result_fields = "";
  protected $searchcode = array();
      

  function __construct($inifile, $silent=false) {
    parent::__construct($inifile);
    verbose::log(DEBUG, "ini " . $inifile );
    $this->solr_server = $this->config->get_value( "solr_server", "solr" );
    $this->searchcode = $this->config->get_value( "searchcode", "solr" );
    $this->result_fields = $this->config->get_value( "result_set", "solr" );
    $this->curl = new curl();
    verbose::log(TRACE, "openxid:: openxid initialized");

  }

  function __destruct() {
    parent::__destruct();
  }

 /** \brief _normalize
  *
  */
  protected function _normalize($idType, $idValue) {
    switch ($idType) {
      case 'ean':
        // Normalize an EAN number
        $ean = materialId::normalizeEAN($idValue);
        if (strlen($ean) == 13) return $ean;  // Yes it was...
        // Normalize an ISBN number
        $isbn = materialId::normalizeISBN($idValue);
        return $isbn;
      case 'issn':
        return materialId::normalizeISSN($idValue);
      case 'faust':
        return materialId::normalizeFaust($idValue);
      case 'local':
        return $idValue;
      default:
        return 0;
    }
  }


 /** \brief _removeDuplicates
  *
  */
  protected function _removeDuplicates($data) {
    if (!is_array($data)) return null;
    foreach($data as $item) {
      $acc[$item['idtype']][$item['idvalue']]++;  // Make new array with data as keys, hereby duplicates are overwritten
    }
    foreach ($acc as $type => $valueArray) {
      foreach($valueArray as $value => $count) {
        $res[] = array('idtype' => $type, 'idvalue' => $value);  // Re-establish array
      }
    }
    return $res;
  }

 /** \brief do_search
  *
  */
  protected function do_search($search_string, $result_fields ) {
    $url_append = "/select?q=" . $search_string . "&rows=999&fl=" . $result_fields . "&wt=phps&defType=edismax&stopwords=true&lowercaseOperators=true" ;
    verbose::log(DEBUG, 'URL ' . $this->solr_server . $url_append );
    $get_result = $this->curl->get( $this->solr_server . $url_append );
    $result_array[] = @ unserialize( $get_result );
    verbose::log(DEBUG, 'RES <' . $get_result . ">" );
    return $result_array;
  }
// =============================================================================


 /** \brief getIdsRequest
  *
  */
  public function getIdsRequest($param) {
    verbose::log(DEBUG, "openxid:: getIdsRequest(...);");
    $xid_getIdsResponse = &$ret->getIdsResponse;
    $xid_getIdsResponse->_namespace = $this->xmlns['xid'];

    if (!$this->aaa->has_right("openxidget", 560)) {
      $xid_error = &$xid_getIdsResponse->_value->error;
      $xid_error->_value = "authentication error";
      $xid_error->_namespace = $this->xmlns['xid'];
      verbose::log(ERROR, "openxid:: getIdsRequest(...); - authentication error");
      return $ret;
    }

    verbose::log(ERROR, "WUT <" . print_r($param, true) . '>' );
    $paramId = is_array($param->id) ? $param->id : array($param->id);
    verbose::log(ERROR, "WAT <" . print_r($paramId, true) . '>' );
    if (empty($paramId)) {
      $xid_error = &$xid_getIdsResponse->_value->error;
      $xid_error->_value = "invalid id";
      $xid_error->_namespace = $this->xmlns['xid'];
      verbose::log(ERROR, "openxid:: getIdsRequest(...); - invalid request <" . print_r($param, true) . '>' );
      return $ret;
    }

    $clusterData = array();
    foreach ($paramId as $id) {
      $item = array();
      $item['idType'] = strtolower(strip_tags($id->_value->idType->_value));
      $item['idValue'] = strip_tags($id->_value->idValue->_value);
      // check if idType is supported
      if (!array_key_exists($item['idType'], $this->searchcode)) {
        verbose::log(ERROR, "invalid idType in request <" . $item['idType'] . '>');
        $item['error'] = "invalid idType";
        $clusterData[] = $item;
        continue;  // Next iteration
      }
      // Normalize
      $idValue = self::_normalize($item['idType'], $item['idValue']);
      if ($idValue === 0) {
        verbose::log(ERROR, "invalid id in request <" . $item['idValue'] . '>');
        $item['error'] = "invalid id"; 
        $clusterData[] = $item;
        continue;  // Next iteration
      }

      verbose::log(TRACE, 'REQUEST type <' . $this->searchcode[ $item['idType'] ] . "> value <" . $idValue . ">" );
      $search_string = "" . $this->searchcode[ $item ['idType'] ] . "%3A" . $idValue ;
      verbose::log(TRACE, 'SEARCH ' . $search_string );
      $result_array = self::do_search($search_string, "unit.id" );
      verbose::log(DEBUG, 'UNITID_RESULT ' . print_r($result_array, true) );


      // Uniqing unit.id's
      $num_of_rows_found = $result_array[0]['response']['numFound'];
      if ( $num_of_rows_found > 999 ) {
        verbose::log(WARNING, 'More than 999 unit.id hits on ' . $search_string . ' (got : ' . $num_of_rows_found . ") rest is ignored");
      }
      $unit_ids = array();

      $docs = array();
      $docs = $result_array[0]['response']['docs'];
      foreach ( $docs as $tempodocs ) {
        if ( ! isset( $unit_ids[ $tempodocs['unit.id' ] ] ) ) {
          // search string is "unit.id:unit:<unit.id>" and ['unit.id'] contains "unit:<unit.id>"
          $unit_ids[ $tempodocs['unit.id' ] ] = substr_replace( $tempodocs['unit.id' ], ".id%3Aunit%5C%3A", 4, 1);
        }
      }

      // For each unit.id found in previous search we now have to find the wanted ids of 
      // various types.
      $newClusterData = array();
      foreach ( $unit_ids as $tempo ) {
        $result_array = self::do_search($tempo, $this->result_fields);
        verbose::log(DEBUG, 'TERMS_RESULT ' . print_r($result_array, true) );

        $docs = array();
        $docs = $result_array[0]['response']['docs'];
        foreach ( $docs as $tempodoc ) {
          $recarr = array();

          if ( isset( $tempodoc['dkcclterm.id'] ) ) {
            // 001 *a
            $recarr = $tempodoc['dkcclterm.id'];
            foreach ( $recarr as $temporecid ) {
              if ( strpos( $temporecid, '_') > 0 ) {
                continue;
              }
              if (is_array( $tempodoc['dkcclterm.ln'] ) ) {
                $tmpbib = $tempodoc['dkcclterm.ln'][0];
              } else {
                $tmpbib = $tempodoc['dkcclterm.ln'];
              }
              if ($tmpbib >= 870970 and $tmpbib <= 870979 ) {
                // There are a few records from these bases with invalid faust - this fixes that problem
                if ( materialId::normalizeFaust($temporecid) ) {
                  $xxx['idtype'] = 'faust';
                  $xxx['idvalue'] =  materialId::normalizeFaust($temporecid);
                } else {
                  $xxx['idtype'] = 'local';
                  $xxx['idvalue'] =  $temporecid;
                }
              } else {
                $xxx['idtype'] = 'local';
                $xxx['idvalue'] =  $temporecid;
              }
              $newClusterData[] = $xxx;
            }
          }
          // 002 *acd
          if ( isset( $tempodoc['dkcclterm.tf'] ) ) {
            $recarr = $tempodoc['dkcclterm.tf'];
            foreach ( $recarr as $temporecid ) {
              // content of *d isn't always trustworthy as a faust
              if ( materialId::normalizeFaust($temporecid) ) {
                $xxx['idtype'] = 'faust';
                $xxx['idvalue'] =  materialId::normalizeFaust($temporecid);
              } else {
                $xxx['idtype'] = 'local';
                $xxx['idvalue'] =  $temporecid;
              }
              $newClusterData[] = $xxx;
            }
          }
          // isbn
          if ( isset( $tempodoc['dkcclterm.ib'] ) ) {
            $recarr = $tempodoc['dkcclterm.ib'];
            foreach ( $recarr as $temporecid ) {
              $tmpstr = self::_normalize( 'ean', $temporecid );
              switch ( strlen( $tmpstr ) ) {
                case 10:
                  $xxx['idtype'] = 'isbn';
                  break;
                case 13:
                  $xxx['idtype'] = 'ean';
                  break;
                default:
                  verbose::log(WARNING, 'Neither isbn nor ean in ib section <' . print_r($temporecid, true) . '>' );
                  continue 2;
                  break;
              }
              $xxx['idvalue'] =  $tmpstr;
              $newClusterData[] = $xxx;
            }
          }
          // issn
          if ( isset( $tempodoc['dkcclterm.in'] ) ) {
            $recarr = $tempodoc['dkcclterm.in'];
            foreach ( $recarr as $temporecid ) {
              $tmpstr =  materialId::normalizeISSN($temporecid);
              if ( strlen( $tmpstr ) == 8 ) {
                $xxx['idtype'] = 'issn';
                $xxx['idvalue'] =  $tmpstr;
                $newClusterData[] = $xxx;
              } else {
                // This message can end up being pretty annoying because isbn also occurs here so potential isbn/ean are ignored.
                if ( ! (strlen( $tmpstr ) == 10 || strlen( $tmpstr ) == 13) ) {
                  verbose::log(WARNING, 'Not an issn value in is section <' . print_r($temporecid, true) . '>' );
                }
              }
            }
          }
        }
      }

      if (!is_array($newClusterData) or (count($newClusterData)==0)) {
        $item['error'] = "no results found for requested id"; 
        $clusterData[] = $item;
        continue;  // Next iteration
      }
      $item['ids'] = self::_removeDuplicates($newClusterData);
      $clusterData[] = $item;
    }

    // Format output xml
    $xid_getIdResult = &$xid_getIdsResponse->_value->getIdResult;
    foreach ($clusterData as $item) {
      unset($xid_get_item);
      $xid_get_item->_namespace = $this->xmlns['xid'];
      $xid_requestedId = &$xid_get_item->_value->requestedId;
      $xid_requestedId->_namespace = $this->xmlns['xid'];
      $xid_requestedIdType = &$xid_requestedId->_value->idType;
      $xid_requestedIdType->_namespace = $this->xmlns['xid'];
      $xid_requestedIdType->_value = $item['idType'];
      $xid_requestedIdValue = &$xid_requestedId->_value->idValue;
      $xid_requestedIdValue->_namespace  = $this->xmlns['xid'];
      $xid_requestedIdValue->_value = $item['idValue'];
      // If error
      if (!isset($item['error'])) {
        $xid_ids = &$xid_get_item->_value->ids;
        $xid_ids->_namespace = $this->xmlns['xid'];
        $xid_id = &$xid_ids->_value->id;
        foreach ($item['ids'] as $item_id) {
          unset($xid_get_id);
          $xid_get_id->_namespace = $this->xmlns['xid'];
          $xid_idType = &$xid_get_id->_value->idType;
          $xid_idType->_namespace  = $this->xmlns['xid'];
          $xid_idType->_value = $item_id['idtype'];
          $xid_idValue = &$xid_get_id->_value->idValue;
          $xid_idValue->_namespace  = $this->xmlns['xid'];
          $xid_idValue->_value = $item_id['idvalue'];
          $xid_id[] = $xid_get_id;
        }
      } else {
        $xid_ids_error = &$xid_get_item->_value->error;
        $xid_ids_error->_namespace = $this->xmlns['xid'];
        $xid_ids_error->_value = $item['error'];
        $xid_getIdResult[] = $xid_get_item;
        continue;
      }
      $xid_getIdResult[] = $xid_get_item;
    }
    return $ret;
  }


 /** \brief updateIdRequest
  *
  */
  public function updateIdRequest($param) {
    verbose::log(DEBUG, "openxid:: updateIdRequest(...);");
    $xid_updateIdResponse = &$ret->updateIdResponse;
    $xid_updateIdResponse->_namespace = $this->xmlns['xid'];
    $xid_error = &$xid_updateIdResponse->_value->error;
    $xid_error->_value = "Update is not possible when using Solr";
    $xid_error->_namespace = $this->xmlns['xid'];
    return $ret;
  }

}

//*
//* Local variables:
//* tab-width: 2
//* c-basic-offset: 2
//* End:
//* vim600: sw=2 ts=2 fdm=marker expandtab
//* vim<600: sw=2 ts=2 expandtab
//*/
?>
