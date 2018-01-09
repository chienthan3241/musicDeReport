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

function get_sf_last_update_zeitkey($lastupdatefile){
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

function update_sf_last_update_zeitkey($lastupdatefile, $zeitkey){
    $fhandle = fopen($lastupdatefile, 'w');
    $r = fwrite($fhandle, $zeitkey);
    fclose($fhandle);
    return $r;
}
/////////////END Funtions////////////
$is_error = false;
$error_msg = '';

$dsn = $exasol_dsn;
$db = MDB2::connect($dsn);
if (MDB2::isError($db)) {
	$par['empfaenger'] = $reports_error_recipients;
	$par['message'] = array('subject'=>"Error FTP-Daten SOULFOOD Top 5000",
        'body_txt'=>"(Exasol) can not connect to the database.\n");
	//sendEMail($par);
	die ($db->getMessage());
}
/////////////////
//physical data//
/////////////////
//select max wochen 
$qry = "select max(zeit_key) as max_zeit from zeitraum_gui where zeit_typeflag = 'P' and zeit_einheit = 'W' and zeit_landid = 1054";
$rs = $db->query($qry);
$data_key = $rs->fetchRow(MDB2_FETCHMODE_ASSOC);
$zeit = $data_key['max_zeit'];
#$zeit = '201505';
$lastupdatefile	= $nfs_root_dir."SOULFOOD_TOP5000_PHYS.txt";
$last_time = get_sf_last_update_zeitkey($lastupdatefile);
if(($last_time && $last_time < $zeit) || !$last_time){
	echo "seems we have new week PHYS data.\n";
	$jahr2 = substr($zeit,2,2);
	$jahr4 = substr($zeit,0,4);
	$woche = substr($zeit,-2,2);

	$sql = "with
		fact_query AS(
			SELECT
				stamm_eancid eancid,
				sum(bwg_menge) sum_meng,
				sum(bwg_wert) sum_wert,
				row_number() over(order by sum(bwg_wert) DESC) index_
			FROM
				stamm_gesamt,
				region,
				bewegung_W,
				zeitraum_W
			WHERE
				bwg_haendlerid = region_haendlerid AND
				bwg_eancid = stamm_eancid AND
				bwg_zeitkey = zeit_key AND
				zeit_key = $zeit
				AND bwg_landid = 1054 AND
				stamm_landid = 1054 AND
				stamm_lauf = 'DE' AND
				bwg_appid = 12 AND
				stamm_appid = 12 AND
				region_appid = 12 AND
				bwg_cont_dist = 641 AND
				stamm_type_flag = 'P' AND
				bwg_typeflag = 'P' AND
				NVL(stamm_quelle, 'EMPTY') <> 'MA' AND
				stamm_landid = 1054 AND
				stamm_firm_code = '8415' AND
				stamm_musikfremd = 0 AND
				STAMM_AG_PHY_DIGI = 'P' AND
				region_awid not in (243, 361, 2)
			group by
				stamm_eancid
		),
		rank_query AS(
			SELECT
				*
			FROM
				fact_query
			WHERE
				index_ between 1 AND
				5000
		),
		voe_query AS(
			SELECT
				stamm_eancid voe_eancid,
				sum(bwg_menge) voe_meng,
				sum(bwg_wert) voe_wert
			FROM
				bewegung_W,
				stamm_gesamt,
				region,
				rank_query
			WHERE
				stamm_eancid = eancid AND
				stamm_eancid = bwg_eancid AND
				stamm_lauf = 'DE' AND
				region_haendlerid = bwg_haendlerid AND
				stamm_landid = 1054 AND
				bwg_landid = 1054 AND
				NVL(stamm_quelle, 'EMPTY') <> 'MA' AND
				bwg_cont_dist = 641 AND
				bwg_typeflag = 'P' AND
				bwg_appid = 12 AND
				stamm_appid = 12 AND
				region_appid = 12 AND
				stamm_type_flag = 'P' AND
				NVL(stamm_quelle, 'EMPTY') <> 'MA' AND
				stamm_landid = 1054 AND
				stamm_firm_code = '8415' AND
				stamm_musikfremd = 0 AND
				STAMM_AG_PHY_DIGI = 'P' AND
				region_awid not in (243, 361, 2)
			group by
				stamm_eancid
		)
	SELECT
		stamm_titel,
		stamm_artist,
		stamm_eanc,
		stamm_artnr,
		stamm_wg_txt,
		case length(stamm_wg_code)
		when 5 then SUBSTR(stamm_wg_code,3)
		else 0
		end as stamm_wg_code,
		stamm_ag_txt,
		stamm_ag_code,
		stamm_label_txt,
		stamm_herst_txt,
		stamm_archivnr,
		stamm_vdatum,
		round(sum_meng, 0) sum_meng,
		round(sum_wert, 2) sum_wert,
		round(voe_meng, 0) voe_meng,
		round(voe_wert, 2) voe_wert
	FROM
		rank_query,
		stamm_gesamt,
		voe_query
	WHERE
		rank_query.eancid = stamm_eancid AND
		rank_query.eancid = voe_eancid AND
		stamm_landid = 1054 AND
		stamm_lauf = 'DE' AND
		stamm_type_flag = 'P'
	order by
		index_ asc";

	echo "Querying phys. $zeit...\n";

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
	            
	            $data[$ct]['stamm_titel']	= (isset($arr['stamm_titel']) and strlen($arr['stamm_titel']) > 0) ? $arr['stamm_titel'] : 'N/A';
	            $data[$ct]['stamm_artist']	= (isset($arr['stamm_artist']) and strlen($arr['stamm_artist']) > 0) ? $arr['stamm_artist'] : 'N/A';
	            $data[$ct]['stamm_eanc']	= $arr['stamm_eanc'];//EAN
	            #ISRC could sometimes be null. in this case, simply fill it with 13 times 9
	            $data[$ct]['ISRC']          = (isset($arr['stamm_artnr']) and strlen($arr['stamm_artnr']) > 0) ? $arr['stamm_artnr'] : '9999999999999';//Katalognummer
	            $data[$ct]['stamm_wg_txt'] 	= $arr['stamm_wg_txt'];//Genre-Text
	            $data[$ct]['stamm_wg_code'] = $arr['stamm_wg_code'];//Genre-Code 
	            $data[$ct]['stamm_ag_txt'] 	= $arr['stamm_ag_txt'];//TontrÃ¤ger-Text
	            $data[$ct]['stamm_ag_code'] 	= $arr['stamm_ag_code'];//TontrÃ¤ger-Code
	            $data[$ct]['stamm_label_txt'] 	= $arr['stamm_label_txt'];//Label
	            $data[$ct]['stamm_herst_txt'] 	= $arr['stamm_herst_txt'];//Vertrieb
	            $data[$ct]['stamm_archivnr'] 	= $arr['stamm_archivnr'];//MCArchivnummer
	            $data[$ct]['stamm_vdatum'] 	= formatdateDDMMYYYY($arr['stamm_vdatum']);//VÃ–-Datum
	            $data[$ct]['sum_meng'] 		= $arr['sum_meng'];//Units der aktuellen Woche
	            $data[$ct]['sum_wert'] 		= $arr['sum_wert'];//Value der aktuellen Woche
	            $data[$ct]['voe_meng'] 		= $arr['voe_meng'];//Units Ã¼ber alle verkauften Wochen des Artikels
	            $data[$ct]['voe_wert'] 		= $arr['voe_wert'];//Value Ã¼ber alle verkauften Wochen des Artikels
	        }
	        $ct++;
	    }
	    $rs->free();
	}

	echo "Schreibe phys. SOULFOOD-File...\n";
	$ftp_top5000_phys = "top5000_phys_".$jahr2.'KW'.$woche.".csv";
	$top5000_phys = "top5000_phys_SOULFOOD".$jahr2.'KW'.$woche.".csv";
	$file = fopen($nfs_root_dir.'temp/'.$top5000_phys,"w");
	$uebrschrift = array('Titel',
						'Interpret',
						'EAN/ISRC',
						'Katalognummer',
						'Genre-Text',
						'Genre-Code',
						'Tonträger-Text',
						'Tonträger-Code',
						'Label',
						'Vertrieb',
						'MCArchivnummer',
						'VÖ-Datum',
						'Units',
						'Value',
						'Units seit VÖ',
						'Value seit VÖ'
						);
	fwritecsv($file,$uebrschrift);
	foreach ($data as $key => $value) {
		fwritecsv($file,$value);
	}
	fclose($file);
	//upload to FTP
	echo "begin upload to FTP...\n";
	$ftp = new FTP;
	$ftp->connect($ftp_host);
	$ftp->login($ftp_username, $ftp_password);
	if ( ! $ftp->put($target_path_de . '/Soulfood/' . $ftp_top5000_phys,
	    	$nfs_root_dir.'temp/'.$top5000_phys, FTP_BINARY)){
	  	echo "failed to upload: " . $top5000_phys . "\n";
	  	$is_error = true;
	  }
	echo "Updating $lastupdatefile ...\n";
	  if(!update_sf_last_update_zeitkey($lastupdatefile, $zeit)){
	      echo "WARNING: failed to update last-update zeitkey ($zeit).\n";
	  }
	//email benachrichtigung
	if($is_error == true) {
	        $par['empfaenger'] = $reports_error_recipients;
	        $par['message'] = array('subject'=>"(SOULFOOD) Error FTP-PHYS Daten Wochen $top5000_phys",'body_txt'=>"Bei Generierung FTP-Daten (WOCHEN PHYS Daten, SOULFOOD, $top5000_phys) Fehler aufgetreten\n");

	    } else {
	        $par['empfaenger'] = $reports_normal_recipients;
	        $par['message'] = array('subject'=>"(SOULFOOD) Erfolg FTP-PHYS Daten Wochen $top5000_phys",'body_txt'=>"FTP-Daten (WOCHEN PHYS Daten, SOULFOOD, $top5000_phys ) generiert und verteilt.\n");
	    }
	    sendEMail($par);
	unlink($nfs_root_dir.'temp/'.$top5000_phys); 
}else{
	echo "... No new week PHYS data, aborting.\n";
}


