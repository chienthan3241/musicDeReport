<?php
$jobs = array(
    /*
    'emi-alben'                      => '* * * * *',
    'emi-compilations'               => '* * * * *',
    'emi-musicvideos'                => '* * * * *',
    'emi-singles'                    => '* * * * *',
    'ftp-daten-downloads-AT'         => '* * * * *',
    'ftp-daten-downloads_ora'        => '* * * * *',
    'ftp-monatesdaten-downloads_ora' => '* * * * *',
    'ftp-tagesdaten-downloads_ora'   => '* * * * *',
    'ftp-tagesdaten-downloads_ora'   => array(
                                        '* * * * *',
                                        '* * * * *', ),
     *//* HD 25228
    'emi-alben'                      => '30 14 * * 2',
    'emi-compilations'               => '30 14 * * 2',
    'emi-musicvideos'                => '30 14 * * 2',
    'emi-singles'                    => '30 14 * * 2',
    
	'ftp-daily-stream-topall-DE.php' => 'xx xx * * *' ,
	*/
    'ftp-top-charts-BMG'	     => '01 * * * *',
    'ftp-daten-top500-SOULFOOD'      => '00 16 * * *',
    'ftp-daten-top5000-BMG'	     => '00 16 * * *',
    'ftp-daten-downloads-AT'         => '00 21 * * 1',
    'groove_weekly_downloads_AT'     => '02 21 * * 1',
    'ftp-daten-downloads-CH'         => '00 21 * * 4',
    'ftp-daten-physical-CH' 	     => '00 13 * * 3',
    'ftp-daten-downloads_ora'        => '00 21 * * 1',
    'groove_weekly_downloads'        => '03 21 * * 1',
    'groove_monthly_downloads'       => '02 20 6 * *', 
    'ftp-monatesdaten-downloads_ora' => '00 20 6 * *',
    'groove_mothly_downloads_AT'     => '00 20 6 * *',    
    'ftp-tagesdaten-downloads_ora'   => array(
                                        '00 21 * * *',
                                        '00 12 * * *',
                                        '00 02 0 * 2'),
   'groove_daily_downloads'          => array(
                                        '00 21 * * *',
                                        '00 12 * * *',
                                        '00 02 0 * 2'),
   'ftp-daily-stream-topall-DE'      => '30 22 * * *',
   'ftp-daily-longplay-topall-DE-universal'      => '32 22 * * *',
   'ftp-monatesdaten-streams-sony'   => '00 12 10 * *'
);

// original crontab from 10.149.24.90:

/*
#EMI-Datei SINGLES & ALBEN fuer Physisch & Downloads
30 14 * * 2 cd /srv/www/musicdereports.music-panel.int/bin; bash ./emi-datei.sh > /home/web/log/emi_gen.log 2>&1
#FTP-Daten fuer Downloads
00 21 * * 1 cd /srv/www/musicdereports.music-panel.int/bin; bash ./ftp-daten-dwl.sh > /home/web/log/ftp_dl_gen.log 2>&1
#00 21 * * 2 cd /srv/www/musicdereports.music-panel.int/bin; bash ./ftp-daten-dwl.sh > /home/web/log/ftp_dl_gen.log 2>&1
00 21 * * * cd /srv/www/musicdereports.music-panel.int/bin; bash ./ftp-daten-dwl-tagesdaten.sh > /home/web/log/ftp_dl_gen_tagesdaten.log 2>&1
00 02 * * 2 cd /srv/www/musicdereports.music-panel.int/bin; bash ./ftp-daten-dwl-tagesdaten.sh > /home/web/log/ftp_dl_gen_tagesdaten.log 2>&1
00 20 6 * * cd /srv/www/musicdereports.music-panel.int/bin; bash ./ftp-daten-dwl-monatsdaten.sh > /home/web/log/ftp_dl_month_gen.log 2>&1
*/



