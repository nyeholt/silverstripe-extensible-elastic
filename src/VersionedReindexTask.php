<?php

namespace Symbiote\ElasticSearch;

use SilverStripe\Security\Permission;
use SilverStripe\Control\Director;
use SilverStripe\Versioned\Versioned;
use SilverStripe\Core\Extensible;
use SilverStripe\Dev\BuildTask;
use SilverStripe\Core\Config\Config;

use Heyday\Elastica\ElasticaService;

/**
 * 
 *
 * @author marcus
 */
class VersionedReindexTask extends BuildTask
{
    protected $title = 'Elastic Search Reindex to include Versioned content';

	protected $description = 'Refreshes the elastic search index including versioned content';

	/**
	 * @var ElasticaService
	 */
	private $service;

	public function __construct(ElasticaService $service) {
		$this->service = $service;
	}

	public function run($request) {
        if (!(Permission::check('ADMIN') || Director::is_cli())) {
            exit("Invalid");
        }

		$message = function ($content) {
			print(Director::is_cli() ? "$content\n" : "<p>$content</p>");
		};

        $message("Specify 'rebuild' to delete the index first, and 'reindex' to re-index content items");

        if ($request->getVar('rebuild')) {
            $this->service->getIndex()->delete();
        }
        
        $svc = $this->service;

        $doIndex = function ($record) use ($svc, $message) {
            if (($record instanceof SiteTree && $record->ShowInSearch) ||
                (!$record instanceof SiteTree && ($record->hasMethod('getShowInSearch') && $record->getShowInSearch())) ||
                (!$record instanceof SiteTree && !$record->hasMethod('getShowInSearch'))
            ) {
                $svc->index($record);
                $message("INDEXED: Document Type \"" . $record->getClassName() . "\" - " . $record->getTitle() . " - ID " . $record->ID);
            } else {
                $svc->remove($record);
                $message("REMOVED: Document Type \"" . $record->getClassName() . "\" - " . $record->getTitle() . " - ID " . $record->ID);
            }
        };

		$message('Defining the mappings (if not already)');
		$this->service->define();

        if ($request->getVar('reindex')) {
            $message('Refreshing the index');
            try {
                // doing this manually because the base module doesn't support versioned directly
                

                foreach ($this->service->getIndexedClasses() as $class) {
                    Versioned::set_stage('Stage');
                    if (!Config::inst()->get($class, 'supporting_type')) { //Only index types (or classes) that are not just supporting other index types
                        foreach ($class::get() as $record) {

                            //Only index records with Show In Search enabled for Site Tree descendants
                            //otherwise index all other data objects
                            $message("Indexing draft record " . $record->Title);
                            $record->reIndex('Stage');
                        }

                        if (Extensible::has_extension($class, Versioned::class)) {
                            Versioned::set_stage('Live');
                            $live = Versioned::get_by_stage($class, 'Live');
                            foreach ($live as $liveRecord) {
                                $message("Indexing Live record " . $liveRecord->Title);
                                $liveRecord->reIndex('Live');
                            }
                        }
                    }
                }
            } catch (\Exception $ex) {
                $message("Some failures detected when indexing " . $ex->getMessage());
            }
        }
	}
}