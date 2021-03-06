<?php
class WikiaMapsParserTagController extends WikiaController {

	//TODO: figure out max and min height and width - PO design decision
	const DEFAULT_ZOOM = 7;
	const MIN_ZOOM = 0;
	const MAX_ZOOM = 16;
	const DEFAULT_WIDTH = 680;
	const DEFAULT_HEIGHT = 382;
	const DEFAULT_LATITUDE = 0;
	const MIN_LATITUDE = -90;
	const MAX_LATITUDE = 90;
	const DEFAULT_LONGITUDE = 0;
	const MIN_LONGITUDE = -180;
	const MAX_LONGITUDE = 180;
	const PARSER_TAG_NAME = 'imap';
	const RENDER_ENTRY_POINT = 'render';

	private $mapsModel;

	public function __construct() {
		parent::__construct();
		$this->mapsModel = new WikiaMaps( $this->wg->IntMapConfig );
	}

	/**
	 * @desc Parser hook: used to register parser tag in MW
	 *
	 * @param Parser $parser
	 * @return bool
	 */
	public static function parserTagInit( Parser $parser ) {
		$parser->setHook( self::PARSER_TAG_NAME, [ new static(), 'renderPlaceholder' ] );
		return true;
	}

	/**
	 * @desc Based on parser tag arguments validation parsers an error or placeholder
	 *
	 * @param String $input
	 * @param Array $args
	 * @param Parser $parser
	 * @param PPFrame $frame
	 *
	 * @return String
	 */
	public function renderPlaceholder( $input, Array $args, Parser $parser, PPFrame $frame ) {
		// register resource loader module dependencies for map parser tag
		// done separately for CSS and JS, so CSS will go to top of the page
		$parser->getOutput()->addModuleStyles( 'ext.wikia.WikiaMaps.ParserTag' );
		$parser->getOutput()->addModuleScripts( 'ext.wikia.WikiaMaps.ParserTag' );

		$errorMessage = '';
		$params = $this->sanitizeParserTagArguments( $args );
		$isValid = $this->validateParseTagParams( $params, $errorMessage );

		if ( $isValid ) {
			$params[ 'map' ] = $this->getMapObj( $params[ 'id' ] );

			if ( !empty( $params [ 'map' ]->id ) ) {
				return $this->sendRequest(
					'WikiaMapsParserTagController',
					'mapThumbnail',
					$params
				);
			} else {
				$errorMessage = wfMessage( 'wikia-interactive-maps-parser-tag-error-no-map-found' )->plain();
			}
		}

		return $this->sendRequest(
			'WikiaMapsParserTagController',
			'parserTagError',
			[ 'errorMessage' => $errorMessage ]
		);
	}

	/**
	 * @desc Displays parser tag error
	 *
	 * @return string
	 */
	public function parserTagError() {
		$this->setVal( 'errorMessage', $this->getVal( 'errorMessage' ) );
		$this->response->setTemplateEngine( WikiaResponse::TEMPLATE_ENGINE_MUSTACHE );
	}

	/**
	 * @desc Renders Wikia Maps placeholder
	 *
	 * @return null|string
	 */
	public function mapThumbnail() {
		$params = $this->getMapPlaceholderParams();
		$userName = $params->map->created_by;
		$isMobile = $this->app->checkskin( 'wikiamobile' );

		if ( $isMobile ) {
			//proper image is lazy loaded from the thumbnailer
			$params->map->imagePlaceholder = $this->wg->BlankImgUrl;
			$params->map->mobile = true;
			$params->map->href =
				WikiaMapsSpecialController::getSpecialUrl() . '/' . $params->map->id;
		} else {
			$params->map->image = $this->mapsModel->createCroppedThumb( $params->map->image, self::DEFAULT_WIDTH, self::DEFAULT_HEIGHT );
		}

		$renderParams = $this->mapsModel->getMapRenderParams( $params->map->city_id );

		$params->map->url = $this->mapsModel->getMapRenderUrl( [
			$params->map->id,
			$params->zoom,
			$params->lat,
			$params->lon,
		], $renderParams );

		$this->setVal( 'map', (object) $params->map );
		$this->setVal( 'params', $params );
		$this->setVal( 'created_by', wfMessage( 'wikia-interactive-maps-parser-tag-created-by', $userName )->text() );
		$this->setVal( 'avatarUrl', AvatarService::getAvatarUrl( $userName, AvatarService::AVATAR_SIZE_SMALL ) );
		$this->setVal( 'view', wfMessage( 'wikia-interactive-maps-parser-tag-view' )->plain() );

		$this->response->setTemplateEngine( WikiaResponse::TEMPLATE_ENGINE_MUSTACHE );

		if ( $isMobile ) {
			$this->overrideTemplate( 'mapThumbnail_mobile' );
		}
	}

	/**
	 * @desc Get map object from API
	 *
	 * @param integer $mapId - map object id
	 *
	 * @return object - map object
	 */
	private function getMapObj( $mapId ) {
		return $this->mapsModel->getMapByIdFromApi( $mapId );
	}

