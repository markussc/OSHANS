<?php

namespace AppBundle\Controller;

use AppBundle\Entity\Settings;
use AppBundle\Utils\LogicProcessor;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;

class DefaultController extends Controller
{
    /**
     * @Route("/", name="homepage")
     */
    public function indexAction(Request $request)
    {
        $clientIp = $request->getClientIp();
        $authenticatedIps = $this->getParameter('authenticated_ips');
        $params = $request->query->all();
        $securityContext = $this->container->get('security.authorization_checker');
        if (!$securityContext->isGranted('IS_AUTHENTICATED_REMEMBERED') && array_key_exists($clientIp, $authenticatedIps)) {
            $route = $request->get('route');
            $em = $this->getDoctrine()->getManager();
            $user = $em->getRepository('AppBundle:User')->findOneBy(array('email' => $authenticatedIps[$clientIp]));
            if ($user) {
                $token = new UsernamePasswordToken($user, $user->getPassword(), "xinstance", $user->getRoles());
                $this->get('security.token_storage')->setToken($token);
                if ($route) {
                    return $this->redirectToRoute($route, $params);
                }
            }
        }

        return $this->redirectToRoute('overview', $params);
    }

    /**
     * @Route("/overview", name="overview")
     */
    public function overviewAction(Request $request)
    {
        $activePage = "overview";
        $history = [];
        $currentStat = [];
        if ($request->query->get("details")) {
            $activePage = "details";
            $em = $this->getDoctrine()->getManager();
            // get current values
            if (array_key_exists('smartfox', $this->getParameter('connectors'))) {
                $currentStat['smartFox'] = $this->get('AppBundle\Utils\Connectors\SmartFoxConnector')->getAllLatest();
                $currentStat['smartFoxChart'] = true;
            }
            if (array_key_exists('pcoweb', $this->getParameter('connectors'))) {
                $currentStat['pcoWeb'] = $this->get('AppBundle\Utils\Connectors\PcoWebConnector')->getAllLatest();
            }
            if (array_key_exists('conexio', $this->getParameter('connectors'))) {
                $currentStat['conexio'] = $this->get('AppBundle\Utils\Connectors\ConexioConnector')->getAllLatest();
            }
            if (array_key_exists('mobilealerts', $this->getParameter('connectors'))) {
                $currentStat['mobileAlerts'] = $this->get('AppBundle\Utils\Connectors\MobileAlertsConnector')->getAllLatest();
            }
            if (array_key_exists('edimax', $this->getParameter('connectors'))) {
                $currentStat['edimax'] = $this->get('AppBundle\Utils\Connectors\EdiMaxConnector')->getAllLatest();
            }
            if (array_key_exists('mystrom', $this->getParameter('connectors'))) {
                $currentStat['mystrom'] = $this->get('AppBundle\Utils\Connectors\MyStromConnector')->getAllLatest();
            }
            if (array_key_exists('shelly', $this->getParameter('connectors'))) {
                $currentStat['shelly'] = $this->get('AppBundle\Utils\Connectors\ShellyConnector')->getAllLatest();
            }
            if (array_key_exists('openweathermap', $this->getParameter('connectors'))) {
                $currentStat['openweathermap'] = $this->get('AppBundle\Utils\Connectors\OpenWeatherMapConnector')->getAllLatest();
            }
            if (array_key_exists('logocontrol', $this->getParameter('connectors'))) {
                $currentStat['logoControl'] = $this->get('AppBundle\Utils\Connectors\LogoControlConnector')->getAllLatest();
            }

            // get history
            if (array_key_exists('mobilealerts', $this->getParameter('connectors')) && is_array($this->getParameter('connectors')['mobilealerts']['sensors'])) {
                $mobileAlertsHistory = [];
                foreach ($this->getParameter('connectors')['mobilealerts']['sensors'] as $sensorId => $mobileAlertsSensor) {
                    $mobileAlertsHistory[$sensorId] = $em->getRepository('AppBundle:MobileAlertsDataStore')->getHistoryLast24h($sensorId);
                }
                $history['mobileAlerts'] = $mobileAlertsHistory;
            }
            if (array_key_exists('smartfox', $this->getParameter('connectors'))) {
                $history['smartFox'] = $em->getRepository('AppBundle:SmartFoxDataStore')->getHistoryLast24h($this->get('AppBundle\Utils\Connectors\SmartFoxConnector')->getIp());
            }
            if (array_key_exists('pcoweb', $this->getParameter('connectors'))) {
                $history['pcoWeb'] = $em->getRepository('AppBundle:PcoWebDataStore')->getHistoryLast24h($this->get('AppBundle\Utils\Connectors\PcoWebConnector')->getIp());
            }
            if (array_key_exists('conexio', $this->getParameter('connectors'))) {
                $history['conexio'] = $em->getRepository('AppBundle:ConexioDataStore')->getHistoryLast24h($this->get('AppBundle\Utils\Connectors\ConexioConnector')->getIp());
            }
            if (array_key_exists('logocontrol', $this->getParameter('connectors'))) {
                $history['logoControl'] = $em->getRepository('AppBundle:LogoControlDataStore')->getHistoryLast24h($this->get('AppBundle\Utils\Connectors\LogoControlConnector')->getIp());
            }
        } else {
            $currentStat = $this->getCurrentStat([
                'edimax' => true,
                'mystrom' => true,
                'shelly' => true,
                'pcoweb' => true,
                'openweathermap' => true,
                'mobilealerts' => true,
            ]);
        }     

        // render the template
        return $this->render('default/index.html.twig', [
            'activePage' => $activePage,
            'currentStat' => $currentStat,
            'history' => $history,
        ]);
    }

