<?php

namespace AppBundle\Utils\Connectors;

use Doctrine\ORM\EntityManager;
use AppBundle\Entity\Settings;

/**
 * Connector to retrieve data from MyStrom devices
 * For information refer to www.mystrom.ch or api.mystrom.ch
 *
 * @author Markus Schafroth
 */
class MyStromConnector
{
    protected $em;
    protected $browser;
    protected $connectors;

    public function __construct(EntityManager $em, \Buzz\Browser $browser, Array $connectors, $host, $session_cookie_path)
    {
        $this->em = $em;
        $this->browser = $browser;
        $this->connectors = $connectors;
        // set timeout for buzz browser client
        $this->browser->getClient()->setTimeout(3);
        $this->host = $host;
        $this->session_cookie_path = $session_cookie_path;
    }

    public function getAlarms()
    {
        $alarms = [];
        if (array_key_exists('mystrom', $this->connectors)) {
            foreach ($this->connectors['mystrom'] as $deviceConf) {
                if (array_key_exists('type', $deviceConf) && $deviceConf['type'] == 'motion') {
                    $status = $this->em->getRepository('AppBundle:MyStromDataStore')->getLatest($deviceConf['ip']);
                    if ($status) {
                        $alarms[] = [
                            'name' => $deviceConf['name'],
                            'state' => 'label.device.status.motion_detected',
                            'type' => 'motion',
                        ];
                    }
                }
            }
        }

        return $alarms;
    }

    public function motionAvailable()
    {
        if (array_key_exists('mystrom', $this->connectors)) {
            foreach ($this->connectors['mystrom'] as $deviceConf) {
                if (array_key_exists('type', $deviceConf) && $deviceConf['type'] == 'motion') {
                    return true;
                }
            }
        }

        return true;
    }

    /**
     * Reads the latest available data from the database
     * @return array
     */
    public function getAllLatest()
    {
        $results = [];
        $today = new \DateTime('today');
        $now = new \DateTime();
        if (array_key_exists('mystrom', $this->connectors) && is_array($this->connectors['mystrom'])) {
            foreach ($this->connectors['mystrom'] as $device) {
                $mode = $this->em->getRepository('AppBundle:Settings')->getMode($device['ip']);
                if (isset($device['nominalPower'])) {
                    $nominalPower = $device['nominalPower'];
                } else {
                    $nominalPower = 0;
                }
                if (isset($device['autoIntervals'])) {
                    $autoIntervals = $device['autoIntervals'];
                } else {
                    $autoIntervals = [];
                }
                if (isset($device['type'])) {
                    $type = $device['type'];
                } else {
                    $type = 'relay';
                }
                $results[] = [
                    'ip' => $device['ip'],
                    'name' => $device['name'],
                    'type' => $type,
                    'status' => $this->createStatus($this->em->getRepository('AppBundle:MyStromDataStore')->getLatest($device['ip'])),
                    'nominalPower' => $nominalPower,
                    'autoIntervals' => $autoIntervals,
                    'mode' => $mode,
                    'activeMinutes' => $this->em->getRepository('AppBundle:MyStromDataStore')->getActiveDuration($device['ip'], $today, $now),
                ];
            }
        }

        return $results;
    }

    public function getAll()
    {
        $results = [];

        if (array_key_exists('mystrom', $this->connectors) && is_array($this->connectors['mystrom'])) {
            foreach ($this->connectors['mystrom'] as $device) {
                $results[] = $this->getOne($device);
            }
        }

        return $results;
    }

    public function getOne($device)
    {
        $today = new \DateTime('today');
        $now = new \DateTime();

        $status = $this->getStatus($device);
        $mode = $this->em->getRepository('AppBundle:Settings')->getMode($device['ip']);
        if (isset($device['nominalPower'])) {
            $nominalPower = $device['nominalPower'];
        } else {
            $nominalPower = 0;
        }
        if (isset($device['autoIntervals'])) {
            $autoIntervals = $device['autoIntervals'];
        } else {
            $autoIntervals = [];
        }
        if (isset($device['type'])) {
            $type = $device['type'];
        } else {
            $type = 'relay';
        }

        return [
            'ip' => $device['ip'],
            'name' => $device['name'],
            'type' => $type,
            'status' => $status,
            'nominalPower' => $nominalPower,
            'autoIntervals' => $autoIntervals,
            'mode' => $mode,
            'activeMinutes' => $this->em->getRepository('AppBundle:MyStromDataStore')->getActiveDuration($device['ip'], $today, $now),
        ];
    }

    public function activateAllPIR()
    {
        if (array_key_exists('mystrom', $this->connectors) && is_array($this->connectors['mystrom'])) {
            foreach ($this->connectors['mystrom'] as $key => $device) {
                if (isset($device['type']) && $device['type'] == 'motion') {
                    $this->executeCommand($key, 10);
                }
            }
        }
    }

