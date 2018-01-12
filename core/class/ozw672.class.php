<?php

/* This file is part of Jeedom.
 *
 * Jeedom is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Jeedom is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU General Public License for more details.
 *
 * You should have received a copy of the GNU General Public License
 * along with Jeedom. If not, see <http://www.gnu.org/licenses/>.
 */

/* * ***************************Includes********************************* */
require_once dirname(__FILE__) . '/../../../../core/php/core.inc.php';

class ozw672 extends eqLogic {
    /*     * *************************Attributs****************************** */
	public $_SessionId;
    /*     * ***********************Methode static*************************** */

	public static function pull() {
		foreach (eqLogic::byTypeAndSearhConfiguration('ozw672', '"type":"appareil"') as $eqLogic)
		{
			log::add('ozw672','debug','Scan appareil : '.$eqLogic->name);
			$eqLogic->scan();
		}
	}

	public function https_file_get_contents($url)
	{
	  $ctx = stream_context_create(
		array(
			"ssl"=>array(
				"verify_peer"=>false,
				"verify_peer_name"=>false,
			)
		)
	  );
	  return file_get_contents($url, false, $ctx);
	}

	public function getSessionId()
	{
		if ( ! isset($this->_SessionId) && $this->getConfiguration('ip', '') != '' )
		{
			log::add('ozw672','debug','get SessionId');
			$statuscmd = $this->getCmd(null, 'status');
			$json = $this->https_file_get_contents('https://'.$this->getConfiguration('ip').'/api/auth/login.json?user='.$this->getConfiguration('username').'&pwd='.$this->getConfiguration('password'));
			if ( $json === false )
				throw new Exception(__('L\'ozw672 ne repond pas.',__FILE__));
			$obj = json_decode($json, TRUE);
			if ( isset($obj['Result']['Success']) && $obj['Result']['Success'] !== "false" )
			{
				$this->_SessionId = $obj['SessionId'];
				log::add('ozw672','debug',"SessionId : ".$obj['SessionId']);
				if ( is_object($statuscmd) ) {
					if (is_object($statuscmd) && $statuscmd->execCmd() != 1) {
						$statuscmd->setCollectDate('');
						$statuscmd->event(1);
					}
				}
				return true;
			}
			else
			{
				unset($this->_SessionId);
				if ( is_object($statuscmd) ) {
					if (is_object($statuscmd) && $statuscmd->execCmd() != 0) {
						$statuscmd->setCollectDate('');
						$statuscmd->event(0);
					}
				}
				if ( isset($obj['Result']['Error']['Txt']) )
				{
					log::add('ozw672','error',__('L\'ozw672 error : ',__FILE__).$obj['Result']['Error']['Txt']);
					throw new Exception(__('L\'ozw672 error : ',__FILE__).$obj['Result']['Error']['Txt']);
				}
				else
				{
					log::add('ozw672','error',__('Erreur de communication avec l\'ozw672',__FILE__));
					throw new Exception(__('Erreur de communication avec l\'ozw672',__FILE__));
				}
				return false;
			}
		}
		return false;
	}

	public function force_detect_carte()
	{
		if ( $this->getIsEnable() )
		{
			log::add('ozw672','debug',__('force detect carte ',__FILE__).$this->name);
			$this->getSessionId();
			$json = $this->https_file_get_contents('https://'.$this->getConfiguration('ip').'/api/menutree/list.json?SessionId='.$this->_SessionId);
			if ( $json === false )
				throw new Exception(__('L\'ozw672 ne repond pas.',__FILE__));
			$obj = json_decode($json, TRUE);
			if ( isset($obj['MenuItems']) )
			{
				foreach ($obj['MenuItems'] as $item )
				{
					log::add('ozw672','debug','Find appareil : '.$item['Id']);
					if ( ! is_object(self::byLogicalId($item['Id'], 'ozw672')) ) {
						log::add('ozw672','info','Creation appareil : '.$item['Id'].' ('.$item['Text']['Long'].')');
						$eqLogic = new ozw672();
						$eqLogic->setLogicalId($item['Id']);
						$eqLogic->setName($item['Text']['Long']);
						$eqLogic->setEqType_name('ozw672');
						$eqLogic->setConfiguration('type', 'appareil');
						$eqLogic->setConfiguration('parent', $this->getId());
						$eqLogic->setIsEnable(1);
						$eqLogic->setIsVisible(1);
						$eqLogic->save();
					}
				}
			}
		}
	}

