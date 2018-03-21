<?php

namespace Symbiote\ElasticSearch;

use SilverStripe\Core\Extension;
use SilverStripe\Forms\FieldList;
use Symbiote\MultiValueField\Fields\MultiValueTextField;
use SilverStripe\CMS\Model\SiteTree;

/**
 * @author marcus
 */
class DataDiscovery extends Extension
{
    //put your code here
    private static $db = [
        'BoostTerms' => 'MultiValueField',
    ];

    public function updateCMSFields(FieldList $fields)
    {
        $fields->addFieldsToTab('Root.Tagging', $mvf = MultiValueTextField::create('BoostTerms', 'Boost for these keywords'));
        $mvf->setRightTitle("Enter the word 'important' to boost this item in any search it appears in");

    }


    /**
     * Sets appropriate mappings for fields that need to be subsequently faceted upon
     * @param type $mappings
     */
    public function updateElasticMappings($mappings)
    {
        $mappings['BoostTerms'] = ['type' => 'text'];

        $mappings['Categories'] = ['type' => 'keyword'];
        $mappings['Keywords'] = ['type' => 'text'];
        $mappings['Tags'] = ['type' => 'keyword'];

        if ($this->owner instanceof SiteTree) {
            // store the SS_URL for consistency
            $mappings['SS_URL'] = ['type' => 'keyword'];
        }
    }

    public function updateElasticDoc($document)
    {
        $document->set('BoostTerms', $this->owner->BoostTerms->getValues());
        // expects taxonomy terms here...
        if ($this->owner->hasMethod('Terms')) {
            $categories = $this->owner->Terms()->column('Name');

            $currentCats = $document->has('Categories') ? $document->get('Categories') : [];

            $document->set('Categories', array_merge($currentCats, $categories));
            $document->set('Keywords', implode(' ', $categories));
        }

        if ($this->owner->hasMethod('Tags')) {
            $tags = $this->owner->Tags()->column('Title');
            $currentCats = $document->has('Tags') ? $document->get('Tags') : [];
            $document->set('Tags', array_merge($currentCats, $tags));
        }


        if ($this->owner instanceof SiteTree) {
            // store the SS_URL for consistency
            $document->set('SS_URL', $this->owner->RelativeLink());
        }
    }
}