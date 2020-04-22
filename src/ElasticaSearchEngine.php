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
use SilverStripe\Control\HTTP;
use SilverStripe\View\ArrayData;

use Elastica\Query\QueryString;
use SilverStripe\CMS\Model\SiteTree;
use SilverStripe\Control\Director;
use Elastica\Query\BoolQuery;
use Elastica\Query\Term;
use Elastica\ResultSet;
use Exception;
use Heyday\Elastica\ResultList;
use SilverStripe\ORM\FieldType\DBVarchar;
use SilverStripe\Control\HTTPRequest;

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
     * The raw result list from elastic
     *
     * @var ResultSet
     */
    protected $elasticResult;

    /**
     * URL param for current search string
     *
     * @var string
     */
    public static $filter_param = 'filter';

    /**
     * Yes, yes we do support hierarchical searches
     *
     * @var boolean
     */
    public $supports_hierarchy = true;

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
                $item = \singleton($classType);
                $fields = $item->getElasticaFields();
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

    /**
     * @param array $data Variables to be used for search params
     * @param HttpRequest | Form $form
     *              The form or request object that triggered the seach
     * @param ArPage $page
     *              The search page with configuration for the search
     */
    public function getSearchResults($data = null, $form = null, $page = null)
    {
        if ($this->currentResults) {
            return $this->currentResults;
        }

        $request = $form instanceof HTTPRequest ? $form : $form->getController()->getRequest();

        foreach (['SortBy', 'SortDirection', 'SearchType', 'start', 'limit', 'UserFilter', 'aggregation'] as $reqVar) {
            if (!isset($data[$reqVar])) {
                $data[$reqVar] = $request->getVar($reqVar);
            }
        }

        $query = null;
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

        $sortBy = isset($data['SortBy']) ? $data['SortBy'] : $page->SortBy;
        $sortDir = isset($data['SortDirection']) ? $data['SortDirection'] : $page->SortDirection;
        $types = $this->searchableTypes($page);
        // allow user to specify specific type
        if (isset($data['SearchType'])) {
            $fixedType = $data['SearchType'];
            if (in_array($fixedType, $types)) {
                $types = array($fixedType);
            }
        }
        // (strlen($this->SearchType) ? $this->SearchType : null);
        $fields = $page->getSelectableFields();
        // if we've explicitly set a sort by, then we want to make sure we have a type
        // so we can resolve what the field name in elastic. Otherwise we don't care about type
        // overly much
        if (!count($types) && $sortBy) {
            // default to page
            $types = Config::inst()->get(ElasticaSearch::class, 'additional_search_types');
        }
        if (!isset($fields[$sortBy])) {
            $sortBy = '_score';
        }

        $offset = (int) isset($data['start']) ? $data['start'] : 0;
        $limit = (int) isset($data['limit']) ? $data['limit'] : ($page->ResultsPerPage ? $page->ResultsPerPage : 10);
        // Apply any hierarchy filters.
        if (count($types)) {
            $sortBy = $this->searchService->getSortFieldName($sortBy, $types);
            $hierarchyTypes = array();
            $parents = $page->SearchTrees()->count() ? implode(
                ' OR ParentsHierarchy:',
                $page->SearchTrees()->column('ID')
            ) : null;

            foreach ($types as $type) {
                $convertedType = str_replace('\\', "_", $type);
                // Search against site tree elements with parent hierarchy restriction.
                if ($parents && (ClassInfo::baseDataClass($type) === SiteTree::class)) {
                    $hierarchyTypes[] = "{$convertedType} AND (ParentsHierarchy:{$parents}))";
                }
                // Search against other data objects without parent hierarchy restriction.
                else {
                    $hierarchyTypes[] = "{$convertedType})";
                }
            }

            $builder->addFilter('ClassNameHierarchy', new QueryString('(ClassNameHierarchy:' . implode(' OR (ClassNameHierarchy:', $hierarchyTypes)));
        }
        if (!$sortBy) {
            $sortBy = 'score';
        }
        $sortDir = in_array($sortDir, array('ASC', 'asc', 'Ascending')) ? 'asc' : 'desc';
        $builder->sortBy($sortBy, $sortDir);
        $selectedFields = $page->SearchOnFields->getValues();
        $extraFields = $page->ExtraSearchFields->getValues();

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

        if ($page->ContentMatchBoost) {
            $builder->setContentBoost($page->ContentMatchBoost);
        }

        if ($filters = $page->FilterFields->getValues()) {
            if (count($filters)) {
                foreach ($filters as $filter => $val) {
                    $builder->addFilter($filter, $val);
                }
            }
        }

        // Add in any fields we want to facet by in the response set
        $fieldFacets = $page->facetFieldMapping();
        if (count($fieldFacets)) {
            $builder->addFacetFields($fieldFacets, $page->MaxFacetResults ? $page->MaxFacetResults : 20);
        }

        if ($page->ExpandedResultCount) {
            $builder->setExpandFacetResults($page->ExpandedResultCount);
        }

        // and now filter by any applied in the request
        $aggregation = $request->getVar('aggregation');
        $filterMethod = $page->MinFacetCount > 0 ? 'addFilter' : 'addPostFilter';
        if ($aggregation && is_array($aggregation)) {
            foreach ($aggregation as $field => $value) {
                if (!isset($fieldFacets[$field])) {
                    // someone's add a field that shouldn't be filtered on
                    continue;
                }
                if (!$value) {
                    continue;
                }
                if (is_array($value)) {
                    $orFilter = new BoolQuery();
                    foreach ($value as $filterValue) {
                        $filter = new Term([$field => $filterValue]);
                        $orFilter->addShould($filter);
                    }
                    $builder->$filterMethod($field, $orFilter);
                } else {
                    $builder->$filterMethod($field, $value);
                }
            }
        }

        if (isset($data['UserFilter'])) {
            $filters = $page->UserFilters->getValues();
            if (count($filters)) {
                $queries = array_keys($filters);
                foreach ($data['UserFilter'] as $index => $junk) {
                    if (isset($queries[$index])) {
                        $fv = explode(':', $queries[$index]);
                        $builder->addFilter($fv[0], $fv[1]);
                    }
                }
            }
        }

        $page->invokeWithExtensions('updateQueryBuilder', $builder, $page);
        $resultSet = $this->searchService->query($builder, $offset, $limit);
        /* @var $resultSet \Heyday\Elastica\ResultList */

        $results = PaginatedList::create($resultSet->toArrayList())->setLimitItems(false);
        $results->setPageLength($limit);
        $results->setPageStart($offset);

        if (count($resultSet->toArray())) {
            $results->setTotalItems($resultSet->totalItems());
        }

        if (!$resultSet->getResults()) {
            if (isset($_GET['debug']) && Permission::check('ADMIN')) {
                $o = $resultSet->getQuery()->toArray();
                echo json_encode($o);
            }

            throw new \Exception('Search failed');
        }
        $time = $resultSet->getResults()->getTotalTime();
        $results = [
            'Results' => $results,
            'TimeTaken' => $time,
            'Query' => DBVarchar::create_field('Varchar', $query)
        ];

        // determine if we need to stick aggregation output in place for facets in the result set
        // The aggregations.
        $aggregations = ArrayList::create();

        try {
            $this->elasticResult = $resultSet->getResults();

            if (!$this->elasticResult) {
                throw new \RuntimeException("Could not retrieve results from elastic");
            }

            unset($data['url']);
            unset($data['start']);
            unset($data['aggregation']);
            $link = $page->Link('getForm');
            foreach ($data as $var => $value) {
                $link = HTTP::setGetVar($var, $value, $link);
            }

            foreach ($this->elasticResult->getAggregations() as $type => $aggregation) {
                // The groupings for each aggregation.
                $buckets = ArrayList::create();
                if (isset($aggregation['buckets'])) {
                    foreach ($aggregation['buckets'] as $bucket) {
                        $bucket['type'] = isset($fieldFacets[$type]) ? $fieldFacets[$type] : $type;
                        $bucket['field'] = $type;
                        // Determine the redirect to be used when using the facet/aggregation.

                        $bucket['link'] = HTTP::setGetVar('aggregation', array(
                            $type => $bucket['key']
                        ), $link);

                        // The information for each aggregation/grouping.

                        $buckets->push(ArrayData::create(
                            $bucket
                        ));
                    }
                }
                $aggregations->push($buckets);
                $results['Aggregations'] = $aggregations;
            }
        } catch (Exception $ex) {
            error_log($ex->getMessage());
            if (Director::isDev()) {
                throw $ex;
            }
        }

        $this->resultList = $resultSet;
        $this->currentResults = $results;

        if (isset($_GET['debug']) && Permission::check('ADMIN')) {
            $o = $resultSet->getQuery()->toArray();
            echo json_encode($o);
        }
        return $this->currentResults;
    }

    /**
     * Retrieves the current set of results, if they've already been
     * put together by the search form processing.
     *
     * @return array
     */
    public function getCurrentResults()
    {
        return $this->currentResults;
    }

    /**
     * Get the raw elastic result list
     *
     * @return ResultSet
     */
    public function getCurrentElasticResult()
    {
        return $this->elasticResult;
    }
}