    /**
     * Execute command
     * @Route("/cmd/{command}", name="command_exec")
     */
    public function commandExecuteAction(Request $request, $command)
    {
        // only owners are allowed to execute commands
        $this->denyAccessUnlessGranted('ROLE_OWNER');

        // execute the command
        $success = $this->executeCommand($command);

        return new JsonResponse(['success' => $success]);
    }

    private function executeCommand($jsonCommand)
    {
        $command = json_decode($jsonCommand);
        switch ($command[0]) {
            case 'edimax':
                return $this->get('AppBundle\Utils\Connectors\EdiMaxConnector')->executeCommand($command[1], $command[2]);
            case 'mystrom':
                return $this->get('AppBundle\Utils\Connectors\MyStromConnector')->executeCommand($command[1], $command[2]);
            case 'shelly':
                return $this->get('AppBundle\Utils\Connectors\ShellyConnector')->executeCommand($command[1], $command[2]);
            case 'pcoweb':
                return $this->get('AppBundle\Utils\Connectors\PcoWebConnector')->executeCommand($command[1], $command[2]);
            case 'settings':
                if ($command[1] == 'mode') {
                    if ($command[2] == 'pcoweb') {
                        $connectorId = $this->get('AppBundle\Utils\Connectors\PcoWebConnector')->getIp();
                    } else {
                        $connectorId = $command[2];
                    }
                    if ($connectorId == 'alarm')
                    {
                        // make sure all mystrom PIR devices have their action URL set correctly
                        $this->get('AppBundle\Utils\Connectors\MyStromConnector')->activateAllPIR();
                    }
                    $settings = $this->getDoctrine()->getManager()->getRepository('AppBundle:Settings')->findOneByConnectorId($connectorId);
                    if (!$settings) {
                        $settings = new Settings();
                        $settings->setConnectorId($connectorId);
                        $this->getDoctrine()->getManager()->persist($settings);
                    }
                    $settings->setMode($command[3]);

                    $this->getDoctrine()->getManager()->flush();
                    return true;
                }
            case 'command':
                $connectors = $this->getParameter('connectors');
                exec($connectors['command'][$command[1]]['cmd']);
                return true;
        }

        // no known device
        return false;
    }

