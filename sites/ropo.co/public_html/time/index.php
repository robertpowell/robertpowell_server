<!DOCTYPE html>
<html lang="en">
<head>
    <meta http-equiv="Content-Security-Policy" content="default-src https:">
<meta charset="utf-8" />
<meta http-equiv="refresh" content="60">
<meta name="apple-mobile-web-app-capable" content="yes"/>
<title>Time</title>
<link rel="shortcut icon" type="image/x-icon" href="/favicon.ico">

<link rel="stylesheet" type="text/css" href="desktop.css" />

<!-- 
This css removed after it stopped working.
<link rel="stylesheet" type="text/css" href="iphone.css" media="only screen and (max-width: 563px)" /> -->

<link rel="stylesheet" type="text/css" href="MyFontsWebfontsKit.css">
<meta name="viewport" content="user-scalable=no, width=device-width">
</head>
<body>
<p>
<?php
include_once("analyticstracking.php");


# add to count
$c = fopen("count.txt","r+");
$prev = fread($c, 10);
$prev=$prev+1;
fclose ($c);
$c = fopen("count.txt","w+");
fwrite ($c,$prev);
fclose ($c);


#Create the London Hours array
date_default_timezone_set('Europe/London');
$lonHour = date("G");
$hour=$lonHour;
$min = date("i");
for ($i=0; $i<=23; $i++){
$lon[$i]=$hour;
$hour++;
if ($hour==24){
$hour=0;}
}
#$now=date("r");
$now=date("D, d-M-y H:i ");
$wkno=date("W");
if (date("I")==1){
$londst="+";
}


# write log file
$f = fopen("log.txt", "a");
fwrite ($f, $now." , ");
fwrite ($f, $_SERVER['REMOTE_ADDR']." , ");
fwrite ($f, $_SERVER['HTTP_USER_AGENT']."\n"); 
fclose ($f);

# Create the Vancouver hours array
date_default_timezone_set('America/Vancouver');
$vanhour = date("G");
$hour=$vanhour;
for ($i=0; $i<=23; $i++){
$van[$i]=$hour;
$hour++;
if ($hour==24){
$hour=0;}
}
if (date("I")==1){
$vandst="+";
}
$vantz=date("T");

# Create the NewYork hours array
date_default_timezone_set('America/new_york');
$nychour = date("G");
$hour=$nychour;
for ($i=0; $i<=23; $i++){
$nyc[$i]=$hour;
$hour++;
if ($hour==24){
$hour=0;}
}
if (date("I")==1){
$nycdst="+";
}
$nyctz=date("T");

# Create the Tokyo hours array
date_default_timezone_set('Asia/Tokyo');
$tokhour = date("G");
$hour=$tokhour;
for ($i=0; $i<=23; $i++){
$tok[$i]=$hour;
$hour++;
if ($hour==24){
$hour=0;}
}
if (date("I")==1){
$tokdst="+";
}
$toktz=date("T");


# Create the Singapore hours array
date_default_timezone_set('Asia/Singapore');
$sinhour = date("G");
$hour=$sinhour;
for ($i=0; $i<=23; $i++){
$sin[$i]=$hour;
$hour++;
if ($hour==24){
$hour=0;}
}
if (date("I")==1){
$sindst="+";
}
$sintz=date("T");

# Create the Sydney hours array
date_default_timezone_set('Australia/Sydney');
$sydhour = date("G");
$hour=$sydhour;
for ($i=0; $i<=23; $i++){
$syd[$i]=$hour;
$hour++;
if ($hour==24){
$hour=0;}
}
if (date("I")==1){
$syddst="+";
}
$sydtz=date("T");

# Create the CET hours array
date_default_timezone_set('Europe/Paris');
$cethour = date("G");
$hour=$cethour;
for ($i=0; $i<=23; $i++){
$cet[$i]=$hour;
$hour++;
if ($hour==24){
$hour=0;}
}
if (date("I")==1){
$cetdst="+";
}
$cettz=date("T");



#function
function getclass($hour){
if ($hour<7)
return "bgn";
elseif ($hour<19)
return "mid";
else 
return "end";
}
#function end

#now - loop through the arrays and the hours to produce the  .

echo($now."<span class='jclock'></span>");
echo("</p>");

#echo("<p id='note' class='red'>London is no longer in daylight saving</p>");
/*
echo("<p class='dark'>+=DST</p>");
echo("<table>
<thead>
<tr><th id='van' title='Pacific Standard Time - [PST]'>VAN$vandst<th id='nyc' title='Eastern Standard Time - [EST]'>NYC$nycdst<th id='lon' title='London-[UTC]'>LON$londst<th title='Singapore'>SIN$sindst<th title='Tokyo'>TOK$tokdst<th title='Sydney'>SYD$syddst</th></tr>
</thead>
<tbody>");
*/

//Includes Paris

