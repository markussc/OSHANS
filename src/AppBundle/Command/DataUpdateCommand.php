<?php

namespace AppBundle\Command;

use AppBundle\Entity\Settings;
use AppBundle\Entity\EdiMaxDataStore;
use AppBundle\Entity\MyStromDataStore;
use AppBundle\Entity\ConexioDataStore;
use AppBundle\Entity\LogoControlDataStore;
use AppBundle\Entity\PcoWebDataStore;
use AppBundle\Entity\SmartFoxDataStore;
use AppBundle\Entity\MobileAlertsDataStore;
use AppBundle\Entity\ShellyDataStore;
use AppBundle\Utils\Connectors\PcoWebConnector;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Retrieves data from connectors and stores it into the database
 *
 */
class DataUpdateCommand extends ContainerAwareCommand
{
    protected function configure()
    {
        $this
            ->setName('oshans:data:update')
            ->setDescription('Retrieve data from connectors and store in database')
        ;
    }

    /**
     * Updates data from connectors and stores in database
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return boolean
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $em = $this->getContainer()->get('doctrine.orm.entity_manager');

        // edimax
        foreach ($this->getContainer()->get('AppBundle\Utils\Connectors\EdiMaxConnector')->getAll() as $edimax) {
            $edimaxEntity = new EdiMaxDataStore();
            $edimaxEntity->setTimestamp(new \DateTime('now'));
            $edimaxEntity->setConnectorId($edimax['ip']);
            $edimaxEntity->setData($edimax['status']['val']);
            $em->persist($edimaxEntity);
        }

        // mystrom
        foreach ($this->getContainer()->get('AppBundle\Utils\Connectors\MyStromConnector')->getAll() as $mystrom) {
            $mystromEntity = new MyStromDataStore();
            $mystromEntity->setTimestamp(new \DateTime('now'));
            $mystromEntity->setConnectorId($mystrom['ip']);
            $mystromEntity->setData($mystrom['status']['val']);
            $em->persist($mystromEntity);
        }

        // shelly
        foreach ($this->getContainer()->get('AppBundle\Utils\Connectors\ShellyConnector')->getAll() as $shelly) {
            $shellyEntity = new ShellyDataStore();
            $shellyEntity->setTimestamp(new \DateTime('now'));
            $shellyEntity->setConnectorId($shelly['ip'].'_'.$shelly['port']);
            $shellyEntity->setData($shelly['status']);
            $em->persist($shellyEntity);
        }

        // smartfox
        if (array_key_exists('smartfox', $this->getContainer()->getParameter('connectors'))) {
            $smartfox = $this->getContainer()->get('AppBundle\Utils\Connectors\SmartFoxConnector')->getAll();
            $smartfoxEntity = new SmartFoxDataStore();
            $smartfoxEntity->setTimestamp(new \DateTime('now'));
            $smartfoxEntity->setConnectorId($this->getContainer()->get('AppBundle\Utils\Connectors\SmartFoxConnector')->getIp());
            $smartfoxEntity->setData($smartfox);
            if ($smartfox['PvEnergy'][0] > 0) {
                $em->persist($smartfoxEntity);
            }
        }

        // conexio
        if (array_key_exists('smartfox', $this->getContainer()->getParameter('connectors'))) {
            $conexio = $this->getContainer()->get('AppBundle\Utils\Connectors\ConexioConnector')->getAll();
            if ($conexio) {
                // we only want to store valid and complete data
                $conexioEntity = new ConexioDataStore();
                $conexioEntity->setTimestamp(new \DateTime('now'));
                $conexioEntity->setConnectorId($this->getContainer()->get('AppBundle\Utils\Connectors\ConexioConnector')->getIp());
                $conexioEntity->setData($conexio);
                $em->persist($conexioEntity);
            }
        }

        // logocontrol
        if (array_key_exists('logocontrol', $this->getContainer()->getParameter('connectors'))) {
            $logocontrol = $this->getContainer()->get('AppBundle\Utils\Connectors\LogoControlConnector')->getAll();
            if ($logocontrol) {
                // we only want to store valid and complete data
                $logocontrolEntity = new LogoControlDataStore();
                $logocontrolEntity->setTimestamp(new \DateTime('now'));
                $logocontrolEntity->setConnectorId($this->getContainer()->get('AppBundle\Utils\Connectors\LogoControlConnector')->getIp());
                $logocontrolEntity->setData($logocontrol);
                $em->persist($logocontrolEntity);
            }
        }

        // pcoweb
        if (array_key_exists('pcoweb', $this->getContainer()->getParameter('connectors'))) {
            $pcoweb = $this->getContainer()->get('AppBundle\Utils\Connectors\PcoWebConnector')->getAll();
            $pcowebEntity = new PcoWebDataStore();
            $pcowebEntity->setTimestamp(new \DateTime('now'));
            $pcowebEntity->setConnectorId($this->getContainer()->get('AppBundle\Utils\Connectors\PcoWebConnector')->getIp());
            $pcowebEntity->setData($pcoweb);
            $em->persist($pcowebEntity);
        }

        // mobilealerts
        if (array_key_exists('mobilealerts', $this->getContainer()->getParameter('connectors'))) {
            foreach ($this->getContainer()->get('AppBundle\Utils\Connectors\MobileAlertsConnector')->getAll() as $sensorId => $sensorData) {
                $mobilealertsEntity = new MobileAlertsDataStore();
                $mobilealertsEntity->setTimestamp(new \DateTime('now'));
                $mobilealertsEntity->setConnectorId($sensorId);
                $mobilealertsEntity->setData($sensorData);
                $em->persist($mobilealertsEntity);
            }
        }

        // write to database
        $em->flush();

        // openweathermap
        $this->getContainer()->get('AppBundle\Utils\Connectors\OpenWeatherMapConnector')->save5DayForecastToDb();
        $this->getContainer()->get('AppBundle\Utils\Connectors\OpenWeatherMapConnector')->saveCurrentWeatherToDb();

        // execute auto actions for edimax devices
        $this->autoActionsEdimax();

        // execute auto actions for mystrom devices
        $this->autoActionsMystrom();

        // execute auto actions for shelly devices
        $this->autoActionsShelly();

        // execute auto actions for PcoWeb heating, if we are in auto mode
        if (Settings::MODE_MANUAL != $em->getRepository('AppBundle:Settings')->getMode($this->getContainer()->get('AppBundle\Utils\Connectors\PcoWebConnector')->getIp())) {
            $this->autoActionsPcoWeb();
        }
    }

    /**
     * Based on the available values in the DB, decide whether any commands should be sent to attached edimax devices
     */
    private function autoActionsEdimax()
    {
        $em = $this->getContainer()->get('doctrine.orm.entity_manager');
        $avgPower = $em->getRepository('AppBundle:SmartFoxDataStore')->getNetPowerAverage($this->getContainer()->get('AppBundle\Utils\Connectors\SmartFoxConnector')->getIp(), 10);

        // get current net_power
        $smartfox = $this->getContainer()->get('AppBundle\Utils\Connectors\SmartFoxConnector')->getAllLatest();
        $netPower = $smartfox['power_io'];

        if ($netPower > 0) {
            if ($avgPower > 0) {
                // if current net_power positive and average over last 10 minutes positive as well: turn off the first found device
                foreach ($this->getContainer()->get('AppBundle\Utils\Connectors\EdiMaxConnector')->getAllLatest() as $deviceId => $edimax) {
                    // check for "forceOn" or "lowRateOn" conditions (if true, try to turn it on and skip)
                    if ($this->forceOnEdimax($deviceId, $edimax)) {
                        continue;
                    }
                    // check if the device is on and allowed to be turned off
                    if ($edimax['status']['val'] && $this->getContainer()->get('AppBundle\Utils\Connectors\EdiMaxConnector')->switchOK($deviceId)) {
                        $this->getContainer()->get('AppBundle\Utils\Connectors\EdiMaxConnector')->executeCommand($deviceId, 0);
                        break;
                    }
                }
            }
        } else {
            // if current net_power negative and average over last 10 minutes negative: turn on a device if its power consumption is less than the negative value (current and average)
            foreach ($this->getContainer()->get('AppBundle\Utils\Connectors\EdiMaxConnector')->getAllLatest() as $deviceId => $edimax) {
                // check for "forceOff" conditions (if true, try to turn it off and skip
                if ($this->getContainer()->get('AppBundle\Utils\ConditionChecker')->checkCondition($edimax, 'forceOff')) {
                    $this->forceOffEdimax($deviceId, $edimax);
                    continue;
                }
                // if a "forceOn" condition is set, check it (if true, try to turn it on and skip)
                if ($this->forceOnEdimax($deviceId, $edimax)) {
                    continue;
                }
                // check if the device is off, compare the required power with the current and average power over the last 10 minutes, and on condition is fulfilled (or not set) and check if the device is allowed to be turned on
                if (!$edimax['status']['val'] && $edimax['nominalPower'] < -1*$netPower && $edimax['nominalPower'] < -1*$avgPower && $this->getContainer()->get('AppBundle\Utils\ConditionChecker')->checkCondition($edimax, 'on') && $this->getContainer()->get('AppBundle\Utils\Connectors\EdiMaxConnector')->switchOK($deviceId)) {
                    $this->getContainer()->get('AppBundle\Utils\Connectors\EdiMaxConnector')->executeCommand($deviceId, 1);
                    break;
                }
            }
        }
    }

