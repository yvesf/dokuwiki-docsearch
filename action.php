<?php
/**
 * Script to search in uploaded pdf documents
 *
 * @author Dominik Eckelmann <eckelmann@cosmocode.de>
 * @author Yves Fischer 
 *
 * Converter:
 *  catdoc(/catppt):
 *   Site: http://site.n.ml.org/info/catdoc
 *  pdftotext:
 *   Debian 5: poppler-utils
 *   CentOS 4.3: xpdf-3.0-11.2
 */

if(!defined('DOKU_INC')) die();
if(!defined('DOKU_PLUGIN')) define('DOKU_PLUGIN',DOKU_INC.'lib/plugins/');
if(!defined('DOKU_DATA')) define('DOKU_DATA',DOKU_INC.'data/');

require_once(DOKU_PLUGIN.'action.php');
require_once(DOKU_INC . 'inc/fulltext.php');


class action_plugin_docsearch extends DokuWiki_Action_Plugin {
  var $datadir_docsearch;
  var $indexdir_docsearch;
  var $datadir_dokuwiki;
  var $indexdir_dokuwiki;
    
  /**
   * return some info
   */
  function getInfo() {
    return confToHash(dirname(__FILE__).'/plugin.info.txt');
  }
  
  /**
   * Register to the content display event to place the results under it.
   */
  function register(&$controller) {
    global $conf;
    $this->datadir_docsearch = DOKU_INC . $conf['savedir'] . "/docsearch/pages";
    $this->indexdir_docsearch = DOKU_INC . $conf['savedir'] . "/docsearch/index";
    $this->datadir_dokuwiki = $conf['datadir'];
    $this->indexdir_dokuwiki = $conf['indexdir'];

    $controller->register_hook('TPL_CONTENT_DISPLAY', 'AFTER', $this, 'display', array());
    $controller->register_hook('MEDIA_UPLOAD_FINISH', 'AFTER', $this, 'file_new', array());
    $controller->register_hook('MEDIA_DELETE_FILE', 'BEFORE', $this, 'file_delete', array());
    $controller->register_hook('MEDIA_SENDFILE', 'BEFORE', $this, 'file_send', array());
  }
  
  /**
   * do the search and displays the result
   */
  function display(&$event, $param) {
    global $ACT;
    global $ID;
    global $conf;
    global $QUERY;
    global $lang;
    
    // only work with search
    if ($ACT != 'search') return;
    
    $this->_set_savedir();

      
    // search the documents
    //search($res,$conf['datadir'],'search_fulltext',array('query'=>$ID));
    $data = ft_pageSearch($QUERY,$regex);
    
    // if there no results in the documents we have nothing else to do
    if (empty($data)) {
      return;
    }
    
    echo '<h2>'.hsc($this->getLang('title')).'</h2>';
    echo '<div class="search_result">';
    
    // printout the results
    $num = 0;
    foreach ($data as $id => $hits) {
      if (auth_quickaclcheck($id) >= AUTH_READ) {
	echo '<a href="'.ml($id).'" title="" class="wikilink1">'.hsc($id).'</a>:';
	echo '<span class="search_cnt">'.hsc($hits).' '.hsc($lang['hits']).'</span> ';
	echo '<span class="search_mimetype">'.$this->getLang('filetype').': ' . strtoupper(array_shift(mimetype($id))) . "</span>";
	if ($num < 15) {
	  echo '<div class="search_snippet">';
	  echo ft_snippet($id,$regex);
	  echo '</div>';
	}
	echo '<br />';
	$num ++;
      }
    }
    echo '</div>';

    $this->_reset_savedir();
  }
  
  //MEDIA_UPLOAD_FINISH event from lib/exe/mediamanager.php
  function file_new(&$event, $param) {
    //wiki id of uploaded file
    $id = $event->data[2];
    //mime-type of uploaded file
    $mimeType = $event->data[3];
    
    $this->_update_index($id);
  }
  
  //MEDIA_DELETE_FILE event from lib/exe/mediamanager.php
  function file_delete(&$event, $param) {
    $this->_update_index($event->data['id'], true);
  }
  
  
  //MEDIA_SENDFILE event from lib/exe/fetch.php
  function file_send(&$event, $param) {
    global $MEDIA;

    if ( ! $this->_is_indexed($MEDIA) ) {
      $this->_update_index($MEDIA);
    }
  }

  //check if mediafile is indexed
  function _is_indexed($id) {
    $this->_set_savedir();
    $page_idx = idx_getIndex('page', '');
    $this->_reset_savedir();    

    return is_int(array_search("$id\n", $page_idx));
  }

  /**
   * Adds/updates the document-search index
   * for given media-file.
   * @param $delete    if delete is true then the text-version
   *                   of this file will be removed from index and filesystem
   */
  function _update_index($id, $delete=false) {
    global $conf;    
    $ID_old = $ID;

    $ID = $id;
    $this->_set_savedir();    
    
    $in_file = mediaFN($ID);
    $out_file = $conf['datadir'] . "/" . str_replace(":", "/", $ID) . ".txt";
    if ( ! is_dir($out_file) and ! io_mkdir_p(dirname($out_file)) ) {
      msg($this->getLang("cannot_create_dir") . ": " . dirname($out_file), -1);
      return false;
    }

    $mimetype = mimetype($id);
    switch ($mimetype[1]) {
    case "application/pdf":
      $cmd = "/usr/bin/pdftotext " . escapeshellarg($in_file) . " " . escapeshellarg($out_file);
      break;
    case "application/msword":
      $cmd = "/usr/bin/catdoc -d utf-8 " . escapeshellarg($in_file) . " > " . escapeshellarg($out_file);
      break;
    case "application/mspowerpoint":
      $cmd = "/usr/bin/catppt -d utf-8 " . escapeshellarg($in_file) . " > " . escapeshellarg($out_file);
      break;
    default:
      msg($this->getLang("cannot_index_filetype") . ": " . $mimetype[0]);
      return false;
    }


    if ($delete) {
      $fh = fopen($out_file, "w");
      ftruncate($fh, 0);
      fclose($fh);
    } else {
      $system_return = system($cmd);
      if ($system_return != "") {
	msg($this->getLang("conversion_error") . ": " . $system_return, -1);
      }
    }

    if ( ! is_dir($conf['datadir']) and ! io_mkdir_p($conf['datadir']) ) {
      msg($this->getLang("cannot_create_dir") . ": " . realpath($conf['datadir']), -1);
      return false;
    }
    if ( ! is_dir($conf['indexdir']) and ! io_mkdir_p($conf['indexdir']) ) {
      msg($this->getLang("cannot_create_dir") . ": " . realpath($conf['indexdir']), -1);
      return false;
    }
    
    if (idx_addPage($ID)) {
      msg($this->getLang("index_refreshed") . ": " . $ID, 1);
    } else {
      msg($this->getLang("index_error") . ": " . $ID, -1);
    }
    
    if ($delete) {
      if ( ! @unlink($out_file) ) {
	msg($this->getLang("cannot_remove") . ": " . $out_file, -1);
      }
    }
    $ID = $ID_old;
    $this->_reset_savedir();
    return true;
  }

  function _set_savedir() {
    global $conf;
    // change the datadir to the docsearch data dir
    $conf['datadir'] = $this->datadir_docsearch;
    // set the index directory to the docsearch index
    $conf['indexdir'] = $this->indexdir_docsearch;
  }
  function _reset_savedir() {
    global $conf;
    $conf['datadir'] = $this->datadir_dokuwiki;
    $conf['indexdir'] = $this->indexdir_dokuwiki;
  }
}

?>
