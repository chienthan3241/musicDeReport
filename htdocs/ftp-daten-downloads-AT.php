<?php

/***
 * 
 *  AUSTRIA 
 *  FTP Data
 *  NOTE: The AT-Data are delivered in UTF-8 format.
 * 
 */


$zeit_keys = array(
    '201313',
    '201237',
);


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
	$par['message'] = array('subject'=>"(Exasol) Error FTP-Daten Downloads",
        'body_txt'=>"Bei Generierung FTP-Daten Fehler aufgetreten, /tmp/ftp_dl_gen.log checken!\n");

	sendEMail($par);

	die ($db->getMessage());
}


if(!isset($_REQUEST['zeitid'])) {
	$qry = "select max(zeit_key) as max_zeit from zeitraum_gui where zeit_typeflag = 'D' and zeit_einheit = 'W'";
	$rs = $db->query($qry);
	$data = $rs->fetchRow(MDB2_FETCHMODE_ASSOC);
	$zeit = $data['max_zeit'];
} else {
	$zeit = substr($_REQUEST['zeitid'],0,6);
}

/**
 * for historical data re-generation
 */
#    foreach ($zeit_keys as $zeit){

        $jahr2 = substr($zeit,2,2);
        $jahr4 = substr($zeit,0,4);
        $woche = substr($zeit,-2,2);

        $uarr   = array();
        $udata  = array();

        $sql = "
        SELECT   s1.*,
                stamm_asnr AS seanc,
                stamm_eancid AS seanc_id,
                sprnr,
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
                stamm_archivnr AS archivnr
            FROM   (SELECT   *
                    FROM   (SELECT   ROWNUM AS index_, daten.*
                                FROM   (  SELECT 
                                                stamm_prodnr AS sprnr,
                                                SUM (bwg_wert) AS sum_wert,
                                                SUM (bwg_menge) AS sum_meng,
                                                SUM (bwg_wert_abs) AS sum_wert_abs,
                                                SUM (bwg_menge_abs) AS sum_meng_abs
                                            FROM   bewegung_w, region, stamm_gesamt
                                        WHERE       bwg_zeitkey      = $zeit
						      AND BWG_CONT_DIST IN ('641','645')
                                                AND stamm_landid     = 1013
                                                AND bwg_landid       = 1013
                                                AND region_landid    = 1013
                                                AND bwg_appid        = 12
                                                AND stamm_appid      = 12
                                                AND region_appid     = 12
                                                AND bwg_haendlerid   = region_haendlerid
                                                AND stamm_eancid     = bwg_eancid
                                                AND stamm_type_flag  = 'D'
                                                AND stamm_lauf       = 'DWN'
                                                AND NVL(stamm_quelle, 'EMPTY') <> 'MA'
                                        GROUP BY   stamm_prodnr
                                        ORDER BY   SUM (bwg_menge) DESC) daten)) s1,
                stamm_gesamt
        WHERE       s1.sprnr = SUBSTR (stamm_gesamt.stamm_eancid, 2)
                AND stamm_type_flag = 'D'
                AND stamm_lauf = 'DWN'
                AND stamm_landid = 1013
                AND stamm_is_header = 1
        GROUP BY   sprnr,
                stamm_display_code,
                stamm_main_prodnr,
                stamm_ag_txt,
                stamm_agid,
                stamm_ag_code,
                stamm_wg_txt,
                stamm_wg_code,
                stamm_firm_code,
                stamm_eancid,
                stamm_titel,
                stamm_asnr,
                stamm_artnr,
                stamm_artist,
                sum_wert,
                sum_meng,
                sum_wert_abs,
                sum_meng_abs,
                stamm_label_txt,
                stamm_herst_txt,
                stamm_herst_txt2,
                stamm_archivnr,
                index_
        ORDER BY   index_ ASC ";

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
                    $data[$ct]['ProdNr']        = trim($arr['sprnr']);
                    $data[$ct]['MainProdNr']    = trim($arr['main_prodnr']);
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


        //HONANZL
        echo "Schreibe Honanzl-File...";
        if(($dlhoanzl = fopen($nfs_root_dir . 'temp/ATDLHO'.$jahr2.'KW'.$woche.'.TXT', 'w')) === FALSE){
            $is_error = true;
        } else {
            foreach ($data as $c=>$dt) {
                    fwrite($dlhoanzl, $zeit.';');
                    fwrite($dlhoanzl, date('Ymd',thursdaykw($woche,$jahr4)).';');
                    fwrite($dlhoanzl, date('Ymd',thursdaykw($woche,$jahr4,'+')).';');  #date end
                    fwrite($dlhoanzl, $dt['ISRC'].';');                                #ISRC
                    fwrite($dlhoanzl, '"'.$dt['Interpret'].'";');                      #Artist
                    fwrite($dlhoanzl, '"'.$dt['Titel'].'";');                          #Title
                    fwrite($dlhoanzl, '"'.$dt['MainProdNr'].'";');                     #mainprodID
                    fwrite($dlhoanzl, $dt['TartCode'].';');                            #Tart Code
                    fwrite($dlhoanzl, number_format($dt['Menge'], 0, ",", "") . ';');  #Menge
                    fwrite($dlhoanzl, $dt['DisplayCode'].';');                         #DispCode
                    fwrite($dlhoanzl, $dt['Label'].';');                               #Label
                    if(in_array($dt['DisplayCode'], $hoanzl)) {
                        //for Hoanzl this should never be true, as they do not have a
                        // Vertrieb
                        fwrite($dlhoanzl, number_format($dt['Umsatz'], 2, ",", ""));
                    }
                    fwrite($dlhoanzl,"\n");
            }

            fclose($dlhoanzl);
            echo "fertig.\n";
        }

        echo "Zipping Hoanzl-File...\n";
        $is_error = !create_zip(
                    array(
                        array(
                            "abs_path" => $nfs_root_dir . 'temp/ATDLHO'.$jahr2.'KW'.$woche.'.TXT',
                            "rel_path" => 'ATDLHO'.$jahr2.'KW'.$woche.'.TXT'
                        )
                    ),
                    $nfs_root_dir . 'temp/ATDLHO'.$jahr2.'KW'.$woche.'.ZIP',
                    true
                );

        echo "fertig.\n";

        //Sony
        echo "Schreibe Sony-File...";
        if(($dlsony = fopen($nfs_root_dir . 'temp/ATDLBM'.$jahr2.'KW'.$woche.'.TXT', 'w')) === FALSE){
            $is_error = true;
        } else {
            foreach ($data as $c=>$dt) {
                    fwrite($dlsony, $zeit.';');
                    fwrite($dlsony, date('Ymd',thursdaykw($woche,$jahr4)).';');
                    fwrite($dlsony, date('Ymd',thursdaykw($woche,$jahr4,'+')).';');  #date end
                    fwrite($dlsony, $dt['ISRC'].';');                                #ISRC
                    fwrite($dlsony, '"'.$dt['Interpret'].'";');                      #Artist
                    fwrite($dlsony, '"'.$dt['Titel'].'";');                          #Title
                    fwrite($dlsony, '"'.$dt['MainProdNr'].'";');                     #mainprodID
                    fwrite($dlsony, $dt['TartCode'].';');                            #Tart Code
                    fwrite($dlsony, number_format($dt['Menge'], 0, ",", "") . ';');  #Menge
                    fwrite($dlsony, $dt['DisplayCode'].';');                         #DispCode
                    fwrite($dlsony, '"'.$dt['Label'].'";');                          #Label
                    if(in_array($dt['DisplayCode'], $sony)) {
                        // Vertrieb
                        fwrite($dlsony, number_format($dt['Umsatz'], 2, ",", ""));
                    }
                    fwrite($dlsony,"\n");
            }

            fclose($dlsony);
            echo "fertig.\n";
        }

        echo "Zipping Sony-File...\n";
        $is_error = !create_zip(
                    array(
                        array(
                            "abs_path" => $nfs_root_dir . 'temp/ATDLBM'.$jahr2.'KW'.$woche.'.TXT',
                            "rel_path" => 'ATDLBM'.$jahr2.'KW'.$woche.'.TXT'
                        )
                    ),
                    $nfs_root_dir . 'temp/ATDLBM'.$jahr2.'KW'.$woche.'.ZIP',
                    true
                );

        echo "fertig.\n";

        //Warner
        echo "Schreibe Warner-File...";
        if(($dlwarner = fopen($nfs_root_dir . 'temp/ATDLWA'.$jahr2.'KW'.$woche.'.TXT', 'w')) === FALSE){
            $is_error = true;
        } else {
            foreach ($data as $c=>$dt) {
                    fwrite($dlwarner, $zeit.';');
                    fwrite($dlwarner, date('Ymd',thursdaykw($woche,$jahr4)).';');
                    fwrite($dlwarner, date('Ymd',thursdaykw($woche,$jahr4,'+')).';');  #date end
                    fwrite($dlwarner, '"'.$dt['ISRC'].'";');                                #ISRC
                    fwrite($dlwarner, '"'.$dt['Interpret'].'";');                           #Artist
                    fwrite($dlwarner, '"'.$dt['Titel'].'";');                               #Title
                    fwrite($dlwarner, '"'.$dt['MainProdNr'].'";');                          #mainprodID
                    fwrite($dlwarner, '"'.$dt['TartCode'].'";');                            #Tart Code
                    fwrite($dlwarner, '"'.$dt['Tart'].'";');                                #Tart Text 
                    fwrite($dlwarner, '"'.$dt['RepeID'].'";');                              #Genre Code
                    fwrite($dlwarner, '"'.$dt['Repe'].'";');                                #Genre Text
                    fwrite($dlwarner, number_format($dt['Menge'], 0, ",", "") . ';');       #Menge
                    fwrite($dlwarner, '"'.$dt['DisplayCode'].'";');                         #DispCode (Distributor Code)
                    fwrite($dlwarner, '"'.$dt['VertriebLong'].'";');                        #Distributor Text
                    fwrite($dlwarner, '"'.$dt['Label'].'";');                               #Label
                    if(in_array($dt['DisplayCode'], $warner)) {
                        // Warner wants avg. price instead of sales
                        $m = $dt['Menge'];
                        $u = $dt['Umsatz'];
                        $ap = ($m == 0 ? 0 : $u/$m);
                        fwrite($dlwarner, number_format($ap, 2, ",", ""));
                    }else{
                        fwrite($dlwarner, number_format(0, 2, ",", ""));
                    }
                    fwrite($dlwarner,"\n");
            }

            fclose($dlwarner);
            echo "fertig.\n";
        }
        
        
        echo "Zipping Warner-File...\n";
        $is_error = !create_zip(
                    array(
                        array(
                            "abs_path" => $nfs_root_dir . 'temp/ATDLWA'.$jahr2.'KW'.$woche.'.TXT',
                            "rel_path" => 'ATDLWA'.$jahr2.'KW'.$woche.'.TXT'
                        )
                    ),
                    $nfs_root_dir . 'temp/ATDLWA'.$jahr2.'KW'.$woche.'.ZIP',
                    true
                );

        echo "fertig.\n";
          
	/*
        //EMI
        echo "Schreibe EMI-File...";
        if(($dlemi = fopen($nfs_root_dir . 'temp/ATDLEM'.$jahr2.'KW'.$woche.'.TXT', 'w')) === FALSE){
            $is_error = true;
        } else {
            foreach ($data as $c=>$dt) {
                    fwrite($dlemi, $zeit.';');
                    fwrite($dlemi, date('Ymd',thursdaykw($woche,$jahr4)).';');
                    fwrite($dlemi, date('Ymd',thursdaykw($woche,$jahr4,'+')).';');  #date end
                    fwrite($dlemi, $dt['ISRC'].';');                                #ISRC
                    fwrite($dlemi, '"'.$dt['Interpret'].'";');                      #Artist
                    fwrite($dlemi, '"'.$dt['Titel'].'";');                          #Title
                    fwrite($dlemi, '"'.$dt['MainProdNr'].'";');                     #mainprodID
                    fwrite($dlemi, $dt['TartCode'].';');                            #Tart Code
                    fwrite($dlemi, number_format($dt['Menge'], 0, ",", "") . ';');  #Menge
                    fwrite($dlemi, $dt['DisplayCode'].';');                         #DispCode
                    fwrite($dlemi, $dt['Label'].';');                               #Label
                    if(in_array($dt['DisplayCode'], $emi)) {
                        // Vertrieb
                        fwrite($dlemi, number_format($dt['Umsatz'], 2, ",", ""));
                    }
                    fwrite($dlemi,"\n");
            }

            fclose($dlemi);
            echo "fertig.\n";
        }

        echo "Zipping EMI-File...\n";
        $is_error = !create_zip(
                    array(
                        array(
                            "abs_path" => $nfs_root_dir . 'temp/ATDLEM'.$jahr2.'KW'.$woche.'.TXT',
                            "rel_path" => 'ATDLEM'.$jahr2.'KW'.$woche.'.TXT'
                        )
                    ),
                    $nfs_root_dir . 'temp/ATDLEM'.$jahr2.'KW'.$woche.'.ZIP',
                    true
                );

        echo "fertig.\n";
	*/
        //Universal
        echo "Schreibe Universal-File...";
        if(($dluni = fopen($nfs_root_dir . 'temp/ATDLUN'.$jahr2.'KW'.$woche.'.TXT', 'w')) === FALSE){
            $is_error = true;
        } else {
            foreach ($data as $c=>$dt) {
                    fwrite($dluni, $zeit.';');
                    fwrite($dluni, date('Ymd',thursdaykw($woche,$jahr4)).';');
                    fwrite($dluni, date('Ymd',thursdaykw($woche,$jahr4,'+')).';');  #date end
                    fwrite($dluni, $dt['ISRC'].';');                                #ISRC
                    fwrite($dluni, '"'.$dt['Interpret'].'";');                      #Artist
                    fwrite($dluni, '"'.$dt['Titel'].'";');                          #Title
                    fwrite($dluni, '"'.$dt['MainProdNr'].'";');                     #mainprodID
                    fwrite($dluni, $dt['TartCode'].';');                            #Tart Code
                    fwrite($dluni, number_format($dt['Menge'], 0, ",", "") . ';');  #Menge
                    fwrite($dluni, $dt['DisplayCode'].';');                         #DispCode
                    fwrite($dluni, $dt['Label'].';');                               #Label
                    if(in_array($dt['DisplayCode'], $universal)) {
                        // Vertrieb
                        fwrite($dluni, number_format($dt['Umsatz'], 2, ",", ""));
                    }
                    fwrite($dluni,"\n");
            }

            fclose($dluni);
            echo "fertig.\n";
        }

        echo "Zipping Universal-File...\n";
        $is_error = !create_zip(
                    array(
                        array(
                            "abs_path" => $nfs_root_dir . 'temp/ATDLUN'.$jahr2.'KW'.$woche.'.TXT',
                            "rel_path" => 'ATDLUN'.$jahr2.'KW'.$woche.'.TXT'
                        )
                    ),
                    $nfs_root_dir . 'temp/ATDLUN'.$jahr2.'KW'.$woche.'.ZIP',
                    true
                );

        echo "fertig.\n";

        //COPY
        echo "Kopieren der Dateien auf die entsprechenden FTP-Laufwerke...";

        $ftp = new FTP;
        $ftp->connect($ftp_host);
        $ftp->login($ftp_username, $ftp_password);
 
        //Hoanzl
        if( ! $ftp->put($target_path_at . '/Hoanzl/ATDLHO' . $jahr2 . 'KW' . $woche . '.ZIP',
                $nfs_root_dir . 'temp/ATDLHO' . $jahr2 . 'KW' . $woche . '.ZIP', FTP_BINARY)){
            echo "failed to upload ATDLHO" . $jahr2 . "KW" . $woche . ".ZIP\n";
            $is_error = true;
        }

        echo "fertig.\n";

        //Warner AT, but upload to GERMANY!
        if( ! $ftp->put($target_path_de . '/WARNER/DOWNLOAD/SALES/ATDLWA' . $jahr2 . 'KW' . $woche . '.TXT',
                $nfs_root_dir . 'temp/ATDLWA' . $jahr2 . 'KW' . $woche . '.TXT', FTP_BINARY)){
            echo "failed to upload Warner file ATDLWA" . $jahr2 . "KW" . $woche . ".TXT\n";
            $is_error = true;
        }

        echo "fertig.\n";

        echo "Generierte Dateien in Archiv verschieben...";
        if (!mkdir($nfs_root_dir . 'temp/'.$zeit) and !(rmdirr($nfs_root_dir . 'temp/'.$zeit) and mkdir($nfs_root_dir . 'temp/'.$zeit))) {
            echo "failed to create directory: ".$nfs_root_dir . 'temp/'.$zeit;
            $is_error = true;
        } else {           
            //Hoanzl
            if (!rename($nfs_root_dir . 'temp/ATDLHO'.$jahr2.'KW'.$woche.'.TXT', $nfs_root_dir . 'temp/'.$zeit.'/ATDLHO'.$jahr2.'KW'.$woche.'.TXT')) {
                echo "failed to archive ATDLHO".$jahr2."KW".$woche.".TXT\n";
                $is_error = true;
            }
            //Sony BMG
            if (!rename($nfs_root_dir . 'temp/ATDLBM'.$jahr2.'KW'.$woche.'.TXT', $nfs_root_dir . 'temp/'.$zeit.'/ATDLBM'.$jahr2.'KW'.$woche.'.TXT')) {
                echo "failed to archive ATDLBM".$jahr2."KW".$woche.".TXT\n";
                $is_error = true;
            }
/*
            //EMI
            if (!rename($nfs_root_dir . 'temp/ATDLEM'.$jahr2.'KW'.$woche.'.TXT', $nfs_root_dir . 'temp/'.$zeit.'/ATDLEM'.$jahr2.'KW'.$woche.'.TXT')) {
                echo "failed to archive ATDLEM".$jahr2."KW".$woche.".TXT\n";
                $is_error = true;
            }
*/
            //Universal
            if (!rename($nfs_root_dir . 'temp/ATDLUN'.$jahr2.'KW'.$woche.'.TXT', $nfs_root_dir . 'temp/'.$zeit.'/ATDLUN'.$jahr2.'KW'.$woche.'.TXT')) {
                echo "failed to archive ATDLUN".$jahr2."KW".$woche.".TXT\n";
                $is_error = true;
            }
            //Warner
            if (!rename($nfs_root_dir . 'temp/ATDLWA'.$jahr2.'KW'.$woche.'.TXT', $nfs_root_dir . 'temp/'.$zeit.'/ATDLWA'.$jahr2.'KW'.$woche.'.TXT')) {
                echo "failed to archive ATDLWA".$jahr2."KW".$woche.".TXT\n";
                $is_error = true;
            }
        }
    echo "fertig.\n";
#HISTORY
#    }

if($is_error == true) {
	$par['empfaenger'] = $reports_error_recipients;
	$par['message'] = array('subject'=>"(Exasol) Error FTP-Daten Downloads",'body_txt'=>"Bei Generierung AT-FTP-Daten Fehler aufgetreten.\n");
} else {
	$par['empfaenger'] = $reports_normal_recipients;
	$par['message'] = array('subject'=>"(Exasol) Erfolg FTP-Daten Downloads",'body_txt'=>"AT FTP-Daten generiert und verteilt.\n");
}
sendEMail($par);

?>
