    <?php

/*****
 * Music Flatfiles Generator Cron.php
 * Author: Tao Wu
 */
include_once('cron-config.php');
require_once(dirname(__FILE__).'/../lib/global.php');
define('DS', '/');
$date = getdate();
//return true if the cron is matching the current time
function check_cron($cron_str){
    global $date;
    $t = array(
        $date['minutes'],
        $date['hours'],
        $date['mday'],
        $date['mon'],
        $date['wday']
    );
	
	$time_names = array('minutes', 'hours', 'mday', 'mon', 'wday');

    $c = preg_split("/\s+/", $cron_str, -1, PREG_SPLIT_NO_EMPTY);
    if(count($c) != 5){
	     echo "Unkown cron config. Config must have five parts. see cron.config.php \n";
        return false;
    }
	var_dump($c);
    for($i = 0; $i < 5; $i++){
        if($c[$i] == '*') continue;
		
		echo "Interval:{$time_names[$i]} Cron time:  {$c[$i]}  current time: {$t[$i]} \n";
        if($c[$i] != $t[$i]) return false;
    }
    return true;
}

foreach($jobs as $job => $cron){

    if(is_array($cron)){
        if( ! array_filter(array_map('check_cron', $cron))) continue;
    }else{
        if( ! check_cron($cron)) continue;
    }

    $filename = dirname(__FILE__) . DS . $job . '.php';
    echo "tring to run $job...\n($filename)\n";

    $lockfile = $lockfile_path . DS . $job . '.lock';
    echo "checking lock... ";

    if(is_file($lockfile)){
        echo "lock found, skipping.\n";
        continue;
    }else{
        touch($lockfile);
    }
    echo "no lock, processing...\n";
    include_once($filename);
    unlink($lockfile);
    echo "done.\n";
}

echo "Done!";


