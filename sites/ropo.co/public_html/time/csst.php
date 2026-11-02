<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8" />
<meta http-equiv="refresh" content="60">
<meta name="apple-mobile-web-app-capable" content="yes"/>
<meta name="viewport" content="user-scalable=no, width=device-width">
<title>Time</title>
<link rel="shortcut icon" type="image/x-icon" href="/favicon.ico">

<style>
 @font-face {font-family: 'MuseoSans-500';src: url('webfonts/226D45_0_0.eot');src: url('webfonts/226D45_0_0.eot?#iefix') format('embedded-opentype'),url('webfonts/226D45_0_0.woff') format('woff'),url('webfonts/226D45_0_0.ttf') format('truetype');}
 
  body {
  font-family: MuseoSans-500;
  color: #000E26;
  background-color: #000000;
  color:#ffffff;
  }

.tab-cont {
	border: 1px solid white;
	}

.tab-row {
	max-width: 355px;
	padding: 5px;
	}
	
.tab-row:after{
	content: " ";
	clear: both;
}

.cal-row {
	max-width: 200px;
	}
	
.cal-row:after{
	content: "";
	clear: both;
}


.th, .bgn, .mid, .end{
width: 40px;
margin-left: 3px;
float: left;
padding: 3px;
padding-bottom: 0px;
margin-bottom: 3px;
text-align: center;
}

.th {
vertical-align: top;
background-color: black;
color: white;
}

  .bgn{
background-color: red;
color:black;
border-bottom-right-radius: 10px;
border-top-left-radius: 10px;
vertical-align: bottom;
  
  }
  .mid{
background-color:#0000ff;
color: #fff;
border-bottom-right-radius: 10px;
border-top-left-radius: 10px;
vertical-align: bottom;
  
  }
  .end{
background-color: #333333;
color: yellow;
border-bottom-right-radius: 10px;
border-top-left-radius: 10px;
vertical-align: bottom;
}

.dark{
margin:0px;
color:#b00000;
font-size:10px;
}
  
.cal-head{
width: 200px;
text-align: center;
float: left;
}
 
  
.cday{
width: 18px;
float: left;
margin-left: 3px;
padding: 3px;
padding-bottom: 0px;
border-bottom-right-radius: 10px;
border-top-left-radius: 10px;
text-align: center;
vertical-align: bottom;
}
  
.foot{
clear: both;;
}

.calcell{
width: 18px;
margin-left: 3px;
float: left;
padding: 3px;
padding-bottom: 0px;
margin-bottom: 3px;
text-align: center;
background-color: #333333;
color: yellow;
border-bottom-right-radius: 10px;
border-top-left-radius: 10px;
vertical-align: bottom;
}

.caltoday{
width: 18px;
margin-left: 3px;
float: left;
padding: 3px;
padding-bottom: 0px;
margin-bottom: 3px;
text-align: center;
background-color: red;
color:black;
border-bottom-right-radius: 10px;
border-top-left-radius: 10px;
vertical-align: bottom;
}
</style>
<script>
  (function(i,s,o,g,r,a,m){i['GoogleAnalyticsObject']=r;i[r]=i[r]||function(){
  (i[r].q=i[r].q||[]).push(arguments)},i[r].l=1*new Date();a=s.createElement(o),
  m=s.getElementsByTagName(o)[0];a.async=1;a.src=g;m.parentNode.insertBefore(a,m)
  })(window,document,'script','//www.google-analytics.com/analytics.js','ga');
  ga('create', 'UA-52204307-1', 'ropo.co');
  ga('send', 'pageview');
</script>
</head>
<body>
<p>
<?php
# add to count
$c = fopen("css-count.txt","r+");
$prev = fread($c, 10);
$prev=$prev+1;
fclose ($c);
$c = fopen("css-count.txt","w+");
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

//show the current clock & refresh
echo($now."<span class='jclock'></span>");
echo("</p>");

echo "<p class='dark'>+=DST</p>";

// table starts here
// max width for ios was 375px
// max width per cell is 53px (44px+9px)

echo "<div class='tab-row'>
<div class='th' ".($vandst=="+" ? "title='Pacific Daylight Time'>PDT" : "title='Pacific Standard Time'>PST")."</div>
<div class='th' ".($nycdst=="+" ? "title='Eastern Daylight Time'>EDT" : "title='Eastern Standard Time'>EST")."</div>
<div class='th' ".($londst=="+" ? "title='British Summer Time'>BST" : "title='Greenwich Mean Time'>GMT")."</div>
<div class='th' ".($nycdst=="+" ? "title='Central European Summer Time'>CEST" : "title='Central European Time'>CET")."</div>
<div class='th'  title='Singapore Time'>SGT</div>
<div class='th'  title='Japan Standard Time'>JST</div>
<div class='th' ".($syddst=="+" ? "title='Australian Eastern Daylight Time'>AEDT" : "title='Australian Eastern Standard Time'>AEST")."</div>