	/**
	 * @desc Gets map placeholder params from request
	 *
	 * @return stdClass
	 */
	private function getMapPlaceholderParams() {
		$params = [];

		$params[ 'lat' ] = $this->request->getVal( 'lat', self::DEFAULT_LATITUDE );
		$params[ 'lon' ] = $this->request->getVal( 'lon', self::DEFAULT_LONGITUDE );
		$params[ 'zoom' ] = $this->request->getInt( 'zoom', self::DEFAULT_ZOOM );
		$params[ 'width' ] = self::DEFAULT_WIDTH;
		$params[ 'height' ] = self::DEFAULT_HEIGHT;
		$params[ 'map' ] = $this->request->getVal( 'map' );
		$params[ 'created_by' ] = $this->request->getVal( 'created_by' );
		$params[ 'avatarUrl' ] = $this->request->getVal( 'avatarUrl' );

		$params[ 'width' ] .= 'px';
		$params[ 'height' ] .= 'px';

		return (object) $params;
	}

	/**
	 * @desc Sanitizes given data
	 *
	 * @param Array $data
	 *
	 * @return Array
	 */
	public function sanitizeParserTagArguments( $data ) {
		$result = [];
		$validParams = [
			'map-id' => 'id',
			'lat' => 'lat',
			'lon' => 'lon',
			'zoom' => 'zoom',
		];

		foreach( $validParams as $key => $mapTo ) {
			if ( !empty( $data[ $key ] ) ) {
				$result[ $mapTo ] = $data[ $key ];
			}
		}

		return $result;
	}

	/**
	 * @desc Validates data provided in parser tag arguments, returns empty string if there is no error
	 *
	 * @param Array $params an array with parser tag arguments
	 * @param String $errorMessage a variable where the error message will be assigned to
	 *
	 * @return String
	 */
	public function validateParseTagParams( Array $params, &$errorMessage ) {
		$isValid = false;

		if( empty( $params ) ) {
			$errorMessage = wfMessage( 'wikia-interactive-maps-parser-tag-error-no-require-parameters' )->plain();
			return $isValid;
		}

		foreach( $params as $param => $value ) {
			$isValid = $this->isTagParamValid( $param, $value, $errorMessage );

			if( !$isValid ) {
				return $isValid;
			}
		}

		return $isValid;
	}

	/**
	 * @desc Checks if parameter from parameters array is valid
	 *
	 * @param String $paramName name of parameter which should get validated
	 * @param String|Mixed $paramValue value of the parameter
	 * @param String $errorMessage reference to a string variable which will get error message if one occurs
	 *
	 * @return bool
	 */
	private function isTagParamValid( $paramName, $paramValue, &$errorMessage ) {
		$isValid = false;

		$validator = $this->buildParamValidator( $paramName );
		if( $validator ) {
			$isValid = $validator->isValid( $paramValue );

			if( !$isValid ) {
				$errorMessage = $validator->getError()->getMsg();
			}
		}

		return $isValid;
	}

	/**
	 * @desc Small factory method to create validators for params; returns false if validator can't be created
	 *
	 * @param String $paramName
	 * @return bool|WikiaValidator
	 */
	private function buildParamValidator( $paramName ) {
		$validator = false;

		switch( $paramName ) {
			case 'id':
				$validator = new WikiaValidatorInteger(
					[ 'required' => true ],
					[ 'not_int' => 'wikia-interactive-maps-parser-tag-error-invalid-map-id' ]
				);
				break;
			case 'lat':
				$validator = new WikiaValidatorNumeric(
					[
						'min' => self::MIN_LATITUDE,
						'max' => self::MAX_LATITUDE

					],
					[
						'not_numeric' => 'wikia-interactive-maps-parser-tag-error-invalid-latitude',
						'too_small' => 'wikia-interactive-maps-parser-tag-error-min-latitude',
						'too_big' => 'wikia-interactive-maps-parser-tag-error-max-latitude'
					]
				);
				break;
			case 'lon':
				$validator = new WikiaValidatorNumeric(
					[
						'min' => self::MIN_LONGITUDE,
						'max' => self::MAX_LONGITUDE

					],
					[
						'not_numeric' => 'wikia-interactive-maps-parser-tag-error-invalid-longitude',
						'too_small' => 'wikia-interactive-maps-parser-tag-error-min-longitude',
						'too_big' => 'wikia-interactive-maps-parser-tag-error-max-longitude'
					]
				);
				break;
			case 'zoom':
				$validator = new WikiaValidatorInteger(
					[
						'min' => self::MIN_ZOOM,
						'max' => self::MAX_ZOOM

					],
					[
						'not_int' => 'wikia-interactive-maps-parser-tag-error-invalid-zoom',
						'too_small' => 'wikia-interactive-maps-parser-tag-error-min-zoom',
						'too_big' => 'wikia-interactive-maps-parser-tag-error-max-zoom'
					]
				);
				break;
		}

		return $validator;
	}

	/**
	 * @desc Ajax method for lazy-loading map thumbnails
	 */
	public function getMobileThumbnail() {
		$width = $this->getVal( 'width' );
		//To keep the original aspect ratio
		$height = floor( $width * self::DEFAULT_HEIGHT / self::DEFAULT_WIDTH );
		$image = $this->getVal( 'image' );
		$this->setVal( 'src', $this->mapsModel->createCroppedThumb( $image, $width, $height ) );
	}

}