/////////////////
//digital  data//
/////////////////
//select max wochen 
/* 19.11.2014: ist momentant nicht freigegeben da SOULFOOD hat kein eingenen DWN-Vertrieb. 
Wird aber ab dem n�chsten Jahr freigeben. HD :28593*/

$qry = "select max(zeit_key) as max_zeit from zeitraum_gui where zeit_typeflag = 'D' and zeit_einheit = 'W' and zeit_landid = 1054";
$rs = $db->query($qry);
$data_key = $rs->fetchRow(MDB2_FETCHMODE_ASSOC);
$zeit = $data_key['max_zeit'];
#$zeit='201508';
$lastupdatefile	= $nfs_root_dir."SOULFOOD_TOP5000_DWN.txt";
$last_time = get_sf_last_update_zeitkey($lastupdatefile);
if(($last_time && $last_time < $zeit) || !$last_time){
	echo "seems we have new week DWN data.\n";
	$jahr2 = substr($zeit,2,2);
	$jahr4 = substr($zeit,0,4);
	$woche = substr($zeit,-2,2);

	$sql = "with
		fact_query AS(
			SELECT
				stamm_prodnr prodnr,
				sum(bwg_menge) sum_meng,
				sum(bwg_wert) sum_wert,
				row_number() over(order by sum(bwg_wert) DESC) index_
			FROM
				stamm_gesamt,
				region,
				bewegung_W,
				zeitraum_W
			WHERE
				bwg_haendlerid = region_haendlerid AND
				bwg_eancid = stamm_eancid AND
				bwg_zeitkey = zeit_key AND
				zeit_key = $zeit
				AND bwg_landid = 1054 AND
				stamm_landid = 1054 AND
				stamm_lauf = 'DWN' AND
				bwg_appid = 12 AND
				stamm_appid = 12 AND
				region_appid = 12 AND
				bwg_cont_dist = 645 AND
				stamm_type_flag = 'D' AND
				bwg_typeflag = 'D' AND
				NVL(stamm_quelle, 'EMPTY') <> 'MA' AND
				stamm_landid = 1054 AND
				stamm_musikfremd = 0 AND
				STAMM_AG_PHY_DIGI = 'D' AND
				stamm_prodnr in (
					select distinct stamm_prodnr from stamm_gesamt
					where stamm_landid = 1054 and stamm_appid = 12 
					and stamm_lauf = 'DWN' and stamm_type_flag = 'D'
					and stamm_match_code in (
						select distinct stamm_match_code from stamm_gesamt
						where stamm_landid = 1054 and stamm_firm_code = 8415
						and stamm_appid = 12
						)
				) AND
				region_awid not in (243, 361, 2)
			group by
				stamm_prodnr
		),
		rank_query AS(
			SELECT
				*
			FROM
				fact_query
			WHERE
				index_ between 1 AND
				5000
		),
		voe_query AS(
			SELECT
				stamm_prodnr voe_prodnr,
				sum(bwg_menge) voe_meng,
				sum(bwg_wert) voe_wert
			FROM
				bewegung_W,
				stamm_gesamt,
				region,
				rank_query
			WHERE
				stamm_prodnr = prodnr AND
				stamm_eancid = bwg_eancid AND
				stamm_lauf = 'DWN' AND
				region_haendlerid = bwg_haendlerid AND
				stamm_landid = 1054 AND
				bwg_landid = 1054 AND
				NVL(stamm_quelle, 'EMPTY') <> 'MA' AND
				bwg_cont_dist = 645 AND
				bwg_typeflag = 'D' AND
				bwg_appid = 12 AND
				stamm_appid = 12 AND
				region_appid = 12 AND
				stamm_type_flag = 'D' AND
				NVL(stamm_quelle, 'EMPTY') <> 'MA' AND
				stamm_landid = 1054 AND
				stamm_musikfremd = 0 AND
				STAMM_AG_PHY_DIGI = 'D' AND
				region_awid not in (243, 361, 2)
			group by
				stamm_prodnr
		)
	SELECT
		stamm_titel,
		stamm_artist,
		stamm_eanc,
		stamm_artnr,
		stamm_wg_txt,
		case length(stamm_wg_code)
		when 5 then SUBSTR(stamm_wg_code,3)
		else 0
		end as stamm_wg_code,
		stamm_ag_txt,
		stamm_ag_code,
		stamm_label_txt,
		stamm_herst_txt,
		stamm_archivnr,
		stamm_vdatum,
		round(sum_meng, 0) sum_meng,
		round(sum_wert, 2) sum_wert,
		round(voe_meng, 0) voe_meng,
		round(voe_wert, 2) voe_wert
	FROM
		rank_query,
		stamm_gesamt,
		voe_query
	WHERE
		rank_query.prodnr = stamm_prodnr AND
		stamm_is_header = 1 AND
		rank_query.prodnr = voe_prodnr AND
		stamm_landid = 1054 AND
		stamm_lauf = 'DWN' AND
		stamm_type_flag = 'D'
	order by
		index_ asc";

	echo "Querying digi. $zeit...\n";

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
	            
	            $data[$ct]['stamm_titel']	= (isset($arr['stamm_titel']) and strlen($arr['stamm_titel']) > 0) ? $arr['stamm_titel'] : 'N/A';
	            $data[$ct]['stamm_artist']	= (isset($arr['stamm_artist']) and strlen($arr['stamm_artist']) > 0) ? $arr['stamm_artist'] : 'N/A';
	            $data[$ct]['stamm_eanc']	= $arr['stamm_eanc'];//EAN
	            #ISRC could sometimes be null. in this case, simply fill it with 13 times 9
	            $data[$ct]['ISRC']          = (isset($arr['stamm_artnr']) and strlen($arr['stamm_artnr']) > 0) ? $arr['stamm_artnr'] : '9999999999999';//Katalognummer
	            $data[$ct]['stamm_wg_txt'] 	= $arr['stamm_wg_txt'];//Genre-Text
	            $data[$ct]['stamm_wg_code'] = $arr['stamm_wg_code'];//Genre-Code 
	            $data[$ct]['stamm_ag_txt'] 	= $arr['stamm_ag_txt'];//TontrÃ¤ger-Text
	            $data[$ct]['stamm_ag_code'] 	= $arr['stamm_ag_code'];//TontrÃ¤ger-Code
	            $data[$ct]['stamm_label_txt'] 	= $arr['stamm_label_txt'];//Label
	            $data[$ct]['stamm_herst_txt'] 	= $arr['stamm_herst_txt'];//Vertrieb
	            $data[$ct]['stamm_archivnr'] 	= $arr['stamm_archivnr'];//MCArchivnummer
	            $data[$ct]['stamm_vdatum'] 	= formatdateDDMMYYYY($arr['stamm_vdatum']);//VÃ–-Datum
	            $data[$ct]['sum_meng'] 		= $arr['sum_meng'];//Units der aktuellen Woche
	            $data[$ct]['sum_wert'] 		= $arr['sum_wert'];//Value der aktuellen Woche
	            $data[$ct]['voe_meng'] 		= $arr['voe_meng'];//Units Ã¼ber alle verkauften Wochen des Artikels
	            $data[$ct]['voe_wert'] 		= $arr['voe_wert'];//Value Ã¼ber alle verkauften Wochen des Artikels
	        }
	        $ct++;
	    }
	    $rs->free();
	}

	echo "Schreibe digi. SOULFOOD-File...\n";
	$ftp_top5000_digi = "top5000_digi_".$jahr2.'KW'.$woche.".csv";
	$top5000_digi = "top5000_digi_SOULFOOD_".$jahr2.'KW'.$woche.".csv";
	$file = fopen($nfs_root_dir.'temp/'.$top5000_digi,"w");
	$uebrschrift = array('Titel',
						'Interpret',
						'EAN/ISRC',
						'Katalognummer',
						'Genre-Text',
						'Genre-Code',
						'Tonträger-Text',
						'Tonträger-Code',
						'Label',
						'Vertrieb',
						'MCArchivnummer',
						'VÖ-Datum',
						'Units',
						'Value',
						'Units seit VÖ',
						'Value seit VÖ'
						);
	fwritecsv($file,$uebrschrift);
	foreach ($data as $key => $value) {
		fwritecsv($file,$value);
	}
	fclose($file);
	//upload to FTP
	echo "begin upload to FTP...\n";
	$ftp = new FTP;
	$ftp->connect($ftp_host);
	$ftp->login($ftp_username, $ftp_password);
	if ( ! $ftp->put($target_path_de . '/soulfood/' . $ftp_top5000_digi,
	    	$nfs_root_dir.'temp/'.$top5000_digi, FTP_BINARY)){
	  	echo "failed to upload: " . $top5000_digi . "\n";
	  	$is_error = true;
	  }
	echo "Updating $lastupdatefile ...\n";
	  if(!update_sf_last_update_zeitkey($lastupdatefile, $zeit)){
	      echo "WARNING: failed to update last-update zeitkey ($zeit).\n";
	  }
	//email benachrichtigung	
	if($is_error == true) {
	        $par['empfaenger'] = $reports_error_recipients;
	        $par['message'] = array('subject'=>"(SOULFOOD) Error FTP-DWN Daten Wochen $top5000_digi",'body_txt'=>"Bei Generierung FTP-Daten (WOCHEN DWN Daten, SOULFOOD, $top5000_digi) Fehler aufgetreten\n");
		
	    } else {
	        $par['empfaenger'] = $reports_normal_recipients;
	        $par['message'] = array('subject'=>"(SOULFOOD) Erfolg FTP-DWN Daten Wochen $top5000_digi",'body_txt'=>"FTP-Daten (WOCHEN DWN Daten, SOULFOOD, $top5000_digi ) generiert und verteilt.\n");
	    }
	    sendEMail($par);
	unlink($nfs_root_dir.'temp/'.$top5000_digi); 
}else{
	echo "... No new week DWN data, aborting.\n";
}

	$db->disconnect();
?>
