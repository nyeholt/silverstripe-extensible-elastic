<?php

use Elastica\Aggregation;
use Elastica\Query;

/**
 * @author marcus
 */
class ElasticaSearch extends SolrSearch {
    
    private static $db = array(
        'QueryType' => 'Varchar',
        'SearchType' => 'MultiValueField',	// types that a user can search within
        'SearchOnFields' => 'MultiValueField',
        'ExtraSearchFields'	=> 'MultiValueField',
        'BoostFields' => 'MultiValueField',
        'BoostMatchFields' => 'MultiValueField',
        // faceting fields
        'FacetFields' => 'MultiValueField',
        'CustomFacetFields' => 'MultiValueField',
        'FacetMapping' => 'MultiValueField',
        'FacetQueries' => 'MultiValueField',
        'MinFacetCount' => 'Int',
        // filter fields (not used for relevance, just for restricting data set)
        'FilterFields'  => 'MultiValueField',
        // filters that users can explicitly choose from
        'UserFilters'   => 'MultiValueField'
    );
    
    public function updateExtensibleSearchPageCMSFields(\FieldList $fields) {
        parent::updateExtensibleSearchPageCMSFields($fields);
        $objFields = $this->owner->getSelectableFields();
        
        $fields->addFieldToTab(
            'Root.Main',
            $kv = KeyValueField::create('FacetFields', _t('ExtensibleSearchPage.FACET_FIELDS', 'Fields to create facets for'), $objFields),
            'FacetMapping'
        );
        $kv->setRightTitle('FieldName in left column, display label in the right');
        
        $fields->addFieldToTab(
            'Root.Main',
            $kv = new KeyValueField('UserFilters', _t('ExtensibleSearchPage.USER_FILTER_FIELDS', 'User selectable filters')),
            'FilterFields'
        );
        $kv->setRightTitle('Filter query on the left, label displayed on right');

        $fields->addFieldToTab(
            'Root.Main',
            $kv = KeyValueField::create('CustomFacetFields', _t('ExtensibleSearchPage.CUSTOM_FACET_FIELDS', 'Additional fields to create facets for')),
            'FacetMapping'
        );
        $kv->setRightTitle('FieldName in left column, display label in the right');
        
        $fields->removeByName('FacetMapping');
    }

    
    public function setSolrSearchService($v) {
        if ($v instanceof ExtensibleElasticService) {
            $this->solrSearchService = $v;
        }
    }
    
    public function getSelectableFields($listType = null, $excludeGeo = true) {
        if (!$listType) {
            $listType = $this->owner->searchableTypes('Page');
        }
        
        $allFields = array();
        foreach ($listType as $classType) {
            if (class_exists($classType)) {
                $item = singleton($classType);
                $fields = $item->getElasticaFields();
                $allFields = array_merge($allFields, $fields);
            }
        }
        
        $allFields = array_keys($allFields);
        $allFields = array_combine($allFields, $allFields);
        
        $allFields['_score'] = 'Score';

        ksort($allFields);
        return $allFields;
    }
    
    protected $currentQuery;
    
    public function getQuery() {
        if ($this->currentQuery) {
            return $this->currentQuery;
        }
        $this->currentQuery = parent::getQuery();
        return $this->currentQuery;
    }

	public function updateQueryBuilder($builder) {
		// This is required to load the faceting/aggregation.
        $fieldFacets = $this->owner->facetFieldMapping();
        if (count($fieldFacets)) {
            $builder->addFacetFields($fieldFacets);
        }
        
        
        if (isset($_GET['UserFilter'])) {
            $filters = $this->owner->UserFilters->getValues();
            if (count($filters)) {
                $queries = array_keys($filters);
                foreach ($_GET['UserFilter'] as $index => $junk) {
                    if (isset($queries[$index])) {
                        $builder->addFilter($queries[$index]);
                    }
                }
            }

        }
	}
    
    public function facetFieldMapping() {
        
        $selected = $this->owner->FacetFields->getValues();
        if (!$selected) {
            $selected = array();
        }
        $custom = $this->owner->CustomFacetFields->getValues();
        if (!$custom) {
            $custom = array();
        }
        
        $all = array_merge($selected, $custom);
        
        return $all;
    }
    
}

class ElasticaSearch_Controller extends SolrSearch_Controller {

    public function updateExtensibleSearchForm(Form $form) {
        $page = $this->owner->data();
        if (!$page) {
            return;
        }
        $filters = $page->UserFilters->getValues();
        if (!$filters) {
            $filters = array();
        }
        $cbsf = new CheckBoxSetField('UserFilter', '', array_values($filters));
        
        $filterFieldValues = array();
		if(isset($_GET['UserFilter'])) {
			foreach(array_values($filters) as $k => $v) {
				if(in_array($k, (array)$_GET['UserFilter'])) {
					$filterFieldValues[] = $k;
				}
			}
		} else {
			$filterFieldValues = array_keys(array_values($filters));
		}
		$cbsf->setValue($filterFieldValues);
        
        $form->Fields()->push($cbsf);
    }
    
