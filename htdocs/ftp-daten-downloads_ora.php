<?php

require_once(dirname(__FILE__).'/../lib/global.php');

require_once('MDB2.php');
require_once(CFG_PATH.'db.conf.php');

require_once 'Mail.php';
require_once 'Mail/mime.php';
require_once 'MIME/Type.php';

//Abbruch durch Benutzer ignorieren
#ignore_user_abort(true);

$uni        = array(8001);
$emi        = array(8003,8025,8029,8065);
$sonybmg    = array(8002);
$warner     = array(8005);
$groove     = array(8009,8019,8135);

$is_error = false;
$error_msg = '';

$dsn = $exasol_dsn;
$db = MDB2::connect($dsn);
if (MDB2::isError($db)) {
    $par['empfaenger'] = $reports_error_recipients;
    $par['message'] = array('subject'=>"Error FTP-Daten Downloads",
        'body_txt'=>"(Exasol) can not connect to the database.\n");

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
#$zeit = '201516';
$jahr2 = substr($zeit,2,2);
$jahr4 = substr($zeit,0,4);
$woche = substr($zeit,-2,2);

$uarr = array();
$udata = array();

$sql = "
  SELECT   s1.*,
           stamm_asnr AS seanc,
           stamm_eancid AS seanc_id,
           sprnr,
           stamm_ag_txt AS tart,
           stamm_agid AS tartid,
           stamm_firm_code AS firmid,
           stamm_artnr AS satnr,
           stamm_titel AS stitl,
           stamm_artist AS sinte,
           stamm_label_txt AS slabl,
           stamm_herst_txt AS vertrieb_lang,
           stamm_herst_txt2 AS vertrieb,
           stamm_archivnr AS archivnr
    FROM   (SELECT   *
              FROM   (SELECT   ROWNUM AS index_, daten.*
                        FROM   (  SELECT /*+ REWRITE */
                                           stamm_prodnr AS sprnr,
                                           SUM (bwg_wert) AS sum_wert,
                                           SUM (bwg_menge) AS sum_meng,
                                           SUM (bwg_wert_abs) AS sum_wert_abs,
                                           SUM (bwg_menge_abs) AS sum_meng_abs
                                    FROM   bewegung_w, region, stamm_gesamt
                                   WHERE       bwg_zeitkey = $zeit
                         AND BWG_CONT_DIST IN ('641','645')
                                           AND stamm_landid = 1054
                                           AND bwg_landid = 1054
                                           AND region_landid = 1054
                                           AND bwg_appid = 12
                                           AND stamm_appid = 12
                                           AND region_appid = 12
                                           AND bwg_haendlerid = region_haendlerid
                                           AND stamm_eancid = bwg_eancid
                                           AND stamm_type_flag = 'D'
                                           AND stamm_lauf = 'DWN'
                                           AND NVL(stamm_quelle, 'EMPTY') <> 'MA'
                                           AND (region_awid IN
                                           (SELECT region_awid
                                              FROM region
                                             WHERE region_awid NOT IN
                                                      (240, 243, 2)
                                                   AND region_appid = 12
                                                   AND region_landid = 1054))
                                GROUP BY   stamm_prodnr
                                ORDER BY   SUM (bwg_menge) DESC) daten)) s1,
           stamm_gesamt
   WHERE       s1.sprnr = SUBSTR (stamm_gesamt.stamm_eancid, 2)
           AND stamm_type_flag = 'D'
           AND stamm_lauf = 'DWN'
           AND stamm_landid = 1054
           AND stamm_is_header = 1
GROUP BY   sprnr,
           stamm_ag_txt,
           stamm_agid,
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

echo "Querying $zeit...\n";

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
            if(isset($udata[$arr['vertrieb']])) {
                    $firma = $udata[$arr['vertrieb']];
            } else {
                    $firma = '';
            }
            $arr = array_utf_to_iso($arr);
            #ISRC could sometimes be null. in this case, simply fill it with 13 times 9
            $data[$ct]['ISRC']          = (isset($arr['satnr']) and strlen($arr['satnr']) > 0) ? $arr['satnr'] : '9999999999999';
            $data[$ct]['Titel']         = (isset($arr['stitl']) and strlen($arr['stitl']) > 0) ? $arr['stitl'] : '          ';
            $data[$ct]['Interpret']     = (isset($arr['sinte']) and strlen($arr['sinte']) > 0) ? $arr['sinte'] : '          ';
            $data[$ct]['Menge'] = $arr['sum_meng'];
            $data[$ct]['Firma'] = $firma;
            $data[$ct]['Label'] = $arr['slabl'];
            $data[$ct]['Vertrieb'] = $arr['vertrieb'];
            $data[$ct]['FirmID'] = $arr['firmid'];
            $data[$ct]['Archivnr'] = trim($arr['archivnr']);
            $data[$ct]['Umsatz'] = $arr['sum_wert'];
            $data[$ct]['Tart'] = trim($arr['tart']);
            $data[$ct]['TartID'] = $arr['tartid'];
        }
        $ct++;
    }
    $rs->free();
}
/*
echo "Schreibe Groove-File...";

if(($dlgroove = fopen($nfs_root_dir . 'temp/DLGR'.$jahr2.'KW'.$woche.'.TXT', 'w')) === FALSE){
    $is_error = true;
    $error_msg .= 'can not create file buffer.\n';
} else {
    foreach ($data as $c=>$dt) {
        if(in_array($dt['FirmID'], $groove)) {
            fwrite($dlgroove, $zeit.';');
            fwrite($dlgroove, date('Ymd',thursdaykw($woche,$jahr4)).';');
            fwrite($dlgroove, date('Ymd',thursdaykw($woche,$jahr4,'+')).';');  #date end
            fwrite($dlgroove, $dt['ISRC'].';');                                #ISRC
            fwrite($dlgroove, '"'.$dt['Interpret'].'";');                      #Artist
            fwrite($dlgroove, '"'.$dt['Titel'].'";');                          #Title
            fwrite($dlgroove, ';');                                            #Artikel nr.
            fwrite($dlgroove, $dt['TartID'].';');                              #Tart Code
            fwrite($dlgroove, number_format($dt['Menge'],0,",","").';');       #Menge
            fwrite($dlgroove, $dt['FirmID'].';');                              #Phononet Nummer
            fwrite($dlgroove, ';');                                            #Artikel nr.
            fwrite($dlgroove, number_format(($dt['Menge'] == 0 ? 0 : ($dt['Umsatz']/$dt['Menge'])),2,",","")); #Final Price
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
                    "abs_path" => $nfs_root_dir . 'temp/DLGR'.$jahr2.'KW'.$woche.'.TXT',
                    "rel_path" => 'DLGR'.$jahr2.'KW'.$woche.'.TXT'
                )
            ),
            $nfs_root_dir . 'temp/DLGR'.$jahr2.'KW'.$woche.'.ZIP',
            true
        );

if($is_error){
    $error_msg .= 'can not zip file.\n';
}
echo "fertig.\n";
*/
echo "Schreibe Uni-File...";

