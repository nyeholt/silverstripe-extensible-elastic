<?php

use Symbiote\Elastica\ResultList;

/**
 * @author marcus
 */
class ExtensibleElasticService extends Symbiote\Elastica\ElasticaService {
    
    /**
	 * A mapping of all the available query builders
	 *
	 * @var map
	 */
	protected $queryBuilders = array();

    
    public function __construct(\Elastica\Client $client, $index) {
        parent::__construct($client, $index);
        
        $this->queryBuilders['default'] = 'ElasticaQueryBuilder';
    }

    public function query($query, $offset = 0, $limit = 20, $params = array(), $andWith = array()) {
        if ($query instanceof ElasticaQueryBuilder) {
            $elasticQuery = $query->toQuery();
        } else {
            $elasticQuery = $query;
        }
        
        $results = new ResultList($this->getIndex(), $elasticQuery);

		// The result list needs to be limited so the pagination is looking at the correct page.

		$results = $results->limit((int)$limit, (int)$offset);
        return $results;
        
    }

    public function isConnected() {
        return true;
    }
    
    /**
	 * Gets the list of query parsers available
	 *
	 * @return array
	 */
	public function getQueryBuilders() {
		return $this->queryBuilders;
	}

	/**
	 * Gets the query builder for the given search type
	 *
	 * @param string $type 
	 * @return SolrQueryBuilder
	 */
	public function getQueryBuilder($type='default') {
		return isset($this->queryBuilders[$type]) ? Injector::inst()->create($this->queryBuilders[$type]) : Injector::inst()->create($this->queryBuilders['default']);
	}
    
    /////////
    // Solr search compatibility layer
    /////////

    /**
     * Get all fields the particular class type can be searched on
     * @param string $listType
     */
    public function getAllSearchableFieldsFor($listType) {
        
    }
    
    public function getIndexFieldName($field, $classNames = array('Page')) {
		return $field;
	}
    
    public function getSortFieldName($sortBy, $types) {
        return $sortBy;
    }

}