	public function scan_commande()
	{
		if ( $this->getIsEnable() )
		{
			log::add('ozw672','debug',__('scan commande appareil ',__FILE__).$this->name);
			$carte = ozw672::byId($this->getConfiguration('parent'));
			if (!is_object($carte)) {
				throw new Exception(__('ozw672 eqLogic non trouvé : ', __FILE__) . $this->getConfiguration('parent'));
			}
			$carte->getSessionId();
			$this->scan_sub_commande($carte, $this->getLogicalId());
		}
	}

	public function scan_sub_commande($carte, $id)
	{
		$url = 'https://'.$carte->getConfiguration('ip').'/api/menutree/list.json?SessionId='. $carte->_SessionId.'&Id='.$id;
		log::add('ozw672','debug',$url);
		$json = $this->https_file_get_contents($url);
		if ( $json === false )
			throw new Exception(__('L\'ozw672 ne repond pas.',__FILE__));
		$obj = json_decode($json, TRUE);
		if ( isset($obj['MenuItems']) )
		{
			foreach ($obj['MenuItems'] as $item )
			{
				log::add('ozw672','debug','Find MenuItems : '.$item['Id']);
				if ( isset($item['Id']) )
				{
					$this->scan_sub_commande($carte, $item['Id']);
				}
			}
		}
		if ( isset($obj['DatapointItems']) )
		{
			foreach ($obj['DatapointItems'] as $item )
			{
				log::add('ozw672','debug','Find DatapointItems : '.$item['Id']);
				if ( isset($item['Id']) )
				{
					if ( ! is_object($this->getCmd(null, $item['Id'])) )
					{
						log::add('ozw672','info','Detection commande : '.$item['Id'].' ('.$item['Text']['Long'].' : '.$item['Id']['WriteAccess'].')');
						$url = 'https://'.$carte->getConfiguration('ip').'/api/menutree/read_datapoint.json?SessionId='. $carte->_SessionId.'&Id='.$item['Id'];
						log::add('ozw672','debug',$url);
						$json = $this->https_file_get_contents($url);
						if ( $json === false )
							throw new Exception(__('L\'ozw672 ne repond pas.',__FILE__));
						$obj_detail = json_decode($json, TRUE);
						if ( isset($obj_detail['Result']['Success']) && $obj_detail['Result']['Success'] !== "false" )
						{
							log::add('ozw672','info','Creation commande : '.$item['Id'].' ('.$item['Text']['Long'].' : '.$item['Id']['WriteAccess'].')');
							$cmd = new ozw672Cmd();
							$cmd->setName($item['Text']['Long']);
							$cmd->setEqLogic_id($this->getId());
							$cmd->setLogicalId($item['Id']);
							$cmd->setEventOnly(1);
							$cmd->setIsVisible(0);
							switch ($obj_detail['Data']['Type']) {
								case "DateTime":
									$cmd->setType('info');
									$cmd->setSubType('string');
									$cmd->setDisplay('generic_type','GENERIC_INFO');
									break;
								case "Enumeration":
									$cmd->setType('info');
									$cmd->setSubType('string');
									$cmd->setDisplay('generic_type','GENERIC_INFO');
									break;
								case "Numeric":
									$cmd->setType('info');
									$cmd->setSubType('numeric');
									$cmd->setUnite($obj_detail['Data']['Unit']);
									$cmd->setDisplay('generic_type','GENERIC_INFO');
									break;
								case "Scheduler":
									$cmd->setType('info');
									$cmd->setSubType('string');
									$cmd->setDisplay('generic_type','GENERIC_INFO');
									break;
								case "RadioButton":
									$cmd->setType('info');
									$cmd->setSubType('string');
									$cmd->setDisplay('generic_type','GENERIC_INFO');
									break;
								case "String":
									$cmd->setType('info');
									$cmd->setSubType('string');
									$cmd->setDisplay('generic_type','GENERIC_INFO');
									break;
								case "AlarmInfo":
									$cmd->setType('info');
									$cmd->setSubType('string');
									$cmd->setDisplay('generic_type','GENERIC_INFO');
									break;
								case "TimeOfDay":
									$cmd->setType('info');
									$cmd->setSubType('binary');
									$cmd->setDisplay('generic_type','GENERIC_INFO');
									break;
								default:
									log::add('ozw672','error','Type inconnu reply : '.print_r($obj_detail, true));
									break;
							}
							$cmd->save();
						}
						else
						{
							log::add('ozw672','debug','Reply : '.print_r($obj_detail, true));
							throw new Exception(__('L\'ozw672 ne repond pas.',__FILE__));
							next;
						}
					}
				}
			}
		}
	}

