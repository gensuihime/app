<?php

/**
 * CensusDataRetriever Extension is responsible for pulling data from SOE database
 * and providing preformated data for user creating new page
 *
 * @author Kamil Koterba  <kamil(at)wikia-inc.com>
 */

class CensusDataRetriever {

	public static function onEditFormPreloadText( &$text, &$title )
	{
$text = 'foobar22';
die( 'blah' );
wfDebug("onEditFromPreloadedText kamilk1");

//wfDebug("onEditFromPreloadedText kamilk1".$text.$title);
//$text='kamilk';
//		wfDebug("onEditFromPreloadedText kamilk\n");

		return true;
	}

}
