<?php

namespace Symbiote\ElasticSearch;

use Elastica\Query;
use SilverStripe\Versioned\Versioned;

/**
 * @author marcus
 */
class ElasticaQueryBuilder
{
    public $title        = 'Default Elastica';
    public $version      = 2;
    protected $userQuery = '';
    protected $fuzziness  = 0;
    protected $fields    = array('Title', 'Content');
    protected $and       = array();
    protected $params    = array();
    protected $filters   = array();

    /**
     * Should 'emtpy' user queries still generate a result set?
     * @var boolean
     */
    protected $allowEmpty = false;
    /**
     * 	Allow "alpha only sort" fields to be wrapped in wildcard characters when queried against.
     * 	@var boolean
     */
    protected $enableQueryWildcard = true;

    /**
     * an array of field => amount to boost
     * @var array
     */
    protected $boost = array();

    /**
     * Field:value => boost amount
     *
     * @var array
     */
    protected $boostFieldValues = array();
    protected $sort;

    /**
     *
     * @var array
     */
    protected $facets = array('fields' => array(), 'queries' => array());

    /**
     * Per-field facet limits
     *
     * @var array
     */
    protected $facetFieldLimits = array();

    /**
     * Number of facets to return
     *
     * @var int
     */
    protected $facetLimit = 50;

    /**
     * Number of items with faces to be included
     *
     * @var int
     */
    protected $facetCount = 1;

    public function baseQuery($query)
    {
        $this->userQuery = $query;
        return $this;
    }

    public function queryFields($fields)
    {
        $this->fields = $fields;
        return $this;
    }

    /**
     * Retrieve the current set of fields being queried
     *
     * @return array
     */
    public function currentFields()
    {
        return $this->fields;
    }

    public function setFuzziness($f) {
        $this->fuzziness = $f;
        return $this;
    }

    public function setAllowEmpty($v) {
        $this->allowEmpty = $v;
        return $this;
    }

    public function sortBy($field, $direction)
    {
        $this->sort = "$field $direction";
        return $this;
    }

    public function andWith($field, $value)
    {
        $existing = array();
        if (isset($this->and[$field])) {
            $existing = $this->and[$field];
        }

        if (is_array($value)) {
            $existing = $existing + $value;
        } else {
            $existing[] = $value;
        }

        $this->and[$field] = $existing;
        return $this;
    }

    public function setParams($params)
    {
        $this->params = $params;
        return $this;
    }

    public function addFacetFields($fields, $limit = 0)
    {
        $a                      = array_merge($this->facets['fields'], $fields);
        $this->facets['fields'] = array_unique(array_merge($this->facets['fields'], $fields));
        $this->facetLimit       = $limit;
        if ($limit) {
            $this->facetLimit = $limit;
        }
        return $this;
    }

    public function addFacetQueries($queries, $limit = 0)
    {
        $this->facets['queries'] = array_unique(array_merge($this->facets['queries'], $queries));
        if ($limit) {
            $this->facetLimit = $limit;
        }

        return $this;
    }

    public function addFacetFieldLimit($field, $limit)
    {
        $this->facetFieldLimits[$field] = $limit;
    }

    public function getParams()
    {
        if ($this->sort) {
            $this->params['sort'] = $this->sort;
        }

        $this->facetParams();

        return $this->params;
    }

    /**
     * Return the base search term
     * 
     * @return string
     */
    public function getUserQuery()
    {
        return $this->userQuery;
    }

    public function parse($string)
    {
        // custom search query entered
        if (strpos($string, ':') > 0) {
            return $string;
        }

        $sep    = '';
        $lucene = '';
        foreach ($this->fields as $field) {
            $lucene .= $sep.'('.$field.':';

            // Wrap wildcard characters around the individual terms for any "alpha only sort" fields.

            $lucene .= ($this->enableQueryWildcard) ? $this->wildcard($string) : $string;

            $lucene .= ')';
            if (isset($this->boost[$field])) {
                $lucene .= '^'.$this->boost[$field];
            }
            $sep = ' OR ';
        }

        return $lucene;
    }

    /**
     * 	Wrap wildcard characters around individual terms of an input string, useful when dealing with "alpha only sort" fields.
     * 	NOTE: The support for custom query syntax of an input string is currently limited to: * () "" OR || AND && NOT ! + -
     * 	@param string
     * 	@return string
     */
    public function wildcard($string)
    {
        if (!strlen($string)) {
            return $string;
        }
        $wildcard = $this->fuzziness > 0 ? '~' : '*';

        // Appropriately handle the input string if it only consists of a single term, where wildcard characters should not be wrapped around quotations.

        $single = (strpos($string, ' ') === false);
        if ($single && (strpos($string, '"') === false)) {
            return "{$string}$wildcard";
        } else if ($single) {
            return $string;
        }

        // Parse each individual term of the input string.

        $string = explode(' ', $string);
        $terms  = array();
        if (is_array($string)) {
            $quotation = false;
            foreach ($string as $term) {
                if (!strlen($term)) {
                    continue;
                }
                // Parse a "search phrase" by storing the current state, where wildcard characters should no longer be wrapped.

                if (($quotations = substr_count($term, '"')) > 0) {
                    if ($quotations === 1) {
                        $quotation = !$quotation;
                    }
                    $terms[] = $term;
                    continue;
                }

                // Appropriately handle each individual term depending on the "search phrase" state and any custom query syntax.

                if ($quotation || ($term === 'OR') || ($term === '||') || ($term === 'AND') || ($term === '&&') || ($term
                    === 'NOT') || ($term === '!') || (strpos($term, '+') === 0) || (strpos($term, '-') === 0)) {
                    $terms[] = $term;
                } else {
                    $term = "{$term}{$wildcard}";

                    // When dealing with custom grouping, make sure the search terms have been wrapped.

                    $term    = str_replace(array(
                        ')' . $wildcard
                        ), array(
                        $wildcard. ')'
                        ), $term);
                    $terms[] = $term;
                }
            }
        }
        return implode(' ', $terms);
    }

