<?
if (!defined('IN_CMS')) { exit(); }

class Blockhead {
	// entry point from wolf
	public function apply($text) {
		$blocks = BH::getblocks($text);
		$strlist = array();
		$offset = 0;
		foreach ($blocks as $block) {
			$endpos = $block->offset;
			$strlist[] = substr($text, $offset, $endpos-$offset);
			$offset = $endpos + $block->length;
			$snipname = "BH_{$block->type}";
			//$argstr = base64_encode(serialize($block->args));
			$blkcode = NULL;
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
			if ($blkcode == NULL) {
				$blkcode = "<? BH::block('$snipname', '{$block->args}'); ?>";
			}
			$strlist[] = $blkcode;
		}
		$strlist[] = substr($text, $offset);

		return implode("\n", $strlist);
	}
}
