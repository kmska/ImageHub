<?php

namespace App\Command;

use App\Entity\DatahubData;
use App\Entity\RelatedResources;
use App\ResourceSpace\ResourceSpace;
use App\Utils\StringUtil;
use DOMDocument;
use DOMXPath;
use Phpoaipmh\Endpoint;
use Phpoaipmh\Exception\HttpException;
use Phpoaipmh\Exception\OaipmhException;
use Psr\Log\LoggerAwareInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\ContainerAwareInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

class DatahubToResourceSpaceCommand extends Command implements ContainerAwareInterface, LoggerAwareInterface
{
    private $datahubUrl;
    private $datahubLanguage;
    private $namespace;
    private $metadataPrefix;
    private $dataDefinition;
    private $creditLineDefinition;
    private $relatedWorksXpath;

    private $verbose;

    private $resourceSpace;

    private $relations;

    protected function configure()
    {
        $this
            ->setName('app:datahub-to-resourcespace')
            ->addArgument('rs_id', InputArgument::OPTIONAL, 'The ID (ref) of the resource in ResourceSpace that needs updating')
            ->addArgument('url', InputArgument::OPTIONAL, 'The URL of the Datahub')
            ->setDescription('')
            ->setHelp('');
    }

    public function setContainer(ContainerInterface $container = null)
    {
        $this->container = $container;
    }

    public function setLogger(LoggerInterface $logger)
    {
        $this->logger = $logger;
    }

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $this->verbose = $input->getOption('verbose');

        $resourceSpaceId = $input->getArgument('rs_id');
        if($resourceSpaceId != null && !preg_match('/^[0-9]+$/', $resourceSpaceId)) {
            $resourceSpaceId = null;
        }

        $this->datahubUrl = $input->getArgument('url');
        if (!$this->datahubUrl) {
            $this->datahubUrl = $this->container->getParameter('datahub_url');
        }

        $this->datahubLanguage = $this->container->getParameter('datahub_language');
        $this->namespace = $this->container->getParameter('datahub_namespace');
        $this->metadataPrefix = $this->container->getParameter('datahub_metadataprefix');
        $this->relatedWorksXpath = $this->container->getParameter('datahub_related_works_xpath');
        $this->dataDefinition = $this->container->getParameter('datahub_data_definition');
        $this->creditLineDefinition = $this->container->getParameter('credit_line');
        $publicUse = $this->container->getParameter('public_use');
        $recommendedForPublication = $this->container->getParameter('recommended_for_publication');
        $iiifSortNumber = $this->container->getParameter('iiif_sort_number');

        $this->resourceSpace = new ResourceSpace($this->container);

        $em = $this->container->get('doctrine')->getManager();

        $this->cacheAllDatahubData($em);
        $this->addAllRelations();
        $this->fixSortOrders();

        $resources = $this->resourceSpace->getAllResources();
        if ($resources === null) {
            $this->logger->error( 'Error: no resourcespace data.');
            return;
        }

        $recordIds = array();
        $recordIdsToResourceIds = array();
        $publicImages = array();
        $recommendedImagesForPub = array();
        $resourceSpaceSortNumbers = array();
        $originalFilenames = array();

        $total = count($resources);
        $n = 0;
        foreach($resources as $resource) {
            $resourceId = $resource['ref'];
            $rsData = $this->resourceSpace->getResourceSpaceData($resourceId);
            $inventoryNumber = $rsData['sourceinvnr'];
            $originalFilenames[$resourceId] = $rsData['originalfilename'];
            if (!empty($inventoryNumber)) {
                $rsIdsToInventoryNumbers[$resourceId] = $inventoryNumber;
                $dhData_ = $em->createQueryBuilder()
                         ->select('i')
                         ->from(DatahubData::class, 'i')
                         ->where('i.id = :id')
                         ->setParameter('id', $rsData['sourceinvnr'])
                         ->getQuery()
                         ->getResult();
                $dhData = array();
                $recordId = null;
                foreach($dhData_ as $data) {
                    if($data->getName() == 'dh_record_id') {
                        $recordId = $data->getValue();
                        if(!array_key_exists($recordId, $recordIdsToResourceIds)) {
                            $recordIdsToResourceIds[$recordId] = array();
                        }
                        $recordIdsToResourceIds[$recordId][] = $resourceId;
                        $recordIds[$resourceId] = $recordId;
                    } else {
                        $dhData[$data->getName()] = $data->getValue();
                    }
                }
                if ($this->resourceSpace->isPublicUse($rsData, $publicUse)) {
                    $publicImages[] = $resourceId;
                }
                if ($this->resourceSpace->isRecommendedForPublication($rsData, $recommendedForPublication)) {
                    $recommendedImagesForPub[] = $resourceId;
                }
                $index = $this->resourceSpace->getIIIFSortNumber($rsData, $iiifSortNumber);
                if($index > -1) {
                    $resourceSpaceSortNumbers[$resourceId] = $index;
                }
                // Empty the 'related records' field in ResourceSpace
                // TODO Perhaps we can remove this line after the first run, because we won't need it again after
                $dhData['relatedrecords'] = '';
                $this->resourceSpace->generateCreditLines($this->creditLineDefinition, $rsData, $dhData);
                $this->updateResourceSpaceFields($resourceId, $rsData, $dhData);
            }
            if($this->verbose) {
                $n++;
                if ($n % 1000 == 0) {
//                    echo 'At ' . $n . '/' . $total . ' resources.' . PHP_EOL;
                    $this->logger->info('At ' . $n . '/' . $total . ' resources.');
                }
            }
        }

