<?php

namespace AppBundle\Utils;

use AppBundle\Utils\Connectors\EdiMaxConnector;
use AppBundle\Utils\Connectors\MobileAlertsConnector;
use AppBundle\Utils\Connectors\OpenWeatherMapConnector;
use AppBundle\Utils\Connectors\MyStromConnector;
use AppBundle\Utils\Connectors\ShellyConnector;
use AppBundle\Utils\Connectors\PcoWebConnector;
use Doctrine\Common\Persistence\ObjectManager;

/**
 *
 * @author Markus Schafroth
 */
class ConditionChecker
{
    protected $em;
    protected $edimax;
    protected $mobilealerts;
    protected $openweathermap;
    protected $mystrom;
    protected $shelly;
    protected $pcoWeb;
    protected $energyLowRate;

    public function __construct(ObjectManager $em, EdiMaxConnector $edimax, MobileAlertsConnector $mobilealerts, OpenWeatherMapConnector $openweathermap, MyStromConnector $mystrom, ShellyConnector $shelly, PcoWebConnector $pcoweb, $energyLowRate)
    {
        $this->em = $em;
        $this->edimax = $edimax;
        $this->mobilealerts = $mobilealerts;
        $this->openweathermap = $openweathermap;
        $this->mystrom = $mystrom;
        $this->shelly = $shelly;
        $this->pcoweb = $pcoweb;
        $this->energyLowRate = $energyLowRate;
        $this->deviceClass = null;
        $this->ip = null;
        $this->port = null;
    }

    public function checkCondition($device, $type='forceOn')
    {
        $this->deviceClass = "EdiMax";
        $this->ip = $device['ip'];
        $conf = $this->edimax->getConfig($this->ip);
        if (null === $conf) {
            // there is no edimax device with this IP. We check if there is a mystrom device instead
            $conf = $this->mystrom->getConfig($this->ip);
            $this->deviceClass = "MyStrom";
        }
        if (null === $conf) {
            // there is no edimax and mystrom device with this IP. We check if there is a shelly device instead
            $conf = $this->shelly->getConfig($this->ip, $device['port']);
            $this->port = $device['port'];
            $this->deviceClass = "Shelly";
        }
        // check for on condition for all energy rates
        if (isset($conf['on']) && $type == 'on') {
            if ($this->processConditions($conf['on'])) {

                return true;
            }
        } elseif ($type == 'on') {
            // if we check for 'on' condition but this is not set, it is implicitely fulfilled
            return true;
        }

        // check for minimum runtime conditions during low energy rate and in first night half (before midnight)
        $now = new \DateTime("now");
        if ($now->format("H") > 12 && $this->checkEnergyLowRate() && isset($conf['minRunTime'])) {
            $runTime = null;
            if ($this->deviceClass == "EdiMax") {
                $runTime = $this->em->getRepository("AppBundle:EdiMaxDataStore")->getActiveDuration($this->ip);
            } elseif($this->deviceClass == "MyStrom") {
                $runTime = $this->em->getRepository("AppBundle:MyStromDataStore")->getActiveDuration($this->ip);
            } elseif($this->deviceClass == "Shelly") {
                $runTime = $this->em->getRepository("AppBundle:ShellyDataStore")->getActiveDuration($this->ip);
            }
            if ($runTime !== null && $runTime < $conf['minRunTime']) {
                if ($type !== 'forceOff') {
                    // in case we check an "activate" condition, we want to return true
                    return true;
                }
            }
        }

        // check for force conditions for all energy rates
        if ($type == 'forceOn' && isset($conf['forceOn'])) {
            if ($this->processConditions($conf['forceOn'])) {

                return true;
            }
        }
        if ($type == 'forceOff' && isset($conf['forceOff'])) {
            if ($this->processConditions($conf['forceOff'])) {

                return true;
            }
        }
        if ($type == 'forceOpen' && isset($conf['forceOpen'])) {
            if ($this->processConditions($conf['forceOpen'])) {

                return true;
            }
        }
        if ($type == 'forceClose' && isset($conf['forceClose'])) {
            if ($this->processConditions($conf['forceClose'])) {

                return true;
            }
        }

        // check for force conditions valid on low energy rate
        if (isset($conf['lowRateOn']) && $this->checkEnergyLowRate()) {
            if ($this->processConditions($conf['lowRateOn'])) {
                return true;
            }
        }

        return false;
    }

    public function checkEnergyLowRate()
    {
        $now = new \DateTime();
        $nowH = $now->format('H');
        if ($nowH >= $this->energyLowRate['start'] || $nowH < $this->energyLowRate['end']) {
            return true;
        } else {
            return false;
        }
    }

    private function processConditions($conditionSets)
    {
        // all conditions in one set must be fulfilled
        // at least one set must be fulfilled
        $fulfilled = false;
        foreach ($conditionSets as $conditions) {
            if ($this->checkConditionSet($conditions)) {
                $fulfilled = true;
                break;
            }
        }

        return $fulfilled;
    }