    /**
     * Get data which needs regular refresh
     * @Route("/refresh", name="refresh")
     */
    public function refreshAction(Request $request)
    {
        $currentStat = [];

        $template = "default/contentHomepage.html.twig";
        if ($request->query->get("details")) {
            $template = "default/contentDetails.html.twig";
            $currentStat = $this->getCurrentStat(true);
        } else {
            $currentStat = $this->getCurrentStat([
                'edimax' => true,
                'mystrom' => true,
                'shelly' => true,
                'openweathermap' => true,
                'mobilealerts' => true,
            ]);
        }
        
        // render the template
        return $this->render($template, [
            'currentStat' => $currentStat,
            'refresh' => true,
        ]);
    }

    /**
     * @Route("/history", name="history")
     */
    public function historyAction(Request $request)
    {
        $em = $this->getDoctrine()->getManager();
        $yesterday = new \DateTime('yesterday');
        $now = new \DateTime('now');
        $today = new \DateTime('today');
        $thisWeek = new \DateTime('monday this week midnight');
        $thisMonth = new \DateTime('first day of this month midnight');
        $lastYearMonth = new \DateTime('first day of this month midnight');
        $lastYearMonth = $lastYearMonth->sub(new \DateInterval('P1Y'));
        $thisYear = new \DateTime('first day of january this year midnight');
        $lastYearPart = new \DateTime();
        $lastYearPart = $lastYearPart->sub(new \DateInterval('P1Y'));
        $lastYear = new \DateTime('first day of january last year midnight');

        $history['intervals'] = [
            'today',
            'yesterday',
            'week',
            'month',
            'lastYearMonth',
            'year',
            'lastYearPart',
            'lastYear',
        ];
        if (array_key_exists('smartfox', $this->getParameter('connectors'))) {
            $history['smartfox'] = [
                'pv_today' => $em->getRepository('AppBundle:SmartFoxDataStore')->getEnergyInterval($this->get('AppBundle\Utils\Connectors\SmartFoxConnector')->getIp(), 'PvEnergy', $today, $now),
                'pv_yesterday' => $em->getRepository('AppBundle:SmartFoxDataStore')->getEnergyInterval($this->get('AppBundle\Utils\Connectors\SmartFoxConnector')->getIp(), 'PvEnergy', $yesterday, $today),
                'pv_week' => $em->getRepository('AppBundle:SmartFoxDataStore')->getEnergyInterval($this->get('AppBundle\Utils\Connectors\SmartFoxConnector')->getIp(), 'PvEnergy', $thisWeek, $now),
                'pv_month' => $em->getRepository('AppBundle:SmartFoxDataStore')->getEnergyInterval($this->get('AppBundle\Utils\Connectors\SmartFoxConnector')->getIp(), 'PvEnergy', $thisMonth, $now),
                'pv_lastYearMonth' => $em->getRepository('AppBundle:SmartFoxDataStore')->getEnergyInterval($this->get('AppBundle\Utils\Connectors\SmartFoxConnector')->getIp(), 'PvEnergy', $lastYearMonth, $lastYearPart),
                'pv_year' => $em->getRepository('AppBundle:SmartFoxDataStore')->getEnergyInterval($this->get('AppBundle\Utils\Connectors\SmartFoxConnector')->getIp(), 'PvEnergy', $thisYear, $now),
                'pv_lastYearPart' => $em->getRepository('AppBundle:SmartFoxDataStore')->getEnergyInterval($this->get('AppBundle\Utils\Connectors\SmartFoxConnector')->getIp(), 'PvEnergy', $lastYear, $lastYearPart),
                'pv_lastYear' => $em->getRepository('AppBundle:SmartFoxDataStore')->getEnergyInterval($this->get('AppBundle\Utils\Connectors\SmartFoxConnector')->getIp(), 'PvEnergy', $lastYear, $thisYear),
                'energy_in_today' => $em->getRepository('AppBundle:SmartFoxDataStore')->getEnergyInterval($this->get('AppBundle\Utils\Connectors\SmartFoxConnector')->getIp(), 'energy_in', $today, $now),
                'energy_out_today' => $em->getRepository('AppBundle:SmartFoxDataStore')->getEnergyInterval($this->get('AppBundle\Utils\Connectors\SmartFoxConnector')->getIp(), 'energy_out', $today, $now),
                'energy_in_yesterday' => $em->getRepository('AppBundle:SmartFoxDataStore')->getEnergyInterval($this->get('AppBundle\Utils\Connectors\SmartFoxConnector')->getIp(), 'energy_in', $yesterday, $today),
                'energy_out_yesterday' => $em->getRepository('AppBundle:SmartFoxDataStore')->getEnergyInterval($this->get('AppBundle\Utils\Connectors\SmartFoxConnector')->getIp(), 'energy_out', $yesterday, $today),
                'energy_in_week' => $em->getRepository('AppBundle:SmartFoxDataStore')->getEnergyInterval($this->get('AppBundle\Utils\Connectors\SmartFoxConnector')->getIp(), 'energy_in', $thisWeek, $now),
                'energy_out_week' => $em->getRepository('AppBundle:SmartFoxDataStore')->getEnergyInterval($this->get('AppBundle\Utils\Connectors\SmartFoxConnector')->getIp(), 'energy_out', $thisWeek, $now),
                'energy_in_month' => $em->getRepository('AppBundle:SmartFoxDataStore')->getEnergyInterval($this->get('AppBundle\Utils\Connectors\SmartFoxConnector')->getIp(), 'energy_in', $thisMonth, $now),
                'energy_out_month' => $em->getRepository('AppBundle:SmartFoxDataStore')->getEnergyInterval($this->get('AppBundle\Utils\Connectors\SmartFoxConnector')->getIp(), 'energy_out', $thisMonth, $now),
                'energy_in_lastYearMonth' => $em->getRepository('AppBundle:SmartFoxDataStore')->getEnergyInterval($this->get('AppBundle\Utils\Connectors\SmartFoxConnector')->getIp(), 'energy_in', $lastYearMonth, $lastYearPart),
                'energy_out_lastYearMonth' => $em->getRepository('AppBundle:SmartFoxDataStore')->getEnergyInterval($this->get('AppBundle\Utils\Connectors\SmartFoxConnector')->getIp(), 'energy_out', $lastYearMonth, $lastYearPart),
                'energy_in_year' => $em->getRepository('AppBundle:SmartFoxDataStore')->getEnergyInterval($this->get('AppBundle\Utils\Connectors\SmartFoxConnector')->getIp(), 'energy_in', $thisYear, $now),
                'energy_out_year' => $em->getRepository('AppBundle:SmartFoxDataStore')->getEnergyInterval($this->get('AppBundle\Utils\Connectors\SmartFoxConnector')->getIp(), 'energy_out', $thisYear, $now),
                'energy_in_lastYearPart' => $em->getRepository('AppBundle:SmartFoxDataStore')->getEnergyInterval($this->get('AppBundle\Utils\Connectors\SmartFoxConnector')->getIp(), 'energy_in', $lastYear, $lastYearPart),
                'energy_out_lastYearPart' => $em->getRepository('AppBundle:SmartFoxDataStore')->getEnergyInterval($this->get('AppBundle\Utils\Connectors\SmartFoxConnector')->getIp(), 'energy_out', $lastYear, $lastYearPart),
                'energy_in_lastYear' => $em->getRepository('AppBundle:SmartFoxDataStore')->getEnergyInterval($this->get('AppBundle\Utils\Connectors\SmartFoxConnector')->getIp(), 'energy_in', $lastYear, $thisYear),
                'energy_out_lastYear' => $em->getRepository('AppBundle:SmartFoxDataStore')->getEnergyInterval($this->get('AppBundle\Utils\Connectors\SmartFoxConnector')->getIp(), 'energy_out', $lastYear, $thisYear),
            ];
        }
        if (array_key_exists('conexio', $this->getParameter('connectors'))) {
            $history['conexio'] = [
                'energy_today' => $em->getRepository('AppBundle:ConexioDataStore')->getEnergyInterval($this->get('AppBundle\Utils\Connectors\ConexioConnector')->getIp(), $today, $now),
                'energy_yesterday' => $em->getRepository('AppBundle:ConexioDataStore')->getEnergyInterval($this->get('AppBundle\Utils\Connectors\ConexioConnector')->getIp(), $yesterday, $today),
                'energy_week' => $em->getRepository('AppBundle:ConexioDataStore')->getEnergyInterval($this->get('AppBundle\Utils\Connectors\ConexioConnector')->getIp(), $thisWeek, $now),
                'energy_month' => $em->getRepository('AppBundle:ConexioDataStore')->getEnergyInterval($this->get('AppBundle\Utils\Connectors\ConexioConnector')->getIp(), $thisMonth, $now),
                'energy_lastYearMonth' => $em->getRepository('AppBundle:ConexioDataStore')->getEnergyInterval($this->get('AppBundle\Utils\Connectors\ConexioConnector')->getIp(), $lastYearMonth, $lastYearPart),
                'energy_year' => $em->getRepository('AppBundle:ConexioDataStore')->getEnergyInterval($this->get('AppBundle\Utils\Connectors\ConexioConnector')->getIp(), $thisYear, $now),
                'energy_lastYearPart' => $em->getRepository('AppBundle:ConexioDataStore')->getEnergyInterval($this->get('AppBundle\Utils\Connectors\ConexioConnector')->getIp(), $lastYear, $lastYearPart),
                'energy_lastYear' => $em->getRepository('AppBundle:ConexioDataStore')->getEnergyInterval($this->get('AppBundle\Utils\Connectors\ConexioConnector')->getIp(), $lastYear, $thisYear),
            ];
        }

        // render the template
        return $this->render('default/history.html.twig', [
            'activePage' => 'history',
            'history' => $history,
        ]);
    }

