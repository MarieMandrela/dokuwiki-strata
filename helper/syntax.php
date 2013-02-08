<?php
/**
 * DokuWiki Plugin strata (Helper Component)
 *
 * @license GPL 2 http://www.gnu.org/licenses/gpl-2.0.html
 * @author  Brend Wanders <b.wanders@utwente.nl>
 */

if (!defined('DOKU_INC')) die('meh.');

if (!defined('STRATA_PREDICATE')) define('STRATA_PREDICATE','[^_:\(\)\[\]\{\}\<\>\|\~\!\@\#\$\%\^\&\*\?\="]+');
if (!defined('STRATA_VARIABLE')) define('STRATA_VARIABLE','[^ _:\(\)\[\]\{\}\<\>\|\~\!\@\#\$\%\^\&\*\?\="]+');

require_once(DOKU_PLUGIN.'strata/strata_exception.php');

/**
 * A single capture. Used as a return value for the RegexHelper's
 * capture methods.
 */
class helper_plugin_strata_syntax_RegexHelperCapture {
    function __construct($values) {
        $this->values = $values;
    }

    function __get($name) {
        if(array_key_exists($name, $this->values)) {
            return $this->values[$name];
        } else {
            return null;
        }
    }
}

/**
 * Helper to construct and handle syntax fragments.
 */
class helper_plugin_strata_syntax_RegexHelper {
    /**
     * Regular expression fragment table. This is used for interpolation of
     * syntax patterns, and should be without captures. Do not assume any
     * specific delimiter.
     */
    var $regexFragments = array(
        'variable'  => '(?:\?[^\s_:\(\)\[\]\{\}\<\>\|\~\!\@\#\$\%\^\&\*\?\="]+)',
        'predicate' => '(?:[^_:\(\)\[\]\{\}\<\>\|\~\!\@\#\$\%\^\&\*\?\="]+)',
        'reflit'    => '(?:\[\[[^]]*\]\])',
        'type'      => '(?:_[a-z0-9]+(?:\([^)]+\))?)',
        'aggregate' => '(?:@[a-z0-9]*(?:\([^)]+\))?)'
    );

    /**
     * Patterns used to extract information from captured fragments. These patterns
     * are used with '/' as delimiter, and should contain at least one capture group.
     */
    var $regexCaptures = array(
        'variable'  => array('\?(.*)', array('name')),
        'type'      => array('_([a-z0-9]+)(?:\(([^)]+)\))?', array('type', 'hint')),
        'reflit'    => array('\[\[(.*)\]\]',array('reference'))
    );

    /**
     * Grabs the syntax fragment.
     */
    function __get($name) {
        if(array_key_exists($name, $this->regexFragments)) {
            return $this->regexFragments[$name];
        } else {
            $trace = debug_backtrace();
            trigger_error("Undefined syntax fragment '$name' on {$trace[0]['file']}:{$trace[0]['line']}", E_USER_NOTICE);
        }
    }

    /**
     * Extracts information from a fragment, based on the type.
     */
    function __call($name, $arguments) {
        if(array_key_exists($name, $this->regexCaptures)) {
            list($pattern, $names) = $this->regexCaptures[$name];
            $result = preg_match("/^{$pattern}$/", $arguments[0], $match);
            if($result == 1) {
                array_shift($match);
                $shortest = min(count($names), count($match));
                return new helper_plugin_strata_syntax_RegexHelperCapture(array_combine(array_slice($names,0,$shortest), array_slice($match, 0, $shortest)));
            } else {
                return null;
            }
        } else {
            $trace = debug_backtrace();
            trigger_error("Undefined syntax capture '$name' on {$trace[0]['file']}:{$trace[0]['line']}", E_USER_NOTICE);
        }
    }
}


/**
 * Helper plugin for common syntax parsing.
 */
class helper_plugin_strata_syntax extends DokuWiki_Plugin {
    function __construct() {
        $this->util =& plugin_load('helper', 'strata_util');
        $this->regexHelper = new helper_plugin_strata_syntax_RegexHelper();
    }


    /**
     * Returns an object describing the pattern fragments.
     */
    function getPatterns() {
        return $this->regexHelper;
    }

