<?php

namespace Symbiote\ElasticSearch;

use SilverStripe\Core\Extension;
use SilverStripe\Forms\Form;
use SilverStripe\Control\HTTP;
use SilverStripe\Core\Convert;
use SilverStripe\Forms\CheckboxSetField;
use SilverStripe\ORM\ArrayList;
use SilverStripe\View\ArrayData;

use SilverStripe\Forms\DropdownField;
use SilverStripe\Forms\CompositeField;
use SilverStripe\Forms\FieldGroup;
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

        $existingSearch = singleton(ElasticaSearchEngine::class)->getCurrentResults();
        if (
            $existingSearch &&
            isset($existingSearch['Aggregations']) &&
            count($existingSearch['Aggregations'])
        ) {

            $request = $this->owner->getRequest();
            $aggregation  = $request->getVar('aggregation');

            $aggregationGroup = FieldGroup::create('AggregationOptions');

            foreach ($existingSearch['Aggregations'] as $facetType) {
                $currentLabel = null;
                $filterField = null;
                $options = [];
                foreach ($facetType as $facetItem) {
                    if (!$currentLabel) {
                        $currentLabel = $facetItem->type;
                        $filterField = $facetItem->field;
                    }
                    $options[$facetItem->key] = $facetItem->key;
                }
                if (count($options)) {
                    $fieldName = "aggregation[$filterField]";
                    $values = isset($aggregation[$filterField]) ? $aggregation[$filterField] : [];
                    if ($page->FacetStyle === 'Dropdown') {
                        $aggregationGroup->push(
                            DropdownField::create("", $currentLabel, $options)
                                ->addExtraClass('facet-dropdown')
                                ->setEmptyString(' ')
                                ->setValue($values)
                        );
                    } else if ($page->FacetStyle === 'Checkbox') {
                        $aggregationGroup->push(
                            CheckboxSetField::create("aggregation[$filterField]", $currentLabel, $options)
                                ->addExtraClass('facet-checkbox')
                                ->setValue($values)
                        );
                    }
                }
            }

            $form->Fields()->push($aggregationGroup);
        }
    }

    public function getAggregationFilters()
    {

        $request = $this->owner->getRequest();

        // Determine the selected facets/aggregations.

        $aggregation  = $request->getVar('aggregation');
        $aggregations = null;
        if ($aggregation && is_array($aggregation) && count($aggregation)) {
            $aggregations = ArrayList::create();

            // Determine the display title for an aggregation.

            $facets = $this->owner->data()->facetFieldMapping();
            foreach ($aggregation as $type => $filter) {
                if (!$filter) {
                    continue;
                }
                $bucket = array(
                    'key' => is_array($filter) ? implode(', ', $filter) : $filter,
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
     * @deprecated Since 2018 or thereabouts. Doesn't work!
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
