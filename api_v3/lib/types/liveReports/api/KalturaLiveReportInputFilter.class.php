<?php

/**
 * @package api
 * @subpackage objects
 */
class KalturaLiveReportInputFilter extends KalturaObject
{	
	/**
	 * @var string
	 **/
	public $entryIds;
	
	/**
	 * @var time
	 **/
	public $fromTime;
	
	/**
	 * @var time
	 **/
	public $toTime;
	
	/**
	 * @var KalturaNullableBoolean
	 **/
	public $live;
	
	public function getWSObject() {
		$obj = new WSLiveReportInputFilter();
		$obj->fromKalturaObject($this);
		return $obj;
	}
}