    /**
     * Determines whether a line can be ignored.
     */
    function ignorableLine($line) {
        $line = utf8_trim($line);
        return $line == '' || utf8_substr($line,0,2) == '--';
    }

    /**
     * Updates the given typemap with new information.
     *
     * @param typemap array a typemap
     * @param var string the name of the variable
     * @param type string the type of the variable
     * @param hint string the type hint of the variable
     */
    function updateTypemap(&$typemap, $var, $type, $hint=null) {
        if(empty($typemap[$var]) && $type) {
            $typemap[$var] = array('type'=>$type,'hint'=>$hint);
            return true;
        }

        return false;
    }

    /**
     * Constructs a literal with the given text.
     */
    function literal($val) {
        return array('type'=>'literal', 'text'=>$val);
    }

    /**
     * Constructs a variable with the given name.
     */
    function variable($var) {
        if($var[0] == '?') $var = substr($var,1);
        return array('type'=>'variable', 'text'=>$var);
    }

    function _fail($message, $regions=array()) {
        msg($message,-1);

        if($this->isGroup($regions) || $this->isText($regions)) {
            $regions = array($regions);
        }

        $lines = array();
        foreach($regions as $r) $lines[] = array('start'=>$r['start'], 'end'=>$r['end']);
        throw new strata_exception($message, $lines);
    }

    /**
     * Constructs a query from the give tree.
     *
     * @param root array the tree to transform
     * @param typemap array the type information collected so far
     * @param projection array the variables to project
     * @return a query structure
     */
    function constructQuery(&$root, &$typemap, $projection) {
        $p = $this->getPatterns();

        $result = array(
            'type'=>'select',
            'group'=>array(),
            'projection'=>$projection,
            'ordering'=>array(),
            'grouping'=>false,
            'considering'=>array()
        );

        // extract sort groups
        $ordering = $this->extractGroups($root, 'sort');

        // extract grouping groups
        $grouping = $this->extractGroups($root, 'group');

        // extract additional projection groups
        $considering = $this->extractGroups($root, 'consider');

        // transform actual group
        $where = $this->extractGroups($root, 'where');
        $tree = null;
        if(count($where)==0) {
            $tree =& $root;
        } elseif(count($where)==1) {
            $tree =& $where[0];
            if(count($root['cs'])) {
                $this->_fail($this->getLang('error_query_outofwhere'), $root['cs']);
            }
        } else {
            $this->_fail($this->getLang('error_query_singlewhere'), $where);
        }

        list($group, $scope) = $this->transformGroup($tree, $typemap);
        $result['group'] = $group;
        if(!$group) return false;

        // handle sort groups
        if(count($ordering)) {
            if(count($ordering) > 1) {
                $this->_fail($this->getLang('error_query_multisort'), $ordering);
            }
   
            // handle each line in the group 
            foreach($ordering[0]['cs'] as $line) {
                if($this->isGroup($line)) {
                    $this->_fail($this->getLang('error_query_sortblock'), $line);
                }

                if(preg_match("/^({$p->variable})\s*(?:\((asc|desc)(?:ending)?\))?$/S",utf8_trim($line['text']),$match)) {
                    $var = $p->variable($match[1]);
                    if(!in_array($var->name, $scope)) {
                        $this->_fail(sprintf($this->getLang('error_query_sortvar'),utf8_tohtml(hsc($var->name))), $line);
                    }

                    $result['ordering'][] = array('variable'=>$var->name, 'direction'=>($match[2]?:'asc'));
                } else {
                    $this->_fail(sprintf($this->getLang('error_query_sortline'), utf8_tohtml(hsc($line['text']))), $line);
                }
            }
        }

        //handle grouping
        if(count($grouping)) {
            if(count($grouping) > 1) {
                $this->_fail($this->getLang('error_query_multigrouping'), $grouping);
            }

            // we have a group, so we want grouping
            $result['grouping'] = array();

            foreach($grouping[0]['cs'] as $line) {
                if($this->isGroup($line)) {
                    $this->_fail($this->getLang('error_query_groupblock'), $line);
                }

                if(preg_match("/({$p->variable})$/",utf8_trim($line['text']),$match)) {
                    $var = $p->variable($match[1]);
                    if(!in_array($var->name, $scope)) {
                        $this->_fail(sprintf($this->getLang('error_query_groupvar'),utf8_tohtml(hsc($var->name))), $line);
                    }

                    $result['grouping'][] = $var->name;
                } else {
                    $this->_fail(sprintf($this->getLang('error_query_groupline'), utf8_tohtml(hsc($line['text']))), $line);
                }
            }
        }

        //handle considering
        if(count($considering)) {
            if(count($considering) > 1) {
                $this->_fail($this->getLang('error_query_multiconsidering'), $considering);
            }

            foreach($considering[0]['cs'] as $line) {
                if($this->isGroup($line)) {
                    $this->_fail($this->getLang('error_query_considerblock'), $line);
                }

                if(preg_match("/^({$p->variable})$/",utf8_trim($line['text']),$match)) {
                    $var = $p->variable($match[1]);
                    if(!in_array($var->name, $scope)) {
                        $this->_fail(sprintf($this->getLang('error_query_considervar'),utf8_tohtml(hsc($var->name))), $line);
                    }

                    $result['considering'][] = $var->name;
                } else {
                    $this->_fail(sprintf($this->getLang('error_query_considerline'), utf8_tohtml(hsc($line['text']))), $line);
                }
            }
        }

        foreach($projection as $var) {
            if(!in_array($var, $scope)) {
                $this->_fail(sprintf($this->getLang('error_query_selectvar'), utf8_tohtml(hsc($var))));
            }
        }

        // return final query structure
        return array($result, $scope);
    }