    private function checkConditionSet($conditions)
    {
        $fulfilled = false;
        foreach ($conditions as $sensor => $condition) {
            $condArr = explode(':', $sensor);
            if ($condArr[0] == 'mobilealerts') {
                $maData = $this->mobilealerts->getAllLatest();
                $sensorData = $maData[$condArr[1]][$condArr[2]];
                // check if > or < should be checked
                if (strpos($condition, 'rain') !== false) {
                    // we check for rain (more than x)
                    $condition = floatval(str_replace('rain>', '', $condition));
                    $rain = $this->em->getRepository("AppBundle:MobileAlertsDataStore")->getDiffLast60Min($condArr[1]);
                    if ($rain > strtolower($condition)) {
                        $fulfilled = true;
                    } else {
                        $fulfilled = false;
                        break;
                    }
                } elseif (strpos($condition, '<') !== false){
                    // we have a smaller than condition
                    $condition = str_replace('<', '', $condition);
                    if (floatval($sensorData['value']) < floatval($condition)) {
                        $fulfilled = true;
                    } else {
                        $fulfilled = false;
                        break;
                    }
                } elseif (strpos($condition, '>') !== false) {
                    // we have larger than condition
                    $condition = str_replace('>', '', $condition);
                    if (floatval($sensorData['value']) > floatval($condition)) {
                        $fulfilled = true;
                    } else {
                        $fulfilled = false;
                        break;
                    }
                }
            }
            if ($condArr[0] == 'openweathermap') {
                $owmData = $this->openweathermap->getAllLatest();
                $weatherData = $owmData[$condArr[1]];
                // check if > or < should be checked
                if (strpos($condition, '>') !== false) {
                    // we have larger than condition
                    $condition = str_replace('>', '', $condition);
                    if (floatval($weatherData) > floatval($condition)) {
                        $fulfilled = true;
                    } else {
                        $fulfilled = false;
                        break;
                    }
                } elseif (strpos($condition, '<') !== false) {
                    // we have a smaller than condition
                    $condition = str_replace('<', '', $condition);
                    if (floatval($weatherData) < floatval($condition)) {
                        $fulfilled = true;
                    } else {
                        $fulfilled = false;
                        break;
                    }
                } else {
                    // we have a equal condition
                    $condition = str_replace('=', '', $condition);
                    if (strtolower($weatherData) == strtolower($condition)) {
                        $fulfilled = true;
                    } else {
                        $fulfilled = false;
                        break;
                    }
                }
            }
            if (strpos($condArr[0], 'time')===0) {
                $currentTime = date('H')*60 + date('i');
                $timeDataArr = explode(':', str_replace('>', '', str_replace('<', '', $condition)));
                $timeData = $timeDataArr[0]*60+$timeDataArr[1];
                // check if > or < should be checked
                if (strpos($condition, '>') !== false) {
                    // we have larger than condition
                    if ($currentTime > $timeData) {
                        $fulfilled = true;
                    } else {
                        $fulfilled = false;
                        break;
                    }
                } elseif (strpos($condition, '<') !== false) {
                    // we have a smaller than condition
                    if ($currentTime < $timeData) {
                        $fulfilled = true;
                    } else {
                        $fulfilled = false;
                        break;
                    }
                }
            }
            if (strpos($condArr[0], 'outsideTemp')===0) {
                $pcoweb = $this->pcoweb->getAllLatest();
                $outsideTemp = $pcoweb['outsideTemp'];
                $tempData = str_replace('<', '', str_replace('>', '', $condition));
                // check if > or < should be checked
                if (strpos($condition, '>') !== false) {
                    // we have larger than condition
                    if ($outsideTemp > $tempData) {
                        $fulfilled = true;
                    } else {
                        $fulfilled = false;
                        break;
                    }
                } elseif (strpos($condition, '<') !== false) {
                    // we have a smaller than condition
                    if ($outsideTemp < $tempData) {
                        $fulfilled = true;
                    } else {
                        $fulfilled = false;
                        break;
                    }
                }
            }
            if ($condArr[0] == 'mystrom') {
                $status = $this->em->getRepository("AppBundle:MyStromDataStore")->getLatest($condArr[1]);
                // we only have equal condition (true / false)
                if ($status == $condition) {
                    $fulfilled = true;
                } else {
                    $fulfilled = false;
                    break;
                }
            }
            if ($condArr[0] == 'alarm') {
                $status = $this->em->getRepository("AppBundle:Settings")->getMode('alarm');
                // we only have equal condition (true / false)
                if ($status == $condition) {
                    $fulfilled = true;
                } else {
                    $fulfilled = false;
                    break;
                }
            }
            if ($condArr[0] == 'runTime') {
                $runTime = null;
                if ($this->deviceClass == 'EdiMax') {
                    $runTime = $this->em->getRepository("AppBundle:EdiMaxDataStore")->getActiveDuration($this->ip);
                }
                if ($this->deviceClass == 'MyStrom') {
                    $runTime = $this->em->getRepository("AppBundle:MyStromDataStore")->getActiveDuration($this->ip);
                }
                if ($this->deviceClass == 'Shelly') {
                    $runTime = $this->em->getRepository("AppBundle:ShellyDataStore")->getActiveDuration($this->ip);
                }
                if ($runTime !== null && intval($runTime) > intval($condition)) {
                    $fulfilled = true;
                } else {
                    $fulfilled = false;
                    break;
                }
            }
        }

        return $fulfilled;
    }
}
