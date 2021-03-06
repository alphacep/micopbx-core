<?php
/**
 * Copyright (C) MIKO LLC - All Rights Reserved
 * Unauthorized copying of this file, via any medium is strictly prohibited
 * Proprietary and confidential
 * Written by Nikolay Beketov, 5 2018
 *
 */

use Models\OutgoingRoutingTable,
    Models\Providers;


class OutboundRoutesController extends BaseController {


	/**
	 * Построениие списка исходящих маршрутов
	 */
    public function indexAction()
    {

        $rules = OutgoingRoutingTable::find(array('order'=>'priority'));
        $routingTable = array();
        foreach ($rules as $rule){
            $provider       = $rule->Providers;
            if ($provider){
	        	$modelType      = ucfirst( $provider->type );
	        	$provByType     = $provider->$modelType;
            	$routingTable[] =[
	            	'id'               =>  $rule->id,
	            	'priority'         =>  $rule->priority,
	            	'provider'         =>  $provider->getRepresent(),
	            	'numberbeginswith' =>  $rule->numberbeginswith,
	            	'restnumbers'      =>  $rule->restnumbers,
	            	'trimfrombegin'    =>  $rule->trimfrombegin,
	            	'prepend'          =>  $rule->prepend,
	            	'note'             =>  $rule->note,
	            	'rulename'         =>  $rule->getRepresent(),
	            	'disabled'         =>  $provByType->disabled,
            	];
			} else {
				$routingTable[] =[
					'id'               =>  $rule->id,
					'priority'         =>  $rule->priority,
					'provider'         =>  null,
					'numberbeginswith' =>  $rule->numberbeginswith,
					'restnumbers'      =>  $rule->restnumbers,
					'trimfrombegin'    =>  $rule->trimfrombegin,
					'prepend'          =>  $rule->prepend,
					'note'             =>  $rule->note,
					'rulename'         =>  '<i class="icon attention"></i> '.$rule->getRepresent(),
					'disabled'         =>  false,
				];
			}
        }

        $this->view->routingTable = $routingTable;
    }

	/**
	 * Карточка редактирования исходящего маршрута
	 * @param null $id
	 */
	public function modifyAction($id=null){

		$rule = OutgoingRoutingTable::findFirstByid($id);
		if (!$rule) $rule = new OutgoingRoutingTable();

		$providers = Providers::find();
		$providersList = array();
		foreach ($providers as $provider){
			$providersList[ $provider->uniqid ] = $provider->getRepresent();
		}

		uasort($providersList, ["OutboundRoutesController", "sortArrayByNameAndState"]);

		if ($rule->restnumbers == -1){
			$rule->restnumbers = '';
		}
		$this->view->form = new OutgoingRouteEditForm($rule, $providersList);
		$this->view->represent  = $rule->getRepresent();

	}

	/**
	 * Сохранение карточки исходящего маршрута
	 */
    public function saveAction()
    {
	    $this->db->begin();

	    $data = $this->request->getPost();

	    $rule = OutgoingRoutingTable::findFirstByid($data['id']);
	    if (!$rule)  $rule = new OutgoingRoutingTable();

	    foreach ($rule as $name => $value) {
		    switch($name) {
				case 'restnumbers':{
					if (!array_key_exists($name, $data)) continue;
					$rule->$name = $data[$name]==''?-1:$data[$name];
					break;
				}
			    default:
				    if (!array_key_exists($name, $data)) continue;
				    $rule->$name = $data[$name];
		    }
	    }

	    if ($rule->save() === false) {
		    $errors = $rule->getMessages();
		    $this->flash->warning(implode('<br>', $errors));
		    $this->view->success = false;
		    $this->db->rollback();
		    return;
	    }

	    $this->flash->success($this->translation->_('ms_SuccessfulSaved'));
	    $this->view->success=true;
	    $this->db->commit();

	    // Если это было создание карточки то надо перегрузить страницу с указанием ID
	    if (empty($data['id'])){
		    $this->view->reload = "outbound-routes/modify/{$rule->id}";
	    }
    }


	/**
	 * Удаление исходящего маршрута из базы данных
	 * @param null $id
	 */
    public function deleteAction($id=null){
        $rule = OutgoingRoutingTable::findFirstByid($id);
        if ($rule) $rule->delete();
        return $this->forward('outbound-routes/index');

    }

	/**
	 * Изменение приоритета правила
	 * @param null $ruleid
	 */
	public function changePriorityAction($ruleid=null){
		$this->view->disable();
		$result = false;

		if (!$this->request->isPost()) {
			return;
		}
		$data = $this->request->getPost();
		$rule = OutgoingRoutingTable::findFirstById($ruleid);
		if ($rule){
			$rule->priority=intval($data['newPriority']);
			$result=$rule->update();
		}
		echo json_encode($result);
	}

	private function sortArrayByNameAndState ($a, $b)
	{
		$sDisabled = $this->translation->_('mo_Disabled');
			if ($a == $b) {
				return 0;
			} elseif (strpos ( $a , $sDisabled)!==false && strpos ( $b , $sDisabled)===false) {
				return 1;
			} else {
				return ($a < $b) ? -1 : 1;
			}

	}
}