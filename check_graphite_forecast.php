#!/usr/bin/php
<?php

$longopts=[];
$shortopts ="H:";$longopts[] ="host:";
$shortopts.="P:";$longopts[] ="port:";
$shortopts.="Q:";$longopts[] ="query:";
$shortopts.="T:";$longopts[] ="time:";
$shortopts.="M:";$longopts[] ="max:";
$shortopts.="h";$longopts[] = "help";
$shortopts.="c:";$longopts[] = "critical:";
$shortopts.="w:";$longopts[] = "warning:";
$shortopts.="v";$longopts[] = "verbose";

function print_help_message(){
  echo "Help:\n";
  echo "\t-H, --host\t\tGraphite Host that should be checked\n";
  echo "\t-P, --port\t\tPort of the Graphite Host\n";
  echo "\t-Q, --query\t\tWich Series of Data to Query\n";
  echo "\t-T, --time\t\tRealtive Timerange. Use the following Format:\n";
  echo "\t\t\t\thttps://graphite.readthedocs.io/en/latest/render_api.html#from-until \n";
  echo "\t-m, --max\t\tCheck if the trend hit this line\n";
  exit(3);
}

$opts = getopt($shortopts, $longopts);

function handle_opt($opts,$shortopt,$longopt,$optional=false){
  if($optional){
      if(array_key_exists($shortopt,$opts)){
        if($shortopt=="h"){
          return "help";
        }
        if($shortopt=="v"){
          return "verbose";
        }
        return $opts[$shortopt];
      }elseif(array_key_exists($longopt,$opts)){
        if($longopt=="help"){
          return "help";
        }
        if($longopt=="verbose"){
          return "verbose";
        }
        return $opts[$longopt];
      }else{
        return null;
      }
  }else{
    if(!(array_key_exists($shortopt,$opts) xor array_key_exists($longopt,$opts))){
      echo "Please specify -$shortopt or --$longopt\n";
      print_help_message();
      exit(3);
    }else{
      if(array_key_exists($shortopt,$opts)){
        return $opts[$shortopt];
      }else{
        return $opts[$longopt];
      }
    }
  }
}

function parse_threshold($threshold){
  if(preg_match("/^(?P<number>\d+)(?P<unit>[hd])$/",$threshold,$matches)){
    if($matches["unit"]=="h"){
      return 60*60*$matches["number"];
    }else{
      return 60*60*24*$matches["number"];
    }
  }else{
    echo "Threshold '$threshold'  has wrong format\n";
    exit(3);
  }
}

function verbose($verbose,$msg){
  if($verbose=="verbose"){
    // blue
    echo "\033[34m[VERBOSE] $msg\033[0m\n";
  }
}

$host=handle_opt($opts,'H','host');
$port=handle_opt($opts,'P','port');
$target=handle_opt($opts,'Q','query');
$from="-".handle_opt($opts,'t','time');
$max=handle_opt($opts,'M','max',true);
$warning=handle_opt($opts,'w','warning',true);
$critical=handle_opt($opts,'c','critical',true);
$help=handle_opt($opts,'h','help',true);
$v=handle_opt($opts,'v','verbose',true);

if($help == "help"){
  print_help_message();
}

verbose($v,print_r($opts,true));

$format="json";

$url = "http://$host:$port/render?target=$target&from=$from&format=$format";
$json = file_get_contents($url);
$graphite_data = json_decode($json);
$dateformat="Y-m-d H:i:s";
$datapoints=$graphite_data[0]->datapoints;


/* Test Datapoints
$datapoints=[
  1 => [2,1],
  2 => [2,2],
  3 => [3,3],
  4 => [3,4],
  5 => [4,5],
  6 => [4,6],
];
 */
$values=[];
$timestamps=[];
$lastvalue=0;
$lasttimestamp=0;
foreach($datapoints as $datapoint){
  if ($datapoint[0]!=""){
    //echo gmdate($dateformat, $datapoint[1])." ";
    //echo $datapoint[1]." ".$datapoint[0]."\n";
    $values[]=$datapoint[0];
    $timestamps[]=$datapoint[1];
    $lastvalue=$datapoint[0];
    $lasttimestamp=$datapoint[1];
  }
}
$n=count($values);
function linear_regression( $x, $y ) {
 
    $n     = count($x);     // number of items in the array
    $x_sum = array_sum($x); // sum of all X values
    $y_sum = array_sum($y); // sum of all Y values
 
    $xx_sum = 0;
    $xy_sum = 0;
 
    for($i = 0; $i < $n; $i++) {
        $xy_sum += ( $x[$i]*$y[$i] );
        $xx_sum += ( $x[$i]*$x[$i] );
    }
 
    // Slope
    $slope = ( ( $n * $xy_sum ) - ( $x_sum * $y_sum ) ) / ( ( $n * $xx_sum ) - ( $x_sum * $x_sum ) );
 
    // calculate intercept
    $intercept = ( $y_sum - ( $slope * $x_sum ) ) / $n;
 
    return array( 
        'slope'     => $slope,
        'intercept' => $intercept,
    );
}
$lr=linear_regression($timestamps,$values);
$slope=$lr["slope"];
$intercept=$lr["intercept"];
#if($max == null){
verbose($v, "Found $n datapoints");
#  echo "Last Value: $lastvalue at ".gmdate($dateformat,$lasttimestamp)."\n";
#  echo "slope: $slope ";
#  echo "intercept: $intercept\n";
#  exit(3);
#}

if($slope < 0){
  $when = (0 + ($intercept * -1)) / $slope;
  $date = gmdate($dateformat, $when);
  if(isset($max)){
    // we want to know if the counter hits the max value
    // this is not the cases here
    echo "[OK] The counter will reach 0 at $date\n";
    exit(0);
  }else{
    // The counter can hit 0 so we check against thresholds
    if($when <= time() + parse_threshold($warning)){
      echo "[WARNING] The counter will reach 0 at $date\n";
      exit(1);
    }elseif($when <= time() + parse_threshold($critical)){
      echo "[CRITICAL] The counter will reach 0 at $date\n";
      exit(2);
    }else{
      echo "[OK] The counter will reach 0 at $date\n";
      exit(0);
    }
  }
}elseif($slope == 0){
  // with slope == 0 we cannot hit any threshold
  echo "[OK] no slope\n";
  exit(0);
}else{
  $when = ($max + ($intercept * -1)) / $slope;
  $date = gmdate($dateformat, $when);
  if(isset($max)){
    // we want to know if the counter hits the max value
    // The counter can hit max so we check against thresholds
    if($when <= time() + parse_threshold($warning)){
      echo "[WARNING] At $date the counter will hit: $max \n";
      exit(1);
    }elseif($when <= time() + parse_threshold($critical)){
      echo "[CRITICAL] At $date the counter will hit: $max \n";
      exit(2);
    }else{
      echo "[OK] At $date the counter will hit: $max \n";
      exit(0); 
    }
  }else{
      // we want to know if the counter hit 0 this is not the case here
      echo "[OK] At $date the counter will hit: $max \n";
      exit(0); 
  }
}
