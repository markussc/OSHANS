<?php

namespace AppBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use AppBundle\Utils\LogicProcessor;

class ConfigureDeviceCommand extends ContainerAwareCommand
{
    // the name of the command (the part after "bin/console")
    protected static $defaultName = 'oshans:device:configure';

    protected function configure()
    {
        $this
            ->setDescription('Sends configuration data to a specific device')
            ->addArgument(
                'deviceId',
                InputArgument::REQUIRED,
                'deviceId is the id of the device according to the parameters.yml file (array index, 0-based)'
            )
        ;
    }

    /**
     * Sends configuration data to a device
     *
     * @param InputInterface $input
     * @param OutputInterface $output
     * @return boolean
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $deviceId = $input->getArgument('deviceId');
        $this->getContainer()->get(LogicProcessor::class)->configureDevice($deviceId);
    }
}
