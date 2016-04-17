<?php


logTime(">>>>>>>>>Start");


loadSecurityTokens();

//get arguments from slack command
$arguments = loadArguments();

//get all types suitable for happy hour
$placesInfo = searchForNearbyPlaces();

//pick the random winner from the best
$thisPlace = pickFirstQualifyingPlace($placesInfo);

//set a photo to show user
prepareFeaturePhoto($thisPlace);

//print slack response
$prices = array(0=>"Free",1=>"Cheap",2=>"Moderate",3=>"Expensive",4=>"Very Expensive",9=>"Unknown");
$response='{
    "response_type": "in_channel",
    "attachments": [
        {
            "image_url":"'. $thisPlace['photo_url'] .'",
            "thumb_url":"'. $thisPlace['photo_url'] .'", 
            "fallback": "Go to ' . $thisPlace['name'] . '",

            "color": "good",

            "title": "Go to ' . $thisPlace['name'] . '",
            "title_link":"' . $thisPlace['url']  . '",

            "text": "You could go to ' . $thisPlace['name'] . ' at ' . $thisPlace['vicinity'] .'. You can also try adding key words for tailored results;",
            "fields":[
				{"title":"Rating",
				 "value":' . $thisPlace['rating'] . ',
				 "short":true
				},
				{"title":"Total Ratings",
				 "value":' . $thisPlace['user_ratings_total'] . ',
				 "short":true
				},
				{"title":"Price",
				 "value":"' . $prices[$thisPlace['price_level']] . '",
				 "short":true
				},
				{"title":"Website",
				 "value":"' . $thisPlace['website'] . '",
				 "short":false
				},
				{"title":"Reason",
				 "value":"' . $thisPlace['reason'] . '",
				 "short":false
				}
			]


        }
    ]
}';
header('Content-Type: application/json');
echo $response;

//https://maps.googleapis.com/maps/api/place/details/json?placeid=



/**********\
*
*
\************/

function loadSecurityTokens(){
	global $apiKey;
	//grab google key from file, be nice and remove any silly charatcres thatv ariod eitors might put on.
	if(file_exists('api.key'))$apiKey = preg_replace('/[^A-Za-z0-9_\-]/', '',file_get_contents('api.key'));
	if(empty($apiKey)){
		die("Create a file names api.key in this directory. You will need to obtain the key from google cloud console.");
	}

	//make sure our peeps, from slack are the client
	if(file_exists('slack.token'))$token = preg_replace('/[^A-Za-z0-9]/', '',file_get_contents('slack.token'));
	$userToken = filter_input(INPUT_GET, 'token',FILTER_SANITIZE_STRING);
	if(! empty($token)  && ($token != $userToken)){
		slackError("Invalid integration token");
	}
}

function loadArguments(){
	$argumentString = filter_input(INPUT_GET, 'text',FILTER_SANITIZE_STRING);
	if (! $argumentString ){
		slackError("Must provide zip code!");
	}
	$arguments = explode(" ",$argumentString);
	return $arguments;
}

function searchForNearbyPlaces($types = array("bar","restaurant")){
	global $apiKey, $arguments;
	logTime("startPLaces");
	$zip = findLatLngForZip($arguments[0]) ;
	logTime("know zip");
	$placesInfo =array();
	foreach($types as $type){
		$rez=getResponseTo(buildSearchUrl($type, $zip) ,true);
		logTime($type."s loaded");
		if(array_key_exists("results", $rez) && sizeof( $rez['results']) > 0 ){
			$placesInfo=array_merge($placesInfo,$rez['results']);
			logTime($type."s merged");
		}
	}
	logTime("all merged");
	shuffle($placesInfo);
	return $placesInfo;
}

function buildSearchUrl($type,$zip){
	global $apiKey, $arguments;
	$placesUrl="https://maps.googleapis.com/maps/api/place/nearbysearch/json?key=" . $apiKey;
	//use geoinfo to get nearby places
	$placesUrl .= "&location=" . $zip;
	$placesUrl .= "&radius=8000"; //5 miles
	$placesUrl .= "&opennow"; //...
	$placesUrl .= "&type=" . $type;
	return $placesUrl;
}

