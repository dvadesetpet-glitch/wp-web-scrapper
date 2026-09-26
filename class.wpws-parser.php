<?php

require_once __DIR__ . '/vendor/autoload.php';
use Symfony\Component\CssSelector\CssSelectorConverter;

class WP_Web_Scraper_Parser {
	
	public $html;
	public $charset;
	public $error;
	public $result;
	public $count;
	public $selector;
	public $xpath;
    public $regex;
	
	public function __construct( $html, $charset = 'UTF-8' ){
		
		$this->html = $html;
		$this->charset = $charset;
		$this->error = null;
		$this->result = null;
		$this->count = 0;
		$this->selector = null;
		$this->xpath = null;
        $this->regex = null;
		
	}
	
	/**
	 * Convert a CSS selector to XPath (HTML mode: case-insensitive tag names).
	 * Converter is reused — building it is the expensive part.
	 *
	 * @param string $selector CSS selector.
	 * @return string XPath expression.
	 */
	public static function css_to_xpath( $selector ) {
		static $converter = null;
		if ( $converter === null ) {
			$converter = new CssSelectorConverter( true );
		}
		return $converter->toXPath( $selector );
	}

	public function parse_selector( $selector ){
		
		$this->selector = $selector;
		try {
			$libxml_previous_state = libxml_use_internal_errors(true);
			$this->xpath = self::css_to_xpath( $selector );
			libxml_clear_errors();
			libxml_use_internal_errors($libxml_previous_state);
		} catch (\Throwable $e) {
			$this->error = 'Invalid CSS selector';
		}
		
		if($this->error === null)
			return $this->parse_xpath( $this->xpath );
			
		return $this->result;
		
	}

	public function parse_xpath( $xpath ){
		
		$this->xpath = $xpath;
		$doc = new DOMDocument();
		
		// Suppress warnings for malformed HTML, but log errors in debug mode
		$libxml_previous_state = libxml_use_internal_errors(true);
		$doc->loadHTML('<?xml encoding="'.$this->charset.'" ?>'.$this->html, LIBXML_NONET);
		libxml_clear_errors();
		libxml_use_internal_errors($libxml_previous_state);
		
		$xpath_obj = new DomXPath($doc);
		$elements = $xpath_obj->query($this->xpath);
		
		$elements_html = array();
		
		if(is_object($elements)){
			foreach ($elements as $element)
				$elements_html[] = trim($doc->saveHTML($element));
            if( !empty($elements_html) ){
                $this->result = $elements_html;
                $this->count = $elements->length;
            } else {
                $this->error = 'Query returned empty response';
            }
		} elseif($elements === false){
			$this->error = 'Invalid XPath expression';
		}
		
		return $this->result;

	}
    
    public function parse_regex( $regex ){
      
        $this->regex = $regex;
        $preg_matches = preg_match_all($this->regex, $this->html, $elements, PREG_SET_ORDER);
        
        $elements_html = array();
        
		if($preg_matches !== false && is_array($elements)){
			foreach ($elements as $element)
				$elements_html[] = trim($element[0]);
            if( !empty($elements_html) ){
                $this->result = $elements_html;
                $this->count = count($elements);
            } else {
                $this->error = 'Query returned empty response';
            }
		} elseif($preg_matches === false){
			$this->error = 'Invalid PREG pattern';
		}
		
		return $this->result;        
        
    }
    
	public function replace_selector( $selector, $with ){
		
		$this->selector = $selector;
		try {
			$libxml_previous_state = libxml_use_internal_errors(true);
			$this->xpath = self::css_to_xpath( $selector );
			libxml_clear_errors();
			libxml_use_internal_errors($libxml_previous_state);
		} catch (\Throwable $e) {
            $this->xpath = null;
		}
		
		return $this->replace_xpath( $this->xpath, $with );
		
	}    
    
    public function replace_xpath( $xpath, $with = '' ){
        
        $this->xpath = $xpath;
        $doc = new DOMDocument();
        
        // Suppress warnings for malformed HTML
        $libxml_previous_state = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="'.$this->charset.'" ?>'.$this->html, LIBXML_NONET);
		libxml_clear_errors();
		libxml_use_internal_errors($libxml_previous_state);
		
        $xpath_obj = new DomXPath($doc);
        $elements = $xpath_obj->query($this->xpath);
        
        $elements_remove = array();
        $elements_replace = array();
        
        if(is_object($elements)){
            if($with === ''){
                foreach ($elements as $element)
                    $elements_remove[] = $element;
                foreach( $elements_remove as $element_remove )
                    $element_remove->parentNode->removeChild($element_remove);
            } else {
                foreach ($elements as $element)
                    $elements_replace[] = $element;
                foreach( $elements_replace as $element_replace ){
                    // Build a fresh fragment per node; a fragment is emptied once
                    // inserted, so it cannot be reused across iterations.
                    $with_element = $doc->createDocumentFragment();
                    if ( @$with_element->appendXML($with) === false )
                        continue; // invalid replacement markup; leave node untouched
                    $element_replace->parentNode->replaceChild($with_element, $element_replace);
                }
            }
        }
        
        return str_replace(array('<body>','</body>'), '', trim($doc->saveHTML($doc->getElementsByTagName('body')->item(0))));
        
    } 
    
