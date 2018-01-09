<?php

error_reporting(E_ALL & ~E_STRICT & ~E_DEPRECATED);
ini_set("memory_limit", -1);

$inc_conf = dirname(__FILE__) . '/';
$root_dir = $inc_conf . '../';

$lockfile_path = '/srv/nfs/music-reports.media-control.int/lock';
$ftp_host = 'asbad04.mcbad.net';
$ftp_username = 'MC_FTP';
$ftp_password = 'linux_3018';
$target_path_de = '/NFS/ASBAD04/FTP/MediaControl/GER';
$target_path_at = '/NFS/ASBAD04/FTP/MediaControl/AUT/Customer';

define('CFG_PATH', $inc_conf.'cfg/');
define('LIB_PATH', $inc_conf.'lib/');
define('MOD_PATH', $inc_conf.'mod/');
define('TPL_PATH', $inc_conf.'tpl/');

$nfs_root_dir = '/srv/nfs/music-reports.media-control.int/';

$reports_error_recipients = array(
    "vitus.nagel@gfk.com, manh-cuong.tran@gfk.com, Christian.Steinmann@gfk.com"
);
$reports_normal_recipients = array(
    "vitus.nagel@gfk.com, manh-cuong.tran@gfk.com, Christian.Steinmann@gfk.com"
);
$emi_reports_error_recipients = array(
    "vitus.nagel@gfk.com, manh-cuong.tran@gfk.com"
);
$emi_reports_normal_recipients = array(
    "Silke.Lotsch@gfk.com, Mike.Timm@gfk.com, Michael.Hacker@gfk.com, vitus.nagel@gfk.com, manh-cuong.tran@gfk.com, Lisa.Luppold@gfk.com"
);



require_once('MDB2.php');
require_once('ftp.class.php');
require_once(CFG_PATH . 'db.conf.php');
include_once('functions.inc.php');

$dsn = $exasol_dsn; 

$options 	= array(
    'result_buffering' 	=> false, 
    'field_case' 		=> CASE_LOWER
);

$db = MDB2::connect($dsn, $options);
if (MDB2::isError($db)) {
	die ($db->getMessage());
}


/**
 * recursively delete a file or a folder and its contents
 *
 * @author      Aidan Lister <aidan@php.net>
 * @version     1.0.3
 * @link        http://aidanlister.com/repos/v/function.rmdirr.php
 * @param       string   $dirname    Directory to delete
 * @return      bool     Returns TRUE on success, FALSE on failure
 */
function rmdirr($dirname) {
    // Sanity check
    if (!file_exists($dirname)) {
        return false;
    }

    // Simple delete for a file
    if (is_file($dirname) || is_link($dirname)) {
        return unlink($dirname);
    }

    // Loop through the folder
    $dir = dir($dirname);
    while (false !== $entry = $dir->read()) {
        // Skip pointers
        if ($entry == '.' || $entry == '..') {
            continue;
        }

        // Recurse
        rmdirr($dirname . DIRECTORY_SEPARATOR . $entry);
    }

    // Clean up
    $dir->close();
    return rmdir($dirname);
}

/* creates a compressed zip file
 * parameter: $files = array(
		array("abs_path" => '/absolute/path/to/the/file1', "rel_path" => '/path/in/zip1'),
		array("abs_path" => '/absolute/path/to/the/file2', "rel_path" => '/path/in/zip2'),
		array("abs_path" => '/absolute/path/to/the/file3', "rel_path" => '/path/in/zip3'),
	), $destination = "target/zip/file",
	$overwrite = doh
 */
function create_zip($files = array(), $destination = '',$overwrite = false) {
	//if the zip file already exists and overwrite is false, return false
	if(file_exists($destination) && !$overwrite) { return false; }
	//vars
	$valid_files = array();
	//if files were passed in...
	if(is_array($files)) {
		//cycle through each file
		foreach($files as $file) {
			//make sure the file exists
			if(file_exists($file['abs_path'])) {
				$valid_files[] = $file;
			}
		}
	}
	//if we have good files...
	if(count($valid_files)) {
		//create the archive
		$zip = new ZipArchive();
		if($zip->open($destination,$overwrite ? ZIPARCHIVE::OVERWRITE : ZIPARCHIVE::CREATE) !== true) {
			return false;
		}
		//add the files
		foreach($valid_files as $file) {
			$zip->addFile($file['abs_path'],$file['rel_path']);
		}
		//debug
		//echo 'The zip archive contains ',$zip->numFiles,' files with a status of ',$zip->status;

		//close the zip -- done!
		$zip->close();

		//check to make sure the file exists
		return file_exists($destination);
	}
	else
	{
		return false;
	}
}

