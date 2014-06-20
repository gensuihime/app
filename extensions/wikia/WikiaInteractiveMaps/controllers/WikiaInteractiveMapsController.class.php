<?php
/**
 * Class WikiaInteractiveMapsController
 * @desc Special:WikiaInteractiveMaps controller
 */
class WikiaInteractiveMapsController extends WikiaSpecialPageController {

	const MAP_PREVIEW_WIDTH = 660;
	const MAP_HEIGHT = 600;
	const PIN_TYPE_MARKER_WIDTH = 60;
	const MAPS_PER_PAGE = 10;
	const PAGE_NAME = 'InteractiveMaps';

	/**
	 * @var WikiaMaps
	 */
	private $mapsModel;

	/**
	 * Keeps data needed while creating map/tile set process
	 * @var Array
	 */
	private $creationData;

	/**
	 * @desc Special page constructor
	 *
	 * @param null $name
	 * @param string $restriction
	 * @param bool $listed
	 * @param bool $function
	 * @param string $file
	 * @param bool $includable
	 */
	public function __construct( $name = null, $restriction = 'editinterface', $listed = true, $function = false, $file = 'default', $includable = false ) {
		parent::__construct( self::PAGE_NAME, $restriction, $listed, $function, $file, $includable );
		$this->mapsModel = new WikiaMaps( $this->wg->IntMapConfig );
	}

	/**
	 * Interactive maps special page
	 */
	public function index() {
		$this->wg->SuppressPageHeader = true;
		$this->wg->out->setHTMLTitle( wfMessage( 'wikia-interactive-maps-title' )->escaped() );

		if ( is_numeric( $this->getPar() ) ) {
			$this->forward( 'WikiaInteractiveMaps', 'map' );
		} else {
			$this->forward( 'WikiaInteractiveMaps', 'main' );
		}
	}

	/**
	 * Main Special:InteractiveMaps page
	 */
	public function main() {
		$selectedSort = $this->getVal( 'sort', null );
		$this->setVal( 'selectedSort', $selectedSort );
		$currentPage = $this->request->getInt( 'page', 1 );

		$offset = $this->getPaginationOffset( $currentPage, self::MAPS_PER_PAGE );

		$params = [
			'city_id' => $this->app->wg->CityId,
			'sort' => $selectedSort,
			'offset' => $offset,
			'limit' => self::MAPS_PER_PAGE,
		];

		$mapsResponse = $this->mapsModel->cachedRequest( 'getMapsFromApi', $params );

		if ( !$mapsResponse ) {
			$this->forward( 'WikiaInteractiveMaps', 'error' );
			return;
		}

		$this->setVal( 'maps', $mapsResponse->items );
		$this->setVal( 'hasMaps', !empty( $mapsResponse->total ) );

		$url = $this->getContext()->getTitle()->getFullURL();
		$this->setVal( 'baseUrl', $url );

		$messages = [
			'wikia-interactive-maps-title' => wfMessage( 'wikia-interactive-maps-title' ),
			'wikia-interactive-maps-create-a-map' => wfMessage( 'wikia-interactive-maps-create-a-map' ),
			'wikia-interactive-maps-no-maps' => wfMessage( 'wikia-interactive-maps-no-maps' )
		];
		$this->setVal( 'messages', $messages );
		$this->setVal( 'sortingOptions', $this->mapsModel->getSortingOptions( $selectedSort ) );

		$urlParams = [];
		if ( !is_null( $selectedSort ) ) {
			$urlParams['sort'] = $selectedSort;
		}

		$url = $this->getContext()->getTitle()->getFullURL( $urlParams );

		$pagination = false;
		$totalMaps = (int)$mapsResponse->total;

		if ( $totalMaps > self::MAPS_PER_PAGE ) {
			$pagination = $this->app->renderView(
				'PaginationController',
				'index',
				array(
					'totalItems' => $totalMaps,
					'itemsPerPage' => self::MAPS_PER_PAGE,
					'currentPage' => $currentPage,
					'url' => $url
				)
			);
		}
		$this->setVal( 'pagination', $pagination );

		$this->response->addAsset( 'extensions/wikia/WikiaInteractiveMaps/css/WikiaInteractiveMaps.scss' );
		$this->response->setTemplateEngine( WikiaResponse::TEMPLATE_ENGINE_MUSTACHE );
	}