	public function preInsert()
	{
		if ( $this->getConfiguration('type', '') == "" )
		{
			$this->setConfiguration('type', 'ozw672');
		}
	}

	public function preUpdate()
	{
		if ( $this->getIsEnable() )
		{
			return $this->getSessionId();
		}
	}

	public function preSave()
	{
		if ( $this->getIsEnable() )
		{
			return $this->getSessionId();
		}
	}

/*	public function preInsert()
	{
		$this->setConfiguration('username', 'admin');
		$this->setConfiguration('password', 'admin');
		$this->setConfiguration('ip', 'ozw672');
		$this->setLogicalId('ozw672');
		$this->setEqType_name('ozw672');
		$this->setIsEnable(1);
		$this->setIsVisible(0);
	}
*/
	public function postInsert()
	{
		if ( $this->getIsEnable() )
		{
			$cmd = $this->getCmd(null, 'updatetime');
			if ( ! is_object($cmd)) {
				$cmd = new ozw672Cmd();
				$cmd->setName('Dernier refresh');
				$cmd->setEqLogic_id($this->getId());
				$cmd->setLogicalId('updatetime');
				$cmd->setUnite('');
				$cmd->setType('info');
				$cmd->setSubType('string');
				$cmd->setIsHistorized(0);
				$cmd->setEventOnly(1);
				$cmd->save();		
			}
			$cmd = $this->getCmd(null, 'status');
			if ( ! is_object($cmd) ) {
				$cmd = new ipx800Cmd();
				$cmd->setName('Etat');
				$cmd->setEqLogic_id($this->getId());
				$cmd->setType('info');
				$cmd->setSubType('binary');
				$cmd->setLogicalId('status');
				$cmd->setIsVisible(1);
				$cmd->setEventOnly(1);
				$cmd->setDisplay('generic_type','GENERIC_INFO');
				$cmd->save();
			}
			$this->refreshInfo();
		}
	}

	public function postUpdate()
	{
		if ( $this->getIsEnable() )
		{
			$cmd = $this->getCmd(null, 'updatetime');
			if ( ! is_object($cmd)) {
				$cmd = new ozw672Cmd();
				$cmd->setName('Dernier refresh');
				$cmd->setEqLogic_id($this->getId());
				$cmd->setLogicalId('updatetime');
				$cmd->setUnite('');
				$cmd->setType('info');
				$cmd->setSubType('string');
				$cmd->setIsHistorized(0);
				$cmd->setEventOnly(1);
				$cmd->save();		
			}
			$cmd = $this->getCmd(null, 'status');
			if ( ! is_object($cmd) ) {
				$cmd = new ipx800Cmd();
				$cmd->setName('Etat');
				$cmd->setEqLogic_id($this->getId());
				$cmd->setType('info');
				$cmd->setSubType('binary');
				$cmd->setLogicalId('status');
				$cmd->setIsVisible(1);
				$cmd->setEventOnly(1);
				$cmd->setDisplay('generic_type','GENERIC_INFO');
				$cmd->save();
			}
			$this->refreshInfo();
		}
	}