function gen_array ($quid) {

	$anzahl = trans_field_count($quid);
	$raus = array();

	if ( $anzahl == 0 ) return $raus;

	for ( $t=0; $t<=$anzahl -1 ; $t++) {
		$feldname = trim(trans_field_name($t,$quid));
		// $feld_inhalt = trans_field_value($t,$quid);

		if ( trans_field_type ( $t,$quid ) == 4) {
			$raus[$feldname] = trim(trans_field_value($t,$quid));
		} else {
			$raus[$feldname] = trim(trans_field_value($t,$quid)+0);
		}
	}
	return $raus;
}

function firstkw($jahr) {
	$erster = mktime(0,0,0,1,1,$jahr);
	$wtag = date('w',$erster);

	if ($wtag <= 4) {
		/**
* Donnerstag oder kleiner: auf den Montag zur�ckrechnen.
*/
		$montag = mktime(0,0,0,1,1-($wtag-1),$jahr);
	} else {
		/**
* auf den Montag nach vorne rechnen.
*/
		$montag = mktime(0,0,0,1,1+(7-$wtag+1),$jahr);
	}
	return $montag;
}

function mondaykw($kw,$jahr) {
	$firstmonday = firstkw($jahr);
	$mon_monat = date('m',$firstmonday);
	$mon_jahr = date('Y',$firstmonday);
	$mon_tage = date('d',$firstmonday);

	$tage = ($kw-1)*7;

	$mondaykw = mktime(0,0,0,$mon_monat,$mon_tage+$tage,$mon_jahr);
	return $mondaykw;
}

function monthfirstday($month, $year){
    return mktime(0, 0, 1, $month, 1, $year);
}

function monthlastday($month, $year){
    return strtotime('-1 second', strtotime('+1 month', strtotime($month.'/01/'.$year.' 00:00:00')));
}

function thursdaykw($kw,$jahr,$plusminus='-') {
	$firstmonday = firstkw($jahr);
	$mon_monat = date('m',$firstmonday);
	$mon_jahr = date('Y',$firstmonday);
	$mon_tage = date('d',$firstmonday);

	$tage = ($kw-1)*7;

	if($plusminus=='+') {
		$mondaykw = mktime(0,0,0,$mon_monat,$mon_tage+$tage+3,$mon_jahr);
	}
	elseif ($plusminus=='-') {
		$mondaykw = mktime(0,0,0,$mon_monat,$mon_tage+$tage-3,$mon_jahr);
	}
	else {
		$mondaykw = mktime(0,0,0,$mon_monat,$mon_tage+$tage,$mon_jahr);
	}
	return $mondaykw;
}

function gen_in_list_emi ( $in_array ) {

    // $out = ' ( ';
    $out = '';
    $counter=0;

    foreach ( $in_array as $element ) {

        $counter++;

        if ( $counter == 1 ) {
            $out.= "'".$element."%'";
        } else {
            $out.= "and '".$element."%'";
        }
    }

    // $out.=')';
    return $out;
}

function array_utf_to_iso($arr){
    $r = array();
    foreach($arr as $key => $var){
        $r[$key] = utf8_decode($var);
    }
    return $r;
}

function freierTag($tag, $monat, $jahr) {

   // Parameter in richtiges Format bringen
   if(strlen($tag) == 1) {
      $tag = "0$tag";
   }
   if(strlen($monat) == 1) {
      $monat = "0$monat";
   }

   // Wochentag berechnen
   $datum = getdate(mktime(0, 0, 0, $monat, $tag, $jahr));
   $wochentag = $datum['wday'];

   // Prüfen, ob Sonntag
   if($wochentag == 0) {
      return true;
   }

   // Feste Feiertage werden nach dem Schema ddmm eingetragen
   $feiertage[] = "0101"; // Neujahrstag
   $feiertage[] = "0105"; // Tag der Arbeit
   $feiertage[] = "0310"; // Tag der Deutschen Einheit
   $feiertage[] = "2512"; // Erster Weihnachtstag
   $feiertage[] = "2612"; // Zweiter Weihnachtstag

   // Bewegliche Feiertage berechnen
   $tage = 60 * 60 * 24;
   $ostersonntag = easter_date($jahr);
   $feiertage[] = date("dm", $ostersonntag - 2 * $tage);  // Karfreitag
   $feiertage[] = date("dm", $ostersonntag + 1 * $tage);  // Ostermontag
   $feiertage[] = date("dm", $ostersonntag + 39 * $tage); // Himmelfahrt
   $feiertage[] = date("dm", $ostersonntag + 50 * $tage); // Pfingstmontag

   // Prüfen, ob Feiertag
   $code = $tag.$monat;
   if(in_array($code, $feiertage)) {
      return true;
   } else {
      return false;
   }
}

?>