    public function basehref($base){
        
        require_once __DIR__ . '/vendor/phpuri/phpuri.php';
        $doc = new DOMDocument();
        
        // Suppress warnings for malformed HTML
        $libxml_previous_state = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="'.$this->charset.'" ?>'.$this->html, LIBXML_NONET);
		libxml_clear_errors();
		libxml_use_internal_errors($libxml_previous_state);   
        
        foreach ($doc->getElementsByTagName('*') as $item){
            if($item->getAttribute('href') != '')
                $item->setAttribute('href', phpUri::parse($base)->join($item->getAttribute('href')));
            if($item->getAttribute('src') != '')
                $item->setAttribute('src', phpUri::parse($base)->join($item->getAttribute('src')));
        }
        
        return str_replace(array('<body>','</body>'), '', trim($doc->saveHTML($doc->getElementsByTagName('body')->item(0))));     
        
    }
    
    public function a_target($target){
        
        $doc = new DOMDocument();
        
        // Suppress warnings for malformed HTML
        $libxml_previous_state = libxml_use_internal_errors(true);
        $doc->loadHTML('<?xml encoding="'.$this->charset.'" ?>'.$this->html, LIBXML_NONET);
		libxml_clear_errors();
		libxml_use_internal_errors($libxml_previous_state);   
        
        foreach ($doc->getElementsByTagName('a') as $item)
            $item->setAttribute('target', $target);
        
        return str_replace(array('<body>','</body>'), '', trim($doc->saveHTML($doc->getElementsByTagName('body')->item(0))));      
        
    }    
    
	/**
	 * Parse a dot-notation JSONPath expression against $this->html (must be valid JSON).
	 * Supports: named keys, numeric indices (negative OK), wildcard *.
	 *
	 * @param string $path Dot-notation path, e.g. "$.items.*.title" or "items.0.name".
	 * @return array|null Result array of strings, or null on error.
	 */
	public function parse_jsonpath( $path ) {
		$data = json_decode( $this->html, true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			$this->error = 'Invalid JSON: ' . json_last_error_msg();
			return null;
		}

		$path = trim( $path );
		// Root-only: return entire document as one result
		if ( $path === '' || $path === '$' || $path === '.' ) {
			$val          = is_array( $data ) ? json_encode( $data, JSON_UNESCAPED_UNICODE ) : (string) $data;
			$this->result = array( $val );
			$this->count  = 1;
			return $this->result;
		}

		// Strip leading $. or $
		$path     = preg_replace( '/^\$\.?/', '', $path );
		$segments = array_values( array_filter( explode( '.', $path ), 'strlen' ) );
		$results  = self::_jsonpath_traverse( $data, $segments );

		if ( empty( $results ) ) {
			$this->error = 'Query returned empty response';
			return null;
		}

		$this->result = array_map( function( $v ) {
			return is_array( $v ) ? json_encode( $v, JSON_UNESCAPED_UNICODE ) : (string) $v;
		}, $results );
		$this->count = count( $this->result );
		return $this->result;
	}

	private static function _jsonpath_traverse( $data, $segments ) {
		if ( empty( $segments ) ) {
			return array( $data );
		}

		$seg      = array_shift( $segments );
		$results  = array();

		// Wildcard: iterate every element
		if ( $seg === '*' ) {
			if ( ! is_array( $data ) ) return array();
			foreach ( $data as $item ) {
				$results = array_merge( $results, self::_jsonpath_traverse( $item, $segments ) );
			}
			return $results;
		}

		// Numeric index (supports negative)
		if ( is_array( $data ) && preg_match( '/^-?\d+$/', $seg ) ) {
			$idx  = (int) $seg;
			$keys = array_keys( $data );
			if ( $idx < 0 ) $idx = count( $keys ) + $idx;
			if ( ! isset( $keys[ $idx ] ) ) return array();
			return self::_jsonpath_traverse( $data[ $keys[ $idx ] ], $segments );
		}

		// Named key
		if ( ! is_array( $data ) || ! array_key_exists( $seg, $data ) ) return array();
		return self::_jsonpath_traverse( $data[ $seg ], $segments );
	}

}