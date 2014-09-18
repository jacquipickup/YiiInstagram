<?php
/**
* Instagram Class to work with the realtime subcription service
* Saves Instagram images relating to a specific tag to DB
*
* @link http://instagram.com/developer/realtime/
* @author Jacqui Pickup
* Built for use on Yii Version 1.1.13
*/
class InstagramPhotoController extends Controller
{
	const INSTAGRAM_HASH 	= '_HASHTAG_'; // Store this in a config file or as a constant in another model if it has dependancies
	private $client_id 		= '_CLIENT_ID_';
	private $client_secret 	= '_ClIENT_SECRET_';
	private $callback_url 	= '_CALLBACK_URL_';


	/**
	* Callback View
	* Initialise request to API
	*/
	public function actionCallback()
	{
		/* Use to initialise the subscription */
		// $challenge = $_GET['hub_challenge'];
		// echo $challenge;

		$this->saveNewlyTaggedInstagramPhotos(self::INSTAGRAM_HASH);

		$log_file = realpath(dirname(__FILE__) . '/..') .'/runtime/instagram.log';
		$myString = file_get_contents('php://input');
		fopen($log_file, 'a+');

		$ALL = date("F j, Y, g:i a")." ".$myString ."\r\n";
		@file_put_contents($log_file, $ALL, FILE_APPEND);
	}

	/**
	* Request data from Instagram API for a specific #hashtag, save new photos to DB.
	* Cycle through each page of data until reach items we have previously saved.
	* @param $tag
	* @param $pagination (optional)
	*/
	private function saveNewlyTaggedInstagramPhotos($tag, $pagination = null)
	{
		$stop = false;
		if($pagination){
			$url = $pagination->next_url;
		}else{
			$url = 'https://api.instagram.com/v1/tags/' . $tag . '/media/recent?client_id=' . $this->client_id;
		}

		// Call to Instagram API
		$ch = curl_init($url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		$result = curl_exec($ch);

		if ($result !== FALSE) {
			$response = json_decode($result);
			if($response->meta->code == 200){
				$stop = !$this->saveNewPhotos( $response->data );
			}
		}

		if($stop === false && isset($response->pagination->next_url)){
			// Make another call to the Instagram API as there could be more photos on the next page
			$this->getTaggedInstagramPhotos(self::INSTAGRAM_HASH, $response->pagination->next_url);
		}
	}

	/**
	* Save photos to database that we don't already have
	* @param $photos array
	* @return boolean
	*/
	private function saveNewPhotos( $photos ){
		$photoNew = true;
		$existingInstagramIds = $this->getExistingInstagramIds();

		foreach($photos as $photo){
			if($photo->type === 'image'){
				$photoId = $this->mapId($photo->id);
				if(in_array($photoId, $existingInstagramIds)){
					$photoNew = false;
				}else{
					// Store photos that don't already exist in DB
					$this->savePhoto( $photoId, $photo );
					$photoNew = true;
				}
			}
		}
		return $photoNew;
	}

	/**
	* Map the photo data from Instagram to own db structure and save entry.
	* @param $photo_id int
	* @param $photo object
	* @return boolean
	*/
	private function savePhoto($photoId, $photo){
		$instagram_photo 								= new InstagramPhoto();
		$instagram_photo->instagram_id 					= (int) $photoId;
		$instagram_photo->instagram_username 			= $photo->user->username;
		$instagram_photo->instagram_full_name 			= htmlspecialchars($photo->user->full_name); //Possible to add hearts, stars and allsorts!!!
		$instagram_photo->instagram_medium_photo_url 	= $photo->images->low_resolution->url;
		$instagram_photo->instagram_large_photo_url 	= $photo->images->standard_resolution->url;
		$instagram_photo->instagram_post_url 			= $photo->link;
		$instagram_photo->instagram_creation_dt 		= date('Y-m-d H:i:s', $photo->created_time);
		if(isset($photo->caption->text)){
			$instagram_photo->instagram_caption_text 	= htmlspecialchars(substr($photo->caption->text,0,254));
		}

		return $instagram_photo->save();
	}

	/**
	* Query database returning the identifiers of exisiting InstagramPhotos as an array.
	* @return array
	*/
	private function getExistingInstagramIds(){
		$existing_photos 		= InstagramPhoto::model()->findAll(array('order' => 'instagram_creation_dt desc'));
		$existing_instagram_ids = array();

		foreach($existing_photos as $photo){
			$existing_instagram_ids[] = $photo->instagram_id;
		}

		return $existing_instagram_ids;
	}

	/**
	* Manipulate Instagram Id to conform to our DB structure
	* @param $instagramId string
	* @return int
	*/
	private function mapId($instagramId){
		$photoIdentifier = explode('_', $instagramId);
		return $photoIdentifier[0];
	}

}