    private function forceOnEdimax($deviceId, $edimax)
    {
        $forceOn = $this->getContainer()->get('AppBundle\Utils\ConditionChecker')->checkCondition($edimax);
        if ($forceOn && !$edimax['status']['val'] && $this->getContainer()->get('AppBundle\Utils\Connectors\EdiMaxConnector')->switchOK($deviceId)) {
            // force turn it on if we are allowed to
            $this->getContainer()->get('AppBundle\Utils\Connectors\EdiMaxConnector')->executeCommand($deviceId, 1);
            return true;
        } else {
            return false;
        }
    }

    private function forceOffEdimax($deviceId, $edimax)
    {
        if ($edimax['status']['val'] && $this->getContainer()->get('AppBundle\Utils\Connectors\EdiMaxConnector')->switchOK($deviceId)) {
            // force turn it on if we are allowed to
            $this->getContainer()->get('AppBundle\Utils\Connectors\EdiMaxConnector')->executeCommand($deviceId, 0);
        }

        return true;
    }

    /**
     * Based on the available values in the DB, decide whether any commands should be sent to attached mystrom devices
     */
    private function autoActionsMystrom()
    {
        $em = $this->getContainer()->get('doctrine.orm.entity_manager');
        $avgPower = $em->getRepository('AppBundle:SmartFoxDataStore')->getNetPowerAverage($this->getContainer()->get('AppBundle\Utils\Connectors\SmartFoxConnector')->getIp(), 10);

        // get current net_power
        $smartfox = $this->getContainer()->get('AppBundle\Utils\Connectors\SmartFoxConnector')->getAllLatest();
        $netPower = $smartfox['power_io'];

        if ($netPower > 0) {
            if ($avgPower > 0) {
                // if current net_power positive and average over last 10 minutes positive as well: turn off the first found device
                foreach ($this->getContainer()->get('AppBundle\Utils\Connectors\MyStromConnector')->getAllLatest() as $deviceId => $mystrom) {
                    // check for "forceOn" or "lowRateOn" conditions (if true, try to turn it on and skip)
                    if ($this->forceOnMystrom($deviceId, $mystrom)) {
                        continue;
                    }
                    // check if the device is on and allowed to be turned off
                    if ($mystrom['status']['val'] && $this->getContainer()->get('AppBundle\Utils\Connectors\MyStromConnector')->switchOK($deviceId)) {
                        $this->getContainer()->get('AppBundle\Utils\Connectors\MyStromConnector')->executeCommand($deviceId, 0);
                        break;
                    }
                }
            }
        } else {
            // if current net_power negative and average over last 10 minutes negative: turn on a device if its power consumption is less than the negative value (current and average)
            foreach ($this->getContainer()->get('AppBundle\Utils\Connectors\MyStromConnector')->getAllLatest() as $deviceId => $mystrom) {
                // check for "forceOff" conditions (if true, try to turn it off and skip
                if ($this->getContainer()->get('AppBundle\Utils\ConditionChecker')->checkCondition($mystrom, 'forceOff')) {
                    $this->forceOffMystrom($deviceId, $mystrom);
                    continue;
                }
                // if a "forceOn" condition is set, check it (if true, try to turn it on and skip)
                if ($this->forceOnMystrom($deviceId, $mystrom)) {
                    continue;
                }
                // check if the device is off, compare the required power with the current and average power over the last 10 minutes, and on condition is fulfilled (or not set) and check if the device is allowed to be turned on
                if (!$mystrom['status']['val'] && $mystrom['nominalPower'] < -1*$netPower && $mystrom['nominalPower'] < -1*$avgPower && $this->getContainer()->get('AppBundle\Utils\ConditionChecker')->checkCondition($mystrom, 'on') && $this->getContainer()->get('AppBundle\Utils\Connectors\MyStromConnector')->switchOK($deviceId)) {
                    $this->getContainer()->get('AppBundle\Utils\Connectors\MyStromConnector')->executeCommand($deviceId, 1);
                    break;
                }
            }
        }
    }