	/**
	 * Single map page
	 */
	public function map() {
		$mapId = (int)$this->getPar();
		$zoom = $this->request->getInt( 'zoom', WikiaInteractiveMapsParserTagController::DEFAULT_ZOOM );
		$lat = $this->request->getInt( 'lat', WikiaInteractiveMapsParserTagController::DEFAULT_LATITUDE );
		$lon = $this->request->getInt( 'lon', WikiaInteractiveMapsParserTagController::DEFAULT_LONGITUDE );

		$map = $this->mapsModel->cachedRequest(
			'getMapByIdFromApi',
			[ 'id' => $mapId ]
		);

		if ( isset( $map->title ) ) {
			$this->wg->out->setHTMLTitle( $map->title );

			$url = $this->mapsModel->buildUrl( [
				WikiaMaps::ENTRY_POINT_RENDER,
				$mapId,
				$zoom,
				$lat,
				$lon
			] );

			$this->setVal( 'title', $map->title );
			$this->setVal( 'mapFound', true );
			$this->setVal( 'url', $url );
			$this->setVal( 'height', self::MAP_HEIGHT );
			$this->setVal( 'menu', $this->getMenuMarkup() );
			$this->setVal( 'mapId', $mapId );
		} else {
			$this->setVal( 'mapFound', false );
			$this->setVal( 'title', wfMessage( 'error' ) );
			$this->setVal( 'messages', [
				'wikia-interactive-maps-map-not-found-error' => wfMessage( 'wikia-interactive-maps-map-not-found-error' )
			] );
		}
		$this->response->setTemplateEngine( WikiaResponse::TEMPLATE_ENGINE_MUSTACHE );
	}

	/**
	 * @desc Renders the menu markup for the map page from mustache
	 * @return string
	 */
	function getMenuMarkup() {
		$actionButtonArray = [
			'action' => [
				'text' => wfMessage( 'wikia-interactive-maps-actions' )->escaped(),
			],
			'dropdown' => [
				'deleteMap' => [
					'text' => wfMessage( 'wikia-interactive-maps-delete-map' )->escaped(),
					'id' => 'deleteMap'
				]
			],
		];
		return F::app()->renderView( 'MenuButton', 'index', $actionButtonArray );
	}

	/**
	 * Upload a file entry point
	 */
	public function upload() {
		$uploadType = $this->request->getVal( 'uploadType' );
		$upload = new WikiaInteractiveMapsUploadImageFromFile();
		$upload->initializeFromRequest( $this->wg->Request );
		$uploadResults = $upload->verifyUpload( $uploadType );
		$uploadStatus = [ 'success' => false ];

		if ( empty( $this->wg->EnableUploads ) ) {
			$uploadStatus[ 'errors' ] = [ wfMessage( 'wikia-interactive-maps-image-uploads-disabled' )->plain() ];
		} else if ( $uploadResults[ 'status' ] !== UploadBase::OK ) {
			$uploadStatus[ 'errors' ] = [ $this->translateError( $uploadResults[ 'status' ] ) ];
		} else if ( ( $warnings = $upload->checkWarnings() ) && !empty( $warnings ) ) {
			$uploadStatus[ 'errors' ] = [ wfMessage( 'wikia-interactive-maps-image-uploads-warning' )->parse() ];
		} else {
			//save temp file
			$file = $upload->stashFile();

			if ( $file instanceof File && $file->exists() ) {
				$uploadStatus[ 'success' ] = true;
				$originalWidth = $file->getWidth();

				// $originalHeight = $file->getHeight();
				// $imageServing = new ImageServing( null, $originalWidth );
				// $uploadStatus[ 'fileUrl' ] = $imageServing->getUrl( $file, $originalWidth, $originalHeight );

				// OK, so I couldn't use ImageService because it works only on uploaded files
				// image serving worked with stashed files but it cuts it in a weird way
				// not to block this any longer I came with the line below but we need to sort it out
				// and write in a cleaner way
				// TODO: Talk to Platform Team about adding possibility to add stashed files via ImageService

				$uploadStatus[ 'fileUrl' ] = $this->getStashedImageThumb( $file, $originalWidth );

				switch ( $uploadType ) {
					case WikiaInteractiveMapsUploadImageFromFile::UPLOAD_TYPE_MAP:
						$thumbWidth = self::MAP_PREVIEW_WIDTH;
						break;

					case WikiaInteractiveMapsUploadImageFromFile::UPLOAD_TYPE_PIN_TYPE_MARKER:
						$thumbWidth = self::PIN_TYPE_MARKER_WIDTH;
						break;
				}

				$uploadStatus[ 'fileThumbUrl' ] = $this->getStashedImageThumb( $file, $thumbWidth );
			} else {
				$uploadStatus[ 'success' ] = false;
			}
		}

		$this->setVal( 'results', $uploadStatus );
	}