	public function getAggregations() {

		$request = $this->owner->getRequest();
		$query = $this->owner->data()->getQuery();

		// The aggregations.
		$aggregations = ArrayList::create();
        try {
            foreach($query->getAggregations() as $type => $aggregation) {
                // The groupings for each aggregation.
                $buckets = ArrayList::create();
                if(isset($aggregation['buckets'])) {
                    foreach($aggregation['buckets'] as $bucket) {

                        // Determine the display title for an aggregation.

                        $facets = $this->owner->data()->facetFieldMapping();
                        $bucket['type'] = isset($facets[$type]) ? $facets[$type] : $type;

                        // Determine the redirect to be used when using the facet/aggregation.

                        $vars = $request->getVars();
                        unset($vars['url']);
                        unset($vars['start']);
                        unset($vars['aggregation']);
                        $link = $this->owner->data()->Link('getForm');
                        foreach($vars as $var => $value) {
                            $link = HTTP::setGetVar($var, $value, $link);
                        }
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
            }
        } catch (Exception $ex) {
            \SS_Log::log($ex, SS_Log::WARN);
        }
		
		return $aggregations;
	}

	public function getAggregationFilters() {

		$request = $this->owner->getRequest();
		$query = $this->owner->data()->getQuery();

		// Determine the selected facets/aggregations.

		$aggregation = $request->getVar('aggregation');
		$aggregations = null;
		if($aggregation && is_array($aggregation) && count($aggregation)) {
			$aggregations = ArrayList::create();

			// Determine the display title for an aggregation.

			$facets = $this->owner->data()->facetFieldMapping();
			foreach($aggregation as $type => $filter) {
				$bucket = array(
					'key' => $filter,
					'type' => (isset($facets[$type]) ? $facets[$type] : $type)
				);

				// Determine the redirect to be used when using the facet/aggregation.

				$vars = $request->getVars();
				unset($vars['url']);
				unset($vars['aggregation']);
				$link = $this->owner->data()->Link('getForm');
				foreach($vars as $var => $value) {
					$link = HTTP::setGetVar($var, $value, $link);
				}
				$bucket['link'] = $link;
				$aggregations->push(ArrayData::create($bucket));
			}
		}
		return $aggregations;
	}

	/**
	 *	Process and render search results
	 *
	 *	@return array
	 */
	public function getSearchResults($data = null, $form = null) {

		$request = $this->owner->getRequest();
		$query = $this->owner->data()->getQuery();
        /* @var $query SilverStripe\Elastica\ResultList */

		// Determine the selected facets/aggregations to apply.

		$aggregation = $request->getVar('aggregation');
		if($aggregation && is_array($aggregation)) {
            // HACK sorry
            $qb = singleton('ElasticaQueryBuilder');
            $old = $qb->version < 2;
            
            $q = $query->getQuery()->getQuery()->getParam('query');
			foreach($aggregation as $field => $value) {
                if ($old) {
                    $q->addMust(new Query\QueryString("{$field}:\"{$value}\""));
                } else {
                    $q->addFilter(new Query\QueryString("{$field}:\"{$value}\""));
                }
			}
		}

		// Determine the query sorting.

		$sortBy = $request->getVar('SortBy') ? $request->getVar('SortBy') : $this->owner->SortBy;
		$sortDirection = $request->getVar('SortDirection') ? $request->getVar('SortDirection') : $this->owner->SortDirection;
		$query->getQuery()->setSort(array(
			$sortBy => strtolower($sortDirection)
		));

		$term = $request->getVar('Search') ? Convert::raw2xml($request->getVar('Search')) : '';
        $message = '';
        
        try {
            $results = $query ? $query->getDataObjects(
                $this->owner->ResultsPerPage,
                $request->getVar('start') ? $request->getVar('start') : 0
            ) : ArrayList::create();
        } catch (Exception $ex) {
            SS_Log::log($ex, SS_Log::WARN);
            $message = 'Search failed';
            $query = null;
            $results = ArrayList::create();
        }

		$elapsed = '< 0.001';

		$count = ($query && ($total = $query->getTotalResults())) ? $total : 0;
		if ($query) {
			$resultData = array(
				'TotalResults' => $count
			);
			$time = $query->getTimeTaken();
			if($time) {
				$elapsed = $time / 1000;
			}
		} else {
			$resultData = array();
		}

		$data = array(
            'Message'       => $message,
			'Results'		=> $results,
			'Count'			=> $count,
			'Query'			=> Varchar::create_field('Varchar', $term),
			'Title'			=> $this->owner->data()->Title,
			'ResultData'	=> ArrayData::create($resultData),
			'TimeTaken'		=> $elapsed
		);

		return $data;
	}

}