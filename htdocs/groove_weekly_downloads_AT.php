<?php

/***
 * 
 *  AUSTRIA 
 *  FTP Data
 *  NOTE: The AT-Data are delivered in UTF-8 format.
 * 
 */

require_once(dirname(__FILE__).'/../lib/global.php');

require_once('MDB2.php');
require_once(CFG_PATH.'db.conf.php');

require_once 'Mail.php';
require_once 'Mail/mime.php';
require_once 'MIME/Type.php';

$groove    = array(18270, 18271, 18272);
$hoanzl    = array(18235);
$sony      = array(18202, 19203);
$warner    = array(18205);
$emi       = array(18203);
$universal = array(18201);

$is_error = false;

$dsn = $exasol_dsn;
$db = MDB2::connect($dsn);
if (MDB2::isError($db)) {
	$par['empfaenger'] = $reports_error_recipients;
	$par['message'] = array('subject'=>"(Exasol) Error GROOVE Weekly FTP-Daten Downloads",
        'body_txt'=>"Bei Generierung FTP-Daten GROOVE Weekly Fehler aufgetreten\n");

	sendEMail($par);

	die ($db->getMessage());
}


if(!isset($_REQUEST['zeitid'])) {
	$qry = " select max(zeit_key) as max_zeit from zeitraum_gui where zeit_typeflag = 'D' and zeit_einheit = 'W' and zeit_landid = 1013 ";
	$rs = $db->query($qry);
	$data = $rs->fetchRow(MDB2_FETCHMODE_ASSOC);
	$zeit = $data['max_zeit'];
} else {
	$zeit = substr($_REQUEST['zeitid'],0,6);
}

/**
 * for historical data re-generation
     $zeit_keys = array(
        '201313',
        '201237',
    );
 */
#    foreach ($zeit_keys as $zeit){

        $jahr2 = substr($zeit,2,2);
        $jahr4 = substr($zeit,0,4);
        $woche = substr($zeit,-2,2);

        $uarr   = array();
        $udata  = array();

        $sql = "
        SELECT
    stamm_asnr AS seanc,
    stamm_eancid AS seanc_id,
    stamm_main_prodnr AS main_prodnr,
    stamm_ag_txt AS tart,
    stamm_agid AS tartid,
    stamm_ag_code AS tart_code,
    stamm_wg_txt AS repe,
    stamm_wg_code AS repe_code,
    stamm_firm_code AS firmid,
    stamm_display_code AS display_code,
    stamm_artnr AS satnr,
    stamm_titel AS stitl,
    stamm_artist AS sinte,
    stamm_label_txt AS slabl,
    stamm_herst_txt AS vertrieb_lang,
    stamm_herst_txt2 AS vertrieb,
    stamm_archivnr AS archivnr,
    SUM(bwg_wert) AS sum_wert,
    SUM(bwg_menge) AS sum_meng,
    SUM(bwg_wert_abs) AS sum_wert_abs,
    SUM(bwg_menge_abs) AS sum_meng_abs
FROM
    bewegung_w,
    region,
    stamm_gesamt
WHERE
    bwg_zeitkey = $zeit AND
    BWG_CONT_DIST = 645 AND
    stamm_landid = 1013 AND
    bwg_landid = 1013 AND
    region_landid = 1013 AND
    bwg_appid = 12 AND
    stamm_appid = 12 AND
    region_appid = 12 AND
    bwg_haendlerid = region_haendlerid AND
    stamm_eancid = bwg_eancid AND
    stamm_type_flag = 'D' AND
    stamm_lauf = 'DWN' AND
    NVL(stamm_quelle, 'EMPTY') <> 'MA'
GROUP BY
    stamm_asnr,
    stamm_eancid,
    stamm_main_prodnr,
    stamm_ag_txt,
    stamm_agid,
    stamm_ag_code,
    stamm_wg_txt,
    stamm_wg_code,
    stamm_firm_code,
    stamm_display_code,
    stamm_artnr,
    stamm_titel,
    stamm_artist,
    stamm_label_txt,
    stamm_herst_txt,
    stamm_herst_txt2,
    stamm_archivnr