	/**
	 * Maps error status code to an error message
	 * @param Integer $errorStatus error code status returned from UploadBase method
	 * @return String
	 */
	private function translateError( $errorStatus ) {
		switch ( $errorStatus ) {
			case UploadBase::FILE_TOO_LARGE:
				$errorMessage = wfMessage( 'wikia-interactive-maps-image-uploads-error-file-too-large', $this->getMaxFileSize() )->plain();
				break;
			case UploadBase::EMPTY_FILE:
				$errorMessage = wfMessage( 'wikia-interactive-maps-image-uploads-error-empty-file' )->plain();
				break;
			case UploadBase::FILETYPE_BADTYPE:
			case UploadBase::VERIFICATION_ERROR:
				$errorMessage = wfMessage( 'wikia-interactive-maps-image-uploads-error-bad-type' )->plain();
				break;
			case WikiaInteractiveMapsUploadImageFromFile::PIN_TYPE_MARKER_IMAGE_TOO_SMALL_ERROR:
				$errorMessage = wfMessage(
					'wikia-interactive-maps-image-uploads-error-pin-type-marker-too-small',
					WikiaInteractiveMapsUploadImageFromFile::PIN_TYPE_MARKER_IMAGE_MIN_SIZE
				)->plain();
				break;
			default:
				$errorMessage = wfMessage( 'wikia-interactive-maps-image-uploads-error' )->parse();
				break;
		}

		return $errorMessage;
	}

	/**
	 * Returns max upload file size in MB (gets it from config)
	 * @return float
	 * @todo Extract it somewhere to includes/wikia/
	 */
	private function getMaxFileSize() {
		return $this->wg->MaxUploadSize / 1024 / 1024;
	}

	/**
	 * get list of tile sets
	 *
	 * @return Array
	 */

	public function getTileSets() {
		$params = [];
		$searchTerm = $this->request->getVal( 'searchTerm', null );

		if ( !is_null( $searchTerm ) ) {
			$params[ 'searchTerm' ] = $searchTerm;
		}

		$this->response->setVal( 'results', $this->mapsModel->getTileSets( $params ) );
	}

	/**
	 * Entry point to create a map from either existing tiles or new image
	 *
	 * @requestParam Integer $tileSetId an unique id of existing tiles
	 * @requestParam String $image an URL to image which the tiles will be created from
	 * @requestParam String $title map title
	 *
	 * @throws PermissionsException
	 * @throws BadRequestApiException
	 * @throws InvalidParameterApiException
	 */
	public function createMap() {
		$type = trim( $this->request->getVal( 'type', WikiaMaps::MAP_TYPE_GEO ) );

		$this->setCreationData( 'tileSetId', $this->request->getInt( 'tileSetId', 0 ) );
		$this->setCreationData( 'title', trim( $this->request->getVal( 'title', '' ) ) );
		$this->setCreationData( 'image', trim( $this->request->getVal( 'fileUrl', '' ) ) );

		$this->validateMapCreation();

		$this->setCreationData( 'creatorName', $this->wg->User->getName() );
		$this->setCreationData( 'cityId', (int) $this->wg->CityId );

		if ( $type === WikiaMaps::MAP_TYPE_CUSTOM ) {
			$results = $this->createCustomMap();
		} else {
			$results = $this->createGeoMap();
		}

		$this->setVal( 'results', $results );
	}

	/**
	 * Creates a custom map for given tileset or creating a tileset and then map out of it
	 *
	 * @return Array
	 */
	private function createCustomMap() {
		$tileSetId = $this->getCreationData( 'tileSetId' );

		if ( $tileSetId > 0 ) {
			$results = $this->createMapFromTilesetId();
		} else {
			$results = $this->createTileset();

			if ( true === $results[ 'success' ] ) {
				$this->setCreationData( 'tileSetId', $results[ 'content' ]->id );
				$results = $this->createMapFromTilesetId();
			}
		}

		return $results;
	}

	/**
	 * Creates a map from Geo tileset
	 *
	 * @return Array
	 */
	private function createGeoMap() {
		$this->setCreationData( 'tileSetId', $this->mapsModel->getGeoMapTilesetId() );
		return $this->createMapFromTilesetId();
	}

