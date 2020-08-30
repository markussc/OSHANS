<?php

namespace AppBundle\Utils;

use AppBundle\Entity\Settings;
use AppBundle\Entity\EdiMaxDataStore;
use AppBundle\Entity\MyStromDataStore;
use AppBundle\Entity\ConexioDataStore;
use AppBundle\Entity\LogoControlDataStore;
use AppBundle\Entity\PcoWebDataStore;
use AppBundle\Entity\SmartFoxDataStore;
use AppBundle\Entity\MobileAlertsDataStore;
use AppBundle\Entity\ShellyDataStore;
use AppBundle\Entity\CommandLog;
use AppBundle\Utils\Connectors\EdiMaxConnector;
use AppBundle\Utils\Connectors\MobileAlertsConnector;
use AppBundle\Utils\Connectors\OpenWeatherMapConnector;
use AppBundle\Utils\Connectors\MyStromConnector;
use AppBundle\Utils\Connectors\ShellyConnector;
use AppBundle\Utils\Connectors\PcoWebConnector;
use AppBundle\Utils\Connectors\LogoControlConnector;
use AppBundle\Utils\Connectors\ThreemaConnector;
use AppBundle\Utils\Connectors\SmartFoxConnector;
use AppBundle\Utils\Connectors\ConexioConnector;
use AppBundle\Utils\ConditionChecker;
use Doctrine\Common\Persistence\ObjectManager;
use Symfony\Component\Translation\TranslatorInterface;

/**
 *
 * @author Markus Schafroth
 */
class LogicProcessor
{
    protected $em;
    protected $edimax;
    protected $mobilealerts;
    protected $openweathermap;
    protected $mystrom;
    protected $shelly;
    protected $smartfox;
    protected $pcoweb;
    protected $conexio;
    protected $logo;
    protected $conditionchecker;
    protected $translator;
    protected $energyLowRate;
    protected $connectors;

    public function __construct(ObjectManager $em, EdiMaxConnector $edimax, MobileAlertsConnector $mobilealerts, OpenWeatherMapConnector $openweathermap, MyStromConnector $mystrom, ShellyConnector $shelly, SmartFoxConnector $smartfox, PcoWebConnector $pcoweb, ConexioConnector $conexio, LogoControlConnector $logo, ThreemaConnector $threema, ConditionChecker $conditionchecker, TranslatorInterface $translator, $energyLowRate, Array $connectors)
    {
        $this->em = $em;
        $this->edimax = $edimax;
        $this->mobilealerts = $mobilealerts;
        $this->openweathermap = $openweathermap;
        $this->mystrom = $mystrom;
        $this->shelly = $shelly;
        $this->smartfox = $smartfox;
        $this->pcoweb = $pcoweb;
        $this->conexio = $conexio;
        $this->logo = $logo;
        $this->threema = $threema;
        $this->conditionchecker = $conditionchecker;
        $this->energyLowRate = $energyLowRate;
        $this->connectors = $connectors;
        $this->translator = $translator;
    }

    public function execute()
    {
        // edimax
        $this->initEdimax();

        // mystrom
        $this->initMystrom();

        // shelly
        $this->initShelly();

        // smartfox
        $this->initSmartfox();

        // conexio
        $this->initConexio();

        // logocontrol
        $this->initLogo();

        // pcoweb
        $this->initPcoweb();

        // mobilealerts
        $this->initMobilealerts();

        // write to database
        $this->em->flush();

        // openweathermap
        $this->openweathermap->save5DayForecastToDb();
        $this->openweathermap->saveCurrentWeatherToDb();

        // execute auto actions for edimax devices
        $this->autoActionsEdimax();

        // execute auto actions for mystrom devices
        $this->autoActionsMystrom();

        // execute auto actions for shelly devices
        $this->autoActionsShelly();

        // execute auto actions for PcoWeb heating, if we are not in manual mode
        $pcoMode = $this->em->getRepository('AppBundle:Settings')->getMode($this->pcoweb->getIp());
        if (Settings::MODE_MANUAL != $pcoMode) {
            $this->autoActionsPcoWeb($pcoMode);
        }

        // process alarms
        $this->processAlarms();
    }

