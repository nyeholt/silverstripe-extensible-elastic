<?php

namespace Symbiote\ElasticSearch;

use Elastica\Client;
use Heyday\Elastica\ElasticaService;
use SilverStripe\Core\Injector\Injector;
use Symbiote\ElasticSearch\ElasticaQueryBuilder;
use Psr\Log\LoggerInterface;


/**
 * @author marcus
 */
class ExtensibleElasticService extends ElasticaService {
    
    /**
	 * A mapping of all the available query builders
	 *
	 * @var map
	 */
	protected $queryBuilders = array();

    /**
     *
     * @var LoggerInterface
     */
    public $logger;

    
    public function __construct(Client $client, $index) {
        parent::__construct($client, $index);
        $this->queryBuilders['default'] = ElasticaQueryBuilder::class;
    }

    /**
     * Queries the elastic index using an elastic query, mapped as an array
     *
     * @param ElasticaQueryBuilder|string $query
     * @param int $offset
     * @param int $limit
     * @param string $resultClass
     * @return Heyday\Elastica\ResultList
     */
    public function query($query, $offset = 0, $limit = 20, $resultClass = '') {
        // check for _old_ param structure
        if (!$resultClass ||
            is_array($resultClass) || 
            is_string($resultClass) && !class_exists($resultClass)) {
            $resultClass = 'Heyday\Elastica\ResultList';
        }

        if ($query instanceof ElasticaQueryBuilder) {
            $elasticQuery = $query->toQuery();
        } else {
            $elasticQuery = $query;
        }
        
        $results = Injector::inst()->create($resultClass, $this->getIndex(), $elasticQuery, $this->logger);
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
	 * @return ElasticaQueryBuilder
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