    public function executeCommand($deviceId, $command)
    {
        switch ($command) {
            case 1:
                // turn it on
                return $this->setOn($this->connectors['mystrom'][$deviceId]);
            case 0:
                // turn it off
                return $this->setOff($this->connectors['mystrom'][$deviceId]);
            case 10:
                // activate PIR action
                return $this->activatePIR($this->connectors['mystrom'][$deviceId]);
        }
        // no known command
        return false;
    }

    public function switchOK($deviceId)
    {
        // check if manual mode is set
        if ($this->em->getRepository('AppBundle:Settings')->getMode($this->connectors['mystrom'][$deviceId]['ip']) == Settings::MODE_MANUAL) {
            return false;
        }

        // check if autoIntervals or nominalPower are set (if not, this is not a device to be managed automatically)
        if (!isset($this->connectors['mystrom'][$deviceId]['autoIntervals']) && !isset($this->connectors['mystrom'][$deviceId]['nominalPower'])) {
            return false;
        }

        // get current status
        $currentStatus = $this->getStatus($this->connectors['mystrom'][$deviceId])['val'];

        // get latest timestamp with opposite status
        $oldStatus = $this->em->getRepository('AppBundle:MyStromDataStore')->getLatest($this->connectors['mystrom'][$deviceId]['ip'], ($currentStatus + 1)%2);
        if (count($oldStatus) == 1) {
            $oldTimestamp = $oldStatus[0]->getTimestamp();

            // calculate time diff
            $now = new \DateTime('now');
            $diff = ($now->getTimestamp() - $oldTimestamp->getTimestamp())/60; // diff in minutes
            if ($currentStatus) {
                // currently on, we want to switch off
                $minOnTime = array_key_exists('minOnTime', $this->connectors['mystrom'][$deviceId])?$this->connectors['mystrom'][$deviceId]['minOnTime']:15;
                if ($diff > $minOnTime) {
                    // check the minOnTime
                    return true;
                }
            } else {
                // currently off, we want to switch on
                $minOffTime = array_key_exists('minOffTime', $this->connectors['mystrom'][$deviceId])?$this->connectors['mystrom'][$deviceId]['minOffTime']:15;
                if ($diff > $minOffTime) {
                    return true;
                }
            }
        }

        return false;
    }

    private function getStatus($device)
    {
        if (array_key_exists('type', $device) && $device['type'] == 'motion') {
            $r = $this->queryMyStrom($device, 'motion');
            $arrKey = 'motion';
        } else {
            $r = $this->queryMyStrom($device, 'status');
            $arrKey = 'relay';
        }
        if (!empty($r) && array_key_exists($arrKey, $r) && $r[$arrKey] == true) {
            return $this->createStatus(1);
        } else {
            return $this->createStatus(0);
        }
    }

    private function createStatus($status)
    {
        if ($status) {
            return [
                'label' => 'label.device.status.on',
                'val' => 1,
            ];
        } else {
            return [
                'label' => 'label.device.status.off',
                'val' => 0,
            ];
        }
    }

    private function setOn($device)
    {
        $r = $this->queryMyStrom($device, 'on');
        if (!empty($r)) {
            return true;
        } else {
            return false;
        }
    }

    private function setOff($device)
    {
        $r = $this->queryMyStrom($device, 'off');
        if (!empty($r)) {
            return true;
        } else {
            return false;
        }
    }

    private function activatePIR($device)
    {
        $url = '/api/v1/action/pir/generic';
        $payload = 'get://'.$this->host.$this->session_cookie_path.'trigger/'.$device['ip'];
        $r = $this->postMyStrom($device, $url, $payload);
        if (!empty($r)) {
            return true;
        } else {
            return false;
        }
    }

    private function queryMyStrom($device, $cmd)
    {
        switch ($cmd) {
            case 'status':
                $reqUrl = 'report';
                break;
            case 'motion':
                $reqUrl = 'api/v1/motion';
                break;
            case 'on':
                $reqUrl = 'relay?state=1';
                break;
            case 'off':
                $reqUrl =  'relay?state=0';
                break;
            default:
                $reqUrl = '';
        }
    
        $url = 'http://' . $device['ip'] . '/' . $reqUrl;
        try {
            $response = $this->browser->get($url);
            $statusCode = $response->getStatusCode();
            if ($statusCode != 200) {
                return false;
            }
            $json = $response->getContent();

            return json_decode($json, true);
        } catch (\Exception $e) {
            return false;
        }
    }

    private function postMyStrom($device, $reqUrl, $payload)
    {
        $url = 'http://' . $device['ip'] . '/' . $reqUrl;
        $headers = [];
        $response = $this->browser->post($url, $headers, http_build_query([$payload]));
        try {
            $response = $this->browser->post($url, $headers, $payload);
            $statusCode = $response->getStatusCode();
            if ($statusCode != 200) {
                return false;
            }
            $json = $response->getContent();

            return json_decode($json, true);
        } catch (\Exception $e) {
            return false;
        }
    }

    public function getConfig($ip)
    {
        foreach ($this->connectors['mystrom'] as $device) {
            if ($device['ip'] == $ip) {
                return $device;
            }
        }
        return null;
    }
}