echo "<p class='dark'>+=DST</p>";
echo "<table>
<thead>
<tr><th ".($vandst=="+" ? "title='Pacific Daylight Time'>PDT" : "title='Pacific Standard Time'>PST")."</th>
<th ".($nycdst=="+" ? "title='Eastern Daylight Time'>EDT" : "title='Eastern Standard Time'>EST")."</th>
<th ".($londst=="+" ? "title='British Summer Time'>BST" : "title='Greenwich Mean Time'>GMT")."</th>
<th ".($nycdst=="+" ? "title='Central European Summer Time'>CEST" : "title='Australian Eastern Daylight Time'>AEDT")."</th>
<th title='Singapore Time'>SGT</th>
<th title='Japan Standard Time'>JST</th>
<th ".($syddst=="+" ? "title='Australian Eastern Daylight Time'>AEDT" : "title='Australian Eastern Standard Time'>AEST")."</th>
<tr><th title='Seattle'>SEA$vandst<th title='New York - [EST]'>NYC$nycdst<th title='London'>LON$londst<th title='PARIS'>PAR$cetdst<th title='Singapore'>SIN$sindst<th title='Tokyo'>TOK$tokdst<th title='Sydney'>SYD$syddst</th></tr>
</thead>
<tbody>";



/*echo("</p><table>
<thead>
<tr><th title='Pacific Standard Time - [PST] (+ = Daylight Savings)'>VAN$vandst</th><th title='Eastern Standard Time - [EST] (+ = Daylight Savings)'>NYC$nycdst</th><th title='Grenwich Mean Time - [GMT] (+ = Daylight Savings)'>LON$londst</th><th title='Singapore & Hong Kong (+ = Daylight Savings)'>SIN<br/>HKG$sindst</th><th title='Tokyo (+ = Daylight Savings)'>TOK$tokdst</th></tr>

</thead>
<tbody>");
*/

echo('<tr><td class="');
echo(getclass($van[0]).'">');
echo(sprintf("%02d",$van[0]).':'.$min);
echo('<td class="');
echo(getclass($nyc[0]).'">');
echo(sprintf("%02d",$nyc[0]).':'.$min);
echo('<td class="');
echo(getclass($lon[0]).'">');
echo(sprintf("%02d",$lon[0]).':'.$min);
echo('<td class="');
echo(getclass($cet[0]).'">');
echo(sprintf("%02d",$cet[0]).':'.$min);
echo('<td class="');
echo(getclass($sin[0]).'">');
echo(sprintf("%02d",$sin[0]).':'.$min);
echo('<td class="');
echo(getclass($tok[0]).'">');
echo(sprintf("%02d",$tok[0]).':'.$min."</td>");
echo('<td class="');
echo(getclass($syd[0]).'">');
echo(sprintf("%02d",$syd[0]).':'.$min."</td></tr>");

/*echo('<td>');
echo('<a href="mailto:?body=');
echo('Vancouver----['.$vantz.']: '.sprintf("%02d",$van[0]).':00');
echo('%0ANew York-['.$nyctz.']: '.sprintf("%02d",$nyc[0]).':00');
echo('%0ALondon---[GMT]: '.sprintf("%02d",$lon[0]).':00');
echo('%0AEuropean-['.$cettz.']: '.sprintf("%02d",$cet[0]).':00');
echo('%0ASingapore['.$sintz.']: '.sprintf("%02d",$sin[0]).':00');
echo('%0ATokyo----['.$toktz.']: '.sprintf("%02d",$tok[0]).':00');
echo('%0ASydney---['.$sydtz.']: '.sprintf("%02d",$syd[0]).':00');

echo('"><button type="button">Email</button></a></td>');
*/