    private function forceOnMystrom($deviceId, $mystrom)
    {
        $forceOn = $this->getContainer()->get('AppBundle\Utils\ConditionChecker')->checkCondition($mystrom);
        if ($forceOn && !$mystrom['status']['val'] && $this->getContainer()->get('AppBundle\Utils\Connectors\MyStromConnector')->switchOK($deviceId)) {
            // force turn it on if we are allowed to
            $this->getContainer()->get('AppBundle\Utils\Connectors\MyStromConnector')->executeCommand($deviceId, 1);
            return true;
        } else {
            return false;
        }
    }

    private function forceOffMystrom($deviceId, $mystrom)
    {
        if ($mystrom['status']['val'] && $this->getContainer()->get('AppBundle\Utils\Connectors\MyStromConnector')->switchOK($deviceId)) {
            // force turn it on if we are allowed to
            $this->getContainer()->get('AppBundle\Utils\Connectors\MyStromConnector')->executeCommand($deviceId, 0);
        }

        return true;
    }

    /**
     * Based on the available environmental data, decide whether any commands should be sent to attached shelly devices
     * NOTE: currently only implemented for roller devices
     */
    private function autoActionsShelly()
    {
        foreach ($this->getContainer()->get('AppBundle\Utils\Connectors\ShellyConnector')->getAllLatest() as $deviceId => $shelly) {
            $shellyConfig = $this->getContainer()->getParameter('connectors')['shelly'][$deviceId];
            if ($shellyConfig['type'] == 'roller') {
                // for rollers, check forceOpen and forceClose conditions
                if($this->getContainer()->get('AppBundle\Utils\ConditionChecker')->checkCondition($shelly, 'forceClose')) {
                    if ($this->forceCloseShelly($deviceId, $shelly)) {
                        break;
                    }
                } elseif ($this->getContainer()->get('AppBundle\Utils\ConditionChecker')->checkCondition($shelly, 'forceOpen')) {
                    // we only try to open if we did not close just before (closing wins)
                    if ($this->forceOpenShelly($deviceId, $shelly)) {
                        break;
                    }
                }
            }
        }
    }

