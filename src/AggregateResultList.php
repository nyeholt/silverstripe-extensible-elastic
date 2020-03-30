<?php

namespace Symbiote\ElasticSearch;

use Elastica\Result;
use Heyday\Elastica\ResultList;

/**
 * A class that makes use of the results returned in a top_hits
 * aggregate to allow for sub-grouped sets of results to be displayed
 *
 */
class AggregateResultList extends ResultList
{

    private $aggregateResult;

    public function __construct($results)
    {
        $this->aggregateResult = [];
        foreach ($results as $hitData) {
            $this->aggregateResult[] = new Result($hitData);
        }
    }

    public function getResults()
    {
        return $this->aggregateResult;
    }
}