if(($dluni = fopen($nfs_root_dir . 'temp/DLUN'.$jahr2.'KW'.$woche.'.TXT', 'w')) === FALSE){
    $is_error = true;
    $error_msg .= "can not create file buffer.\n";
} else {
    foreach ($data as $c=>$dt) {
        fwrite($dluni, $zeit.';');
        fwrite($dluni, date('Ymd',thursdaykw($woche,$jahr4)).';');
        fwrite($dluni, date('Ymd',thursdaykw($woche,$jahr4,'+')).';');
        fwrite($dluni, $dt['ISRC'].';');
        fwrite($dluni, '"'.$dt['Interpret'].'";');
        fwrite($dluni, '"'.$dt['Titel'].'";');
        fwrite($dluni, ';');
        fwrite($dluni, ';');
        fwrite($dluni, number_format($dt['Menge'],0,",","").';');
        fwrite($dluni, $dt['Firma'].';');
        fwrite($dluni, $dt['Label'].';');
        if(in_array($dt['FirmID'], $uni) or $zeit >= "201136") {
            fwrite($dluni, number_format($dt['Umsatz'],2,",","").';');
        } else {
            fwrite($dluni, number_format(0,2,",","").';');
        }
        fwrite($dluni,"\n");
        #   fwrite($dluni, $dt['Archivnr']."\n");
    }

    fclose($dluni);

    echo "fertig.\n";
}
/*
echo "Schreibe Emi-File...";

if(($dlemi = fopen($nfs_root_dir . 'temp/DLEM'.$jahr2.'KW'.$woche.'.TXT', 'w')) === FALSE) {
    $is_error = true;
    $error_msg .= "can not create file buffer.\n";
} else {
    foreach ($data as $c=>$dt) {
        if(in_array($dt['FirmID'], $emi)){
            fwrite($dlemi, substr($dt['Interpret'],0,30)."\t");
            fwrite($dlemi, substr($dt['Titel'],0,30)."\t");
            fwrite($dlemi, substr($dt['ISRC'],0,13)."\t");
            fwrite($dlemi, number_format(number_format($dt['Menge'],0,",",""),6,",","")."\t");
            if(in_array($dt['FirmID'], $emi) or $zeit >= "201136") {
                fwrite($dlemi, number_format(number_format($dt['Umsatz'],0,",",""),6,",","")."\n");
            } else {
                fwrite($dlemi, number_format(0,6,",","")."\n");
            }
        }
    }

    fclose($dlemi);

    echo "fertig.\n";
}
*/
echo "Schreibe SonyBMG-File...";