	public function scan() {
		if ( $this->getIsEnable() ) {
			$this->refreshInfo();
		}
	}

	function refreshInfo() {
		$carte = ozw672::byId($this->getConfiguration('parent'));
		if (!is_object($carte)) {
			throw new Exception(__('ozw672 eqLogic non trouvé : ', __FILE__) . $this->getConfiguration('parent'));
		}
		$carte->getSessionId();
		$eqLogic_cmd = $this->getCmd(null, 'updatetime');
		$eqLogic_cmd->event(date("d/m/Y H:i",(time())));
		foreach ($this->getCmd() as $cmd)
		{
			if ( is_numeric($cmd->getLogicalId()) )
			{
				$url = 'https://'.$carte->getConfiguration('ip').'/api/menutree/read_datapoint.json?SessionId='. $carte->_SessionId.'&Id='.$cmd->getLogicalId();
				log::add('ozw672','debug',$url);
				$json = $this->https_file_get_contents($url);
				if ( $json === false )
					throw new Exception(__('L\'ozw672 ne repond pas.',__FILE__));
				$obj = json_decode($json, TRUE);
				log::add('ozw672','debug','Detail data : '.print_r($obj, true));
				if ( isset($obj['Result']['Success']) && $obj['Result']['Success'] !== "false" )
				{
					log::add('ozw672','debug','Valeur : '.$obj['Data']['Value']);
					$cmd->event($obj['Data']['Value']);
				}
				else
				{
					if ( isset($obj['Result']['Error']['Txt']) )
					{
						log::add('ozw672','error',__('L\'ozw672 error : ',__FILE__).$obj['Result']['Error']['Txt']);
						throw new Exception(__('L\'ozw672 error : ',__FILE__).$obj['Result']['Error']['Txt']);
					}
					else
					{
						log::add('ozw672','error',__('Erreur de communication avec l\'ozw672',__FILE__));
						throw new Exception(__('Erreur de communication avec l\'ozw672',__FILE__));
					}
					return false;
				}
			}
		}
	}
}

class ozw672Cmd extends cmd 
{
    /*     * *************************Attributs****************************** */


    /*     * ***********************Methode static*************************** */


    /*     * *********************Methode d'instance************************* */

    /*     * **********************Getteur Setteur*************************** */
    public function execute($_options = null) {
		$eqLogic = $this->getEqLogic();
        if (!is_object($eqLogic) || $eqLogic->getIsEnable() != 1) {
            throw new Exception(__('Equipement desactivé impossible d\éxecuter la commande : ' . $this->getHumanName(), __FILE__));
        }
		log::add('ozw672','debug','get '.$this->getLogicalId());
        return true;
    }

    public function formatValue($_value, $_quote = false) {
        if (trim($_value) == '') {
            return '';
        }
        if ($this->getType() == 'info') {
            switch ($this->getSubType()) {
                case 'binary':
                    $_value = strtolower($_value);
                    if ($_value == 'up') {
                        $_value = 1;
                    } else if ($_value == 'connected') {
                        $_value = 1;
                    } else if ($_value == 'bound') {
                        $_value = 1;
                    } else if ($_value == 'available') {
                        $_value = 1;
                    } else if ( (is_numeric( intval($_value) ) && intval($_value) > 1) || $_value == 1 ) {
                        $_value = 1;
                    } else {
					   $_value = 0;
					}
                    return $_value;
				case 'string':
					if ( substr($this->getLogicalId(), 0, 15) == 'numerotelephone') {
						if( strlen($_value) > 9 ) {
							 $_value = '0'.substr($_value, -9);
						}
					}
                    return $_value;
            }
        }
        return $_value;
    }
}
?>