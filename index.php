<?php
if (!defined('IN_CMS')) { exit(); }

Plugin::setInfos(array(
    'id'          => 'blockhead',
    'title'       => __('BlockHead'),
    'description' => __('Facilitates creation of block-based pages.'),
    'version'     => '0.1',
    'license'     => 'GPL',
    'author'      => 'Scott MacLean',
));

Filter::add('blockhead', 'blockhead/filter.php');
Plugin::addController('blockhead', __('BlockHead'), 'administrator', false);

function blockhead_getheadstrings($text) {
	$blocks = BH::getblocks($text);
	$strlist = array();
	if (!empty($blocks)) {
		$strlist[] = '<?';
	}
	foreach ($blocks as $block) {
		$snipname = "BH_{$block->type}_head";
		$blkhead = NULL;
		if ($block->_static) {
			$snippet = Snippet::findByName($snipname);
			if ($snippet) {
				BH::pushargs($block->args);
				ob_start();
				eval('?>'.$snippet->content_html);
				$blkcode = ob_get_clean();
				BH::popargs();
			}
			else {
				Flash::set('info', "Block snippet $snipname was not found, so it could not be evaluated statically.");
			}
		}
		if ($blkhead == NULL) {
			$blkhead = "\$this->includeSnippet($snipname);";
		}
		$strlist[] = $blkhead;
	}
	if (!empty($blocks)) {
		$strlist[] = 'BH::head(); ?>';
	}
	return $strlist;
}

function blockhead_page($page) {
	$parts =& $_POST['part'];
	$headpart = NULL;
	$strlist = array();
	foreach ($parts as &$part) {
		if ($part['name'] == 'blockhead') {
			$headpart =& $part;
		}
		else if ($part['filter_id'] == 'blockhead') {
			$partlist = blockhead_getheadstrings($part['content']);
			$strlist = array_merge($strlist, $partlist);
		}
	}

	if (!empty($strlist)) {
		if ($headpart == NULL) {
			$headpart = array();
			$headpart['name'] = 'blockhead';
			$n = array_push($parts, $headpart);
			$headpart =& $parts[$n-1];
		}

		$headpart['content'] = implode("\n", $strlist);
	}
	else if ($headpart != NULL) {
		foreach ($parts as $i => $part) {
			if ($part['name'] == 'blockhead') {
				unset($_POST[$i]);
			}
		}
	}
}

function blockhead_snippet($snippet) {
	if ($snippet->filter_id != 'blockhead') {
		return;
	}

	$strlist = blockhead_getheadstrings($snippet->content);
	$name = $snippet->name . "_head";
	$ini = Snippet::findByName($name);
	if (!empty($strlist)) {
		if ($ini == NULL) {
			$ini = new Snippet();
			$ini->name = $name;
			$ini->filter_id = '';
			$ini->content = '';
			$ini->content_html = '';
			$action = 'added';
		}
		else {
			$action = 'updated';
		}
		//$ini->content = implode("\n", $strlist);
		$ini->content_html = $ini->content_html . implode("\n", $strlist);
		if ($ini->save()) {
			Flash::set('info', "Blockhead snippet $name has been {$action}.");
		}
		else {
			Flash::set('error', "Error adding blockhead snippet {$name}.");
		}
	}
	else if ($ini) {
		if ($ini->delete()) {
			Flash::set('info', "Init snippet $name has been deleted.");
		}
		else {
			Flash::set('error', "Error deleting blockhead snippet {$name}.");
		}
	}
}

Observer::observe('page_add_before_save', 'blockhead_page');
Observer::observe('page_edit_before_save', 'blockhead_page');
Observer::observe('snippet_after_add', 'blockhead_snippet');
Observer::observe('snippet_after_edit', 'blockhead_snippet');

class BH {
	private static $args = array();
	private static $deps = array();
	private static $staticpt = -1;

	public static function pushargs($args) {
		// limit stack depth to prevent infinite recursion
		if (count(BH::$args) >= 8) {
			return FALSE;
		}

		BH::$args[] = json_decode($args);
		if (count(BH::$args) > 1) {
			next(BH::$args);
		}
		return TRUE;
	}

	public static function popargs() {
		prev(BH::$args);
		array_pop(BH::$args);
		// when we pop the top-most static block, stop forcing static block inclusion
		if (count(BH::$args) == BH::$staticpt) {
			BH::$staticpt = -1;
		}
	}

	public static function arg($name) {
		$curargs = current(BH::$args);
		if ($curargs !== FALSE && isset($curargs) && isset($curargs->{$name})) {
			return $curargs->{$name};
		}
		return NULL;
	}

	public static function dump() {
		var_export(BH::$args);
	}

	private static function _snippet($name) {
		$snippet = Snippet::findByName($name);
		if ($snippet) {
			eval('?>'.$snippet->content_html);
		}
	}

	public static function block($snipname, $blkargs) {
		if (BH::pushargs($blkargs)) {
			BH::_snippet($snipname);
			BH::popargs();
		}
		else {
			echo '<p>The blocks on this page are too deeply nested to render.</p>';
		}
	}

	public static function dep($type, $name, $extra = '') {
		if (!isset(BH::$deps[$type])) {
			BH::$deps[$type] = array();
		}
		if (!isset(BH::$deps[$type][$name])) {
			BH::$deps[$type][$name] = array();
		}
		BH::$deps[$type][$name][$extra] = $extra;
	}

	public static function head() {
		foreach (BH::$deps as $type => $names) {
			foreach ($names as $name => $extras) {
				foreach ($extras as $extra) {
					BH::_writehead($type, $name, $extra);
				}
			}
		}
	}

	private static function _writehead($type, $name, $extra) {
		$path = URI_PUBLIC . "public/$type/$name";
		if ($type == 'css') {
			echo "<link rel=\"stylesheet\" href=\"$path\" $extra/>\n";
		}
		else if ($type == 'js') {
			echo "<script src=\"$path\">$extra</script>\n";
		}
	}

	public static function getblocks($text) {
		$REGEX = '/\[\[(!|)\s*([a-zA-Z0-9_-]+)\s*(\{[^\{\}]*\}|)\s*\]\]/';
		$n = preg_match_all($REGEX, $text, $blocks, PREG_PATTERN_ORDER | PREG_OFFSET_CAPTURE);
		$blks = array();
		for ($i = 0; $i < $n; ++$i) {
			$block = new stdClass();
			if (!empty($blocks[1][$i][0])) {
				BH::$staticpt = count(BH::$args);
			}
			$block->type = $blocks[2][$i][0];
			$block->args = $blocks[3][$i][0];
			$block->offset = $blocks[0][$i][1];
			$block->length = strlen($blocks[0][$i][0]);
			if (strlen($block->args) == 0) {
				$block->args = '{}';
			}
			$blkargs = json_decode($block->args);
			if (BH::$staticpt >= 0 || (isset($blkargs->_static) && $blkargs->_static == 'yes')) {
				$block->_static = TRUE;
			}
			else {
				$block->_static = FALSE;
			}
			$blks[] = $block;
		}
		return $blks;
	}
}
