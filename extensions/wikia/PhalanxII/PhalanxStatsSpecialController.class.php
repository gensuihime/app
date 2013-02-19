<?php

class PhalanxStatsSpecialController extends WikiaSpecialPageController {
	private $phalanxTitle = null;
	private $title = null;

	function __construct( ) {
		parent::__construct( 'PhalanxStats', 'phalanx', false );
		$this->title = F::build( 'Title', array( 'PhalanxStats', NS_SPECIAL ), 'newFromText' );
		$this->phalanxTitle = F::build( 'Title', array( 'Phalanx', NS_SPECIAL ), 'newFromText' );
	}

	public function index() {
		$this->wf->profileIn( __METHOD__ );
		$this->wg->Out->setPageTitle( $this->wf->Msg('phalanx-stats-title') );

		if ( !$this->userCanExecute( $this->wg->User ) ) {
			$this->displayRestrictionError();
			$this->wf->profileOut( __METHOD__ );
			return;
		}

		$par = $this->getPar();
		$blockId = $this->wg->Request->getInt('blockId', intval($par));
		if ( empty( $blockId ) ) {
			// show help page
			$this->forward('PhalanxStatsSpecial', 'help');
		} elseif ( strpos( $par, 'wiki' ) !== false ) {
			// show per-wiki stats
			list ( , $wikiId ) = explode( "/", $par, 2 );
			$this->blockWikia($wikiId);
		} else {
			// show block stats
			$this->blockStats($blockId);
		}

		$this->wf->profileOut( __METHOD__ );
	}

	private function blockStats($blockId) {
		$this->wg->Out->setPageTitle( sprintf( "%s #%s", $this->wf->Msg('phalanx-stats-title'), $blockId ) );

		$data = F::build( 'Phalanx', array( $blockId ), 'newFromId' );

		if ( !isset( $data["id"] ) ) {
			$this->wg->Out->addWikiMsg( 'phalanx-stats-block-notfound', $blockId );
			return;
		}

		$phalanxUrl = $this->phalanxTitle->getFullUrl( array( 'id' => $data['id'] ) );

		$data['author_id'] = F::build( 'User', array( $data['author_id'] ), 'newFromId' )->getName();
		$data['type'] = implode( ', ', Phalanx::getTypeNames( $data['type'] ) );
		$data['timestamp'] = $this->wg->Lang->timeanddate( $data['timestamp'] );

		if ( $data['expire'] == null ) {
			$data['expire'] = 'infinite';
		} else {
			$data['expire'] = $this->wg->Lang->timeanddate( $data['expire'] );
		}

		$data['regex'] = $data['regex'] ? 'Yes' : 'No';
		$data['case']  = $data['case']  ? 'Yes' : 'No';
		$data['exact'] = $data['exact'] ? 'Yes' : 'No';
		$data['lang'] = empty( $data['lang'] ) ? '*' : $data['lang'];

		/* pull these out of the array, so they dont get used in the top rows */
		if ( $data['type'] & Phalanx::TYPE_EMAIL && !$this->wg->User->isAllowed( 'phalanxemailblock' ) ) {
			/* hide email from non-privildged users */
			$data['text'] = $this->wf->Msg( 'phalanx-email-filter-hidden' );
		}

		/* stats table */
		$headers = array(
			$this->wf->Msg('phalanx-stats-table-id'),
			$this->wf->Msg('phalanx-stats-table-user'),
			$this->wf->Msg('phalanx-stats-table-type'),
			$this->wf->Msg('phalanx-stats-table-create'),
			$this->wf->Msg('phalanx-stats-table-expire'),
			$this->wf->Msg('phalanx-stats-table-regex'),
			$this->wf->Msg('phalanx-stats-table-case'),
			$this->wf->Msg('phalanx-stats-table-exact'),
			$this->wf->Msg('phalanx-stats-table-language'),
		);

		$tableAttribs = array(
			'class' => 'wikitable',
			'width' => '100%',
		);

		$row = $data->toArray();
		unset($row['text']);
		unset($row['reason']);
		unset($row['ip_hex']);

		$table  = Xml::buildTable( array( $row ), $tableAttribs, $headers );
		$table  = str_replace("</table>", "", $table);
		$table .= "<tr><th>" . $this->wf->Msg('phalanx-stats-table-text') . "</th><td colspan='8'>" . htmlspecialchars( $data['text'] ) . "</td></tr>";
		$table .= "<tr><th>" . $this->wf->Msg('phalanx-stats-table-reason')  ."</th><td colspan='8'>{$data['reason']}</td></tr>";
		$table .= "</table>";

		$link = Html::element( 'a', array( 'class' => 'modify', 'href' => $phalanxUrl ), $this->wf->Msg('phalanx-link-modify') );
		$html = $table . "\n&bull; " . $link . "<br/>\n";
		$this->wg->Out->addHTML( $html );

		/* pager */
		$pager = new PhalanxStatsPager( $blockId );
		$html  = $pager->getNavigationBar();
		$html .= $pager->getBody();
		$html .= $pager->getNavigationBar();
		$this->wg->Out->addHTML( $html );
	}

	private function blockWikia($wikiId) {
		$this->wg->Out->addWikiMsg('phalanx-stats-not-implemented');

		/**
		$oWiki = WikiFactory::getWikiById( $this->wikiId );
		if ( !is_object($oWiki) ) {
			return false;
		}

		// process block data for display
		$data = array(
			'wiki_id'         => $oWiki->city_id,
			'sitename'        => WikiFactory::getVarValueByName( "wgSitename", $oWiki->city_id ),
			'url'             => WikiFactory::getVarValueByName( "wgServer", $oWiki->city_id ),
			'last_timestamp'  => $this->wg->Lang->timeanddate( $oWiki->city_last_timestamp ),
		);

		// we have a valid id, change title to use it
		$this->wg->Out->setPageTitle( $this->wf->Msg( 'phalanx-stats-title' ) . ': ' . $data['url'] );

		$headers = array(
			$this->wf->Msg('phalanx-stats-table-wiki-id'),
			$this->wf->Msg('phalanx-stats-table-wiki-name'),
			$this->wf->Msg('phalanx-stats-table-wiki-url'),
			$this->wf->Msg('phalanx-stats-table-wiki-last-edited'),
		);

		$tableAttribs = array(
			'border' => 1,
			'class' => 'wikitable',
			'style' => "font-family:monospace;",
		);

		$table = Xml::buildTable( array( $data ), $tableAttribs, $headers );
		$this->wg->Out->addHTML( $table . "<br />\n" );

		$pager = new PhalanxStatsWikiaPager( $this->wikiId );
		$html  = $pager->getNavigationBar();
		$html .= $pager->getBody();
		$html .= $pager->getNavigationBar();
		$this->wg->Out->addHTML( $html );
		 **/
	}

	public function help() {
		$this->setVal( 'action', $this->title->getFullURL() );
	}
}