    /**
     * Transforms a full query group.
     * 
     * @param root array the tree to transform
     * @param typemap array the type information
     * @return the transformed group and a list of in-scope variables
     */
    function transformGroup(&$root, &$typemap) {
        // extract patterns and split them in triples and filters
        $patterns = $this->extractText($root);

        // extract union groups
        $unions = $this->extractGroups($root, 'union');

        // extract minus groups
        $minuses = $this->extractGroups($root,'minus');

        // extract optional groups
        $optionals = $this->extractGroups($root,'optional');

        // check for leftovers
        if(count($root['cs'])) {
            $this->_fail(sprintf($this->getLang('error_query_group'),( isset($root['cs'][0]['tag']) ? sprintf($this->getLang('named_group'), utf8_tohtml(hsc($root['cs'][0]['tag']))) : $this->getLang('unnamed_group'))), $root['cs']);
        }

        // split patterns into triples and filters
        list($patterns, $filters, $scope) = $this->transformPatterns($patterns, $typemap);

        // convert each union into a pattern
        foreach($unions as $union) {
            list($u, $s) = $this->transformUnion($union, $typemap);
            $scope = array_merge($scope, $s);
            $patterns[] = $u;
        }

        if(count($patterns) == 0) {
            $this->_fail(sprintf($this->getLang('error_query_grouppattern')), $root);
        }

        // chain all patterns with ANDs 
        $result = array_shift($patterns);
        foreach($patterns as $pattern) {
            $result = array(
                'type'=>'and',
                'lhs'=>$result,
                'rhs'=>$pattern
            );
        }

        // apply all optionals
        if(count($optionals)) {
            foreach($optionals as $optional) {
                // convert eacfh optional
                list($optional, $s) = $this->transformGroup($optional, $typemap);
                $scope = array_merge($scope, $s);
                $result = array(
                    'type'=>'optional',
                    'lhs'=>$result,
                    'rhs'=>$optional
                );
            }
        }


        // add all filters; these are a bit weird, as only a single FILTER is really supported
        // (we have defined multiple filters as being a conjunction)
        if(count($filters)) {
            foreach($filters as $f) {
                if($f['lhs']['type'] == 'variable' && !in_array($f['lhs']['text'], $scope)) {
                    $this->_fail(sprintf($this->getLang('error_query_filterscope'),utf8_tohtml(hsc($f['lhs']['text']))), $root);
                }
                if($f['rhs']['type'] == 'variable' && !in_array($f['rhs']['text'], $scope)) {
                    $this->_fail(sprintf($this->getLang('error_query_filterscope'),utf8_tohtml(hsc($f['rhs']['text']))), $root);
                }
            }

            $result = array(
                'type'=>'filter',
                'lhs'=>$result,
                'rhs'=>$filters
            );
        }

        // apply all minuses
        if(count($minuses)) {
            foreach($minuses as $minus) {
                // convert each minus, and discard their scope
                list($minus, $s) = $this->transformGroup($minus, $typemap);
                $result = array(
                    'type'=>'minus',
                    'lhs'=>$result,
                    'rhs'=>$minus
                );
            }
        }

        return array($result, $scope);
    }

