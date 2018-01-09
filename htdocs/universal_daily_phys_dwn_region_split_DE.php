<?php 

require_once(dirname(__FILE__).'/../lib/global.php');
require_once('MDB2.php');
require_once(CFG_PATH.'db.conf.php');
require_once 'Mail.php';
require_once 'Mail/mime.php';
require_once 'MIME/Type.php';

//Abbruch durch Benutzer ignorieren
#ignore_user_abort(true);

$is_error = false;

$dsn = $exasol_dsn;

$db = MDB2::connect($dsn);

if (MDB2::isError($db)) {
	$par['empfaenger'] = $reports_error_recipients;
	$par['message'] = array('subject'=>"(Exasol) Error FTP-Daten Downloads (UNIVERSAL TAGESDATEN REGION SPLIT) ",
       'body_txt'=>"Bei Generierung FTP-Daten (UNIVERSAL TAGESDATEN REGION SPLIT) Fehler aufgetreten:\n can not connect to database.");

	sendEMail($par);

	die ($db->getMessage());
}

function get_last_update_universal_daily_region_split_zeitkey($lastupdatefile){
    if(is_readable($lastupdatefile)){
        $fhandle = fopen($lastupdatefile, 'r');
        if($fhandle){
            $zeitkey = fread($fhandle, 8);
        }
        fclose($fhandle);
        if(preg_match('/\d{8}/', $zeitkey)){
            return $zeitkey;
        }
    }
    return false;
}

function update_last_update_universal_daily_region_split_zeitkey($lastupdatefile, $zeitkey){
    $fhandle = fopen($lastupdatefile, 'w');
    $r = fwrite($fhandle, $zeitkey);
    fclose($fhandle);
    return $r;
}


$lastupdatefile = $nfs_root_dir . '/universal_daily_region_split_last_update.txt';

$zeit_keys = array();

if(!isset($_REQUEST['zeitid'])) {
    //check last tagesdata date.
    echo "checking last updated zeit_key...\n";
    $last_time = get_last_update_universal_daily_region_split_zeitkey($lastupdatefile);
	
    if($last_time){
        echo "... found. it's $last_time\n";
    }
	
    echo "querying avalaible zeit_keys...\n";
    
    $qry = "with base_sql as (
				select * from (
				select
					zeit_key,
					'F' as check_feier
				from
					zeitraum_gui
				where
					zeit_einheit = 'T' AND
					zeit_landid = '1054' AND
					zeit_appid = 12 and
					zeit_typeflag = 'D' and
					zeit_key <= (
						select
							max(zeit_key)
						from
							zeitraum_gui
						where
							zeit_einheit = 'T' AND
							zeit_landid = '1054' AND
							zeit_appid = 12 and
							zeit_typeflag in ('P', 'D')
					) order by zeit_key desc
				) where rownum < 6
				)
				select * from (
				select
					zeit_key,
					'T' as check_feier
				from
					zeitraum_gui
				where
					zeit_einheit = 'T' AND
					zeit_landid = '1054' AND
					zeit_appid = 12 and
					zeit_typeflag = 'D' and
					zeit_key > (select max (zeit_key) from base_sql)
				union
				 select * from base_sql)
				order by zeit_key desc";
    $rs = $db->query($qry);
    while($data = $rs->fetchRow(MDB2_FETCHMODE_ASSOC)){
    	if(!$last_time){
    		if($data['check_feier'] == 'F'){
    			$zeit_keys[] = $data['zeit_key'];
    		}else{
    			$subTag = substr($data['zeit_key'], -2);
    			$subMonth = substr($data['zeit_key'], -4, 2);
    			$subYear = substr($data['zeit_key'], 0, 4);
    			if(freierTag($subTag, $subMonth, $subYear)){
    				$zeit_keys[] = $data['zeit_key'];
    			}
    		}
    	}elseif($data['zeit_key'] > $last_time){
			if($data['check_feier'] == 'F'){
    			$zeit_keys[] = $data['zeit_key'];
    		}else{
    			$subTag = substr($data['zeit_key'], -2);
    			$subMonth = substr($data['zeit_key'], -4, 2);
    			$subYear = substr($data['zeit_key'], 0, 4);
    			if(freierTag($subTag, $subMonth, $subYear)){
    				$zeit_keys[] = $data['zeit_key'];
    			}
    		}
    	}
    }
	    
    $zeit_keys = array_reverse($zeit_keys);
} else {
	$zeit_keys[] = $_REQUEST['zeitid'];
}

