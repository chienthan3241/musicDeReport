<?php 
require_once(dirname(__FILE__).'/../lib/global.php');

require_once('MDB2.php');
require_once(CFG_PATH.'db.conf.php');

require_once 'Mail.php';
require_once 'Mail/mime.php';
require_once 'MIME/Type.php';
/////////////Funtions////////////


function fwritecsv($handle, $fields, $delimiter = "\t") {
    # Check if $fields is an array
    if (!is_array($fields) || empty($fields)) {
        return false;
    }    
    # Combine the data array with $delimiter and write it to the file
    $line = implode($delimiter, $fields) . "\n";
    fwrite($handle, $line);
    # Return the length of the written data
    return strlen($line);
}

function get_max_chart_zeitkey($modul_id){
	global $exasol_dsn;
	$db = MDB2::connect($exasol_dsn);
	if (MDB2::isError($db)) {
		die ($db->getMessage());
	}
	$qry = "SELECT max(to_number(th.ausw_zr_jahr || LPAD(th.ausw_zr_wmq, 2, 00))) AS ID 
			  FROM
				mcchrth th
			  WHERE
				th.land = 'DE' AND
				th.ausw_typ = 'CHART' AND
				th.frei_datumzeit_erst <= TO_CHAR(SYSTIMESTAMP, 'YYYY-MM-DD HH24:MI:SS') and
				th.ausw_zr_jahr <= to_char(sysdate, 'YYYY') and
				th.ausw_zr_jahr != 0 and
				th.modul_id = $modul_id and 
				th.ausw_zeitraum_einheit = 'W'
			ORDER BY 
				to_number(th.ausw_zr_jahr || LPAD(th.ausw_zr_wmq, 2, 00)) DESC";
	$rs = $db->query($qry);
	$data_key = $rs->fetchRow(MDB2_FETCHMODE_ASSOC);
	return $data_key['id'];
}

function get_chartBMG_last_update_zeitkey($lastupdatefile){
	if(is_readable($lastupdatefile)){
        $fhandle = fopen($lastupdatefile, 'r');
        if($fhandle){
            $zeitkey = fread($fhandle, 6);
        }
        fclose($fhandle);
        if(preg_match('/\d{6}/', $zeitkey)){
            return $zeitkey;
        }
    }
    return false;
}

function update_chartBMG_last_update_zeitkey($lastupdatefile, $zeitkey){
    $fhandle = fopen($lastupdatefile, 'w');
    $r = fwrite($fhandle, $zeitkey);
    fclose($fhandle);
    return $r;
}

function convert_zeitekey($zeitkey,$zeit_unit){
	$zeitkey = trim($zeitkey);
	switch (strtoupper($zeit_unit)) {
		case 'W':
			$week = substr($zeitkey, -2);
			$year = substr($zeitkey, 0, strlen($zeitkey) -2);
			if(substr($week, 0,1)=='0'){
				$week = substr($week, 1);
			}
			return $week .'.'. $year;
			break;
		case 'M':
			$month = substr($zeitkey, -2);
			$year = substr($zeitkey, 0, strlen($zeitkey) -2);
			if(substr($month, 0,1)=='0'){
				$month = substr($month, 1);
			}
			return $month .'.'. $year;
			break;
		case 'D':
			$day = substr($zeitkey, -2);
			$month = substr($zeitkey, 4,2);
			$year = substr($zeitkey, 0, 4);
			if(substr($day, 0,1)=='0'){
				$day = substr($day, 1);
			}
			return $day .'.'.$month.'.'.$year;
			break;
		default:
			return $zeitkey;
			break;
	}
}
/////////////END Funtions////////////
$is_error = false;

$dsn = $exasol_dsn;
$db = MDB2::connect($dsn);
if (MDB2::isError($db)) {
	$par['empfaenger'] = $reports_error_recipients;
	$par['message'] = array('subject'=>"Error FTP-Daten BMG Charts",
        'body_txt'=>"(Exasol) can not connect to the database.\n");
	//sendEMail($par);
	die ($db->getMessage());
}
$modul_arr=array(
	101 => 'Single',
	102 => 'Longplay',
	103 => 'Compilation');