    /**
     * Based on the available values in the DB, decide whether any commands should be sent to attached edimax devices
     */
    public function autoActionsEdimax()
    {
        $avgPower = $this->em->getRepository('AppBundle:SmartFoxDataStore')->getNetPowerAverage($this->smartfox->getIp(), 10);

        // get current net_power
        $smartfox = $this->smartfox->getAllLatest();
        $netPower = $smartfox['power_io'];

        // auto actions for devices which have a nominalPower
        if ($netPower > 0) {
            if ($avgPower > 0) {
                // if current net_power positive and average over last 10 minutes positive as well: turn off the first found device
                foreach ($this->edimax->getAllLatest() as $deviceId => $edimax) {
                    if ($edimax['nominalPower'] > 0) {
                        // check for "forceOn" or "lowRateOn" conditions (if true, try to turn it on and skip)
                        if ($this->forceOnEdimax($deviceId, $edimax)) {
                            continue;
                        }
                        // check if the device is on and allowed to be turned off
                        if ($edimax['status']['val'] && $this->edimax->switchOK($deviceId)) {
                            $this->edimax->executeCommand($deviceId, 0);
                            break;
                        }
                    }
                }
            }
        } else {
            // if current net_power negative and average over last 10 minutes negative: turn on a device if its power consumption is less than the negative value (current and average)
            foreach ($this->edimax->getAllLatest() as $deviceId => $edimax) {
                if ($edimax['nominalPower'] > 0) {
                    // check for "forceOff" conditions (if true, try to turn it off and skip
                    if ($this->conditionchecker->checkCondition($edimax, 'forceOff')) {
                        $this->forceOffEdimax($deviceId, $edimax);
                        continue;
                    }
                    // if a "forceOn" condition is set, check it (if true, try to turn it on and skip)
                    if ($this->forceOnEdimax($deviceId, $edimax)) {
                        continue;
                    }
                    // check if the device is off, compare the required power with the current and average power over the last 10 minutes, and on condition is fulfilled (or not set) and check if the device is allowed to be turned on
                    if (!$edimax['status']['val'] && $edimax['nominalPower'] < -1*$netPower && $edimax['nominalPower'] < -1*$avgPower && $this->conditionchecker->checkCondition($edimax, 'on') && $this->edimax->switchOK($deviceId)) {
                        if($this->edimax->executeCommand($deviceId, 1)) {
                            break;
                        } else {
                            continue;
                        }
                    }
                }
            }
        }

        // auto actions for devices without nominal power
        foreach ($this->edimax->getAllLatest() as $deviceId => $edimax) {
            if ($edimax['nominalPower'] == 0) {
                if($this->conditionchecker->checkCondition($edimax, 'forceOff')) {
                    if ($this->forceOffEdimax($deviceId, $edimax)) {
                        break;
                    }
                } elseif ($this->conditionchecker->checkCondition($edimax, 'forceOn')) {
                    // we only try to activate if we disable not close just before (disable wins)
                    if ($this->forceOnEdimax($deviceId, $edimax)) {
                        break;
                    }
                }
            }
        }
    }

    private function forceOnEdimax($deviceId, $edimax)
    {
        $forceOn = $this->conditionchecker->checkCondition($edimax, 'forceOn');
        if ($forceOn && $this->edimax->switchOK($deviceId)) {
            // force turn it on if we are allowed to
            if (!$edimax['status']['val']) {
                $this->edimax->executeCommand($deviceId, 1);
            }
            return true;
        } else {
            return false;
        }
    }

    private function forceOffEdimax($deviceId, $edimax)
    {
        if ($edimax['status']['val'] && $this->edimax->switchOK($deviceId)) {
            // force turn it on if we are allowed to
            $this->edimax->executeCommand($deviceId, 0);
        }

        return true;
    }

    /**
     * Based on the available values in the DB, decide whether any commands should be sent to attached mystrom devices
     */
    public function autoActionsMystrom()
    {
        $avgPower = $this->em->getRepository('AppBundle:SmartFoxDataStore')->getNetPowerAverage($this->smartfox->getIp(), 10);

        // get current net_power
        $smartfox = $this->smartfox->getAllLatest();
        $netPower = $smartfox['power_io'];

        // auto actions for devices which have a nominalPower
        if ($netPower > 0) {
            if ($avgPower > 0) {
                // if current net_power positive and average over last 10 minutes positive as well: turn off the first found device
                foreach ($this->mystrom->getAllLatest() as $deviceId => $mystrom) {
                    if ($mystrom['nominalPower'] > 0) {
                        // check for "forceOn" or "lowRateOn" conditions (if true, try to turn it on and skip)
                        if ($this->forceOnMystrom($deviceId, $mystrom)) {
                            continue;
                        }
                        // check if the device is on and allowed to be turned off
                        if ($mystrom['status']['val'] && $this->mystrom->switchOK($deviceId)) {
                            $this->mystrom->executeCommand($deviceId, 0);
                            break;
                        }
                    }
                }
            }
        } else {
            // if current net_power negative and average over last 10 minutes negative: turn on a device if its power consumption is less than the negative value (current and average)
            foreach ($this->mystrom->getAllLatest() as $deviceId => $mystrom) {
                if ($mystrom['nominalPower'] > 0) {
                    // check for "forceOff" conditions (if true, try to turn it off and skip
                    if ($this->conditionchecker->checkCondition($mystrom, 'forceOff')) {
                        $this->forceOffMystrom($deviceId, $mystrom);
                        continue;
                    }
                    // if a "forceOn" condition is set, check it (if true, try to turn it on and skip)
                    if ($this->forceOnMystrom($deviceId, $mystrom)) {
                        continue;
                    }
                    // check if the device is off, compare the required power with the current and average power over the last 10 minutes, and on condition is fulfilled (or not set) and check if the device is allowed to be turned on
                    if (!$mystrom['status']['val'] && $mystrom['nominalPower'] < -1*$netPower && $mystrom['nominalPower'] < -1*$avgPower && $this->conditionchecker->checkCondition($mystrom, 'on') && $this->mystrom->switchOK($deviceId)) {
                        if($this->mystrom->executeCommand($deviceId, 1)) {
                            break;
                        } else {
                            continue;
                        }
                    }
                }
            }
        }

        // auto actions for devices without nominal power
        foreach ($this->mystrom->getAllLatest() as $deviceId => $mystrom) {
            if ($mystrom['nominalPower'] == 0) {
                if($this->conditionchecker->checkCondition($mystrom, 'forceOff')) {
                    if ($this->forceOffMystrom($deviceId, $mystrom)) {
                        continue;
                    }
                } elseif ($this->conditionchecker->checkCondition($mystrom, 'forceOn')) {
                    // we only try to activate if we disable not close just before (disable wins)
                    if ($this->forceOnMystrom($deviceId, $mystrom)) {
                        continue;
                    }
                }
            }
        }
    }

