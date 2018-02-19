<?php

namespace Symbiote\ElasticSearch;

use ArrayObject;
use nglasl\extensible\CustomSearchEngine;
use Psr\Log\LoggerInterface;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Core\Config\Config;
use SilverStripe\ORM\ArrayList;
use SilverStripe\ORM\PaginatedList;
use SilverStripe\Security\Permission;
use function singleton;

/**
 * @author marcus
 */
class ElasticaSearchEngine extends CustomSearchEngine
{
    /**
     *
     * @var ExtensibleElasticService
     */
    public $searchService;

    /**
     * Current result set
     * 
     * @var ArrayList
     */
    protected $currentResults;

    /**
     * URL param for current search string
     *
     * @var string
     */
    public static $filter_param = 'filter';

    /**
     *
     * @var LoggerInterface
     */
    public $logger;

    
    public function setElasticaSearchService($v)
    {
        if ($v instanceof ExtensibleElasticService) {
            $this->searchService = $v;
        }
    }

    public function getSelectableFields($page = null)
    {
        $listType = $this->searchableTypes($page);

        $allFields = array();
        foreach ($listType as $classType) {
            if (class_exists($classType)) {
                $item      = singleton($classType);
                $fields    = $item->getElasticaFields();
                $allFields = array_merge($allFields, $fields instanceof ArrayObject ? $fields->getArrayCopy() : $fields);
            }
        }

        $allFields = array_keys($allFields);
        $allFields = array_combine($allFields, $allFields);

        $allFields['_score'] = 'Score';

        ksort($allFields);
        return $allFields;
    }

    public function searchableTypes($page, $default = null)
    {
        $listType = $page->SearchType ? $page->SearchType->getValues() : [$default];
        if (count($listType) === 0) {
            $listType = $default ? array($default) : [];
        }
        return $listType;
    }

    public function getSearchResults($data = null, $form = null, $page = null)
    {
        if ($this->currentResults) {
            return $this->currentResults;
        }

        $query   = null;
        $builder = $this->searchService->getQueryBuilder($page->QueryType);
        if (isset($data['Search']) && strlen($data['Search'])) {
            $query = $data['Search'];
            // lets convert it to a base solr query
            $builder->baseQuery($query);
        }

        if ($page->StartWithListing) {
            $builder->setAllowEmpty(true);
        }

        if ($page->Fuzziness) {
            $builder->setFuzziness($page->Fuzziness);
        }

        $sortBy  = isset($data['SortBy']) ? $data['SortBy'] : $page->SortBy;
        $sortDir = isset($data['SortDirection']) ? $data['SortDirection'] : $page->SortDirection;
        $types   = $this->searchableTypes($page);
        // allow user to specify specific type
        if (isset($_GET['SearchType'])) {
            $fixedType = $_GET['SearchType'];
            if (in_array($fixedType, $types)) {
                $types = array($fixedType);
            }
        }
        // (strlen($this->SearchType) ? $this->SearchType : null);
        $fields = $this->getSelectableFields($page);
        // if we've explicitly set a sort by, then we want to make sure we have a type
        // so we can resolve what the field name in solr is. Otherwise we don't care about type
        // overly much
        if (!count($types) && $sortBy) {
            // default to page
            $types = Config::inst()->get(__CLASS__, 'default_searchable_types');
        }
        if (!isset($fields[$sortBy])) {
            $sortBy = 'score';
        }
        $activeFacets = $page->getActiveFacets();
        if (count($activeFacets)) {
            foreach ($activeFacets as $facetName => $facetValues) {
                foreach ($facetValues as $value) {
                    $builder->addFilter($facetName, $value);
                }
            }
        }
        $offset = isset($_GET['start']) ? $_GET['start'] : 0;
        $limit  = isset($_GET['limit']) ? $_GET['limit'] : ($page->ResultsPerPage ? $page->ResultsPerPage : 10);
        // Apply any hierarchy filters.
        if (count($types)) {
            $sortBy         = $this->searchService->getSortFieldName($sortBy, $types);
            $hierarchyTypes = array();
            $parents        = $page->SearchTrees()->count() ? implode(' OR ParentsHierarchy:',
                    $page->SearchTrees()->column('ID')) : null;
            foreach ($types as $type) {
                // Search against site tree elements with parent hierarchy restriction.
                if ($parents && (ClassInfo::baseDataClass($type) === 'SiteTree')) {
                    $hierarchyTypes[] = "{$type} AND (ParentsHierarchy:{$parents}))";
                }
                // Search against other data objects without parent hierarchy restriction.
                else {
                    $hierarchyTypes[] = "{$type})";
                }
            }
            $builder->addFilter('(ClassNameHierarchy', implode(' OR (ClassNameHierarchy:', $hierarchyTypes));
        }
        if (!$sortBy) {
            $sortBy = 'score';
        }
        $sortDir        = in_array($sortDir, array('ASC', 'asc', 'Ascending')) ? 'ASC' : 'DESC';
        $builder->sortBy($sortBy, $sortDir);
        $selectedFields = $page->SearchOnFields->getValues();
        $extraFields    = $page->ExtraSearchFields->getValues();

        // the following serves two purposes; filter out the searched on fields to only those that
        // are in the actually  searched on types, and to map them to relevant solr types
        if (count($selectedFields)) {
            $mappedFields = array();
            foreach ($selectedFields as $field) {
                $mappedField = $this->searchService->getIndexFieldName($field, $types);
                // some fields that we're searching on don't exist in the types that the user has selected
                // to search within
                if ($mappedField) {
                    $mappedFields[] = $mappedField;
                }
            }
            if ($extraFields && count($extraFields)) {
                $mappedFields = array_merge($mappedFields, $extraFields);
            }

            $builder->queryFields($mappedFields);
        }
        if ($boost = $page->BoostFields->getValues()) {
            $boostSetting = array();
            foreach ($boost as $field => $amount) {
                if ($amount > 0) {
                    $boostSetting[$this->searchService->getIndexFieldName($field, $types)] = $amount;
                }
            }
            $builder->boost($boostSetting);
        }
        if ($boost = $page->BoostMatchFields->getValues()) {
            if (count($boost)) {
                $builder->boostFieldValues($boost);
            }
        }
        if ($filters = $page->FilterFields->getValues()) {
            if (count($filters)) {
                foreach ($filters as $filter => $val) {
                    $builder->addFilter($filter, $val);
                }
            }
        }

        $page->extend('updateQueryBuilder', $builder, $page);
        $resultSet = $this->searchService->query($builder, $offset, $limit);
        /* @var $resultSet \Heyday\Elastica\ResultList */

        $results = PaginatedList::create($resultSet->toArrayList());
        $results->setPageLength($limit);
        $results->setPageStart($offset);
        $results->setTotalItems($resultSet->totalItems());

        $results = ['Results' => $results];

        $this->currentResults = $results;

        if (isset($_GET['debug']) && Permission::check('ADMIN')) {
            $o = $resultSet->getQuery()->toArray();
            echo json_encode($o);
        }
        return $this->currentResults;
    }
}