    /**
     * Transforms a union group with multiple subgroups
     * 
     * @param root array the union group to transform
     * @param typemap array the type information
     * @return the transformed group and a list of in-scope variables
     */
    function transformUnion(&$root, &$typemap) {
        // fetch all child patterns
        $subs = $this->extractGroups($root,null);

        // do sanity checks
        if(count($root['cs'])) {
            $this->_fail($this->getLang('error_query_unionblocks'), $root['cs']);
        }

        if(count($subs) < 2) {
            $this->_fail($this->getLang('error_query_unionreq'), $root);
        }

        // transform the first group
        list($result,$scope) = $this->transformGroup(array_shift($subs), $typemap);

        // transform each subsequent group
        foreach($subs as $sub) {
            list($rhs, $s) = $this->transformGroup($sub, $typemap);
            $scope = array_merge($scope, $s);
            $result = array(
                'type'=>'union',
                'lhs'=>$result,
                'rhs'=>$rhs
            );
        }

        return array($result, $scope);
    }

    /**
     * Transforms a list of patterns into a list of triples and a
     * list of filters.
     *
     * @param lines array a list of lines to transform
     * @param typemap array the type information
     * @return a list of triples, a list of filters and a list of in-scope variables
     */
    function transformPatterns(&$lines, &$typemap) {
        // we need this to resolve things
        global $ID;

        // we need patterns
        $p = $this->getPatterns();

        // result holders
        $scope = array();
        $triples = array();
        $filters = array();

        foreach($lines as $lineNode) {
            $line = trim($lineNode['text']);

            //if(preg_match('/^((?:\?'.STRATA_VARIABLE.')|(?:\[\[[^]]*\]\]))\s+(?:((?:'.STRATA_PREDICATE.')|(?:\?'.STRATA_VARIABLE.'))(?:_([a-z0-9]+)(?:\(([^)]+)\))?)?)\s*:\s*(.+?)\s*$/S',$line,$match)) {
            if(preg_match("/^({$p->variable}|{$p->reflit})\s+({$p->variable}|{$p->predicate})({$p->type})?\s*:\s*(.+?)\s*$/S",$line,$match)) {
                // triple pattern
                list(, $subject, $predicate, $type, $object) = $match;

                $subject = utf8_trim($subject);
                if($subject[0] == '?') {
                    $subject = $this->variable($subject);
                    $scope[] = $subject['text'];
                    $this->updateTypemap($typemap, $subject['text'], 'ref');
                } else {
                    global $ID;
                    $subject = $p->reflit($subject)->reference;
                    $subject = $this->util->loadType('ref')->normalize($subject,null);
                    $subject = $this->literal($subject);
                }

                $predicate = utf8_trim($predicate);
                if($predicate[0] == '?') {
                    $predicate = $this->variable($predicate);
                    $scope[] = $predicate['text'];
                    $this->updateTypemap($typemap, $predicate['text'], 'text');
                } else {
                    $predicate = $this->literal($this->util->normalizePredicate($predicate));
                }

                $object = utf8_trim($object);
                if($object[0] == '?') {
                    // match a proper type variable
                    if(preg_match("/^({$p->variable})({$p->type})?$/",$object,$captures)!=1) {
                        $this->_fail($this->getLang('error_pattern_garbage'),$lineNode);
                    }
                    list(, $var, $vtype) = $captures;

                    // create the object node
                    $object = $this->variable($var);
                    $scope[] = $object['text'];

                    // try direct type first, implied type second
                    $vtype = $p->type($vtype);
                    $type = $p->type($type);
                    $this->updateTypemap($typemap, $object['text'], $vtype->type, $vtype->hint);
                    $this->updateTypemap($typemap, $object['text'], $type->type, $type->hint);
                } else {
                    // check for empty string token
                    if($object == '[[]]') {
                        $object='';
                    }
                    if(!$type) {
                        list($type, $hint) = $this->util->getDefaultType();
                    }
                    $type = $this->util->loadType($type);
                    $object = $this->literal($type->normalize($object,$hint));
                }

                $triples[] = array('type'=>'triple','subject'=>$subject, 'predicate'=>$predicate, 'object'=>$object);

            } elseif(preg_match('/^(?:\?('.STRATA_VARIABLE.')(?:_([a-z0-9]+)(?:\(([^)]+)\))?)?)\s*(!=|>=|<=|>|<|=|!~|!\^~|!\$~|\^~|\$~|~)\s*(?:\?('.STRATA_VARIABLE.')(?:_([a-z0-9]+)(?:\(([^)]+)\))?)?)\s*$/S',$line, $match)) {
                // var op var
                list(,$lhs, $ltype, $lhint, $operator, $rhs, $rtype, $rhint) = $match;

                $lhs = $this->variable($lhs);
                $rhs = $this->variable($rhs);

                // do type information propagation

                if($ltype) {
                    // left has a defined type, so update the map
                    $this->updateTypemap($typemap, $lhs['text'], $ltype, $lhint);

                    // and propagate to right if possible
                    if(!$rtype) {
                        $this->updateTypemap($typemap, $rhs['text'], $ltype, $lhint);
                    }
                }
                if($rtype) {
                    // right has a defined type, so update the map
                    $this->updateTypemap($typemap, $rhs['text'], $rtype, $rhint);

                    // and propagate to left if possible
                    if(!$ltype) {
                        $this->updateTypemap($typemap, $lhs['text'], $rtype, $rhint);
                    }
                }

                $filters[] = array('type'=>'filter', 'lhs'=>$lhs, 'operator'=>$operator, 'rhs'=>$rhs);

            } elseif(preg_match('/^(?:\?('.STRATA_VARIABLE.')(?:_([a-z0-9]+)(?:\(([^)]+)\))?)?)\s*(!=|>=|<=|>|<|=|!~|!\^~|!\$~|\^~|\$~|~)\s*(.+?)\s*$/S',$line, $match)) {
                // var op lit

                // filter pattern
                list(, $lhs,$type,$hint,$operator,$rhs) = $match;

                $lhs = $this->variable($lhs);

                // update typemap if a type was defined
                if($type) {
                    $this->updateTypemap($typemap, $lhs['text'],$type,$hint);
                }

                // use the already declared type if no type was defined
                if(!$type) {
                    if(!empty($typemap[$lhs['text']])) {
                        extract($typemap[$lhs['text']]);
                    } else {
                        list($type, $hint) = $this->util->getDefaultType();
                    }
                }

                // check for empty string token
                if($rhs == '[[]]') {
                    $rhs = '';
                }

                $type = $this->util->loadType($type);
                $rhs = $this->literal($type->normalize($rhs,$hint));

                $filters[] = array('type'=>'filter','lhs'=>$lhs, 'operator'=>$operator, 'rhs'=>$rhs);
            } elseif(preg_match('/^(.+?)\s*(!=|>=|<=|>|<|=|!~|!\^~|!\$~|\^~|\$~|~)\s*(?:\?('.STRATA_VARIABLE.')(?:_([a-z0-9]+)(?:\(([^)]+)\))?)?)\s*$/S',$line, $match)) {
                // lit op var

                // filter pattern
                list(, $lhs,$operator,$rhs,$type,$hint) = $match;

                $rhs = $this->variable($rhs);

                // update typemap if a type was defined
                if($type) {
                    $this->updateTypemap($typemap, $rhs['text'],$type,$hint);
                }

                // use the already declared type if no type was defined
                if(!$type) {
                    if(!empty($typemap[$rhs['text']])) {
                        extract($typemap[$rhs['text']]);
                    } else {
                        list($type, $hint) = $this->util->getDefaultType();
                    }
                }

                // check for empty string token
                if($lhs == '[[]]') {
                    $lhs = '';
                }

                $type = $this->util->loadType($type);
                $lhs = $this->literal($type->normalize($lhs,$hint));

                $filters[] = array('type'=>'filter','lhs'=>$lhs, 'operator'=>$operator, 'rhs'=>$rhs);
            } else {
                // unknown lines are fail
                $this->_fail(sprintf($this->getLang('error_query_pattern'),utf8_tohtml(hsc($line))), $lineNode);
            }
        }

        return array($triples, $filters, $scope);
    }