	/**
	 * Helper method to validate creation data
	 *
	 * @throws PermissionsException
	 * @throws BadRequestApiException
	 * @throws InvalidParameterApiException
	 */
	private function validateMapCreation() {
		$tileSetId = $this->getCreationData( 'tileSetId' );
		$imageUrl = $this->getCreationData( 'image' );
		$mapTitle = $this->getCreationData( 'title' );

		if ( $tileSetId === 0 && empty( $imageUrl ) && empty( $mapTitle ) ) {
			throw new BadRequestApiException( wfMessage( 'wikia-interactive-maps-create-map-bad-request-error' )->plain() );
		}

		if ( empty( $mapTitle ) ) {
			throw new InvalidParameterApiException( 'title' );
		}

		if ( !$this->wg->User->isLoggedIn() ) {
			throw new PermissionsException( 'interactive maps' );
		}
	}

	/**
	 * Helper method which sends request to maps service to create tiles' set
	 * and then processes the response providing results array
	 */
	private function createTileset() {
		return $this->mapsModel->saveTileset( [
			'name' => $this->getCreationData( 'title' ),
			'url' => $this->getCreationData( 'image' ),
			'created_by' => $this->getCreationData( 'creatorName' ),
		] );
	}

	/**
	 * Helper method which sends request to maps service to create a map from existing tiles' set
	 * and processes the response providing results array
	 *
	 * @return Array
	 */
	private function createMapFromTilesetId() {
		$response = $this->mapsModel->saveMap( [
			'title' => $this->getCreationData( 'title' ),
			'tile_set_id' => $this->getCreationData( 'tileSetId' ),
			'city_id' => $this->getCreationData( 'cityId' ),
			'created_by' => $this->getCreationData( 'creatorName' ),
		] );

		if ( true === $response[ 'success' ] ) {
			$response[ 'content' ]->mapUrl = Title::newFromText(
				self::PAGE_NAME . '/' . $response[ 'content' ]->id,
				NS_SPECIAL
			)->getFullUrl();

			// Log new map created
			WikiaMapsLogger::addLogEntry(
				WikiaMapsLogger::ACTION_CREATE_MAP,
				$mapId,
				$this->getCreationData( 'title' )
			);
		}

		return $response;
	}

	/**
	 * @desc Obtains a url to a special page with a given path
	 * @param string $name - name of the special page
	 * @return string
	 */
	function getSpecialUrl( $name ) {
		return Title::newFromText( $name, NS_SPECIAL )->getFullUrl();
	}

	/**
	 * Getter for creation data
	 *
	 * @param String $name name of the data key
	 * @param Bool $default
	 *
	 * @return Mixed
	 */
	private function getCreationData( $name, $default = false ) {
		if ( isset( $this->creationData[ $name ] ) ) {
			return $this->creationData[ $name ];
		}

		return $default;
	}

	/**
	 * Setter of creation data
	 *
	 * @param String $name
	 * @param Mixed $value
	 */
	public function setCreationData( $name, $value ) {
		$this->creationData[ $name ] = $value;
	}

	/**
	 * Entry point to create pin types
	 *
	 * @requestParam Integer $mapId an unique map id
	 * @requestParam Array $pinTypeNames an array of pin types names
	 *
	 * @throws PermissionsException
	 * @throws BadRequestApiException
	 */
	public function createPinTypes() {
		$this->setCreationData( 'mapId', $this->request->getInt( 'mapId' ) );
		$this->setCreationData( 'pinTypeNames', $this->request->getArray( 'pinTypeNames' ) );
		$this->setCreationData( 'pinTypeMarkers', $this->request->getArray( 'pinTypeMarkers' ) );

		$this->validatePinTypesCreation();

		$this->setCreationData( 'createdBy', $this->wg->User->getName() );

		$this->createPinTypesFromArray();
		$createdPinTypes = $this->getCreationData( 'createdPinTypes' );

		$this->setVal( 'results', $this->getPinTypesCreationResults(
			count( $this->getCreationData( 'pinTypeNames' ) ),
			$createdPinTypes
		) );
	}

	/**
	 * Validates process of creating pin types
	 *
	 * @throws PermissionsException
	 * @throws BadRequestApiException
	 * @throws InvalidParameterApiException
	 */
	private function validatePinTypesCreation() {
		$mapId = $this->getCreationData( 'mapId' );
		$pinTypesNames = $this->getCreationData( 'pinTypeNames' );

		if ( $mapId === 0 && empty( $pinTypesNames ) ) {
			throw new BadRequestApiException( wfMessage( 'wikia-interactive-maps-create-map-bad-request-error' )->plain() );
		}

		if ( $mapId === 0 ) {
			throw new InvalidParameterApiException( 'mapId' );
		}

		if ( empty( $pinTypesNames ) ) {
			throw new InvalidParameterApiException( 'pinTypeNames' );
		}

		if ( !$this->hasNamesForAllPinTypes() ) {
			throw new InvalidParameterApiException( 'pinTypeNames' );
		}

		if ( !$this->wg->User->isLoggedIn() ) {
			throw new PermissionsException( 'interactive maps' );
		}
	}

