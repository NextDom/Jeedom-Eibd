<?php
class knxproj {
	private $path;
	private $projectType;
	private $devices=array();
	private $groupAddresses=array();
	private $locations=array();
	private $templates=array();
	private $myProject=array();
	public static function extractTX100ProjectFile($file){
		$path = dirname(__FILE__) . '/../config/knxproj/';
		if (!is_dir($path)) 
			mkdir($path);
		exec('sudo chmod -R 655 '.$path);
		system('cd ' . $path . '; tar xfz "' . $file . '"');
		log::add('eibd','debug','[Import TX100] Extraction des fichiers de projets');
	}
	public static function extractETSProjectFile($file){
		$path = dirname(__FILE__) . '/../config/knxproj/';
		if (!is_dir($path)) 
			mkdir($path);
		exec('sudo chmod -R 655 '.$path);
		$zip = new ZipArchive(); 
		// On ouvre l’archive.
		if($zip->open($file) == TRUE){
			$zip->extractTo($path);
			$zip->close();
		}
		log::add('eibd','debug','[Import ETS] Extraction des fichiers de projets');
	}
 	public function __construct($_merge, $_projectType){
		$this->path = dirname(__FILE__) . '/../config/knxproj/';
		$this->templates=eibd::devicesParameters();
		$this->projectType=$_projectType;
		if($_merge != 'false'){
			log::add('eibd','debug','[Import ETS] Chargement du fichier projet');
			$filename=dirname(__FILE__) . '/../config/KnxProj.json';
			$myKNX=json_decode(file_get_contents($filename),true);
			$this->devices=$myKNX['DevicesAll'];
			$this->groupAddresses=$myKNX['GAD'];
			$this->locations=$myKNX['Locations'];
		}
		//log::add('eibd','debug','[Import ETS]'.json_encode($_options));
		switch($this->projectType){
			case "ETS":
				$projectFile=$this->searchETSFolder("P-");
				$this->myProject=simplexml_load_file($projectFile.'/0.xml');
				$this->parserETSDevice();
				$this->parserETSGroupAddresses();
				$this->parserLocations();
				//$this->CheckOptions();
			break;
			case "TX100":
				$this->searchTX100Folder($this->path);
				$this->parserTX100GroupAddresses();
				$this->parserTX100Products();
			break;
		}
	}
 	public function __destruct(){
		$path = dirname(__FILE__) . '/../config/knxproj/';
		if (file_exists($path)) 
			exec('sudo rm -R '.$path );
	}
	public function writeJsonProj(){
		$filename=dirname(__FILE__) . '/../config/KnxProj.json';
		if (file_exists($filename)) 
			exec('sudo rm '.$filename);
		$file=fopen($filename,"a+");
		fwrite($file,$this->getAll());
		fclose($file);	
	}
	public function getAll(){
		foreach($this->devices as $deviceProductRefId => $device){
			$myKNX['Devices']['('.$device['AdressePhysique'].') '.$device['DeviceName']] = null;
			foreach($device['Cmd'] as $groupAddressRefId=> $cmd){
				$myKNX['Devices']['('.$device['AdressePhysique'].') '.$device['DeviceName']][$cmd['cmdName']]['AdressePhysique']=$device['AdressePhysique'];
				$myKNX['Devices']['('.$device['AdressePhysique'].') '.$device['DeviceName']][$cmd['cmdName']]['AdresseGroupe']=$cmd['AdresseGroupe'];
				$myKNX['Devices']['('.$device['AdressePhysique'].') '.$device['DeviceName']][$cmd['cmdName']]['DataPointType']=$cmd['DataPointType'];
			}
		}
		$myKNX['DevicesAll']=$this->devices;
		$myKNX['GAD']=$this->groupAddresses;
		$myKNX['Locations']=$this->locations;
		return json_encode($myKNX,JSON_PRETTY_PRINT);
	}
	private function searchTX100Folder($path){
		if ($dh = opendir($path)){
			log::add('eibd','debug','[Import TX100] overture de  '.$path);
			while (($file = readdir($dh)) !== false){
				if($file != '.' && $file != '..'){
					log::add('eibd','debug','[Import TX100] Rechercher dans '.$path.$file);
					if ($file == 'configuration'){
						$this->path = $path.$file.'/';
						log::add('eibd','debug','[Import TX100] Dossier courant '.$this->path);
						return $this->path;
					}else{
						$this->searchTX100Folder($path.$file.'/');
					}
				}
			}
			closedir($dh);
		}	
		return false;
	}
	private function searchETSFolder($folder){
		if ($dh = opendir($this->path)){
			while (($file = readdir($dh)) !== false){
				if (substr($file,0,2) == $folder){
					if (opendir($this->path . $file)) 
						return $this->path . $file;
				}
			}
			closedir($dh);
		}	
		return false;
	}
	private function getETSCatalog($deviceProductRefId){
		//log::add('eibd','debug','[Import ETS] Rechecher des nom de module dans le catalogue');
		$catalog = new DomDocument();
		if ($catalog->load($this->path . substr($deviceProductRefId,0,6).'/Catalog.xml')) {//XMl décrivant les équipements
			foreach($catalog->getElementsByTagName('CatalogItem') as $catalogItem){
				if ($deviceProductRefId==$catalogItem->getAttribute('ProductRefId'))
					return $catalogItem->getAttribute('Name');
			}
		}
	}
	private function xml_attribute($object, $attribute){
		if(isset($object[$attribute]))
			return (string) $object[$attribute];
		return false;
	}
	private function getTX100Topology(){
		$topology=simplexml_load_file($this->path . 'Topology.xml');
		foreach ($topology->children() as $room) {
			$this->xml_attribute($room, 'name');
			foreach ($room->children() as $property) {
				$propertyKey = $this->xml_attribute($property, 'key');
				$propertyValue = $this->xml_attribute($property, 'value');
				if($propertyKey == "name")
					return $propertyValue;
			}
		}
		return '';
	}
	private function parserTX100Products(){
		log::add('eibd','debug','[Import TX100] Recherche des equipement');
		$products=simplexml_load_file($this->path . 'Products.xml');
		foreach ($products->children() as $product) {
			$productId = $this->xml_attribute($product, 'name');
			foreach ($product->children() as $property) {
				$propertyKey = $this->xml_attribute($property, 'key');
				$propertyValue = $this->xml_attribute($property, 'value');
				if($propertyKey == "SerialNumber")
					$serialNumber = $propertyValue;
				if($propertyKey == "ProductCatalogReference")
					$reference = $propertyValue;
				if($propertyKey == "IndividualAddress")
					$individualAddress = $propertyValue;
				if($propertyKey == "product.location")
					$location = $this->getTX100Topology();
			}
			if(isset($individualAddress)){
				$this->devices[$productId]['AdressePhysique'] = $individualAddress;
			}
			if(isset($location) && isset($reference) && isset($serialNumber)) {
				$this->devices[$productId]['DeviceName'] = $location . ' - ' . $reference . ' (' . $serialNumber . ')';
			}
			$this->devices[$productId]['Cmd'] = $this->getTX100ProductCmd($productId);
		}
	}
	private function parserTX100GroupAddresses(){
		log::add('eibd','debug','[Import TX100] Création de l\'arborescence d\'adresse de groupe');
		$groupLinks=simplexml_load_file($this->path . 'GroupLinks.xml');
		$this->groupAddresses = $this->getTX100Level($groupLinks);
	}
	private function getTX100Level($groupRanges, $nbLevel=0){
		$level = array();
		$nbLevel++;
		foreach ($groupRanges->children() as $groupRange) {
			$groupName = $this->xml_attribute($groupRange, 'name');
          		if ($groupName == 'Links'){
		  		$nbLevel--;
				return $this->getTX100Level($groupRange,$nbLevel);
			}
			if($groupRange->getName() == 'property' && $this->xml_attribute($groupRange, 'key') == "GroupAddress"){
				config::save('level',$nbLevel,'eibd');
				$addressGroup=$this->formatgaddr($this->xml_attribute($groupRange, 'value'));
				$channelId=$this->xml_attribute($groupRanges->config->property, 'key');
				$dataPointId=$this->xml_attribute($groupRanges->config->property, 'value');
				list($dataPointType,$groupName)=$this->getTX100DptInfo($channelId,$dataPointId);
				$level[$groupName]=array('DataPointType' => $dataPointType,'AdresseGroupe' => $addressGroup);
				return $level;
			}else{
				if($groupRange->getName() == 'config')
					$level[$groupName]=$this->getTX100Level($groupRange,$nbLevel);
			}
        	}
		return $level;
	}
	private function getTX100ProductCmd($productId){
		$dataPointType='';
		$groupName=' - ';
		$channels=simplexml_load_file($this->path . 'Channels.xml');
		foreach ($channels->children() as $channel) {
			$channelId = $this->xml_attribute($channel, 'name');
			foreach ($channel->children() as $block) {
				if($this->xml_attribute($block, 'name') == "Context"){
					foreach ($block->children() as $parameter) {
						if($this->xml_attribute($parameter, 'key') == 'product.id'){
							if($this->xml_attribute($parameter, 'value') == $productId){
							}
						}
					}
				}
			}
		}
		return array($dataPointType,$groupName);
	}
	private function getTX100DptInfo($channelId, $dataPointId){
		$dataPointType='';
		$groupName=' - ';
		$channels=simplexml_load_file($this->path . 'Channels.xml');
		foreach ($channels->children() as $channel) {
			if($this->xml_attribute($channel, 'name') == $channelId){
				foreach ($channel->children() as $block) {
					if($this->xml_attribute($block, 'name') == "FunctionalBlocks"){
						foreach ($block->children() as $functionalBlock) {
							foreach ($functionalBlock->config->children() as $dataPoints) {
								if($this->xml_attribute($dataPoints, 'name') == $dataPointId){
									foreach ($dataPoints->children() as $parameter) {
										if($this->xml_attribute($parameter, 'key') == 'aDPTNumber')
											$dataPointType=$this->xml_attribute($parameter, 'value').".xxx";
										if($this->xml_attribute($parameter, 'key') == 'name')
											$groupName=$this->xml_attribute($parameter, 'value');
									}
									return array($dataPointType,$groupName);
								}
							}
						}
					}
				}
			}
		}
		return array($dataPointType,$groupName);
	}
	private function parserLocations(){
		log::add('eibd','debug','[Import ETS] Création de l\'arborescence de localisation');
		$Level = $this->myProject->Project->Installations->Installation->Locations;
		$this->locations = $this->getETSLevel($Level,$this->locations);
		if(!$this->locations){
			$Level = $this->myProject->Project->Installations->Installation->Buildings;
			$this->locations = $this->getETSLevel($Level,$this->locations);

		}
	}
	private function parserETSGroupAddresses(){
		log::add('eibd','debug','[Import ETS] Création de l\'arborescence d\'adresse de groupe');
		$Level= $this->myProject->Project->Installations->Installation->GroupAddresses->GroupRanges;
		$this->groupAddresses = $this->getETSLevel($Level,$this->groupAddresses);
	}
	private function getETSLevel($groupRanges, $level=null, $nbLevel=0){
    		if($level == null)
			$level = array();
		$nbLevel++;
		if($groupRanges == null)
			return false;
		foreach ($groupRanges->children() as $groupRange) {
			$groupName = $this->xml_attribute($groupRange, 'Name');
			if($groupRange->getName() == 'GroupAddress'){
				config::save('level',$nbLevel,'eibd');
				$addressGroup=$this->formatgaddr($this->xml_attribute($groupRange, 'Address'));
				$groupId=$this->xml_attribute($groupRange, 'Id');
				list($addressPhysical,$dataPointType)=$this->updateDeviceInfo($groupId,$groupName,$addressGroup);
				$level['('.$addressGroup.') '.$groupName]=array('AdressePhysique' => $addressPhysical ,'DataPointType' => $dataPointType,'AdresseGroupe' => $addressGroup);
			}elseif($groupRange->getName() == 'DeviceInstanceRef'){
				$level = $this->getDeviceGad($level,$this->xml_attribute($groupRange, 'RefId'));
			}else {
				if (count($level[$groupName]) == 0) {
					$level[$groupName] = $this->getETSLevel($groupRange, null, $nbLevel);
				}
			}
		}
		return $level;
	}
	private function getDeviceGad($deviceGad, $id){
		if($deviceGad == null)
			$deviceGad =array();
		foreach($this->devices as $deviceProductRefId => $device){
			if(strrpos($id,$deviceProductRefId) !== false){
				foreach($device['Cmd'] as $groupAddressRefId=> $cmd){
					$deviceGad[$cmd['cmdName'].' ('.$device['AdressePhysique'].')']['AdressePhysique']=$device['AdressePhysique'];
					$deviceGad[$cmd['cmdName'].' ('.$device['AdressePhysique'].')']['AdresseGroupe']=$cmd['AdresseGroupe'];
					$deviceGad[$cmd['cmdName'].' ('.$device['AdressePhysique'].')']['DataPointType']=$cmd['DataPointType'];
				}
             	break;
			}
		}
		return $deviceGad;
	}
	private function updateDeviceInfo($id,$name,$addr){
		$addressPhysical='';
		$dataPointType='';
		foreach($this->devices as $deviceProductRefId => $device){
			foreach($device['Cmd'] as $groupAddressRefId=> $cmd){
				if(strrpos($id,$groupAddressRefId) !== false){
					$addressPhysical = $this->devices[$deviceProductRefId]['AdressePhysique'];
					$this->devices[$deviceProductRefId]['Cmd'][$groupAddressRefId]['cmdName']=$name;
					$this->devices[$deviceProductRefId]['Cmd'][$groupAddressRefId]['AdresseGroupe']=$addr;
					if($dataPointType == '') {
						$dataPointType = $this->devices[$deviceProductRefId]['Cmd'][$groupAddressRefId]['DataPointType'];
					}
				}
			}
		}
		return array($addressPhysical,$dataPointType);
	}
	private function parserETSDevice(){
		log::add('eibd','debug','[Import ETS] Recherche de device');
		$topology = $this->myProject->Project->Installations->Installation->Topology;
		if($topology == null)
			return false;
		foreach($topology->children() as $area){
			$areaAddress=$this->xml_attribute($area, 'Address');
			foreach ($area->children() as $line)  {
				$lineAddress=$this->xml_attribute($line, 'Address');
				foreach ($line->children() as $device)  {
					$deviceId=$this->xml_attribute($device, 'Id');
					$deviceProductRefId=$this->xml_attribute($device, 'ProductRefId');
					if ($deviceProductRefId != ''){
						$this->devices[$deviceId]=array();
                      				$this->devices[$deviceId]['DeviceName']=$this->getETSCatalog($deviceProductRefId);
						$deviceAddress=$this->xml_attribute($device, 'Address');
						$this->devices[$deviceId]['AdressePhysique']=$areaAddress.'.'.$lineAddress.'.'.$deviceAddress;
						foreach($device->children() as $comObjectInstanceRefs){
							if($comObjectInstanceRefs->getName() == 'ComObjectInstanceRefs'){
								foreach($comObjectInstanceRefs->children() as $comObjectInstanceRef){
									$dataPointType=explode('-',$this->xml_attribute($comObjectInstanceRef, 'DatapointType'));
									if($this->xml_attribute($comObjectInstanceRef, 'Links') !== false){
										$this->devices[$deviceId]['Cmd'][$this->xml_attribute($comObjectInstanceRef, 'Links')]['DataPointType']=$dataPointType[1].'.'.sprintf('%1$03d',$dataPointType[2]);
									}else{
										foreach($comObjectInstanceRef->children() as $connector){
											foreach($connector->children() as $commande)
												$this->devices[$deviceId]['Cmd'][$this->xml_attribute($commande, 'GroupAddressRefId')]['DataPointType']=$dataPointType[1].'.'.sprintf('%1$03d',$dataPointType[2]);
										}
									}
								}
							}
						}
					}
				}
			}
		}
	}
	private function formatgaddr($addr){
		switch(config::byKey('level', 'eibd')){
			case '3':
				return sprintf ("%d/%d/%d", ($addr >> 11) & 0x1f, ($addr >> 8) & 0x07,$addr & 0xff);
			break;
			case '2':
				return sprintf ("%d/%d", ($addr >> 11) & 0x1f,$addr & 0x7ff);
			break;
			case '1':
				return sprintf ("%d", $addr);
			break;
		}
	}
}
