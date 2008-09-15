<?php
/**
 * Amazon Plugin: pulls Bookinfo from amazon.com
 *
 * @license    GPL 2 (http://www.gnu.org/licenses/gpl.html)
 * @author     Andreas Gohr <andi@splitbrain.org>
 */

if(!defined('DOKU_INC')) define('DOKU_INC',realpath(dirname(__FILE__).'/../../').'/');
if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');
require_once(DOKU_PLUGIN.'syntax.php');
require_once(DOKU_INC.'inc/HTTPClient.php');


if(!defined('AMAZON_APIKEY')) define('AMAZON_APIKEY','0R9FK149P6SYHXZZDZ82');

/**
 * All DokuWiki plugins to extend the parser/rendering mechanism
 * need to inherit from this class
 */
class syntax_plugin_amazon extends DokuWiki_Syntax_Plugin {

    /**
     * return some info
     */
    function getInfo(){
        return array(
            'author' => 'Andreas Gohr',
            'email'  => 'andi@splitbrain.org',
            'date'   => '2008-09-15',
            'name'   => 'Amazon Plugin',
            'desc'   => 'Pull bookinfo from Amazon',
            'url'    => 'http://wiki.splitbrain.org/plugin:amazon',
        );
    }

    /**
     * What kind of syntax are we?
     */
    function getType(){
        return 'substition';
    }

    function getPType(){
        return 'block';
    }

    /**
     * Where to sort in?
     */
    function getSort(){
        return 160;
    }

    /**
     * Connect pattern to lexer
     */
    function connectTo($mode) {
      $this->Lexer->addSpecialPattern('\{\{amazon>[\w:\\-]+\}\}',$mode,'plugin_amazon');
    }

    /**
     * Handle the match
     */
    function handle($match, $state, $pos, &$handler){
        $match = substr($match,9,-2); // Strip markup
        list($ctry,$asin) = explode(':',$match);

        // no country given?
        if(empty($asin)){
            $asin = $ctry;
            $ctry = 'us';
        }

        // correct country given?
        if(!preg_match('/^(us|uk|jp|de|fr|ca)$/',$ctry)){
            $ctry = 'us';
        }

        // get partner id
        $partner = $this->getConf('partnerid_'.$ctry);

        // correct domains
        if($ctry == 'us') $ctry = 'com';
        if($ctry == 'uk') $ctry = 'co.uk';

        // build API Url
        $url = "http://ecs.amazonaws.$ctry/onca/xml?Service=AWSECommerceService&AWSAccessKeyId=".AMAZON_APIKEY.
               "&AssociateTag=$partner".
               "&Operation=ItemLookup".
               "&ResponseGroup=Medium,OfferFull";
        if(strlen($asin)<13){
            $url .= "&IdType=ASIN&ItemId=$asin";
        }else{
            $url .= "&SearchIndex=Books&IdType=ISBN&ItemId=$asin";
        }


        // fetch it
        $http = new DokuHTTPClient();
        $xml  = $http->get($url);
        if(empty($xml)){
            return array();
        }

        require_once(dirname(__FILE__).'/XMLParser.php');
        $xmlp = new XMLParser($xml);
        return $xmlp->getTree();
    }

    /**
     * Create output
     */
    function render($mode, &$renderer, $data) {
        if($mode == 'xhtml'){
            if(!count($data)){
                $renderer->doc .= '<p>failed to fetch data</p>';
                return false;
            }
            $renderer->doc .= $this->_format($data);
            return true;
        }
        return false;
    }

    function _format($data){
        global $conf;

        if($data['ITEMLOOKUPRESPONSE'][0]['ITEMS'][0]['REQUEST'][0]['ERRORS']){
            return $data['ITEMLOOKUPRESPONSE'][0]['ITEMS'][0]['REQUEST'][0]
                        ['ERRORS'][0]['ERROR'][0]['MESSAGE'][0]['VALUE'];
        }


        $item = $data['ITEMLOOKUPRESPONSE'][0]['ITEMS'][0]['ITEM'][0];
        $attr = $item['ITEMATTRIBUTES'][0];

//        dbg($attr);

        $img = '';
        if(!$img) $img = $item['MEDIUMIMAGE'][0]['URL'][0]['VALUE'];
        if(!$img) $img = $item['LARGEIMAGE'][0]['URL'][0]['VALUE'];
        if(!$img) $img = $item['SMALLIMAGE'][0]['URL'][0]['VALUE'];
        if(!$img) $img = 'http://images.amazon.com/images/P/01.MZZZZZZZ.gif'; // transparent pixel

        $img = ml($img,array('w'=>$this->getConf('imgw'),'h'=>$this->getConf('imgh')));

        ob_start();
        print '<div class="amazon">';
        print '<a href="'.$item['DETAILPAGEURL'][0]['VALUE'].'"';
        if($conf['target']['extern']) print ' target="'.$conf['target']['extern'].'"';
        print '>';
        print '<img src="'.$img.'" width="'.$this->getConf('imgw').'" height="'.$this->getConf('imgh').'" alt="" />';
        print '</a>';


        print '<div class="amazon_author">';
        if($attr['AUTHOR']){
            $this->display($attr['AUTHOR']);
        }elseif($attr['DIRECTOR']){
            $this->display($attr['DIRECTOR']);
        }elseif($attr['ARTIST']){
            $this->display($attr['ARTIST']);
        }elseif($attr['STUDIO']){
            $this->display($attr['STUDIO']);
        }elseif($attr['LABEL']){
            $this->display($attr['LABEL']);
        }elseif($attr['BRAND']){
            $this->display($attr['BRAND']);
        }
        print '</div>';

        print '<div class="amazon_title">';
        $this->display($attr['TITLE'][0]['VALUE']);
        print '</div>';



        print '<div class="amazon_isbn">';
        if($attr['ISBN']){
            print 'ISBN ';
            $this->display($attr['ISBN'][0]['VALUE']);
        }elseif($attr['RUNNINGTIME']){
            $this->display($attr['RUNNINGTIME'][0]['VALUE'].' ');
            $this->display($attr['RUNNINGTIME'][0]['ATTRIBUTES']['UNITS']);
        }elseif($attr['PLATFORM']){
            $this->display($attr['PLATFORM'][0]['VALUE']);
        }
        print '</div>';

        if($this->getConf('showprice')){
            print '<div class="amazon_price">';
            print htmlspecialchars($attr['LISTPRICE'][0]['FORMATTEDPRICE'][0]['VALUE']);
            print '</div>';
        }
        print '</div>';
        $out = ob_get_contents();
        ob_end_clean();

        return $out;
    }

    function display($input){
        $string = '';
        if(is_array($input)){
            foreach($input as $opt){
                if(is_array($opt) && $opt['VALUE']){
                    $string .= $opt['VALUE'].', ';
                }
            }
            $string = rtrim($string,', ');
        }else{
            $string = $input;
        }

        if($this->getConf('maxlen') && utf8_strlen($string) > $this->getConf('maxlen')){
            print '<span title="'.htmlspecialchars($string).'">';
            $string = utf8_substr($string,0,$this->getConf('maxlen') - 3);
            print htmlspecialchars($string);
            print '&hellip;</span>';
        }else{
            print htmlspecialchars($string);
        }
    }

}

//Setup VIM: ex: et ts=4 enc=utf-8 :