    private function forceOpenShelly($deviceId, $shelly)
    {
        if ($shelly['status']['position'] < 100 && $this->getContainer()->get('AppBundle\Utils\Connectors\ShellyConnector')->switchOK($deviceId)) {
            // force open if we are allowed to
            $this->getContainer()->get('AppBundle\Utils\Connectors\ShellyConnector')->executeCommand($deviceId, 2);
            return true;
        } else {
            return false;
        }
    }

    private function forceCloseShelly($deviceId, $shelly)
    {
        if ($shelly['status']['position'] > 0 && $this->getContainer()->get('AppBundle\Utils\Connectors\ShellyConnector')->switchOK($deviceId)) {
            // force open if we are allowed to
            $this->getContainer()->get('AppBundle\Utils\Connectors\ShellyConnector')->executeCommand($deviceId, 3);
            return true;
        } else {
            return false;
        }
    }

    /**
     * Based on the available values in the DB, decide whether any commands should be sent to attached pcoweb heating
     */
    private function autoActionsPcoWeb()
    {
        $energyLowRate = $this->getContainer()->get('AppBundle\Utils\ConditionChecker')->checkEnergyLowRate();
        $smartfox = $this->getContainer()->get('AppBundle\Utils\Connectors\SmartFoxConnector')->getAllLatest();
        $smartFoxHighPower = $smartfox['digital'][0]['state'];
        $em = $this->getContainer()->get('doctrine.orm.entity_manager');
        $avgPower = $em->getRepository('AppBundle:SmartFoxDataStore')->getNetPowerAverage($this->getContainer()->get('AppBundle\Utils\Connectors\SmartFoxConnector')->getIp(), 10);
        $avgPvPower = $em->getRepository('AppBundle:SmartFoxDataStore')->getPvPowerAverage($this->getContainer()->get('AppBundle\Utils\Connectors\SmartFoxConnector')->getIp(), 10);
        $nowDateTime = new \DateTime();
        $diffToEndOfLowEnergyRate = $this->getContainer()->getParameter('energy_low_rate')['end'] - $nowDateTime->format('H');
        if ($diffToEndOfLowEnergyRate < 0) {
            $diffToEndOfLowEnergyRate += 24;
        }

        // set the emergency temperature levels
            // we are on low energy rate
            $minWaterTemp = 38;
            $minInsideTemp = 19.5;
        // set the max inside temp above which we do not want to have the 2nd heat circle active
            $maxInsideTemp = 22;

        // readout current temperature values
        if (array_key_exists('mobilealerts', $this->getContainer()->getParameter('connectors'))) {
            $mobilealerts = $this->getContainer()->get('AppBundle\Utils\Connectors\MobileAlertsConnector')->getAllLatest();
            $mobilealerts = $mobilealerts[$this->getContainer()->get('AppBundle\Utils\Connectors\MobileAlertsConnector')->getId(0)];
            $insideTemp = $mobilealerts[1]['value']; // this is assumed to be the first value of the first mobilealerts sensor
        } else {
            // if no inside sensor is available, we assume 20°C
            $insideTemp = 20;
        }
        $pcoweb = $this->getContainer()->get('AppBundle\Utils\Connectors\PcoWebConnector')->getAll();
        $waterTemp = $pcoweb['waterTemp'];
        $ppMode = $this->getContainer()->get('AppBundle\Utils\Connectors\PcoWebConnector')->ppModeToInt($pcoweb['ppMode']);

        // if no heatStorage sensor is available, we assume 35°C
        $heatStorageMidTemp = 35;
        
        if (array_key_exists('conexio', $this->getContainer()->getParameter('connectors'))) {
            // get conexio value for heatStorage temperature (if available)
            $conexio = $this->getContainer()->get('AppBundle\Utils\Connectors\ConexioConnector')->getAllLatest();
            $heatStorageMidTemp = ($conexio['s3'] + $conexio['s2'])/2;
        } else if (array_key_exists('logocontrol', $this->getContainer()->getParameter('connectors')) && array_key_exists('heatStorageSensor', $this->getContainer()->getParameter('connectors')['logocontrol'])) {
            // get conexio value for heatStorage temperature (if available and not set by conexio already)
            $logocontrol = $this->getContainer()->get('AppBundle\Utils\Connectors\LogoControlConnector')->getAllLatest();
            $logocontrolConf = $this->getContainer()->getParameter('connectors')['logocontrol'];
            $heatStorageMidTemp = $logocontrol[$logocontrolConf['heatStorageSensor']];
        }

        // readout weather forecast (currently the cloudiness for the next mid-day hours period)
        $avgClouds = $this->getContainer()->get('AppBundle\Utils\Connectors\OpenWeatherMapConnector')->getRelevantCloudsNextDaylightPeriod();
        if (array_key_exists('pcoweb', $this->getContainer()->getParameter('connectors'))) {
            if (!$smartFoxHighPower && $avgClouds < 30) {
                // we expect clear sky in the next daylight period which will give some extra heat. Reduce heating curve (circle 1)
                $this->getContainer()->get('AppBundle\Utils\Connectors\PcoWebConnector')->executeCommand('hc1', 25);
            } elseif (!$smartFoxHighPower) {
                $this->getContainer()->get('AppBundle\Utils\Connectors\PcoWebConnector')->executeCommand('hc1', 30);
            }

            // decide whether it's summer half year
            $isSummer = (\date('z') > 70 && \date('z') < 243); // 10th of march - 31th august

            $activateHeating = false;
            $deactivateHeating = false;

            if ($smartFoxHighPower && $waterTemp < 65) {
                // SmartFox has force heating flag set
                $activateHeating = true;
                // we make sure the hwHysteresis is set to a lower value, so hot water heating is forced
                $this->getContainer()->get('AppBundle\Utils\Connectors\PcoWebConnector')->executeCommand('hwHysteresis', 5);
                // we make sure the heating curve (circle 1) is maximized
                $this->getContainer()->get('AppBundle\Utils\Connectors\PcoWebConnector')->executeCommand('hc1', 40);
                if ($ppMode !== PcoWebConnector::MODE_AUTO) {
                    $this->getContainer()->get('AppBundle\Utils\Connectors\PcoWebConnector')->executeCommand('mode', PcoWebConnector::MODE_AUTO);
                }
            }

            // heat storage is low or net power is not growing too much into positive. Warm up on high PV power or low energy rate (if it makes any sense)
            if ($heatStorageMidTemp < 33 || ($avgPower < 2*$avgPvPower && ($heatStorageMidTemp < 55 || $waterTemp < 62 ))) {
                if (!$smartFoxHighPower && (((!$isSummer || $avgClouds > 25 || \date('G') > 12) && $avgPvPower > 1300) || ($isSummer && $avgPvPower > 3000) )) {
                    // detected high PV power (independently of current use), but SmartFox is not forcing heating
                    // and either
                    // - winter, cloudy or later than 12am together with avgPvPower > 1700 W
                    // - summer and avgPvPower > 3000 W
                    $activateHeating = true;
                    // we make sure the hwHysteresis is set to the default value
                    $this->getContainer()->get('AppBundle\Utils\Connectors\PcoWebConnector')->executeCommand('hwHysteresis', 10);

                    if ($ppMode !== PcoWebConnector::MODE_AUTO) {
                        $this->getContainer()->get('AppBundle\Utils\Connectors\PcoWebConnector')->executeCommand('mode', PcoWebConnector::MODE_AUTO);
                    }
                }
            }

            // default cases for energy low rate
            if ($energyLowRate && $diffToEndOfLowEnergyRate > 1) {
                $warmWater = false;
                if ($diffToEndOfLowEnergyRate <= 2) {
                    // 2 hours before end of energyLowRate, we decrease the hwHysteresis to make sure the warm water can be be heated up (only warm water will be heated during this hour!)
                    $this->getContainer()->get('AppBundle\Utils\Connectors\PcoWebConnector')->executeCommand('hwHysteresis', 5);
                    $warmWater = true;
                    $activateHeating = true;
                }
                if ($warmWater && $ppMode !== PcoWebConnector::MODE_SUMMER && ($waterTemp < 50 || $heatStorageMidTemp < 36)) {
                    // warm water generation only
                    $this->getContainer()->get('AppBundle\Utils\Connectors\PcoWebConnector')->executeCommand('mode', PcoWebConnector::MODE_SUMMER);
                }
                if (!$warmWater && $heatStorageMidTemp < 36) {
                    // combined heating
                    $activateHeating = true;
                    if ($ppMode !== PcoWebConnector::MODE_AUTO) {
                        $this->getContainer()->get('AppBundle\Utils\Connectors\PcoWebConnector')->executeCommand('hwHysteresis', 10);
                        $this->getContainer()->get('AppBundle\Utils\Connectors\PcoWebConnector')->executeCommand('mode', PcoWebConnector::MODE_AUTO);
                    }
                }
            }

            // end of energy low rate is near. switch to MODE_2ND or MODE_SUMMER (depending on current inside temperature) as soon as possible and reset the hwHysteresis to default value
            if ($diffToEndOfLowEnergyRate <= 1) {
                $deactivateHeating = true;
                if ($ppMode !== PcoWebConnector::MODE_2ND && $insideTemp < $minInsideTemp+1) {
                    $this->getContainer()->get('AppBundle\Utils\Connectors\PcoWebConnector')->executeCommand('mode', PcoWebConnector::MODE_2ND);
                } elseif ($ppMode !== PcoWebConnector::MODE_SUMMER && $insideTemp >= $minInsideTemp+2) {
                    $this->getContainer()->get('AppBundle\Utils\Connectors\PcoWebConnector')->executeCommand('mode', PcoWebConnector::MODE_SUMMER);
                }
                $this->getContainer()->get('AppBundle\Utils\Connectors\PcoWebConnector')->executeCommand('hwHysteresis', 10);
                return;
            }

            // deactivate 2nd heating circle if insideTemp is > $maxInsideTemp
            if ($insideTemp > $maxInsideTemp) {
                // it's warm enough, disable 2nd heating circle
                $this->getContainer()->get('AppBundle\Utils\Connectors\PcoWebConnector')->executeCommand('hc2', 0);
            } else {
                // it's not too warm, set 2nd heating circle with a low target temperature
                $this->getContainer()->get('AppBundle\Utils\Connectors\PcoWebConnector')->executeCommand('hc2', 10);
                if ($ppMode == PcoWebConnector::MODE_SUMMER && $insideTemp < ($minInsideTemp + 0.5)) {
                    // if we are in summer mode and insideTemp drops towards minInsideTemp
                    // if we are currently in summer mode (probably because before it was too warm inside), we switch back to MODE_2ND so 2nd heating circle can restart if required
                    $this->getContainer()->get('AppBundle\Utils\Connectors\PcoWebConnector')->executeCommand('mode', PcoWebConnector::MODE_2ND);
                }
            }
            // apply emergency actions
            if ($insideTemp < $minInsideTemp || $waterTemp < $minWaterTemp) {
                // we are below expected values (at least for one of the criteria), switch HP on
                $activateHeating = true;
                if ($insideTemp < $minInsideTemp) {
                    $this->getContainer()->get('AppBundle\Utils\Connectors\PcoWebConnector')->executeCommand('hc2', 22);
                    if ($ppMode !== PcoWebConnector::MODE_AUTO) {
                        $this->getContainer()->get('AppBundle\Utils\Connectors\PcoWebConnector')->executeCommand('mode', PcoWebConnector::MODE_AUTO);
                    }
                } else {
                    // only warmWater is too cold
                    if ($ppMode !== PcoWebConnector::MODE_SUMMER && $ppMode !== PcoWebConnector::MODE_AUTO) {
                        $this->getContainer()->get('AppBundle\Utils\Connectors\PcoWebConnector')->executeCommand('mode', PcoWebConnector::MODE_AUTO);
                    }
                }
            }
            if (!$energyLowRate && !$activateHeating && $insideTemp > ($minInsideTemp + 1) && $heatStorageMidTemp > 28 && $waterTemp > ($minWaterTemp + 4)) {
                // the minimum requirements are fulfilled, no heating is required during high energy rate
                $deactivateHeating = true;
                $this->getContainer()->get('AppBundle\Utils\Connectors\PcoWebConnector')->executeCommand('hwHysteresis', 10);
                if (($isSummer || $insideTemp >= $maxInsideTemp) && $ppMode !== PcoWebConnector::MODE_SUMMER) {
                    $this->getContainer()->get('AppBundle\Utils\Connectors\PcoWebConnector')->executeCommand('mode', PcoWebConnector::MODE_SUMMER);
                }
                if (!$isSummer && $insideTemp < $maxInsideTemp && $ppMode !== PcoWebConnector::MODE_2ND) {
                    $this->getContainer()->get('AppBundle\Utils\Connectors\PcoWebConnector')->executeCommand('mode', PcoWebConnector::MODE_2ND);
                }
            }

            // make sure heating is deactivated if not required, during low energy rate
            if (!$activateHeating && $energyLowRate) {
                if ($insideTemp > ($minInsideTemp + 1)) {
                    if ($ppMode !== PcoWebConnector::MODE_SUMMER) {
                        $this->getContainer()->get('AppBundle\Utils\Connectors\PcoWebConnector')->executeCommand('mode', PcoWebConnector::MODE_SUMMER);
                    }
                } elseif ($ppMode !== PcoWebConnector::MODE_2ND) {
                    $this->getContainer()->get('AppBundle\Utils\Connectors\PcoWebConnector')->executeCommand('hwHysteresis', 10);
                    $this->getContainer()->get('AppBundle\Utils\Connectors\PcoWebConnector')->executeCommand('mode', PcoWebConnector::MODE_2ND);
                }
            }
        }
    }
}