    public function boost($boost)
    {
        $this->boost = $boost;
    }

    public function boostFieldValues($boost)
    {
        $this->boostFieldValues = $boost;
    }

    public function toQuery()
    {
        // Determine the field specific boosting to be applied.
        $fields = array();
        foreach ($this->fields as $field) {
            if (isset($this->boost[$field])) {
                $field .= "^{$this->boost[$field]}";
            }
            $fields[] = $field;
        }

        $query = new Query\BoolQuery();

        $chars = explode(' ', '+ - && || ! ( ) { } [ ] ^ " ~ * ? : \ /');

        $userQuery = $this->getUserQuery();

        $filteredQuery = str_replace($chars, ' ', $userQuery);

        if (!$this->allowEmpty || strlen($filteredQuery)) {
            // okay, let's add our various matching bits that are needed
            $subquery = new Query\BoolQuery();

            // Partial match on the entered term
            $mq = new Query\MultiMatch();
            $mq->setQuery($filteredQuery);
            $mq->setFields($fields);
            $mq->setType("phrase_prefix");
            $subquery->addShould($mq);

            // Mostfields match to cover how frequently it exists. Use most_fields to match any field and combines the _score from each field.
            $mq2 = new Query\MultiMatch();
            $mq2->setQuery($filteredQuery);
            $mq2->setFields($fields);
            $mq2->setType("most_fields");
            if ($this->fuzziness) {
                $mq2->setParam('fuzziness', (int) $this->fuzziness);
            }
            $subquery->addShould($mq2);

            // and now one with a keyword analyzer to do exact matching of the input text
            $mq3 = new Query\MultiMatch();
            $mq3->setQuery($filteredQuery);
            $mq3->setFields($fields);
            $mq3->setType("most_fields");
            $mq3->setAnalyzer('keyword');
            $mq3->setParam('boost', '3');
            // $mq3->
            $subquery->addShould($mq3);

            $query->addMust($subquery);
        }

        $include = (Versioned::get_stage() === 'Live') ? 'Live' : 'Stage';

        $overallFilter = new Query\BoolQuery();
        $overallFilter->addMust(new Query\QueryString("SS_Stage:{$include}"));


        // Determine the filters to be applied, separating the class hierarchy restriction.

        if (count($this->filters)) {
            $currentFilters = $this->filters;

            // Determine the filters to be applied
            foreach ($currentFilters as $field => $filter) {
                if (!is_object($filter)) {
                    $filter = new Query\Term([$field => $filter]);
                }
                $overallFilter->addMust($filter);
            }
        }

        // Determine the value specific boosting to be applied, wrapping around the boolean query.

        foreach ($this->boostFieldValues as $field => $boost) {
            // we use a constant score here because the expectation is that
            // it's an explicit match, not that the match will be weighted before
            // being boosted
            $boostQ = new Query\ConstantScore(new Query\QueryString($field));
            $boostQ->setBoost((float) $boost);
            $query->addShould($boostQ);
        }

        $query->addFilter($overallFilter);

        // Instantiate the query object using this boosting wrapper.
        $query = new Query($query);

        if ($this->sort) {
            list($sortField, $sortOrder) = explode(" ", $this->sort);
            $sort = [
                $sortField => [
                    'order' => $sortOrder,
//                        'unmapped_type' => 'long',
                ]
            ];
            
            $query->setSort($sort);
        }



        // Determine the faceting/aggregation.

        foreach ($this->facets['fields'] as $facet => $title) {

            // The second string will be the display title.
            $aggregation = new \Elastica\Aggregation\Terms($facet);
            $aggregation->setField($facet);
            $query->addAggregation($aggregation);
        }

//        $o = $query->toArray();
//
//        $p = json_encode($o);

        return $query;
    }

    /**
     * Add a filter query clause.
     *
     * Filter queries simply restrict the result set without affecting the score of results
     *
     * @param string $query
     */
    public function addFilter($name, $value)
    {
        $this->filters[$name] = $value;
        return $this;
    }

    /**
     * Remove a filter in place on this query
     *
     * @param string $query
     * @param mixed $value
     */
    public function removeFilter($query, $value = null)
    {
        if ($value) {
            $query = "$query:$value";
        }
        unset($this->filters[$query]);
        return $this;
    }

    /**
     * Apply a geo field restriction around a particular point
     *
     * @param string $point
     * 					The point in "lat,lon" format
     * @param string $field
     * @param float $radius
     */
    public function restrictNearPoint($point, $field, $radius)
    {
        $this->addFilter("{!geofilt}");

        $this->params['sfield'] = $field;
        $this->params['pt']     = $point;
        $this->params['d']      = $radius;

        return $this;
    }
}