    function getFields(&$tree, &$typemap) {
        $fields = array();

        // extract the projection information in 'long syntax' if available
        $fieldsGroups = $this->extractGroups($tree, 'fields');

        // parse 'long syntax' if we don't have projection information yet
        if(count($fieldsGroups)) {
            if(count($fieldsGroups) > 1) {
                $this->_fail($this->getLang('error_query_fieldsgroups'), $fieldsGroups);
            }

            $fieldsLines = $this->extractText($fieldsGroups[0]);
            if(count($fieldsGroups[0]['cs'])) {
                $this->_fail(sprintf($this->getLang('error_query_fieldsblock'),( isset($fieldsGroups[0]['cs'][0]['tag']) ? sprintf($this->getLang('named_group'),hsc($fieldsGroups[0]['cs'][0]['tag'])) : $this->getLang('unnamed_group'))), $fieldsGroups[0]['cs']);
            }
            $fields = $this->parseFieldsLong($fieldsLines, $typemap);
            if(!$fields) return array();
        }
    
        return $fields;
    }

    /**
     * Parses a projection group in 'long syntax'.
     */
    function parseFieldsLong($lines, &$typemap) {
        $result = array();

        foreach($lines as $lineNode) {
            $line = trim($lineNode['text']);
            if(preg_match('/^(?:([^_]*)(?:_([a-z0-9]*)(?:\(([^)]+)\))?)?(:))?\s*\?('.STRATA_VARIABLE.')(?:@([a-z0-9]*)(?:\(([^)]+)\))?)?(?:_([a-z0-9]*)(?:\(([^)]+)\))?)?$/S',$line, $match)) {
                list($_, $caption, $type, $hint, $nocaphint, $variable, $agg, $agghint, $rtype, $rhint) = $match;
                if(!$nocaphint || (!$nocaphint && !$caption && !$type)) $caption = ucfirst($variable);

                // use right-hand type if no left-hand type available
                if(!$type) {
                    $type = $rtype;
                    $hint = $rhint;
                }

                if($type && $rtype && ($type!=$rtype || $hint!=$rhint)) {
                    $this->_fail(sprintf($this->getLang('error_query_fieldsdoubletyped'), utf8_tohtml(hsc($variable))), $lineNode);
                }
                $this->updateTypemap($typemap, $variable, $type, $hint);
                $result[] = array('variable'=>$variable,'caption'=>$caption, 'aggregate'=>($agg?:null), 'aggregateHint'=>($agg?$agghint:null), 'type'=>$type, 'hint'=>$hint);
            } else {
                $this->_fail(sprintf($this->getLang('error_query_fieldsline'),utf8_tohtml(hsc($line))), $lineNode);
            }
        }

        return $result;
    }

