<?php

namespace Symbiote\ElasticSearch;

use Elastica\Query;
use Elastica\Query\BoolQuery;
use Elastica\Query\Range;
use Elastica\Query\Term;
use Heyday\Elastica\ElasticaService;
use SilverStripe\Core\Injector\Injector;
use Symbiote\QueuedJobs\Services\AbstractQueuedJob;
use Symbiote\QueuedJobs\Services\QueuedJobService;

class PruneStaleResultsJob extends AbstractQueuedJob
{
    public function __construct($filter = null, $since = '-1 month', $repeat = 0, $number = 500)
    {
        if ($filter) {
            $this->filter = $filter;
            $this->since = $since;
            $this->repeat = $repeat;
            $this->number = $number;

            $list = $this->getOldItems();
            if ($list) {
                $this->totalSteps = $list->getTotalHits();
            }
        }
    }

    public function getTitle()
    {
        return "Prune stale elasticsearch " . $this->filter;
    }

    public function getOldItems()
    {
        $bool = new BoolQuery();

        $range = new Range('LastEdited', ['lt' => date('Y-m-d\TH:i:s', strtotime($this->since))]);

        $bool->addFilter($range);

        if (strpos($this->filter, ':')) {
            $parts = explode(":", trim($this->filter));
            $bool->addFilter(new Term([$parts[0] => $parts[1]]));
        }

        $query = new Query($bool);

        $service = Injector::inst()->get(ElasticaService::class);

        /** @var ExtensibleElasticService $service  */

        $resultSet = $service->query($query, 0, $this->number);

        $list = $resultSet->getResults();
        return $list;
    }

    public function process()
    {
        $list = $this->getOldItems();
        $list = $list->getDocuments();

        $numFound = count($list);

        if ($numFound) {
            $service = Injector::inst()->get(ElasticaService::class);
            $service->getIndex()->deleteDocuments($list);
        }

        $next = null;
        // if we found the max number, we need to re-execute
        $time = $this->repeat;
        if ($numFound == $this->number) {
            $time = 3600;
            $next = new PruneStaleResultsJob($this->filter, $this->since, $this->repeat, $this->number);
        } else if ($this->repeat) {
            $next = new PruneStaleResultsJob($this->filter, $this->since, $this->repeat, $this->number);
        }

        if ($next) {
            Injector::inst()->get(QueuedJobService::class)->queueJob($next, date('Y-m-d H:i:s', time() + $time));
        }

        $this->currentStep = $this->number;
        $this->isComplete = true;
    }
}
