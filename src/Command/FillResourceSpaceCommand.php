<?php

namespace App\Command;



use App\ResourceSpace\ResourceSpace;
use App\Utils\StringUtil;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class FillResourceSpaceCommand extends ContainerAwareCommand
{
    private $datahubUrl;
    private $resourceSpace;

    protected function configure()
    {
        $this
            ->setName('app:fill-resourcespace')
            ->addArgument('csv', InputArgument::REQUIRED, 'The CSV file containing the information to put in ResourceSpace')
            ->addArgument('url', InputArgument::OPTIONAL, 'The URL of the Datahub')
            ->setDescription('')
            ->setHelp('');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->datahubUrl = $input->getArgument('url');
        if(!$this->datahubUrl) {
            $this->datahubUrl = $this->getContainer()->getParameter('datahub_url');
        }

        $csvFile = $input->getArgument('csv');

        $this->resourceSpace = new ResourceSpace($this->getContainer());

        $csvData = $this->readRecordIdsFromCsv($csvFile);

        $resourceSpaceIds = $this->resourceSpace->getResourceSpaceIds();

        foreach($csvData as $csvLine) {
            $filename = StringUtil::stripExtension($csvLine['originalfilename']);
            if(array_key_exists($filename, $resourceSpaceIds)) {
                $id = $resourceSpaceIds[$filename];

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
                $line = array_combine($columns, $row);

                $csvData[] = $line;
                $i++;
            }
            fclose($handle);
        }

        return $csvData;
    }
}