<div class='th' title='Seattle'>SEA$vandst</div>
<div class='th' title='New York - [EST]'>NYC$nycdst</div>
<div class='th' title='London'>LON$londst</div>
<div class='th' title='PARIS'>PAR$cetdst</div>
<div class='th' title='Singapore'>SIN$sindst</div>
<div class='th' title='Tokyo'>TOK$tokdst</div>
<div class='th' title='Sydney'>SYD$syddst</div>";

// this section prints the current time
echo('<div class="');
echo(getclass($van[0]).'">');
echo(sprintf("%02d",$van[0]).':'.$min."</div>");
echo('<div class="');
echo(getclass($nyc[0]).'">');
echo(sprintf("%02d",$nyc[0]).':'.$min."</div>");
echo('<div class="');
echo(getclass($lon[0]).'">');
echo(sprintf("%02d",$lon[0]).':'.$min."</div>");
echo('<div class="');
echo(getclass($cet[0]).'">');
echo(sprintf("%02d",$cet[0]).':'.$min."</div>");
echo('<div class="');
echo(getclass($sin[0]).'">');
echo(sprintf("%02d",$sin[0]).':'.$min."</div>");
echo('<div class="');
echo(getclass($tok[0]).'">');
echo(sprintf("%02d",$tok[0]).':'.$min."</div>");
echo('<div class="');
echo(getclass($syd[0]).'">');
echo(sprintf("%02d",$syd[0]).':'.$min."</div>");

#loop starts here
#loop 24 times - once for each hour
for ($h=1; $h<=23; $h++){

echo('<div class="');
echo(getclass($van[$h]).'">');
echo(sprintf("%02d",$van[$h]).':00</div>');
echo('<div class="');
echo(getclass($nyc[$h]).'">');
echo(sprintf("%02d",$nyc[$h]).':00</div>');
echo('<div class="');
echo(getclass($lon[$h]).'">');
echo(sprintf("%02d",$lon[$h]).':00</div>');
echo('<div class="');
echo(getclass($cet[$h]).'">');
echo(sprintf("%02d",$cet[$h]).':00</div>');
echo('<div class="');
echo(getclass($sin[$h]).'">');
echo(sprintf("%02d",$sin[$h]).':00</div>');
echo('<div class="');
echo(getclass($tok[$h]).'">');
echo(sprintf("%02d",$tok[$h]).':00</div>');
echo('<div class="');
echo(getclass($syd[$h]).'">');
echo(sprintf("%02d",$syd[$h]).':00</div>');
} #end of 24 loop
#loop ends here

echo("</div>"); #end of the main times table

function grid_cal($m,$y){
    $tabs = "\n";
    $w_days = array("M","T","W","T","F","S","S");
    $stamp = mktime(0,0,0,$m,1,$y);
    $maxday = date("t",$stamp);
    $thismonth = getdate ($stamp);
    $startday = $thismonth['wday']-1;         
?>
<div class='cal-row'>
<div class='cal-head'><?php echo date("F",$stamp).' '. date("Y",$stamp); ?></div>

 <?php foreach($w_days as $d) { echo "$tabs<div class='cday'>$d</div>"; } ?>

<?php
    $curr_month = (date("F Y")==date("F Y",$stamp))? TRUE : FALSE; // if this calendar is displaying current month
    $today = date("j"); 
    for ($i=0; $i<($maxday+$startday); $i++) {
        //if(($i % 7) == 0 ) echo "$tabs\t<tr>";
        $date_class = ($curr_month && $i - $startday + 1 == $today)? " class='caltoday'": " class='calcell'"; 
        if($i < $startday) echo "$tabs<div class='calcell'>-</div>";
        else echo "$tabs<div {$date_class}>". ($i - $startday + 1) . "</div>";
        //if(($i % 7) == 6 ) echo "$tabs\t</tr>\n";
    }
echo "$tabs</div>";
#echo "$i<br/>";
#echo "$maxday";
}
grid_cal(date("n"),date("Y"));
grid_cal((date("n")+1),date("Y"));
grid_cal((date("n")+2),date("Y"));
?>

<?php
echo("<div class='foot'><div>Week #: ".$wkno."</div>");
echo("<div>&copy; Robert Powell 2013. <span class='dark'>".$prev."</span></div></div>");
?>

<script async src="jquery/jquery-1.7.1.min.js" type="text/javascript" charset="utf-8"></script>
<script async src="jquery/jquery.jclock.js" type="text/javascript" charset="utf-8"></script>
<script async src="/scripts/script.js" type="text/javascript"></script>
</body>
</html>