    private function forceOnMystrom($deviceId, $mystrom)
    {
        $forceOn = $this->conditionchecker->checkCondition($mystrom, 'forceOn');
        if ($forceOn  && $this->mystrom->switchOK($deviceId)) {
            // force turn it on if we are allowed to
            if (!$mystrom['status']['val']) {
                $this->mystrom->executeCommand($deviceId, 1);
            }
            return true;
        } else {
            return false;
        }
    }

    private function forceOffMystrom($deviceId, $mystrom)
    {
        if ($mystrom['status']['val'] && $this->mystrom->switchOK($deviceId)) {
            // force turn it on if we are allowed to
            $this->mystrom->executeCommand($deviceId, 0);
        }

        return true;
    }

    /**
     * Based on the available environmental data, decide whether any commands should be sent to attached shelly devices
     * NOTE: currently only implemented for roller devices
     */
    public function autoActionsShelly()
    {
        foreach ($this->shelly->getAllLatest() as $deviceId => $shelly) {
            $shellyConfig = $this->connectors['shelly'][$deviceId];
            if ($shellyConfig['type'] == 'roller') {
                // for rollers, check forceOpen and forceClose conditions
                if($this->conditionchecker->checkCondition($shelly, 'forceClose')) {
                    if ($this->forceCloseShelly($deviceId, $shelly)) {
                        break;
                    }
                } elseif ($this->conditionchecker->checkCondition($shelly, 'forceOpen')) {
                    // we only try to open if we did not close just before (closing wins)
                    if ($this->forceOpenShelly($deviceId, $shelly)) {
                        break;
                    }
                }
            } elseif ($shellyConfig['type'] != 'door') {
                // for switches, check force off and forceOn conditions
                if($this->conditionchecker->checkCondition($shelly, 'forceOff')) {
                    if ($this->forceOffShelly($deviceId, $shelly)) {
                        continue;
                    }
                } elseif ($this->conditionchecker->checkCondition($shelly, 'forceOn')) {
                    // we only try to activate if we did not disable just before (disable wins)
                    if ($this->forceOnShelly($deviceId, $shelly)) {
                        continue;
                    }
                }
            }
        }
    }

    private function forceOpenShelly($deviceId, $shelly)
    {
        if ($shelly['status']['position'] < 100 && $this->shelly->switchOK($deviceId)) {
            // force open if we are allowed to
            $this->shelly->executeCommand($deviceId, 2);
            return true;
        } else {
            return false;
        }
    }

    private function forceCloseShelly($deviceId, $shelly)
    {
        if ($shelly['status']['position'] > 0 && $this->shelly->switchOK($deviceId)) {
            // force open if we are allowed to
            $this->shelly->executeCommand($deviceId, 3);
            return true;
        } else {
            return false;
        }
    }

    private function forceOnShelly($deviceId, $shelly)
    {
        $forceOn = $this->conditionchecker->checkCondition($shelly, 'forceOn');
        if ($forceOn && $this->shelly->switchOK($deviceId)) {
            // force turn it on if we are allowed to
            if (!$shelly['status']['val']) {
                $this->shelly->executeCommand($deviceId, 1);
            }
            return true;
        } else {
            return false;
        }
    }