	/**
	 * Sends requests to the service to create a pin type. Counts how many requests were successful.
	 */
	private function createPinTypesFromArray() {
		$mapId = $this->getCreationData( 'mapId' );
		$pinTypesNames = $this->getCreationData( 'pinTypeNames' );
		$pinTypesMarkers = $this->getCreationData( 'pinTypeMarkers' );

		$numberOfPinTypes = count( $pinTypesNames );
		$numberOfPinTypesCreated = 0;

		for ( $i = 0; $i < $numberOfPinTypes; $i++ ) {
			$pinTypeData = [
				'map_id' => $mapId,
				'name' => $pinTypesNames[ $i ],
				'created_by' => $this->getCreationData( 'createdBy' ),
			];

			// if user didn't upload marker then this is empty string. we don't want to send it to api
			// when there is more of sanitizing we should abstract it to another function
			if ( !empty( $pinTypesMarkers[ $i ] ) ) {
				$pinTypeData[ 'marker' ] = $pinTypesMarkers[ $i ];
			}

			$response = $this->mapsModel->savePinType( $pinTypeData );

			if( $response['success'] === true ) {
				$numberOfPinTypesCreated++;
			}
		}

		if ( !empty( $logEntries ) ) {
			WikiaMapsLogger::addLogEntries( $logEntries );
		}

		$this->setCreationData( 'createdPinTypes', $numberOfPinTypesCreated );
	}

	/**
	 * Returns result of creating pin types
	 *
	 * @param integer $requestedCreations how many pin types were supposed to be created
	 * @param integer $createdPinTypes how many pin types were created
	 *
	 * @return Array
	 */
	private function getPinTypesCreationResults( $requestedCreations, $createdPinTypes ) {
		$response[ 'success' ] = true;

		if( $createdPinTypes !== $requestedCreations ) {
			$response[ 'success' ] = false;
			$response[ 'content' ] = new stdClass();
			$response[ 'message' ] = wfMessage(
				'wikia-interactive-maps-create-pin-types-error',
				$createdPinTypes,
				$requestedCreations
			)->plain();
		}

		return $response;
	}

	/**
	 * Iterates through all pin types and returns true if all of them have name, false otherwise
	 *
	 * @return bool
	 */
	public function hasNamesForAllPinTypes() {
		$pinTypesNames = $this->getCreationData( 'pinTypeNames' );

		foreach ( $pinTypesNames as $name ) {
			$name = trim( $name );
			if ( empty( $name ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Creates stashed image's thumb url and returns it
	 *
	 * @param File $file stashed upload file
	 * @param Integer $width width of the thumbnail
	 *
	 * @return String
	 */
	private function getStashedImageThumb( $file, $width ) {
		return wfReplaceImageServer( $file->getThumbUrl( $width . "px-" . $file->getName() ) );
	}

	/**
	 * @desc Ajax method for deleting a map from IntMaps API
	 */
	public function deleteMap() {
		$mapId = $this->request->getVal( 'mapId', 0 );
		$result = false;
		if ( $mapId && $this->wg->User->isLoggedIn() ) {
			$result = $this->mapsModel->deleteMapById( $mapId )[ 'success' ];
		}
		if ( $result ) {
			WikiaMapsLogger::addLogEntry(
				WikiaMapsLogger::ACTION_DELETE_MAP,
				$mapId,
				$mapId
			);

			NotificationsController::addConfirmation( wfMessage( 'wikia-interactive-maps-delete-map-success' ) );
			$this->response->setVal(
				'redirectUrl',
				$this->getSpecialUrl( 'InteractiveMaps' )
			);
		}
	}
	
	/**
	 * API Error page
	 */
	public function error() {
		$this->setVal( 'messages', [
			'wikia-interactive-maps-service-error' => wfMessage( 'wikia-interactive-maps-service-error' )->parse()
		] );
		$this->response->setTemplateEngine( WikiaResponse::TEMPLATE_ENGINE_MUSTACHE );
	}

	/**
	 * Generates offset value based on current page and items per page
	 *
	 * @param int $currentPage
	 * @param int $itemsPerPage
	 *
	 * @return int mixed
	 */
	private function getPaginationOffset( $currentPage, $itemsPerPage ) {
		return ($currentPage - 1) * $itemsPerPage;
	}

}