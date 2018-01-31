<?php

namespace Symbiote\ElasticSearch;

use SilverStripe\Core\Extension;
use SilverStripe\Forms\Form;
use SilverStripe\Control\HTTP;
use SilverStripe\Core\Convert;
use SilverStripe\Forms\CheckboxSetField;

/**
 * 
 *
 * @author marcus
 */
class ElasticaSearchController extends Extension
{

    public function updateExtensibleSearchForm(Form $form)
    {
        $page = $this->owner->data();
        if (!$page) {
            return;
        }
        $filters = $page->UserFilters->getValues();
        if ($filters) {
            $cbsf = CheckboxSetField::create('UserFilter', '', array_values($filters));

            $filterFieldValues = array();
            if (isset($_GET['UserFilter'])) {
                foreach (array_values($filters) as $k => $v) {
                    if (in_array($k, (array) $_GET['UserFilter'])) {
                        $filterFieldValues[] = $k;
                    }
                }
            } else {
                $filterFieldValues = array_keys(array_values($filters));
            }
            $cbsf->setValue($filterFieldValues);

            $form->Fields()->push($cbsf);
        }
    }

    public function getAggregations()
    {

        $request = $this->owner->getRequest();
        $query   = $this->owner->data()->getResults();

        // The aggregations.
        $aggregations = ArrayList::create();
        try {
            foreach ($query->getAggregations() as $type => $aggregation) {
                // The groupings for each aggregation.
                $buckets = ArrayList::create();
                if (isset($aggregation['buckets'])) {
                    foreach ($aggregation['buckets'] as $bucket) {

                        // Determine the display title for an aggregation.

                        $facets         = $this->owner->data()->facetFieldMapping();
                        $bucket['type'] = isset($facets[$type]) ? $facets[$type] : $type;

                        // Determine the redirect to be used when using the facet/aggregation.

                        $vars = $request->getVars();
                        unset($vars['url']);
                        unset($vars['start']);
                        unset($vars['aggregation']);
                        $link = $this->owner->data()->Link('getForm');
                        foreach ($vars as $var => $value) {
                            $link = HTTP::setGetVar($var, $value, $link);
                        }
                        $bucket['link'] = HTTP::setGetVar('aggregation',
                                array(
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
            if ($this->logger) {
                $this->logger;
            }
        }

        return $aggregations;
    }

    public function getAggregationFilters()
    {

        $request = $this->owner->getRequest();
        $query   = $this->owner->data()->getResults();

        // Determine the selected facets/aggregations.

        $aggregation  = $request->getVar('aggregation');
        $aggregations = null;
        if ($aggregation && is_array($aggregation) && count($aggregation)) {
            $aggregations = ArrayList::create();

            // Determine the display title for an aggregation.

            $facets = $this->owner->data()->facetFieldMapping();
            foreach ($aggregation as $type => $filter) {
                $bucket = array(
                    'key' => $filter,
                    'type' => (isset($facets[$type]) ? $facets[$type] : $type)
                );

                // Determine the redirect to be used when using the facet/aggregation.

                $vars = $request->getVars();
                unset($vars['url']);
                unset($vars['aggregation']);
                $link = $this->owner->data()->Link('getForm');
                foreach ($vars as $var => $value) {
                    $link = HTTP::setGetVar($var, $value, $link);
                }
                $bucket['link'] = $link;
                $aggregations->push(ArrayData::create($bucket));
            }
        }
        return $aggregations;
    }

    /**
     * 	Process and render search results
     *
     * 	@return array
     */
    public function getSearchResults($data = null, $form = null)
    {
        $request = $this->owner->getRequest();
        $query   = $this->owner->data()->getResults();
        /* @var $query SilverStripe\Elastica\ResultList */

        // Determine the selected facets/aggregations to apply.

        $aggregation = $request->getVar('aggregation');
        if ($aggregation && is_array($aggregation)) {
            // HACK sorry
            $q = $query->getQuery()->getQuery()->getParam('query');
            foreach ($aggregation as $field => $value) {
                $q->addFilter(new Query\QueryString("{$field}:\"{$value}\""));
            }
        }

        // Determine the query sorting.

        $sortBy        = $request->getVar('SortBy') ? $request->getVar('SortBy') : $this->owner->SortBy;
        $sortDirection = $request->getVar('SortDirection') ? $request->getVar('SortDirection') : $this->owner->SortDirection;
        $query->getQuery()->setSort(array(
            $sortBy => strtolower($sortDirection)
        ));

        $term    = $request->getVar('Search') ? Convert::raw2xml($request->getVar('Search')) : '';
        $message = '';

        try {
            $results = $query ? $query->getDataObjects(
                    $this->owner->ResultsPerPage, $request->getVar('start') ? $request->getVar('start') : 0
                ) : ArrayList::create();
        } catch (Exception $ex) {
            SS_Log::log($ex, SS_Log::WARN);
            $message = 'Search failed';
            $query   = null;
            $results = ArrayList::create();
        }

        $elapsed = '< 0.001';

        $count = ($query && ($total = $query->getTotalResults())) ? $total : 0;
        if ($query) {
            $resultData = array(
                'TotalResults' => $count
            );
            $time       = $query->getTimeTaken();
            if ($time) {
                $elapsed = $time / 1000;
            }
        } else {
            $resultData = array();
        }

        $data = new ArrayObject(array(
            'Message' => $message,
            'Results' => $results,
            'Count' => $count,
            'Query' => DBVarchar::create_field('Varchar', $term),
            'Title' => $this->owner->data()->Title,
            'ResultData' => ArrayData::create($resultData),
            'TimeTaken' => $elapsed,
            'RawQuery' => $query ? json_encode($query->getQuery()->toArray()) : ''
        ));

        $this->owner->extend('updateSearchResults', $data);

        return $data->getArrayCopy();
    }
}