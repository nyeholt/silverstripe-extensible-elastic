<?php

namespace Symbiote\ElasticSearch;

use ArrayObject;
use Heyday\Elastica\Searchable;
use SilverStripe\Core\ClassInfo;
use SilverStripe\Security\Member;
use SilverStripe\Versioned\Versioned;

/**
 * Adds additional indexing fields to support broader search usage
 *
 * Ensures that Versioned content is indexed in an appropriate stage. 
 *
 * @author marcus
 */
class ElasticaSearchable extends Searchable
{
    public static $stage_field = 'SS_Stage';

    /**
     * Are we indexing _live_ content?
     *
     * @var boolean
     */
    private $liveIndex = false;

    /**
     * Handles indexing of stage and Live content
     *
     * @param string $stage
     * @return void
     */
    public function reIndex($stage = '')
    {
        $currentStage = $stage ? $stage : Versioned::get_stage();
        $this->liveIndex = $currentStage === 'Live' ? true : false;
        return parent::reIndex($currentStage);
    }

    public function onAfterPublish() {
        $this->liveIndex = true;
        $this->reIndex('Live');
    }

	/**
	 * @return string
	 */
	public function getElasticaType($input = null) {
		return str_replace('\\', '_', $input ? $input : $this->owner->baseClass());
	}

    public function getElasticaFields() {
        $result = parent::getElasticaFields();

        // this needs to be an array object because invokeWithExtensions will _not_ pass
        // params by reference
        $result = new ArrayObject($result);

        $result['ID'] = ['type' => 'long'];
        $result['ClassName'] = ['type' => 'keyword'];
        $result['ClassNameHierarchy'] = ['type' => 'keyword'];
        $result['SS_Stage'] = ['type' => 'keyword'];

        $result['PublicView'] = array('type' => 'boolean');
        if ($this->owner->hasExtension('Hierarchy') || $this->owner->hasField('ParentID')) {
            $result['ParentsHierarchy'] = array('type' => 'long',);
        }

        foreach ($result as $field => $spec) {
            if (isset($spec['type']) && ($spec['type'] == 'date') && !isset($spec['format'])) {
                $spec['format'] = 'yyyy-MM-dd HH:mm:ss';
                $result[$field] = $spec;
            }
        }
        if (isset($result['Content']) && count($result['Content']) && !isset($result['Content']['store'])) {
            $spec = $result['Content'];
            $spec['store'] = false;
            $result['Content'] = $spec;
        }
        $this->owner->invokeWithExtensions('updateElasticMappings', $result);
        return $result->getArrayCopy();
        
    }

    public function getElasticaDocument() {
        $document = parent::getElasticaDocument();

        $stage = null;
        $indexedInStage = [];
        // is versioned, or has VersionedDataObject extension
        if ($this->owner->hasExtension(Versioned::class) || $this->owner->hasMethod('getCMSPublishedState')) {
            // add in the specific stage(s)
            $stage = $this->liveIndex ? 'Live' : 'Stage'; 
            $indexedInStage = [$stage];
        } else {
            $indexedInStage = array('Live', 'Stage');
        }
        $document->set('SS_Stage', $indexedInStage);
        
        $document->set('PublicView', $this->owner->canView(Member::create()));

        if ($this->owner->hasExtension('Hierarchy') || $this->owner->hasField('ParentID')) {
            $document->set('ParentsHierarchy', $this->getParentsHierarchyField());
        }

        if (!$document->has('ClassNameHierarchy')) {
            $classes = array_values(ClassInfo::ancestry($this->owner->ClassName));
            if (!$classes) {
                $classes = array($this->owner->ClassName);
            }
            $self = $this;
            $classes = array_map(function ($item) use ($self) {
                return $self->getElasticaType($item);
            }, $classes);
            $document->set('ClassNameHierarchy', $classes);
        }

        // Construct our ID based on type and stage, as _type mappings are being removed
        // in Elastic 6, meaning we need a unique ID
        $id = $this->owner->getElasticaType() . '_' . $this->owner->ID . '_' . $stage;

        $document->setId($id);
        $this->owner->invokeWithExtensions('updateElasticDoc', $document);

		return $document;
	}

    /**
	 * Get a field value representing the parents hierarchy (if applicable)
	 *
	 * @param type $dataObject
	 */
	protected function getParentsHierarchyField() {
		// see if we've got Parent values
        $parents = array();

        $parent = $this->owner;
        while ($parent && $parent->ParentID) {
            $parents[] = (int) $parent->ParentID;
            $parent = $parent->Parent();
            // fix for odd behaviour - in some instance a node is being assigned as its own parent.
            if ($parent->ParentID == $parent->ID) {
                $parent = null;
            }
        }
        return $parents;
	}
}