    private function forceOffShelly($deviceId, $shelly)
    {
        if ($shelly['status']['val'] && $this->shelly->switchOK($deviceId)) {
            // force turn it on if we are allowed to
            $this->shelly->executeCommand($deviceId, 0);
        }

        return true;
    }

    /**
     * Based on the available values in the DB, decide whether any commands should be sent to attached pcoweb heating
     */
    private function autoActionsPcoWeb($pcoMode)
    {
        if ($pcoMode === Settings::MODE_HOLIDAY) {
            $autoMode = PcoWebConnector::MODE_HOLIDAY;
        } else {
            $autoMode = PcoWebConnector::MODE_AUTO;
        }
        $energyLowRate = $this->conditionchecker->checkEnergyLowRate();
        $smartfox = $this->smartfox->getAllLatest();
        $smartFoxHighPower = $smartfox['digital'][0]['state'];
        $avgPower = $this->em->getRepository('AppBundle:SmartFoxDataStore')->getNetPowerAverage($this->smartfox->getIp(), 10);
        $avgPvPower = $this->em->getRepository('AppBundle:SmartFoxDataStore')->getPvPowerAverage($this->smartfox->getIp(), 10);
        $nowDateTime = new \DateTime();
        $diffToEndOfLowEnergyRate = $this->energyLowRate['end'] - $nowDateTime->format('H');
        if ($diffToEndOfLowEnergyRate < 0) {
            $diffToEndOfLowEnergyRate += 24;
        }
        $pcoweb = $this->pcoweb->getAll();

        // set the temperature offset for low outside temp
        $tempOffset = 0;
        $outsideTemp = $pcoweb['outsideTemp'];
        if ($outsideTemp < 2) {
            $tempOffset = (1 +(0-$outsideTemp)/10);
        }

        // set the emergency temperature levels
            // we are on low energy rate
            $minWaterTemp = 38;
            $minInsideTemp = 19.5+$tempOffset/5;
        // set the max inside temp above which we do not want to have the 2nd heat circle active
            $maxInsideTemp = 21.7+$tempOffset;
        // readout current temperature values
        if ($this->mobilealerts->getAvailable()) {
            $mobilealerts = $this->mobilealerts->getAllLatest();
            $mobilealerts = $mobilealerts[$this->mobilealerts->getId(0)];
            $insideTemp = $mobilealerts[1]['value']; // this is assumed to be the first value of the first mobilealerts sensor
        } else {
            // if no inside sensor is available, we assume 20°C
            $insideTemp = 20;
        }

        $waterTemp = $pcoweb['waterTemp'];
        if ($waterTemp === null) {
            // if waterTemp could not be read out, the values can not be trusted. Skip any further processing.
            return;
        }
        $ppMode = $this->pcoweb->ppModeToInt($pcoweb['ppMode']);
        $ppStatus = 0;
        if ($pcoweb['ppStatus'] == "label.device.status.on") {
            $ppStatus = 1;
        }

        $heatStorageMidTemp = $pcoweb['storTemp'];

        // readout weather forecast (currently the cloudiness for the next mid-day hours period)
        $avgClouds = $this->openweathermap->getRelevantCloudsNextDaylightPeriod();

        // write values to command log
        $commandLog = new CommandLog();
        $commandLog->setHighPvPower($smartFoxHighPower);
        $commandLog->setAvgPvPower($avgPvPower);
        $commandLog->setAvgPower($avgPower);
        $commandLog->setInsideTemp($insideTemp);
        $commandLog->setWaterTemp($waterTemp);
        $commandLog->setHeatStorageMidTemp($heatStorageMidTemp);
        $commandLog->setAvgClouds($avgClouds);
        $log = [];

        if ($this->pcoweb->getIp()) {
            // decide whether it's summer half year
            $isSummer = (\date('z') > 70 && \date('z') < 243); // 10th of march - 31th august

            $activateHeating = false;
            $deactivateHeating = false;
            $warmWater = false;

            if ($smartFoxHighPower && $waterTemp < 65) {
                // SmartFox has force heating flag set
                $activateHeating = true;
                // we make sure the hwHysteresis is set to a lower value, so hot water heating is forced
                $this->pcoweb->executeCommand('hwHysteresis', 5);
                // we make sure the heating curve (circle 1) is maximized
                $this->pcoweb->executeCommand('hc1', 40);
                $log[] = "PvHighPower, water temp < 65. minimize hwHysteresis (5), increase hc1 (set hc1=40)";

                if ($ppMode !== PcoWebConnector::MODE_AUTO) {
                    $this->pcoweb->executeCommand('mode', PcoWebConnector::MODE_AUTO);
                    $log[] = "set MODE_AUTO due to PvHighPower";
                }
            }

            // heat storage is low or net power is not growing too much into positive. Warm up on high PV power or low energy rate (if it makes any sense)
            if ($heatStorageMidTemp < 33 || ($avgPower < 2*$avgPvPower && ($heatStorageMidTemp < 55 || $waterTemp < 62 ))) {
                if (!$smartFoxHighPower && (((((!$isSummer || $avgClouds > 25) && \date('G') > 10) || \date('G') > 12) && $avgPvPower > 1300) || ($isSummer && $avgPvPower > 3000) )) {
                    // detected high PV power (independently of current use), but SmartFox is not forcing heating
                    // and either
                    // - winter, cloudy or later than 12am together with avgPvPower > 1300 W
                    // - summer and avgPvPower > 3000 W
                    $activateHeating = true;
                    // we make sure the hwHysteresis is set to the default value
                    $this->pcoweb->executeCommand('hwHysteresis', 10);
                    $this->pcoweb->executeCommand('hc1', 35);
                    $log[] = "low heatStorageMidTemp and/or relatively high PV but flag not set with not fully charged heat storage. Set hwHysteresis to default (10), increase hc1 (set hc1=35).";
                    if ($ppMode !== PcoWebConnector::MODE_AUTO && $ppMode !== PcoWebConnector::MODE_HOLIDAY && ($ppMode != PcoWebConnector::MODE_SUMMER || !$ppStatus)) {
                        $this->pcoweb->executeCommand('mode', PcoWebConnector::MODE_HOLIDAY);
                        $log[] = "set MODE_HOLIDAY due to high PV without flag set";
                    }
                }
            }

            // apply emergency actions
            $emergency = false;
            $insideEmergency = false;
            if ($insideTemp < $minInsideTemp || $waterTemp < $minWaterTemp) {
                // we are below expected values (at least for one of the criteria), switch HP on
                $activateHeating = true;
                $emergency = true;
                if ($insideTemp < $minInsideTemp) {
                    $insideEmergency = true;
                    $this->pcoweb->executeCommand('hc2', 30);
                    $log[] = "set hc2=30 as emergency action";
                    if (($ppMode !== Settings::MODE_AUTO || $ppMode !== Settings::MODE_HOLIDAY) && $heatStorageMidTemp < 36 && $pcoweb['effDistrTemp'] < 25) {
                        $this->pcoweb->executeCommand('mode', PcoWebConnector::MODE_HOLIDAY);
                        $log[] = "set MODE_HOLIDAY due to emergency action";
                    }
                } elseif ($pcoMode !== Settings::MODE_HOLIDAY) {
                    // only warmWater is too cold
                    $this->pcoweb->executeCommand('hwHysteresis', 10);
                    $log[] = "set hwHysteresis to default (10) due to emergency action";
                    if ($ppMode !== PcoWebConnector::MODE_SUMMER && $ppMode !== PcoWebConnector::MODE_AUTO) {
                        $this->pcoweb->executeCommand('mode', PcoWebConnector::MODE_SUMMER);
                        $log[] = "set MODE_SUMMER due to emergency action (warm water only)";
                    }
                }
            }

            // default cases for energy low rate
            if (!$emergency && $energyLowRate && $diffToEndOfLowEnergyRate > 1) {
                if ($avgClouds < 30) {
                    // we expect clear sky in the next daylight period which will give some extra heat. Reduce heating curve (circle 1)
                    $this->pcoweb->executeCommand('hc1', 23);
                    $log[] = "not PvHighPower, expected clear sky, reduce hc1 (set hc1=23)";
                } else {
                    $this->pcoweb->executeCommand('hc1', 28);
                    $log[] = "not PvHighPower, expected cloudy sky, increase hc1 (set hc1=28)";
                }
                $warmWater = false;
                if ($diffToEndOfLowEnergyRate <= 2) {
                    // 2 hours before end of energyLowRate, we decrease the hwHysteresis to make sure the warm water can be be heated up (only warm water will be heated during this hour!)
                    $this->pcoweb->executeCommand('hwHysteresis', 5);
                    $log[] = "diffToEndOfLowEnergyRate <= 2. reduce hwHysteresis (5)";
                    if ($pcoMode !== Settings::MODE_HOLIDAY) {
                        $warmWater = true;
                    }
                    $activateHeating = true;
                }
                if ($warmWater && $ppMode !== PcoWebConnector::MODE_SUMMER && ($waterTemp < 50 || $heatStorageMidTemp < 36)) {
                    // warm water generation only
                    $this->pcoweb->executeCommand('mode', PcoWebConnector::MODE_SUMMER);
                    $log[] = "set MODE_SUMMER for warm water generation only during low energy rate";
                }
                elseif (!$warmWater && $heatStorageMidTemp < 36) {
                    // storage heating only
                    $activateHeating = true;
                    if ((!$ppStatus || (PcoWebConnector::MODE_SUMMER && $waterTemp > $minWaterTemp + 4)) && ($ppMode !== PcoWebConnector::MODE_AUTO || $ppMode !== PcoWebConnector::MODE_HOLIDAY)) {
                        $this->pcoweb->executeCommand('hwHysteresis', 10);
                        $this->pcoweb->executeCommand('mode', PcoWebConnector::MODE_HOLIDAY);
                        $log[] = "set MODE_HOLIDAY for storage only heating during low energy rate";
                    }
                }
            }

            // end of energy low rate is near. switch to MODE_2ND or MODE_SUMMER (depending on current inside temperature) as soon as possible and reset the hwHysteresis to default value
            if (!$emergency && $diffToEndOfLowEnergyRate <= 1 && $diffToEndOfLowEnergyRate > 0) {
                $deactivateHeating = true;
                if (!$ppStatus && $ppMode !== PcoWebConnector::MODE_2ND && $insideTemp < $minInsideTemp+1) {
                    $this->pcoweb->executeCommand('mode', PcoWebConnector::MODE_2ND);
                    $log[] = "set MODE_2ND in final low energy rate";
                } elseif (!$ppStatus && $ppMode !== PcoWebConnector::MODE_SUMMER && $insideTemp >= $minInsideTemp+2) {
                    $this->pcoweb->executeCommand('mode', PcoWebConnector::MODE_SUMMER);
                    $log[] = "set MODE_SUMMER in final low energy rate";
                }
                $this->pcoweb->executeCommand('hwHysteresis', 10);
                $log[] = "set hwHysteresis to default (10)";
            }

            // deactivate 2nd heating circle if insideTemp is > $maxInsideTemp
            if ($insideTemp > $maxInsideTemp) {
                // it's warm enough, disable 2nd heating circle
                $this->pcoweb->executeCommand('hc2', 0);
                $this->pcoweb->executeCommand('cpAutoMode', 1);
                $log[] = "warm enough inside and waterTemp above minimum, disable hc2 (set hc2=0)";
                if ($waterTemp < $minWaterTemp + 3 && $ppMode == PcoWebConnector::MODE_SUMMER && !$emergency && !$warmWater && !$energyLowRate) {
                    $this->pcoweb->executeCommand('mode', PcoWebConnector::MODE_2ND);
                    $log[] = "set MODE_2ND instead of MODE_SUMMER due to water temp dropping towards but not below minimum during high energy rate";
                }
            } else {
                $activate2ndCircle = false;
                // it's not too warm, set 2nd heating circle with a reasonable target temperature
                if (!$emergency && $ppMode == PcoWebConnector::MODE_SUMMER && $insideTemp < ($minInsideTemp + 1)) {
                    // if we are in summer mode and insideTemp drops towards minInsideTemp
                    // if we are currently in summer mode (probably because before it was too warm inside), we switch back to MODE_2ND so 2nd heating circle can restart if required
                    $activate2ndCircle = true;
                    $this->pcoweb->executeCommand('cpAutoMode', 0);
                } elseif ($insideTemp > ($minInsideTemp + 0.8) && $insideTemp <= ($minInsideTemp + 1.5)) {
                    $this->pcoweb->executeCommand('hc2', 19);
                    $log[] = "set hc2=19 due to current inside temp";
                    $activate2ndCircle = true;
                } elseif ($insideTemp >= ($minInsideTemp + 0.5) && $insideTemp <= ($minInsideTemp + 0.8)) {
                    $this->pcoweb->executeCommand('hc2', 23);
                    $log[] = "set hc2=22 due to current inside temp";
                    $activate2ndCircle = true;
                } elseif (!$insideEmergency && $insideTemp < ($minInsideTemp + 0.5)) {
                    // set default value for 2nd heating circle
                    $this->pcoweb->executeCommand('hc2', 28);
                    $log[] = "set hc2=28 due to current inside temp";
                    $activate2ndCircle = true;
                }
                if (!$emergency && !$warmWater && $activate2ndCircle && $ppMode == PcoWebConnector::MODE_SUMMER && (!$ppStatus || $waterTemp > $minWaterTemp + 5)) {
                    // do no switch to MODE_2ND if we are in emergency mode or pp is currently running (except water temp is warmer than min + 5°C)
                    $this->pcoweb->executeCommand('mode', PcoWebConnector::MODE_2ND);
                    $log[] = "set MODE_2ND instead of MODE_SUMMER due to inside temp dropping towards minInsideTemp";
                }
            }

            // check if minimum requirements are fulfilled during high energy rate
            if (!$energyLowRate && !$activateHeating && $insideTemp > ($minInsideTemp + 0.5) && $heatStorageMidTemp > 28 && $waterTemp > ($minWaterTemp + 4)) {
                // the minimum requirements are fulfilled, no heating is required during high energy rate
                $deactivateHeating = true;
                $this->pcoweb->executeCommand('hwHysteresis', 12);
                $log[] = "high energy rate, set high hwHysteresis (12)";
                $this->pcoweb->executeCommand('hc1', 25);
                $log[] = "normalize hc1 (set hc1=25) during high energy rate";
                if ((($isSummer && $insideTemp > ($minInsideTemp + 1))|| $insideTemp >= $maxInsideTemp) && ($ppMode !== PcoWebConnector::MODE_SUMMER && $ppMode !== PcoWebConnector::MODE_HOLIDAY) && $pcoweb['hwHist'] >= 12) {
                    $this->pcoweb->executeCommand('mode', PcoWebConnector::MODE_SUMMER);
                    $log[] = "set MODE_SUMMER due to high energy rate";
                }
                if ((!$isSummer || $insideTemp < ($minInsideTemp + 1) ) && ($insideTemp < $maxInsideTemp || $ppMode === PcoWebConnector::MODE_HOLIDAY) && $ppMode !== PcoWebConnector::MODE_2ND) {
                    $this->pcoweb->executeCommand('mode', PcoWebConnector::MODE_2ND);
                    $log[] = "set MODE_2ND due to high energy rate";
                }
            }

            // make sure heating is deactivated if not required, during low energy rate
            if (!$activateHeating && $energyLowRate && !$ppStatus) {
                if ($insideTemp > ($minInsideTemp + 1.5)) {
                    if ($ppMode !== PcoWebConnector::MODE_SUMMER) {
                        $this->pcoweb->executeCommand('mode', PcoWebConnector::MODE_SUMMER);
                        $log[] = "set MODE_SUMMER during low energy rate and high inside temperature";
                    }
                } elseif ($ppMode !== PcoWebConnector::MODE_2ND && $insideTemp <= ($minInsideTemp + 1)) {
                    $this->pcoweb->executeCommand('hwHysteresis', 10);
                    $this->pcoweb->executeCommand('mode', PcoWebConnector::MODE_2ND);
                    $log[] = "set MODE_2ND and normalize hwHysteresis during low energy rate and lower inside temperature";
                }
            }
        }
        $pcowebNew = $this->pcoweb->getAll();
        $commandLog->setPpMode($pcowebNew['ppMode']);
        $commandLog->setLog($log);
        $commandLog->setTimestamp(new \DateTime());
        $this->em->persist($commandLog);
        $this->em->flush();
    }