if(($dlsony = fopen($nfs_root_dir . 'temp/DLBM'.$jahr2.'KW'.$woche.'.APA', 'w')) === FALSE) {
    $is_error = true;
    $error_msg .= "can not create file buffer.\n";
} else {
    $artschl = '000000_____________';
    $ean = '             ';
    $titinte = '                              ';
    foreach ($data as $c=>$dt) {
        fwrite($dlsony,substr_replace($artschl,$dt['ISRC'],0-strlen($dt['ISRC'])));
        fwrite($dlsony,'EAN            '); //ean
        fwrite($dlsony,'EIN');             //ein
        if(!isset($dt['FirmID']) or $dt['FirmID'] == 0 or $dt['FirmID'] == '') {            //phnr
            fwrite($dlsony,'0000         ');
        } else {
            fwrite($dlsony, $dt['FirmID'] .'         ');
        }
        fwrite($dlsony,substr_replace($ean,$dt['ISRC'],0,strlen($dt['ISRC']))); //ISRC
        fwrite($dlsony,substr_replace($titinte,substr($dt['Interpret'],0,30),0-strlen(substr($dt['Interpret'],0,30))));
        fwrite($dlsony,substr_replace($titinte,substr($dt['Titel'],0,30),0-strlen(substr($dt['Titel'],0,30))));
        fwrite($dlsony,'            '); //phononet artikel nummer
        #fwrite($dlsony,'     ');       //tontraegeart nr
        fwrite($dlsony,substr_replace('     ',substr($dt['TartID'],15),0,strlen(substr($dt['TartID'], 15))));   //tontraegeart nr
        #fwrite($dlsony,$titinte);      //tontraegerart bezeichnung
        fwrite($dlsony,substr_replace($titinte,substr($dt['Tart'],0,30),0-strlen(substr($dt['Tart'],0,30))));
        fwrite($dlsony,$titinte);
        fwrite($dlsony,$titinte);
        if(strlen($dt['Label']) == 0) {
            fwrite($dlsony, '          ');
        } else {
            fwrite($dlsony,substr_replace('          ',substr($dt['Label'],0,10),0-strlen(substr($dt['Label'],0,10))));
        }
        fwrite($dlsony,'   ');
        fwrite($dlsony,'     ');
        fwrite($dlsony,'       ');
        fwrite($dlsony,'      ');
        fwrite($dlsony,'  ');
        #   fwrite($dlsony,"     \n");
        fwrite($dlsony,substr_replace('     ',$dt['Archivnr'],0-strlen($dt['Archivnr']))."\n");
    }
    fclose($dlsony);
}

if(($dlsony = fopen($nfs_root_dir . 'temp/DLBM'.$jahr2.'KW'.$woche.'.DAT', 'w')) === FALSE) {
    $is_error = true;
    $error_msg .= "can not create file buffer.\n";
} else {
    $mengwert = '000000000000000';
    foreach ($data as $c=>$dt) {
        fwrite($dlsony,date('ymd',thursdaykw($woche,$jahr4)).'_'.date('ymd',thursdaykw($woche,$jahr4,'+')));
        fwrite($dlsony,'00000001');
        fwrite($dlsony,substr_replace($artschl,$dt['ISRC'],0-strlen($dt['ISRC'])));
        fwrite($dlsony,substr_replace($mengwert, number_format($dt['Menge'],0,".",""),0-strlen(number_format($dt['Menge'],0,".",""))));

        if(in_array($dt['FirmID'], $sonybmg) or $zeit >= "201136") {
            fwrite($dlsony, substr_replace($mengwert, number_format($dt['Umsatz'],2,".",""),0-strlen(number_format($dt['Umsatz'],2,".",""))));
        } else {
            fwrite($dlsony, substr_replace($mengwert, number_format(0,2,".",""),0-strlen(number_format(0,2,".",""))));
        }
        fwrite($dlsony,$mengwert);
        fwrite($dlsony,$mengwert);
        fwrite($dlsony,$mengwert."\n");
    }
    fclose($dlsony);
}
echo "fertig.\n";