    /**
     * Parses a projection group in 'short syntax'.
     */
    function parseFieldsShort($line, &$typemap) {
        $result = array();

        if(preg_match_all('/(?:\s*\?('.STRATA_VARIABLE.')(?:@([a-z0-9]*)(?:\(([^\)]*)\))?)?(?:_([a-z0-9]*)(?:\(([^\)]*)\))?)?\s*(?:(")([^"]*)")?)/',$line,$match, PREG_SET_ORDER)) {
            foreach($match as $m) {
                list(, $variable, $agg, $agghint, $type, $hint, $caption_indicator, $caption) = $m;
                if(!$caption_indicator) $caption = ucfirst($variable);
                $this->updateTypemap($typemap, $variable, $type, $hint);
                $result[] = array('variable'=>$variable,'caption'=>$caption, 'aggregate'=>($agg?:null), 'aggregateHint'=>($agg?$agghint:null), 'type'=>$type, 'hint'=>$hint);
            }
        }

        return $result;
    }

    /**
     * Returns the regex pattern used by the 'short syntax' for projection. This methods can
     * be used to get a dokuwiki-lexer-safe regex to embed into your own syntax pattern.
     *
     * @param captions boolean Whether the pattern should include caption matching (defaults to true)
     */
    function fieldsShortPattern($captions = true) {
        return '(?:\s*\?'.STRATA_VARIABLE.'(?:@[a-z0-9]*(?:\([^\)]*\))?)?(?:_[a-z0-9]*(?:\([^\)]*\))?)?'.($captions?'\s*(?:"[^"]*")?':'').')';
    }