    public function initEdimax()
    {
        foreach ($this->edimax->getAll() as $edimax) {
            $edimaxEntity = new EdiMaxDataStore();
            $edimaxEntity->setTimestamp(new \DateTime('now'));
            $edimaxEntity->setConnectorId($edimax['ip']);
            $edimaxEntity->setData($edimax['status']['val']);
            $this->em->persist($edimaxEntity);
            $this->em->flush();
        }
    }

    public function initMystrom($deviceId = null)
    {
        if ($deviceId !== null) {
            $device = $this->mystrom->getConfig($deviceId);
            if ($device) {
                $mystrom = $this->mystrom->getOne($device);
                $mystromEntity = new MyStromDataStore();
                $mystromEntity->setTimestamp(new \DateTime('now'));
                $mystromEntity->setConnectorId($mystrom['ip']);
                $mystromEntity->setData($mystrom['status']['val']);
                $this->em->persist($mystromEntity);
            }
        }
        foreach ($this->mystrom->getAll() as $mystrom) {
            $mystromEntity = new MyStromDataStore();
            $mystromEntity->setTimestamp(new \DateTime('now'));
            $mystromEntity->setConnectorId($mystrom['ip']);
            $mystromEntity->setData($mystrom['status']['val']);
            $this->em->persist($mystromEntity);
        }
        $this->em->flush();
    }

