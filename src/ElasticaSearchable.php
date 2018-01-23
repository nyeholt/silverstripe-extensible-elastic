<?php

namespace Symbiote\ElasticSearch;

use Heyday\Elastica\Searchable;

/**
 * Adds additional indexing fields to support broader search usage
 *
 * @author marcus
 */
class ElasticaSearchable extends Searchable
{
    public function allSearchableFields() {
        $result = parent::getElasticaFields();

        $result['PublicView'] = array('type' => 'boolean');
        if ($this->owner->hasExtension('Hierarchy') || $this->owner->hasField('ParentID')) {
            $result['ParentsHierarchy'] = array('type' => 'long',);
        }

        $updated = $this->owner->invokeWithExtensions('updateElasticaFields');


        foreach ($result as $field => $spec) {
            if (isset($spec['type']) && ($spec['type'] == 'date')) {
                $spec['format'] = 'yyyy-MM-dd HH:mm:ss';
                $result[$field] = $spec;
            }
        }
        if (isset($result['Content']) && count($result['Content'])) {
            $spec = $result['Content'];
            $spec['store'] = false;
            $result['Content'] = $spec;
        }
        $this->owner->invokeWithExtensions('updateElasticMappings', $result);
        return $result;
        
    }
}