function getResponseTo($url, $asArray=false){
	logTime("request Start");
	$ch = curl_init();
	logTime("init");
	curl_setopt($ch, CURLOPT_URL, $url);
	curl_setopt($ch, CURLOPT_PROXY,""); //no proxyt
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	$data = curl_exec($ch);
	logTime("executed");
	$info = curl_getinfo($ch);
	logTime("info");
	if( ! $data || $info['http_code'] != 200 ){
		$err = "ERROR (http_" . $info['http_code'] . "): ";
		if(!$data){
			$err .= curl_error($ch);
		}else{
			$data = json_decode( $data, true);
			$err .= $data['status'] . ", " .$data['error_message'];
		}
		echo   $err;

		curl_close($ch);
		die();
	}
	curl_close($ch);
	logTime("closed");
	$result = json_decode( $data, $asArray);
	logTime("    request finshed");
	return $result;
}


function pickFirstQualifyingPlace($places){
	global $apiKey, $arguments;
	// pubs, bars, etc, not chain restaruants
	$acceptableTypes = array("pub","bar");
	$forbiddenTypes = array("bakery","cafe","store");

	$winner=null;
	foreach($places as $place){
		//skip what we can with information we know from place summary
		//     types we want with good rating
		if( ! array_intersect($acceptableTypes, $place['types'])) {
			continue; //not acceptable
		}
		if(  array_intersect($forbiddenTypes, $place['types'])) {
			continue; //not acceptable
		}
		if(! isset($place['rating']) || $place['rating'] < 3.5){
			continue;
		}
		if( ! isset($place['photos'])){
			continue;
		}
		// now get more details (reviews) and pick first match
		if(isMatch($place)){
			$winner=$place;
			break;
		}	
	}
	if( empty($winner)){
		slackError("No places near " . $arguments[0] . " match the criteria. Try a another keyword such as \"docks\",\"specials\", etc");
	}
	return $winner;

}

function isMatch(&$place){
	global $apiKey,$arguments;
	$placeDetails = getResponseTo("https://maps.googleapis.com/maps/api/place/details/json?placeid=" . $place['place_id'] . "&key=" . $apiKey,true);
		
	if( !isset($placeDetails['result']['reviews'])) return false;
	$acceptable=false;
	foreach($placeDetails['result']['reviews'] as $review){
		$checkFor=!empty($arguments[1])?$arguments[1]:"drink";
		$mention = stripos($review['text'],$checkFor);
		if($mention) {
			$acceptable=true;
			$place = $placeDetails['result'];
			$place['reason']=substr($review['text'], $mention - 15, 100);
			if(empty($place['price_level']))$place['price_level']=9;
			return true;
		}
	}
}

function slackError($message){
	$response='{"text":  "Whoops: ' . $message . '"}';
	header('Content-Type: application/json');
	echo $response;
	die();
}

function findLatLngForZip($zipcode){
	global $apiKey, $arguments;
	//turn zipcode into geo code
	$geoUrl = "https://maps.googleapis.com/maps/api/geocode/json?key=" . $apiKey;
	$geoUrl .= "&address=" . $arguments[0];
	$geoInfo = getResponseTo($geoUrl);
	return $geoInfo->results[0]->geometry->location->lat . "," . $geoInfo->results[0]->geometry->location->lng;
}



function prepareFeaturePhoto(&$place){	
	global $apiKey;
	$place['photo_url'] = $place['icon'];
	if($place['photos'][0]['photo_reference']){
		$place['photo_url'] = "https://maps.googleapis.com/maps/api/place/photo?key=".$apiKey."&maxheight=150&photoreference=" . $place['photos'][0]['photo_reference'];
	}
}
function logTime($step){
	// set true for debug timeing
	if(false){
		global $time_start;
		if(empty($time_start)){
			$time_start=round(microtime(true) * 1000);
		}
		error_log($step . "time:".(round(microtime(true) * 1000)-$time_start));
	}
}
?>