    public function initShelly($deviceId = null, $action = null) // $deviceId is a string in the form ip_port
    {
        if ($deviceId !== null) {
            $port = null;
            $ip_port = explode("_", $deviceId);
            $ip = $ip_port[0];
            if (count($ip_port) > 1) {
                $port = $ip_port[1];
            } else {
                $port = null;
            }
            $device = $this->shelly->getConfig($ip, $port);
            if ($device) {
                if ($action !== null) {
                    $shelly = $device;
                    $shelly['status'] = $this->shelly->createStatus($action);
                } else {
                    $shelly = $this->shelly->getOne($device);
                }
                if (!array_key_exists('port', $shelly)) {
                    $shelly['port'] = 0;
                }
                $shellyEntity = new ShellyDataStore();
                $shellyEntity->setTimestamp(new \DateTime('now'));
                $shellyEntity->setConnectorId($shelly['ip'].'_'.$shelly['port']);
                $shellyEntity->setData($shelly['status']);
                $this->em->persist($shellyEntity);
            }
        }
        else {
            foreach ($this->shelly->getAll() as $shelly) {
                if (!array_key_exists('port', $shelly)) {
                    $shelly['port'] = 0;
                }

                $shellyEntity = new ShellyDataStore();
                $shellyEntity->setTimestamp(new \DateTime('now'));
                $shellyEntity->setConnectorId($shelly['ip'].'_'.$shelly['port']);
                $shellyEntity->setData($shelly['status']);
                $this->em->persist($shellyEntity);
            }
        }
        $this->em->flush();
    }

