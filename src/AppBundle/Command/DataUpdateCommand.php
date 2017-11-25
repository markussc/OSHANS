<?php

namespace AppBundle\Command;

use AppBundle\Entity\EdiMaxDataStore;
use AppBundle\Entity\ConexioDataStore;
use AppBundle\Entity\PcoWebDataStore;
use AppBundle\Entity\SmartFoxDataStore;
use AppBundle\Entity\MobileAlertsDataStore;
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
    private $output; // OutputInterface

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

        // smartfox
        $smartfox = $this->getContainer()->get('AppBundle\Utils\Connectors\SmartFoxConnector')->getAll();
        $smartfoxEntity = new SmartFoxDataStore();
        $smartfoxEntity->setTimestamp(new \DateTime('now'));
        $smartfoxEntity->setConnectorId($this->getContainer()->get('AppBundle\Utils\Connectors\SmartFoxConnector')->getIp());
        $smartfoxEntity->setData($smartfox);
        $em->persist($smartfoxEntity);

        // conexio
        $conexio = $this->getContainer()->get('AppBundle\Utils\Connectors\ConexioConnector')->getAll();
        $conexioEntity = new ConexioDataStore();
        $conexioEntity->setTimestamp(new \DateTime('now'));
        $conexioEntity->setConnectorId($this->getContainer()->get('AppBundle\Utils\Connectors\ConexioConnector')->getIp());
        $conexioEntity->setData($conexio);
        $em->persist($conexioEntity);

        // pcoweb
        $pcoweb = $this->getContainer()->get('AppBundle\Utils\Connectors\PcoWebConnector')->getAll();
        $pcowebEntity = new PcoWebDataStore();
        $pcowebEntity->setTimestamp(new \DateTime('now'));
        $pcowebEntity->setConnectorId($this->getContainer()->get('AppBundle\Utils\Connectors\PcoWebConnector')->getIp());
        $pcowebEntity->setData($pcoweb);
        $em->persist($pcowebEntity);

        // mobilealerts
        foreach ($this->getContainer()->get('AppBundle\Utils\Connectors\MobileAlertsConnector')->getAll() as $sensorId => $sensorData) {
            $mobilealertsEntity = new MobileAlertsDataStore();
            $mobilealertsEntity->setTimestamp(new \DateTime('now'));
            $mobilealertsEntity->setConnectorId($sensorId);
            $mobilealertsEntity->setData($sensorData);
            $em->persist($mobilealertsEntity);
        }

        // write to database
        $em->flush();

        // openweathermap
        $this->getContainer()->get('AppBundle\Utils\Connectors\OpenWeatherMapConnector')->save5DayForecastToDb();

        // execute auto actions for edimax devices
        $this->autoActionsEdimax();

        // execute auto actions for PcoWeb heating
        $this->autoActionsPcoWeb();
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
            // if curren net_power negative and average over last 10 minutes negative: turn on a device if its power consumption is less than the negative value (current and average)
            foreach ($this->getContainer()->get('AppBundle\Utils\Connectors\EdiMaxConnector')->getAllLatest() as $deviceId => $edimax) {
                // if a "forceOn" condition is set, check it (if true, try to turn it on and skip)
                if ($this->forceOnEdimax($deviceId, $edimax)) {
                    continue;
                }
                // check if the device is off, compare the required power with the current and average power over the last 10 minutes and check if the device is allowed to be turned on
                if (!$edimax['status']['val'] && $edimax['nominalPower'] < -1*$netPower && $edimax['nominalPower'] < -1*$avgPower && $this->getContainer()->get('AppBundle\Utils\Connectors\EdiMaxConnector')->switchOK($deviceId)) {
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

    /**
     * Based on the available values in the DB, decide whether any commands should be sent to attached pcoweb heating
     */
    private function autoActionsPcoWeb()
    {
        $energyLowRate = $this->getContainer()->get('AppBundle\Utils\ConditionChecker')->checkEnergyLowRate();
        $em = $this->getContainer()->get('doctrine.orm.entity_manager');
        $avgPvPower = $em->getRepository('AppBundle:SmartFoxDataStore')->getPvPowerAverage($this->getContainer()->get('AppBundle\Utils\Connectors\SmartFoxConnector')->getIp(), 10);
        // depending on the energy tariff, set the threshold values
        if ($energyLowRate) {
            // we are on low energy rate
            $minInsideTemp = 20;
            $maxInsideTemp = 21;
            $minWaterTemp = 45;
            $maxWaterTemp = 48;
        } else {
            // we are on high energy rate
            $minInsideTemp = 19;
            $maxInsideTemp = 20;
            $minWaterTemp = 38;
            $maxWaterTemp = 45;
        }

        // readout current temperature values
        $mobilealerts = $this->getContainer()->get('AppBundle\Utils\Connectors\MobileAlertsConnector')->getAllLatest();
        $mobilealerts = $mobilealerts[$this->getContainer()->get('AppBundle\Utils\Connectors\MobileAlertsConnector')->getId(0)];
        $insideTemp = $mobilealerts[1]['value']; // this is assumed to be the first value of the first mobilealerts sensor
        $pcoweb = $this->getContainer()->get('AppBundle\Utils\Connectors\PcoWebConnector')->getAll();
        $waterTemp = $pcoweb['waterTemp'];
        $ppMode = $this->getContainer()->get('AppBundle\Utils\Connectors\PcoWebConnector')->ppModeToInt($pcoweb['ppMode']);

        // get conexio values
        $conexio = $this->getContainer()->get('AppBundle\Utils\Connectors\ConexioConnector')->getAllLatest();
        $heatStorageMidTemp = ($conexio['s3'] + $conexio['s2'])/2;

        // readout weather forecast (currently the cloudiness for the next mid-day hours period)
        $avgClouds = $this->getContainer()->get('AppBundle\Utils\Connectors\OpenWeatherMapConnector')->getRelevantCloudsNextDaylightPeriod();
        if ($avgClouds < 30) {
            // we expect clear sky in the next daylight period which will give some extra heat. Reduce heating curve (circle 1)
            $this->getContainer()->get('AppBundle\Utils\Connectors\PcoWebConnector')->executeCommand('hc1', 25);
        } else {
            $this->getContainer()->get('AppBundle\Utils\Connectors\PcoWebConnector')->executeCommand('hc1', 30);
        }

        $setMode = false;
        // heat storige is low. Warm up on high PV power or low energy rate
        if ($heatStorageMidTemp < 33) {
            $activateHeating = false;
            if ($avgPvPower > 1900) {
                $activateHeating = true;
                $this->getContainer()->get('AppBundle\Utils\Connectors\PcoWebConnector')->executeCommand('hwHysteresis', 10);
                $setMode = true;
            }
            if ($energyLowRate) {
                $activateHeating = true;
                $this->getContainer()->get('AppBundle\Utils\Connectors\PcoWebConnector')->executeCommand('hwHysteresis', 70);
                $setMode = true;
            }
            if ($activateHeating && $ppMode !== PcoWebConnector::MODE_AUTO) {
                $this->getContainer()->get('AppBundle\Utils\Connectors\PcoWebConnector')->executeCommand('mode', PcoWebConnector::MODE_AUTO);
            }
        }
        // mode is not influenced by PV power or energy rate. Action should be taken based on temperature thresholds
        if (!$setMode) {
            if ($insideTemp < $minInsideTemp || $waterTemp < $minWaterTemp) {
                // we are below expected values (at least for one of the criteria), switch to auto mode and minimize hot water hysteresis
                if ($ppMode !== PcoWebConnector::MODE_AUTO) {
                    $this->getContainer()->get('AppBundle\Utils\Connectors\PcoWebConnector')->executeCommand('hwHysteresis', 7);
                    $this->getContainer()->get('AppBundle\Utils\Connectors\PcoWebConnector')->executeCommand('mode', PcoWebConnector::MODE_AUTO);
                }
            }
            if ($insideTemp > $maxInsideTemp && $waterTemp > $maxWaterTemp) {
                // the max levels for both criteria are reached, we can switch to summer mode. TODO: optimize summer / off modes
                if ($ppMode !== PcoWebConnector::MODE_2ND) {
                    $this->getContainer()->get('AppBundle\Utils\Connectors\PcoWebConnector')->executeCommand('hwHysteresis', 10);
                    $this->getContainer()->get('AppBundle\Utils\Connectors\PcoWebConnector')->executeCommand('mode', PcoWebConnector::MODE_2ND);
                }
            }
        }
    }
}