    /**
     * Create the visual dashboard
     * @Route("/visualdashboard", name="visual_dashboard")
     */
    public function visualDashboardAction(Request $request)
    {
        $currentStat = $this->getCurrentStat([
            'smartfox' => true,
            'mobilealerts' => true,
            'pcoweb' => true,
            'conexio' => true,
            'logocontrol' => true,
        ]);

        $fileContent = file_get_contents($this->getParameter('kernel.project_dir').'/web/visual_dashboard.svg');

        $maValues = [];
        foreach ($currentStat['mobileAlerts'] as $maDevice) {
            if (is_array($maDevice)) {
                foreach ($maDevice as $maSensor) {
                    if (array_key_exists('usage', $maSensor) && $maSensor['usage'] !== false) {
                        $maValues[$maSensor['usage']] = $maSensor['value'];
                    }
                }
            }
        }

        // get values
        if (isset($currentStat['smartFox'])) {
            $pvpower = $currentStat['smartFox']['PvPower'][0]." W";
            $netpower = $currentStat['smartFox']['power_io']." W";
            $intpower = ($currentStat['smartFox']['power_io'] + $currentStat['smartFox']['PvPower'][0])." W";
        } else {
            $pvpower = "";
            $netpower = "";
            $intpower = "";
        }

        if (isset($currentStat['conexio'])) {
            $solpower = $currentStat['conexio']['p']." W";
            $soltemp = $currentStat['conexio']['s1']." °C";
            $hightemp = $currentStat['conexio']['s3']." °C";
            $lowtemp = $currentStat['conexio']['s2']." °C";
        } elseif (isset($currentStat['logoControl'])) {
            $solpower = $currentStat['logoControl'][$this->getParameter('connectors')['logocontrol']['powerSensor']] . " °C";
            $soltemp = $currentStat['logoControl'][$this->getParameter('connectors')['logocontrol']['collectorSensor']] . " °C";
            $hightemp = $currentStat['logoControl'][$this->getParameter('connectors')['logocontrol']['heatStorageSensor']] . " °C";
            $lowtemp = "";
        } else {
            $solpower = "";
            $soltemp = "";
            $hightemp = "";
            $lowtemp = "";
        }

        $insidetemp = "";
        $firstfloortemp = "";
        $secondfloortemp = "";
        $insidehumidity = "";
        $basementtemp = "";
        $basementhumidity = "";
        if (isset($currentStat['mobileAlerts'])) {
            if (isset($maValues['insidetemp'])) {
                $insidetemp = $maValues['insidetemp'] . " °C";
            }
            if (isset($maValues['firstfloortemp'])) {
                $firstfloortemp = $maValues['firstfloortemp'] . " °C";
            }
            if (isset($maValues['secondfloortemp'])) {
                $secondfloortemp = $maValues['secondfloortemp']. " °C";
            }
            if (isset($maValues['insidehumidity'])) {
                $insidehumidity = $maValues['insidehumidity'] . " %";
            }
            if (isset($maValues['basementtemp'])) {
                $basementtemp = $maValues['basementtemp'] . " °C";
            }
            if (isset($maValues['basementhumidity'])) {
                $basementhumidity = $maValues['basementhumidity'] . " %";
            }
        }
        if($currentStat['pcoWeb']['cpStatus'] === 'label.device.status.on') {
            $effDistrTemp = $currentStat['pcoWeb']['effDistrTemp']." °C";
        } else {
            $effDistrTemp = $this->get('translator')->trans($currentStat['pcoWeb']['cpStatus']);
        }

        if ($currentStat['pcoWeb']['ppStatus'] === "label.device.status.on") {
            $sourceintemp = $currentStat['pcoWeb']['ppSourceIn']." °C";
            $sourceouttemp = $currentStat['pcoWeb']['ppSourceOut']." °C";
        } else {
            $sourceintemp = "";
            $sourceouttemp = "";
        }
        // write current values into the svg
        $labels = [
            "pvpower",
            "netpower",
            "intpower",
            "solpower",
            "soltemp",
            "outsidetemp",
            "watertemp",
            "ppstatus",
            "effdistrtemp",
            "insidetemp",
            "firstfloortemp",
            "secondfloortemp",
            "insidehumidity",
            "basementtemp",
            "basementhumidity",
            "hightemp",
            "midtemp",
            "lowtemp",
            "sourceintemp",
            "sourceouttemp"
        ];
        $values = [
            $pvpower,
            $netpower,
            $intpower,
            $solpower,
            $soltemp,
            $currentStat['pcoWeb']['outsideTemp']." °C",
            $currentStat['pcoWeb']['waterTemp']." °C",
            $this->get('translator')->trans($currentStat['pcoWeb']['ppStatus']),
            $effDistrTemp,
            $insidetemp,
            $firstfloortemp,
            $secondfloortemp,
            $insidehumidity,
            $basementtemp,
            $basementhumidity,
            $hightemp,
            $currentStat['pcoWeb']['storTemp']." °C",
            $lowtemp,
            $sourceintemp,
            $sourceouttemp,
        ];

        $fileContent = str_replace($labels, $values, $fileContent);

        // Return a response with a specific content
        $response = new Response($fileContent);

        // Create the disposition of the file
        $disposition = $response->headers->makeDisposition(
            ResponseHeaderBag::DISPOSITION_ATTACHMENT,
            'dashboard.svg'
        );

        // Set the content disposition
        $response->headers->set('Content-Disposition', $disposition);

        $response->headers->set('Content-Type', 'image/svg+xml');

        // Dispatch request
        return $response;
    }