    public function initSmartfox()
    {
        if ($this->smartfox->getIp()) {
            $smartfox = $this->smartfox->getAll();
            $smartfoxEntity = new SmartFoxDataStore();
            $smartfoxEntity->setTimestamp(new \DateTime('now'));
            $smartfoxEntity->setConnectorId($this->smartfox->getIp());
            if ($smartfox['PvEnergy'][0] <= 0) {
                $lastSmartFox = $this->smartfox->getAllLatest();
                if ($lastSmartFox) {
                    $smartfox['PvEnergy'][0] = $lastSmartFox['PvEnergy'][0];
                }
            }
            $smartfoxEntity->setData($smartfox);
            $this->em->persist($smartfoxEntity);
            $this->em->flush();
        }
    }

    public function initConexio()
    {
        if ($this->conexio->getIp()) {
            $conexio = $this->conexio->getAll();
            if ($conexio) {
                // we only want to store valid and complete data
                $conexioEntity = new ConexioDataStore();
                $conexioEntity->setTimestamp(new \DateTime('now'));
                $conexioEntity->setConnectorId($this->conexio->getIp());
                $conexioEntity->setData($conexio);
                $this->em->persist($conexioEntity);
                $this->em->flush();
            }
        }
    }

    public function initLogo()
    {
        if ($this->logo->getIp()) {
            $logocontrol = $this->logo->getAll();
            if ($logocontrol) {
                // we only want to store valid and complete data
                $logocontrolEntity = new LogoControlDataStore();
                $logocontrolEntity->setTimestamp(new \DateTime('now'));
                $logocontrolEntity->setConnectorId($this->logo->getIp());
                $logocontrolEntity->setData($logocontrol);
                $this->em->persist($logocontrolEntity);
                $this->em->flush();
            }
        }
    }