    /**
     * Constructs a tagged tree from the given list of lines.
     *
     * @return a tagged tree
     */
    function constructTree($lines, $what) {
        $root = array(
            'tag'=>'',
            'cs'=>array(),
            'start'=>1,
            'end'=>1
        );

        $stack = array();
        $stack[] =& $root;
        $top = count($stack)-1;
        $lineCount = 0;

        foreach($lines as $line) {
            $lineCount++;
            if($this->ignorableLine($line)) continue;

            if(preg_match('/^([^\{]*) *{$/',utf8_trim($line),$match)) {
                list(, $tag) = $match;
                $tag = utf8_trim($tag);

                $stack[$top]['cs'][] = array(
                    'tag'=>$tag?:null,
                    'cs'=>array(),
                    'start'=>$lineCount,
                    'end'=>0
                );
                $stack[] =& $stack[$top]['cs'][count($stack[$top]['cs'])-1];
                $top = count($stack)-1;

            } elseif(preg_match('/^}$/',utf8_trim($line))) {
                $stack[$top]['end'] = $lineCount;
                array_pop($stack);
                $top = count($stack)-1;

            } else {
                $stack[$top]['cs'][] = array(
                    'text'=>$line,
                    'start'=>$lineCount,
                    'end'=>$lineCount
                );
            }
        }

        if(count($stack) != 1 || $stack[0] != $root) {
            msg(sprintf($this->getLang('error_syntax_braces'),$what),-1);
        }

        $root['end'] = $lineCount;

        return $root;
    }

    /**
     * Renders a debug display of the syntax.
     *
     * @param lines array the lines that form the syntax
     * @param region array the region to highlight
     * @return a string with markup
     */
    function debugTree($lines, $regions) {
        $result = '';
        $lineCount = 0;
        $count = 0;

        foreach($lines as $line) {
            $lineCount++;

            foreach($regions as $region) {
                if($lineCount == $region['start']) {
                    if($count == 0) $result .= '<div class="strata-debug-highlight">';
                    $count++;
                }

                if($lineCount == $region['end']+1) {
                    $count--;

                    if($count==0) $result .= '</div>';
                }
            }

            if($line != '') {
                $result .= '<div class="strata-debug-line">'.hsc($line).'</div>'."\n";
            } else {
                $result .= '<div class="strata-debug-line"><br/></div>'."\n";
            }
        }

        if($count > 0) {
            $result .= '</div>';
        }

        return '<div class="strata-debug">'.$result.'</div>';
    }

    /**
     * Extract all occurences of tagged groups from the given tree.
     * This method does not remove the tagged groups from subtrees of
     * the given root.
     *
     * @param root array the tree to operate on
     * @param tag string the tag to remove
     * @return an array of groups
     */
    function extractGroups(&$root, $tag) {
        $result = array();
        foreach($root['cs'] as $i=>&$tree) {
            if(!$this->isGroup($tree)) continue;
            if($tree['tag'] == $tag || (($tag=='' || $tag==null) && $tree['tag'] == null) ) {
                $result[] =& $tree;
                array_splice($root['cs'],$i,1);
            }
        }
        return $result;
    }

    /**
     * Extracts all text elements from the given tree.
     * This method does not remove the text elements from subtrees
     * of the root.
     *
     * @param root array the tree to operate on
     * @return array an array of text elements
     */
    function extractText(&$root) {
        $result = array();
        foreach($root['cs'] as $i=>&$tree) {
            if(!$this->isText($tree)) continue;
            $result[] =& $tree;
            array_splice($root['cs'],$i,1);
        }
        return $result;
    }

    /**
     * Returns whether the given node is a line.
     */
    function isText(&$node) {
        return array_key_exists('text', $node);
    }

    /**
     * Returns whether the given node is a group.
     */
    function isGroup(&$node) {
        return array_key_exists('tag', $node);
    }
}