#loop starts here
#loop 24 times - once for each hour
for ($h=1; $h<=23; $h++){
#Make each row clickable to create an email containing the times on that row.

echo('<tr><td class="');
echo(getclass($van[$h]).'">');
echo(sprintf("%02d",$van[$h]).':00');
echo('</td><td class="');
echo(getclass($nyc[$h]).'">');
echo(sprintf("%02d",$nyc[$h]).':00');
echo('</td><td class="');
echo(getclass($lon[$h]).'">');
echo(sprintf("%02d",$lon[$h]).':00');
echo('</td><td class="');
echo(getclass($cet[$h]).'">');
echo(sprintf("%02d",$cet[$h]).':00');
echo('</td><td class="');
echo(getclass($sin[$h]).'">');
echo(sprintf("%02d",$sin[$h]).':00');
echo('</td><td class="');
echo(getclass($tok[$h]).'">');
echo(sprintf("%02d",$tok[$h]).':00');
echo('</td><td class="');
echo(getclass($syd[$h]).'">');
echo(sprintf("%02d",$syd[$h]).':00</td>');

/*echo('<td>');
echo('<a href="mailto:?body=');
echo('Vancouver        ['.$vantz.']: '.sprintf("%02d",$van[0]).':00');
echo('%0ANew York      ['.$nyctz.']: '.sprintf("%02d",$nyc[0]).':00');
echo('%0ALondon time   [GMT]: '.sprintf("%02d",$lon[0]).':00');
echo('%0AEuropean time ['.$cettz.']: '.sprintf("%02d",$cet[0]).':00');
echo('%0ASingapore time['.$sintz.']: '.sprintf("%02d",$sin[0]).':00');
echo('%0ATokyo time    ['.$toktz.']: '.sprintf("%02d",$tok[0]).':00');
echo('%0ASydney time   ['.$sydtz.']: '.sprintf("%02d",$syd[0]).':00');

echo('"><button type="button">Email</button></a></td>');
*/


#echo("<br />");
} #end of 24 loop
#loop ends here
echo("</tbody>
</table>");

function grid_cal($m,$y){
    $tabs = "\n\t\t\t\t";
    $w_days = array("M","T","W","T","F","S","S");
    $stamp = mktime(0,0,0,$m,1,$y);
    $maxday = date("t",$stamp);
    $thismonth = getdate ($stamp);
    $startday = $thismonth['wday']-1;         
?>
 
                <table>
                    <tr>
                        <td colspan="7"><?php echo date("F",$stamp).' '. date("Y",$stamp); ?></td>
                    </tr>
                    <tr class="cal-days">
                        <?php foreach($w_days as $d) { echo "$tabs\t\t<td>$d</td>"; } ?>
                    </tr>
<?php
    $curr_month = (date("F Y")==date("F Y",$stamp))? TRUE : FALSE; // if this calendar is displaying current month
    $today = date("j"); 
    for ($i=0; $i<($maxday+$startday); $i++) {
        if(($i % 7) == 0 ) echo "$tabs\t<tr>";
        $date_class = ($curr_month && $i - $startday + 1 == $today)? " class='bgn'": " class='end'"; 
        if($i < $startday) echo "$tabs\t\t<td></td>";
        else echo "$tabs\t\t<td{$date_class}>". ($i - $startday + 1) . "</td>";
        if(($i % 7) == 6 ) echo "$tabs\t</tr>\n";
    }
echo "$tabs</table>";
#echo "$i<br/>";
#echo "$maxday";
}

grid_cal(date("n"),date("Y"));
grid_cal((date("n")+1),date("Y"));
grid_cal((date("n")+2),date("Y"));


?>

<?php
/*
<caption>Public Holidays</caption>
<table>
<tr><th>Date</th><th>Day</th><th>Description</th><th>Country</th><th>Flag</th></tr>
<tr><td class="end">13 SEP</td><TD class="end">TUE</td><TD class="end">Day after Mid-Autumn Festival</td><TD class="end">Hong Kong</td><TD class="end"><img alt="Hong Kong" src="images/flags/HK.gif"></td></tr>
<tr><td class="end">05 OCT</td><TD class="end">WED</td><TD class="end">Chung Yeung Festival</td><TD class="end">Hong Kong</td><TD class="end"><img alt="Hong Kong" src="images/flags/HK.gif"></td></tr>
<tr><td class="end">10 OCT</td><TD class="end">MON</td><TD class="end">Columbus Day</td><TD class="end">USA</td><TD class="end"><img alt="USA" src="images/flags/US.gif"></td></tr>
<tr><td class="end">10 OCT</td><TD class="end">MON</td><TD class="end">Thanksgiving</td><TD class="end">Canada</td><TD class="end"><img alt="Canada" src="images/flags/CA.gif"></td></tr>
<tr><td class="end">26 OCT</td><TD class="end">WED</td><TD class="end">Deepvali</td><TD class="end">Singapore</td><TD class="end"><img alt="Singapore" src="images/flags/SG.gif"></td></tr>
<tr><td class="end">07 NOV</td><TD class="end">MON</td><TD class="end">Feast of Sacrifice</td><TD class="end">Singapore</td><TD class="end"><img alt="Singapore" src="images/flags/SG.gif"></td></tr>
<tr><td class="end">11 NOV</td><TD class="end">FRI</td><TD class="end">Remembrance Day</td><TD class="end">Canada</td><TD class="end"><img alt="Canada" src="images/flags/CA.gif"></td></tr>
<tr><td class="end">11 NOV</td><TD class="end">FRI</td><TD class="end">Veteran's Day</td><TD class="end">USA</td><TD class="end"><img alt="USA" src="images/flags/US.gif"></td></tr>
<tr><td class="end">24 NOV</td><TD class="end">THU</td><TD class="end">Thanksgiving</td><TD class="end">USA</td><TD class="end"><img alt="USA" src="images/flags/US.gif"></td></tr>
</table>
*/
?>

<?php
echo("<p>Week #: ".$wkno."</p>");
echo("<p>&copy; Robert Powell 2013. <span class='dark'>".$prev."</span></p>");
?>

<script src="jquery/jquery-1.7.1.min.js" type="text/javascript" charset="utf-8"></script>
<script src="jquery/jquery.animate-colors-min.js" type="text/javascript" charset="utf-8"></script>
<script src="jquery/jquery.jclock.js" type="text/javascript" charset="utf-8"></script>
<script type="text/javascript" src="/scripts/script.js"></script>

</body>
</html>