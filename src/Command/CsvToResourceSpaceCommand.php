<?php

namespace App\Command;

use App\ResourceSpace\ResourceSpace;
use App\Utils\StringUtil;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class CsvToResourceSpaceCommand extends Command implements ContainerAwareInterface
{
    private $resourceSpace;

    protected function configure()
    {
        $this
            ->setName('app:csv-to-resourcespace')
            ->addArgument('csv', InputArgument::REQUIRED, 'The CSV file containing the information to put in ResourceSpace')
            ->setDescription('')
            ->setHelp('');
    }

    public function setContainer(ContainerInterface $container = null)
    {
        $this->container = $container;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $csvFile = $input->getArgument('csv');

        $this->resourceSpace = new ResourceSpace($this->container);

        $csvData = $this->readRecordsFromCsv($csvFile);

        $resourceSpaceFilenames = $this->resourceSpace->getAllOriginalFilenames();

        foreach($csvData as $csvLine) {

            $filename = StringUtil::stripExtension($csvLine['originalfilename']);
            if(!array_key_exists($filename, $resourceSpaceFilenames)) {
                echo 'Error: could not find any resources for file ' . $filename . PHP_EOL;
                continue;
            }

            $id = $resourceSpaceFilenames[$filename];

            foreach($csvLine as $key => $value) {
                if($value != 'NULL') {
                    if ($key != 'originalfilename') {
                        $this->resourceSpace->updateField($id, $key, $value);
                    }
                }
            }
        }
    }

    private function readRecordsFromCsv($csvFile)
    {
        $csvData = array();
        if (($handle = fopen($csvFile, "r")) !== false) {
            $columns = fgetcsv($handle, 1000, ";");
            $i = 0;
            while (($row = fgetcsv($handle, 1000, ";")) !== false) {
                if(count($columns) != count($row)) {
                    echo 'Wrong column count: should be ' . count($columns) . ', is ' . count($row) . ' at row ' . $i;
//                    $this->logger->error('Wrong column count: should be ' . count($columns) . ', is ' . count($row) . ' at row ' . $i);
                }
                //TODO trim headers
                $line = array_combine($columns, $row);

                $csvData[] = $line;
                $i++;
            }
            fclose($handle);
        }

        return $csvData;
    }
}
