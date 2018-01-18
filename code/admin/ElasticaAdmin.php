<?php

namespace Symbiote\Elastic;

use ModelAdmin;
use TextareaField;
use FormAction;
use Exception;
use PjaxResponseNegotiator;


/**
 * @author marcus
 */
class ElasticaAdmin extends ModelAdmin {
    private static $url_segment = 'elasticsearch';
    private static $managed_models = array('ExtensibleSearch');
    private static $menu_title = 'Elastic Search';
    
    public function getEditForm($id = null, $fields = null) {
        $form = parent::getEditForm($id, $fields);
        
        $form->Fields()->insertBefore($this->modelClass, TextareaField::create('rawquery', 'Query')->setRows(50));
        $form->Fields()->removeByName('ExtensibleSearch');
        $form->Actions()->push(FormAction::create('execute', 'Run Query'));
        
        return $form;
    }
    
    public function execute($data, $form) {
        
        $index = singleton('ElasticaService')->getIndex();
        /* @var $index Elastica\Index */
        $param = json_decode($data['rawquery'], true);
        $query = new Elastica\Query($param);
        
        $response = '';
        try {
            $search = $index->search($query);
            $response = json_encode($search->getResponse()->getData());
        } catch (Exception $ex) {
            $response = $ex->getMessage();
        }
        
        $controller = $this;
        $responseNegotiator = new PjaxResponseNegotiator(array(
            'CurrentForm' => function() use(&$form) {
                return $form->forTemplate();
            },
            'default' => function() use(&$controller) {
                return $controller->redirectBack();
            }
        ));
        if($controller->getRequest()->isAjax()){
            $controller->getRequest()->addHeader('X-Pjax', 'CurrentForm');
        }
        
        $form->Fields()->push(TextareaField::create('Responsedata', 'Response')->setRows(50)->setColumns(40)->setValue($response));
        
        return $responseNegotiator->respond($controller->getRequest());
    }
}