ORDER BY
    SUM(bwg_menge) DESC ";

        echo "$zeit\n";

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
                    if(isset($udata[$arr['vertrieb']])) {
                            $firma = $udata[$arr['vertrieb']];
                    } else {
                            $firma = '';
                    }
                    #ISRC could sometimes be null. in this case, simply fill it with 13 times 9
                    $data[$ct]['ISRC']          = (isset($arr['satnr']) and strlen($arr['satnr']) > 0) ? $arr['satnr'] : '9999999999999';
                    $data[$ct]['Titel']         = (isset($arr['stitl']) and strlen($arr['stitl']) > 0) ? $arr['stitl'] : '          ';
                    $data[$ct]['Interpret']     = (isset($arr['sinte']) and strlen($arr['sinte']) > 0) ? $arr['sinte'] : '          ';
                    $data[$ct]['Menge']         = $arr['sum_meng'];
                    $data[$ct]['Firma']         = $firma;
                    $data[$ct]['Label']         = $arr['slabl'];
                    $data[$ct]['Vertrieb']      = $arr['vertrieb'];
                    $data[$ct]['VertriebLong']  = $arr['vertrieb_lang'];
                    $data[$ct]['FirmID']        = $arr['firmid'];
                    $data[$ct]['DisplayCode']   = $arr['display_code'];
                    $data[$ct]['Archivnr']      = trim($arr['archivnr']);                    
                    #$data[$ct]['MainProdNr']    = trim($arr['main_prodnr']);
                    $data[$ct]['Umsatz']        = $arr['sum_wert'];
                    $data[$ct]['Tart']          = trim($arr['tart']);
                    $data[$ct]['TartID']        = $arr['tartid'];
                    $data[$ct]['TartCode']      = $arr['tart_code'];
                    $data[$ct]['RepeID']        = $arr['repe_code'];
                    $data[$ct]['Repe']          = $arr['repe'];
                }
                $ct++;
            }
            $rs->free();
        }

        //GRROVE
        echo "Schreibe Groove-File...";
        if(($dlgroove = fopen($nfs_root_dir . 'temp/ATDLGR'.$jahr2.'KW'.$woche.'.TXT', 'w')) === FALSE){
            $is_error = true;
        } else {
            foreach ($data as $c=>$dt) {
                if(in_array($dt['DisplayCode'], $groove)) {
                    fwrite($dlgroove, $zeit.';');
                    fwrite($dlgroove, date('Ymd',thursdaykw($woche,$jahr4)).';');
                    fwrite($dlgroove, date('Ymd',thursdaykw($woche,$jahr4,'+')).';');  #date end
                    fwrite($dlgroove, $dt['ISRC'].';');                                #ISRC
                    fwrite($dlgroove, '"'.$dt['Interpret'].'";');                      #Artist
                    fwrite($dlgroove, '"'.$dt['Titel'].'";');                          #Title
                    #fwrite($dlgroove, ';');                                           #Artikel nr.
                    fwrite($dlgroove, $dt['TartID'].';');                              #Tart Code
                    fwrite($dlgroove, number_format($dt['Menge'], 0, ",", "") . ';');  #Menge
                    fwrite($dlgroove, $dt['FirmID'].';');                              #Phononet Nummer
                    #fwrite($dlgroove, ';');                                           #Artikel nr.
                    fwrite($dlgroove, number_format($dt['Umsatz'], 2, ",", ""));       #Final Price
                    fwrite($dlgroove,"\n");
                } //Groove Attack has no competitor data
            }

            fclose($dlgroove);
            echo "fertig.\n";
        }

        echo "Zipping Groove-File...\n";
        $is_error = !create_zip(
                    array(
                        array(
                            "abs_path" => $nfs_root_dir . 'temp/ATDLGR'.$jahr2.'KW'.$woche.'.TXT',
                            "rel_path" => 'ATDLGR'.$jahr2.'KW'.$woche.'.TXT'
                        )
                    ),
                    $nfs_root_dir . 'temp/ATDLGR'.$jahr2.'KW'.$woche.'.ZIP',
                    true
                );

        echo "fertig.\n";

        //COPY
        echo "Kopieren der Dateien auf die entsprechenden FTP-Laufwerke...";

        $ftp = new FTP;
        $ftp->connect($ftp_host);
        $ftp->login($ftp_username, $ftp_password);

        //Groove attacks AT, but upload to GERMANY!
        if( ! $ftp->put($target_path_de . '/GROOVE/ATDLGR' . $jahr2 . 'KW' . $woche . '.ZIP',
                $nfs_root_dir . 'temp/ATDLGR' . $jahr2 . 'KW' . $woche . '.ZIP', FTP_BINARY)){
            echo "failed to upload ATDLGR".$jahr2."KW".$woche.".ZIP\n";
            $is_error = true;
        }
                
       

        echo "Generierte Dateien in Archiv verschieben...";
        if (!mkdir($nfs_root_dir . 'temp/'.$zeit) and !(rmdirr($nfs_root_dir . 'temp/'.$zeit) and mkdir($nfs_root_dir . 'temp/'.$zeit))) {
            echo "failed to create directory: ".$nfs_root_dir . 'temp/'.$zeit;
            $is_error = true;
        } else {
            //Groove Attack
            if (!rename($nfs_root_dir . 'temp/ATDLGR'.$jahr2.'KW'.$woche.'.TXT', $nfs_root_dir . 'temp/'.$zeit.'/ATDLGR'.$jahr2.'KW'.$woche.'.TXT')) {
                echo "failed to archive ATDLGR".$jahr2."KW".$woche.".TXT\n";
                $is_error = true;
            }   
           
        }
    echo "fertig.\n";
#HISTORY
#    }

if($is_error == true) {
	$par['empfaenger'] = $reports_error_recipients;
	$par['message'] = array('subject'=>"(Exasol) Error GROOVE Weekly AT FTP-Daten Downloads",'body_txt'=>"Bei Generierung GROOVE Weekly AT-FTP-Daten Fehler aufgetreten.\n");
} else {
	$par['empfaenger'] = $reports_normal_recipients;
	$par['message'] = array('subject'=>"(Exasol) Erfolg GROOVE Weekly AT FTP-Daten Downloads",'body_txt'=>"GROOVE Weekly AT FTP-Daten generiert und verteilt.\n");
}
sendEMail($par);

?>