    public function initPcoweb()
    {
        if ($this->pcoweb->getIp()) {
            $pcoweb = $this->pcoweb->getAll();
            $pcowebEntity = new PcoWebDataStore();
            $pcowebEntity->setTimestamp(new \DateTime('now'));
            $pcowebEntity->setConnectorId($this->pcoweb->getIp());
            $pcowebEntity->setData($pcoweb);
            $this->em->persist($pcowebEntity);
            $this->em->flush();
        }
    }

    public function initMobilealerts()
    {
        if ($this->mobilealerts->getAvailable()) {
            foreach ($this->mobilealerts->getAll() as $sensorId => $sensorData) {
                $mobilealertsEntity = new MobileAlertsDataStore();
                $mobilealertsEntity->setTimestamp(new \DateTime('now'));
                $mobilealertsEntity->setConnectorId($sensorId);
                $mobilealertsEntity->setData($sensorData);
                $this->em->persist($mobilealertsEntity);
            }
            $this->em->flush();
        }
    }

    public function processAlarms()
    {
        $maAlarms = $this->mobilealerts->getAlarms();
        $msAlarms = $this->mystrom->getAlarms();
        $shAlarms = $this->shelly->getAlarms();
        $alarms = array_merge($maAlarms, $msAlarms, $shAlarms);
        if (count($alarms)) {
            $alarmSetting = $this->em->getRepository('AppBundle:Settings')->findOneByConnectorId('alarm');
            if(!$alarmSetting) {
                $alarmSetting = new Settings();
                $alarmSetting->setConnectorId('alarm');
                $alarmSetting->setMode(0);
            }
            if ($alarmSetting && $alarmSetting->getMode() == 1) {
                $alarmSetting->setMode(0);
                $this->em->flush();
                $alarmMsg = $this->translator->trans("label.alarm.active");
                foreach ($alarms as $alarm) {
                    $alarmMsg .= "\n" . $alarm['name'] . ": " . $this->translator->trans($alarm['state']);
                }
                foreach ($this->connectors['threema']['alarm'] as $alarmRecipient) {
                    $this->threema->sendMessage($alarmRecipient, $alarmMsg);
                }
            }
        }
    }

    public function configureDevice($deviceId)
    {
        // currently only required and available for Shelly Door-Sensors
        $this->shelly->executeCommand($deviceId, 100);
    }
}
