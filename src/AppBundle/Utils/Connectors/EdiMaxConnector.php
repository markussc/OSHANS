<?php

namespace AppBundle\Utils\Connectors;

use Doctrine\ORM\EntityManager;

/**
 * Connector to retrieve data from EdiMax devices
 * For information refer to www.edimax.com
 *
 * @author Markus Schafroth
 */
class EdiMaxConnector
{
    protected $em;
    protected $browser;
    protected $connectors;

    public function __construct(EntityManager $em, \Buzz\Browser $browser, Array $connectors)
    {
        $this->em = $em;
        $this->browser = $browser;
        $this->connectors = $connectors;
        // set timeout for buzz browser client
        $this->browser->getClient()->setTimeout(3);
    }

    /**
     * Reads the latest available data from the database
     * @return array
     */
    public function getAllLatest()
    {
        $results = [];
        foreach ($this->connectors['edimax'] as $device) {
            $results[] = [
                'ip' => $device['ip'],
                'name' => $device['name'],
                'status' => $this->createStatus($this->em->getRepository('AppBundle:EdiMaxDataStore')->getLatest($device['ip'])),
                'nominalPower' => $device['nominalPower'],
            ];
        }
        return $results;
    }

    public function getAll()
    {
        $results = [];
        foreach ($this->connectors['edimax'] as $device) {
            $status = $this->getStatus($device);
            $results[] = [
                'ip' => $device['ip'],
                'name' => $device['name'],
                'status' => $status,
            ];
        }
        return $results;
    }

    public function executeCommand($deviceId, $command)
    {
        switch ($command) {
            case 1:
                // turn it on
                return $this->setOn($this->connectors['edimax'][$deviceId]);
            case 0:
                // turn it off
                return $this->setOff($this->connectors['edimax'][$deviceId]);
        }
        // no known command
        return false;
    }

    public function switchOK($deviceId)
    {
        // get current status
        $currentStatus = $this->getStatus($this->connectors['edimax'][$deviceId])['val'];

        // get latest timestamp with opposite status
        $oldStatus = $this->em->getRepository('AppBundle:EdiMaxDataStore')->getLatest($this->connectors['edimax'][$deviceId]['ip'], ($currentStatus + 1)%2);
        if (count($oldStatus) == 1) {
            $oldTimestamp = $oldStatus[0]->getTimestamp();

            // calculate time diff
            $now = new \DateTime('now');
            $diff = $oldTimestamp->diff($now)->format('%i');
            if ($diff > 15) {
                return true;
            }
        }

        return false;
    }

    private function getStatus($device)
    {
        $r = $this->queryEdiMax($device, 'status');
        if (!empty($r) && array_key_exists('CMD', $r) && array_key_exists('Device.System.Power.State', $r['CMD']) && $r['CMD']['Device.System.Power.State'] == 'ON') {
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
        $r = $this->queryEdiMax($device, 'on');
        if (!empty($r) AND array_key_exists('CMD', $r) AND $r['CMD'] == 'OK') {
            return true;
        } else {
            return false;
        }
    }

    private function setOff($device)
    {
        $r = $this->queryEdiMax($device, 'off');
        if (!empty($r) AND array_key_exists('CMD', $r) AND $r['CMD'] == 'OK') {
            return true;
        } else {
            return false;
        }
    }

    private function queryEdiMax($device, $cmd)
    {
        switch ($cmd) {
            case 'status':
                $xmlRequest = '<?xml version="1.0" encoding="UTF8"?><SMARTPLUG id="edimax"><CMD id="get"><Device.System.Power.State></Device.System.Power.State></CMD></SMARTPLUG>';
                break;
            case 'on':
                $xmlRequest = '<?xml version="1.0" encoding="utf-8"?><SMARTPLUG id="edimax"><CMD id="setup"><Device.System.Power.State>ON</Device.System.Power.State></CMD></SMARTPLUG>';
                break;
            case 'off';
                $xmlRequest =  '<?xml version="1.0" encoding="utf-8"?><SMARTPLUG id="edimax"><CMD id="setup"><Device.System.Power.State>OFF</Device.System.Power.State></CMD></SMARTPLUG>';
                break;
            default:
                $xmlRequest = '';
        }
        $data =  [
            'xmlRequest' => $xmlRequest,
        ];
        
        $headers = [
            'Content-Type' => 'application/xml',
            'Content-Length' => strlen($data['xmlRequest'])
        ];

        $this->browser->setListener(new \Buzz\Listener\DigestAuthListener($device['username'], $device['password']));
        $url = 'http://' . $device['ip'] . ':10000/smartplug.cgi';
        try {
            $response = $this->browser->post($url, $headers, 'xmlRequest='.$data['xmlRequest']);

            $statusCode = $response->getStatusCode();
            if ($statusCode == 401) {
                $responseXml = $this->browser->post($url, $headers, 'xmlRequest='.$data['xmlRequest'])->getContent();
            } else {
                $responseXml = $response->getContent();
            }

            $ob = simplexml_load_string($responseXml);
            $json  = json_encode($ob);
            return json_decode($json, true);
        } catch (\Exception $e) {
            return false;
        }
    }

    public function getConfig($ip)
    {
        foreach ($this->connectors['edimax'] as $device) {
            if ($device['ip'] == $ip) {
                return $device;
            }
        }
        return null;
    }
}