        // Sort by oldest > newest resources to generally improve sort orders in related resources
        ksort($rsIdsToInventoryNumbers);

        $qb = $em->createQueryBuilder();
        $qb->delete(RelatedResources::class, 'data')->getQuery()->execute();
        $em->flush();

        $n = 0;
        foreach ($rsIdsToInventoryNumbers as $resourceId => $inventoryNumber) {
            $potentialRelations = array();
            $thisSortOrder = 1000000000;
            // Add all resources of related records (with different inventory numbers)
            if(array_key_exists($resourceId, $recordIds)) {
                $recordId = $recordIds[$resourceId];
                foreach ($this->relations[$recordId] as $k => $v) {
                    if($v['record_id'] == $recordId) {
                        $thisSortOrder = $v['sort_order'];
                    }
                    if (array_key_exists($k, $recordIdsToResourceIds)) {
                        foreach ($recordIdsToResourceIds[$k] as $otherResourceId) {
                            $potentialRelations[$otherResourceId] = $v['sort_order'];
                        }
                    }
                }
            }
            // Add all resources with the same inventory number (including itself)
            foreach($rsIdsToInventoryNumbers as $rsId => $invNr) {
                if($invNr == $inventoryNumber) {
                    $potentialRelations[$rsId] = $thisSortOrder;
                }
            }
            asort($potentialRelations);

            $relations = array();
            $isThisPublic = in_array($resourceId, $publicImages);
            $isThisRecommendedForPublication = in_array($resourceId, $recommendedImagesForPub);
            // Add relations when one of the following coditions is met:
            // - The 'related' resource is actually itself
            // - Both resources are for public use and both are recommended for publication
            // - This resource is not public, but the other one is public (public images added to research images)
            foreach($potentialRelations as $otherResourceId => $index) {
                $isOtherPublic = in_array($otherResourceId, $publicImages);
                $isOtherRecommendedForPublication = in_array($otherResourceId, $recommendedImagesForPub);
                if ($resourceId == $otherResourceId
                    || $isThisPublic && $isThisRecommendedForPublication && $isOtherPublic && $isOtherRecommendedForPublication
                    || !$isThisPublic && $isOtherPublic && $isOtherRecommendedForPublication
                    && $rsIdsToInventoryNumbers[$resourceId] == $rsIdsToInventoryNumbers[$otherResourceId]) {
                    if (!array_key_exists($index, $relations)) {
                        $relations[$index] = array();
                    }
                    $sortNumber = PHP_INT_MAX;
                    if (array_key_exists($otherResourceId, $resourceSpaceSortNumbers)) {
                        $sortNumber = $resourceSpaceSortNumbers[$otherResourceId];
                    }
                    if (!array_key_exists($sortNumber, $relations[$index])) {
                        $relations[$index][$sortNumber] = array();
                    }
                    $relations[$index][$sortNumber][$otherResourceId] = $originalFilenames[$otherResourceId];
                }
            }
            ksort($relations);
            $sortedRelations = array();
            foreach($relations as $index => $rel) {
                if(!empty($rel)) {
                    $sortedRelations[$index] = array();
                    ksort($rel);
                    foreach ($rel as $sortNumber => $ids) {
                        // Sort resources with the same sort number based on original filename
                        asort($ids);
                        $sortedRelations[$index] = $rel;
                    }
                }
            }

            $relatedResources = array();
            foreach($sortedRelations as $index => $rel) {
                foreach($rel as $sortNumber => $ids) {
                    foreach($ids as $rsId => $originalFilename) {
                        $relatedResources[] = $rsId;
                    }
                }
            }
            $relatedResourcesObj = new RelatedResources();
            $relatedResourcesObj->setId($resourceId);
            $relatedResourcesObj->setRelatedResources(implode(',', $relatedResources));
            $em->persist($relatedResourcesObj);
            $n++;
            if($n % 500 == 0) {
                $em->flush();
            }
        }
        $em->flush();
    }

    function cacheAllDatahubData($em)
    {
        $qb = $em->createQueryBuilder();
        $qb->delete(DatahubData::class, 'data')->getQuery()->execute();
        $em->flush();

        try {
            $datahubEndpoint = Endpoint::build($this->datahubUrl . '/oai');
            $records = $datahubEndpoint->listRecords($this->metadataPrefix);
            $n = 0;
            foreach($records as $record) {
                $id = null;
                $datahubData = array();

                $data = $record->metadata->children($this->namespace, true);
                $recordId = trim($record->header->identifier);
                // Add a reference to itself
                $this->relations[$recordId] = array(
                    $recordId => array(
                        'related_work_type' => 'relation',
                        'record_id'         => $recordId,
                        'sort_order'        => 1
                    )
                );

                if($this->verbose) {
                    $n++;
                    if($n % 1000 == 0) {
//                        echo 'At ' . $n . ' datahub records.' . PHP_EOL;
                        $this->logger->info('At ' . $n . ' datahub records.');
                    }
                }

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
                        $value = trim($value);
                        if($dataDef['field'] == 'id') {
                            $id = $value;
                        } else {
                            $datahubData[$dataDef['field']] = $value;
                        }
                    }
                }

                if($id != null) {
                    // Find all related works (hasPart, isPartOf, relatedTo)
                    $query = $this->buildXpath($this->relatedWorksXpath, $this->datahubLanguage);
                    $domNodes = $xpath->query($query);
                    $value = null;
                    if ($domNodes) {
                        if (count($domNodes) > 0) {
                            foreach ($domNodes as $domNode) {
                                $relatedRecordId = null;
                                $relation = null;
                                $sortOrder = 1;
                                if ($domNode->attributes) {
                                    for ($i = 0; $i < $domNode->attributes->length; $i++) {
                                        if ($domNode->attributes->item($i)->nodeName == $this->namespace . ':sortorder') {
                                            $sortOrder = $domNode->attributes->item($i)->nodeValue;
                                        }
                                    }
                                }
                                $childNodes = $domNode->childNodes;
                                foreach ($childNodes as $childNode) {
                                    if ($childNode->nodeName == $this->namespace . ':relatedWork') {
                                        $objects = $childNode->childNodes;
                                        foreach ($objects as $object) {
                                            if ($object->childNodes) {
                                                foreach ($object->childNodes as $objectId) {
                                                    if ($objectId->attributes) {
                                                        for ($i = 0; $i < $objectId->attributes->length; $i++) {
                                                            if ($objectId->attributes->item($i)->nodeName == $this->namespace . ':type' && $objectId->attributes->item($i)->nodeValue == 'oai') {
                                                                $relatedRecordId = $objectId->nodeValue;
                                                            }
                                                        }
                                                    }
                                                }
                                            }
                                        }
                                    } else if ($childNode->nodeName == $this->namespace . ':relatedWorkRelType') {
                                        $objects = $childNode->childNodes;
                                        foreach ($objects as $object) {
                                            if ($object->nodeName == $this->namespace . ':term') {
                                                if ($object->attributes) {
                                                    for ($i = 0; $i < $object->attributes->length; $i++) {
                                                        if ($object->attributes->item($i)->nodeName == $this->namespace . ':pref' && $object->attributes->item($i)->nodeValue == 'preferred') {
                                                            $relation = $object->nodeValue;
                                                        }
                                                    }
                                                }
                                            }
                                        }
                                    }
                                }

                                if ($relatedRecordId != null) {
                                    if (!array_key_exists($relatedRecordId, $this->relations[$recordId])) {
                                        if ($relation == null) {
                                            $relation = 'relation';
                                        }
                                        $arr = array(
                                            'related_work_type' => $relation,
                                            'record_id' => $relatedRecordId,
                                            'sort_order' => $sortOrder
                                        );
                                        $this->relations[$recordId][$relatedRecordId] = $arr;
                                    }
                                }
                            }
                        }
                    }

                    // Combine earliest and latest date into one
                    if(array_key_exists('earliestdate', $datahubData)) {
                        if(array_key_exists('latestdate', $datahubData)) {
                            $datahubData['datecreatedofartwork'] = StringUtil::getDateRange($datahubData['earliestdate'], $datahubData['latestdate']);
                            unset($datahubData['latestdate']);
                        } else {
                            $datahubData['datecreatedofartwork'] = StringUtil::getDateRange($datahubData['earliestdate'], $datahubData['earliestdate']);
                        }
                        unset($datahubData['earliestdate']);
                    } else if(array_key_exists('latestdate', $datahubData)) {
                        $datahubData['datecreatedofartwork'] = StringUtil::getDateRange($datahubData['latestdate'], $datahubData['latestdate']);
                        unset($datahubData['latestdate']);
                    }
                    // Combine role and creator name
                    if(array_key_exists('roleofcreatorofartworkobje', $datahubData)) {
                        if(array_key_exists('creatorofartworkobje', $datahubData)) {
                            $datahubData['creatorofartworkobje'] = $datahubData['roleofcreatorofartworkobje'] . ': ' . $datahubData['creatorofartworkobje'];
                        }
                        unset($datahubData['roleofcreatorofartworkobje']);
                    }
                    // Delete any data that might already exist for this inventory number
                    $oldData = $em->createQueryBuilder()
                        ->select('i')
                        ->from(DatahubData::class, 'i')
                        ->where('i.id = :id')
                        ->setParameter('id', $id)
                        ->getQuery()
                        ->getResult();
                    foreach($oldData as $oldD) {
                        $em->remove($oldD);
                    }
                    $em->flush();

                    $datahubData['dh_record_id'] = $recordId;
                    //Store all relevant Datahub data in mysql
                    foreach($datahubData as $key => $value) {
                        $data = new DatahubData();
                        $data->setId($id);
                        $data->setName($key);
                        $data->setValue($value);
                        $em->persist($data);
                    }
                    $em->flush();
                }
            }