    private function getCurrentStat($fullSet = true)
    {
        $currentStat = [];
        if (($fullSet === true || isset($fullSet['smartfox'])) && array_key_exists('smartfox', $this->getParameter('connectors'))) {
            $currentStat['smartFox'] = $this->get('AppBundle\Utils\Connectors\SmartFoxConnector')->getAll();
        }
        if (($fullSet === true || isset($fullSet['pcoweb'])) && array_key_exists('pcoweb', $this->getParameter('connectors'))) {
            $currentStat['pcoWeb'] = $this->get('AppBundle\Utils\Connectors\PcoWebConnector')->getAllLatest();
        }
        if (($fullSet === true || isset($fullSet['conexio'])) && array_key_exists('conexio', $this->getParameter('connectors'))) {
            $currentStat['conexio'] = $this->get('AppBundle\Utils\Connectors\ConexioConnector')->getAll(true);
        }
        if (($fullSet === true || isset($fullSet['edimax'])) && array_key_exists('edimax', $this->getParameter('connectors'))) {
            $currentStat['edimax'] = $this->get('AppBundle\Utils\Connectors\EdiMaxConnector')->getAll();
        }
        if (($fullSet === true || isset($fullSet['mystrom'])) && array_key_exists('mystrom', $this->getParameter('connectors'))) {
            $currentStat['mystrom'] = $this->get('AppBundle\Utils\Connectors\MyStromConnector')->getAll();
        }
        if (($fullSet === true || isset($fullSet['shelly'])) && array_key_exists('shelly', $this->getParameter('connectors'))) {
            $currentStat['shelly'] = $this->get('AppBundle\Utils\Connectors\ShellyConnector')->getAll();
        }
        if (($fullSet === true || isset($fullSet['mobilealerts'])) && array_key_exists('mobilealerts', $this->getParameter('connectors'))) {
            $currentStat['mobileAlerts'] = $this->get('AppBundle\Utils\Connectors\MobileAlertsConnector')->getAllLatest();
        }
        if (($fullSet === true || isset($fullSet['openweathermap'])) && array_key_exists('openweathermap', $this->getParameter('connectors'))) {
            $currentStat['openweathermap'] = $this->get('AppBundle\Utils\Connectors\OpenWeatherMapConnector')->getAllLatest();
        }
        if (($fullSet === true || isset($fullSet['logocontrol'])) && array_key_exists('logocontrol', $this->getParameter('connectors'))) {
            $currentStat['logoControl'] = $this->get('AppBundle\Utils\Connectors\LogoControlConnector')->getAll(true);
        }

        return $currentStat;
    }

    /**
     * trigger by external event
     * @Route("/trigger/{deviceId}/{action}", defaults={"deviceId"=null, "action"=null}, name="trigger")
     */
    public function triggerAction(Request $request, LogicProcessor $logic, $deviceId, $action)
    {
        // init the mystrom device if reasonable
        $logic->initMystrom($deviceId);

        // init the shelly device if reasonable
        $logic->initShelly($deviceId, $action);

        // execute auto actions where reasonable
        $logic->autoActionsEdimax();
        $logic->autoActionsMystrom();
        $logic->autoActionsShelly();

        // trigger the alarms
        $logic->processAlarms();

        return new JsonResponse(['success' => true]);
    }

    /**
     * interface for external variable requests
     * currently only supports requests for netPower
     * @Route("/stat/{variable}", name="stat")
     */
    public function statAction(Request $request, $variable)
    {
        $value = null;
        if ($variable === 'netPower' && $this->get('AppBundle\Utils\Connectors\SmartFoxConnector')->getIp()) {
            $smartFox = $this->get('AppBundle\Utils\Connectors\SmartFoxConnector')->getAll();
            $value = $smartFox['power_io'];
        }

        return new JsonResponse($value);
    }
}
