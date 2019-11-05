<?php
/**
 * Created by PhpStorm.
 * User: root
 * Date: 11/4/19
 * Time: 3:39 PM
 */

namespace App\Command;


use App\ResourceSpace\ResourceSpace;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class TestCommand extends ContainerAwareCommand
{

    protected function configure()
    {
        $this
            ->setName('app:test-resourcespace')
            ->setDescription('')
            ->setHelp('');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $resourceSpace = new ResourceSpace($this->getContainer());
        $resourceSpace->updateField(332, 'creditline', ' ');
//        $resourceSpace->updateField(332, 'clearedforusage', 'Internal use only,Academic use,Use for museums and other institutions,Public use');
    }
}