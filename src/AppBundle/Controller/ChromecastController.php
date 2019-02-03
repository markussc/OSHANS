<?php

namespace AppBundle\Controller;

use AppBundle\Utils\Connectors\ChromecastConnector;
use AppBundle\Utils\Connectors\EdiMaxConnector;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Chromecast controller.
 *
 * @Route("/cc")
 */
class ChromecastController extends Controller
{
    /**
     * @Route("/power/{ccId}/{power}", name="chromecast_power")
     */
    public function powerAction(EdiMaxConnector $edimax, $ccId, $power)
    {
        $em = $this->getDoctrine()->getManager();
        $chromecast = $this->getParameter('connectors')['chromecast'][$ccId];
        $ip = $chromecast['ip'];
        $settings = $em->getRepository('AppBundle:Settings')->findOneByConnectorId($ip);
        if (!$settings) {
            $settings = new Settings();
            $settings->setConnectorId($ip);
        }
        if ($power) {
            // turn on
            $settings->setMode(1);
            foreach ($chromecast['edimax'] as $edimaxId) {
                $edimax->executeCommand($edimaxId, 1);
            }
            // wait a few seconds until chromecast might be ready
            sleep(20);
        } else {
            // turn off
            $settings->setMode(0);
            $settings->setConfig([
                'url' => false,
                'state' => 'stopped',
            ]);
            foreach ($chromecast['edimax'] as $edimaxId) {
                $edimax->executeCommand($edimaxId, 0);
            }
        }
        $em->persist($settings);
        $em->flush();

        return new JsonResponse(['success' => true]);
    }

    /**
     * @Route("/play/{ccId}/{streamId}", name="chromecast_play")
     */
    public function playAction(ChromecastConnector $ccConnector, $ccId, $streamId)
    {
        $chromecast = $this->getParameter('connectors')['chromecast'][$ccId];
        $stream = $chromecast['streams'][$streamId];
        $success = $ccConnector->startStream($chromecast['ip'], $stream['url']);

        return new JsonResponse(['success' => $success]);
    }

    /**
     * @Route("/stop/{ccId}", name="chromecast_stop")
     */
    public function stopAction(ChromecastConnector $ccConnector, $ccId)
    {
        $chromecast = $this->getParameter('connectors')['chromecast'][$ccId];
        $success = $ccConnector->stopStream($chromecast['ip']);

        return new JsonResponse(['success' => $success]);
    }

    /**
     * @Route("/volume_up/{ccId}", name="chromecast_volume_up")
     */
    public function volumeUpAction(ChromecastConnector $ccConnector, $ccId)
    {
        $chromecast = $this->getParameter('connectors')['chromecast'][$ccId];
        $success = $ccConnector->volumeUp($chromecast['ip']);

        return new JsonResponse(['success' => $success]);
    }

    /**
     * @Route("/volume_down/{ccId}", name="chromecast_volume_down")
     */
    public function volumeDownAction(ChromecastConnector $ccConnector, $ccId)
    {
        $chromecast = $this->getParameter('connectors')['chromecast'][$ccId];
        $success = $ccConnector->volumeDown($chromecast['ip']);

        return new JsonResponse(['success' => $success]);
    }
}