echo "Creating Tar for Sony-File...\n";
#"tar -C t/ DLBM10KW41.DAT DLBM10KW41.APA -cf t/DLBM10KW41.TAR";
$script = "tar -C $nfs_root_dir" . "temp/ DLBM" . $jahr2 . 'KW' . $woche . '.DAT'
            . " " . 'DLBM' . $jahr2 . 'KW' . $woche . '.APA'
            . " -cf " . "$nfs_root_dir" . "temp/DLBM" . $jahr2 . 'KW' . $woche . '.TAR';
$exec_output = array();
exec($script, $exec_output);
echo "Zip Sony-File...\n";
$is_error = !create_zip(
    array(
        array(
            "abs_path" => $nfs_root_dir . 'temp/DLBM'.$jahr2.'KW'.$woche.'.TAR',
            "rel_path" => 'DLBM'.$jahr2.'KW'.$woche.'.TAR'
        )
    ),
    $nfs_root_dir . 'temp/DLBM'.$jahr2.'KW'.$woche.'.ZIP',
    true
);
if($is_error){
    $error_msg .= "can not create file buffer.\n";
}

echo "fertig.\n";

echo "Schreibe Warner-File...";
if(($dlwarner = fopen($nfs_root_dir . 'temp/DLWM'.$jahr2.'KW'.$woche.'.TXT', 'w')) === FALSE) {
    $is_error = true;
    $error_msg .= "can not create file buffer.\n";
} else {
    $ct = 1;
    foreach ($data as $c=>$dt) {
        $dt['Vertrieb'] = trim($dt['Vertrieb']);
        if (!isset($dt['Vertrieb']) || $dt['Vertrieb'] == '' || $dt['Vertrieb'] == null) {
            $dt['Vertrieb'] = '???';
        }
        fwrite($dlwarner, '"'.$ct.'";');
        fwrite($dlwarner, '"'.$dt['ISRC'].'";');
        fwrite($dlwarner, '"'.$dt['Interpret'].'";');
        fwrite($dlwarner, '"'.$dt['Titel'].'";');
        fwrite($dlwarner, '"'.$dt['Vertrieb'].'";');
        if(in_array($dt['FirmID'], $warner) or $zeit >= "201136") {
            if($dt['Menge'] != 0) {
                fwrite($dlwarner, '"'.(number_format($dt['Umsatz']/$dt['Menge'],2,",","")).'";');
            } else {
                fwrite($dlwarner, '"'.number_format(0,2,",","").'";');
            }
        } else {
            fwrite($dlwarner, '"'.number_format(0,2,",","").'";');
        }
        fwrite($dlwarner, '"'.number_format($dt['Menge'],0,",","").'";');
        fwrite($dlwarner, '"'.$jahr4.'";');
        fwrite($dlwarner, '"'.$woche.'";');
        fwrite($dlwarner, '"'.$dt['Archivnr'].'";');
        fwrite($dlwarner, "\n");
        $ct++;
    }
    fclose($dlwarner);
}

echo "fertig.\n";

echo "Upload die Dateien auf die entsprechenden FTP-Laufwerke...";

$ftp = new FTP;
$ftp->connect($ftp_host);
$ftp->login($ftp_username, $ftp_password);

//Groove
/*if( ! $ftp->put($target_path_de . '/GROOVE/DLGR' . $jahr2 . 'KW' . $woche . ".ZIP",
        $nfs_root_dir . 'temp/DLGR' . $jahr2 . 'KW' . $woche . '.ZIP', FTP_BINARY)){
        echo "Groove upload failed.\n";
        $is_error = true;
}
*/
//Warner
if( ! $ftp->put($target_path_de . '/WARNER/DOWNLOAD/SALES/DLWM' . $jahr2 . 'KW' . $woche . '.TXT',
          $nfs_root_dir . 'temp/DLWM' . $jahr2 . 'KW' . $woche . '.TXT', FTP_BINARY)){
        echo "Warner upload failed.\n";
        $is_error = true;
}

//Universal
if( ! $ftp->put($target_path_de . '/UNIVERSAL/DLUN' . $jahr2 . 'KW' . $woche . '.TXT',
          $nfs_root_dir . 'temp/DLUN' . $jahr2 . 'KW' . $woche . '.TXT', FTP_BINARY)){
        echo "Universal upload failed.\n";
        $is_error = true;
}
/*
//EMI
if( ! $ftp->put($target_path_de . '/EMI/SALES/DLEM' . $jahr2 . 'KW' . $woche . '.TXT',
          $nfs_root_dir . 'temp/DLEM' . $jahr2 . 'KW' . $woche . '.TXT', FTP_BINARY)){
        echo "EMI upload failed.\n";
        $is_error = true;
}
*/
//SonyBMG
if( ! $ftp->put($target_path_de . '/BMG/DLBM' . $jahr2 . 'KW' . $woche . '.ZIP',
          $nfs_root_dir . 'temp/DLBM' . $jahr2 . 'KW' . $woche . '.ZIP', FTP_BINARY)){
        echo "SonyBMG upload failed.\n";
        $is_error = true;
}

