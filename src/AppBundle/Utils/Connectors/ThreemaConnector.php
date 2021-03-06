<?php

namespace AppBundle\Utils\Connectors;

use Doctrine\ORM\EntityManager;

/**
 *
 * @author Markus Schafroth
 */
class ThreemaConnector
{
    protected $em;
    protected $browser;
    protected $connectors;

    public function __construct(EntityManager $em, \Buzz\Browser $browser, Array $connectors)
    {
        $this->em = $em;
        $this->browser = $browser;
        $this->config = $connectors['threema'];
        $this->apiSendSimple = 'https://msgapi.threema.ch/send_simple';
    }

    public function sendMessage($email, $msg)
    {
        $payload = [
            'from' => $this->config['id'],
            'email' => $email,
            'text' => $msg,
            'secret' => $this->config['secret'],
        ];

        $headers = [
            'Content-Type' => 'application/x-www-form-urlencoded',
        ];

        $response = $this->browser->post($this->apiSendSimple, $headers, http_build_query($payload));
    }
}
