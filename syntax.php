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
    //shorten string to this length on output
    var $MAXCHARS = 25; //set to 0 if not wanted


    // set your partnerid for the different local sites here
    var $partnerid = array(
        'de'  => 'ballermannsyndic',
        'com' => 'splitbrainorg-20',
    );


    /**
     * return some info
     */
    function getInfo(){
        return array(
            'author' => 'Andreas Gohr',
            'email'  => 'andi@splitbrain.org',
            'date'   => '2007-04-25',
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
      $this->Lexer->addSpecialPattern('\{\{amazon>[^}]*\}\}',$mode,'plugin_amazon');
    }

    /**
     * Handle the match
     */
    function handle($match, $state, $pos, &$handler){
        $match = substr($match,9,-2); // Strip markup
        list($ctry,$asin) = explode(':',$match);

        if(empty($asin)){
            $asin = $ctry;
            $ctry = 'us';
        }
        if($ctry == 'us') $ctry = 'com';
        if($ctry == 'uk') $ctry = 'co.uk';
        if($ctry == 'jp') $ctry = 'co.jp';

        $partner = $this->partnerid[$ctry];

        // build API Url
        $url = 'http://webservices.amazon.'.$ctry.'/onca/xml?Service=AWSECommerceService'.
               '&AWSAccessKeyId='.AMAZON_APIKEY.
               '&Operation=ItemLookup&IdType=ASIN&ItemId='.$asin.
               '&ResponseGroup=Medium,OfferFull'.
               '&AssociateTag='.$partner;

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
                $renderer .= 'failed to fetch data';
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

        ob_start();
        print '<div class="amazon">';
        print '<a href="'.$item['DETAILPAGEURL'][0]['VALUE'].'"';
        if($conf['target']['extern']) print ' target="'.$conf['target']['extern'].'"';
        print '>';
        print '<img height="60" src="'.DOKU_BASE.'lib/exe/fetch.php?media='.
                urlencode($item['SMALLIMAGE'][0]['URL'][0]['VALUE']).'&amp;h=60" alt="" />';
        print '</a>';


        print '<div class="amazon_author">';
        if($attr['AUTHOR']){
            $this->display($attr['AUTHOR'][0]['VALUE']);
        }elseif($attr['DIRECTOR']){
            $this->display($attr['DIRECTOR'][0]['VALUE']);
        }elseif($attr['ARTIST']){
            $this->display($attr['ARTIST'][0]['VALUE']);
        }elseif($attr['STUDIO']){
            $this->display($attr['STUDIO'][0]['VALUE']);
        }elseif($attr['LABEL']){
            $this->display($attr['LABEL'][0]['VALUE']);
        }elseif($attr['BRAND']){
            $this->display($attr['BRAND'][0]['VALUE']);
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

        print '</div>';
        $out = ob_get_contents();
        ob_end_clean();

        return $out;
    }

    function display($string){
        if($this->MAXCHARS && utf8_strlen($string) > $this->MAXCHARS){
            print '<span title="'.htmlspecialchars($string).'">';
            $string = utf8_substr($string,0,$this->MAXCHARS - 3);
            print htmlspecialchars($string);
            print '&hellip;</span>';
        }else{
            print htmlspecialchars($string);
        }
    }

}

//Setup VIM: ex: et ts=4 enc=utf-8 :
?>
