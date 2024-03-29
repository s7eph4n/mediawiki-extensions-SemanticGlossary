<?php

namespace SG\Maintenance;

use SG\PropertyRegistry;
use SG\Cache\GlossaryCache;

use SMWUpdateJob as UpdateJob;
use SMW\Store;

use SMWQuery as Query;
use SMWSomeProperty as SomeProperty;
use SMWDIProperty as DIProperty;
use SMWThingDescription as ThingDescription;

/**
 * Part of the `rebuildGlossaryCache.php` maintenance script
 *
 * @ingroup SG
 *
 * @license GNU GPL v2+
 * @since 1.1
 *
 * @author mwjames
 */
class GlossaryCacheRebuilder {

	/** @var Store */
	protected $store;

	/** @var GlossaryCache */
	protected $glossaryCache;

	protected $reporter = null;
	protected $rebuildCount = 0;
	protected $verbose = false;

	/**
	 * @since 1.1
	 *
	 * @param Store $store
	 * @param GlossaryCache $glossaryCache
	 * @param $reporter
	 */
	public function __construct( Store $store, GlossaryCache $glossaryCache, $reporter = null ) {
		$this->store = $store;
		$this->glossaryCache = $glossaryCache;
		$this->reporter = $reporter; // Should be a MessageReporter instance
	}

	/**
	 * @since 1.1
	 *
	 * @param array $options
	 */
	public function setParameters( array $options ) {
		$this->verbose = array_key_exists( 'verbose', $options );
	}

	/**
	 * @since 1.1
	 *
	 * @return int
	 */
	public function getRebuildCount() {
		return $this->rebuildCount;
	}

	/**
	 * @since 1.1
	 *
	 * @return boolean
	 */
	public function rebuild() {

		$pages = $this->store->getQueryResult( $this->buildQuery() )->getResults();

		$this->removeEntitiesFromCache( $pages );
		$this->updateSelectedPages( $pages );

		return true;
	}

	protected function updateSelectedPages( array $pages ) {

		$titleCache = array();

		foreach ( $pages as $page ) {

			$title = $page->getTitle();

			if ( $title !== null && !isset( $titleCache[ $title->getPrefixedDBkey() ] ) ) {

				$this->rebuildCount++;

				$this->reportMessage( "($this->rebuildCount) Processing page " . $title->getPrefixedDBkey() . " ...\n", $this->verbose );

				// FIXME Wrong approach, users outside of smw-core should not
				// directly create an instance and instead use a factory for
				// that purpose such as JobFactory::newUpdateJob( ... )
				$updatejob = new UpdateJob( $title );
				$updatejob->run();

				$titleCache[ $title->getPrefixedDBkey() ] = true;
			}
		}

		$this->reportMessage( "$this->rebuildCount pages refreshed.\n" );

		return true;
	}

	protected function buildQuery() {

		$description = new SomeProperty(
			new DIProperty( PropertyRegistry::SG_TERM ),
			new ThingDescription()
		);

		$countQuery = new Query( $description, false, false );
		$countQuery->querymode = Query::MODE_COUNT;

		$numberOfPages = (int)$this->store->getQueryResult( $countQuery );

		$resultQuery = new Query( $description, false, false );

		// FIXME SMWQuery setLimit
		// @see SMW\Store\Maintenance\DataRebuilder
		$beforeMaxLimitManipulation = $GLOBALS['smwgQMaxLimit'];
		$GLOBALS['smwgQMaxLimit'] = $numberOfPages;
		$resultQuery->setLimit( $numberOfPages, false );
		$GLOBALS['smwgQMaxLimit'] = $beforeMaxLimitManipulation;

		return $resultQuery;
	}

	protected function removeEntitiesFromCache( array $pages ) {

		$cache = $this->glossaryCache->getCache();

		$cache->delete( $this->glossaryCache->getKeyForLingo() );

		foreach ( $pages as $page ) {
			$cache->delete( $this->glossaryCache->getKeyForSubject( $page ) );
		}

		$this->reportMessage( "\n" . ( count( $pages ) + 1 ) . " cache entities deleted.\n\n" );
	}

	/**
	 * @codeCoverageIgnore
	 */
	protected function reportMessage( $message, $output = true ) {
		if ( is_callable( $this->reporter ) && $output ) {
			call_user_func( $this->reporter, $message );
		}
	}

}
