<?php
 
/**
 * This class is a complex implementation for 1min aggregation of location report
 */
class LiveReportLocation1MinEngine extends LiveReportEngine {
	
	const TIME_CHUNK = 600;
	const MAX_RECORDS_PER_REQUEST = 1000;
	const AGGREGATION_CHUNK = LiveReportConstants::SECONDS_60;
	
	protected $dateFormatter;
	protected $nameFormatter;
	
	public function LiveReportLocation1MinEngine(LiveReportDateFormatter $dateFormatter) {
		$this->dateFormatter = $dateFormatter;
		$this->nameFormatter = new LiveReportStringFormatter();
	}
	
	public function run($fp, array $args = array()) {
		$this->checkParams($args, array(LiveReportConstants::TIME_REFERENCE_PARAM, LiveReportConstants::ENTRY_IDS));
		$toTime = $args[LiveReportConstants::TIME_REFERENCE_PARAM];
		$fromTime = $args[LiveReportConstants::TIME_REFERENCE_PARAM] - LiveReportConstants::SECONDS_36_HOURS;
		
		$this->printHeaders($fp);
		
		$objs = array();
		$lastTimeGroup = null;
		
		$fix = 0; // The report is inclussive, therefore starting from the the second request we shouldn't query twice
		for($curTime = $fromTime; $curTime < $toTime; $curTime = $curTime + self::TIME_CHUNK) {
			$curTo = min($toTime, $curTime + self::TIME_CHUNK);
			
			$pageIndex = 0;
			$moreResults = true;
			
			while($moreResults) {
				$pageIndex++;
				$results = $this->getRecords($curTime + $fix, $curTo, $args[LiveReportConstants::ENTRY_IDS], $pageIndex);
				$moreResults = self::MAX_RECORDS_PER_REQUEST * $pageIndex < $results->totalCount;
				if($results->totalCount == 0)  
					continue;
				
				foreach($results->objects as $result) {
					
					$groupTime = $this->roundTime($result->timestamp);
					
					if(is_null($lastTimeGroup))
						$lastTimeGroup = $groupTime;
					
					if($lastTimeGroup < $groupTime) {
						$this->printRows($fp, $objs, $lastTimeGroup);
						$lastTimeGroup = $groupTime;
					}
					
					$country = $result->country->name;
					$city = $result->city->name;
					$key = ($result->entryId . "#" . $country . "#" . $city);
			
					if(!array_key_exists($key, $objs)) {
						$objs[$key] = array();
					}
					$objs[$key][] = $result;
					
				}
			}
			$fix = LiveReportConstants::SECONDS_10;
		}
		
		$this->printRows($fp, $objs, $lastTimeGroup);
	}
	
	// ASUMPTION - we have a single entry ID (that's a constraint of the cassandra)
	// and the results are ordered from the oldest to the newest
	protected function getRecords($fromTime, $toTime, $entryId, $pageIdx) {
		
		$reportType = KalturaLiveReportType::ENTRY_GEO_TIME_LINE;
		$filter = new KalturaLiveReportInputFilter();
		$filter->toTime = $toTime;
		$filter->fromTime = $fromTime;
		$filter->entryIds = $entryId;
		
		$pager = new KalturaFilterPager();
		$pager->pageIndex = $pageIdx;
		$pager->pageSize = self::MAX_RECORDS_PER_REQUEST;
		
		return KBatchBase::$kClient->liveReports->getReport($reportType, $filter, $pager);
	}
	
	protected function printHeaders($fp) {
		$values = array();
		$values[] = "Date";
		$values[] = "Country";
		$values[] = "City";
		$values[] = "Latitude";
		$values[] = "Longitude";
		$values[] = "Plays";
		$values[] = "Average Audience";
		$values[] = "Min Audience";
		$values[] = "Max Audience";
		$values[] = "Average bitrate";
		$values[] = "Buffer time";
		$values[] = "Seconds viewed";
		
		fwrite($fp, implode(LiveReportConstants::CELLS_SEPARATOR, $values) . "\n");
	}
	
	protected function printRows($fp, &$objects, $lastTimeGroup) {
		
		foreach ($objects as $records) {

			$firstRecord = $records[0];
			
			$values = array();
			$values[] = $this->dateFormatter->format($lastTimeGroup);
			$values[] = $this->nameFormatter->format($firstRecord->country->name);
			$values[] = $this->nameFormatter->format($firstRecord->city->name);
			$values[] = $firstRecord->city->latitude;
			$values[] = $firstRecord->city->longitude;
			
			$plays = $audience = $avgBitrate = $bufferTime = $secondsViewed = $maxAudience = 0;
			$minAudience = PHP_INT_MAX;
			
			foreach ($records as $record) {
				$plays += $record->plays;
				$audience += $record->audience;
				$maxAudience = max($maxAudience, $record->audience);
				$minAudience = min($minAudience, $record->audience);
				$avgBitrate += $record->avgBitrate;
				$bufferTime += $record->bufferTime;
				$secondsViewed += $record->secondsViewed;
			}
			
			$nObj = count($records);
			$values[] = $plays;
			$values[] = round($audience / $nObj, 2);
			$values[] = $minAudience;
			$values[] = $maxAudience;
			$values[] = round($avgBitrate / $nObj, 2);
			$values[] = round($bufferTime / $nObj, 2);
			$values[] = $secondsViewed;
			
			fwrite($fp, implode(LiveReportConstants::CELLS_SEPARATOR, $values) . "\n");
		}
		
		$objects = array();
	}
	
	protected function roundTime($time) {
		return floor($time / self::AGGREGATION_CHUNK) * self::AGGREGATION_CHUNK;
	}

}