//            var_dump($relations);
        }
        catch(OaipmhException $e) {
//            echo 'OAI-PMH error: ' . $e . PHP_EOL;
            $this->logger->error('OAI-PMH error: ' . $e);
        }
        catch(HttpException $e) {
//            echo 'OAI-PMH error: ' . $e . PHP_EOL;
            $this->logger->error('OAI-PMH error: ' . $e);
        }
    }

    private function addAllRelations()
    {
        $relations = array();

        // Initialize the array containing all directly related works
        foreach($this->relations as $recordId => $value) {
            $relations[$recordId] = $value;
        }

        // Loop through all records and keep adding relations until all (directly or indirectly) related works contain references to each other
        $relationsChanged = true;
        while($relationsChanged) {
            $relationsChanged = false;
            foreach($relations as $recordId => $related) {
                foreach($relations as $otherid => $otherRelation) {
                    if(array_key_exists($recordId, $otherRelation)) {
                        foreach ($related as $relatedData) {
                            if (!array_key_exists($relatedData['record_id'], $otherRelation)) {
                                $relations[$otherid][$relatedData['record_id']] = array(
                                    'related_work_type' => 'relation',
                                    'record_id'         => $relatedData['record_id'],
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
        foreach($relations as $recordId => $related) {
            foreach($related as $relatedData) {
                if(array_key_exists($relatedData['record_id'], $this->relations)) {
                    if (array_key_exists($recordId, $this->relations)) {
                        if (!array_key_exists($relatedData['record_id'], $this->relations[$recordId])) {
                            $this->relations[$recordId][$relatedData['record_id']] = array(
                                'related_work_type' => 'relation',
                                'record_id'         => $relatedData['record_id'],
                                'sort_order'        => $relatedData['sort_order']
                            );
                        }
                    }
                }
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
        foreach($this->relations as $recordId => $value) {
            if(count($value) > 1) {

                // Sort based on record ids to ensure all related_works for related record ids contain exactly the same information in the same order
                ksort($this->relations[$recordId]);

                // Check for colliding sort orders
                $mismatch = true;
                while($mismatch) {
                    $mismatch = false;
                    foreach ($this->relations[$recordId] as $relatedId => $relatedWork) {
                        $order = $this->relations[$recordId][$relatedId]['sort_order'];

                        foreach ($this->relations[$recordId] as $otherId => $otherWork) {

                            // Find colliding sort orders
                            if ($relatedId != $otherId && $this->relations[$recordId][$otherId]['sort_order'] == $order) {

                                // Upon collision, find out which relation has the highest priority
                                $highest = null;
                                $highestType = 'none';
                                foreach ($this->relations[$recordId] as $relatedRef => $data) {
                                    if ($this->relations[$recordId][$relatedRef]['sort_order'] == $order
                                        && $this->isHigherOrder($this->relations[$recordId][$relatedRef]['related_work_type'], $highestType)) {
                                        $highest = $relatedRef;
                                        $highestType = $this->relations[$recordId][$relatedRef]['related_work_type'];
                                    }
                                }

                                // Increment the sort order of all related works with the same or higher sort order with one,
                                // except the one with the highest priority
                                foreach ($this->relations[$recordId] as $relatedRef => $data) {
                                    if ($relatedRef != $highest && $this->relations[$recordId][$relatedRef]['sort_order'] >= $order) {
                                        $this->relations[$recordId][$relatedRef]['sort_order'] = $this->relations[$recordId][$relatedRef]['sort_order'] + 1;
                                    }
                                }


                                $mismatch = true;
                                break;
                            }
                        }
                    }
                }

                // Sort related works based on sort_order
                uasort($this->relations[$recordId], array('App\Command\DatahubToResourceSpaceCommand', 'sortRelatedWorks'));

            }
        }
    }

    private function sortRelatedWorks($a, $b)
    {
        return $a['sort_order'] - $b['sort_order'];
    }

    function updateResourceSpaceFields($resourceId, $rsData, $dhData)
    {
        $updatedFields = 0;
        foreach($dhData as $key => $value) {
            $update = false;
            if(!array_key_exists($key, $rsData)) {
                if($this->verbose) {
//                    echo 'Field ' . $key . ' does not exist, should be ' . $value . PHP_EOL;
                    $this->logger->info('Field ' . $key . ' does not exist, should be ' . $value);
                }
                $update = true;
            } else if($key == 'keywords') {
                $explodeVal = explode(',', $value);
                $explodeRS = explode(',', $rsData[$key]);
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
//                        echo 'Mismatching field ' . $key . ', should be ' . $value . ', is ' . $oldData[$key] . PHP_EOL;
                        $this->logger->info('Mismatching field ' . $key . ', should be ' . $value . ', is ' . $rsData[$key]);
                    }
                    $update = true;
                }
            } else {
                if($rsData[$key] != $value) {
                    if($this->verbose) {
//                        echo 'Mismatching field ' . $key . ', should be ' . $value . ', is ' . $oldData[$key] . PHP_EOL;
                        $this->logger->info('Mismatching field ' . $key . ', should be ' . $value . ', is ' . $rsData[$key]);
                    }
                    $update = true;
                }
            }
            if($update) {
                $result = $this->resourceSpace->updateField($resourceId, $key, $value);
                if($result !== 'true') {
//                    echo 'Error updating field ' . $key . ' for resource id ' . $resourceId . ':' . PHP_EOL . $result . PHP_EOL;
                    $this->logger->error('Error updating field ' . $key . ' for resource id ' . $resourceId . ':' . PHP_EOL . $result);
                } else {
                    $updatedFields++;
                }
            }
        }
        if($this->verbose) {
//            echo 'Updated ' . $updatedFields . ' fields for resource id ' . $resourceId . PHP_EOL;
            $this->logger->info('Updated ' . $updatedFields . ' fields for resource id ' . $resourceId);
        }
    }

    // Build the xpath based on the provided namespace
    private function buildXpath($xpath, $language)
    {
        $xpath = str_replace('{language}', $language, $xpath);
        $xpath = str_replace('[@', '[@' . $this->namespace . ':', $xpath);
        $xpath = str_replace('[@' . $this->namespace . ':xml:', '[@xml:', $xpath);
        $xpath = preg_replace('/\[([^@])/', '[' . $this->namespace . ':${1}', $xpath);
        $xpath = preg_replace('/\(([^\/])/', '(' . $this->namespace . ':${1}', $xpath);
        $xpath = preg_replace('/\/([^\/])/', '/' . $this->namespace . ':${1}', $xpath);
        if(strpos($xpath, '/') !== 0) {
            $xpath = $this->namespace . ':' . $xpath;
        }
        $xpath = 'descendant::' . $xpath;
        return $xpath;
    }
}
