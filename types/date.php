<?php
/**
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Brend Wanders <b.wanders@utwente.nl>
 */
// must be run within Dokuwiki
if(!defined('DOKU_INC')) die('Meh.');

/**
 * The date type.
 */
class plugin_strata_type_date extends plugin_strata_type {
    static $from = array('Y', 'm', 'd', 'H', 'i', 's');
    static $to   = array('%Y','%m','%d','%H','%M','%S');

    function render($mode, &$R, &$triples, $value, $hint) {
        if($mode == 'xhtml') {
            if(is_numeric($value)) {
                // use the hint if available
                $format = $hint ? $hint : 'Y-m-d';

               $result = strftime(str_replace(plugin_strata_type_date::$from, plugin_strata_type_date::$to, $format), (int)$value);

                // render
                $R->doc .= $R->_xmlEntities($result);
            } else {
                $R->doc .= $R->_xmlEntities($value);
            }
            return true;
        }

        return false;
    }

    function normalize($value, $hint) {
        // use hint if available
        // (prefix with '!' te reset all fields to the unix epoch)
        $format = ($hint ? $hint : 'Y-m-d');

        $format = str_replace(plugin_strata_type_date::$from, plugin_strata_type_date::$to, $format);

        // try and parse the value
        $date = strptime($value, $format);

        // handle failure in a non-intrusive way
        if($date === false) {
            return $value;
        } else {
            $stamp = mktime($date['tm_hour'], $date['tm_min'], $date['tm_sec'],
                            $date['tm_mon']+1, $date['tm_mday'], $date['tm_year']+1900);
            return $stamp;
        }
    }
        
    function getInfo() {
        return array(
            'desc'=>'Stores and displays dates in the YYYY-MM-DD format. The optional hint can give a different format to use.',
            'tags'=>array('numeric'),
            'hint'=>'different date format'
        );
    }
}