#$zeit_keys = array('20150410','20150411','20150412');

foreach($zeit_keys as $zeit){

    echo "generating tagesdata for $zeit ...\n";

    $jahr4 = substr($zeit,0,4);
    $monate2 = substr($zeit,-4,2);
    $tag2 = substr($zeit,-2,2);

    $sql = "
      with
		dwn_query as (
			SELECT
				stamm_match_code dwn_match_code,
				sum(bwg_menge) dwn_meng,
				sum(bwg_wert) dwn_wert,
				case region_id when 1097 then 1760 else region_id end as dwn_region_id
			FROM
				stamm_gesamt,
				region,
				bewegung_T,
				zeitraum_T
			WHERE
				bwg_haendlerid = region_haendlerid AND
				bwg_eancid = stamm_eancid AND
				bwg_zeitkey = zeit_key AND
				zeit_key >= $zeit AND
				zeit_key <= $zeit AND
				bwg_landid = 1054 AND
				bwg_appid = 12 AND
				region_appid = 12 AND
				stamm_appid = 12 AND
				bwg_typeflag = 'D' AND
				stamm_type_flag = 'D' AND
				region_awid not in (
					243,
					361,
					2
				) AND
				NVL(
					stamm_quelle,
					'EMPTY'
				) <> 'MA' AND
				stamm_landid = 1054 AND
				stamm_musikfremd = 0 AND
				stamm_lauf = 'DWN' AND
				stamm_match_code != '0' AND
				bwg_cont_dist = 645
			group by
				stamm_match_code,
				case region_id when 1097 then 1760 else region_id end
		),
		phys_query as (
			SELECT
				stamm_match_code phys_match_code,
				sum(
					bwg_menge
				) phys_meng,
				sum(
					bwg_wert
				) phys_wert,
				case region_id when 1097 then 1760 else region_id end as phys_region_id
			FROM
				stamm_gesamt,
				region,
				bewegung_T,
				zeitraum_T
			WHERE
				bwg_haendlerid = region_haendlerid AND
				bwg_eancid = stamm_eancid AND
				bwg_zeitkey = zeit_key AND
				zeit_key >= $zeit AND
				zeit_key <= $zeit AND
				bwg_landid = 1054 AND
				bwg_appid = 12 AND
				region_appid = 12 AND
				stamm_appid = 12 AND
				bwg_typeflag = 'P' AND
				stamm_type_flag = 'P' AND
				region_awid not in (
					243,
					361,
					2
				) AND
				NVL(
					stamm_quelle,
					'EMPTY'
				) <> 'MA' AND
				stamm_landid = 1054 AND
				stamm_musikfremd = 0 AND
				stamm_lauf = 'DE' AND
				stamm_match_code != '0' AND
				bwg_cont_dist = 641
			group by
				stamm_match_code,
				case region_id when 1097 then 1760 else region_id end
		),
		stamm_query as (
			SELECT
				distinct stamm_match_code
			FROM
				stamm_gesamt
			WHERE
				stamm_match_code != '0' AND
				stamm_lauf in (
					'DE',
					'DWN'
				) AND
				stamm_type_flag in (
					'P',
					'D'
				) AND
				stamm_landid = 1054
		),
		combine_query as (
			SELECT
				decode(
					phys_match_code, null, dwn_match_code, phys_match_code
				) match_code,
				decode(
					phys_region_id,
					null,
					dwn_region_id,
					phys_region_id
				) region_id,
				decode(
					phys_match_code,
					null,
					0,
					phys_meng
				) phys_meng,
				decode(
					phys_match_code,
					null,
					0,
					phys_wert
				) phys_wert,
				decode(
					dwn_match_code,
					null,
					0,
					dwn_meng
				) dwn_meng,
				decode(
					dwn_match_code,
					null,
					0,
					dwn_wert
				) dwn_wert
			FROM
				(
						phys_query full
					outer join
						dwn_query
					on
						phys_match_code = dwn_match_code and
						phys_region_id = dwn_region_id
				)
			WHERE
				decode(
					phys_match_code,
					null,
					dwn_match_code,
					phys_match_code
				) in (
					SELECT
						stamm_match_code
					FROM
						stamm_query
				)
		),
		product_query as (
			SELECT
				combine_query.*,
				phys_meng + dwn_meng prod_meng,
				phys_wert + dwn_wert prod_wert,
				row_number() over(
					order by
						(
							phys_wert + dwn_wert
						) DESC
				) index_
			FROM
				combine_query
		),
		region_query as (
			select
				distinct region_id,
				region_txt
			from
				region
			where
				region_landid = 1054
		)
	SELECT
		stamm_eanc,
		stamm_titel,
		stamm_artist,
		stamm_ag_txt,
		stamm_wg_txt,
		stamm_firm_code,
		stamm_herst_txt,
		stamm_label_txt,
		stamm_vdatum,
		stamm_archivnr,
		region_txt,
		round(
			prod_meng,
			0
		) prod_meng,
		round(
			prod_wert,
			2
		) prod_wert,
		round(
			phys_meng,
			0
		) phys_meng,
		round(
			phys_wert,
			2
		) phys_wert,
		round(
			dwn_meng,
			0
		) dwn_meng,
		round(
			dwn_wert,
			2
		) dwn_wert
	FROM
		product_query,
		stamm_gesamt,
		region_query
	WHERE
		product_query.match_code = stamm_match_code AND
		product_query.region_id = region_query.region_id and
		stamm_mainprod_header = 1 AND
		stamm_landid = 1054 AND
		CASE
			WHEN
				stamm_lauf = 'DE' AND
				stamm_mainprod_header = 1
			THEN
				'DE'
			ELSE
				'DWN'
		END = stamm_lauf
	order by
		index_ asc
      ";

    //echo $sql."\n";

    $arr = array();
    $data = array();

    $ct = 0;

    $rs=$db->query($sql);

    if (MDB2::isError($rs)) {
        dbug('error', $sql);
        $is_error = true;
    } else {
        while($arr= $rs->fetchRow(MDB2_FETCHMODE_ASSOC)) {
            if ($arr) {                
                //convert everything from UTF8 to ISO for backward compatibility
                $arr = array_utf_to_iso($arr);
                #ISRC could sometimes be null. in this case, simply fill it with 12 times 9
                $data[$ct]['EANC']          = isset($arr['stamm_eanc']) ? $arr['stamm_eanc'] : '';
                $data[$ct]['TITEL']         = (isset($arr['stamm_titel']) and strlen($arr['stamm_titel']) > 0) ? $arr['stamm_titel'] : '';
                $data[$ct]['INTERPRETER']   = (isset($arr['stamm_artist']) and strlen($arr['stamm_artist']) > 0) ? $arr['stamm_artist'] : '';
                $data[$ct]['FORMAT'] 		= isset($arr['stamm_ag_txt']) ? $arr['stamm_ag_txt'] : '';
                $data[$ct]['GENRE'] 		= $arr['stamm_wg_txt'];
                $data[$ct]['PNCODE'] 		= $arr['stamm_firm_code'];
                $data[$ct]['PNTXT'] 		= $arr['stamm_herst_txt'];
                $data[$ct]['LABEL'] 		= $arr['stamm_label_txt'];
                $data[$ct]['VOEDATUM'] 		= trim($arr['stamm_vdatum']);
                $data[$ct]['ARCHIVNR'] 		= $arr['stamm_archivnr'];
                $data[$ct]['REGIONTXT'] 	= $arr['region_txt'];
                $data[$ct]['SALEP'] 		= $arr['phys_wert'];
                $data[$ct]['SALED'] 		= $arr['dwn_wert'];
                $data[$ct]['UNITP'] 		= $arr['phys_meng'];
                $data[$ct]['UNITD'] 		= $arr['dwn_meng'];
            }
            $ct++;
        }
        $rs->free();
    }

    echo "Schreibe Universal-File...";
    if(($fileToWrite = fopen($nfs_root_dir . 'temp/TopallDeRegionBase'.$jahr4.$monate2.$tag2.'.TXT', 'w')) === FALSE){
        $is_error = true;
    } else {
        foreach ($data as $c=>$dt) {            
                fwrite($fileToWrite, $jahr4.$monate2.$tag2."\t");		#day
                fwrite($fileToWrite, $dt['EANC']."\t"); 				#EAN (vom Masterprodukt/Header)
                fwrite($fileToWrite, $dt['TITEL']."\t"); 				#Titel
                fwrite($fileToWrite, $dt['INTERPRETER']."\t"); 			#Interpret
                fwrite($fileToWrite, $dt['FORMAT']."\t"); 				#Tonträgerformat (CD, DWL Album usw.)
                fwrite($fileToWrite, $dt['GENRE']."\t"); 				#Genretext
                fwrite($fileToWrite, $dt['PNCODE']."\t"); 				#PN-Code 
                fwrite($fileToWrite, $dt['PNTXT']."\t"); 				#Distributortext
				fwrite($fileToWrite, $dt['LABEL']."\t"); 				#Label
                fwrite($fileToWrite, $dt['VOEDATUM']."\t");				#VÖ-Datum (Erst-VÖ-Datum)
                fwrite($fileToWrite, $dt['ARCHIVNR']."\t"); 			#MC-Archivnummer (Verknüpfungsvariable)
                fwrite($fileToWrite, $dt['REGIONTXT']."\t"); 			#verkaufte Region (auf Basis der 23 Einzelregionen)
                fwrite($fileToWrite, $dt['SALEP']."\t"); 				#PHYS sale
				fwrite($fileToWrite, $dt['SALED']."\t"); 				#DWN sale
                fwrite($fileToWrite, $dt['UNITP']."\t");				#PHYS unit
                fwrite($fileToWrite, $dt['UNITD']."\t");				#DWN unit
                fwrite($fileToWrite,"\n");
            }
        

        fclose($fileToWrite);
        echo "fertig.\n";
    }
    /*echo "Zip Groove-File...\n";
    $is_error = !create_zip(
                array(
                    array(
                        "abs_path" => $nfs_root_dir . 'temp/DLGR'.$jahr4.$monate2.$tag2.'.TXT',
                        "rel_path" => 'DLGR'.$jahr4.$monate2.$tag2.'.TXT'
                    )
                ),
                $nfs_root_dir . 'temp/DLGR'.$jahr4.$monate2.$tag2.'.ZIP',
                true
            );

    echo "fertig.\n";

    echo "Upload die Dateien auf die entsprechenden FTP-Laufwerke...";
    $ftp = new FTP;
    $ftp->connect($ftp_host);
    $ftp->login($ftp_username, $ftp_password);

    //Groove
//    if( ! $ftp->put($target_path_de . '/GROOVE/DLGR' . $jahr2 . 'KW' . $woche . ".ZIP",
//            $nfs_root_dir . 'temp/DLGR' . $jahr2 . 'KW' . $woche . '.ZIP', FTP_BINARY)){

    //Groove Attack
    if( ! $ftp->put($target_path_de . '/GROOVE/DLGR' . $jahr4 . $monate2 . $tag2 . '.ZIP',
            $nfs_root_dir  .  'temp/DLGR' . $jahr4 . $monate2 . $tag2 . '.ZIP', FTP_BINARY)) {
        $is_error = true;
        echo "failed to upload Groove Attack file.\n";
    }

    if( ! rmdirr($nfs_root_dir  .  'temp/DLGR' . $jahr4 . $monate2 . $tag2 . '.TXT')){
        $is_error = true;
        echo "failed to delete DLGR" . $jahr4 . $monate2 . $tag2 . ".TXT\n";
    }
    
    $ftp->close();

    echo "done.\n";    

    if(!update_last_update_groove_zeitkey($lastupdatefile, $zeit)){
        echo "WARNING: failed to update last-update zeitkey ($zeit).\n";
    }

    if($is_error == true) {
        $par['empfaenger'] = $reports_error_recipients;
        $par['message'] = array('subject'=>"(Exasol) Error GROOVE FTP-Daten Downloads",'body_txt'=>"Bei Generierung FTP-Daten (GROOVE TAGESDATEN, $jahr4.$monate2.$tag2 ) Fehler aufgetreten\n");
    } else {
        $par['empfaenger'] = $reports_normal_recipients;
        $par['message'] = array('subject'=>"(Exasol) Erfolg GROOVE FTP-Daten Downloads",'body_txt'=>"FTP-Daten (GROOVE TAGESDATEN, $jahr4.$monate2.$tag2 ) generiert und verteilt.\n");
    }
    sendEMail($par);*/
}

?>