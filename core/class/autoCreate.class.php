<?php
class autoCreate {
	private $options;
	private $Arboresance=array();
	private $Templates=array();
	private $ObjetLevel;
	private $TemplateLevel;
	private $CommandeLevel;
 	public function __construct($_options){
		$this->Templates=eibd::devicesParameters();
		$this->options=$_options[0];
		
		$myKNX=json_decode(file_get_contents(dirname(__FILE__) . '/../config/KnxProj.json'),true);
		
		switch($this->options['arboresance']){
			case 'gad':
				$this->Arboresance=$myKNX['GAD'];
			break;
			case 'device':
				$this->Arboresance=$myKNX['Devices'];
			break;
			case 'locations':
				$this->Arboresance=$myKNX['Locations'];
			break;
		}
	}
  	private function getOptionLevel($GroupLevel,$Groupe,$NbLevel=0){
		foreach ($GroupLevel as $Name => $Level) {
			if($this->ObjetLevel == $NbLevel){
				$Object=$this->createObject($Name,$Groupe['Object']);
				$Groupe['Object']=$Object->getId();
			}
			elseif($this->TemplateLevel == $NbLevel)
				$Groupe['Template']=$Name;
			elseif($this->CommandeLevel == $NbLevel)
				$Groupe['Commande']=$Name;
			if(is_array($Level)){
				$this->getOptionLevel($Level,$Groupe,$NbLevel++);
			}else{
				$this->createEqLogic($Groupe['Object'],$Groupe['Template'],$Level)
				$Groupe['Object'] = '';
				$Groupe['Template'] = '';
			}
		}
	}
	public function CheckOptions(){
		$Groupe['Object'] = '';
		$Groupe['Template'] = '';
		$GroupName['Commande'] = '';
		$Architecture= $this->getOptionLevel($this->Arboresance,$Groupe);
	}
	private function checkLevel($search){
		foreach($this->options as $level =>$options){
			if($options == $search)
				return $level;
		}
	}
	private function createObject($Name,$Father){
		if(!$this->options['createObjet'])
			return null;
		$Object = jeeObject::byName($Name); 
		if (!is_object($Object)) {
			log::add('eibd','info','[Création automatique] Nous allons cree l\'objet : '.$Name);
			$Object = new jeeObject(); 
			$Object->setName($Name);
			$Object->setFather_id($Father);
			$Object->setIsVisible(true);
			$Object->save();
		}
		return $Object;
	}
	private function createEqLogic($Object,$Name,$Cmds){
		if(!$this->options['createEqLogic'])
			return;
		$TemplateId=$this->getTemplateName($Name);
		if($TemplateId != false){
			$TemplateOptions=$this->getTemplateOptions($TemplateId,$Cmds);
			log::add('eibd','info','[Création automatique] Le template ' .$Name.' existe, nous créons un equipement');
			$EqLogic=eibd::AddEquipement($Name,'',$Object);
			$EqLogic->applyModuleConfiguration($TemplateId,$TemplateOptions);
			foreach($EqLogic->getCmd() as $Cmd){
				$TemplateCmdName=$this->getTemplateCmdName($TemplateId,$Cmd->getName());
				if($TemplateCmdName === false)
					return;
				$Cmd->setLogicalId($Cmds[$TemplateCmdName]['AdresseGroupe']);
				$Cmd->save();
			}
		}else{
			if(!$this->options['createTemplate']){				
				log::add('eibd','info','[Création automatique] Il n\'exite aucun template ' .$Name.', nous créons un equipement basique qu\'il faudra mettre a jours');
				$EqLogic=eibd::AddEquipement($Name,'',$Object);
				foreach($Cmds as $Name => $Cmd){
					if($Cmd['DataPointType'] == ".000" ||$Cmd['DataPointType'] == ".000")
						$Cmd['DataPointType']= "1.xxx";
					$EqLogic->AddCommande($Name,$Cmd['AdresseGroupe'],"info", $Cmd['DataPointType']);
				}
			}
		}
	}
	private function getTemplateName($TemplateName){
		foreach($this->Templates as $TemplateId => $Template){
			if($Template['name'] == $TemplateName)
				return $TemplateId;
			foreach($Template['Synonyme'] as $SynonymeName){
				if($SynonymeName == $TemplateName)
					return $TemplateId;
			}
		}
		return false;
	}
	private function getTemplateOptions($TemplateId,$Cmds){
		$Options=array();
		foreach($Cmds as $Name => $Cmd){
			foreach($this->Templates[$TemplateId]['options'] as $TemplateOptionId =>$TemplateOption){	      
				foreach($TemplateOption['cmd'] as $OptionCmd){
					if($OptionCmd['name'] == $Name){
						$Options[$TemplateOptionId]=true;
						break;
					}
				}
			}
		}
		return $Options;
	}
	private function getTemplateCmdName($TemplateId,$CmdName){
		foreach($this->Templates[$TemplateId]['cmd'] as $TemplateCmdName){
			if($TemplateCmdName['name'] == $CmdName)
				return $TemplateCmdName['name'];
			foreach(explode('|',$TemplateCmdName['SameCmd']) as $SameCmd){
				if($SameCmd == $CmdName)
					return $TemplateCmdName['name'];
			}
			foreach($TemplateCmdName['Synonyme'] as $SynonymeName){
				if($SynonymeName == $CmdName)
					return $SynonymeName;
			}
		}
		return false;
	}
}
?>