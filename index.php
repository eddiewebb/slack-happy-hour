<?php

//grab key from file, be nice and remove any silly charatcres thatv ariod eitors might put on.
if(file_exists('api.key'))$apiKey = preg_replace('/[^A-Za-z0-9_\-]/', '',file_get_contents('api.key'));
if(empty($apiKey)){
	die("Create a file names api.key in this directory. You will need to obtain the key from google cloud console.");
}
$geoUrl = "https://maps.googleapis.com/maps/api/geocode/json?key=" . $apiKey;
$placesUrl="https://maps.googleapis.com/maps/api/place/nearbysearch/json?key=" . $apiKey;
$prices = array(0=>"Free",1=>"Cheap",2=>"Moderate",3=>"Expensive",4=>"Very Expensive");

//make sure our peeps, from slack are the client

if(file_exists('slack.token'))$token = preg_replace('/[^A-Za-z0-9]/', '',file_get_contents('slack.token'));
$userToken = filter_input(INPUT_GET, 'token',FILTER_SANITIZE_STRING);
if($token  && ($token != $userToken)){
	slackError("Invalid integration token");
}


//get arguments from slack command
$argumentString = filter_input(INPUT_GET, 'text',FILTER_SANITIZE_STRING);
if (! $argumentString ){
	slackError("Must provide zip code!");
}
$arguments = explode(" ",$argumentString);
// 0=>zip code, 1=>optional review key word



//turn zipcode into geo code
$geoUrl .= "&address=" . $arguments[0];
$geoInfo = getResponseTo($geoUrl);


//use geoinfo to get nearby places
$placesUrl .= "&location=" . $geoInfo->results[0]->geometry->location->lat . "," . $geoInfo->results[0]->geometry->location->lng;
$placesUrl .= "&radius=8000"; //5 miles
$placesUrl .= "&opennow"; //...
$placesInfo = array();
//get all types suitable for happy hour
$types = array("bar","restaurant");
foreach($types as $type){
	$rez=getResponseTo($placesUrl . "&type=" . $type,true);

	if(array_key_exists("results", $rez) && sizeof( $rez['results']) > 0 ){
		$placesInfo=array_merge($placesInfo,$rez['results']);
	}

}




// filter down by rating (not available as request filter), and randomly pick one,
$bestPlaces = array_filter($placesInfo,"bestPlaces");
if( ! sizeof($bestPlaces)>0 ){
	slackError("No places near " . $arguments[0] . " match the criteria. Try a another keyword such as \"docks\",\"specials\", etc");
}
$thisPlaceId = array_rand($bestPlaces,1);
$thisPlace = $bestPlaces[$thisPlaceId];
$photoUrl = $thisPlace['icon'];
if($thisPlace['photos'][0]['photo_reference']){
	$photoUrl = "https://maps.googleapis.com/maps/api/place/photo?key=".$apiKey."&maxheight=150&photoreference=" . $thisPlace['photos'][0]['photo_reference'];
	$morePhotos = $thisPlace['photos'][0]['html_attributions'][0];
}




//print slack response
$response='{
    "response_type": "in_channel",
    "attachments": [
        {
            "image_url":"'. $photoUrl .'",
            "thumb_url":"'. $photoUrl .'", 
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









function getResponseTo($url, $asArray=false){
	$ch = curl_init();
	curl_setopt($ch, CURLOPT_URL, $url);
	 curl_setopt($ch, CURLOPT_PROXY,""); //no proxyt
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
	$data = curl_exec($ch);
	$info = curl_getinfo($ch);
	curl_close($ch);
	if( ! $data || $info['http_code'] != 200 ){
		$err = "ERROR (http_" . $info['http_code'] . "): ";
		if(!$data){
			$err .= curl_error($ch);
		}else{
			$data = json_decode( $data, true);
			$err .= $data['status'] . ", " .$data['error_message'];
		}
		echo   $err;
		die();
	}

	$result = json_decode( $data, $asArray);

	return $result;
}

function bestPlaces(&$place){
	global $apiKey, $arguments;
	// pubs, bars, etc, not chain restaruants
	$acceptableTypes = array("pub","bar");
	$forbiddenTypes = array("bakery","cafe","store");


	if( ! array_intersect($acceptableTypes, $place['types'])) {
		return false; //not acceptable
	}
	if(  array_intersect($forbiddenTypes, $place['types'])) {
		return false; //not acceptable
	}

	//also unique!
	 static $idlist = array();
	 if ( in_array( $place['place_id'], $idlist ) ){
	 	return false;
	 }else{
	 	$idlist[] = $place['place_id'];
	 }
	//new/unrated places, or good ones.
	if(! isset($place['rating']) || $place['rating'] < 3.8){
		return false;
	}

	
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
			break;
		}
	}
	return $acceptable;

}

function slackError($message){
	$response='{"text":  "Whoops: ' . $message . '"}';
	header('Content-Type: application/json');
	echo $response;
	die();
}


?>
