<?php

namespace App\Command;

use App\ResourceSpace\ResourceSpace;
use DOMDocument;
use DOMXPath;
use Phpoaipmh\Endpoint;
use Phpoaipmh\Exception\HttpException;
use Phpoaipmh\Exception\OaipmhException;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class DatahubToResourceSpaceCommand extends ContainerAwareCommand
{
    private $datahubDatapidPrefix;

    private $datahubUrl;
    private $datahubLanguage;
    private $namespace;
    private $metadataPrefix;
    private $dataDefinition;

    private $datahubEndpoint;
    private $verbose;

    private $resourceSpace;

    private $resourceSpaceData;
    private $datahubData;
    private $resourceIds;
    private $relations;

    protected function configure()
    {
        $this
            ->setName('app:datahub-to-resourcespace')
            ->addArgument('url', InputArgument::OPTIONAL, 'The URL of the Datahub')
            ->setDescription('')
            ->setHelp('');
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->verbose = $input->getOption('verbose');

        $this->datahubUrl = $input->getArgument('url');
        if (!$this->datahubUrl) {
            $this->datahubUrl = $this->getContainer()->getParameter('datahub_url');
        }

        $this->datahubDatapidPrefix = $this->getContainer()->getParameter('datahub_datapid_prefix');

        $this->datahubLanguage = $this->getContainer()->getParameter('datahub_language');
        $this->namespace = $this->getContainer()->getParameter('datahub_namespace');
        $this->metadataPrefix = $this->getContainer()->getParameter('datahub_metadataprefix');
        $this->dataDefinition = $this->getContainer()->getParameter('datahub_data_definition');


        // Make sure the API URL does not end with a '?' character
        $this->resourceSpace = new ResourceSpace($this->getContainer());

        $this->resourceSpaceData = $this->resourceSpace->getCurrentResourceSpaceData();
        if ($this->resourceSpaceData === null) {
            return;
        }
        $this->resourceIds = array();

        foreach ($this->resourceSpaceData as $resourceId => $oldData) {
            $dataPid = $this->datahubDatapidPrefix . $oldData['sourceinvnr'];
            $this->resourceIds[$dataPid] = $resourceId;
            $this->getDatahubData($dataPid);
        }

        if (count($this->datahubData) > 0) {

            $this->addAllRelations();
            $this->fixSortOrders();

            foreach ($this->datahubData as $dataPid => $newData) {
                $this->updateResourceSpaceFields($dataPid, $newData);
            }
        }
    }

    function getDatahubData($dataPid)
    {
        $newData = array();
        try {
            if (!$this->datahubEndpoint)
                $this->datahubEndpoint = Endpoint::build($this->datahubUrl . '/oai');

            $record = $this->datahubEndpoint->getRecord($dataPid, $this->metadataPrefix);
            $data = $record->GetRecord->record->metadata->children($this->namespace, true);
            $domDoc = new DOMDocument;
            $domDoc->loadXML($data->asXML());
            $xpath = new DOMXPath($domDoc);

            foreach ($this->dataDefinition as $key => $dataDef) {
                if(!array_key_exists('field', $dataDef)) {
                    continue;
                }
                $xpaths = array();
                if(array_key_exists('xpaths', $dataDef)) {
                    $xpaths = $dataDef['xpaths'];
                } else if(array_key_exists('xpath', $dataDef)) {
                    $xpaths[] = $dataDef['xpath'];
                }
                $value = null;
                foreach($xpaths as $xpath_) {
                    $query = $this->buildXpath($xpath_, $this->datahubLanguage);
                    $extracted = $xpath->query($query);
                    if ($extracted) {
                        if (count($extracted) > 0) {
                            foreach ($extracted as $extr) {
                                if ($extr->nodeValue !== 'n/a') {
                                    if($value == null) {
                                        $value = $extr->nodeValue;
                                    }
                                    else if($key != 'keywords' || !in_array($extr->nodeValue, explode(",", $value))) {
                                        $value .= ', ' . $extr->nodeValue;
                                    }
                                }
                            }
                        }
                    }
                }
                if ($value != null) {
                    $newData[$dataDef['field']] = trim($value);
                }
            }


            $this->relations[$dataPid] = array();
            // Find all related works (hasPart, isPartOf, relatedTo)
            $query = $this->buildXpath('descriptiveMetadata[@xml:lang="{language}"]/objectRelationWrap/relatedWorksWrap/relatedWorkSet', $this->datahubLanguage);
            $domNodes = $xpath->query($query);
            $value = null;
            if ($domNodes) {
                if (count($domNodes) > 0) {
                    foreach ($domNodes as $domNode) {
                        $relatedDataPid = null;
                        $relation = null;
                        $sortOrder = 1;
                        if($domNode->attributes) {
                            for($i = 0; $i < $domNode->attributes->length; $i++) {
                                if($domNode->attributes->item($i)->nodeName == $this->namespace . ':sortorder') {
                                    $sortOrder = $domNode->attributes->item($i)->nodeValue;
                                }
                            }
                        }
                        $childNodes = $domNode->childNodes;
                        foreach ($childNodes as $childNode) {
                            if ($childNode->nodeName == $this->namespace . ':relatedWork') {
                                $objects = $childNode->childNodes;
                                foreach($objects as $object) {
                                    if($object->childNodes) {
                                        foreach($object->childNodes as $objectId) {
                                            if($objectId->attributes) {
                                                for($i = 0; $i < $objectId->attributes->length; $i++) {
                                                    if($objectId->attributes->item($i)->nodeName == $this->namespace . ':type' && $objectId->attributes->item($i)->nodeValue == 'oai') {
                                                        $relatedDataPid = $objectId->nodeValue;
                                                    }
                                                }
                                            }
                                        }
                                    }
                                }
                            } else if($childNode->nodeName == $this->namespace . ':relatedWorkRelType') {
                                $objects = $childNode->childNodes;
                                foreach($objects as $object) {
                                    if($object->nodeName == $this->namespace . ':conceptID') {
                                        $relation = substr($object->nodeValue, strrpos($object->nodeValue, '/') + 1);
                                    }
                                }
                            }
                        }
                        if($relatedDataPid != null) {

                            if($relation == null) {
                                $relation = 'relation';
                            }
                            $arr = array(
                                'related_work_type' => $relation,
                                'data_pid'          => $relatedDataPid,
                                'sort_order'        => $sortOrder
                            );
                            $this->relations[$dataPid][$relatedDataPid] = $arr;
                        }
                    }
                }
            }
        }
        catch(OaipmhException $e) {
            echo 'Data pid ' . $dataPid . ' error: ' . $e . PHP_EOL;
//            $this->logger->error('Resource ' . $resourceId . ' (data pid ' . $dataPid . ') error: ' . $e);
        }
        catch(HttpException $e) {
            echo 'Data pid ' . $dataPid . ' error: ' . $e . PHP_EOL;
//            $this->logger->error('Resource ' . $resourceId . ' (data pid ' . $dataPid . ') error: ' . $e);
        }

        // Combine earliest and latest date into one
        //TODO clen up the CSV so this isn't necessary anymore
        if(array_key_exists('earliestdate', $newData)) {
            if(array_key_exists('latestdate', $newData)) {
                $newData['datecreatedofartwork'] = $newData['earliestdate'] . '-01-01, ' . $newData['latestdate'] . '-12-31';
                unset($newData['latestdate']);
            } else {
                $newData['datecreatedofartwork'] = $newData['earliestdate'] . '-01-01, ' . $newData['earliestdate'] . '-12-31';
            }
            unset($newData['earliestdate']);
        } else if(array_key_exists('latestdate', $newData)) {
            $newData['datecreatedofartwork'] = $newData['latestdate'] . '-01-01, ' . $newData['latestdate'] . '-12-31';
            unset($newData['latestdate']);
        }

        $this->datahubData[$dataPid] = $newData;
    }

    private function addAllRelations()
    {
        $relations = array();

        // Initialize the array containing all directly related works
        foreach($this->relations as $resourceId => $value) {
            $relations[$resourceId] = $value;
        }

        // Loop through all data pids and keep adding relations until all (directly or indirectly) related works contain references to each other
        $relationsChanged = true;
        while($relationsChanged) {
            $relationsChanged = false;
            foreach($relations as $resourceId => $related) {
                foreach($relations as $otherPid => $otherRelation) {
                    if(array_key_exists($resourceId, $otherRelation)) {
                        foreach ($related as $relatedData) {
                            if (!array_key_exists($relatedData['data_pid'], $otherRelation)) {
                                $relations[$otherPid][$relatedData['data_pid']] = array(
                                    'related_work_type' => 'relation',
                                    'data_pid'          => $relatedData['data_pid'],
                                    'sort_order'        => $relatedData['sort_order']
                                );
                                $relationsChanged = true;
                            }
                        }
                    }
                }
            }
        }

        // Add the newly found relations to the appropriate related_works arrays
        foreach($relations as $resourceId => $related) {
            foreach($related as $relatedData) {
                if(array_key_exists($relatedData['data_pid'], $this->relations)) {
                    if (array_key_exists($resourceId, $this->relations)) {
                        if (!array_key_exists($relatedData['data_pid'], $this->relations[$resourceId])) {
                            $this->relations[$resourceId][$relatedData['data_pid']] = array(
                                'related_work_type' => 'relation',
                                'data_pid'          => $relatedData['data_pid'],
                                'sort_order'        => $relatedData['sort_order']
                            );
                        }
                    }
                }
            }
        }

        // Add reference to itself
        foreach($this->relations as $resourceId => $value) {
            if (!array_key_exists($resourceId, $value)) {
                $this->relations[$resourceId][$resourceId] = array(
                    'related_work_type' => 'relation',
                    'data_pid'          => $resourceId,
                    'sort_order'        => 1
                );
            }
        }

    }

    private function isHigherOrder($type, $highestType)
    {
        if($highestType == null) {
            return true;
        } else if($highestType == 'isPartOf') {
            return false;
        } else if($highestType == 'relation') {
            return $type == 'isPartOf';
        } else if($highestType == 'hasPart') {
            return $type == 'isPartOf' || $type == 'relation';
        } else {
            return true;
        }
    }

    private function fixSortOrders()
    {
        foreach($this->relations as $dataPid => $value) {
            if(count($value) > 1) {

                // Sort based on data pids to ensure all related_works for related data pid's contain exactly the same information in the same order
                ksort($this->relations[$dataPid]);

                // Check for colliding sort orders
                $mismatch = true;
                while($mismatch) {
                    $mismatch = false;
                    foreach ($this->relations[$dataPid] as $pid => $relatedWork) {
                        $order = $this->relations[$dataPid][$pid]['sort_order'];

                        foreach ($this->relations[$dataPid] as $otherPid => $otherWork) {

                            // Find colliding sort orders
                            if ($pid != $otherPid && $this->relations[$dataPid][$otherPid]['sort_order'] == $order) {

                                // Upon collision, find out which relation has the highest priority
                                $highest = null;
                                $highestType = 'none';
                                foreach ($this->relations[$dataPid] as $relatedRef => $data) {
                                    if ($this->relations[$dataPid][$relatedRef]['sort_order'] == $order
                                        && $this->isHigherOrder($this->relations[$dataPid][$relatedRef]['related_work_type'], $highestType)) {
                                        $highest = $relatedRef;
                                        $highestType = $this->relations[$dataPid][$relatedRef]['related_work_type'];
                                    }
                                }

                                // Increment the sort order of all related works with the same or higher sort order with one,
                                // except the one with the highest priority
                                foreach ($this->relations[$dataPid] as $relatedRef => $data) {
                                    if ($relatedRef != $highest && $this->relations[$dataPid][$relatedRef]['sort_order'] >= $order) {
                                        $this->relations[$dataPid][$relatedRef]['sort_order'] = $this->relations[$dataPid][$relatedRef]['sort_order'] + 1;
                                    }
                                }


                                $mismatch = true;
                                break;
                            }
                        }
                    }
                }

                // Sort related works based on sort_order
                uasort($this->relations[$dataPid], array('App\Command\DatahubToResourceSpaceCommand', 'sortRelatedWorks'));

                $relations = '';
                foreach($this->relations[$dataPid] as $key => $value) {
                    if(array_key_exists($dataPid, $this->resourceIds)) {
                        $relations .= (empty($relations) ? '' : '\n') . $this->resourceIds[$key];
                    }
                }

                $this->datahubData[$dataPid]['relatedrecords'] = $relations;
            }
        }
    }

    private function sortRelatedWorks($a, $b)
    {
        return $a['sort_order'] - $b['sort_order'];
    }

    function updateResourceSpaceFields($dataPid, $newData)
    {
        $resourceId = $this->resourceIds[$dataPid];
        $oldData = $this->resourceSpaceData[$resourceId];

        $updatedFields = 0;
        foreach($newData as $key => $value) {
            $update = false;
            if(!array_key_exists($key, $oldData)) {
                if($this->verbose) {
                    echo 'Field ' . $key . ' does not exist, should be ' . $value . PHP_EOL;
//                    $this->logger->error('Field ' . $key . ' does not exist, should be ' . $value);
                }
                $update = true;
            } else if($key == 'keywords') {
                $explodeVal = explode(',', $value);
                $explodeRS = explode(',', $oldData[$key]);
                $hasAll = true;
                foreach($explodeVal as $val) {
                    $has = false;
                    foreach($explodeRS as $rs) {
                        if($rs == $val) {
                            $has = true;
                            break;
                        }
                    }
                    if(!$has) {
                        $hasAll = false;
                        break;
                    }
                }
                if(!$hasAll) {
                    if($this->verbose) {
                        echo 'Mismatching field ' . $key . ', should be ' . $value . ', is ' . $oldData[$key] . PHP_EOL;
//                        $this->logger->error('Mismatching field ' . $key . ', should be ' . $value . ', is ' . $oldData[$key]);
                    }
                    $update = true;
                }
            } else {
                if($oldData[$key] != $value) {
                    if($this->verbose) {
                        echo 'Mismatching field ' . $key . ', should be ' . $value . ', is ' . $oldData[$key] . PHP_EOL;
//                        $this->logger->error('Mismatching field ' . $key . ', should be ' . $value . ', is ' . $oldData[$key]);
                    }
                    $update = true;
                }
            }
            if($update) {
                $result = $this->resourceSpace->updateField($resourceId, $key, $value);
                if($result !== 'true') {
                    echo 'Error updating field ' . $key . ' for resource id ' . $resourceId . ':' . PHP_EOL . $result . PHP_EOL;
//                    $this->logger->error('Error updating field ' . $key . ' for resource id ' . $resourceId . ':' . PHP_EOL . $result);
                } else {
                    $updatedFields++;
                }
            }
        }
        if($this->verbose && $updatedFields > 0) {
            echo 'Updated ' . $updatedFields . ' fields for resource id ' . $resourceId . PHP_EOL;
//            $this->logger->info('Updated ' . $updatedFields . ' fields for image ' . $fullImagePath);
        }

    }

    // Build the xpath based on the provided namespace
    private function buildXpath($xpath, $language)
    {
        $xpath = str_replace('{language}', $language, $xpath);
        $xpath = str_replace('[@', '[@' . $this->namespace . ':', $xpath);
        $xpath = str_replace('[@' . $this->namespace . ':xml:', '[@xml:', $xpath);
        $xpath = preg_replace('/\[([^@])/', '[' . $this->namespace . ':${1}', $xpath);
        $xpath = preg_replace('/\/([^\/])/', '/' . $this->namespace . ':${1}', $xpath);
        if(strpos($xpath, '/') !== 0) {
            $xpath = $this->namespace . ':' . $xpath;
        }
        $xpath = 'descendant::' . $xpath;
        return $xpath;
    }
}
