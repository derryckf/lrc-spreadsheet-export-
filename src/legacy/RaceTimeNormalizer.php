<?php

/*
 * To change this license header, choose License Headers in Project Properties.
 * To change this template file, choose Tools | Templates
 * and open the template in the editor.
 */

namespace myTest\compute;

use DateTime;
use DateTimeZone;
use DateInterval;

/**
 * Description of RaceTimeNormalizer
 *
 * @author mac
 */

class RaceTimeNormalizer {
    
    static public function monthsAgo($months) {
        $date = new DateTime();
        $date->sub(new DateInterval("P{$months}M"));
        return $date;
    }
    
    static public function timeToSec($time) {
        // Convert time to seconds
        // can handle \DateTime object or string
        if ($time instanceof \DateTime){
            // output time only string
            $time = $time->format('H:i:s');
            
        }
        $dt = new DateTime("1970-01-01 $time", new \DateTimeZone('UTC'));
        $time_in_seconds = (int)$dt->getTimestamp();
        return $time_in_seconds;
    }
    
    static public function SecToTime($seconds) : \DateTime {
        // Convert seconds to time
        $dt = new DateTime("1970-01-01 00:00:00", new \DateTimeZone('UTC'));
        $interval = new \DateInterval(sprintf("PT%dS",round($seconds)));
        $time = $dt->add($interval);
        return $time;
    }
    
    static public function convertSecToTime(int $sec) :\DateInterval 
    {
	$date1 = new \DateTime("@0"); //starting seconds
	$date2 = new \DateTime("@$sec"); // ending seconds
	$interval =  date_diff($date1, $date2); //the time difference
	return $interval; 
    }
    
    static public function getNormalizedTime($time, $d, $stdDist) {
        // time in second
        // distances in kilometers
        // elevation meters
               
        $normalized_time = $time * ($stdDist / $d) ** (1.06);
       
        
        return $normalized_time;
    }
    
    static public function getExpectedTime($d, $stdTime, $stdDist) {
        // time in second
        // distances in kilometers
        // elevation meters
        
        //echo 'getExpectedTime => Course Distance = '.$d.' Standard Distance = '.$stdDist.' Standard Time = '.$stdTime.PHP_EOL;
           
        $expected_time =  ($d ** (1.06) * $stdTime) / $stdDist ** (1.06); 
        
        //echo 'getExpectedTime => return = '.$expected_time.PHP_EOL;
        
        return $expected_time;
    }
}