foreach ($modul_arr as $modul=>$chartname) {
	$zeit = get_max_chart_zeitkey($modul);
 
	echo "check zeitkey...\n";
	$lastupdatefile	= $nfs_root_dir."BMGRIGHTS_CHARTS_".$modul.".txt";
	$last_time = get_chartBMG_last_update_zeitkey($lastupdatefile);
	
	if($last_time){
	    echo "... found. it's $last_time\n";
	    if($last_time < $zeit){
	        echo "seems we have new week data.\n";
	    }else{
	        echo "No new week data, aborting.\n";
	        continue;
	    }
	}else{
	    echo "... not found. Generating data for the newst week.\n";
	}
	$jahr2 = substr($zeit,2,2);
	$jahr4 = substr($zeit,0,4);
	$woche = substr($zeit,-2,2);
	$chart_zeitkey = convert_zeitekey($zeit,'W');
	$cond = "";
	$limit = 0;
	switch ($modul) {
		case 101:
			$cond = " SUBSTR(m2.code,0,length(m2.code)-1) as code ";
			$limit = 100;
			break;
		case 102:
			$cond = " m2.code ";
			$limit = 100;
			break;
		case 103:
		default:
			$cond = " m2.code ";
			$limit = 30;
			break;
	}

	$sql = "WITH chart_qr as (SELECT
				NVL(Platzierung, 0) AS platzierung,
				NVL(code, ' ') AS code
			FROM
				(
					SELECT
						TO_NUMBER(Platzierung) AS Platzierung,			
						$cond
					FROM
						mcchart m2,
						mcchrth h2
					WHERE
						h2.Modul_Id = $modul AND
						h2.Ausw_Zeitraum = $chart_zeitkey AND
						h2.Ausw_Zeitraum_Einheit = 'W' AND
						m2.referenz = h2.referenz
					ORDER BY
						1
				)
			WHERE
				Platzierung <= $limit)
		SELECT 
			chart_qr.code as code,
			stamm_titel,
			stamm_artist,
			stamm_eanc,
			chart_qr.platzierung as platzierung,
			stamm_herst_txt,
			stamm_label_txt,
			stamm_ag_txt,
			stamm_wg_txt,
			stamm_vdatum
		FROM 
			chart_qr,
			stamm_gesamt
		WHERE
			chart_qr.code = stamm_gesamt.stamm_archivnr
			AND stamm_landid = 1054 
			AND stamm_appid = 12
			AND stamm_mainprod_header = 1
		ORDER BY chart_qr.platzierung asc
		";
echo "Querying modul $modul, $zeit...\n";
$arr = array();
$data = array();
$ct = 0;
$rs=$db->query($sql);

if (MDB2::isError($rs)) {
    dbug('error', $sql);
    $is_error = true;
    $error_msg .= 'Database connection failed.\n';
} else {
    while($arr= $rs->fetchRow(MDB2_FETCHMODE_ASSOC)) {
        if ($arr) {            
            $arr = array_utf_to_iso($arr);

            $data[$ct]['code']	= $arr['code'];//archivnr
            $data[$ct]['stamm_titel']	= (isset($arr['stamm_titel']) and strlen($arr['stamm_titel']) > 0) ? $arr['stamm_titel'] : 'N/A';
            $data[$ct]['stamm_artist']	= (isset($arr['stamm_artist']) and strlen($arr['stamm_artist']) > 0) ? $arr['stamm_artist'] : 'N/A';
            $data[$ct]['stamm_eanc']	= $arr['stamm_eanc'];//EAN
            $data[$ct]['platzierung']	= $arr['platzierung'];//Chartposition
            $data[$ct]['stamm_herst_txt'] 	= $arr['stamm_herst_txt'];//Vertrieb
            $data[$ct]['stamm_label_txt'] 	= $arr['stamm_label_txt'];//Label
            $data[$ct]['stamm_ag_txt'] 	= $arr['stamm_ag_txt'];//Tonträger-Text
            $data[$ct]['stamm_wg_txt'] 	= $arr['stamm_wg_txt'];//Genre-Text
            $data[$ct]['stamm_vdatum'] 	= formatdateDDMMYYYY($arr['stamm_vdatum']);//VÖ-Datum            
        }
        $ct++;
    }
    $rs->free();
}
echo "Schreibe $chartname BMG-File...\n";
$file_name = "top_".$limit."_".$chartname."_".$jahr2.'KW'.$woche.".csv";
$file = fopen($nfs_root_dir.'temp/'.$file_name,"w");
foreach ($data as $key => $value) {
	fwritecsv($file,$value);
}
fclose($file);
//upload to FTP
echo "begin upload to FTP...\n";
$ftp = new FTP;
$ftp->connect($ftp_host);
$ftp->login($ftp_username, $ftp_password);
if ( ! $ftp->put($target_path_de . '/BMGRIGHTS/' . $file_name,
    	$nfs_root_dir.'temp/'.$file_name, FTP_BINARY)){
  	echo "failed to upload: " . $file_name . "\n";
  	$is_error = true;
  }
echo "Updating $lastupdatefile ...\n";
  if(!update_chartBMG_last_update_zeitkey($lastupdatefile, $zeit)){
      echo "WARNING: failed to update last-update zeitkey ($zeit).\n";
  }
//email benachrichtigung
if($is_error == true) {
        $par['empfaenger'] = $reports_error_recipients;
        $par['message'] = array('subject'=>"(BMG RIGHTS) Error FTP-Daten Wochen Charts $file_name",'body_txt'=>"Bei Generierung FTP-Daten (WOCHEN Daten, BMG Rights, $file_name) Fehler aufgetreten\n");
    } else {
        $par['empfaenger'] = $reports_normal_recipients;
        $par['message'] = array('subject'=>"(BMG RIGHTS) Erfolg FTP-Daten Wochen Charts $file_name",'body_txt'=>"FTP-Daten (WOCHEN Daten, BMG Rights, $file_name ) generiert und verteilt.\n");
    }
    sendEMail($par);
unlink($nfs_root_dir.'temp/'.$file_name);
} 


$db->disconnect();

?>