<?php

namespace App\Command;

use App\ResourceSpace\ResourceSpace;
use App\Utils\StringUtil;
use Exception;
use Phpoaipmh\Endpoint;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class CsvToResourceSpaceCommand extends ContainerAwareCommand
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

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $csvFile = $input->getArgument('csv');

        $this->resourceSpace = new ResourceSpace($this->getContainer());

        $csvData = $this->readRecordIdsFromCsv($csvFile);

        $resourceSpaceFilenames = $this->resourceSpace->getAllOriginalFilenames();

        $datahubUrl = $this->getContainer()->getParameter('datahub_url');
        $metadataPrefix = $this->getContainer()->getParameter('datahub_metadataprefix');
        $datahubDatapidPrefix = $this->getContainer()->getParameter('datahub_record_id_prefix');
        $datahubEndpoint = null;

        foreach($csvData as $csvLine) {

            try {
                if (!$datahubEndpoint)
                    $datahubEndpoint = Endpoint::build($datahubUrl . '/oai');

                $datahubEndpoint->getRecord($datahubDatapidPrefix . StringUtil::cleanObjectNumber($csvLine['sourceinvnr']), $metadataPrefix);
            }catch(Exception $exception) {
                echo $exception . PHP_EOL;
            }


            if(true)
            continue;

            $filename = StringUtil::stripExtension($csvLine['originalfilename']);
            if(!array_key_exists($filename, $resourceSpaceFilenames)) {
                echo 'Error: could not find any resources for file ' . $filename . PHP_EOL;
                continue;
            }

            $id = $resourceSpaceFilenames[$filename];

            foreach($csvLine as $key => $value) {
                if($value != 'NULL') {
                    // Combine start date and end into one date
                    // TODO clean up the CSV so we don't need to do this anymore
                    if ($key == 'datecreatedofartwork-start') {
                        if ($value != '0') {
                            $this->resourceSpace->updateField($id, 'datecreatedofartwork', $value . '-01-01, ' . $csvLine['datecreatedofartwork-end'] . '-12-31');
                        }
                    } else if ($key != 'originalfilename' && $key != 'datecreatedofartwork-end') {
                        $this->resourceSpace->updateField($id, $key, $value);
                    }
                }
            }
        }
    }

    private function readRecordIdsFromCsv($csvFile)
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