$ftp->close();
echo "Done.\n";

if (!rmdirr($nfs_root_dir . 'temp/DLBM' . $jahr2 . 'KW' . $woche . '.TAR')){
        echo "failed to delete DLBM" . $jahr2 . "KW" . $woche . ".TAR\n";
    $is_error = true;
    $error_msg .= "can not upload file to ftp server.\n";
}

echo "fertig.\n";

echo "Generierte Dateien in Archiv verschieben...";
if (!mkdir($nfs_root_dir . "temp/" . $zeit) and !(rmdirr($nfs_root_dir . 'temp/'.$zeit) and mkdir($nfs_root_dir . 'temp/'.$zeit))) {
    echo "failed to create directory: ".$nfs_root_dir . 'temp/'.$zeit;
    $is_error = true;
} else {
    //Groove Attack
    /*if (!rename($nfs_root_dir . 'temp/DLGR'.$jahr2.'KW'.$woche.'.TXT', $nfs_root_dir . 'temp/'.$zeit.'/DLGR'.$jahr2.'KW'.$woche.'.TXT')) {
        echo "failed to archive DLGR".$jahr2."KW".$woche.".TXT\n";
        $is_error = true;
    }*/
    //Warner
    if (!rename($nfs_root_dir . 'temp/DLWM'.$jahr2.'KW'.$woche.'.TXT', $nfs_root_dir . 'temp/'.$zeit.'/DLWM'.$jahr2.'KW'.$woche.'.TXT')) {
        echo "failed to archive DLWM".$jahr2."KW".$woche.".TXT\n";
        $is_error = true;
    }

    //Universal
    if (!rename($nfs_root_dir . 'temp/DLUN'.$jahr2.'KW'.$woche.'.TXT', $nfs_root_dir . 'temp/'.$zeit.'/DLUN'.$jahr2.'KW'.$woche.'.TXT')) {
        echo "failed to archive DLUN".$jahr2."KW".$woche.".TXT\n";
        $is_error = true;
    }
/*
    //EMI
    if (!rename($nfs_root_dir . 'temp/DLEM'.$jahr2.'KW'.$woche.'.TXT',$nfs_root_dir . 'temp/'.$zeit.'/DLEM'.$jahr2.'KW'.$woche.'.TXT')) {
        echo "failed to archive DLEM".$jahr2."KW".$woche.".TXT\n";
        $is_error = true;
    }
*/
    //SonyBMG
    if (!rename($nfs_root_dir . 'temp/DLBM'.$jahr2.'KW'.$woche.'.APA', $nfs_root_dir . 'temp/'.$zeit.'/DLBM'.$jahr2.'KW'.$woche.'.APA')) {
        echo "failed to archive DLBM".$jahr2."KW".$woche.".APA\n";
        $is_error = true;
    }
    if (!rename($nfs_root_dir . 'temp/DLBM'.$jahr2.'KW'.$woche.'.DAT', $nfs_root_dir . 'temp/'.$zeit.'/DLBM'.$jahr2.'KW'.$woche.'.DAT')) {
        echo "failed to archive DLBM".$jahr2."KW".$woche.".DAT\n";
        $is_error = true;
    }
        if (!rename($nfs_root_dir . 'temp/DLBM'.$jahr2.'KW'.$woche.'.ZIP', $nfs_root_dir . 'temp/'.$zeit.'/DLBM'.$jahr2.'KW'.$woche.'.ZIP')) {
        echo "failed to archive DLBM".$jahr2."KW".$woche.".ZIP\n";
        $is_error = true;
    }
}
echo "fertig.\n";

if($is_error == true) {
    $par['empfaenger'] = $reports_error_recipients;
    $par['message'] = array('subject'=>"(Exasol) Error FTP-Daten Downloads",'body_txt'=>"there was an error by reports generation.\n");
} else {
    $par['empfaenger'] = $reports_normal_recipients;
    $par['message'] = array('subject'=>"(Exasol) Erfolg FTP-Daten Downloads",'body_txt'=>"FTP-Daten generiert und verteilt.\n");
}
sendEMail($par);



?>
